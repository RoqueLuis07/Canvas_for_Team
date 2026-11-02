<?php

namespace App\Filament\Resources\ActaServicioTecnicoResource\Pages;

use App\Exceptions\ActaException;
use App\Filament\Resources\ActaServicioTecnicoResource;
use App\Services\Actas\ActaServicioTecnicoService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateActaServicioTecnico extends CreateRecord
{
    protected static string $resource = ActaServicioTecnicoResource::class;

    /**
     * El alta real (número + generación del PDF) pasa por
     * ActaServicioTecnicoService::crear(), no por un insert directo —
     * mismo criterio ya usado en InscripcionMateriaResource/CreateInscripcionMateria.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ActaServicioTecnicoService::class)->crear($data, auth()->user()?->email);
        } catch (ActaException $e) {
            Notification::make()
                ->title('No se pudo generar el acta')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}
