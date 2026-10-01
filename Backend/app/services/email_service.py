"""Envío de correos de credenciales (Microsoft Graph API — sendMail).

Replica el flujo de "Envío de Credenciales" / "Envío de Credenciales UBS"
del proceso manual anterior (referencias_excel/alumnos para crear.xlsm):
el alumno/docente recibe sus credenciales institucionales en su correo
personal, con copia a un conjunto de direcciones institucionales que
depende del tipo de programa.

El envío usa la API de Microsoft Graph (POST /users/{mailbox}/sendMail)
en vez de SMTP directo: reutiliza las mismas credenciales de la app
registration (AZURE_TENANT_ID/CLIENT_ID/CLIENT_SECRET) que ya se usan
para Canvas/Teams, sin depender de MFA ni de una contraseña de buzón.
Requiere el permiso de aplicación 'Mail.Send' con consentimiento de
administrador en Azure Portal.
"""
import logging
import mimetypes
from pathlib import Path

from fastapi import HTTPException

from app.core.config import settings
from app.services import teams_client as graph

logger = logging.getLogger(__name__)

# Límite documentado por Microsoft Graph para adjuntos "simples" (enviados
# inline en base64 dentro del cuerpo de sendMail, ver `graph.send_mail`).
# Por encima de esto hay que usar el flujo de upload session para adjuntos
# grandes (ver `graph.send_mail_with_large_attachment`).
_SMALL_ATTACHMENT_LIMIT = 3 * 1024 * 1024

# CC fijo compartido por todos los envíos de credenciales.
_BASE_CC = ["comercialcredenciales@usil.edu.py", "resteche@usil.edu.py"]

# CC adicional según el tipo de programa, replicando "Envio Credenciales"
# (grado) vs "Envio Credenciales UBS" (diplomados) de la planilla de referencia.
# "mba" usa el mismo CC que "diplomado" — mismo área (UBS), confirmado con
# el correo real que TI UBS venía enviando a mano para MBA.
_PROGRAM_CC: dict[str, list[str]] = {
    "diplomado": ["ubs@usil.edu.py", "glezcano@usil.edu.py"],
    "mba": ["ubs@usil.edu.py", "glezcano@usil.edu.py"],
    "grado": ["gradocredenciales@usil.edu.py"],
}

# Instructivos que el proceso manual anterior adjuntaba a los correos de
# Diplomados (referencias_excel/alumnos para crear.xlsm, hoja "Envio
# Credenciales UBS"). Se mantienen en el repo tal como estaban.
_BACKEND_DIR = Path(__file__).resolve().parents[2]
_DIPLOMADO_ATTACHMENTS_DIR = _BACKEND_DIR / "Archivos para los correos" / "Diplomados (UBS - USIL Business School)"
_DIPLOMADO_ATTACHMENTS = [
    _DIPLOMADO_ATTACHMENTS_DIR / "2° Acceso a la Plataforma Teams- Instructivo.pdf",
    _DIPLOMADO_ATTACHMENTS_DIR / "3° Descargar grabacion en TEAMS - Instructivo.pdf",
]

# Instructivos para alumnos de grado (Envío/Reenvío de Credenciales): acceso
# a Office 365, a Teams, cómo descargar una grabación, y 7 guías de Canvas,
# agrupados en un único ZIP con carpetas por plataforma ("1. Office 365",
# "2. Microsoft Teams", "3. Canvas"). Como el ZIP pesa ~7.3MB — por encima
# del límite de adjunto "simple" de Graph (~3MB, ver `send_mail`) — se
# envía con `send_mail_with_large_attachment` (flujo de upload session),
# que necesita el permiso de aplicación 'Mail.ReadWrite' con admin consent
# (ya concedido en Azure Portal — antes solo se tenía 'Mail.Send', lo que
# hacía fallar el job #20 con 403 al intentar crear el borrador).
_GRADO_ATTACHMENTS_DIR = _BACKEND_DIR / "Archivos para los correos" / "Alumnos (Grado)"
_GRADO_ATTACHMENT_ZIP = _GRADO_ATTACHMENTS_DIR / "Manuales e Instructivos de las Plataformas.zip"

# Datos de contacto de TI UBS, tal como aparecen en el correo real que el
# equipo venía enviando a mano (mismo texto, mismo WhatsApp).
_DIPLOMADO_CONTACT_EMAILS = ["glezcano@usil.edu.py", "resteche@usil.edu.py"]
_DIPLOMADO_WHATSAPP = "0991 856 488"
_DIPLOMADO_TEAMS_LINK = "https://teams.cloud.microsoft/"

