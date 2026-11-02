<?php

namespace App\Services\Actas;

use App\Exceptions\ActaException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Convierte un .docx a PDF invocando LibreOffice en modo headless — mismo
 * enfoque que el sistema FastAPI en producción (ver
 * Backend/app/services/docx_to_pdf.py): es la única forma de producir un
 * PDF que sea *literalmente* el documento generado (mismas fuentes,
 * espaciados, layout) en vez de una reconstrucción aproximada vía HTML.
 *
 * Requiere el binario `soffice`/`libreoffice` disponible en el servidor —
 * ver Dockerfile (raíz y docker/php/), que lo instala explícitamente
 * (libreoffice-writer + fonts-dejavu, igual que producción).
 */
class DocxToPdfConverter
{
    protected const TIMEOUT_SEGUNDOS = 60;

    /**
     * @throws ActaException
     */
    public function convertir(string $docxPath): string
    {
        $binario = $this->resolverBinario();

        $tmpDir = sys_get_temp_dir().'/acta_'.Str::random(12);
        $profileDir = $tmpDir.'/profile';
        mkdir($tmpDir, 0775, true);
        mkdir($profileDir, 0775, true);

        try {
            // Perfil de usuario aislado por conversión: dos invocaciones de
            // soffice en simultáneo comparten por defecto el mismo perfil y
            // chocan con un lock ("no se puede conectar"), rompiendo la
            // segunda conversión concurrente — mismo motivo documentado en
            // producción.
            $resultado = Process::timeout(self::TIMEOUT_SEGUNDOS)->run([
                $binario,
                '--headless',
                '--norestore',
                '-env:UserInstallation=file://'.$profileDir,
                '--convert-to', 'pdf',
                '--outdir', $tmpDir,
                $docxPath,
            ]);

            if ($resultado->failed()) {
                throw new ActaException(
                    'No se pudo convertir el documento a PDF. Detalle: '.$resultado->errorOutput()
                );
            }

            $pdfPath = $tmpDir.'/'.pathinfo($docxPath, PATHINFO_FILENAME).'.pdf';

            if (! file_exists($pdfPath)) {
                throw new ActaException('LibreOffice no generó el archivo PDF esperado.');
            }

            return file_get_contents($pdfPath);
        } finally {
            $this->limpiar($tmpDir);
        }
    }

    /**
     * @throws ActaException
     */
    protected function resolverBinario(): string
    {
        foreach (['soffice', 'libreoffice'] as $candidato) {
            $resultado = Process::run(['which', $candidato]);

            if ($resultado->successful() && trim($resultado->output()) !== '') {
                return trim($resultado->output());
            }
        }

        throw new ActaException(
            'No se pudo generar el PDF: LibreOffice no está instalado en este servidor '.
            '(necesario para producir el documento exacto a la plantilla). '.
            'Verificá el despliegue — ver Dockerfile.'
        );
    }

    protected function limpiar(string $tmpDir): void
    {
        if (! is_dir($tmpDir)) {
            return;
        }

        $archivos = array_diff(scandir($tmpDir) ?: [], ['.', '..']);

        foreach ($archivos as $archivo) {
            $ruta = "{$tmpDir}/{$archivo}";

            if (is_dir($ruta)) {
                $this->limpiar($ruta);
            } else {
                @unlink($ruta);
            }
        }

        @rmdir($tmpDir);
    }
}
