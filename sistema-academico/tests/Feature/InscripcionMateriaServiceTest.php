<?php

namespace Tests\Feature;

use App\Exceptions\InscripcionMateriaException;
use App\Models\Curso;
use App\Models\Materia;
use App\Models\Matricula;
use App\Models\PeriodoAcademico;
use App\Models\Persona;
use App\Models\PlanEstudio;
use App\Models\Postulacion;
use App\Services\InscripcionMateriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InscripcionMateriaServiceTest extends TestCase
{
    use RefreshDatabase;

    private InscripcionMateriaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InscripcionMateriaService::class);
    }

    private function personaConCuentas(): Persona
    {
        return Persona::factory()->create([
            'canvas_user_id' => 'canvas-1',
            'azure_user_id' => 'azure-1',
        ]);
    }

    public function test_no_deja_matricular_en_un_curso_no_creado_en_canvas_y_teams(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->create(['materia_id' => $materia->id]); // sin canvas_course_id/teams_group_id

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $this->expectException(InscripcionMateriaException::class);
        $this->expectExceptionMessage('todavía no fue creado en Canvas y Teams');

        $this->service->inscribirManual($matricula, $curso);
    }

    public function test_no_deja_inscribir_sin_prerequisito_aprobado(): void
    {
        $plan = PlanEstudio::factory()->create();
        $prerequisito = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $materia->prerequisitos()->attach($prerequisito);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $this->expectException(InscripcionMateriaException::class);
        $this->expectExceptionMessage('falta aprobar');

        $this->service->inscribirManual($matricula, $curso);
    }

    public function test_deja_inscribir_con_prerequisito_aprobado_y_da_de_alta_en_canvas_y_teams(): void
    {
        $plan = PlanEstudio::factory()->create();
        $prerequisito = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $materia->prerequisitos()->attach($prerequisito);
        $cursoPrerequisito = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $prerequisito->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);
        $matricula->inscripciones()->create([
            'curso_id' => $cursoPrerequisito->id,
            'origen' => 'manual',
            'estado' => 'aprobada',
            'fecha_inscripcion' => now(),
        ]);

        $inscripcion = $this->service->inscribirManual($matricula, $curso);

        $this->assertSame('inscrita', $inscripcion->estado);
        $this->assertSame('manual', $inscripcion->origen);
        $this->assertTrue($inscripcion->estaAlDiaEnCanvas());
        $this->assertTrue($inscripcion->estaAlDiaEnTeams());
    }

    public function test_no_deja_inscribir_sin_cupo_disponible(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => 1]);

        $primeraMatricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);
        $this->service->inscribirManual($primeraMatricula, $curso);

        $segundaMatricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $this->expectException(InscripcionMateriaException::class);
        $this->expectExceptionMessage('No hay cupo disponible');

        $this->service->inscribirManual($segundaMatricula, $curso);
    }

    public function test_no_deja_inscribir_dos_veces_en_el_mismo_curso(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);
        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $this->service->inscribirManual($matricula, $curso);

        $this->expectException(InscripcionMateriaException::class);
        $this->expectExceptionMessage('ya está inscrito');

        $this->service->inscribirManual($matricula, $curso);
    }

    public function test_alumno_nuevo_recibe_cursos_de_primer_semestre_predefinidos(): void
    {
        $plan = PlanEstudio::factory()->create();
        $periodo = PeriodoAcademico::factory()->create();

        $materia1 = Materia::factory()->primerSemestre()->create(['plan_estudio_id' => $plan->id]);
        $materia2 = Materia::factory()->primerSemestre()->create(['plan_estudio_id' => $plan->id]);
        $materia3 = Materia::factory()->create(['plan_estudio_id' => $plan->id, 'semestre_sugerido' => 3]);

        Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia1->id, 'periodo_academico_id' => $periodo->id]);
        Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia2->id, 'periodo_academico_id' => $periodo->id]);
        Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia3->id, 'periodo_academico_id' => $periodo->id]);

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'periodo_academico_id' => $periodo->id,
            'postulacion_id' => Postulacion::factory()->admitido()->create()->id,
        ]);

        $inscripciones = $this->service->inscribirPredefinidasAlumnoNuevo($matricula);

        $this->assertCount(2, $inscripciones);
        $this->assertTrue($inscripciones->every(fn ($i) => $i->origen === 'predefinida'));
        $this->assertTrue($inscripciones->every(fn ($i) => $i->estaAlDiaEnCanvas() && $i->estaAlDiaEnTeams()));
    }

    public function test_alumno_que_continua_no_puede_recibir_cursos_predefinidos(): void
    {
        $plan = PlanEstudio::factory()->create();
        $matricula = Matricula::factory()->create(['plan_estudio_id' => $plan->id, 'postulacion_id' => null]);

        $this->expectException(InscripcionMateriaException::class);

        $this->service->inscribirPredefinidasAlumnoNuevo($matricula);
    }

    public function test_dar_de_baja_retira_academicamente_y_de_canvas_teams(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);
        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $inscripcion = $this->service->inscribirManual($matricula, $curso);
        $inscripcion = $this->service->darDeBaja($inscripcion);

        $this->assertSame('retirada', $inscripcion->estado);
        $this->assertFalse($inscripcion->estaAlDiaEnCanvas());
        $this->assertFalse($inscripcion->estaAlDiaEnTeams());
    }

    public function test_cambiar_materia_da_de_baja_la_actual_e_inscribe_en_la_nueva(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materiaActual = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $materiaNueva = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $cursoActual = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materiaActual->id, 'cupo_maximo' => null]);
        $cursoNuevo = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materiaNueva->id, 'cupo_maximo' => null]);

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $inscripcionActual = $this->service->inscribirManual($matricula, $cursoActual);
        $inscripcionNueva = $this->service->cambiarMateria($inscripcionActual, $cursoNuevo);

        $this->assertSame('retirada', $inscripcionActual->refresh()->estado);
        $this->assertSame('inscrita', $inscripcionNueva->estado);
        $this->assertSame($cursoNuevo->id, $inscripcionNueva->curso_id);
        $this->assertTrue($inscripcionNueva->estaAlDiaEnCanvas());
        $this->assertTrue($inscripcionNueva->estaAlDiaEnTeams());
    }
}
