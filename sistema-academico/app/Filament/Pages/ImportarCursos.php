<?php

namespace App\Filament\Pages;

use App\Services\Excel\CursoImporter;
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
 * Importación masiva de Cursos (asignación a Canvas/Teams) desde una
 * planilla .xlsx — equivalente a /excel/canvas/courses en producción,
 * usando CursoImporter.
 */
class ImportarCursos extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Importar Cursos';

    protected static ?string $navigationGroup = 'Importación';

    protected static ?string $title = 'Importación masiva de Cursos';

    protected static string $view = 'filament.pages.importar-cursos';

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
                    ->maxSize(10 * 1024)
                    ->required()
                    ->helperText('Columnas: Código de Materia*, Período Académico* (nombre exacto, ej. "2026-1"). La Materia y el Período deben existir ya en el catálogo.'),
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
                    $path = app(PlantillaExcelGenerator::class)->plantillaCursos();

                    return response()->download($path, 'plantilla_cursos.xlsx')->deleteFileAfterSend(true);
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
            $resultado = app(CursoImporter::class)->importar($rutaAbsoluta);

            Notification::make()
                ->title('Importación de Cursos terminada')
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
        $mensaje = count($resultado['succeeded'])." exitosos, ".count($resultado['failed'])." con error.";

        if ($resultado['failed'] === []) {
            return $mensaje;
        }

        $primeros = array_slice($resultado['failed'], 0, 3);
        $lineas = array_map(fn (array $f): string => '- '.$f['error'], $primeros);
        $resto = count($resultado['failed']) - count($primeros);

        return $mensaje."\n\n".implode("\n", $lineas).($resto > 0 ? "\n… y {$resto} más." : '');
    }
}
