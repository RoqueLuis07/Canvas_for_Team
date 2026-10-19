# PRD — Diagnóstico asistido por IA para sincronizaciones

| Campo | Valor |
|---|---|
| Estado | Propuesta funcional y técnica; pendiente de validación del equipo |
| Producto | Sistema Académico USIL — Canvas LMS ↔ Microsoft Teams |
| Versión | 1.1 |
| Fecha | 18 de octubre de 2026 |
| Responsable de producto | Por definir |
| Documentos relacionados | [goal.md](goal.md), [ERD_DIAGNOSTICO_IA.md](ERD_DIAGNOSTICO_IA.md), [Arquitectura técnica](ARQUITECTURA.md) |

## 1. Resumen

Incorporar al panel administrativo una capacidad de diagnóstico asistido por IA para ayudar a Admin TI a entender fallos registrados durante sincronizaciones con Canvas LMS y Microsoft Graph. La función explicará el error en español claro, presentará evidencias y propondrá pasos de verificación o mitigación.

La IA es exclusivamente consultiva: no reintenta sincronizaciones, no cambia matrículas ni usuarios, no ejecuta acciones contra Canvas o Teams y no modifica datos académicos. El operador conserva la decisión y realiza cualquier corrección por los flujos existentes.

La propuesta utiliza un contrato `LLMClient` y simula el formato de OpenAI sin clave API real, cuota ni llamadas externas. En la implementación actual `NullLLMClient` realiza esta simulación en proceso; el servidor FastAPI local descrito abajo sigue siendo una etapa propuesta, no está implementado. Ninguno de los dos modos es un modelo generativo ni evalúa libremente el error. Un proveedor LLM real queda fuera del MVP y requiere aprobación separada.

El repositorio ya incluye FastAPI para el backend de integración en [`Backend/app/main.py`](../../Backend/app/main.py). El mock se ejecutará como una aplicación/punto de entrada local separado: no debe montarse en la aplicación principal, que inicializa bases de datos, clientes de Canvas/Teams y middleware de autenticación. Así se evita mezclar la simulación con operaciones reales y sus credenciales.

### Contrato propuesto del mock local

- `GET /v1/models`: devuelve el modelo simulado disponible.
- `POST /v1/chat/completions`: acepta el formato de solicitud de chat compatible con OpenAI y responde con su estructura `choices[0].message.content`, conteniendo el JSON de diagnóstico definido en este PRD.
- No exige encabezado `Authorization` ni acepta o necesita una clave real. El cliente Laravel apunta a una URL configurable, por ejemplo `http://127.0.0.1:8765/v1`.
- Devuelve escenarios deterministas según entradas/casos de prueba controlados, e incluye respuestas válidas y casos de error para pruebas de timeout, respuesta inválida y proveedor no disponible.
- En desarrollo local se enlaza solo a `127.0.0.1`. Si Laravel y el mock se ejecutan en contenedores distintos, se usa el nombre DNS de servicio en la red interna, sin publicar el mock en Internet.

El endpoint compatible permite verificar el transporte y parseo con el mismo contrato esperado de un proveedor, pero no demuestra calidad diagnóstica ni compatibilidad con todas las funciones de OpenAI. Para pruebas unitarias Laravel inyecta un `LLMClient` falso; pruebas de integración pueden usar el servidor local. Ninguno de los dos debe cambiar silenciosamente a respuestas simuladas si un proveedor real falla.

## 2. Contexto y problema

El proyecto ya registra las operaciones en `job_sincronizacions`, con tipo, estado, fechas y resultado. `SincronizacionService` conserva el mensaje de excepción en el resultado del job y en el detalle de auditoría. Esos mensajes de Canvas y Microsoft Graph pueden ser técnicos, incompletos o difíciles de relacionar con una corrección concreta.

Hoy no hay una capa de IA, una entidad de historial de diagnósticos ni una interfaz dedicada a explicarlos. La propuesta debe integrarse con la trazabilidad actual sin confundir una recomendación generada con el resultado real de una sincronización.

## 3. Objetivos y no objetivos

### Objetivos

