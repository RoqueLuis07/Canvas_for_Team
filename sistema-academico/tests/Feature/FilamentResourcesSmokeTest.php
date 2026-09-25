<?php

namespace Tests\Feature;

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

    #[DataProvider('resourceIndexUrls')]
    public function test_resource_index_requires_auth_then_loads(string $url): void
    {
        $this->get($url)->assertRedirect('/admin/login');

        $this->actingAs(User::factory()->create());

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
        ];
    }
}