# Instructivos de MBA (referencia: correo real "Accesos MBA | UBS Business
# School" enviado a mano por TI UBS, con estos mismos dos adjuntos).
_MBA_ATTACHMENTS_DIR = _BACKEND_DIR / "Archivos para los correos" / "MBA (UBS - USIL Business School)"
_MBA_ATTACHMENTS = [
    _MBA_ATTACHMENTS_DIR / "Tutorial de uso de Microsoft Teams.pdf",
    _MBA_ATTACHMENTS_DIR / "Tutorial_Canvas_MBA_USIL_v2.pdf",
]

# Contacto de soporte y SLA tal como figuran en ese mismo correo real.
_MBA_CONTACT_EMAILS = ["glezcano@usil.edu.py", "resteche@usil.edu.py"]
_MBA_WHATSAPP = "0991 856 488"
_MBA_TEAMS_LINK = "https://teams.cloud.microsoft/"
_MBA_SCHEDULE = "Lunes a viernes de 08:00 a 20:00 horas."
_MBA_SLA = "Hasta 24 horas hábiles."


def default_cc_for_program(program_type: str | None) -> list[str]:
    extra = _PROGRAM_CC.get((program_type or "").strip().lower(), [])
    return [*extra, *_BASE_CC]


def attachments_for_program(program_type: str | None) -> list[Path]:
    """Adjuntos fijos según el tipo de programa. Si algún archivo no existe
    en disco, se omite en vez de romper el envío del correo."""
    program_type_norm = (program_type or "").strip().lower()
    if program_type_norm == "diplomado":
        return [p for p in _DIPLOMADO_ATTACHMENTS if p.is_file()]
    if program_type_norm == "mba":
        return [p for p in _MBA_ATTACHMENTS if p.is_file()]
    if program_type_norm == "grado":
        return [_GRADO_ATTACHMENT_ZIP] if _GRADO_ATTACHMENT_ZIP.is_file() else []
    return []


def _read_attachments(attachments: list[Path] | None) -> list[tuple[str, bytes, str]]:
    out = []
    for path in attachments or []:
        try:
            ctype, _ = mimetypes.guess_type(path.name)
            out.append((path.name, path.read_bytes(), ctype or "application/octet-stream"))
        except Exception as exc:
            logger.warning("No se pudo adjuntar %s al correo de credenciales: %s", path, exc)
    return out


def _build_credentials_message(
    *, full_name: str, login_id: str, password: str, program_name: str = "",
) -> tuple[str, str]:
    """Devuelve (subject, html) para el correo genérico (Ingreso/Docentes/Masiva)."""
    subject = "Tus credenciales de acceso institucional — USIL"
    programa_line = f"<p><strong>Programa:</strong> {program_name}</p>" if program_name else ""
    html = f"""
    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #222;">
      <p>Hola {full_name},</p>
      <p>Ya tenés acceso a Canvas LMS y Microsoft Teams con las siguientes credenciales institucionales:</p>
      <ul>
        <li><strong>Usuario:</strong> {login_id}</li>
        <li><strong>Contraseña temporal:</strong> {password}</li>
      </ul>
      {programa_line}
      <p>Por seguridad, el sistema te pedirá cambiar la contraseña la primera vez que inicies sesión.</p>
      <p>Saludos,<br>Universidad San Ignacio de Loyola — Área de Tecnologías de la Información</p>
    </div>
    """
    return subject, html


