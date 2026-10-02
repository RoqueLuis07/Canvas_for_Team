<?php

namespace App\Filament\Resources\InscripcionMateriaResource\Pages;

use App\Filament\Resources\InscripcionMateriaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInscripcionMaterias extends ListRecords
{
    protected static string $resource = InscripcionMateriaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
