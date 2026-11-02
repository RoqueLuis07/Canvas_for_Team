<?php

namespace Tests\Feature;

use App\Models\ActaServicioTecnico;
use App\Services\Actas\ActaServicioTecnicoService;
use App\Services\Actas\DocxToPdfConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ActaServicioTecnicoServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActaServicioTecnicoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // El conversor real depende de que `soffice` esté instalado en el
        // servidor (ver Dockerfile) — acá se reemplaza por un doble que
        // devuelve bytes fijos, para poder probar la numeración y la
        // persistencia sin depender de LibreOffice.
        $conversor = Mockery::mock(DocxToPdfConverter::class);
        $conversor->shouldReceive('convertir')->andReturn('%PDF-1.4 contenido falso');
        $this->app->instance(DocxToPdfConverter::class, $conversor);

        $this->service = app(ActaServicioTecnicoService::class);
    }

    private function datosBase(): array
    {
        return [
            'fecha' => '2026-11-02',
            'hora' => '14:30',
            'departamento_area' => 'TI',
            'tipo_equipo' => 'Notebook',
            'marca' => 'Dell',
            'falla_motivo' => 'No enciende',
            'trabajo_realizado' => 'Cambio de fuente',
            'tecnico_responsable' => 'Carlos Gómez',
        ];
    }

    public function test_crear_asigna_numero_secuencial_del_anio_actual(): void
    {
        $acta = $this->service->crear($this->datosBase(), 'admin@usil.edu.py');

        $anioActual = (int) now()->format('Y');

        $this->assertSame(1, $acta->numero_secuencia);
        $this->assertSame($anioActual, $acta->anio);
        $this->assertSame('0001-'.$anioActual, $acta->numero);
        $this->assertSame('admin@usil.edu.py', $acta->created_by);
        $this->assertSame('generado', $acta->estado);
        $this->assertNotNull($acta->pdf_generado_path);
        Storage::disk('local')->assertExists($acta->pdf_generado_path);
    }

    public function test_crear_incrementa_la_secuencia_en_corridas_sucesivas(): void
    {
        $primera = $this->service->crear($this->datosBase());
        $segunda = $this->service->crear($this->datosBase());

        $this->assertSame($primera->numero_secuencia + 1, $segunda->numero_secuencia);
        $this->assertNotSame($primera->numero, $segunda->numero);
    }

    public function test_previsualizar_no_persiste_nada(): void
    {
        $pdf = $this->service->previsualizar($this->datosBase());

        $this->assertNotEmpty($pdf);
        $this->assertSame(0, ActaServicioTecnico::count());
    }

    public function test_actualizar_mantiene_el_numero_y_regenera_el_pdf(): void
    {
        $acta = $this->service->crear($this->datosBase());
        $rutaOriginal = $acta->pdf_generado_path;

        $actualizada = $this->service->actualizar($acta, [
            ...$this->datosBase(),
            'trabajo_realizado' => 'Cambio de fuente y limpieza interna',
        ]);

        $this->assertSame($acta->numero, $actualizada->numero);
        $this->assertSame('Cambio de fuente y limpieza interna', $actualizada->trabajo_realizado);
        $this->assertSame($rutaOriginal, $actualizada->pdf_generado_path);
    }

    public function test_subir_firmado_guarda_el_pdf_y_cambia_el_estado(): void
    {
        $acta = $this->service->crear($this->datosBase());

        $actualizada = $this->service->subirFirmado($acta, '%PDF-1.4 firmado de verdad');

        $this->assertSame('firmado', $actualizada->estado);
        $this->assertNotNull($actualizada->pdf_firmado_path);
        Storage::disk('local')->assertExists($actualizada->pdf_firmado_path);
    }
}
