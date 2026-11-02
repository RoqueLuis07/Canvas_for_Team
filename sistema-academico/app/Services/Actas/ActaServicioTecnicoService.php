<?php

namespace App\Services\Actas;

use App\Exceptions\ActaException;
use App\Models\ActaServicioTecnico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Settings as PhpWordSettings;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Alta, edición y generación de PDF de Actas de Servicio Técnico —
 * equivalente a /actas/servicio-tecnico en el sistema FastAPI de
 * producción (ver Backend/app/routers/actas.py y
 * Backend/app/services/docx_actas.py), usando una plantilla .docx con
 * macros ${...} (phpoffice/phpword) en vez de la edición por posición de
 * celda/párrafo que usa producción — más simple y mantenible en PHPWord.
 */
class ActaServicioTecnicoService
{
    protected const CAMPOS = [
        'fecha', 'hora', 'departamento_area', 'ubicacion_oficina', 'persona_reporta',
        'tipo_equipo', 'tipo_equipo_otro', 'marca', 'modelo', 'nro_serie',
        'falla_motivo', 'trabajo_realizado', 'repuestos_insumos', 'tecnico_responsable',
        'encargado_ti_nombre', 'encargado_ti_ci', 'usuario_nombre', 'usuario_ci',
    ];

    protected const TEMPLATE = 'acta_servicio_tecnico.docx';

    protected const DISCO = 'local';

    protected const CARPETA_PDF = 'actas/servicio-tecnico';

    public function __construct(protected DocxToPdfConverter $conversor) {}

    /**
     * Genera el PDF con los datos dados, SIN asignar número ni persistir
     * nada — equivalente a /servicio-tecnico/preview en producción.
     *
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
    public function crear(array $datos, ?string $creadoPor = null): ActaServicioTecnico
    {
        return DB::transaction(function () use ($datos, $creadoPor) {
            [$numero, $secuencia, $anio] = $this->siguienteNumero();

            $pdfBytes = $this->generarPdf([...$datos, 'numero' => $numero]);
            $rutaPdf = $this->guardarPdf($pdfBytes, $numero);

            return ActaServicioTecnico::create([
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
     * Regenera el PDF manteniendo el número ya asignado — equivalente al
     * PUT de producción.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ActaException
     */
    public function actualizar(ActaServicioTecnico $acta, array $datos): ActaServicioTecnico
    {
        $pdfBytes = $this->generarPdf([...$datos, 'numero' => $acta->numero]);
        $rutaPdf = $this->guardarPdf($pdfBytes, $acta->numero);

        $acta->update([
            ...$this->soloCamposValidos($datos),
            'pdf_generado_path' => $rutaPdf,
        ]);

        return $acta->refresh();
    }

    public function subirFirmado(ActaServicioTecnico $acta, string $contenidoPdf): ActaServicioTecnico
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
            throw new ActaException("No se encontró la plantilla de Acta de Servicio Técnico en {$templatePath}.");
        }

        // PHPWord NO escapa los valores de setValue() para XML por
        // defecto — un "&"/"<"/">" en texto libre (ej. falla_motivo)
        // rompería el XML del .docx resultante. Hay que habilitarlo
        // explícitamente (no es un ajuste por instancia, es un flag
        // estático de la librería).
        PhpWordSettings::setOutputEscapingEnabled(true);

        $tp = new TemplateProcessor($templatePath);

        foreach ([...self::CAMPOS, 'numero'] as $campo) {
            $tp->setValue($campo, (string) ($datos[$campo] ?? ''));
        }

        $tmpDocx = tempnam(sys_get_temp_dir(), 'acta_st_').'.docx';
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
     * Numeración secuencial por año: "0001-2026", continúa desde el máximo
     * ya asignado ese año (no se reutilizan números de actas eliminadas).
     *
     * @return array{0: string, 1: int, 2: int}
     */
    protected function siguienteNumero(): array
    {
        $anio = (int) now()->format('Y');

        $ultima = ActaServicioTecnico::where('anio', $anio)->max('numero_secuencia') ?? 0;
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
