<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Acta de Servicio Técnico — registro administrativo de una intervención de
 * soporte TI (equivalente a /actas/servicio-tecnico en el sistema FastAPI
 * de producción). El PDF generado y, si lo hay, el firmado se guardan como
 * archivos en disco (Storage) y acá solo se persiste la ruta — a diferencia
 * de producción, que guarda el PDF como blob en la base de datos; guardar
 * en disco es más idiomático en Laravel y evita filas enormes en la tabla.
 */
class ActaServicioTecnico extends Model
{
    protected $table = 'actas_servicio_tecnico';

    protected $fillable = [
        'numero',
        'numero_secuencia',
        'anio',
        'fecha',
        'hora',
        'departamento_area',
        'ubicacion_oficina',
        'persona_reporta',
        'tipo_equipo',
        'tipo_equipo_otro',
        'marca',
        'modelo',
        'nro_serie',
        'falla_motivo',
        'trabajo_realizado',
        'repuestos_insumos',
        'tecnico_responsable',
        'encargado_ti_nombre',
        'encargado_ti_ci',
        'usuario_nombre',
        'usuario_ci',
        'pdf_generado_path',
        'pdf_firmado_path',
        'estado',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
        ];
    }

    public function tieneFirmado(): bool
    {
        return $this->pdf_firmado_path !== null;
    }
}
