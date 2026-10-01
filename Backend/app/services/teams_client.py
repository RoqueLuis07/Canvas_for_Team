"""Microsoft Graph API client using MSAL for app-only (client credentials) auth."""
import asyncio
import re
import time
from typing import Any

import httpx
import msal
from fastapi import HTTPException
from tenacity import retry, retry_if_exception_type, stop_after_attempt, wait_exponential

from app.core.config import settings
import logging as _logging

_logger = _logging.getLogger(__name__)

_GRAPH = "https://graph.microsoft.com/v1.0"
_SCOPE = ["https://graph.microsoft.com/.default"]
_TIMEOUT = httpx.Timeout(30.0)

_token_cache: dict = {"access_token": None, "expires_at": 0}
_sku_cache: dict[str, str] = {}

from tenacity import retry, retry_if_exception, stop_after_attempt, wait_exponential

_TRANSIENT = (
    httpx.ConnectError,
    httpx.TimeoutException,
    httpx.RemoteProtocolError,
    httpx.ReadError,
)

def safe_mail_nickname(name: str, suffix: str = "") -> str:
    """Genera un mailNickname válido para Microsoft Graph a partir de un nombre libre.

    Graph rechaza el grupo con 400 'Invalid value specified for property
    mailNickname' si el resultado supera 64 caracteres — algo fácil de pisar
    con nombres de curso/equipo largos. Se trunca la base ANTES de agregar
    el sufijo (típicamente un timestamp para unicidad) para no superar el
    límite nunca."""
    base = re.sub(r'[^a-zA-Z0-9]', '', name or '').lower()
    max_base_len = 64 - len(suffix)
    base = base[:max_base_len] if base else ""
    return f"{base}{suffix}" if base else f"grupo{int(time.time())}"[:64]


def _should_retry(e: Exception) -> bool:
    if isinstance(e, _TRANSIENT):
        return True
    if isinstance(e, HTTPException) and e.status_code in (423, 429, 502, 503, 504):
        return True
    return False

_retry = retry(
    retry=retry_if_exception(_should_retry),
    stop=stop_after_attempt(5),
    wait=wait_exponential(min=1, max=16),
    reraise=True,
)


def _get_access_token() -> str:
    if _token_cache["access_token"] and time.time() < _token_cache["expires_at"] - 60:
        return _token_cache["access_token"]

    if not settings.azure_tenant_id or not settings.azure_client_id:
        raise HTTPException(status_code=401, detail="Azure AD: Las credenciales no están configuradas en el archivo .env (faltan tenant_id o client_id).")

    app = msal.ConfidentialClientApplication(
        client_id=settings.azure_client_id,
        client_credential=settings.azure_client_secret,
        authority=f"https://login.microsoftonline.com/{settings.azure_tenant_id}",
    )
    result = app.acquire_token_for_client(scopes=_SCOPE)
    if "access_token" not in result:
        err_desc = result.get('error_description', result)
        _logger.error(f"MSAL token error: {err_desc}")
        raise HTTPException(status_code=401, detail=f"Azure AD: No se pudo obtener el token. Verificá AZURE_TENANT_ID y AZURE_CLIENT_SECRET. Detalle: {err_desc}")

    _token_cache["access_token"] = result["access_token"]
    _token_cache["expires_at"] = time.time() + result.get("expires_in", 3600)
    return result["access_token"]


def _headers() -> dict:
    return {
        "Authorization": f"Bearer {_get_access_token()}",
        "Content-Type": "application/json",
    }