def _build_diplomado_message(
    *, full_name: str, login_id: str, password: str, program_name: str = "",
) -> tuple[str, str]:
    """Réplica exacta del correo que TI UBS venía enviando a mano para
    Diplomados (bienvenida a la USIL Business School, acceso a Microsoft
    Teams, contacto de TI y WhatsApp). Devuelve (subject, html)."""
    subject_program = program_name or "tu programa"
    subject = f"Credenciales de acceso – {subject_program} – TI UBS"

    contact_lines = "".join(
        f'<p style="margin:2px 0;">Correo: <a href="mailto:{addr}">{addr}</a></p>'
        for addr in _DIPLOMADO_CONTACT_EMAILS
    )
    contact_inline = " | ".join(
        f'<a href="mailto:{addr}">{addr}</a>' for addr in _DIPLOMADO_CONTACT_EMAILS
    )
    programa_line = f"Usted se encuentra inscrito en el <strong>{program_name}</strong>." if program_name else ""

    html = f"""
    <div style="font-family: Arial, sans-serif; font-size: 14px; color: #222; line-height: 1.5;">
      <p>Estimado(a) {full_name},</p>
      <p>Le damos la bienvenida a la <strong>USIL Business School (UBS)</strong>.</p>
      <p>{programa_line}</p>

      <div style="border:1px solid #a9c9ec; background:#eaf2fb; border-radius:8px; padding:12px 16px; margin:16px 0;">
        <p style="margin:0 0 12px;"><strong>Microsoft Teams</strong> es la plataforma oficial donde podrá acceder a sus clases virtuales, materiales académicos y comunicarse con sus docentes y compañeros.</p>
        <p style="margin:0;">
          <a href="{_DIPLOMADO_TEAMS_LINK}" style="display:inline-block; background:#4b53bc; color:#ffffff; text-decoration:none; font-weight:bold; padding:10px 22px; border-radius:6px;">Acceder a Microsoft Teams</a>
        </p>
      </div>

      <p>A continuación, con las siguientes credenciales podrá ingresar a <strong>Microsoft Teams</strong>:</p>

      <div style="border:1px solid #a9c9ec; background:#eaf2fb; border-radius:8px; padding:12px 16px; margin:16px 0;">
        <p style="margin:0;"><strong>Usuario:</strong> {login_id}</p>
        <p style="margin:0;"><strong>Contraseña:</strong> {password}</p>
      </div>

      <div style="border:1px solid #a9c9ec; background:#eaf2fb; border-radius:8px; padding:12px 16px; margin:16px 0;">
        <p style="margin:0 0 8px;">En caso de inconvenientes con el acceso a Teams, puede contactar al área de Tecnología de la Información (TI):</p>
        {contact_lines}
        <p style="margin:2px 0;">WhatsApp corporativo: {_DIPLOMADO_WHATSAPP}</p>
      </div>

      <p>Adjunto encontrará los instructivos de Teams.</p>
      <p>Quedamos atentos a cualquier consulta relacionada con TI y le deseamos mucho éxito en sus estudios.</p>

      <hr style="border:none; border-top:1px solid #d0d5dd; margin:20px 0;">
      <p style="margin:0;"><strong>Área de Tecnología de la Información (TI UBS)</strong><br>
      USIL Business School – Universidad San Ignacio de Loyola<br>
      Correo: {contact_inline}<br>
      WhatsApp corporativo: {_DIPLOMADO_WHATSAPP}</p>
    </div>
    """
    return subject, html


