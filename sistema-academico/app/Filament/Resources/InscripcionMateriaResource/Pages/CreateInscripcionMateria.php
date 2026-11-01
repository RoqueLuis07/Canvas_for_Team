<?php

namespace App\Filament\Resources\InscripcionMateriaResource\Pages;

use App\Exceptions\InscripcionMateriaException;
use App\Filament\Resources\InscripcionMateriaResource;
use App\Models\Curso;
use App\Models\Matricula;
use App\Services\InscripcionMateriaService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInscripcionMateria extends CreateRecord
{
    protected static string $resource = InscripcionMateriaResource::class;

    /**
     * El alta real pasa por InscripcionMateriaService::inscribirManual(),
     * que valida cupo/prerrequisitos y da de alta en Canvas/Teams — en vez
     * de un insert directo del formulario (origen/estado/fecha_inscripcion
     * los fija el propio servicio, por eso esos campos se ocultan en el
     * formulario de creación, ver InscripcionMateriaResource::form()).
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(InscripcionMateriaService::class)->inscribirManual(
                Matricula::findOrFail($data['matricula_id']),
                Curso::findOrFail($data['curso_id']),
            );
        } catch (InscripcionMateriaException $e) {
            Notification::make()
                ->title('No se pudo inscribir')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}
