<?php

namespace Tests\Feature;

use App\Filament\Resources\CursoResource;
use App\Filament\Resources\InscripcionMateriaResource;
use App\Filament\Resources\MaterialResource;
use App\Filament\Resources\MatriculaResource;
use App\Models\Curso;
use App\Models\Materia;
use App\Models\Material;
use App\Models\Matricula;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FilamentResourcesSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_loads(): void
    {
        $this->get('/admin/login')->assertStatus(200);
    }

    public function test_non_admin_user_cannot_access_panel(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertStatus(403);
    }

    public function test_admin_email_without_admin_role_cannot_access_panel(): void
    {
        $this->actingAs(User::factory()->create(['email' => 'admin@usil.edu.py']));

        $this->get('/admin')->assertStatus(403);
    }

    public function test_inactive_admin_person_cannot_access_panel(): void
    {
        $adminRole = Rol::factory()->create(['nombre' => 'Admin TI']);
        $adminUser = User::factory()->create();
        Persona::factory()->create([
            'user_id' => $adminUser->id,
            'rol_id' => $adminRole->id,
            'tipo' => 'administrativo',
            'estado' => 'inactivo',
        ]);

        $this->actingAs($adminUser);

        $this->get('/admin')->assertStatus(403);
    }

    public function test_docente_can_only_view_assigned_courses_and_materials(): void
    {
        $teacherRole = Rol::factory()->create(['nombre' => 'Docente']);
        $teacherUser = User::factory()->create();
        $teacher = Persona::factory()->docente()->create([
            'user_id' => $teacherUser->id,
            'rol_id' => $teacherRole->id,
        ]);
        $otherTeacher = Persona::factory()->docente()->create();

        $teacherCourse = Curso::factory()->create([
            'docente_persona_id' => $teacher->id,
            'materia_id' => Materia::factory()->create(['nombre' => 'Curso del docente'])->id,
        ]);
        $otherCourse = Curso::factory()->create([
            'docente_persona_id' => $otherTeacher->id,
            'materia_id' => Materia::factory()->create(['nombre' => 'Curso ajeno'])->id,
        ]);
        $teacherMaterial = Material::factory()->create(['curso_id' => $teacherCourse->id]);
        $otherMaterial = Material::factory()->create(['curso_id' => $otherCourse->id]);

        $this->actingAs($teacherUser);

        $this->get('/admin')->assertOk();
        $this->get('/admin/cursos')->assertOk();
        $this->get('/admin/materials')->assertOk();
        $this->get('/admin/personas')->assertForbidden();
        $this->get('/admin/cursos/create')->assertForbidden();
        $this->get('/admin/materials/create')->assertForbidden();
        $this->get("/admin/cursos/{$teacherCourse->id}/edit")->assertForbidden();
        $this->get("/admin/cursos/{$otherCourse->id}/edit")->assertNotFound();

        $this->assertEqualsCanonicalizing(
            [$teacherCourse->id],
            CursoResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$teacherMaterial->id],
            MaterialResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertNotContains($otherMaterial->id, MaterialResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_student_can_only_view_their_enrollments(): void
    {
        $studentRole = Rol::factory()->create(['nombre' => 'Estudiante']);
        $studentUser = User::factory()->create();
        $student = Persona::factory()->alumno()->create([
            'user_id' => $studentUser->id,
            'rol_id' => $studentRole->id,
        ]);
        $otherStudent = Persona::factory()->alumno()->create();

        $studentMatricula = Matricula::factory()->create(['persona_id' => $student->id]);
        $otherMatricula = Matricula::factory()->create(['persona_id' => $otherStudent->id]);
        $course = Curso::factory()->create();
        $studentInscripcion = $studentMatricula->inscripciones()->create([
            'curso_id' => $course->id,
            'origen' => 'manual',
            'estado' => 'inscrita',
            'fecha_inscripcion' => today(),
        ]);
        $otherInscripcion = $otherMatricula->inscripciones()->create([
            'curso_id' => Curso::factory()->create()->id,
            'origen' => 'manual',
            'estado' => 'inscrita',
            'fecha_inscripcion' => today(),
        ]);

        $this->actingAs($studentUser);

        $this->get('/admin')->assertOk();
        $this->get('/admin/matriculas')->assertOk();
        $this->get('/admin/inscripcion-materias')->assertOk();
        $this->get('/admin/cursos')->assertForbidden();
        $this->get('/admin/matriculas/create')->assertForbidden();
        $this->get("/admin/matriculas/{$studentMatricula->id}/edit")->assertForbidden();
        $this->get("/admin/matriculas/{$otherMatricula->id}/edit")->assertNotFound();
        $this->get("/admin/inscripcion-materias/{$studentInscripcion->id}/edit")->assertForbidden();
        $this->get("/admin/inscripcion-materias/{$otherInscripcion->id}/edit")->assertNotFound();

        $this->assertEqualsCanonicalizing(
            [$studentMatricula->id],
            MatriculaResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$studentInscripcion->id],
            InscripcionMateriaResource::getEloquentQuery()->pluck('id')->all(),
        );
        $this->assertNotContains(
            $otherInscripcion->id,
            InscripcionMateriaResource::getEloquentQuery()->pluck('id')->all(),
        );
    }

    #[DataProvider('resourceIndexUrls')]
    public function test_resource_index_requires_auth_for_admin_then_loads(string $url): void
    {
        $this->get($url)->assertRedirect('/admin/login');

        $adminRole = Rol::factory()->create(['nombre' => 'Admin TI']);
        $adminUser = User::factory()->create(['email' => 'admin@usil.edu.py']);
        Persona::factory()->create([
            'user_id' => $adminUser->id,
            'rol_id' => $adminRole->id,
            'tipo' => 'administrativo',
            'nombre_completo' => 'Admin de pruebas',
            'cedula' => '1234567',
            'email_institucional' => 'admin@usil.edu.py',
            'estado' => 'activo',
        ]);

        $this->actingAs($adminUser);

        $this->get($url)->assertStatus(200);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function resourceIndexUrls(): array
    {
        return [
            'programas' => ['/admin/programas'],
            'plan-estudios' => ['/admin/plan-estudios'],
            'materias' => ['/admin/materias'],
            'periodo-academicos' => ['/admin/periodo-academicos'],
            'personas' => ['/admin/personas'],
            'postulacions' => ['/admin/postulacions'],
            'matriculas' => ['/admin/matriculas'],
            'cursos' => ['/admin/cursos'],
            'inscripcion-materias' => ['/admin/inscripcion-materias'],
            'materials' => ['/admin/materials'],
            'job-sincronizacions' => ['/admin/job-sincronizacions'],
            'log-auditorias' => ['/admin/log-auditorias'],
        ];
    }
}
