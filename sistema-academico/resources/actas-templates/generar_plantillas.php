<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;

function buildBase(string $titulo): array {
    $phpWord = new PhpWord();
    $phpWord->setDefaultFontName('Arial');
    $phpWord->setDefaultFontSize(10);

    $section = $phpWord->addSection(['marginLeft' => 900, 'marginRight' => 900]);

    $section->addText('UNIVERSIDAD SAN IGNACIO DE LOYOLA', ['bold' => true, 'size' => 12], ['alignment' => Jc::CENTER]);
    $section->addText('Dirección de Tecnología de la Información', ['size' => 9, 'color' => '666666'], ['alignment' => Jc::CENTER]);
    $section->addTextBreak(1);
    $section->addText($titulo, ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER]);
    $section->addText('N.° ${numero}', ['bold' => true, 'size' => 11], ['alignment' => Jc::CENTER]);
    $section->addTextBreak(1);

    return [$phpWord, $section];
}

function addField(\PhpOffice\PhpWord\Element\Section $section, string $label, string $macro, ?array $table = null): void {
    $t = $table;
    if ($t === null) {
        $section->addText("{$label}: \${$macro}", ['size' => 10]);
        return;
    }
}

function addRow($table, string $label, string $macro, int $colspan = 1): void {
    $table->addRow();
    $cell1 = $table->addCell(2800);
    $cell1->addText($label, ['bold' => true, 'size' => 9]);
    $cell2 = $table->addCell(6200, ['gridSpan' => $colspan]);
    $cell2->addText('${' . $macro . '}', ['size' => 9]);
}

function tableStyle($phpWord, string $name) {
    $phpWord->addTableStyle($name, [
        'borderSize' => 6,
        'borderColor' => '999999',
        'cellMargin' => 80,
    ]);
    return $name;
}

function signatureBlock($section): void {
    $section->addTextBreak(2);
    $table = $section->addTable(['borderSize' => 0, 'cellMargin' => 80]);
    $table->addRow();
    $c1 = $table->addCell(4500);
    $c1->addText('_______________________________', ['size' => 10]);
    $c1->addText('Firma', ['size' => 9]);
    $c2 = $table->addCell(4500);
    $c2->addText('_______________________________', ['size' => 10]);
    $c2->addText('Firma', ['size' => 9]);
}

// ── Acta de Servicio Técnico ──────────────────────────────────────────────
[$phpWord, $section] = buildBase('ACTA DE SERVICIO TÉCNICO');

$styleName = tableStyle($phpWord, 'TablaServicioTecnico');
$table = $section->addTable($styleName);
addRow($table, 'Fecha', 'fecha');
addRow($table, 'Hora', 'hora');
addRow($table, 'Departamento/Área', 'departamento_area');
addRow($table, 'Ubicación/Oficina', 'ubicacion_oficina');
addRow($table, 'Persona que reporta', 'persona_reporta');
addRow($table, 'Tipo de equipo', 'tipo_equipo');
addRow($table, 'Tipo de equipo (otro)', 'tipo_equipo_otro');
addRow($table, 'Marca', 'marca');
addRow($table, 'Modelo', 'modelo');
addRow($table, 'N.° de serie', 'nro_serie');

$section->addTextBreak(1);
$section->addText('Falla / Motivo', ['bold' => true, 'size' => 10]);
$section->addText('${falla_motivo}', ['size' => 9]);
$section->addTextBreak(1);
$section->addText('Trabajo realizado', ['bold' => true, 'size' => 10]);
$section->addText('${trabajo_realizado}', ['size' => 9]);
$section->addTextBreak(1);
$section->addText('Repuestos / Insumos utilizados', ['bold' => true, 'size' => 10]);
$section->addText('${repuestos_insumos}', ['size' => 9]);
$section->addTextBreak(1);

$table2 = $section->addTable($styleName);
addRow($table2, 'Técnico responsable', 'tecnico_responsable');
addRow($table2, 'Encargado TI', 'encargado_ti_nombre');
addRow($table2, 'C.I. Encargado TI', 'encargado_ti_ci');
addRow($table2, 'Usuario/Solicitante', 'usuario_nombre');
addRow($table2, 'C.I. Usuario/Solicitante', 'usuario_ci');

signatureBlock($section);

$phpWord->save(__DIR__ . '/acta_servicio_tecnico.docx', 'Word2007');
echo "OK: acta_servicio_tecnico.docx\n";

// ── Acta de Entrega de Equipo ────────────────────────────────────────────
[$phpWord2, $section2] = buildBase('ACTA DE ENTREGA DE EQUIPO');

$styleName2 = tableStyle($phpWord2, 'TablaEntregaEquipo');
$table3 = $section2->addTable($styleName2);
addRow($table3, 'Fecha de emisión', 'fecha_emision');
addRow($table3, 'Entrega (nombre)', 'entrega_nombre');
addRow($table3, 'Entrega (C.I.)', 'entrega_ci');
addRow($table3, 'Entrega (cargo)', 'entrega_cargo');
addRow($table3, 'Recibe (nombre)', 'recibe_nombre');
addRow($table3, 'Recibe (C.I.)', 'recibe_ci');
addRow($table3, 'Recibe (cargo)', 'recibe_cargo');
addRow($table3, 'Insumo/Equipo', 'insumo');
addRow($table3, 'Marca', 'marca');
addRow($table3, 'Modelo', 'modelo');
addRow($table3, 'N.° de serie', 'nro_serie');

$section2->addTextBreak(1);
$section2->addText('Especificaciones', ['bold' => true, 'size' => 10]);
$section2->addText('${especificaciones}', ['size' => 9]);
$section2->addTextBreak(1);
$section2->addText('Accesorios entregados', ['bold' => true, 'size' => 10]);
$section2->addText('${accesorios}', ['size' => 9]);
$section2->addText('Otros accesorios: ${accesorios_otros}', ['size' => 9]);
$section2->addTextBreak(1);
$section2->addText('Estado del equipo (entrega)', ['bold' => true, 'size' => 10]);
$section2->addText('${estado_equipo}', ['size' => 9]);
$section2->addText('Observaciones de entrega', ['bold' => true, 'size' => 10]);
$section2->addText('${observaciones_entrega}', ['size' => 9]);

$section2->addTextBreak(1);
$section2->addText('DEVOLUCIÓN (si aplica)', ['bold' => true, 'size' => 11, 'underline' => 'single']);
$table4 = $section2->addTable($styleName2);
addRow($table4, 'Fecha de devolución', 'fecha_devolucion');
addRow($table4, 'Motivo de devolución', 'motivo_devolucion');
addRow($table4, 'Estado del equipo (devolución)', 'estado_equipo_devolucion');
$section2->addText('Observaciones de devolución', ['bold' => true, 'size' => 10]);
$section2->addText('${observaciones_devolucion}', ['size' => 9]);

signatureBlock($section2);

$phpWord2->save(__DIR__ . '/acta_entrega_equipo.docx', 'Word2007');
echo "OK: acta_entrega_equipo.docx\n";
