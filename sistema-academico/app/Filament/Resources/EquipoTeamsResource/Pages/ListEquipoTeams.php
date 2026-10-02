<?php

namespace App\Filament\Resources\EquipoTeamsResource\Pages;

use App\Filament\Resources\EquipoTeamsResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEquipoTeams extends ListRecords
{
    protected static string $resource = EquipoTeamsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
