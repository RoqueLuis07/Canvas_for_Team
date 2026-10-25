import logging
import warnings

from pydantic import field_validator
from pydantic_settings import BaseSettings, SettingsConfigDict

logger = logging.getLogger(__name__)

_DEFAULT_SECRET = "changeme-please-set-a-secret"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    # Canvas
    canvas_base_url: str = "https://canvas.instructure.com"
    canvas_access_token: str = ""
    canvas_account_id: str = "1"

    # Supabase
    supabase_database_url: str = ""

    # Azure
    azure_tenant_id: str = ""
    azure_client_id: str = ""
    azure_client_secret: str = ""
    azure_sku_students: str = "STANDARDWOFFPACK_STUDENT"
    azure_sku_teachers: str = "STANDARDWOFFPACK_FACULTY"

    # App
    port: int = 3000
    environment: str = "development"
    site_url: str = "http://localhost:3000"
    secret_key: str = _DEFAULT_SECRET
    auth_cookie_name: str = "usil_auth"
    sentry_dsn: str = ""

    # Institutional domain & defaults
    institutional_domain: str = "usil.edu.py"
    usage_location: str = "PY"
    teams_url: str = "https://teams.microsoft.com"

    # Envío de correos de bienvenida/credenciales — Microsoft Graph sendMail,
    # autenticado con las credenciales de Azure de arriba (requiere el
    # permiso de aplicación 'Mail.Send' con consentimiento de administrador).
    # SMTP_FROM es el buzón desde el que se envía.
    smtp_from: str = "it@usil.edu.py"

    # Lista de correos institucionales autorizados a iniciar sesión en el
    # sistema (separados por coma). Solo el personal de TI administrador
    # debe estar acá — alumnos y docentes nunca deben tener acceso.
    admin_allowed_emails: str = "resteche@usil.edu.py"

    @property
    def admin_allowed_emails_set(self) -> set[str]:
        return {e.strip().lower() for e in self.admin_allowed_emails.split(",") if e.strip()}

    # Apagado por defecto (False = comportamiento normal de producción: se
    # manda copia al personal administrativo como siempre). Se activa poniendo
    # DISABLE_STAFF_NOTIFICATIONS=true SOLO en el entorno/servicio de pruebas,
    # para que una corrida de prueba contra datos/credenciales reales no le
    # llene la bandeja a nadie de USIL ni agregue gente real como propietaria
    # de un Team de prueba. El destinatario principal (el alumno/docente de la
    # fila que se está probando) nunca se filtra acá — solo las copias/dueños
    # "fijos" que el sistema agrega por su cuenta.
    disable_staff_notifications: bool = False



    @field_validator("secret_key")
    @classmethod
    def validate_secret_key(cls, v: str) -> str:
        if v == _DEFAULT_SECRET:
            msg = (
                "SECRET_KEY usa el valor por defecto inseguro. "
                "Establece SECRET_KEY en el archivo .env antes de desplegar en producción."
            )
            warnings.warn(msg, stacklevel=2)
            logger.warning(msg)
        return v

    @property
    def is_insecure_secret(self) -> bool:
        return self.secret_key == _DEFAULT_SECRET


settings = Settings()
