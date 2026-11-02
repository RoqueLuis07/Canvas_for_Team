<?php

namespace App\Filament\Pages;

use App\Services\Excel\DiplomadoImporter;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * Alta de Diplomados desde una planilla compartida de OneDrive (no se sube
 * archivo, se lee directo por URL) — equivalente a /excel/diplomados(/preview)
 * en producción, usando DiplomadoImporter.
 */
class ImportarDiplomados extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-down';

    protected static ?string $navigationLabel = 'Importar Diplomados';

    protected static ?string $navigationGroup = 'Importación';

    protected static ?string $title = 'Alta de Diplomados (OneDrive)';

    protected static string $view = 'filament.pages.importar-diplomados';

    /** @var array<string, mixed> */
    public ?array $data = [];

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
                    ->helperText('El nombre exacto de la pestaña dentro del archivo (ej. "Diplomado SQL 2026").'),
            ])
            ->statePath('data');
    }

    public function previsualizar(): void
    {
        abort_unless(auth()->user()?->isAdminTI(), 403);

        $datos = $this->form->getState();

        try {
            $resultado = app(DiplomadoImporter::class)->previsualizar($datos['url'], $datos['sheet_name']);

            Notification::make()
                ->title("Diplomado: {$resultado['titulo']}")
                ->body("Pendientes: {$resultado['students_to_process']} · Ya procesados: {$resultado['students_already_processed']}")
                ->info()
                ->send();
        } catch (Throwable $e) {
            Notification::make()->title('No se pudo previsualizar')->body($e->getMessage())->danger()->send();
        }
    }

    public function importar(): void
    {
        abort_unless(auth()->user()?->isAdminTI(), 403);

        $datos = $this->form->getState();

        try {
            $resultado = app(DiplomadoImporter::class)->importar($datos['url'], $datos['sheet_name']);

            Notification::make()
                ->title('Alta de Diplomados terminada')
                ->body($this->resumen($resultado))
                ->color($resultado['failed'] === [] ? 'success' : 'warning')
                ->send();
        } catch (Throwable $e) {
            Notification::make()->title('No se pudo procesar la planilla')->body($e->getMessage())->danger()->send();
        }
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