def _raise(r: httpx.Response) -> None:
    if r.is_error:
        # Extract Graph API error details from the response body
        try:
            body = r.json()
            graph_err = body.get("error", {})
            graph_code = graph_err.get("code", "")
            graph_msg  = graph_err.get("message", "")
        except Exception:
            graph_code, graph_msg = "", r.text[:300]

        if r.status_code == 401:
            msg = "Azure AD: credenciales inválidas (401). Verificá AZURE_TENANT_ID, AZURE_CLIENT_ID y AZURE_CLIENT_SECRET."
        elif r.status_code == 403:
            msg = f"Azure AD: acceso denegado (403). La app necesita permisos 'User.ReadWrite.All' con admin consent en Azure Portal."
            if graph_msg:
                msg += f" Detalle: {graph_msg}"
        else:
            parts = [f"Microsoft Graph {r.status_code}"]
            if graph_code:
                parts.append(f"[{graph_code}]")
            if graph_msg:
                parts.append(graph_msg)
            msg = " ".join(parts)
        raise HTTPException(status_code=r.status_code, detail=msg)


async def get_sku_id(part_number: str) -> str | None:
    """Return the skuId for the given SKU part number, or None if not found."""
    part_number = part_number.strip().lower()
    if part_number in _sku_cache:
        return _sku_cache[part_number]

    skus = await paginate("/subscribedSkus")
    for sku in skus:
        if sku.get("skuPartNumber", "").strip().lower() == part_number:
            sku_id = sku.get("skuId")
            if sku_id:
                _sku_cache[part_number] = sku_id
                return sku_id

    # Log available SKUs to help diagnose misconfiguration
    available = [s.get("skuPartNumber") for s in skus]
    _logger.warning(
        "SKU '%s' no encontrado en este tenant. SKUs disponibles: %s",
        part_number, available,
    )
    return None


async def assign_license(user_id: str, sku_part_number: str) -> Any:
    """Assign a license to the user. Raises an error if the SKU is not available."""
    sku_id = await get_sku_id(sku_part_number)
    if sku_id is None:
        raise ValueError(f"La licencia {sku_part_number} no se encontró o no está disponible en tu cuenta de Microsoft.")
    return await post(f"/users/{user_id}/assignLicense", {"addLicenses": [{"skuId": sku_id}], "removeLicenses": []})


async def remove_all_licenses(user_id: str) -> list[str]:
    """Libera todas las licencias asignadas a un usuario (usado al dar de baja,
    para no seguir consumiendo asientos pagos). Devuelve los skuId removidos.
    Requiere User.ReadWrite.All (ya concedido)."""
    user = await get(f"/users/{user_id}", params={"$select": "assignedLicenses"})
    sku_ids = [lic["skuId"] for lic in (user.get("assignedLicenses") or []) if lic.get("skuId")]
    if not sku_ids:
        return []
    await post(f"/users/{user_id}/assignLicense", {"addLicenses": [], "removeLicenses": sku_ids})
    return sku_ids

_client_instance: httpx.AsyncClient | None = None

def _client(timeout: httpx.Timeout | None = None) -> httpx.AsyncClient:
    global _client_instance
    if _client_instance is None or _client_instance.is_closed:
        _client_instance = httpx.AsyncClient(timeout=_TIMEOUT, follow_redirects=True)
    if timeout is not None:
        _client_instance.timeout = timeout
    else:
        _client_instance.timeout = _TIMEOUT
    return _client_instance

async def close_client() -> None:
    global _client_instance
    if _client_instance is not None and not _client_instance.is_closed:
        await _client_instance.aclose()
        _client_instance = None

@_retry
async def get(path: str, params: dict | None = None) -> Any:
    r = await _client().get(f"{_GRAPH}{path}", headers=_headers(), params=params)
    _raise(r)
    return r.json()

@_retry
async def get_raw(path: str) -> bytes:
    r = await _client().get(f"{_GRAPH}{path}", headers=_headers())
    _raise(r)
    return r.content

@_retry
async def post(path: str, payload: dict) -> Any:
    r = await _client().post(f"{_GRAPH}{path}", headers=_headers(), json=payload)
    _raise(r)
    return r.json() if r.content else {}

@_retry
async def put_raw(path: str, data: bytes) -> Any:
    headers = _headers()
    headers["Content-Type"] = "application/octet-stream"
    r = await _client().put(f"{_GRAPH}{path}", headers=headers, content=data)
    _raise(r)
    return r.json() if r.content else {}


