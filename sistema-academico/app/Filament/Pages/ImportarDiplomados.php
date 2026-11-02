<?php

namespace App\Filament\Pages;

use App\Services\Excel\DiplomadoImporter;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Throwable;

/**
 * Alta de Diplomados desde una o más planillas compartidas de OneDrive —
 * equivalente en espíritu al panel de Diplomados de producción (no una
 * traducción línea por línea, Filament/Livewire es otro paradigma de UI):
 *
 *   - Cada planilla cargada queda como su propia PESTAÑA (ver $pestañas),
 *     sin perder lo editado en las demás al cambiar entre ellas — Livewire
 *     mantiene vivas las propiedades públicas entre interacciones de la
 *     misma carga de página, así que basta con que los datos de cada
 *     pestaña vivan en ese array, sin JS propio.
 *   - La pestaña activa muestra una tabla editable: las filas pendientes
 *     tienen Nombre/Cédula/Correo editables (wire:model) y un checkbox
 *     para seleccionarlas; "Importar seleccionadas" solo procesa esas,
 *     con los valores que el admin haya corregido ahí mismo. Las filas ya
 *     procesadas se muestran de solo lectura con una badge — a diferencia
 *     de producción, acá NO hay editar/eliminar/quitar-del-grupo por fila
 *     ya procesada (alcance de esta fase: tabla editable + pestañas
 *     nomás; esas acciones son un fork aparte si se piden después).
 */
