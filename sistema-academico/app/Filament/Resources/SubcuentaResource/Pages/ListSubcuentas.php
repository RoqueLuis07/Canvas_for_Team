<?php

namespace App\Filament\Resources\SubcuentaResource\Pages;

use App\Filament\Resources\SubcuentaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSubcuentas extends ListRecords
{
    protected static string $resource = SubcuentaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
