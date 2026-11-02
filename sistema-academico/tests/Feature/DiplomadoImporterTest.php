<?php

namespace Tests\Feature;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Services\CredentialGenerator;
use App\Services\Excel\DiplomadoImporter;
use App\Services\Excel\ExcelHeaderMatcher;
use App\Services\IngresoService;
use App\Services\SincronizacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class DiplomadoImporterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fabrica un .xlsx en memoria con el formato típico de una planilla de
     * Diplomado (título en la fila 1, encabezados en la fila 2) y devuelve
     * sus bytes crudos — así un TeamsClient de prueba puede servirlos desde
     * downloadSharedFile() sin tocar disco fuera de un archivo temporal
     * descartable.
     *
     * @param  list<list<string>>  $filasDeDatos
     */
    private function bytesXlsx(string $titulo, array $filasDeDatos, array $encabezados = ['Nombre', 'Cédula', 'Estado', 'Usuario']): string
    {
        $ruta = sys_get_temp_dir().'/diplomado_fixture_'.uniqid().'.xlsx';

        $writer = new Writer();
        $writer->openToFile($ruta);
        $writer->addRow(Row::fromValues([$titulo]));
        $writer->addRow(Row::fromValues($encabezados));

        foreach ($filasDeDatos as $fila) {
            $writer->addRow(Row::fromValues($fila));
        }

        $writer->close();

        $bytes = file_get_contents($ruta);
        unlink($ruta);

        return $bytes === false ? '' : $bytes;
    }

    private function canvasVacio(): CanvasClient
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
                return 'canvas-x';
            }

            public function deleteUser(string $canvasUserId): void {}
        };
    }

    /**
     * TeamsClient de prueba totalmente controlable: sirve un .xlsx fijo
     * desde downloadSharedFile(), y registra cada llamada relevante
     * (createTeam/addMember/findGroupByName) para poder verificar
     * exactamente qué resolvió el importador.
     */
    private function teamsDePrueba(string $xlsxBytes, ?string $grupoExistente = null, array $upnsResolvibles = []): TeamsClient
    {
        return new class($xlsxBytes, $grupoExistente, $upnsResolvibles) implements TeamsClient
        {
            public array $equiposCreados = [];

            public array $miembrosAgregados = [];

            public function __construct(
                public string $xlsxBytes,
                public ?string $grupoExistente,
                public array $upnsResolvibles,
            ) {}

            public function createTeam(string $name): string
            {
                $id = 'team-'.(count($this->equiposCreados) + 1);
                $this->equiposCreados[] = ['name' => $name, 'id' => $id];

                return $id;
            }

            public function addMember(string $teamsGroupId, string $azureUserId, string $role = 'Member'): void
            {
                $this->miembrosAgregados[] = ['team' => $teamsGroupId, 'user' => $azureUserId, 'role' => $role];
            }

            public function removeMember(string $teamsGroupId, string $azureUserId): void {}

            public function findUserByUpn(string $upn): ?array
            {
                if (! isset($this->upnsResolvibles[$upn])) {
                    return null;
                }

                return ['id' => $this->upnsResolvibles[$upn], 'displayName' => $upn, 'userPrincipalName' => $upn, 'accountEnabled' => true];
            }

            public function createUser(array $datos): string
            {
                return 'azure-nuevo';
            }

            public function disableUser(string $azureUserId): void {}

            public function removeAllLicenses(string $azureUserId): array
            {
                return [];
            }

            public function downloadSharedFile(string $shareUrl): string
            {
                return $this->xlsxBytes;
            }

            public function findGroupByName(string $name): ?string
            {
                return $this->grupoExistente;
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

    private function nuevoIngreso(CanvasClient $canvas, TeamsClient $teams): IngresoService
    {
        return new IngresoService($canvas, $teams, new CredentialGenerator(), app(SincronizacionService::class));
    }

    public function test_previsualizar_cuenta_pendientes_y_procesados_sin_crear_nada(): void
    {
        $bytes = $this->bytesXlsx('Diplomado en SQL Avanzado', [
            ['Juan Perez', '1111111', '', ''],
            ['Maria Gonzalez', '2222222', '✅ Creado', 'maria.gonzalez@usil.edu.py'],
        ]);

        $teams = $this->teamsDePrueba($bytes);
        $importer = new DiplomadoImporter($teams, $this->nuevoIngreso($this->canvasVacio(), $teams), new ExcelHeaderMatcher());

        $resultado = $importer->previsualizar('https://onedrive.example/x', 'Hoja1');

        $this->assertSame('Diplomado en SQL Avanzado', $resultado['titulo']);
        $this->assertSame(1, $resultado['students_to_process']);
        $this->assertSame(1, $resultado['students_already_processed']);
        $this->assertEmpty($teams->equiposCreados, 'previsualizar no debe crear ningún equipo');
    }

    public function test_importar_crea_equipo_nuevo_con_propietarios_por_defecto(): void
    {
        $bytes = $this->bytesXlsx('Diplomado en Finanzas', [
            ['Juan Perez', '1111111', '', ''],
        ]);

        $upns = [
            'ldure@usil.edu.py' => 'id-ldure',
            'jfleitas@usil.edu.py' => 'id-jfleitas',
            'ralvarez@usil.edu.py' => 'id-ralvarez',
        ];
        $teams = $this->teamsDePrueba($bytes, grupoExistente: null, upnsResolvibles: $upns);
        $importer = new DiplomadoImporter($teams, $this->nuevoIngreso($this->canvasVacio(), $teams), new ExcelHeaderMatcher());

        $resultado = $importer->importar('https://onedrive.example/x', 'Hoja1');

        $this->assertCount(1, $resultado['succeeded']);
        $this->assertCount(1, $teams->equiposCreados, 'debe crear el equipo porque no existía');

        // 3 propietarios agregados + 1 alumno como member = 4 altas de membresía.
        $owners = array_filter($teams->miembrosAgregados, fn ($m) => $m['role'] === 'Owner');
        $members = array_filter($teams->miembrosAgregados, fn ($m) => $m['role'] === 'Member');
        $this->assertCount(3, $owners, 'deben agregarse los 3 propietarios por defecto');
        $this->assertCount(1, $members, 'el alumno debe agregarse como Member');
    }

    public function test_importar_suma_propietario_extra_cuando_la_pestana_es_mba(): void
    {
        $bytes = $this->bytesXlsx('MBA Ejecutivo 2026', [
            ['Juan Perez', '1111111', '', ''],
        ]);

        $upns = [
            'ldure@usil.edu.py' => 'id-1',
            'jfleitas@usil.edu.py' => 'id-2',
            'ralvarez@usil.edu.py' => 'id-3',
            'amichelena@usil.edu.py' => 'id-4',
        ];
        $teams = $this->teamsDePrueba($bytes, grupoExistente: null, upnsResolvibles: $upns);
        $importer = new DiplomadoImporter($teams, $this->nuevoIngreso($this->canvasVacio(), $teams), new ExcelHeaderMatcher());

        $importer->importar('https://onedrive.example/x', 'MBA Hoja');

        $owners = array_filter($teams->miembrosAgregados, fn ($m) => $m['role'] === 'Owner');
        $this->assertCount(4, $owners, 'MBA debe sumar el propietario extra a los 3 por defecto');
    }

    public function test_importar_reutiliza_equipo_existente_sin_volver_a_agregar_propietarios(): void
    {
        $bytes = $this->bytesXlsx('Diplomado Ya Existente', [
            ['Juan Perez', '1111111', '', ''],
        ]);

        $teams = $this->teamsDePrueba($bytes, grupoExistente: 'team-ya-existe');
        $importer = new DiplomadoImporter($teams, $this->nuevoIngreso($this->canvasVacio(), $teams), new ExcelHeaderMatcher());

        $resultado = $importer->importar('https://onedrive.example/x', 'Hoja1');

        $this->assertEmpty($teams->equiposCreados, 'no debe crear un equipo nuevo si ya existe uno con ese nombre');
        $owners = array_filter($teams->miembrosAgregados, fn ($m) => $m['role'] === 'Owner');
        $this->assertCount(0, $owners, 'un equipo reutilizado no debe recibir altas de propietario de nuevo');
        $this->assertCount(1, $resultado['succeeded']);
    }

    public function test_propietario_no_resoluble_no_aborta_la_creacion_del_equipo(): void
    {
        $bytes = $this->bytesXlsx('Diplomado X', [
            ['Juan Perez', '1111111', '', ''],
        ]);

        // Solo 1 de los 3 propietarios por defecto se puede resolver en Azure AD.
        $upns = ['ldure@usil.edu.py' => 'id-ldure'];
        $teams = $this->teamsDePrueba($bytes, grupoExistente: null, upnsResolvibles: $upns);
        $importer = new DiplomadoImporter($teams, $this->nuevoIngreso($this->canvasVacio(), $teams), new ExcelHeaderMatcher());

        $resultado = $importer->importar('https://onedrive.example/x', 'Hoja1');

        $this->assertCount(1, $teams->equiposCreados, 'el equipo se crea igual aunque falten propietarios por resolver');
        $owners = array_filter($teams->miembrosAgregados, fn ($m) => $m['role'] === 'Owner');
        $this->assertCount(1, $owners, 'solo se agrega el propietario que sí se pudo resolver');
        $this->assertCount(1, $resultado['succeeded'], 'el alumno igual se procesa correctamente');
    }

    public function test_importar_crea_cuenta_solo_en_teams_no_en_canvas(): void
    {
        $bytes = $this->bytesXlsx('Diplomado Solo Teams', [
            ['Juan Perez', '1111111', '', ''],
        ]);

        $canvas = $this->canvasVacio();
        $teams = $this->teamsDePrueba($bytes, grupoExistente: 'team-x');
        $importer = new DiplomadoImporter($teams, $this->nuevoIngreso($canvas, $teams), new ExcelHeaderMatcher());

        $resultado = $importer->importar('https://onedrive.example/x', 'Hoja1');

        $this->assertCount(1, $resultado['succeeded']);
        $this->assertNotNull($resultado['succeeded'][0]['azure_user_id']);
        // IngresoService::darDeAlta con plataforma 'teams' nunca llama a canvas->createUser();
        // si lo hubiera llamado, canvas_user_id de la Persona creada quedaría seteado.
        $persona = \App\Models\Persona::where('cedula', '1111111')->first();
        $this->assertNull($persona->canvas_user_id, 'Diplomados no debe crear cuenta de Canvas');
    }

    public function test_importar_omite_filas_ya_procesadas(): void
    {
        $bytes = $this->bytesXlsx('Diplomado Mixto', [
            ['Juan Perez', '1111111', '', ''],
            ['Maria Gonzalez', '2222222', 'enviado', ''],
        ]);

        $teams = $this->teamsDePrueba($bytes, grupoExistente: 'team-x');
        $importer = new DiplomadoImporter($teams, $this->nuevoIngreso($this->canvasVacio(), $teams), new ExcelHeaderMatcher());

        $resultado = $importer->importar('https://onedrive.example/x', 'Hoja1');

        $this->assertCount(1, $resultado['succeeded'], 'solo debe procesar la fila pendiente, no la ya marcada "enviado"');
        $this->assertSame('Juan Perez', $resultado['succeeded'][0]['nombre_completo']);
    }
}