1. Permitir que Admin TI solicite un diagnóstico para un job de sincronización fallido desde el panel `/admin`.
2. Explicar el error en español, distinguiendo hechos conocidos de hipótesis.
3. Presentar evidencia contextual de solo lectura y pasos concretos de verificación/mitigación.
4. Guardar cada ejecución, su estado y su salida estructurada para consulta y auditoría.
5. Evitar que fallos, salidas inválidas o indisponibilidad del LLM afecten las operaciones de sincronización.
6. Habilitar pruebas deterministas sin red, proveedor externo, claves ni consumo de tokens.

### No objetivos de la primera versión

- Ejecutar automáticamente la recomendación, corregir datos o reintentar jobs.
- Sustituir los mensajes originales, el estado del job o el registro de auditoría existente.
- Permitir al modelo ejecutar SQL, navegar libremente por la base de datos o llamar directamente a Canvas/Graph.
- Diagnosticar operaciones exitosas, procesos ajenos a Canvas/Teams o errores arbitrarios no asociados a un job.
- Entrenar/fine-tunear modelos con información del proyecto.
- Exponer el diagnóstico a estudiantes o docentes.
- Comprometerse con un proveedor LLM antes de la revisión de privacidad y costos.

## 4. Usuarios y permisos

| Usuario | Necesidad | Permiso propuesto |
|---|---|---|
| Admin TI / operador | Entender el fallo, revisar sugerencias y decidir el siguiente paso | Puede solicitar, consultar y paginar diagnósticos de sincronización |
| Docente | No necesita acceder a errores operativos ni a contexto técnico | Sin acceso a diagnósticos |
| Estudiante | No necesita acceso al historial operativo | Sin acceso a diagnósticos |
| Desarrollador / QA | Probar el contrato HTTP y el flujo sin proveedor real | Servidor FastAPI local determinista y cliente falso inyectado para pruebas unitarias |

Aplicar autorización tanto en la página/acción de Filament como en cualquier endpoint o servicio invocable directamente. No confiar únicamente en ocultar botones del panel. Los endpoints futuros deben requerir autenticación, autorización Admin TI, validación de entrada y protección contra abuso.

## 5. Alcance funcional de la primera versión

1. En un job con estado `error`, Admin TI inicia explícitamente una solicitud de diagnóstico.
2. La solicitud se procesa de forma asíncrona para no bloquear el panel ni el flujo de sincronización.
3. El backend carga un contexto mínimo y permitido del job y, cuando corresponda, obtiene indicadores de solo lectura mediante consultas internas concretas.
4. El backend reduce el mensaje original a una categoría técnica y un código HTTP allow-list; no incluye texto libre, identificadores, datos de Persona ni ID del job en la solicitud al LLM. Enmascara además identificadores y secretos detectables en cualquier salida antes de persistirla.
5. Un contrato intercambiable invoca el mock FastAPI local en el MVP; un adaptador de proveedor real queda deshabilitado hasta su aprobación.
6. La respuesta se valida contra un esquema; se guarda el estado final, contexto sanitizado, versión de prompt y respuesta estructurada.
7. El panel muestra el mensaje original del job junto al diagnóstico, su estado, fecha, modelo/proveedor cuando aplique y advertencia de que es una sugerencia.
8. Un historial paginado permite consultar los diagnósticos previos. Las nuevas solicitudes generan una ejecución histórica nueva; no se sobrescriben respuestas previas.
9. La ejecución registra un evento en `LogAuditoria`, correlacionado con el ID del diagnóstico, sin guardar secretos ni contenido innecesariamente sensible.

La versión inicial recomienda consultas internas predefinidas y de solo lectura en lugar de un ciclo autónomo de tool-calling. Si posteriormente se incorpora tool-calling, sus herramientas tendrán entradas/salidas tipadas, autorización en backend, límites de iteraciones y permisos estrictamente de lectura.

## 6. Requisitos funcionales