def _build_mba_message(
    *, full_name: str, login_id: str, password: str, program_name: str = "",
) -> tuple[str, str]:
    """Correo de bienvenida para alumnos de MBA (USIL Business School).

    Mismo lenguaje visual de tarjetas/"ticket" que `_build_student_credentials_message`
    y `_build_erp_docente_message` (compatible con Outlook vía tablas), pero
    con el copy y los datos de contacto exactos del correo real que TI UBS
    venía enviando a mano ("Accesos MBA | UBS Business School"), y con botón
    de acceso a **ambas** plataformas: Microsoft Teams y Canvas LMS — a
    diferencia de `_build_diplomado_message`, que solo da acceso a Teams.
    """
    program_label = program_name or "MBA"
    subject = f"Accesos {program_label} | UBS Business School"

    platforms = [
        ("Microsoft Teams", _MBA_TEAMS_LINK, "Acceder a Microsoft Teams"),
        ("Canvas LMS", _erp_docente_canvas_link(), "Acceder a Canvas LMS"),
    ]
    platform_cards = "".join(
        f"""
        <tr>
          <td style="padding:0 32px 16px 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="background:#eaf2fb; border-left:4px solid #1f2a5c; border-radius:6px;">
              <tr>
                <td style="padding:14px 18px;">
                  <p style="margin:0 0 8px; font-size:14px; color:#1f2a5c; font-weight:bold;">{p_name}</p>
                  <p style="margin:0; font-size:13.5px; color:#333333;">Usuario: {login_id}</p>
                  <p style="margin:0 0 12px; font-size:13.5px; color:#333333;">Contraseña: {password}</p>
                  <a href="{p_link}"
                     style="display:inline-block; background:#1f2a5c; color:#ffffff; text-decoration:none;
                            font-weight:bold; font-size:13px; padding:10px 20px; border-radius:6px;">
                    {p_cta}
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        """
        for p_name, p_link, p_cta in platforms
    )

    contact_rows = "".join(
        f'<p style="margin:2px 0; font-size:13.5px;"><a href="mailto:{addr}" style="color:#1f2a5c; text-decoration:none;">{addr}</a></p>'
        for addr in _MBA_CONTACT_EMAILS
    )

    html = f"""
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background:#eef1f6; padding:24px 0; font-family: Arial, Helvetica, sans-serif;">
      <tr>
        <td align="center">
          <table role="presentation" width="580" cellpadding="0" cellspacing="0" border="0"
                 style="background:#ffffff; border-radius:10px; overflow:hidden; border:1px solid #dfe3ea;">
            <tr>
              <td style="background:#1f2a5c; padding:22px 32px;">
                <span style="color:#ffffff; font-size:18px; font-weight:bold; letter-spacing:0.3px;">USIL Business School</span>
              </td>
            </tr>
            <tr>
              <td style="padding:28px 32px 8px 32px;">
                <p style="margin:0 0 14px; font-size:19px; color:#1f2a5c; font-weight:bold;">Bienvenido al programa {program_label}</p>
                <p style="margin:0 0 12px; font-size:14px; color:#222222; line-height:1.55;">Estimado/a <strong>{full_name}</strong>,</p>
                <p style="margin:0 0 12px; font-size:14px; color:#222222; line-height:1.55;">
                  Nos complace darle la bienvenida al inicio de sus actividades académicas en <strong>{program_label}</strong>,
                  perteneciente a la USIL Business School (UBS).
                </p>
                <p style="margin:0 0 12px; font-size:14px; color:#222222; line-height:1.55;">
                  Este programa ha sido diseñado para potenciar sus capacidades de liderazgo, fortalecer su visión
                  estratégica y acompañar su desarrollo profesional en entornos altamente exigentes.
                </p>
                <p style="margin:0 0 16px; font-size:14px; color:#222222; line-height:1.55;">
                  A continuación encontrará sus credenciales de acceso a las plataformas institucionales que serán
                  utilizadas durante el desarrollo del programa:
                </p>
              </td>
            </tr>
            <tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">{platform_cards}</table></td></tr>
            <tr>
              <td style="padding:0 32px 20px 32px; font-size:13.5px; color:#5b6472; line-height:1.5;">
                <p style="margin:0;">Le recomendamos verificar el acceso a ambas plataformas antes del inicio de clases,
                a fin de garantizar una experiencia académica fluida desde el primer día.</p>
              </td>
            </tr>
            <tr>
              <td style="padding:0 32px 24px 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="background:#f5f7fa; border-radius:8px;">
                  <tr>
                    <td style="padding:16px 18px;">
                      <p style="margin:0 0 10px; font-size:13.5px; color:#1f2a5c; font-weight:bold;">Soporte Técnico - Área de Tecnología</p>
                      <p style="margin:0 0 10px; font-size:13px; color:#333333; line-height:1.5;">
                        Si presenta inconvenientes con el acceso o funcionamiento de las plataformas institucionales,
                        puede comunicarse a través de los siguientes canales oficiales:
                      </p>
                      <p style="margin:0 0 2px; font-size:12.5px; color:#5b6472; font-weight:bold;">Correo electrónico:</p>
                      {contact_rows}
                      <p style="margin:10px 0 2px; font-size:12.5px; color:#5b6472; font-weight:bold;">WhatsApp corporativo:</p>
                      <p style="margin:0; font-size:13.5px; color:#333333;">{_MBA_WHATSAPP}</p>
                      <p style="margin:10px 0 2px; font-size:12.5px; color:#5b6472; font-weight:bold;">Horario de atención:</p>
                      <p style="margin:0; font-size:13.5px; color:#333333;">{_MBA_SCHEDULE}</p>
                      <p style="margin:10px 0 2px; font-size:12.5px; color:#5b6472; font-weight:bold;">Tiempo estimado de respuesta (SLA):</p>
                      <p style="margin:0; font-size:13.5px; color:#333333;">{_MBA_SLA}</p>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:16px 32px; background:#f5f7fa; border-top:1px solid #e3e7ee;">
                <p style="margin:0 0 2px; font-size:12.5px; color:#1f2a5c; font-weight:bold;">Departamento de Tecnología</p>
                <p style="margin:0; font-size:12px; color:#6b7280;">Universidad San Ignacio de Loyola — Paraguay</p>
              </td>
            </tr>
          </table>
          <p style="margin:12px 0 0; font-size:11px; color:#9aa1ad;">Este mensaje fue creado por el Departamento de Tecnología de USIL Paraguay.</p>
        </td>
      </tr>
    </table>
    """
    return subject, html


