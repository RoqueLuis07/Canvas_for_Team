<?php

namespace Tests\Feature;

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
