<?php

namespace App\Filament\Resources\MiembroEquipoResource\Pages;

use App\Filament\Resources\MiembroEquipoResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMiembroEquipo extends EditRecord
{
    protected static string $resource = MiembroEquipoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