_ERP_DOCENTE_LINK = "https://erp-py.usil.digital/login"
_ERP_DOCENTE_TEAMS_LINK = "https://teams.cloud.microsoft/"


def _erp_docente_canvas_link() -> str:
    return settings.canvas_base_url


_STUDENT_CONTACTS = [
    ("Giovanni Lezcano", "glezcano@usil.edu.py"),
    ("Roque Esteche", "resteche@usil.edu.py"),
]


def _build_student_credentials_message(
    *, full_name: str, login_id: str, password: str, program_name: str = "",
) -> tuple[str, str]:
    """Correo de bienvenida para alumnos nuevos (Envío/Reenvío de
    Credenciales, program_type="grado"). Mismo lenguaje visual que
    `_build_erp_docente_message` (tabla, compatible con Outlook, tarjeta de
    credenciales tipo "ticket", botones "Acceder"), pero solo con las dos
    plataformas a las que un alumno debe entrar: Canvas y Teams — a
    diferencia de docentes, un alumno no tiene acceso al ERP."""
    subject = "Bienvenido a la USIL — Tus credenciales de acceso a Canvas y Teams"

    platforms = [
        ("Canvas LMS", "Tus cursos, materiales y calificaciones.", _erp_docente_canvas_link()),
        ("Microsoft Teams", "Tus clases virtuales y comunicación con docentes y compañeros.", _DIPLOMADO_TEAMS_LINK),
    ]
    platform_rows = "".join(
        f"""
        <tr>
          <td style="padding:10px 0; border-top:1px solid #d7e3f5;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="vertical-align:middle;">
                  <p style="margin:0; font-size:14px; color:#111111; font-weight:bold;">{p_name}</p>
                  <p style="margin:2px 0 0; font-size:12.5px; color:#5b6472;">{p_desc}</p>
                </td>
                <td style="vertical-align:middle; text-align:right; width:110px;">
                  <a href="{p_link}"
                     style="display:inline-block; background:#4b53bc; color:#ffffff; text-decoration:none;
                            font-weight:bold; font-size:12.5px; padding:8px 16px; border-radius:6px; white-space:nowrap;">
                    Acceder
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        """
        for p_name, p_desc, p_link in platforms
    )

    contact_rows = "".join(
        f"""
        <tr>
          <td style="padding:3px 0; color:#111111; font-weight:bold; white-space:nowrap; width:150px;">{name}</td>
          <td style="padding:3px 0;"><a href="mailto:{addr}" style="color:#4b53bc; text-decoration:none;">{addr}</a></td>
        </tr>
        """
        for name, addr in _STUDENT_CONTACTS
    )

    programa_line = (
        f'<p style="margin:0 0 12px;">Estás matriculado/a en: <strong>{program_name}</strong>.</p>'
        if program_name else ""
    )

    html = f"""
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background:#eef1f6; padding:24px 0; font-family: Arial, Helvetica, sans-serif;">
      <tr>
        <td align="center">
          <table role="presentation" width="580" cellpadding="0" cellspacing="0" border="0"
                 style="background:#ffffff; border-radius:10px; overflow:hidden; border:1px solid #dfe3ea;">
            <tr>
              <td style="background:#1f2a5c; padding:22px 32px;">
                <span style="color:#ffffff; font-size:18px; font-weight:bold; letter-spacing:0.3px;">Universidad San Ignacio de Loyola</span><br>
                <span style="color:#c7cdf0; font-size:13px;">Acceso institucional para alumnos</span>
              </td>
            </tr>
            <tr>
              <td style="padding:28px 32px 8px 32px; font-size:14px; color:#222222; line-height:1.55;">
                <p style="margin:0 0 12px;">Hola <strong>{full_name}</strong>,</p>
                <p style="margin:0 0 12px;">¡Bienvenido/a a la USIL! Te deseamos un excelente inicio, lleno de aprendizajes y logros en esta nueva etapa con nosotros.</p>
                {programa_line}
                <p style="margin:0 0 4px;">A continuación, te compartimos tus credenciales institucionales — son las <strong>mismas para ambas plataformas</strong> que vas a usar:</p>
              </td>
            </tr>
            <tr>
              <td style="padding:8px 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="border:1px solid #a9c9ec; background:#eaf2fb; border-radius:8px;">
                  <tr>
                    <td style="padding:14px 18px;">
                      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;">
                        <tr>
                          <td style="padding:3px 0; color:#5b6472; width:110px;">Usuario</td>
                          <td style="padding:3px 0; color:#111111; font-weight:bold;">{login_id}</td>
                        </tr>
                        <tr>
                          <td style="padding:3px 0; color:#5b6472;">Contraseña</td>
                          <td style="padding:3px 0; color:#111111; font-weight:bold;">{password}</td>
                        </tr>
                      </table>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:10px 32px 4px 32px; font-size:12.5px; color:#5b6472; line-height:1.5;">
                <p style="margin:0;">Por seguridad, el sistema te va a pedir cambiar la contraseña la primera vez que inicies sesión.</p>
              </td>
            </tr>
            <tr>
              <td style="padding:20px 32px 4px 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="border:1px solid #a9c9ec; background:#eaf2fb; border-radius:8px;">
                  <tr>
                    <td style="padding:16px 18px;">
                      <p style="margin:0; font-size:14px; color:#222222;">Con ese usuario y contraseña podés ingresar a:</p>
                      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        {platform_rows}
                      </table>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:20px 32px 4px 32px; font-size:14px; color:#222222; line-height:1.55;">
                <p style="margin:0;">¡Mucho éxito en este ciclo!</p>
              </td>
            </tr>
            <tr>
              <td style="padding:16px 32px 24px 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="border:1px solid #a9c9ec; background:#eaf2fb; border-radius:8px;">
                  <tr>
                    <td style="padding:14px 18px;">
                      <p style="margin:0 0 10px; font-size:13.5px; color:#222222;">Ante cualquier duda o inconveniente sobre el acceso, podés contactar al Área de Tecnologías de la Información:</p>
                      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="font-size:13.5px;">
                        {contact_rows}
                      </table>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:16px 32px; background:#f5f7fa; border-top:1px solid #e3e7ee;">
                <p style="margin:0; font-size:12px; color:#6b7280;">Universidad San Ignacio de Loyola — Área de Tecnologías de la Información</p>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
    """
    return subject, html


