<x-filament-panels::page>
    <form wire:submit="cargarPestaña">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4" icon="heroicon-o-cloud-arrow-down">
            Cargar pestaña
        </x-filament::button>
    </form>

    @if (count($pestañas) > 0)
        {{-- Barra de pestañas tipo Excel: cada planilla cargada queda como su
             propia pestaña, sin perder lo editado en las demás al cambiar
             entre ellas (ver ImportarDiplomados::cambiarPestaña/cerrarPestaña). --}}
        <div class="mt-6 flex flex-wrap items-center gap-2 border-b border-gray-200 pb-3 dark:border-white/10">
            @foreach ($pestañas as $tabId => $tab)
                <div
                    wire:key="tab-{{ $tabId }}"
                    class="flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium cursor-pointer {{ $tabId === $pestañaActivaId ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' }}"
                    wire:click="cambiarPestaña('{{ $tabId }}')"
                >
                    <span>{{ $tab['titulo'] }}</span>
                    <button
                        type="button"
                        wire:click.stop="cerrarPestaña('{{ $tabId }}')"
                        title="Cerrar pestaña"
                        class="opacity-70 hover:opacity-100"
                    >
                        <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                    </button>
                </div>
            @endforeach
        </div>

        @php($tabActiva = $pestañaActivaId ? ($pestañas[$pestañaActivaId] ?? null) : null)

        @if ($tabActiva)
            @php($pendientesSeleccionadas = collect($tabActiva['filas'])->filter(fn ($f) => ! $f['ya_procesado'] && ($f['_seleccionada'] ?? false))->count())

            <div class="mt-4 mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Pendientes: {{ collect($tabActiva['filas'])->where('ya_procesado', false)->count() }}
                    · Ya procesados: {{ collect($tabActiva['filas'])->where('ya_procesado', true)->count() }}
                </p>

                <x-filament::button
                    color="success"
                    icon="heroicon-o-check-circle"
                    wire:click="importarSeleccionadas"
                    wire:confirm="¿Procesar las filas seleccionadas?"
                    :disabled="$pendientesSeleccionadas === 0"
                >
                    Importar seleccionadas ({{ $pendientesSeleccionadas }})
                </x-filament::button>
            </div>

            <div class="fi-ta-ctn overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="fi-ta-table w-full text-start">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="p-3"></th>
                            <th class="p-3 text-sm font-medium">Nombre</th>
                            <th class="p-3 text-sm font-medium">Cédula</th>
                            <th class="p-3 text-sm font-medium">Correo</th>
                            <th class="p-3 text-sm font-medium">Estado</th>
                            <th class="p-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($tabActiva['filas'] as $i => $fila)
                            <tr wire:key="fila-{{ $pestañaActivaId }}-{{ $fila['fila_id'] }}">
                                @if ($fila['ya_procesado'])
                                    <td class="p-3"></td>
                                    <td class="p-3 text-sm text-gray-500 dark:text-gray-400">{{ $fila['nombre'] }}</td>
                                    <td class="p-3 text-sm text-gray-500 dark:text-gray-400">{{ $fila['cedula'] }}</td>
                                    <td class="p-3 text-sm text-gray-500 dark:text-gray-400">{{ $fila['correo'] }}</td>
                                    <td class="p-3">
                                        <x-filament::badge color="success">Ya procesado</x-filament::badge>
                                    </td>
                                    <td class="p-3"></td>
                                @else
                                    <td class="p-3">
                                        <input type="checkbox" wire:model="pestañas.{{ $pestañaActivaId }}.filas.{{ $i }}._seleccionada">
                                    </td>
                                    <td class="p-3">
                                        <x-filament::input type="text" wire:model="pestañas.{{ $pestañaActivaId }}.filas.{{ $i }}.nombre" />
                                    </td>
                                    <td class="p-3">
                                        <x-filament::input type="text" wire:model="pestañas.{{ $pestañaActivaId }}.filas.{{ $i }}.cedula" />
                                    </td>
                                    <td class="p-3">
                                        <x-filament::input type="text" wire:model="pestañas.{{ $pestañaActivaId }}.filas.{{ $i }}.correo" />
                                    </td>
                                    <td class="p-3">
                                        <x-filament::badge color="warning">Pendiente</x-filament::badge>
                                    </td>
                                    <td class="p-3">
                                        <button
                                            type="button"
                                            wire:click="quitarFila({{ $fila['fila_id'] }})"
                                            title="Quitar de esta lista (no procesa ni borra nada)"
                                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                        >
                                            <x-heroicon-o-x-mark class="h-4 w-4" />
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="p-3 text-center text-sm text-gray-500 dark:text-gray-400">
                                    No hay filas en esta pestaña.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</x-filament-panels::page>
