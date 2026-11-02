<?php

namespace Tests\Feature;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Exceptions\IngresoException;
use App\Models\LogAuditoria;
use App\Models\Persona;
use App\Services\CredentialGenerator;
use App\Services\IngresoService;
use App\Services\SincronizacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngresoServiceTest extends TestCase
{
    use RefreshDatabase;

    private IngresoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(IngresoService::class);
    }

    /**
     * Test double que simula Canvas/Teams vacíos (nadie existe todavía) y
     * registra los payloads de createUser() recibidos, para poder verificar
     * exactamente qué credenciales se generaron.
     */
    private function canvasVacioQueRegistra(): CanvasClient
    {
        return new class implements CanvasClient
        {
            public array $usuariosCreados = [];

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
                $this->usuariosCreados[] = $datos;

                return 'canvas-user-'.count($this->usuariosCreados);
            }

            public function deleteUser(string $canvasUserId): void {}
        };
    }

    private function teamsVacioQueRegistra(): TeamsClient
    {
        return new class implements TeamsClient
        {
            public array $usuariosCreados = [];

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
                $this->usuariosCreados[] = $datos;

                return 'azure-user-'.count($this->usuariosCreados);
            }

            public function disableUser(string $azureUserId): void {}

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
    }

    public function test_da_de_alta_en_canvas_y_teams_generando_credenciales(): void
    {
        $persona = Persona::factory()->alumno()->create([
            'nombre_completo' => 'Karen Gonzalez',
            'cedula' => '6868066',
            'canvas_user_id' => null,
            'azure_user_id' => null,
        ]);

        $canvas = $this->canvasVacioQueRegistra();
        $teams = $this->teamsVacioQueRegistra();
        $service = new IngresoService($canvas, $teams, new CredentialGenerator(), app(SincronizacionService::class));

        $persona = $service->darDeAlta($persona, 'both');

        $this->assertNotNull($persona->canvas_user_id);
        $this->assertNotNull($persona->azure_user_id);
        $this->assertSame('activo', $persona->estado);
        $this->assertSame('karen.gonzalez@usil.edu.py', $persona->email_institucional);

        $this->assertCount(1, $canvas->usuariosCreados);
        $this->assertSame('karen.gonzalez', $canvas->usuariosCreados[0]['login_id']);
        $this->assertSame('6868066', $canvas->usuariosCreados[0]['sis_user_id']);
        $this->assertSame('6868066-Kg', $canvas->usuariosCreados[0]['password']);

        $this->assertCount(1, $teams->usuariosCreados);
        $this->assertSame('karen.gonzalez@usil.edu.py', $teams->usuariosCreados[0]['upn']);
        $this->assertSame('6868066', $teams->usuariosCreados[0]['cedula']);

        $logs = LogAuditoria::where('entidad_afectada', 'like', "Persona#{$persona->id}:%")->get();
        $this->assertCount(2, $logs);
    }

    public function test_docente_recibe_la_contrasena_generica_en_vez_del_esquema_cedula_iniciales(): void
    {
        $persona = Persona::factory()->docente()->create([
            'nombre_completo' => 'Juan Perez',
            'cedula' => '1234567',
            'canvas_user_id' => null,
            'azure_user_id' => null,
        ]);

        $canvas = $this->canvasVacioQueRegistra();
        $teams = $this->teamsVacioQueRegistra();
        $service = new IngresoService($canvas, $teams, new CredentialGenerator(), app(SincronizacionService::class));

        $service->darDeAlta($persona, 'both');

        $this->assertSame('Usil2026*', $canvas->usuariosCreados[0]['password']);
        // El SIS user ID de un docente es su correo, no la cédula (igual que en producción).
        $this->assertSame('juan.perez@usil.edu.py', $canvas->usuariosCreados[0]['sis_user_id']);
    }

    public function test_reutiliza_el_correo_existente_en_un_reingreso(): void
    {
        $persona = Persona::factory()->alumno()->create([
            'nombre_completo' => 'Ana Lopez',
            'cedula' => '9999999',
            'canvas_user_id' => null,
            'azure_user_id' => null,
        ]);

        $canvasConHistorial = new class implements CanvasClient
        {
            public array $usuariosCreados = [];

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
                // Ya existe en Canvas con un correo distinto al que generaría
                // el esquema estándar (ej. cambió de apellido desde el alta).
                return ['id' => 'canvas-existente-1', 'name' => 'Ana Lopez', 'login_id' => 'ana.lopez.vieja@usil.edu.py', 'email' => 'ana.lopez.vieja@usil.edu.py'];
            }

            public function findUserByLoginId(string $loginId): ?array
            {
                return null;
            }

            public function createUser(array $datos): string
            {
                $this->usuariosCreados[] = $datos;

                return 'no-deberia-crearse';
            }

            public function deleteUser(string $canvasUserId): void {}
        };

        $teams = $this->teamsVacioQueRegistra();
        $service = new IngresoService($canvasConHistorial, $teams, new CredentialGenerator(), app(SincronizacionService::class));

        $persona = $service->darDeAlta($persona, 'both');

        // Se reutiliza el ID/correo ya existente — no se crea una cuenta nueva en Canvas.
        $this->assertSame('canvas-existente-1', $persona->canvas_user_id);
        $this->assertSame('ana.lopez.vieja@usil.edu.py', $persona->email_institucional);
        $this->assertCount(0, $canvasConHistorial->usuariosCreados);

        // Pero sí se crea en Teams, con el correo reutilizado de Canvas.
        $this->assertCount(1, $teams->usuariosCreados);
        $this->assertSame('ana.lopez.vieja@usil.edu.py', $teams->usuariosCreados[0]['upn']);
    }

    public function test_resuelve_colision_de_correo_probando_sufijos(): void
    {
        $persona = Persona::factory()->alumno()->create([
            'nombre_completo' => 'Juan Perez Gomez',
            'cedula' => '5555555',
            'canvas_user_id' => null,
            'azure_user_id' => null,
        ]);

        // "juan.perez" ya está tomado en Canvas -> debe probar el siguiente
        // sufijo generado a partir de la palabra extra ("Gomez" -> "g").
        $canvasConColision = new class implements CanvasClient
        {
            public array $usuariosCreados = [];

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
                return $loginId === 'juan.perez'
                    ? ['id' => 'otro', 'name' => 'Otro Juan Perez', 'login_id' => 'juan.perez@usil.edu.py', 'email' => 'juan.perez@usil.edu.py']
                    : null;
            }

            public function createUser(array $datos): string
            {
                $this->usuariosCreados[] = $datos;

                return 'canvas-user-1';
            }

            public function deleteUser(string $canvasUserId): void {}
        };

        $teams = $this->teamsVacioQueRegistra();
        $service = new IngresoService($canvasConColision, $teams, new CredentialGenerator(), app(SincronizacionService::class));

        $persona = $service->darDeAlta($persona, 'both');

        $this->assertSame('juan.perezg', $canvasConColision->usuariosCreados[0]['login_id']);
        $this->assertSame('juan.perezg@usil.edu.py', $persona->email_institucional);
    }

    public function test_no_deja_dar_de_alta_si_ya_tiene_ambas_cuentas(): void
    {
        $persona = Persona::factory()->create([
            'canvas_user_id' => 'canvas-1',
            'azure_user_id' => 'azure-1',
        ]);

        $this->expectException(IngresoException::class);
        $this->expectExceptionMessage('ya tiene cuentas institucionales');

        $this->service->darDeAlta($persona, 'both');
    }
}