@_retry
async def patch(path: str, payload: dict) -> Any:
    r = await _client().patch(f"{_GRAPH}{path}", headers=_headers(), json=payload)
    _raise(r)
    return r.json() if r.content else {}


@_retry
async def delete(path: str) -> None:
    r = await _client().delete(f"{_GRAPH}{path}", headers=_headers())
    _raise(r)


async def paginate(path: str, params: dict | None = None) -> list[Any]:
    """Follow @odata.nextLink pagination and return all records."""
    results: list[Any] = []
    next_url: str | None = f"{_GRAPH}{path}"

    c = _client()
    while next_url:
        r = await c.get(next_url, headers=_headers(), params=params if next_url == f"{_GRAPH}{path}" else None)
        _raise(r)
        data = r.json()
        results.extend(data.get("value", []))
        next_url = data.get("@odata.nextLink")

    return results


async def paginate_limited(path: str, params: dict | None = None,
                           max_records: int = 5000,
                           extra_headers: dict | None = None) -> list[Any]:
    """Follow @odata.nextLink but stop at max_records to avoid long waits."""
    results: list[Any] = []
    next_url: str | None = f"{_GRAPH}{path}"
    req_headers = {**_headers(), **(extra_headers or {})}

    c = _client(timeout=httpx.Timeout(60.0))
    while next_url and len(results) < max_records:
        r = await c.get(
            next_url,
            headers=req_headers,
            params=params if next_url == f"{_GRAPH}{path}" else None,
        )
        _raise(r)
        data = r.json()
        results.extend(data.get("value", []))
        next_url = data.get("@odata.nextLink")
    
    # reset timeout
    c.timeout = _TIMEOUT

    return results[:max_records]



def _norm_group_name(s: str) -> str:
    """Normaliza para comparar nombres de equipo sin importar tildes/mayúsculas
    — evita que un nombre tipeado sin acentos falle contra el nombre real del
    grupo en Teams y dispare la creación de un equipo duplicado."""
    import unicodedata
    ascii_s = unicodedata.normalize("NFKD", s or "").encode("ascii", "ignore").decode("ascii")
    return ascii_s.strip().lower()


def _escape_odata(s: str) -> str:
    """OData requiere escapar comillas simples duplicándolas — un nombre con
    apóstrofe rompe el filtro si se interpola crudo, y el error resultante
    se confunde con 'no encontrado' más abajo."""
    return (s or "").replace("'", "''")


async def search_group_by_name(name: str) -> str | None:
    """Search for a Microsoft 365 group by exactly matching the displayName. Returns the group ID if found."""
    try:
        # We use $filter to get groups that start with the name to narrow it down,
        # then check exact match in Python to be safe (Graph API filtering can be finicky).
        # Note: $filter requires ConsistencyLevel: eventual for some properties, but startswith on displayName is usually supported.
        params = {
            "$filter": f"startswith(displayName, '{_escape_odata(name)}')",
            "$select": "id,displayName"
        }
        res = await get("/groups", params=params)
        groups = res.get("value", [])
        target = _norm_group_name(name)
        for g in groups:
            if _norm_group_name(g.get("displayName", "")) == target:
                return g.get("id")
        return None
    except Exception as e:
        print(f"Error searching group by name {name}: {e}")
        return None


async def search_group_by_name_and_nickname_prefix(name: str, nickname_prefix: str) -> str | None:
    """Busca un grupo que coincida por displayName exacto Y cuyo mailNickname
    empiece con el prefijo dado. El mismo nombre de curso puede repetirse en
    distintos períodos académicos (materias que se repiten cada semestre) —
    matchear solo por nombre reutilizaría por error el equipo de un período
    viejo. El mailNickname se genera a partir de nombre+período (ver
    safe_mail_nickname), así que su prefijo identifica el período real."""
    if not nickname_prefix:
        return None
    try:
        params = {
            "$filter": f"startswith(displayName, '{_escape_odata(name)}')",
            "$select": "id,displayName,mailNickname"
        }
        res = await get("/groups", params=params)
        groups = res.get("value", [])
        target = _norm_group_name(name)
        for g in groups:
            if _norm_group_name(g.get("displayName", "")) != target:
                continue
            if (g.get("mailNickname") or "").lower().startswith(nickname_prefix.lower()):
                return g.get("id")
        return None
    except Exception as e:
        print(f"Error searching group by name+nickname {name}: {e}")
        return None

