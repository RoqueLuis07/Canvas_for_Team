<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Acta de Entrega de Equipo — registro de entrega (y eventual devolución)
 * de un equipo/insumo TI a una persona. Ver nota sobre almacenamiento de
 * PDF en disco (en vez de blob) en ActaServicioTecnico.
 */
class ActaEntregaEquipo extends Model
{
    protected $table = 'actas_entrega_equipo';

    protected $fillable = [
        'numero',
        'numero_secuencia',
        'anio',
        'fecha_emision',
        'entrega_nombre',
        'entrega_ci',
        'entrega_cargo',
        'recibe_nombre',
        'recibe_ci',
        'recibe_cargo',
        'insumo',
        'marca',
        'modelo',
        'nro_serie',
        'especificaciones',
        'accesorios',
        'accesorios_otros',
        'estado_equipo',
        'observaciones_entrega',
        'fecha_devolucion',
        'motivo_devolucion',
        'estado_equipo_devolucion',
        'observaciones_devolucion',
        'pdf_generado_path',
        'pdf_firmado_path',
        'estado',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision' => 'date',
            'fecha_devolucion' => 'date',
            'accesorios' => 'array',
        ];
    }

    public function tieneFirmado(): bool
    {
        return $this->pdf_firmado_path !== null;
    }
}
