<?php

namespace App\Filament\Resources\MiembroEquipoResource\Pages;

use App\Filament\Resources\MiembroEquipoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMiembroEquipos extends ListRecords
{
    protected static string $resource = MiembroEquipoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
