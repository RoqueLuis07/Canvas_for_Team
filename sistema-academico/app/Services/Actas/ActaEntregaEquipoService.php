<?php

namespace App\Services\Actas;

use App\Exceptions\ActaException;
use App\Models\ActaEntregaEquipo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Settings as PhpWordSettings;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Alta, edición y generación de PDF de Actas de Entrega de Equipo —
 * equivalente a /actas/entrega-equipo en producción. Ver
 * ActaServicioTecnicoService para la explicación de las decisiones
 * compartidas (plantilla con macros en vez de edición por posición,
 * PDF guardado en disco en vez de blob en la base de datos).
 */
class ActaEntregaEquipoService
{
    protected const CAMPOS = [
        'fecha_emision', 'entrega_nombre', 'entrega_ci', 'entrega_cargo',
        'recibe_nombre', 'recibe_ci', 'recibe_cargo', 'insumo', 'marca', 'modelo',
        'nro_serie', 'especificaciones', 'accesorios', 'accesorios_otros',
        'estado_equipo', 'observaciones_entrega', 'fecha_devolucion',
        'motivo_devolucion', 'estado_equipo_devolucion', 'observaciones_devolucion',
    ];

    protected const TEMPLATE = 'acta_entrega_equipo.docx';

    protected const DISCO = 'local';

    protected const CARPETA_PDF = 'actas/entrega-equipo';

    public function __construct(protected DocxToPdfConverter $conversor) {}

    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ActaException
     */
    public function previsualizar(array $datos): string
    {
        $datos['numero'] = 'VISTA PREVIA — sin guardar';

        return $this->generarPdf($datos);
    }

    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ActaException
     */
    public function crear(array $datos, ?string $creadoPor = null): ActaEntregaEquipo
    {
        return DB::transaction(function () use ($datos, $creadoPor) {
            [$numero, $secuencia, $anio] = $this->siguienteNumero();

            $pdfBytes = $this->generarPdf([...$datos, 'numero' => $numero]);
            $rutaPdf = $this->guardarPdf($pdfBytes, $numero);

            return ActaEntregaEquipo::create([
                ...$this->soloCamposValidos($datos),
                'numero' => $numero,
                'numero_secuencia' => $secuencia,
                'anio' => $anio,
                'pdf_generado_path' => $rutaPdf,
                'estado' => 'generado',
                'created_by' => $creadoPor,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ActaException
     */
    public function actualizar(ActaEntregaEquipo $acta, array $datos): ActaEntregaEquipo
    {
        $pdfBytes = $this->generarPdf([...$datos, 'numero' => $acta->numero]);
        $rutaPdf = $this->guardarPdf($pdfBytes, $acta->numero);

        $acta->update([
            ...$this->soloCamposValidos($datos),
            'pdf_generado_path' => $rutaPdf,
        ]);

        return $acta->refresh();
    }

    public function subirFirmado(ActaEntregaEquipo $acta, string $contenidoPdf): ActaEntregaEquipo
    {
        $ruta = self::CARPETA_PDF."/{$acta->numero}_firmado.pdf";
        Storage::disk(self::DISCO)->put($ruta, $contenidoPdf);

        $acta->update(['pdf_firmado_path' => $ruta, 'estado' => 'firmado']);

        return $acta->refresh();
    }

    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ActaException
     */
    protected function generarPdf(array $datos): string
    {
        $templatePath = resource_path('actas-templates/'.self::TEMPLATE);

        if (! file_exists($templatePath)) {
            throw new ActaException("No se encontró la plantilla de Acta de Entrega de Equipo en {$templatePath}.");
        }

        PhpWordSettings::setOutputEscapingEnabled(true);

        $tp = new TemplateProcessor($templatePath);

        foreach ([...self::CAMPOS, 'numero'] as $campo) {
            $valor = $datos[$campo] ?? '';

            // "accesorios" es un array en el modelo (columna JSON) — para
            // el macro de texto libre de la plantilla se muestra como
            // lista separada por comas.
            if ($campo === 'accesorios' && is_array($valor)) {
                $valor = implode(', ', $valor);
            }

            $tp->setValue($campo, (string) $valor);
        }

        $tmpDocx = tempnam(sys_get_temp_dir(), 'acta_ee_').'.docx';
        $tp->saveAs($tmpDocx);

        try {
            return $this->conversor->convertir($tmpDocx);
        } finally {
            @unlink($tmpDocx);
        }
    }

    protected function guardarPdf(string $pdfBytes, string $numero): string
    {
        $ruta = self::CARPETA_PDF."/{$numero}.pdf";
        Storage::disk(self::DISCO)->put($ruta, $pdfBytes);

        return $ruta;
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    protected function siguienteNumero(): array
    {
        $anio = (int) now()->format('Y');

        $ultima = ActaEntregaEquipo::where('anio', $anio)->max('numero_secuencia') ?? 0;
        $secuencia = $ultima + 1;
        $numero = str_pad((string) $secuencia, 4, '0', STR_PAD_LEFT)."-{$anio}";

        return [$numero, $secuencia, $anio];
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    protected function soloCamposValidos(array $datos): array
    {
        return array_intersect_key($datos, array_flip(self::CAMPOS));
    }
}
