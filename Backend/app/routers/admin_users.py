"""Gestión de perfiles con acceso al sistema (quién puede iniciar sesión).

Solo los correos fijos en la variable de entorno ADMIN_ALLOWED_EMAILS
(ver auth.is_super_admin) pueden agregar o quitar otros perfiles acá. El
login en sí sigue siendo el mismo de siempre (cuenta institucional de
Microsoft vía Azure AD) — esto solo administra la lista de quién tiene
permiso de entrar, sin crear contraseñas nuevas ni nada por el estilo.
"""
from fastapi import APIRouter, HTTPException, Depends, Request
from pydantic import BaseModel, EmailStr

from app.core import database
from app.core.config import settings
from app.services import auth as auth_service

router = APIRouter(prefix="/admin/users", tags=["Administración de Accesos"])


def require_super_admin(request: Request) -> dict:
    user = auth_service.get_user_from_request(request)
    if not user:
        raise HTTPException(status_code=401, detail="No autenticado")
    if not auth_service.is_super_admin(user.get("email", "")):
        raise HTTPException(status_code=403, detail="Solo el administrador principal puede gestionar accesos al sistema")
    return user


class AddAdminUserRequest(BaseModel):
    email: EmailStr
    name: str = ""


@router.get("", summary="Listar perfiles con acceso al sistema")
async def list_admin_users(current_user: dict = Depends(require_super_admin)):
    fixed = [{"email": e} for e in sorted(settings.admin_allowed_emails_set)]
    added = await database.get_admin_users()
    return {"fixed": fixed, "added": added}


@router.post("", summary="Agregar un nuevo perfil con acceso")
async def add_admin_user(body: AddAdminUserRequest, current_user: dict = Depends(require_super_admin)):
    email = body.email.strip().lower()
    if email in settings.admin_allowed_emails_set:
        raise HTTPException(status_code=400, detail="Ese correo ya tiene acceso permanente — no hace falta agregarlo.")

    ok = await database.add_admin_user(email, body.name.strip(), current_user["email"])
    if not ok:
        raise HTTPException(status_code=500, detail="No se pudo guardar el nuevo perfil.")

    await auth_service.refresh_admin_users_cache()
    return {"status": "success", "email": email}


@router.delete("/{email}", summary="Quitar acceso a un perfil agregado")
async def remove_admin_user(email: str, current_user: dict = Depends(require_super_admin)):
    email = email.strip().lower()
    if email in settings.admin_allowed_emails_set:
        raise HTTPException(status_code=400, detail="Ese correo tiene acceso permanente por configuración del servidor — no se puede quitar desde acá.")

    ok = await database.remove_admin_user(email)
    if not ok:
        raise HTTPException(status_code=404, detail="Ese correo no estaba en la lista de agregados.")

    await auth_service.refresh_admin_users_cache()
    return {"status": "success"}
