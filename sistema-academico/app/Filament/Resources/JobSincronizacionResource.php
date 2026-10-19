<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JobSincronizacionResource\Pages;
use App\Models\JobSincronizacion;
use App\Services\HistorialService;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Solo lectura: los JobSincronizacion se crean y actualizan desde
 * SincronizacionService, no manualmente desde el panel.
 */
class JobSincronizacionResource extends AdminOnlyResource
{
    protected static ?string $model = JobSincronizacion::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Jobs de sincronización';

    protected static ?string $navigationGroup = 'Auditoría';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'trabajo de sincronización';

    protected static ?string $pluralModelLabel = 'trabajos de sincronización';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Ejecución de sincronización')
                    ->schema([
                        TextEntry::make('tipo')
                            ->label('Tipo de tarea')
                            ->badge(),
                        TextEntry::make('estado')
                            ->label('Estado')
                            ->badge(),
                        TextEntry::make('usuarioSolicitante.name')
                            ->label('Solicitada por')
                            ->placeholder('Sistema'),
                        TextEntry::make('fecha_inicio')
                            ->label('Inicio')
                            ->dateTime(),
                        TextEntry::make('fecha_fin')
                            ->label('Finalización')
                            ->dateTime(),
                        TextEntry::make('resultado')
                            ->label('Resultado')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Tipo de tarea')
                    ->badge(),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'completado' => 'success',
                        'error' => 'danger',
                        'en_proceso' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('usuarioSolicitante.name')
                    ->label('Solicitante')
                    ->placeholder('Sistema'),
                Tables\Columns\TextColumn::make('fecha_inicio')
                    ->label('Inicio')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fecha_fin')
                    ->label('Finalización')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('resultado')
                    ->label('Resultado')
                    ->limit(60)
                    ->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'en_proceso' => 'En proceso',
                        'completado' => 'Completado',
                        'error' => 'Error',
                    ]),
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo de tarea')
                    ->options([
                        'alta_usuario' => 'Alta de usuario',
                        'crear_curso' => 'Crear curso',
                        'crear_team' => 'Crear team',
                        'matricula' => 'Matrícula',
                        'baja' => 'Baja',
                    ]),
                static::dateRangeFilter('fecha_inicio', 'Fecha de inicio'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->tooltip('Ver detalle del trabajo'),
                static::diagnosticarAction(),
            ])
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No hay trabajos de sincronización')
            ->emptyStateDescription('Las tareas de sincronización aparecerán aquí cuando se ejecuten.')
            ->defaultSort('fecha_inicio', 'desc');
    }

    public static function diagnosticarAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('diagnosticar')
            ->label('Diagnosticar con IA')
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->visible(fn (JobSincronizacion $record) => $record->estado === 'error' && (static::currentUser()?->isAdminTI() ?? false))
            ->requiresConfirmation()
            ->modalDescription('Se enviará al LLM solo la categoría del error, sin datos personales. La IA solo sugiere; no ejecuta cambios.')
            ->action(function (JobSincronizacion $record) {
                $servicio = app(HistorialService::class);
                $diagnostico = $servicio->procesar($servicio->solicitar($record, static::currentUser()));

                Notification::make()
                    ->title($diagnostico->estado === 'completado' ? 'Diagnóstico generado' : 'No se pudo generar el diagnóstico')
                    ->color($diagnostico->estado === 'completado' ? 'success' : 'danger')
                    ->send();

                return redirect(DiagnosticoIAResource::getUrl('view', ['record' => $diagnostico]));
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListJobSincronizacions::route('/'),
            'view' => Pages\ViewJobSincronizacion::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