_ERP_DOCENTE_FEATURES = [
    "Cargar calificaciones",
    "Registrar asistencias",
    "Consultar listas de alumnos",
    "Revisar el cronograma académico",
]

_ERP_DOCENTE_CONTACTS = [
    ("Giovanni Lezcano", "glezcano@usil.edu.py"),
    ("Roque Esteche", "resteche@usil.edu.py"),
]


def _build_erp_docente_message(
    *, full_name: str, username: str, password: str,
) -> tuple[str, str]:
    """Correo de bienvenida con acceso al Sistema Académico Docente (ERP
    externo, erp-py.usil.digital, no gestionado por esta app). Basado en
    tablas (compatible con Outlook), con encabezado de marca, tarjeta de
    credenciales tipo "ticket", las 3 plataformas a las que accede con esas
    mismas credenciales (Teams, Canvas y el Sistema Académico Docente) y
    lista de funcionalidades del ERP con íconos."""
    subject = "Bienvenido a la USIL — Acceso a Teams, Canvas y al Sistema Académico Docente"

    platforms = [
        ("Microsoft Teams", "Clases virtuales, materiales y comunicación con tus alumnos.", _ERP_DOCENTE_TEAMS_LINK),
        ("Canvas LMS", "Gestión de tus cursos y contenidos académicos.", _erp_docente_canvas_link()),
        ("Sistema Académico Docente", "Calificaciones, asistencias, listas y cronograma.", _ERP_DOCENTE_LINK),
    ]
    platform_rows = "".join(
        f"""
        <tr>
          <td style="padding:10px 0; border-top:1px solid #d7e3f5;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="vertical-align:middle;">
                  <p style="margin:0; font-size:14px; color:#111111; font-weight:bold;">{p_name}</p>
                  <p style="margin:2px 0 0; font-size:12.5px; color:#5b6472;">{p_desc}</p>
                </td>
                <td style="vertical-align:middle; text-align:right; width:110px;">
                  <a href="{p_link}"
                     style="display:inline-block; background:#4b53bc; color:#ffffff; text-decoration:none;
                            font-weight:bold; font-size:12.5px; padding:8px 16px; border-radius:6px; white-space:nowrap;">
                    Acceder
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        """
        for p_name, p_desc, p_link in platforms
    )

    features_rows = "".join(
        f"""
        <tr>
          <td style="padding:4px 0; vertical-align:top; width:22px;">
            <span style="display:inline-block; width:18px; height:18px; line-height:18px; text-align:center;
                         background:#4b53bc; color:#ffffff; border-radius:50%; font-size:11px; font-weight:bold;">✓</span>
          </td>
          <td style="padding:4px 0 4px 8px; color:#333333;">{feature}</td>
        </tr>
        """
        for feature in _ERP_DOCENTE_FEATURES
    )

    contact_rows = "".join(
        f"""
        <tr>
          <td style="padding:3px 0; color:#111111; font-weight:bold; white-space:nowrap; width:150px;">{name}</td>
          <td style="padding:3px 0;"><a href="mailto:{addr}" style="color:#4b53bc; text-decoration:none;">{addr}</a></td>
        </tr>
        """
        for name, addr in _ERP_DOCENTE_CONTACTS
    )

    html = f"""
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background:#eef1f6; padding:24px 0; font-family: Arial, Helvetica, sans-serif;">
      <tr>
        <td align="center">
          <table role="presentation" width="580" cellpadding="0" cellspacing="0" border="0"
                 style="background:#ffffff; border-radius:10px; overflow:hidden; border:1px solid #dfe3ea;">
            <tr>
              <td style="background:#1f2a5c; padding:22px 32px;">
                <span style="color:#ffffff; font-size:18px; font-weight:bold; letter-spacing:0.3px;">Universidad San Ignacio de Loyola</span><br>
                <span style="color:#c7cdf0; font-size:13px;">Acceso institucional para docentes</span>
              </td>
            </tr>
            <tr>
              <td style="padding:28px 32px 8px 32px; font-size:14px; color:#222222; line-height:1.55;">
                <p style="margin:0 0 12px;">Hola <strong>{full_name}</strong>,</p>
                <p style="margin:0 0 12px;">¡Bienvenido a la USIL! Te deseamos un excelente inicio de semestre, lleno de aprendizajes, logros y nuevas experiencias en esta nueva etapa con nosotros.</p>
                <p style="margin:0 0 4px;">A continuación, te compartimos tus credenciales institucionales — son las <strong>mismas para las tres plataformas</strong> que vas a usar:</p>
              </td>
            </tr>
            <tr>
              <td style="padding:8px 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="border:1px solid #a9c9ec; background:#eaf2fb; border-radius:8px;">
                  <tr>
                    <td style="padding:14px 18px;">
                      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;">
                        <tr>
                          <td style="padding:3px 0; color:#5b6472; width:110px;">Usuario</td>
                          <td style="padding:3px 0; color:#111111; font-weight:bold;">{username}</td>
                        </tr>
                        <tr>
                          <td style="padding:3px 0; color:#5b6472;">Contraseña</td>
                          <td style="padding:3px 0; color:#111111; font-weight:bold;">{password}</td>
                        </tr>
                      </table>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:20px 32px 4px 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="border:1px solid #a9c9ec; background:#eaf2fb; border-radius:8px;">
                  <tr>
                    <td style="padding:16px 18px;">
                      <p style="margin:0; font-size:14px; color:#222222;">Con ese usuario y contraseña podés ingresar a:</p>
                      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        {platform_rows}
                      </table>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:20px 32px 4px 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="border:1px solid #a9c9ec; background:#eaf2fb; border-radius:8px;">
                  <tr>
                    <td style="padding:16px 18px;">
                      <p style="margin:0 0 10px; font-size:14px; color:#222222;">En particular, desde el <strong>Sistema Académico Docente</strong> vas a poder:</p>
                      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="font-size:13.5px;">
                        {features_rows}
                      </table>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:20px 32px 4px 32px; font-size:14px; color:#222222; line-height:1.55;">
                <p style="margin:0;">¡Mucho éxito en este semestre!</p>
              </td>
            </tr>
            <tr>
              <td style="padding:16px 32px 24px 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                       style="border:1px solid #a9c9ec; background:#eaf2fb; border-radius:8px;">
                  <tr>
                    <td style="padding:14px 18px;">
                      <p style="margin:0 0 10px; font-size:13.5px; color:#222222;">Ante cualquier duda o inconveniente sobre el acceso, podés contactar al Área de Tecnologías de la Información:</p>
                      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="font-size:13.5px;">
                        {contact_rows}
                      </table>
                    </td>
                  </tr>
                </table>
              </td>
            </tr>
            <tr>
              <td style="padding:16px 32px; background:#f5f7fa; border-top:1px solid #e3e7ee;">
                <p style="margin:0; font-size:12px; color:#6b7280;">Universidad San Ignacio de Loyola — Área de Tecnologías de la Información</p>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
    """
    return subject, html


