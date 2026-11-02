<?php

namespace Tests\Feature;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Exceptions\EgresoException;
use App\Models\LogAuditoria;
use App\Models\Persona;
use App\Services\EgresoService;
use App\Services\SincronizacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EgresoServiceTest extends TestCase
{
    use RefreshDatabase;

    private EgresoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(EgresoService::class);
    }

    public function test_elimina_en_canvas_y_deshabilita_en_teams(): void
    {
        $persona = Persona::factory()->create([
            'canvas_user_id' => 'canvas-1',
            'azure_user_id' => 'azure-1',
            'estado' => 'activo',
        ]);

        $canvas = new class implements CanvasClient
        {
            public array $eliminados = [];

            public function createCourse(string $name, string $sisCourseId): string
            {
                return 'x';
            }

            public function enrollUser(string $canvasCourseId, string $canvasUserId, string $role = 'StudentEnrollment'): void {}

            public function unenrollUser(string $canvasCourseId, string $canvasUserId): void {}

            public function createPage(string $canvasCourseId, string $title, string $body): string
            {
                return 'x';
            }

            public function findUserBySisId(string $sisUserId): ?array
            {
                return null;
            }

            public function findUserByLoginId(string $loginId): ?array
            {
                return null;
            }

            public function createUser(array $datos): string
            {
                return 'x';
            }

            public function deleteUser(string $canvasUserId): void
            {
                $this->eliminados[] = $canvasUserId;
            }
        };

        $teams = new class implements TeamsClient
        {
            public array $deshabilitados = [];

            public array $licenciasRemovidas = [];

            public function createTeam(string $name): string
            {
                return 'x';
            }

            public function addMember(string $teamsGroupId, string $azureUserId, string $role = 'Member'): void {}

            public function removeMember(string $teamsGroupId, string $azureUserId): void {}

            public function findUserByUpn(string $upn): ?array
            {
                return null;
            }

            public function createUser(array $datos): string
            {
                return 'x';
            }

            public function disableUser(string $azureUserId): void
            {
                $this->deshabilitados[] = $azureUserId;
            }

            public function removeAllLicenses(string $azureUserId): array
            {
                $this->licenciasRemovidas[] = $azureUserId;

                return ['sku-1', 'sku-2'];
            }

            public function downloadSharedFile(string $shareUrl): string
            {
                return '';
            }

            public function findGroupByName(string $name): ?string
            {
                return null;
            }

            public function listOrphanedLicenses(): array
            {
                return [];
            }

            public function listInactiveUsers(int $minDaysInactive): array
            {
                return [];
            }
        };

        $service = new EgresoService($canvas, $teams, app(SincronizacionService::class));
        $persona = $service->darDeBaja($persona, 'both');

        $this->assertSame(['canvas-1'], $canvas->eliminados);
        $this->assertSame(['azure-1'], $teams->deshabilitados);
        $this->assertSame(['azure-1'], $teams->licenciasRemovidas);

        // Canvas: baja definitiva -> se limpia el ID.
        $this->assertNull($persona->canvas_user_id);
        // Teams: baja reversible -> el ID se conserva, solo cambia el estado.
        $this->assertSame('azure-1', $persona->azure_user_id);
        $this->assertSame('inactivo', $persona->estado);

        $logs = LogAuditoria::where('entidad_afectada', 'like', "Persona#{$persona->id}:%")->get();
        $this->assertCount(2, $logs);
    }

    public function test_no_deja_dar_de_baja_una_persona_sin_cuentas(): void
    {
        $persona = Persona::factory()->create([
            'canvas_user_id' => null,
            'azure_user_id' => null,
        ]);

        $this->expectException(EgresoException::class);
        $this->expectExceptionMessage('no tiene cuentas institucionales');

        $this->service->darDeBaja($persona, 'both');
    }

    public function test_solo_canvas_no_toca_teams(): void
    {
        $persona = Persona::factory()->create([
            'canvas_user_id' => 'canvas-1',
            'azure_user_id' => 'azure-1',
        ]);

        $canvas = new class implements CanvasClient
        {
            public int $bajas = 0;

            public function createCourse(string $name, string $sisCourseId): string
            {
                return 'x';
            }

            public function enrollUser(string $canvasCourseId, string $canvasUserId, string $role = 'StudentEnrollment'): void {}

            public function unenrollUser(string $canvasCourseId, string $canvasUserId): void {}

            public function createPage(string $canvasCourseId, string $title, string $body): string
            {
                return 'x';
            }

            public function findUserBySisId(string $sisUserId): ?array
            {
                return null;
            }

            public function findUserByLoginId(string $loginId): ?array
            {
                return null;
            }

            public function createUser(array $datos): string
            {
                return 'x';
            }

            public function deleteUser(string $canvasUserId): void
            {
                $this->bajas++;
            }
        };

        $teams = new class implements TeamsClient
        {
            public function createTeam(string $name): string
            {
                return 'x';
            }

            public function addMember(string $teamsGroupId, string $azureUserId, string $role = 'Member'): void {}

            public function removeMember(string $teamsGroupId, string $azureUserId): void {}

            public function findUserByUpn(string $upn): ?array
            {
                return null;
            }

            public function createUser(array $datos): string
            {
                return 'x';
            }

            public function disableUser(string $azureUserId): void
            {
                throw new \RuntimeException('No debería llamarse a Teams en una baja solo de Canvas.');
            }

            public function removeAllLicenses(string $azureUserId): array
            {
                return [];
            }

            public function downloadSharedFile(string $shareUrl): string
            {
                return '';
            }

            public function findGroupByName(string $name): ?string
            {
                return null;
            }

            public function listOrphanedLicenses(): array
            {
                return [];
            }

            public function listInactiveUsers(int $minDaysInactive): array
            {
                return [];
            }
        };

        $service = new EgresoService($canvas, $teams, app(SincronizacionService::class));
        $persona = $service->darDeBaja($persona, 'canvas');

        $this->assertSame(1, $canvas->bajas);
        $this->assertNull($persona->canvas_user_id);
        // Teams no se tocó: el ID se conserva intacto.
        $this->assertSame('azure-1', $persona->azure_user_id);
    }
}
