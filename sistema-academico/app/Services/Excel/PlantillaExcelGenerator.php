<?php

namespace App\Services\Excel;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Genera planillas .xlsx de ejemplo para descargar — mismo propósito que
 * `_build_template`/`_wb_response` de producción (encabezado en negrita +
 * un par de filas de ejemplo), para que quien vaya a hacer una importación
 * masiva sepa exactamente qué columnas y formato se esperan.
 */
class PlantillaExcelGenerator
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $filasEjemplo
     * @return string  Ruta de un archivo temporal — quien lo use es
     *                  responsable de enviarlo como descarga y borrarlo.
     */
    public function generar(array $headers, array $filasEjemplo): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plantilla_').'.xlsx';

        $estiloEncabezado = (new Style())
            ->setFontBold()
            ->setBackgroundColor('D9D9D9');

        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headers, $estiloEncabezado));

        foreach ($filasEjemplo as $fila) {
            $writer->addRow(Row::fromValues($fila));
        }

        $writer->close();

        return $path;
    }

    /** Columnas que espera PersonaImporter. */
    public function plantillaPersonas(): string
    {
        return $this->generar(
            ['Nombre Completo', 'Cédula', 'Email Personal', 'Tipo', 'Plataforma'],
            [
                ['Karen Gonzalez', '6868066', 'karen.gonzalez@example.com', 'alumno', 'both'],
                ['Juan Perez', '1234567', 'juan.perez@example.com', 'docente', 'both'],
            ],
        );
    }

    /** Columnas que espera CursoImporter. */
    public function plantillaCursos(): string
    {
        return $this->generar(
            ['Código de Materia', 'Período Académico'],
            [
                ['MAT-I-2026', '2026-1'],
                ['COM-ORAL-2026', '2026-1'],
            ],
        );
    }
}