async def send_erp_docente_email(
    *, to_email: str, full_name: str, username: str, password: str, extra_cc: list[str] | None = None,
) -> None:
    """Envía el correo de bienvenida/acceso al Sistema Académico Docente
    (ERP externo). El usuario/contraseña de ese sistema se ingresan
    manualmente — no se generan ni se sincronizan desde esta app."""
    if not settings.smtp_from:
        raise RuntimeError("El envío de correo no está configurado (falta SMTP_FROM, el buzón remitente).")
    if not to_email or "@" not in to_email:
        raise ValueError("Correo de envío inválido o vacío.")

    subject, html = _build_erp_docente_message(full_name=full_name, username=username, password=password)
    cc = list(extra_cc or [])

    try:
        await graph.send_mail(
            mailbox=settings.smtp_from, subject=subject, html_body=html,
            to_email=to_email, cc=cc, attachments=[],
        )
    except HTTPException as exc:
        if exc.status_code == 403:
            logger.error("Error enviando correo ERP docente a %s: %s", to_email, exc.detail)
            raise HTTPException(
                status_code=403,
                detail=(
                    "Falta el permiso de aplicación 'Mail.Send' (con consentimiento de administrador) "
                    "en Azure Portal → App Registrations → API Permissions, o el buzón SMTP_FROM no es "
                    f"válido en este tenant. Detalle original: {exc.detail}"
                ),
            )
        logger.error("Error enviando correo ERP docente a %s: %s", to_email, exc.detail)
        raise
    except Exception as exc:
        logger.error("Error enviando correo ERP docente a %s: %s", to_email, exc)
        raise