| ID | Requisito |
|---|---|
| RF-01 | Solo Admin TI puede iniciar o ver diagnósticos. Acceso no autorizado devuelve/produce denegación explícita y no filtra el contenido. |
| RF-02 | Solo se admite un `JobSincronizacion` existente con estado `error`; los demás estados no habilitan la acción. |
| RF-03 | La solicitud no cambia `estado`, `resultado`, fechas ni otros datos del job. |
| RF-04 | El diagnóstico deja intacto el error original en su registro operativo; la nueva entidad de diagnóstico no lo copia ni transmite. |
| RF-05 | El contexto inicial se limita a una categoría técnica allow-list y un código HTTP reconocido. No contiene el mensaje libre, IDs, tipo/estado del job ni datos de Persona. |
| RF-06 | Los datos personales y secretos se excluyen o enmascaran antes de cualquier solicitud. El MVP solo envía contexto saneado al mock local; si no se puede garantizar el saneamiento, no se envía y se informa el motivo. |
| RF-07 | La salida validada incluye resumen, causa probable (marcada como hipótesis), evidencia disponible, pasos de verificación y mitigaciones manuales. |
| RF-08 | Una respuesta inválida no se presenta como diagnóstico exitoso. Se registra el fallo técnico y se muestra un estado explícito de error. |
| RF-09 | `NullLLMClient` produce respuestas deterministas sin red, clave ni cuota; no hay fallback simulado ante errores de un futuro proveedor real. |
| RF-10 | El historial admite filtro/orden apropiados y paginación del lado del servidor. |
| RF-11 | Cada solicitud, resultado o fallo queda trazable con ID del job, solicitante, fecha, estado y correlación con auditoría. |
| RF-12 | El operador puede distinguir visualmente el resultado real del job de la explicación generada por IA. |

## 7. Formato de salida del diagnóstico

El contrato de salida debe ser versionado y validado en el backend. Esquema conceptual:

```json
{
  "schema_version": "1",
  "resumen": "Explicación breve en español.",
  "causa_probable": {
    "descripcion": "Hipótesis, no hecho confirmado.",
    "confianza": "baja | media | alta"
  },
  "evidencias": [
    "Señales observadas en el error o contexto autorizado."
  ],
  "pasos_verificacion": [
    "Comprobaciones que puede realizar el operador."
  ],
  "mitigaciones": [
    "Acciones manuales y reversibles propuestas."
  ],
  "advertencias": [
    "Límites o información que falta."
  ]
}
```

La aplicación debe validar tipos, campos obligatorios, longitudes máximas y valores de enumeración. No interpretar texto de la respuesta como comandos ni convertir sugerencias en acciones ejecutables.

## 8. Requisitos no funcionales

### Seguridad y privacidad

- Acceso restringido a Admin TI conforme a la autorización ya usada por el sistema.
- Menor privilegio: el modelo no tiene credenciales de base de datos, Canvas ni Microsoft Graph.
- La entrada de error externa se considera no confiable (incluida posible prompt injection); no puede cambiar políticas ni permisos de herramientas.
- La entrada de error se clasifica en el backend mediante una allow-list; el texto libre original nunca se envía al cliente LLM ni se persiste en la entidad del diagnóstico.
- No enviar tokens, cabeceras, secretos, contraseñas, cédulas, correos, identificadores ni payloads completos a un LLM. Las respuestas se limitan al esquema aprobado y se enmascaran antes de persistirse.
- Guardar la versión de plantilla y el prompt/contexto renderizado solo después de saneamiento. Nunca persistir claves API.
- El mock se enlaza solo a loopback en desarrollo y no usa credenciales. Si posteriormente se habilita un servicio remoto, exigir TLS y guardar sus credenciales en configuración secreta del entorno, nunca en el repositorio.
- Definir y aprobar período de retención y proceso de borrado antes de producción. La propuesta inicial es configurable y está pendiente de validación institucional.

### Fiabilidad y rendimiento

- Procesamiento asíncrono con estado visible (`pendiente`, `en_proceso`, `completado`, `error`).
- Timeouts, reintentos acotados para fallos transitorios, límite de solicitudes por job/operador y tratamiento explícito de errores permanentes.
- La indisponibilidad o lentitud del mock/LLM nunca debe bloquear ni revertir una sincronización.
- Paginar en base de datos; no cargar el historial completo en memoria.
- Guardar proveedor/modelo, duración y tokens/costo cuando el proveedor los devuelva. Estos datos no son requisito para una simulación.
- Evitar duplicados accidentales por doble envío; usar protección de idempotencia o deshabilitar temporalmente la acción mientras se crea una solicitud equivalente.

### Mantenibilidad y pruebas

