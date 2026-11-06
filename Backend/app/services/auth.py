import base64
import hashlib
import hmac
import json
import time
from typing import Any

import httpx
from fastapi import HTTPException, Request
from msal import ConfidentialClientApplication

from app.core.config import settings

_AUTH_SCOPES = ["User.Read"]


def _encode(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode("utf-8").rstrip("=")


def _decode(value: str) -> bytes:
    padding = "=" * (-len(value) % 4)
    return base64.urlsafe_b64decode(value + padding)


def _sign(data: bytes) -> str:
    return _encode(hmac.new(settings.secret_key.encode(), data, hashlib.sha256).digest())


def _serialize(payload: dict[str, Any]) -> str:
    payload_bytes = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    return _encode(payload_bytes)


def _deserialize(payload: str) -> dict[str, Any]:
    return json.loads(_decode(payload).decode("utf-8"))


def create_session_token(user: dict[str, Any]) -> str:
    payload = {
        "sub": user.get("sub"),
        "name": user.get("name"),
        "email": user.get("email"),
        "exp": int(time.time()) + 60 * 60 * 8,
    }
    serialized = _serialize(payload)
    signature = _sign(serialized.encode("utf-8"))
    return f"{serialized}.{signature}"


def validate_session_token(token: str) -> dict[str, Any]:
    try:
        serialized, signature = token.split(".", 1)
    except ValueError:
        raise HTTPException(status_code=401, detail="Token de sesión inválido")

    expected = _sign(serialized.encode("utf-8"))
    if not hmac.compare_digest(expected, signature):
        raise HTTPException(status_code=401, detail="Firma de sesión inválida")

    payload = _deserialize(serialized)
    if payload.get("exp", 0) < int(time.time()):
        raise HTTPException(status_code=401, detail="Sesión expirada")

    return payload


def get_redirect_uri(request: Request = None) -> str:
    # Si SITE_URL no es localhost, confiamos en él explícitamente
    if settings.site_url and "localhost" not in settings.site_url and "127.0.0.1" not in settings.site_url:
        return f"{settings.site_url.rstrip('/')}/auth/callback"
        
    if request:
        scheme = request.headers.get("x-forwarded-proto", request.url.scheme)
        # Use HTTP_HOST or X-Forwarded-Host, fallback to request.url.hostname
        host = request.headers.get("x-forwarded-host", request.url.hostname)
        port = request.headers.get("x-forwarded-port")
        
        # Local development uses the actual request port
        if host in ("localhost", "127.0.0.1"):
            if request.url.port:
                return f"{scheme}://{host}:{request.url.port}/auth/callback"
            return f"{scheme}://{host}/auth/callback"
            
        # Cloud/Proxy - Force HTTPS for Azure AD security rules
        scheme = "https"
            
        if port and port not in ("80", "443"):
            return f"{scheme}://{host}:{port}/auth/callback"
            
        # Standard cloud production (e.g. Railway 443)
        return f"{scheme}://{host}/auth/callback"

    return f"{settings.site_url.rstrip('/')}/auth/callback"


def build_auth_url(request: Request = None) -> str:
    app = ConfidentialClientApplication(
        client_id=settings.azure_client_id,
        client_credential=settings.azure_client_secret,
        authority=f"https://login.microsoftonline.com/{settings.azure_tenant_id}",
    )
    try:
        return app.get_authorization_request_url(
            scopes=_AUTH_SCOPES,
            redirect_uri=get_redirect_uri(request),
            response_mode="query",
            state="usil-login",
            prompt="select_account",
        )
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"Error construyendo la URL de inicio de sesión: {exc}")


def exchange_code(code: str, request: Request = None) -> dict[str, Any]:
    app = ConfidentialClientApplication(
        client_id=settings.azure_client_id,
        client_credential=settings.azure_client_secret,
        authority=f"https://login.microsoftonline.com/{settings.azure_tenant_id}",
    )
    try:
        result = app.acquire_token_by_authorization_code(
            code,
            scopes=_AUTH_SCOPES,
            redirect_uri=get_redirect_uri(request),
        )
    except Exception as exc:
        raise HTTPException(status_code=400, detail=f"Error al intercambiar el código de Azure AD: {exc}")

    if not result or "error" in result:
        raise HTTPException(status_code=400, detail=result.get("error_description", result.get("error", "Error de autenticación")))

    claims = result.get("id_token_claims") or {}
    email = claims.get("preferred_username") or claims.get("email")
    name = claims.get("name") or claims.get("preferred_username")
    sub = claims.get("sub") or claims.get("oid")

    if not email or not name or not sub:
        access_token = result.get("access_token")
        if not access_token:
            raise HTTPException(status_code=400, detail="No se pudo obtener el token de acceso de Azure AD")
        try:
            with httpx.Client(timeout=10) as client:
                resp = client.get(
                    "https://graph.microsoft.com/v1.0/me",
                    headers={"Authorization": f"Bearer {access_token}"},
                )
                resp.raise_for_status()
                profile = resp.json()
        except Exception as exc:
            raise HTTPException(status_code=400, detail=f"No se pudo obtener el perfil de usuario de Graph: {exc}")

        email = email or profile.get("mail") or profile.get("userPrincipalName")
        name = name or profile.get("displayName") or email
        sub = sub or profile.get("id") or email

    if not email:
        raise HTTPException(status_code=400, detail="No se pudo obtener el correo electrónico del usuario autenticado")

    return {
        "sub": sub or email,
        "name": name or email,
        "email": email,
    }


# Correos agregados desde /admin/users (tabla admin_users), cacheados en
# memoria para no pegarle a la base de datos en CADA request autenticado
# (is_email_allowed/get_user_from_request se llaman en el middleware y en
# casi todas las rutas). Se refresca al arrancar la app y cada vez que se
# agrega/quita un perfil desde la web — si la base de datos falla al
# refrescar, se mantiene la cache anterior en vez de vaciarla, para no
# dejar a nadie afuera por un hiccup pasajero de Supabase.
_db_allowed_emails: set[str] = set()


async def refresh_admin_users_cache() -> None:
    from app.core import database
    try:
        rows = await database.get_admin_users()
        global _db_allowed_emails
        _db_allowed_emails = {r["email"].strip().lower() for r in rows if r.get("email")}
    except Exception:
        pass


def is_super_admin(email: str) -> bool:
    """Solo estos correos (variable de entorno ADMIN_ALLOWED_EMAILS) pueden
    agregar o quitar otros perfiles — ver /admin/users."""
    return bool(email) and email.strip().lower() in settings.admin_allowed_emails_set


def is_email_allowed(email: str) -> bool:
    """Puede iniciar sesión: el personal de TI fijo en ADMIN_ALLOWED_EMAILS,
    o cualquier perfil agregado desde /admin/users (ver is_super_admin)."""
    if not email:
        return False
    email = email.strip().lower()
    return email in settings.admin_allowed_emails_set or email in _db_allowed_emails


def get_user_from_request(request: Request) -> dict[str, Any] | None:
    token = request.cookies.get(settings.auth_cookie_name)
    if not token:
        return None
    try:
        payload = validate_session_token(token)
    except HTTPException:
        return None
    # Revalidar contra la lista vigente en cada request: si a alguien se le
    # revoca el acceso, deja de poder usar el sistema aunque su sesión
    # (de hasta 8h) todavía no haya expirado.
    if not is_email_allowed(payload.get("email", "")):
        return None
    return payload