async def get_user_by_upn_exact(upn_or_id: str, select: str | None = None) -> dict | None:
    """Busca un usuario por userPrincipalName (o ID) EXACTO — a diferencia de
    search_users (que hace un $search difuso por displayName/UPN/mail y
    puede devolver a otra persona con nombre/correo parecido en el primer
    resultado), esto pega directo a /users/{upn} y sólo puede devolver ESE
    usuario o nada. Usar siempre que se tenga un correo/UPN exacto conocido
    y la intención sea recuperar esa cuenta puntual (ej. tras un "already
    exists" al crearla), no buscar por texto."""
    try:
        return await get(f"/users/{upn_or_id}", params={"$select": select or "id,displayName,userPrincipalName,mail"})
    except Exception:
        return None


async def search_users(query: str, select: str | None = None) -> list[Any]:
    """Search users using Graph $search (requires ConsistencyLevel: eventual)."""
    params: dict = {
        "$search": f'"displayName:{query}" OR "userPrincipalName:{query}" OR "mail:{query}"',
        "$top": 50,
        "$select": select or "id,displayName,userPrincipalName,mail,department,jobTitle,accountEnabled",
        "$orderby": "displayName",
    }
    return await paginate_limited(
        "/users", params,
        max_records=200,
        extra_headers={"ConsistencyLevel": "eventual"},
    )


async def create_team_via_group(
    display_name: str,
    mail_nickname: str,
    description: str,
    visibility: str,
    owner_ids: list[str],
    member_ids: list[str] | None = None,
    email: str | None = None,
    replication_wait: int = 20,
    provision_timeout: int = 60,
) -> dict:
    """Create a Microsoft Team using the reliable Group → Team flow.

    Workaround for POST /teams with templates, which fails on many tenants
    with 'Failed to execute Templates backend request'.

    Steps:
      1. POST /groups  — create M365 group with owners + members + optional email alias
      2. Wait for Azure AD replication (~20s)
      3. PUT /groups/{id}/team  — provision Teams on the group
    """
    owner_binds  = [f"{_GRAPH}/users/{uid}" for uid in owner_ids]
    member_binds = [f"{_GRAPH}/users/{uid}" for uid in (member_ids or [])]

    group_payload: dict[str, Any] = {
        "displayName":   display_name,
        "mailNickname":  mail_nickname,
        "description":   description or "",
        "groupTypes":    ["Unified"],
        "mailEnabled":   True,
        "securityEnabled": False,
        "visibility":    visibility,
        "owners@odata.bind":  owner_binds,
    }
    if member_binds:
        group_payload["members@odata.bind"] = member_binds
    if email:
        group_payload["mail"] = email

    long_client = httpx.AsyncClient(timeout=httpx.Timeout(90.0))
    async with long_client as c:
        # Step 1 — create the group
        rg = await c.post(f"{_GRAPH}/groups", headers=_headers(), json=group_payload)
        if rg.status_code not in (200, 201):
            try:
                err = rg.json().get("error", {})
                detail = err.get("message", rg.text[:300])
            except Exception:
                detail = rg.text[:300]
            raise RuntimeError(f"Group creation failed ({rg.status_code}): {detail}")

        group_id = rg.json()["id"]

        # Step 2 — wait for Azure AD replication
        await asyncio.sleep(replication_wait)

        # Step 3 — provision Teams, retry up to provision_timeout seconds
        team_payload = {
            "memberSettings":   {"allowCreateUpdateChannels": True},
            "messagingSettings": {"allowUserEditMessages": True, "allowUserDeleteMessages": True},
            "funSettings":      {"allowGiphy": False},
        }
        deadline = time.time() + provision_timeout
        last_err = ""
        while time.time() < deadline:
            rt = await c.put(
                f"{_GRAPH}/groups/{group_id}/team",
                headers=_headers(), json=team_payload,
            )
            if rt.status_code in (200, 201):
                return {**rt.json(), "id": rt.json().get("id", group_id)}
            try:
                last_err = rt.json().get("error", {}).get("message", rt.text[:200])
            except Exception:
                last_err = rt.text[:200]
            await asyncio.sleep(5)

        # If Teams provisioning failed, clean up the orphan group
        try:
            await c.delete(f"{_GRAPH}/groups/{group_id}", headers=_headers())
        except Exception:
            pass
        raise RuntimeError(
            f"Teams provisioning did not complete within {provision_timeout}s. "
            f"Group {group_id} was deleted. Last error: {last_err}"
        )


