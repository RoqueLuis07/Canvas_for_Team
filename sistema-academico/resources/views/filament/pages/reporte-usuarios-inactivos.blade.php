<x-filament-panels::page>
    <form wire:submit="generarReporte">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4" icon="heroicon-o-arrow-path">
            Generar reporte
        </x-filament::button>
    </form>

    @if ($seGeneroReporte)
        @if (empty($resultados))
            <p class="mt-6 text-sm text-gray-500 dark:text-gray-400">
                No hay cuentas habilitadas sin inicio de sesión en ese rango.
            </p>
        @else
            <div class="fi-ta-ctn mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="fi-ta-table w-full text-start">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="p-3 text-sm font-medium">Nombre</th>
                            <th class="p-3 text-sm font-medium">Correo institucional</th>
                            <th class="p-3 text-sm font-medium">Último inicio de sesión</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($resultados as $fila)
                            <tr wire:key="inactivo-{{ $fila['id'] }}">
                                <td class="p-3 text-sm">{{ $fila['display_name'] }}</td>
                                <td class="p-3 text-sm">{{ $fila['upn'] }}</td>
                                <td class="p-3 text-sm">{{ $fila['last_signin'] ?? 'Nunca inició sesión' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-filament-panels::page>
