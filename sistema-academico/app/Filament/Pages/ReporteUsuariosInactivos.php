<?php

namespace App\Filament\Pages;

use App\Contracts\TeamsClient;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * Cuentas habilitadas sin inicio de sesión reciente — equivalente a
 * GET /inactive-teams-users en producción. Requiere el permiso de
 * aplicación 'AuditLog.Read.All' con consentimiento de administrador; sin
 * él, Graph devuelve 403 al pedir signInActivity (mismo aviso que
 * producción ya le da al usuario en ese caso, ver el catch de abajo).
 */
class ReporteUsuariosInactivos extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-minus';

    protected static ?string $navigationLabel = 'Usuarios inactivos';

    protected static ?string $navigationGroup = 'Reportes';

    protected static ?string $title = 'Usuarios inactivos';

    protected static string $view = 'filament.pages.reporte-usuarios-inactivos';

    /** @var array<string, mixed> */
    public ?array $data = ['dias' => 60];

    /** @var list<array{id: string, display_name: string, upn: string, last_signin: ?string}> */
    public array $resultados = [];

    public bool $seGeneroReporte = false;

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
                Forms\Components\TextInput::make('dias')
                    ->label('Días de inactividad')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(730)
                    ->default(60)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function generarReporte(): void
    {
        abort_unless(auth()->user()?->isAdminTI(), 403);

        $dias = (int) ($this->form->getState()['dias'] ?? 60);

        try {
            $this->resultados = app(TeamsClient::class)->listInactiveUsers($dias);
            $this->seGeneroReporte = true;

            Notification::make()
                ->title(count($this->resultados)." cuenta(s) sin inicio de sesión en {$dias} día(s)")
                ->color(count($this->resultados) === 0 ? 'success' : 'warning')
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('No se pudo generar el reporte')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