async def post_team(payload: dict, poll_timeout: int = 60) -> dict:
    """Legacy POST /teams wrapper — kept for backwards compatibility.
    Internally delegates to create_team_via_group when the template API fails.
    """
    async with httpx.AsyncClient(timeout=_TIMEOUT) as c:
        r = await c.post(f"{_GRAPH}/teams", headers=_headers(), json=payload)
        if r.status_code in (200, 201) and r.content:
            return r.json()
        if r.status_code == 202:
            location = r.headers.get("Location") or r.headers.get("Content-Location", "")
            team_id_match = re.search(r"teams\('([^']+)'\)|teams/([0-9a-f-]{36})", location)
            deadline = time.time() + poll_timeout
            while time.time() < deadline:
                await asyncio.sleep(3)
                if team_id_match:
                    tid = team_id_match.group(1) or team_id_match.group(2)
                    rp = await c.get(f"{_GRAPH}/teams/{tid}", headers=_headers())
                    if rp.status_code == 200:
                        return rp.json()
            raise TimeoutError(f"Team provisioning timed out. Location: {location}")
        try:
            err = r.json().get("error", {})
            detail = err.get("message", r.text[:300])
        except Exception:
            detail = r.text[:300]
        raise RuntimeError(f"POST /teams failed ({r.status_code}): {detail}")

@_retry
async def update_user_password(user_id_or_upn: str, new_password: str) -> None:
    """Resets the user's password in Azure AD. Requires User.ReadWrite.All permission."""
    payload = {
        "passwordProfile": {
            "forceChangePasswordNextSignIn": False,
            "password": new_password
        }
    }
    async with httpx.AsyncClient(timeout=_TIMEOUT) as c:
        r = await c.patch(f"{_GRAPH}/users/{user_id_or_upn}", headers=_headers(), json=payload)
        _raise(r)

@_retry
async def get_subscribed_skus() -> list:
    """Gets the license SKUs available in the tenant."""
    async with httpx.AsyncClient(timeout=_TIMEOUT) as c:
        r = await c.get(f"{_GRAPH}/subscribedSkus", headers=_headers())
        _raise(r)
        return r.json().get("value", [])


async def list_deleted_groups() -> list[dict]:
    """Lista los Microsoft 365 Groups eliminados recientemente — recuperables
    desde la papelera de Azure AD hasta 30 días después de su borrado."""
    return await paginate(
        "/directory/deletedItems/microsoft.graph.group",
        params={"$select": "id,displayName,mailNickname,createdDateTime,deletedDateTime"},
    )


async def restore_deleted_group(group_id: str) -> dict:
    """Restaura un grupo eliminado (dentro de la ventana de 30 días)."""
    return await post(f"/directory/deletedItems/{group_id}/restore", {})


async def get_group_name_by_id(group_id: str) -> str | None:
    """Get Microsoft 365 Group name by ID."""
    try:
        res = await get(f"/groups/{group_id}", params={"$select": "displayName"})
        if res and "displayName" in res:
            return res["displayName"]
    except Exception:
        pass
    return None


