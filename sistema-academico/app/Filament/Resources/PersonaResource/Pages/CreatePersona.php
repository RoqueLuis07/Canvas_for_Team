<?php

namespace App\Filament\Resources\PersonaResource\Pages;

use App\Filament\Resources\PersonaResource;
use App\Services\IngresoService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreatePersona extends CreateRecord
{
    protected static string $resource = PersonaResource::class;

    /**
     * Si se tildó "crear cuentas institucionales automáticamente" en el
     * formulario, da de alta las cuentas en Canvas y/o Teams apenas se crea
     * la Persona. `crear_cuentas_automaticamente` y `plataforma_alta` no se
     * persisten en el modelo (dehydrated(false) en PersonaResource) — solo
     * existen en $this->data, el estado crudo del formulario.
     */
    protected function afterCreate(): void
    {
        $crearAutomaticamente = $this->data['crear_cuentas_automaticamente'] ?? false;

        if (! $crearAutomaticamente) {
            return;
        }

        $plataforma = $this->data['plataforma_alta'] ?? 'both';

        try {
            app(IngresoService::class)->darDeAlta($this->record, $plataforma);

            Notification::make()
                ->title('Persona creada y cuentas institucionales dadas de alta')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Persona creada, pero falló el alta de cuentas institucionales')
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }
}
