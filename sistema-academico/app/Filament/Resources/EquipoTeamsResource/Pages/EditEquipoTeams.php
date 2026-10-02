<?php

namespace App\Filament\Resources\EquipoTeamsResource\Pages;

use App\Filament\Resources\EquipoTeamsResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditEquipoTeams extends EditRecord
{
    protected static string $resource = EquipoTeamsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