async def remove_member_from_group(group_id: str, user_id: str) -> bool:
    """Remove a member from a Microsoft 365 Group."""
    try:
        await delete(f"/groups/{group_id}/members/{user_id}/$ref")
        return True
    except Exception:
        pass
    return False

async def remove_owner_from_group(group_id: str, user_id: str) -> bool:
    """Remove an owner from a Microsoft 365 Group."""
    try:
        await delete(f"/groups/{group_id}/owners/{user_id}/$ref")
        return True
    except Exception:
        pass
    return False


async def add_member_to_group(group_id: str, user_id: str) -> bool:
    try:
        await post(f'/groups/{group_id}/members/', {'@odata.id': f'https://graph.microsoft.com/v1.0/directoryObjects/{user_id}'})
        return True
    except Exception:
        return False


async def send_mail(
    mailbox: str, subject: str, html_body: str, to_email: str,
    cc: list[str] | None = None,
    attachments: list[tuple[str, bytes, str]] | None = None,
) -> None:
    """Envía un correo con la API de Microsoft Graph (POST /users/{mailbox}/sendMail),
    autenticado con las credenciales de la app registration (permiso de
    aplicación 'Mail.Send' con consentimiento de administrador).

    A diferencia de SMTP, no depende de MFA ni de contraseñas de buzón —
    usa el mismo token de aplicación que ya se usa para crear usuarios y
    gestionar Teams.

    `attachments` es una lista de (nombre_archivo, contenido_bytes, content_type).
    """
    message: dict = {
        "subject": subject,
        "body": {"contentType": "HTML", "content": html_body},
        "toRecipients": [{"emailAddress": {"address": to_email}}],
    }
    if cc:
        message["ccRecipients"] = [{"emailAddress": {"address": addr}} for addr in cc]
    if attachments:
        import base64
        message["attachments"] = [
            {
                "@odata.type": "#microsoft.graph.fileAttachment",
                "name": name,
                "contentType": content_type,
                "contentBytes": base64.b64encode(content).decode(),
            }
            for name, content, content_type in attachments
        ]
    await post(f"/users/{mailbox}/sendMail", {"message": message, "saveToSentItems": True})


# Tamaño de trozo recomendado por la documentación de Graph para
# uploadSession: debe ser múltiplo de 320 KiB.
_UPLOAD_CHUNK_SIZE = 4 * 320 * 1024  # 1.25 MB


def _is_transient_upload_error(e: Exception) -> bool:
    """Dos condiciones puntuales de Graph que ameritan reintentar todo el
    envío desde cero (con un borrador nuevo):

    - `ErrorItemNotFound` justo después de crear el borrador: consistencia
      eventual (el borrador todavía no está disponible en el backend que
      atiende la siguiente llamada), no un error real.
    - `ApplicationThrottled` ("Application is over its IncomingBytes
      limit"): la app superó el límite de bytes entrantes que Graph permite
      en un lapso corto — se ve al enviar varios adjuntos grandes (~7MB
      c/u) seguidos. Baja solo esperando.

    No se reintentan otros 404/429 (ej. buzón inexistente, límite de
    envíos por día) — solo estos dos códigos puntuales."""
    if not isinstance(e, HTTPException):
        return False
    detail = str(e.detail)
    return (e.status_code == 404 and "ItemNotFound" in detail) or "ApplicationThrottled" in detail


_upload_retry = retry(
    retry=retry_if_exception(_is_transient_upload_error),
    stop=stop_after_attempt(5),
    wait=wait_exponential(min=2, max=30),
    reraise=True,
)