class ImportarDiplomados extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static ?string $navigationLabel = 'Importar Diplomados';

    protected static ?string $navigationGroup = 'Importación';

    protected static ?string $title = 'Alta de Diplomados (OneDrive)';

    protected static string $view = 'filament.pages.importar-diplomados';

    protected const MAX_PESTANAS = 8;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * Pestañas cargadas, keyeadas por un id interno aleatorio.
     *
     * @var array<string, array{url: string, sheetName: string, titulo: string, filas: list<array{fila_id: int, nombre: string, cedula: string, correo: string, ya_procesado: bool, _seleccionada?: bool}>}>
     */
    public array $pestañas = [];

    public ?string $pestañaActivaId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdminTI() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('url')
                    ->label('URL compartida de OneDrive')
                    ->url()
                    ->required()
                    ->helperText('Enlace de "Compartir" del archivo .xlsx del Diplomado en OneDrive — se lee directo de ahí, no hace falta subirlo.'),
                Forms\Components\TextInput::make('sheet_name')
                    ->label('Nombre de la pestaña')
                    ->required()
                    ->helperText('El nombre exacto de la pestaña dentro del archivo (ej. "Diplomado SQL 2026"). Cargarla la agrega como una pestaña nueva abajo, sin perder las que ya tenías abiertas.'),
            ])
            ->statePath('data');
    }

    /**
     * Descarga y previsualiza la planilla indicada en el formulario,
     * agregándola como una pestaña nueva (o reutilizando la existente si
     * ya había una pestaña abierta para la misma URL+hoja).
     */
    public function cargarPestaña(): void
    {
        abort_unless(auth()->user()?->isAdminTI(), 403);

        $datos = $this->form->getState();
        $url = trim((string) ($datos['url'] ?? ''));
        $sheet = trim((string) ($datos['sheet_name'] ?? ''));

        if ($url === '' || $sheet === '') {
            Notification::make()->title('Completá la URL y la pestaña')->warning()->send();

            return;
        }

        $tabIdExistente = $this->buscarPestañaPorClave($url, $sheet);

        if ($tabIdExistente === null && count($this->pestañas) >= self::MAX_PESTANAS) {
            Notification::make()
                ->title('Máximo '.self::MAX_PESTANAS.' pestañas abiertas a la vez')
                ->body('Cerrá alguna antes de cargar otra.')
                ->warning()
                ->send();

            return;
        }

        try {
            $resultado = app(DiplomadoImporter::class)->previsualizarCompleto($url, $sheet);
        } catch (Throwable $e) {
            Notification::make()->title('No se pudo previsualizar')->body($e->getMessage())->danger()->send();

            return;
        }

        $tabId = $tabIdExistente ?? Str::random(10);
        $titulo = $resultado['titulo'] !== '' ? $resultado['titulo'] : $sheet;

        $this->pestañas[$tabId] = [
            'url' => $url,
            'sheetName' => $sheet,
            'titulo' => $titulo,
            // _seleccionada se inicializa explícito (en vez de dejar que el
            // binding de Livewire la cree sola al primer toggle) para que
            // el checkbox arranque consistentemente destildado en todas
            // las filas, sin depender de cómo Livewire resuelve una clave
            // de array anidado que todavía no existe.
            'filas' => array_map(
                fn (array $f) => [...$f, '_seleccionada' => false],
                $resultado['filas'],
            ),
        ];
        $this->pestañaActivaId = $tabId;

        $pendientes = count(array_filter($resultado['filas'], fn (array $f) => ! $f['ya_procesado']));
        $procesados = count($resultado['filas']) - $pendientes;

        Notification::make()
            ->title("Diplomado: {$titulo}")
            ->body("Pendientes: {$pendientes} · Ya procesados: {$procesados}")
            ->info()
            ->send();
    }

    public function cambiarPestaña(string $tabId): void
    {
        if (isset($this->pestañas[$tabId])) {
            $this->pestañaActivaId = $tabId;
        }
    }

    /**
     * Cierra una pestaña — solo descarta su estado en memoria (incluidas
     * ediciones sin procesar), no hace ningún llamado al backend, igual
     * que cerrar una hoja de Excel sin guardar.
     */
    public function cerrarPestaña(string $tabId): void
    {
        unset($this->pestañas[$tabId]);

        if ($this->pestañaActivaId !== $tabId) {
            return;
        }

        $restantes = array_keys($this->pestañas);
        $this->pestañaActivaId = $restantes[0] ?? null;
    }

    /**
     * Quita una fila pendiente de la tabla de la pestaña activa sin
     * procesarla ni tocar nada en el backend — para descartarla de esta
     * corrida sin que reaparezca hasta recargar la pestaña.
     */
    public function quitarFila(int $filaId): void
    {
        if ($this->pestañaActivaId === null || ! isset($this->pestañas[$this->pestañaActivaId])) {
            return;
        }

        $this->pestañas[$this->pestañaActivaId]['filas'] = array_values(array_filter(
            $this->pestañas[$this->pestañaActivaId]['filas'],
            fn (array $f) => $f['fila_id'] !== $filaId,
        ));
    }

    /**
     * Procesa las filas pendientes tildadas en la pestaña ACTIVA, con los
     * valores que estén cargados en ese momento en la tabla (editados o
     * no) — nunca toca otras pestañas.
     */
    public function importarSeleccionadas(): void
    {
        abort_unless(auth()->user()?->isAdminTI(), 403);

        if ($this->pestañaActivaId === null || ! isset($this->pestañas[$this->pestañaActivaId])) {
            Notification::make()->title('No hay ninguna planilla cargada')->warning()->send();

            return;
        }

        $tabId = $this->pestañaActivaId;
        $tab = $this->pestañas[$tabId];

        $seleccionadas = array_values(array_filter(
            $tab['filas'],
            fn (array $f) => ! $f['ya_procesado'] && ($f['_seleccionada'] ?? false),
        ));

        if ($seleccionadas === []) {
            Notification::make()->title('Seleccioná al menos una fila pendiente')->warning()->send();

            return;
        }

        try {
            $resultado = app(DiplomadoImporter::class)->importarSeleccionados(
                $tab['url'],
                $tab['sheetName'],
                array_map(fn (array $f) => [
                    'fila_id' => $f['fila_id'],
                    'nombre' => $f['nombre'],
                    'cedula' => $f['cedula'],
                    'correo' => $f['correo'],
                ], $seleccionadas),
            );
        } catch (Throwable $e) {
            Notification::make()->title('No se pudo procesar la planilla')->body($e->getMessage())->danger()->send();

            return;
        }

        // Las filas que SÍ se procesaron (estén en succeeded, con fila_id)
        // pasan a "ya procesado" en el caché de la pestaña — si la pestaña
        // dejó de ser la activa mientras tanto (no debería, es síncrono,
        // pero por las dudas) se busca por tabId explícito, no por
        // $this->pestañaActivaId.
        if (isset($this->pestañas[$tabId])) {
            $idsExitosos = array_column($resultado['succeeded'], 'fila_id');

            foreach ($this->pestañas[$tabId]['filas'] as $i => $fila) {
                if (in_array($fila['fila_id'], $idsExitosos, true)) {
                    $this->pestañas[$tabId]['filas'][$i]['ya_procesado'] = true;
                    $this->pestañas[$tabId]['filas'][$i]['_seleccionada'] = false;
                }
            }
        }

        Notification::make()
            ->title('Alta de Diplomados terminada')
            ->body($this->resumen($resultado))
            ->color($resultado['failed'] === [] ? 'success' : 'warning')
            ->send();
    }

    protected function buscarPestañaPorClave(string $url, string $sheet): ?string
    {
        foreach ($this->pestañas as $id => $tab) {
            if ($tab['url'] === $url && $tab['sheetName'] === $sheet) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  array{succeeded: array<int, array<string, mixed>>, failed: array<int, array{input: array<string, mixed>, error: string}>}  $resultado
     */
    protected function resumen(array $resultado): string
    {
        $mensaje = count($resultado['succeeded']).' exitosas, '.count($resultado['failed']).' con error.';

        if ($resultado['failed'] === []) {
            return $mensaje;
        }

        $primeros = array_slice($resultado['failed'], 0, 3);
        $lineas = array_map(fn (array $f): string => '- '.$f['error'], $primeros);
        $resto = count($resultado['failed']) - count($primeros);

        return $mensaje."\n\n".implode("\n", $lineas).($resto > 0 ? "\n… y {$resto} más." : '');
    }
}
