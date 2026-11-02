<?php

namespace App\Filament\Resources\ActaEntregaEquipoResource\Pages;

use App\Exceptions\ActaException;
use App\Filament\Resources\ActaEntregaEquipoResource;
use App\Services\Actas\ActaEntregaEquipoService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateActaEntregaEquipo extends CreateRecord
{
    protected static string $resource = ActaEntregaEquipoResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ActaEntregaEquipoService::class)->crear($data, auth()->user()?->email);
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
