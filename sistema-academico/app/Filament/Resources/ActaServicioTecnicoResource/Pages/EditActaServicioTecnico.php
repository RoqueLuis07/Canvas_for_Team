<?php

namespace App\Filament\Resources\ActaServicioTecnicoResource\Pages;

use App\Exceptions\ActaException;
use App\Filament\Resources\ActaServicioTecnicoResource;
use App\Models\ActaServicioTecnico;
use App\Services\Actas\ActaServicioTecnicoService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditActaServicioTecnico extends EditRecord
{
    protected static string $resource = ActaServicioTecnicoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Igual que la creación: la edición regenera el PDF a través del
     * servicio (mantiene el número ya asignado), no un update directo.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            /** @var ActaServicioTecnico $record */
            return app(ActaServicioTecnicoService::class)->actualizar($record, $data);
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
