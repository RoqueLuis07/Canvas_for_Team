<x-filament-panels::page>
    @if (! $seGeneroReporte)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Usá "Generar reporte" para ver las cuentas deshabilitadas que todavía tienen licencias asignadas.
        </p>
    @elseif (empty($resultados))
        <p class="text-sm text-gray-500 dark:text-gray-400">
            No hay cuentas deshabilitadas con licencias asignadas.
        </p>
    @else
        <div class="mb-4">
            <x-filament::button
                color="danger"
                icon="heroicon-o-trash"
                wire:click="liberarSeleccionadas"
                wire:confirm="¿Liberar las licencias de las cuentas seleccionadas?"
                :disabled="empty($seleccionados)"
            >
                Liberar licencias seleccionadas ({{ count($seleccionados) }})
            </x-filament::button>
        </div>

        <div class="fi-ta-ctn overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="fi-ta-table w-full text-start">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="p-3"></th>
                        <th class="p-3 text-sm font-medium">Nombre</th>
                        <th class="p-3 text-sm font-medium">Correo institucional</th>
                        <th class="p-3 text-sm font-medium">Licencias</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($resultados as $fila)
                        <tr wire:key="licencia-{{ $fila['id'] }}">
                            <td class="p-3"><input type="checkbox" wire:model="seleccionados" value="{{ $fila['id'] }}"></td>
                            <td class="p-3 text-sm">{{ $fila['display_name'] }}</td>
                            <td class="p-3 text-sm">{{ $fila['upn'] }}</td>
                            <td class="p-3 text-sm">{{ implode(', ', $fila['licenses']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
