# Guía del LLM simulado

## Comportamiento

`NullLLMClient` no llama a OpenAI ni a otro proveedor: no requiere API key, red ni cuota. Es determinista: el mismo contexto seguro produce el mismo diagnóstico. El resultado puede cambiar solo si cambian las reglas/código o si una prueba configura respuestas con `NullLLMClient::fake()`.

En el flujo de la aplicación, el mensaje original del error se reduce primero a una categoría permitida y un código HTTP opcional. Esa pareja, no el error original, es lo que recibe el cliente. Por eso, los diagnósticos se activan por categorías y códigos reconocidos; los términos de proveedor documentados abajo ayudan a clasificar el error original.

## Categorías y claves reconocidas

| Diagnóstico | Claves/códigos que lo activan |
|---|---|
| Autenticación | HTTP `401`, `Unauthorized`, `Authentication`, `invalid_grant`, `token_expired`, `invalid access token` |
| Permisos | HTTP `403`, `Forbidden`, `Authorization_RequestDenied`, `insufficient privileges` |
| Recurso no encontrado | HTTP `404`, `Not Found`, `Request_ResourceNotFound`, `ErrorItemNotFound` |
| Recurso eliminado | HTTP `410`, `Gone`, `resource_gone`, `has been deleted` |
| Conflicto/duplicado | HTTP `409`, `already exists`, `duplicate`, `ObjectConflict`, `mailNicknameInUse` |
| Solicitud/datos inválidos | HTTP `400` o `422`, `Bad Request`, `Request_BadRequest`, `invalid_request`, `validation`, `invalid property` |
| Método no permitido | HTTP `405`, `Method Not Allowed` |
| Límite de solicitudes | HTTP `429`, `Too Many Requests`, `throttl`, `rate limit` |
| Tiempo agotado | HTTP `408` o `504`, `timeout`, `timed out`, `Gateway Timeout` |
| Servicio no disponible | HTTP `500`, `501`, `502` o `503`, `Service Unavailable` |
| Conectividad | `Connection refused`, `Could not resolve host`, `DNS`, `SSL certificate` |
| Desconocido | Ninguna clave/categoría anterior |

El simulador devuelve JSON en español con resumen, causa probable, confianza, pasos de verificación, mitigaciones y advertencias. Las recomendaciones son informativas: el simulador no modifica jobs ni ejecuta acciones externas.

## Probar

```powershell
# Muestra los ejemplos y el contexto enmascarado que se deriva de cada uno
php artisan diagnostico:demo --listar

# Diagnostica un ejemplo en consola, sin base de datos
php artisan diagnostico:demo "429 Too Many Requests"

# Siembra jobs fallidos de ejemplo para probar el panel Filament
php artisan diagnostico:demo --sembrar
```

Para ver el flujo persistido, abra `/admin`, vaya a **Auditoría → Jobs de sincronización**, y en un job fallido pulse **Diagnosticar con IA**. Los resultados aparecen en **Auditoría → Diagnósticos IA**.

Los ejemplos no contienen credenciales ni datos personales reales. El enmascarado no sustituye la revisión de privacidad antes de conectar un proveedor real.