async def send_credentials_email(
    *,
    to_email: str,
    full_name: str,
    login_id: str,
    password: str,
    program_type: str | None = None,
    program_name: str = "",
    extra_cc: list[str] | None = None,
    cc_override: list[str] | None = None,
) -> None:
    """Envía el correo de credenciales vía Microsoft Graph.

    Lanza RuntimeError si el remitente no está configurado, o la excepción
    real de Graph si el envío falla — el caller decide cómo reportarlo
    (nunca debe abortar la creación de la cuenta, que ya ocurrió con éxito).

    `cc_override`, si se pasa (incluso como lista vacía), REEMPLAZA por
    completo el CC por defecto del programa — para flujos que todavía no
    tienen definida a quién copiar y no deben usar el CC fijo genérico
    mientras tanto.
    """
    if not settings.smtp_from:
        raise RuntimeError("El envío de correo no está configurado (falta SMTP_FROM, el buzón remitente).")
    if not to_email or "@" not in to_email:
        raise ValueError("Correo personal inválido o vacío.")

    program_type_norm = (program_type or "").strip().lower()
    if program_type_norm == "diplomado":
        builder = _build_diplomado_message
    elif program_type_norm == "mba":
        builder = _build_mba_message
    elif program_type_norm == "grado":
        builder = _build_student_credentials_message
    else:
        builder = _build_credentials_message
    subject, html = builder(full_name=full_name, login_id=login_id, password=password, program_name=program_name)

    if cc_override is not None:
        cc = cc_override
    else:
        cc = list(dict.fromkeys([*default_cc_for_program(program_type), *(extra_cc or [])]))
    attachments = _read_attachments(attachments_for_program(program_type))
    total_attachment_size = sum(len(content) for _, content, _ in attachments)

    try:
        if attachments and total_attachment_size > _SMALL_ATTACHMENT_LIMIT:
            # Uno o más adjuntos grandes (o varios cuya suma supera el límite
            # de sendMail "simple", p. ej. los dos instructivos de MBA):
            # flujo de borrador + upload session, ver `send_mail_with_attachments`.
            await graph.send_mail_with_attachments(
                mailbox=settings.smtp_from, subject=subject, html_body=html,
                to_email=to_email, attachments=attachments, cc=cc,
            )
        else:
            await graph.send_mail(
                mailbox=settings.smtp_from, subject=subject, html_body=html,
                to_email=to_email, cc=cc, attachments=attachments,
            )
    except HTTPException as exc:
        if exc.status_code == 403:
            logger.error("Error enviando correo de credenciales a %s: %s", to_email, exc.detail)
            raise HTTPException(
                status_code=403,
                detail=(
                    "Falta el permiso de aplicación 'Mail.Send' (con consentimiento de administrador) "
                    "en Azure Portal → App Registrations → API Permissions, o el buzón SMTP_FROM no es "
                    f"válido en este tenant. Detalle original: {exc.detail}"
                ),
            )
        logger.error("Error enviando correo de credenciales a %s: %s", to_email, exc.detail)
        raise
    except Exception as exc:
        logger.error("Error enviando correo de credenciales a %s: %s", to_email, exc)
        raise
