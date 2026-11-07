"""Microsoft Teams / Azure AD user management endpoints."""
import asyncio
from typing import Annotated

from fastapi import APIRouter, HTTPException, Query, Request
from pydantic import BaseModel
from starlette.exceptions import HTTPException as StarletteHTTPException

from app.core.config import settings
from app.core import cache, audit, database
from app.models.teams import BulkResult, BulkTeamsUserCreate, TeamsUserCreate, TeamsUserUpdate
from app.services import teams_client as graph
from app.services import auth as auth_service

router = APIRouter(prefix="/teams/users", tags=["Teams · Users"])
_BULK_SEM = asyncio.Semaphore(5)


class BulkDeleteRequest(BaseModel):
    ids: list[str]


@router.get("", summary="Listar usuarios del directorio")
async def list_users(
    search: Annotated[str | None, Query(description="Buscar por displayName, UPN o mail")] = None,
    top: Annotated[int, Query(ge=1, le=999)] = 999,
    max_records: Annotated[int, Query(ge=1, le=10000)] = 5000,
):
    select = graph.DEFAULT_USER_SELECT_FIELDS
    if search:
        # For searches: try DB first (instant), fall back to Graph if DB empty
        db_result = await database.get_azure_users(search)
        if db_result:
            return db_result
        return await graph.search_users(search, select)

    cache_key = f"teams:users:{top}:{max_records}"
    cached = cache.get(cache_key)
    if cached is not None:
        return cached

    # Serve from local DB if fresh
    if not await database.is_stale("azure_users") and await database.count_azure_users() > 0:
        db_result = await database.get_azure_users()
        if db_result:
            cache.set(cache_key, db_result, ttl=120)
            return db_result

    params: dict = {"$top": top, "$select": select, "$orderby": "displayName"}
    result = await graph.paginate_limited("/users", params, max_records)
    await database.upsert_azure_users(result)
    await database.mark_synced("azure_users")
    cache.set(cache_key, result, ttl=120)
    return result


@router.get("/{user_id}", summary="Obtener usuario por ID o UPN")
async def get_user(user_id: str):
    try:
        return await graph.get(f"/users/{user_id}", params={"$select": graph.DEFAULT_USER_SELECT_FIELDS})
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=404, detail=str(exc))


@router.post("", status_code=201, summary="Crear usuario individual en Azure AD")
async def create_user(body: TeamsUserCreate):
    payload = {
        "displayName": body.display_name,
        "givenName": body.given_name,
        "surname": body.surname,
        "userPrincipalName": body.user_principal_name,
        "mailNickname": body.mail_nickname,
        "department": body.department,
        "jobTitle": body.job_title,
        "usageLocation": body.usage_location,
        "accountEnabled": body.account_enabled,
        "passwordProfile": {
            "forceChangePasswordNextSignIn": False,
            "password": body.password,
        },
    }
    try:
        data = await graph.post("/users", {k: v for k, v in payload.items() if v is not None})
        sku = settings.azure_sku_teachers if body.role == "teacher" else settings.azure_sku_students
        await graph.assign_license(data["id"], sku)
        await database.upsert_azure_users([data])
        cache.patch_list("teams:users:", data.get("id"), data, id_field="id", action="create")
        return data
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))


@router.post("/bulk", summary="Crear usuarios de forma conjunta en Azure AD")
async def create_users_bulk(body: BulkTeamsUserCreate, request: Request) -> BulkResult:
    result = BulkResult()

    async def _create(user: TeamsUserCreate):
        try:
            payload = {
                "displayName": user.display_name,
                "userPrincipalName": user.user_principal_name,
                "mailNickname": user.mail_nickname,
                "usageLocation": user.usage_location,
                "accountEnabled": user.account_enabled,
                "passwordProfile": {"forceChangePasswordNextSignIn": False, "password": user.password},
            }
            if user.given_name:
                payload["givenName"] = user.given_name
            if user.surname:
                payload["surname"] = user.surname
            if user.department:
                payload["department"] = user.department
            if user.job_title:
                payload["jobTitle"] = user.job_title
            data = await graph.post("/users", payload)
            sku = settings.azure_sku_teachers if user.role == "teacher" else settings.azure_sku_students
            await graph.assign_license(data["id"], sku)
            result.succeeded.append(data)
        except Exception as exc:
            result.failed.append({"input": user.model_dump(exclude={"password"}), "error": str(exc)})

    async def _create_throttled(user: TeamsUserCreate):
        async with _BULK_SEM:
            await _create(user)

    await asyncio.gather(*[_create_throttled(u) for u in body.users])
    cache.invalidate("teams:users:")
    req_user = auth_service.get_user_from_request(request) if hasattr(request, "cookies") else None
    audit.log("import", "teams_users_bulk",
              user=req_user.get("email","?") if req_user else "api",
              detail=f"{len(result.succeeded)} ok / {len(result.failed)} errores")
    return result


@router.patch("/{user_id}", summary="Actualizar usuario")
async def update_user(user_id: str, body: TeamsUserUpdate):
    fields = body.model_dump(exclude_none=True)
    # Convert snake_case → camelCase
    mapping = {
        "display_name": "displayName",
        "given_name": "givenName",
        "surname": "surname",
        "department": "department",
        "job_title": "jobTitle",
        "account_enabled": "accountEnabled",
        "mobile_phone": "mobilePhone",
    }
    payload = {mapping[k]: v for k, v in fields.items() if k in mapping}
    try:
        data = await graph.patch(f"/users/{user_id}", payload)
        cache.patch_list("teams:users:", user_id, payload, id_field="id", action="update")
        return data
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))


class ResetPasswordPayload(BaseModel):
    password: str


@router.post("/{user_id}/reset-password", summary="Restablecer contraseña de un usuario en Teams")
async def reset_password_admin(user_id: str, body: ResetPasswordPayload, request: Request):
    payload = {
        "passwordProfile": {
            "forceChangePasswordNextSignIn": False,
            "password": body.password,
        }
    }
    try:
        await graph.patch(f"/users/{user_id}", payload)
        req_user = auth_service.get_user_from_request(request) if hasattr(request, "cookies") else None
        audit.log("update", "teams_reset_password",
                  user=req_user.get("email", "?") if req_user else "api",
                  detail=f"Contraseña de {user_id} restablecida por admin")
        return {"status": "success", "user_id": user_id}
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))


@router.delete("/bulk", summary="Eliminar usuarios de Azure AD en masa")
async def delete_users_bulk(body: BulkDeleteRequest, request: Request) -> BulkResult:
    result = BulkResult()

    async def _del(uid: str):
        async with _BULK_SEM:
            try:
                await graph.delete(f"/users/{uid}")
                await database.delete_azure_user(uid)
                result.succeeded.append({"id": uid})
            except Exception as exc:
                result.failed.append({"id": uid, "error": str(exc)})

    await asyncio.gather(*[_del(uid) for uid in body.ids])
    cache.invalidate("teams:users:")
    req_user = auth_service.get_user_from_request(request) if hasattr(request, "cookies") else None
    audit.log("delete", "azure_users_bulk",
              user=req_user.get("email", "?") if req_user else "api",
              detail=f"{len(result.succeeded)} eliminados / {len(result.failed)} errores")
    return result


@router.delete("/{user_id}", summary="Eliminar usuario de Azure AD")
async def delete_user(user_id: str):
    try:
        await graph.delete(f"/users/{user_id}")
        await database.delete_azure_user(user_id)
        cache.patch_list("teams:users:", user_id, None, id_field="id", action="delete")
        return {"deleted": user_id}
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))

