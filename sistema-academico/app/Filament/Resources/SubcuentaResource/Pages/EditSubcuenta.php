<?php

namespace App\Filament\Resources\SubcuentaResource\Pages;

use App\Filament\Resources\SubcuentaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubcuenta extends EditRecord
{
    protected static string $resource = SubcuentaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
