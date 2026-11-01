<?php

namespace App\Filament\Pages;

use App\Services\Excel\PersonaImporter;
use App\Services\Excel\PlantillaExcelGenerator;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Importación masiva de Personas desde una planilla .xlsx — equivalente a
 * /ingreso/bulk-file en producción, usando PersonaImporter.
 */
class ImportarPersonas extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Importar Personas';

    protected static ?string $navigationGroup = 'Importación';

    protected static ?string $title = 'Importación masiva de Personas';

    protected static string $view = 'filament.pages.importar-personas';

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
                Forms\Components\FileUpload::make('archivo')
                    ->label('Planilla (.xlsx)')
                    ->disk('local')
                    ->directory('excel-imports')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->maxSize(10 * 1024) // 10 MB, mismo límite de seguridad que producción.
                    ->required()
                    ->helperText('Columnas: Nombre Completo*, Cédula*, Email Personal, Tipo (alumno/docente/administrativo/aspirante), Plataforma (canvas/teams/both).'),
            ])
            ->statePath('data');
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('descargarPlantilla')
                ->label('Descargar plantilla')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $path = app(PlantillaExcelGenerator::class)->plantillaPersonas();

                    return response()->download($path, 'plantilla_personas.xlsx')->deleteFileAfterSend(true);
                }),
        ];
    }

    public function importar(): void
    {
        abort_unless(auth()->user()?->isAdminTI(), 403);

        $rutaRelativa = $this->form->getState()['archivo'] ?? null;

        if ($rutaRelativa === null) {
            Notification::make()->title('Subí un archivo .xlsx primero')->warning()->send();

            return;
        }

        $rutaAbsoluta = Storage::disk('local')->path($rutaRelativa);

        try {
            $resultado = app(PersonaImporter::class)->importar($rutaAbsoluta);

            Notification::make()
                ->title('Importación de Personas terminada')
                ->body($this->resumen($resultado))
                ->color($resultado['failed'] === [] ? 'success' : 'warning')
                ->send();
        } catch (Throwable $e) {
            Notification::make()->title('No se pudo importar el archivo')->body($e->getMessage())->danger()->send();
        } finally {
            Storage::disk('local')->delete($rutaRelativa);
            $this->form->fill();
        }
    }

    /**
     * @param  array{succeeded: array<int, array<string, mixed>>, failed: array<int, array{input: array<string, mixed>, error: string}>}  $resultado
     */
    protected function resumen(array $resultado): string
    {
        $mensaje = count($resultado['succeeded'])." exitosas, ".count($resultado['failed'])." con error.";

        if ($resultado['failed'] === []) {
            return $mensaje;
        }

        $primeros = array_slice($resultado['failed'], 0, 3);
        $lineas = array_map(fn (array $f): string => '- '.$f['error'], $primeros);
        $resto = count($resultado['failed']) - count($primeros);

        return $mensaje."\n\n".implode("\n", $lineas).($resto > 0 ? "\n… y {$resto} más." : '');
    }
}
