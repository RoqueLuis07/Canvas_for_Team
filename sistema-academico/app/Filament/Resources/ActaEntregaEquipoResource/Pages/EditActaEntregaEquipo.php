<?php

namespace App\Filament\Resources\ActaEntregaEquipoResource\Pages;

use App\Exceptions\ActaException;
use App\Filament\Resources\ActaEntregaEquipoResource;
use App\Models\ActaEntregaEquipo;
use App\Services\Actas\ActaEntregaEquipoService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditActaEntregaEquipo extends EditRecord
{
    protected static string $resource = ActaEntregaEquipoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            /** @var ActaEntregaEquipo $record */
            return app(ActaEntregaEquipoService::class)->actualizar($record, $data);
        } catch (ActaException $e) {
            Notification::make()
                ->title('No se pudo regenerar el acta')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }
}
