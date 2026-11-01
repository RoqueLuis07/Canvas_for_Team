<?php

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\Materia;
use App\Models\PeriodoAcademico;
use App\Services\Excel\CursoImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class CursoImporterTest extends TestCase
{
    use RefreshDatabase;

    private CursoImporter $importer;

    private string $archivoTemporal;

    protected function setUp(): void
    {
        parent::setUp();

        // app() resuelve CanvasClient/TeamsClient a sus implementaciones
        // Null (sin credenciales configuradas en el entorno de test) — mismo
        // patrón que CursoServiceTest, no hace falta un test double propio.
        $this->importer = app(CursoImporter::class);
        $this->archivoTemporal = sys_get_temp_dir().'/curso_importer_test_'.uniqid().'.xlsx';
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

    public function test_crea_curso_en_canvas_y_teams_para_materia_y_periodo_existentes(): void
    {
        $materia = Materia::factory()->create(['codigo' => 'MAT-I-2026']);
        $periodo = PeriodoAcademico::factory()->create(['nombre' => '2026-1']);

        $this->escribirXlsx([
            ['Código de Materia', 'Período Académico'],
            ['MAT-I-2026', '2026-1'],
        ]);

        $resultado = $this->importer->importar($this->archivoTemporal);

        $this->assertCount(1, $resultado['succeeded']);
        $this->assertCount(0, $resultado['failed']);
        $this->assertNotNull($resultado['succeeded'][0]['canvas_course_id']);
        $this->assertNotNull($resultado['succeeded'][0]['teams_group_id']);

        $this->assertDatabaseHas('cursos', [
            'materia_id' => $materia->id,
            'periodo_academico_id' => $periodo->id,
        ]);
    }

    public function test_falla_la_fila_si_el_codigo_de_materia_no_existe_sin_crearlo_automaticamente(): void
    {
        PeriodoAcademico::factory()->create(['nombre' => '2026-1']);

        $this->escribirXlsx([
            ['Código de Materia', 'Período Académico'],
            ['NO-EXISTE', '2026-1'],
        ]);

        $resultado = $this->importer->importar($this->archivoTemporal);

        $this->assertCount(0, $resultado['succeeded']);
        $this->assertCount(1, $resultado['failed']);
        $this->assertStringContainsString('No existe una Materia', $resultado['failed'][0]['error']);
        $this->assertDatabaseMissing('materias', ['codigo' => 'NO-EXISTE']);
    }

    public function test_falla_la_fila_si_el_periodo_no_existe_sin_crearlo_automaticamente(): void
    {
        Materia::factory()->create(['codigo' => 'MAT-I-2026']);

        $this->escribirXlsx([
            ['Código de Materia', 'Período Académico'],
            ['MAT-I-2026', '2099-9'],
        ]);

        $resultado = $this->importer->importar($this->archivoTemporal);

        $this->assertCount(0, $resultado['succeeded']);
        $this->assertCount(1, $resultado['failed']);
        $this->assertStringContainsString('No existe un Período Académico', $resultado['failed'][0]['error']);
    }

    public function test_reutiliza_el_curso_si_ya_fue_creado_en_canvas_y_teams(): void
    {
        $materia = Materia::factory()->create(['codigo' => 'MAT-I-2026']);
        $periodo = PeriodoAcademico::factory()->create(['nombre' => '2026-1']);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create([
            'materia_id' => $materia->id,
            'periodo_academico_id' => $periodo->id,
        ]);

        $this->escribirXlsx([
            ['Código de Materia', 'Período Académico'],
            ['MAT-I-2026', '2026-1'],
        ]);

        $resultado = $this->importer->importar($this->archivoTemporal);

        $this->assertCount(1, $resultado['succeeded']);
        $this->assertSame($curso->canvas_course_id, $resultado['succeeded'][0]['canvas_course_id']);
        $this->assertSame($curso->teams_group_id, $resultado['succeeded'][0]['teams_group_id']);
        $this->assertDatabaseCount('cursos', 1);
    }
}
