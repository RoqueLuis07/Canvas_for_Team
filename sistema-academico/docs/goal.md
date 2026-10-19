# Goal — Diagnóstico asistido por IA de Canvas ↔ Teams

## Visión

Ayudar al equipo de Admin TI a comprender y resolver de forma más rápida y segura los errores de sincronización entre Canvas LMS y Microsoft Teams, traduciendo señales técnicas a explicaciones en español y sugerencias verificables.

## Resultado buscado

Cuando una operación termina en estado `error`, un operador autorizado puede solicitar una explicación contextualizada, consultar la evidencia utilizada y revisar pasos manuales de diagnóstico en el panel administrativo. El registro conserva suficiente información sanitizada para auditoría y seguimiento.

## Principios no negociables

1. **Solo asistencia:** el modelo propone; el operador decide. No ejecuta, reintenta ni modifica sincronizaciones.
2. **La fuente de verdad es el sistema:** el job y su error original no se reemplazan por la explicación de IA.
3. **Privacidad por diseño:** enviar únicamente el contexto mínimo permitido y saneado; no exponer secretos ni datos personales.
4. **Permisos explícitos:** la función y el historial son exclusivos de Admin TI, tanto en el panel como en el backend.
5. **Solo lectura:** consultas contextuales concretas y controladas; sin SQL ni acceso libre a Canvas/Graph.
6. **Fallo seguro y visible:** mock ausente, timeout o salida inválida produce un estado de error explícito, nunca una respuesta de éxito inventada.
7. **Trazabilidad:** cada intento se relaciona con un job, un solicitante, una fecha y un evento de auditoría.
8. **Simulación sin cuota:** el MVP usa un servidor FastAPI local con contrato compatible con OpenAI, respuestas deterministas y sin clave API ni consumo de cuota. Los tests unitarios usan además un cliente falso inyectable.

## Cómo mediremos el éxito

- Admin TI obtiene recomendaciones comprensibles para jobs fallidos.
- Toda ejecución, incluso si falla, queda registrada y paginada en el historial.
- Roles no autorizados no pueden iniciar ni consultar diagnósticos.
- La ejecución no altera ningún dato académico ni realiza llamadas de escritura a Canvas o Teams.
- El MVP no llama a un proveedor externo; si se habilita en el futuro, solo recibe datos saneados y aprobados.
- La salida se presenta como hipótesis/sugerencia y mantiene visible el error real.

## Límites de la primera versión

- Solicitud iniciada expresamente por Admin TI para un job fallido; no diagnóstico automático de cada fallo.
- Contexto armado por servicios del backend y respuesta JSON validada.
- FastAPI mock local para integración y cliente falso para unit tests; no es un modelo generativo ni evalúa calidad diagnóstica.
- Proveedor/modelo real, si se desea, requiere aprobación separada de privacidad y costos.
- Sin ejecución automática de mitigaciones ni agente con herramientas autónomas.

El alcance, criterios verificables, riesgos y decisiones pendientes están definidos en el [PRD](PRD_DIAGNOSTICO_IA.md). Las relaciones propuestas se describen en el [ERD](ERD_DIAGNOSTICO_IA.md).
