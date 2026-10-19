<?php

namespace Tests\Feature;

use App\Filament\Resources\JobSincronizacionResource\Pages\ListJobSincronizacions;
use App\Models\DiagnosticoIA;
use App\Models\JobSincronizacion;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use App\Services\LLM\NullLLMClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DiagnosticoIAFilamentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        NullLLMClient::reset();
    }

    public function test_admin_diagnostica_job_fallido_desde_el_panel(): void
    {
        $this->actingAs($this->adminUser());
        $job = JobSincronizacion::factory()->create(['estado' => 'error', 'resultado' => '429 Too Many Requests']);

        Livewire::test(ListJobSincronizacions::class)
            ->callTableAction('diagnosticar', $job)
            ->assertHasNoTableActionErrors();

        $diagnostico = DiagnosticoIA::query()->firstOrFail();
        $this->assertSame('completado', $diagnostico->estado);
        $this->assertSame('limite_solicitudes', $diagnostico->contexto_sanitizado['categoria']);
    }

    public function test_accion_oculta_en_jobs_no_fallidos(): void
    {
        $this->actingAs($this->adminUser());
        $job = JobSincronizacion::factory()->create(['estado' => 'completado']);

        Livewire::test(ListJobSincronizacions::class)
            ->assertTableActionHidden('diagnosticar', $job);
    }

    public function test_historial_lista_diagnosticos_y_bloquea_no_admin(): void
    {
        DiagnosticoIA::query()->create([
            'job_sincronizacion_id' => JobSincronizacion::factory()->create(['estado' => 'error'])->id,
            'estado' => 'pendiente',
            'version_prompt' => '1',
        ]);

        $this->actingAs(User::factory()->create())
            ->get('/admin/diagnostico-i-as')
            ->assertForbidden();

        $this->actingAs($this->adminUser())
            ->get('/admin/diagnostico-i-as')
            ->assertOk();
    }

    private function adminUser(): User
    {
        $usuario = User::factory()->create();
        $rol = Rol::query()->firstOrCreate(
            ['nombre' => 'Admin TI'],
            ['descripcion' => 'Administrador de tecnologías de información'],
        );

        Persona::factory()->create([
            'user_id' => $usuario->id,
            'rol_id' => $rol->id,
            'estado' => 'activo',
            'tipo' => 'administrativo',
        ]);

        return $usuario;
    }
}