- Interfaz `App\Contracts\LLMClient` o equivalente, enlazada mediante el contenedor de Laravel.
- `NullLLMClient` en proceso para el modo simulado actual; el servidor FastAPI compatible con OpenAI descrito en la propuesta es una etapa pendiente.
- Cliente falso inyectable y pruebas de categoría, enmascarado, persistencia, relación y auditoría.
- Pruebas unitarias del contrato de salida, saneamiento y consultas; pruebas de integración HTTP/Filament de permisos, estados y paginación.
- Las pruebas no requieren red, cuenta externa ni claves. Los tests de contrato del proveedor real, si existen, deben ser opt-in y usar credenciales fuera del CI general.

## 9. Criterios de aceptación

1. Dado un Admin TI y un job fallido, al solicitar diagnóstico se crea un registro `pendiente` y el panel no espera sincrónicamente a la respuesta del mock/LLM.
2. Dado un usuario autenticado que no sea Admin TI, las rutas/acciones de iniciar y consultar diagnóstico son denegadas, incluso si se invocan directamente.
3. Dado un job que no esté en estado `error`, la solicitud se rechaza con validación clara y no se crea un diagnóstico exitoso.
4. La ejecución del diagnóstico no cambia ningún campo del job ni ejecuta operaciones de Canvas/Teams.
5. Dado un error que contiene datos personales o secretos reconocibles, la solicitud enviada al mock contiene únicamente la versión saneada; si el saneamiento falla, el servidor no recibe la solicitud.
6. Dada una respuesta JSON válida del cliente simulado, el historial muestra la salida estructurada enmascarada y el estado `completado`.
7. Dada una respuesta malformada, timeout o mock no disponible, el historial muestra `error` y la interfaz no presenta una salida inventada como válida.
8. Cada solicitud se correlaciona con un evento de `LogAuditoria` y un `JobSincronizacion`; el historial conserva varias ejecuciones para el mismo job.
9. La lista de historial es paginada por base de datos y el acceso queda limitado a Admin TI.
10. La suite automatizada puede ejecutarse sin claves ni cuota externas; cubre el cliente falso, el contrato del mock, servicio/agente, herramientas de solo lectura, autorización HTTP/Filament y persistencia.

## 10. Modelo de datos propuesto

Agregar una entidad `DiagnosticoIA` (tabla propuesta `diagnosticos_ia`) vinculada a un job y al usuario que solicitó el diagnóstico. Un job puede tener varios diagnósticos a lo largo del tiempo. El diseño detallado y el diagrama están en [ERD_DIAGNOSTICO_IA.md](ERD_DIAGNOSTICO_IA.md).

Se recomienda guardar prompt y contexto **sanitizados**, respuesta JSON validada, plantilla/versionado, estado, proveedor/modelo, duración y error técnico saneado. No guardar la entrada original sin filtrar ni repetir atributos personales que ya existen en `personas`.

`LogAuditoria` se mantiene como bitácora transversal. Los nuevos eventos incluyen `diagnostico_ia_id` como FK nullable y solo guardan estado/categoría técnica; no duplican prompt, respuesta ni mensaje original. Los registros históricos previos no se modifican retroactivamente.

## 11. Flujo principal

1. Admin TI abre un job en estado `error` y elige “Solicitar diagnóstico”.
2. El backend autoriza la acción, valida el estado y crea un registro de diagnóstico pendiente.
3. Un job de cola carga el contexto permitido, sanea los campos y ejecuta verificaciones locales de solo lectura.
4. El contrato `LLMClient` recibe únicamente la categoría técnica y el código HTTP allow-list; `NullLLMClient` produce una respuesta determinista en proceso.
5. El servicio valida la respuesta y persiste resultado/estado; si hay fallo, persiste el motivo saneado.
6. El servicio registra la acción en `LogAuditoria`.
7. Filament presenta el estado final, el error original y la sugerencia sin permitir ejecución automática.

Si el proceso termina abruptamente, el registro no debe quedar presentado como completado; se debe contemplar detección de trabajos abandonados y recuperación conforme a las capacidades de la cola configurada.

## 12. Métricas de éxito

