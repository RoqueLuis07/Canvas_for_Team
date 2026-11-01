<?php

namespace Tests\Feature;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Models\Persona;
use App\Services\CredentialGenerator;
use App\Services\Excel\ExcelHeaderMatcher;
use App\Services\Excel\PersonaImporter;
use App\Services\Excel\XlsxRowReader;
use App\Services\IngresoService;
use App\Services\SincronizacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class PersonaImporterTest extends TestCase
{
    use RefreshDatabase;

    private PersonaImporter $importer;

    private string $archivoTemporal;

    protected function setUp(): void
    {
        parent::setUp();

        $canvas = $this->canvasVacioQueRegistra();
        $teams = $this->teamsVacioQueRegistra();
        $ingreso = new IngresoService($canvas, $teams, new CredentialGenerator(), app(SincronizacionService::class));

        $this->importer = new PersonaImporter(new XlsxRowReader(), new ExcelHeaderMatcher(), $ingreso);
        $this->archivoTemporal = sys_get_temp_dir().'/persona_importer_test_'.uniqid().'.xlsx';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->archivoTemporal)) {
            unlink($this->archivoTemporal);
        }

        parent::tearDown();
    }

    /**
     * @param  list<list<string>>  $filas
     */
    private function escribirXlsx(array $filas): void
    {
        $writer = new Writer();
        $writer->openToFile($this->archivoTemporal);

        foreach ($filas as $fila) {
            $writer->addRow(Row::fromValues($fila));
        }

        $writer->close();
    }

    private function canvasVacioQueRegistra(): CanvasClient
    {
        return new class implements CanvasClient
        {
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
                return 'canvas-user-'.$datos['sis_user_id'];
            }

            public function deleteUser(string $canvasUserId): void {}
        };
    }

    private function teamsVacioQueRegistra(): TeamsClient
    {
        return new class implements TeamsClient
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
                return 'azure-user-'.$datos['cedula'];
            }

            public function disableUser(string $azureUserId): void {}

            public function removeAllLicenses(string $azureUserId): array
            {
                return [];
            }
        };
    }

    public function test_importa_varias_personas_exitosamente(): void
    {
        $this->escribirXlsx([
            ['Nombre Completo', 'Cédula', 'Email Personal', 'Tipo'],
            ['Karen Gonzalez', '6868066', 'karen@example.com', 'alumno'],
            ['Juan Perez', '1234567', 'juan@example.com', 'docente'],
        ]);

        $resultado = $this->importer->importar($this->archivoTemporal);

        $this->assertCount(2, $resultado['succeeded']);
        $this->assertCount(0, $resultado['failed']);

        $this->assertDatabaseHas('personas', ['cedula' => '6868066', 'tipo' => 'alumno']);
        $this->assertDatabaseHas('personas', ['cedula' => '1234567', 'tipo' => 'docente']);

        $karen = Persona::where('cedula', '6868066')->first();
        $this->assertNotNull($karen->canvas_user_id);
        $this->assertNotNull($karen->azure_user_id);
    }

    public function test_fila_sin_cedula_falla_sin_abortar_el_resto_del_lote(): void
    {
        $this->escribirXlsx([
            ['Nombre Completo', 'Cédula'],
            ['Sin Cedula', ''],
            ['Karen Gonzalez', '6868066'],
        ]);

        $resultado = $this->importer->importar($this->archivoTemporal);

        $this->assertCount(1, $resultado['succeeded']);
        $this->assertCount(1, $resultado['failed']);
        $this->assertStringContainsString('obligatorios', $resultado['failed'][0]['error']);
    }

    public function test_persona_ya_con_ambas_cuentas_se_informa_como_exito_sin_llamar_a_las_plataformas(): void
    {
        Persona::factory()->alumno()->create([
            'cedula' => '9999999',
            'nombre_completo' => 'Ya Existente',
            'canvas_user_id' => 'c-1',
            'azure_user_id' => 'a-1',
        ]);

        $this->escribirXlsx([
            ['Nombre Completo', 'Cédula'],
            ['Ya Existente', '9999999'],
        ]);

        $resultado = $this->importer->importar($this->archivoTemporal);

        $this->assertCount(1, $resultado['succeeded']);
        $this->assertSame('ya_tenia_cuentas', $resultado['succeeded'][0]['resultado']);
    }

    public function test_encabezado_ci_no_colisiona_con_columnas_no_relacionadas(): void
    {
        // Mismo caso real de producción que ExcelHeaderMatcherTest, pero de
        // punta a punta: una columna "Servicio" antes de la columna real de
        // "CI" no debe corromper la cédula usada para generar la cuenta.
        $this->escribirXlsx([
            ['Servicio', 'Nombre Completo', 'CI'],
            ['Matrícula', 'Karen Gonzalez', '6868066'],
        ]);

        $resultado = $this->importer->importar($this->archivoTemporal);

        $this->assertCount(1, $resultado['succeeded']);
        $this->assertSame('6868066', $resultado['succeeded'][0]['cedula']);
    }
}
