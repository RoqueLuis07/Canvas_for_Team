<?php

namespace Database\Seeders;

use App\Contracts\CanvasClient;
use App\Models\Curso;
use App\Models\Materia;
use App\Models\Material;
use App\Models\Matricula;
use App\Models\PeriodoAcademico;
use App\Models\Persona;
use App\Models\PlanEstudio;
use App\Models\Postulacion;
use App\Models\Programa;
use App\Models\Rol;
use App\Models\Subcuenta;
use App\Models\User;
use App\Services\CursoService;
use App\Services\InscripcionMateriaService;
use App\Services\MaterialService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed de un caso completo, de punta a punta: postulación → admisión →
     * matrícula → asignación de curso (Canvas/Teams) → alta en materias
     * (predefinida para un alumno nuevo, manual para uno que continúa),
     * incluyendo la matriculación automática en Canvas y Teams.
     *
     * Sin CANVAS_ACCESS_TOKEN / credenciales de Azure configuradas, los
     * clientes usados son los simulados (Null*Client) — ver
     * App\Providers\AppServiceProvider.
     */
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Administrador Académico',
            'email' => 'admin@usil.edu.py',
        ]);

        // ── Entidades formales del MER de Avance 1: ROL y SUBCUENTA. ──────
        $rolAdminTi = Rol::factory()->create(['nombre' => 'Admin TI', 'descripcion' => 'Personal de TI con acceso total al panel.']);
        $rolDocente = Rol::factory()->create(['nombre' => 'Docente', 'descripcion' => 'Docente titular de uno o más cursos.']);
        $rolEstudiante = Rol::factory()->create(['nombre' => 'Estudiante', 'descripcion' => 'Alumno matriculado en la institución.']);

        $subcuenta = Subcuenta::factory()->create([
            'canvas_account_id' => 1001,
            'nombre' => 'Facultad de Ingeniería',
            'carrera' => 'Ingeniería en Informática',
            'sede' => 'USIL Paraguay',
        ]);

        $programa = Programa::factory()->create([
            'codigo' => 'ING-INF',
            'nombre' => 'Ingeniería en Informática',
            'tipo' => 'grado',
        ]);

        $plan = PlanEstudio::factory()->create([
            'programa_id' => $programa->id,
            'nombre' => 'Plan de Estudio 2026',
            'version' => 1,
        ]);

        // Primer semestre: Matemática I es prerrequisito de Cálculo I.
        $matematicaI = Materia::factory()->primerSemestre()->create([
            'plan_estudio_id' => $plan->id,
            'codigo' => 'MAT-101',
            'nombre' => 'Matemática I',
        ]);
        $introProgramacion = Materia::factory()->primerSemestre()->create([
            'plan_estudio_id' => $plan->id,
            'codigo' => 'INF-101',
            'nombre' => 'Introducción a la Programación',
        ]);
        $calculoI = Materia::factory()->create([
            'plan_estudio_id' => $plan->id,
            'codigo' => 'MAT-201',
            'nombre' => 'Cálculo I',
            'semestre_sugerido' => 2,
        ]);
        $calculoI->prerequisitos()->attach($matematicaI);

        $periodo = PeriodoAcademico::factory()->create([
            'nombre' => '2026-2',
            'estado' => 'inscripciones_abiertas',
        ]);

        // ── Asignación de curso: el Departamento Académico crea el Curso
        //    (oferta de la materia en este período) y lo publica en Canvas
        //    y Teams — paso obligatorio antes de poder matricular. ────────
        $cursoService = app(CursoService::class);
        $inscripciones = app(InscripcionMateriaService::class);
        $materiales = new MaterialService(app(CanvasClient::class));

        $cursoMatematica = Curso::factory()->create(['materia_id' => $matematicaI->id, 'subcuenta_id' => $subcuenta->id, 'periodo_academico_id' => $periodo->id, 'cupo_maximo' => null]);
        $cursoIntroProgramacion = Curso::factory()->create(['materia_id' => $introProgramacion->id, 'subcuenta_id' => $subcuenta->id, 'periodo_academico_id' => $periodo->id, 'cupo_maximo' => null]);
        $cursoCalculo = Curso::factory()->create(['materia_id' => $calculoI->id, 'subcuenta_id' => $subcuenta->id, 'periodo_academico_id' => $periodo->id, 'cupo_maximo' => 2]);

        foreach ([$cursoMatematica, $cursoIntroProgramacion, $cursoCalculo] as $curso) {
            $cursoService->crearEnCanvas($curso);
            $cursoService->crearEnTeams($curso);
        }

        // ── Docente titular de Matemática I: se da de alta como profesor en
        //    Canvas y como propietario del equipo en Teams, y publica el
        //    primer material del curso. ───────────────────────────────────
        $docente = Persona::factory()->docente()->create([
            'nombre_completo' => 'Prof. Lucía Fernández',
            'email_institucional' => 'lfernandez@usil.edu.py',
            'rol_id' => $rolDocente->id,
            'canvas_user_id' => 'canvas-lucia',
            'azure_user_id' => 'azure-lucia',
        ]);
        $cursoService->asignarDocente($cursoMatematica, $docente);

        $materiales->publicarEnCanvas(Material::factory()->create([
            'curso_id' => $cursoMatematica->id,
            'titulo' => 'Programa de la materia y bibliografía',
            'tipo' => 'documento',
            'descripcion' => 'Programa analítico, cronograma de clases y bibliografía obligatoria de Matemática I.',
            'orden' => 1,
        ]));

        // ── Caso 1: alumno nuevo — postulación admitida, cursos del primer
        //    semestre predefinidos automáticamente por el Departamento. ───
        $aspiranteNuevo = Persona::factory()->create([
            'nombre_completo' => 'Ana Benítez',
            'email_institucional' => 'abenitez@usil.edu.py',
            'rol_id' => $rolEstudiante->id,
            'canvas_user_id' => 'canvas-ana',
            'azure_user_id' => 'azure-ana',
        ]);
        $postulacion = Postulacion::factory()->admitido()->create([
            'persona_id' => $aspiranteNuevo->id,
            'programa_id' => $programa->id,
            'periodo_academico_id' => $periodo->id,
        ]);
        $aspiranteNuevo->update(['tipo' => 'alumno']);
        $matriculaNueva = Matricula::factory()->create([
            'persona_id' => $aspiranteNuevo->id,
            'plan_estudio_id' => $plan->id,
            'periodo_academico_id' => $periodo->id,
            'postulacion_id' => $postulacion->id,
        ]);
        $inscripciones->inscribirPredefinidasAlumnoNuevo($matriculaNueva);

        // ── Caso 2: alumno que continúa — ya aprobó el prerrequisito
        //    (Matemática I) en un período anterior, y ahora da de alta
        //    Cálculo I manualmente. ─────────────────────────────────────
        $alumnoContinua = Persona::factory()->alumno()->create([
            'nombre_completo' => 'Carlos Rojas',
            'email_institucional' => 'crojas@usil.edu.py',
            'rol_id' => $rolEstudiante->id,
            'canvas_user_id' => 'canvas-carlos',
            'azure_user_id' => 'azure-carlos',
        ]);
        $periodoAnterior = PeriodoAcademico::factory()->create([
            'nombre' => '2026-1',
            'estado' => 'cerrado',
        ]);
        $cursoMatematicaAnterior = Curso::factory()->creadoEnCanvasYTeams()->create([
            'materia_id' => $matematicaI->id,
            'periodo_academico_id' => $periodoAnterior->id,
        ]);
        $matriculaAnterior = Matricula::factory()->create([
            'persona_id' => $alumnoContinua->id,
            'plan_estudio_id' => $plan->id,
            'periodo_academico_id' => $periodoAnterior->id,
            'postulacion_id' => null,
            'estado' => 'finalizada',
        ]);
        $matriculaAnterior->inscripciones()->create([
            'curso_id' => $cursoMatematicaAnterior->id,
            'origen' => 'manual',
            'estado' => 'aprobada',
            'fecha_inscripcion' => now()->subMonths(6),
        ]);

        $matriculaContinua = Matricula::factory()->create([
            'persona_id' => $alumnoContinua->id,
            'plan_estudio_id' => $plan->id,
            'periodo_academico_id' => $periodo->id,
            'postulacion_id' => null,
        ]);
        $inscripciones->inscribirManual($matriculaContinua, $cursoCalculo);

        $this->command?->info("Seed listo. Login de prueba: {$admin->email}");
    }
}
