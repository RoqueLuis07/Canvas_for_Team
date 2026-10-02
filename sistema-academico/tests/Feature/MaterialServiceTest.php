<?php

namespace Tests\Feature;

use App\Exceptions\CursoException;
use App\Models\Curso;
use App\Models\Material;
use App\Services\MaterialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialServiceTest extends TestCase
{
    use RefreshDatabase;

    private MaterialService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MaterialService::class);
    }

    public function test_no_deja_publicar_material_de_un_curso_no_creado_en_canvas(): void
    {
        $curso = Curso::factory()->create(); // sin canvas_course_id
        $material = Material::factory()->create(['curso_id' => $curso->id]);

        $this->expectException(CursoException::class);
        $this->expectExceptionMessage('todavía no fue creado en Canvas');

        $this->service->publicarEnCanvas($material);
    }

    public function test_publica_material_en_canvas(): void
    {
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create();
        $material = Material::factory()->create(['curso_id' => $curso->id]);

        $material = $this->service->publicarEnCanvas($material);

        $this->assertTrue($material->publicado);
        $this->assertTrue($material->estaPublicadoEnCanvas());
        $this->assertNotNull($material->canvas_item_id);
    }
}
