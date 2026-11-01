<x-filament-panels::page>
    <form wire:submit="importar">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4" icon="heroicon-o-arrow-up-tray">
            Importar
        </x-filament::button>
    </form>
</x-filament-panels::page>
