<?php

namespace App\Filament\Resources\PlanEstudioResource\Pages;

use App\Filament\Resources\PlanEstudioResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlanEstudios extends ListRecords
{
    protected static string $resource = PlanEstudioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
