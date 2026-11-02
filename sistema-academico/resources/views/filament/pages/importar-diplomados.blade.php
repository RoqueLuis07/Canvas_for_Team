<x-filament-panels::page>
    <form wire:submit="importar">
        {{ $this->form }}

        <div class="mt-4 flex gap-2">
            <x-filament::button type="button" color="gray" icon="heroicon-o-eye" wire:click="previsualizar">
                Previsualizar
            </x-filament::button>

            <x-filament::button type="submit" icon="heroicon-o-cloud-arrow-down">
                Procesar
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
