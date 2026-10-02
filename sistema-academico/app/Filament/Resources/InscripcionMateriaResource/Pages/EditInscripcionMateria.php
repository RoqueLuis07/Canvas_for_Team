<?php

namespace App\Filament\Resources\InscripcionMateriaResource\Pages;

use App\Filament\Resources\InscripcionMateriaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInscripcionMateria extends EditRecord
{
    protected static string $resource = InscripcionMateriaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