async def _upload_attachment_to_draft(mailbox: str, message_id: str, name: str, content: bytes) -> None:
    """Sube UN adjunto a un borrador ya creado, vía upload session en trozos
    (ver `send_mail_with_attachments` para el porqué de este flujo)."""
    session = await post(
        f"/users/{mailbox}/messages/{message_id}/attachments/createUploadSession",
        {"AttachmentItem": {"attachmentType": "file", "name": name, "size": len(content)}},
    )
    upload_url = session["uploadUrl"]

    total = len(content)
    client = _client(timeout=httpx.Timeout(120.0))
    start = 0
    while start < total:
        end = min(start + _UPLOAD_CHUNK_SIZE, total)
        chunk = content[start:end]
        # La uploadUrl ya trae su propia autenticación embebida — Graph
        # documenta explícitamente NO mandar el header Authorization acá.
        r = await client.put(
            upload_url,
            content=chunk,
            headers={
                "Content-Length": str(len(chunk)),
                "Content-Range": f"bytes {start}-{end - 1}/{total}",
            },
        )
        if r.is_error:
            raise HTTPException(status_code=r.status_code, detail=f"Error subiendo adjunto grande ({name}): {r.text[:300]}")
        start = end


@_upload_retry
async def send_mail_with_attachments(
    mailbox: str, subject: str, html_body: str, to_email: str,
    attachments: list[tuple[str, bytes, str]],
    cc: list[str] | None = None,
) -> None:
    """Envía un correo con uno o más adjuntos "grandes" vía Microsoft Graph.

    `sendMail` (ver `send_mail`) solo admite adjuntos "simples" de hasta
    ~3MB en total porque van codificados en base64 dentro del mismo cuerpo
    JSON. Para adjuntos más grandes (o varios adjuntos cuya suma supera ese
    límite — p. ej. los dos instructivos de MBA, ~4MB juntos), Graph exige
    un flujo de 3 pasos: crear el mensaje como borrador, subirle cada
    adjunto en trozos vía una "upload session" propia, y recién ahí
    enviarlo (POST .../send). Si algún paso falla después de crear el
    borrador, se intenta borrarlo para no dejar basura en Borradores —
    incluyendo entre reintentos, así cada intento parte de un borrador
    nuevo en vez de acumular basura.
    """
    message: dict = {
        "subject": subject,
        "body": {"contentType": "HTML", "content": html_body},
        "toRecipients": [{"emailAddress": {"address": to_email}}],
    }
    if cc:
        message["ccRecipients"] = [{"emailAddress": {"address": addr}} for addr in cc]

    draft = await post(f"/users/{mailbox}/messages", message)
    message_id = draft["id"]

    try:
        # Pequeño margen para la consistencia eventual de Graph: el borrador
        # recién creado a veces todavía no está disponible para la próxima
        # llamada, sobre todo con varias filas en paralelo.
        await asyncio.sleep(0.3)

        for name, content, _content_type in attachments:
            await _upload_attachment_to_draft(mailbox, message_id, name, content)

        await post(f"/users/{mailbox}/messages/{message_id}/send", {})
    except Exception:
        try:
            await delete(f"/users/{mailbox}/messages/{message_id}")
        except Exception:
            pass
        raise


async def send_mail_with_large_attachment(
    mailbox: str, subject: str, html_body: str, to_email: str,
    attachment_name: str, attachment_bytes: bytes, attachment_content_type: str,
    cc: list[str] | None = None,
) -> None:
    """Compatibilidad: envío con UN solo adjunto grande (ver `send_mail_with_attachments`)."""
    await send_mail_with_attachments(
        mailbox, subject, html_body, to_email,
        attachments=[(attachment_name, attachment_bytes, attachment_content_type)],
        cc=cc,
    )


# ═══════════════════════════════════════════════════════════════════════════
# Reportes: licencias huérfanas, cuentas inactivas, actividad de Teams,
# verificación de envío de correo contra el buzón real de Outlook.
# ═══════════════════════════════════════════════════════════════════════════

async def list_disabled_users_with_licenses() -> list[dict]:
    """Cuentas deshabilitadas (accountEnabled=false) que todavía tienen
    licencias asignadas: asientos pagos ocupados por cuentas abandonadas.
    Requiere User.ReadWrite.All (ya concedido)."""
    users = await paginate("/users", params={
        "$select": "id,displayName,userPrincipalName,accountEnabled,assignedLicenses",
        "$filter": "accountEnabled eq false",
    })
    licensed = [u for u in users if u.get("assignedLicenses")]
    if not licensed:
        return licensed

    sku_names = {
        sku["skuId"]: sku.get("skuPartNumber", sku["skuId"])
        for sku in await get_subscribed_skus()
        if sku.get("skuId")
    }
    for u in licensed:
        u["licenseNames"] = [
            sku_names.get(lic.get("skuId"), lic.get("skuId"))
            for lic in u.get("assignedLicenses", [])
        ]
    return licensed


