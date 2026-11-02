<?php

namespace App\Filament\Pages;

use App\Contracts\TeamsClient;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * Cuentas deshabilitadas que todavía tienen licencias asignadas — asientos
 * pagos ocupados por cuentas abandonadas. Equivalente a
 * GET /orphaned-licenses + POST /orphaned-licenses/free en producción.
 *
 * Nota de implementación: se arma como una tabla simple en Blade en vez de
 * usar el Table Builder de Filament — los resultados vienen de una llamada
 * en vivo a Graph (no de una query Eloquent), y no se pudo verificar en
 * este sandbox (PHP 8.3, el proyecto necesita 8.4) si la versión de
 * Filament instalada soporta tablas respaldadas por un array simple sin
 * arriesgar un error de API no verificado.
 */
class ReporteLicenciasHuerfanas extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Licencias huérfanas';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?string $title = 'Licencias huérfanas';

    protected static string $view = 'filament.pages.reporte-licencias-huerfanas';

    /** @var list<array{id: string, display_name: string, upn: string, licenses: list<string>}> */
    public array $resultados = [];

    /** @var list<string> */
    public array $seleccionados = [];

    public bool $seGeneroReporte = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdminTI() ?? false;
    }

    public function generarReporte(): void
    {
        abort_unless(auth()->user()?->isAdminTI(), 403);

        try {
            $this->resultados = app(TeamsClient::class)->listOrphanedLicenses();
            $this->seleccionados = [];
            $this->seGeneroReporte = true;

            Notification::make()
                ->title(count($this->resultados).' cuenta(s) con licencia huérfana')
                ->color(count($this->resultados) === 0 ? 'success' : 'warning')
                ->send();
        } catch (Throwable $e) {
            Notification::make()->title('No se pudo generar el reporte')->body($e->getMessage())->danger()->send();
        }
    }

    public function liberarSeleccionadas(): void
    {
        abort_unless(auth()->user()?->isAdminTI(), 403);

        if ($this->seleccionados === []) {
            return;
        }

        $teams = app(TeamsClient::class);
        $ok = 0;
        $fail = 0;

        foreach ($this->seleccionados as $id) {
            try {
                $teams->removeAllLicenses($id);
                $ok++;
            } catch (Throwable) {
                $fail++;
            }
        }

        $this->resultados = app(TeamsClient::class)->listOrphanedLicenses();
        $this->seleccionados = [];

        Notification::make()
            ->title("Licencias liberadas: {$ok}".($fail ? " · con error: {$fail}" : ''))
            ->color($fail ? 'warning' : 'success')
            ->send();
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('generar')
                ->label('Generar reporte')
                ->icon('heroicon-o-arrow-path')
                ->action('generarReporte'),
        ];
    }
}
