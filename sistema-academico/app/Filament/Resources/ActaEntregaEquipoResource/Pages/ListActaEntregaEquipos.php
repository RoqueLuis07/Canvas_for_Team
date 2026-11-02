<?php

namespace App\Filament\Resources\ActaEntregaEquipoResource\Pages;

use App\Filament\Resources\ActaEntregaEquipoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListActaEntregaEquipos extends ListRecords
{
    protected static string $resource = ActaEntregaEquipoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
