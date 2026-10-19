# Prompt para diseño de interfaz (no genérico)

Pensado para pegar en una herramienta de diseño con IA (Figma Make, Claude
con capacidad de diseño, v0, etc.) y obtener mockups específicos de este
sistema, no un dashboard admin genérico.

---

## Prompt

Diseña la interfaz de un panel administrativo real para **USIL Paraguay**:
un sistema interno que sincroniza **Canvas LMS** (gestión académica) con
**Microsoft Teams** (identidad y colaboración). Lo usa el personal de TI
de la universidad — no estudiantes, no público general — para dar de alta
cursos, crear equipos de Teams a partir de cursos de Canvas, matricular
alumnos en ambas plataformas a la vez, y auditar cada operación. Es una
herramienta operativa de uso diario, no un producto SaaS de marketing: no
debe parecer una plantilla de dashboard genérica (nada de ilustraciones
stock, gradientes morados de SaaS, ni tarjetas de "métricas" vacías sin
sentido para este dominio).

**Identidad visual:**
- Azul institucional USIL como color primario: `#1E3FCF` (azul cobalto
  saturado, no pastel). Tipografía limpia, con peso para títulos de
  sección (el logo USIL usa una serif clásica para el wordmark — podés
  tomar esa seriedad sin copiar el logo).
- Como acentos *funcionales* (no decorativos), usá los colores reales de
  cada plataforma externa para que el estado sea reconocible de un
  vistazo: naranja Canvas (`#E8513C`) y púrpura Microsoft Teams
  (`#6264A7`). Un curso sincronizado en ambas muestra ambos acentos
  juntos; si falta una, se nota la ausencia del color correspondiente.
- Fondo neutro (gris muy claro o blanco), look institucional/confiable,
  no "startup". Pensá "sistema interno de universidad seria", no "app de
  consumo".

**Lo que hay que resolver con diseño, específico de este dominio (no
componentes genéricos):**

1. **Estado de sincronización por curso** — un curso puede estar: sin
   crear, creado solo en Canvas, creado solo en Teams, creado en ambas.
   Necesito un componente visual (chip/badge doble) que comunique ese
   estado combinado de un vistazo en una tabla con decenas de filas, sin
   depender solo de texto.

2. **Pipeline de un job de sincronización** — cada operación (crear
   curso, crear equipo, matricular, dar de baja) pasa por
   `pendiente → en_proceso → completado/error`. Diseñá cómo se ve ese
   ciclo de vida en una vista de detalle (no solo una tabla con una
   columna "estado" de texto plano) — pensá en algo tipo stepper/timeline
   que además muestre cuánto tardó y el error si lo hubo.

3. **Matriculación combinada** — la acción central del sistema: un
   admin de TI busca un alumno, busca un curso, y con una sola acción lo
   matricula en Canvas Y lo agrega al Team de Teams. El flujo debe dejar
   clarísimo que es UNA acción con DOS efectos (no dos pasos separados
   que parezcan independientes), y mostrar el resultado por plataforma
   si una de las dos falla (falla parcial es un caso real y frecuente acá,
   no un edge case a esconder).

4. **Equipo de Teams y sus miembros** — un EquipoTeams tiene un Owner
   (el docente) y Members (los alumnos). Diseñá cómo se visualiza esa
   jerarquía (no una tabla plana más): el Owner debe destacarse
   visualmente del resto.

5. **Log de auditoría** — quién hizo qué, sobre qué entidad, cuándo.
   Se usa para investigar incidentes ("¿por qué este alumno no tiene
   acceso al Team?"), así que necesita ser escaneable y filtrable rápido
   por usuario/curso/fecha, más que "lindo".

6. **Navegación** — el sistema tiene ~17 tipos de entidad agrupadas en:
   Identidad académica (Rol, Persona, Subcuenta), Oferta académica
   (Programa, Plan de Estudio, Materia, Período), Matriculación
   (Postulación, Matrícula, Alta en materias), Canvas ↔ Teams (Curso,
   Equipo Teams, Miembro Equipo, Material), Auditoría (Jobs, Log). Diseñá
   una navegación lateral agrupada y escaneable para eso — no una lista
   plana de 17 ítems con el mismo ícono.

**Pantallas a entregar (en este orden de prioridad):**
1. Listado de cursos con estado de sincronización Canvas/Teams (la
   pantalla que más se usa).
2. Detalle de un curso: datos, acciones (crear en Canvas / crear en
   Teams / asignar docente), miembros del equipo.
3. Flujo de matriculación combinada (buscar alumno → buscar curso →
   confirmar → resultado por plataforma).
4. Vista de un Job de sincronización (el stepper del punto 2).
5. Log de auditoría filtrable.

**Qué evitar explícitamente:** iconografía genérica de "negocio" (cohetes,
balanzas, engranajes abstractos sin relación al dominio), tarjetas de
KPI inventadas sin dato real detrás, cualquier mención a "usuarios
activos" o métricas de producto SaaS que no apliquen a un sistema interno
de sincronización de 2 plataformas para una universidad.

**Contexto técnico** (para que el diseño sea implementable, no solo
bonito): el backend es Laravel 13 + Filament 3.3. Si el output es Figma o
HTML/Tailwind, mantenelo cerca de lo que Filament puede renderizar
(tablas, formularios, badges, acciones en fila) para que el equipo lo
pueda traducir a código sin reinventar el framework del panel.

---

## Cómo usarlo

- **Con Figma (vía MCP de Figma, si está disponible en tu sesión de
  Claude):** pedí directamente "usá este prompt para generar el diseño
  en Figma" y Claude puede crear el archivo.
- **Con Claude normal (artefacto de diseño):** pedí "generá un mockup
  HTML de esto como artifact" y pegá el prompt.
- **Con v0.dev / otra herramienta de diseño con IA:** pegalo tal cual.

Si querés, puedo generar yo mismo un mockup HTML de las 2 pantallas más
importantes (listado de cursos y matriculación combinada) ahora mismo, sin
salir de esta conversación.