async def get_inactive_users(min_days_inactive: int = 60) -> list[dict]:
    """Cuentas habilitadas sin inicio de sesión en los últimos `min_days_inactive`
    días (o que nunca iniciaron sesión).

    Requiere el permiso de aplicación 'AuditLog.Read.All' con consentimiento de
    administrador en Azure Portal — NO está incluido en el scope actual
    (User.ReadWrite.All / GroupMember.ReadWrite.All / Team.ReadBasic.All).
    Sin ese permiso, Graph devuelve 403 al pedir 'signInActivity'.
    """
    from datetime import datetime, timedelta, timezone

    users = await paginate("/users", params={
        "$select": "id,displayName,userPrincipalName,accountEnabled,signInActivity",
        "$filter": "accountEnabled eq true",
    })
    cutoff = datetime.now(timezone.utc) - timedelta(days=min_days_inactive)
    inactive = []
    for u in users:
        last = (u.get("signInActivity") or {}).get("lastSignInDateTime")
        if not last:
            inactive.append({**u, "last_signin": None})
            continue
        try:
            dt = datetime.fromisoformat(last.replace("Z", "+00:00"))
            if dt < cutoff:
                inactive.append({**u, "last_signin": last})
        except Exception:
            pass
    return inactive


async def get_teams_activity_report(period: str = "D90") -> list[dict]:
    """Reporte de actividad de Teams por usuario (mensajes, reuniones, llamadas)
    de los últimos `period` (D7/D30/D90/D180).

    Requiere el permiso de aplicación 'Reports.Read.All' con consentimiento de
    administrador — NO está incluido en el scope actual. Además, si el tenant
    tiene activado el "ocultamiento de datos de reportes" (Reports concealment)
    en el Admin Center, los nombres/UPN vienen anonimizados y hay que
    desactivar esa opción para poder identificar a los usuarios.
    """
    import csv
    import io as _io

    async with httpx.AsyncClient(timeout=_TIMEOUT) as c:
        r = await c.get(
            f"{_GRAPH}/reports/getTeamsUserActivityUserDetail(period='{period}')",
            headers=_headers(),
        )
        _raise(r)
        reader = csv.DictReader(_io.StringIO(r.text))
        return list(reader)


async def search_sent_email(to_email: str, since_iso: str) -> bool:
    """Busca en la carpeta 'Enviados' del buzón SMTP_FROM un correo dirigido a
    `to_email` posterior a `since_iso` (ISO 8601 UTC), para confirmar que
    realmente salió del buzón.

    Requiere el permiso de aplicación 'Mail.Read' (o 'Mail.ReadBasic.All') con
    consentimiento de administrador, con acceso al buzón SMTP_FROM — NO está
    incluido en el scope actual.
    """
    mailbox = settings.smtp_from
    if not mailbox:
        raise ValueError("SMTP_FROM no está configurado; no hay buzón sobre el cual verificar.")

    safe_email = to_email.replace("'", "''")
    headers = _headers()
    headers["ConsistencyLevel"] = "eventual"
    params = {
        "$filter": (
            f"toRecipients/any(r:r/emailAddress/address eq '{safe_email}') "
            f"and sentDateTime ge {since_iso}"
        ),
        "$select": "id,subject,sentDateTime",
        "$count": "true",
        "$top": "1",
    }
    async with httpx.AsyncClient(timeout=_TIMEOUT) as c:
        r = await c.get(
            f"{_GRAPH}/users/{mailbox}/mailFolders/SentItems/messages",
            headers=headers, params=params,
        )
        _raise(r)
        return len(r.json().get("value", [])) > 0
