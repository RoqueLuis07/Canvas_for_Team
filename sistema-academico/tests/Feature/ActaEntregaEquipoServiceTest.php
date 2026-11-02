<?php

namespace Tests\Feature;

use App\Models\ActaEntregaEquipo;
use App\Services\Actas\ActaEntregaEquipoService;
use App\Services\Actas\DocxToPdfConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ActaEntregaEquipoServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActaEntregaEquipoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $conversor = Mockery::mock(DocxToPdfConverter::class);
        $conversor->shouldReceive('convertir')->andReturn('%PDF-1.4 contenido falso');
        $this->app->instance(DocxToPdfConverter::class, $conversor);

        $this->service = app(ActaEntregaEquipoService::class);
    }

    private function datosBase(): array
    {
        return [
            'fecha_emision' => '2026-11-02',
            'entrega_nombre' => 'María López',
            'recibe_nombre' => 'Juan Pérez',
            'insumo' => 'Notebook Dell Latitude',
            'marca' => 'Dell',
            'accesorios' => ['Cargador', 'Mouse'],
        ];
    }

    public function test_crear_asigna_numero_y_persiste_accesorios_como_array(): void
    {
        $acta = $this->service->crear($this->datosBase(), 'admin@usil.edu.py');

        $anioActual = (int) now()->format('Y');

        $this->assertSame('0001-'.$anioActual, $acta->numero);
        $this->assertSame(['Cargador', 'Mouse'], $acta->accesorios);
        Storage::disk('local')->assertExists($acta->pdf_generado_path);
    }

    public function test_crear_y_entrega_equipo_usan_secuencias_independientes_de_servicio_tecnico(): void
    {
        // Las dos tablas son independientes — la secuencia de Entrega de
        // Equipo no se ve afectada por actas de Servicio Técnico (tablas
        // separadas, cada una con su propio numero_secuencia por año).
        $acta = $this->service->crear($this->datosBase());

        $this->assertSame(1, $acta->numero_secuencia);
        $this->assertSame(1, ActaEntregaEquipo::count());
    }

    public function test_previsualizar_no_persiste_nada(): void
    {
        $pdf = $this->service->previsualizar($this->datosBase());

        $this->assertNotEmpty($pdf);
        $this->assertSame(0, ActaEntregaEquipo::count());
    }
}
