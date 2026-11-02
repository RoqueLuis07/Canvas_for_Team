<?php

namespace App\Filament\Resources\ActaServicioTecnicoResource\Pages;

use App\Filament\Resources\ActaServicioTecnicoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListActaServicioTecnicos extends ListRecords
{
    protected static string $resource = ActaServicioTecnicoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
