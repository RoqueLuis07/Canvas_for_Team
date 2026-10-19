<?php

namespace App\Services;

use App\Contracts\CanvasClient;
use App\Exceptions\CursoException;
use App\Models\Material;

/**
 * Publica el contenido académico (materiales) de un Curso como páginas
 * dentro de su curso de Canvas. El Curso debe estar ya creado en Canvas.
 */
class MaterialService
{
    public function __construct(
        protected CanvasClient $canvas,
    ) {}

    /**
     * @throws CursoException
     */
    public function publicarEnCanvas(Material $material): Material
    {
        $curso = $material->curso;

        if (! $curso->estaCreadoEnCanvas()) {
            throw new CursoException(
                "El curso \"{$curso->materia->nombre}\" todavía no fue creado en Canvas — no se puede publicar contenido en él."
            );
        }

        try {
            $canvasItemId = $this->canvas->createPage(
                $curso->canvas_course_id,
                $material->titulo,
                $material->descripcion ?? '',
            );
        } catch (\Throwable $e) {
            throw new CursoException("No se pudo publicar el material en Canvas: {$e->getMessage()}", previous: $e);
        }

        $material->update([
            'canvas_item_id' => $canvasItemId,
            'publicado' => true,
        ]);

        return $material->refresh();
    }
}