- 100 % de ejecuciones asociadas a un job y a un registro histórico, incluidas las fallidas.
- 100 % de denegación de acceso para roles no autorizados en pruebas.
- 0 cambios de datos o transacciones externas iniciados por el agente.
- 0 secretos o datos personales no saneados enviados al adaptador real en pruebas de saneamiento.
- Tiempo de espera de la acción web independiente de la latencia del proveedor, dado que el diagnóstico se ejecuta en cola.
- Medir porcentaje de diagnósticos completados, latencia, respuestas inválidas y errores del mock/cliente. El mock no incurre en cuota de proveedor y no mide precisión real; no medir “precisión” como hecho objetivo sin revisión humana y conjunto de evaluación aprobado.

## 13. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Exposición de datos académicos o identificadores personales | Minimización, saneamiento verificable, revisión de privacidad/proveedor y no enviar si no puede sanearse |
| Alucinación o recomendación incorrecta | Etiquetar como hipótesis/sugerencia, mostrar error original y exigir decisión humana |
| Prompt injection en el mensaje externo | Tratar el error como dato no confiable; instrucciones fijas del lado del servidor; herramientas tipadas de solo lectura |
| Costos o latencia imprevistos | Solicitud explícita del operador en MVP, cola, límites, timeout y métricas |
| Respuesta no estructurada | Esquema estricto, validación y estado `error` ante salida inválida |
| Mock local no iniciado o no disponible | Error explícito y guía operativa para iniciarlo; no sustituir por una respuesta de éxito; la sincronización original permanece intacta |
| Mock confundido con IA real | Etiquetar el modo como simulación, describir sus límites y no usar sus resultados como evidencia de calidad diagnóstica |
| Diagnósticos históricos con datos sensibles | Retención configurable y revisión institucional del plazo y acceso |

## 14. Despliegue por fases

1. **Definición y controles:** validar este PRD, campos permitidos, política de retención y roles. No se selecciona proveedor externo en esta fase.
2. **Simulación local:** `NullLLMClient` en proceso está implementado; FastAPI mock compatible con OpenAI es una opción pendiente. El contrato no requiere cuota ni clave real.
3. **Persistencia base:** modelo/migración, servicio, correlación de auditoría, paginación y pruebas están implementados. La acción Filament, autorización de interfaz y despacho asíncrono quedan pendientes.
4. **Evaluación del flujo:** verificar transporte, parseo, estados de error y experiencia de operador. El mock valida integración, no calidad del diagnóstico.
5. **Proveedor real opcional:** solo tras aprobación de privacidad/costos; nuevo adaptador detrás del contrato, configuración segura, límites, observabilidad y pruebas de contrato opt-in.

El tool-calling iterativo, la solicitud automática tras cada fallo y cualquier inferencia real quedan fuera del MVP y requieren decisión y evaluación separadas.

## 15. Decisiones pendientes antes de producción

- ¿Qué campos exactos son permitidos después del saneamiento y quién valida ese criterio?
- ¿Cuál es el plazo de retención de prompts sanitizados, respuestas y métricas?
- ¿Qué workers/colas están activos en cada despliegue y cuál será el mecanismo operativo para recuperar trabajos abandonados?
- ¿Se habilita solo la solicitud manual en MVP o se desea automatización posterior bajo bandera de configuración?
- ¿Qué roles institucionales adicionales, si alguno, pueden consultar el historial?

## 16. Controles de calidad alineados con ISO 9001

La implementación adopta prácticas compatibles con un sistema de gestión de calidad —trazabilidad, información documentada controlada, criterios de aceptación verificables, registros de resultados y gestión explícita de fallos—. Esto no constituye ni implica certificación ISO 9001. La organización debe determinar aplicabilidad, responsables, retención, revisión y aprobación de cambios dentro de su sistema de gestión.

- Cada diagnóstico conserva estado, fechas, solicitante, versión de prompt y relación con el job.
- Los cambios de estado y fallos tienen registros de auditoría correlacionados.
- Los tests verifican criterios funcionales y el enmascarado con datos de prueba sintéticos.
- El mensaje original permanece como evidencia operativa en registros existentes; la nueva entidad de IA solo usa categoría y código allow-list.
- Las versiones de documentación, prompts y esquema de respuesta deben revisarse como información documentada antes de habilitar producción.
- Si se habilita inferencia real después del MVP, ¿qué proveedor/modelo está aprobado y bajo qué condiciones de residencia/retención de datos?
