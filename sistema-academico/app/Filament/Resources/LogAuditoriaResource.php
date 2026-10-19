<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LogAuditoriaResource\Pages;
use App\Models\LogAuditoria;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Solo lectura (HU-05): historial de auditoría de sincronizaciones,
 * consultable por usuario o entidad afectada. Los registros se crean desde
 * SincronizacionService, no manualmente desde el panel.
 */
class LogAuditoriaResource extends AdminOnlyResource
{
    protected static ?string $model = LogAuditoria::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Auditoría';

    protected static ?string $navigationGroup = 'Auditoría';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'registro de auditoría';

    protected static ?string $pluralModelLabel = 'registros de auditoría';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Evento auditado')
                    ->schema([
                        TextEntry::make('fecha')
                            ->label('Fecha y hora')
                            ->dateTime(),
                        TextEntry::make('usuario.name')
                            ->label('Usuario')
                            ->placeholder('Sistema'),
                        TextEntry::make('accion')
                            ->label('Acción')
                            ->badge(),
                        TextEntry::make('entidad_afectada')
                            ->label('Entidad afectada'),
                        TextEntry::make('detalle')
                            ->label('Detalle')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('fecha')
                    ->label('Fecha y hora')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('usuario.name')
                    ->label('Usuario')
                    ->placeholder('Sistema')
                    ->searchable(),
                Tables\Columns\TextColumn::make('accion')
                    ->label('Acción')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('entidad_afectada')
                    ->label('Entidad afectada')
                    ->searchable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('usuario')
                    ->label('Usuario')
                    ->relationship('usuario', 'name')
                    ->searchable()
                    ->preload(),
                static::dateRangeFilter('fecha', 'Fecha del evento'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->tooltip('Ver registro de auditoría'),
            ])
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No hay registros de auditoría')
            ->emptyStateDescription('Los eventos registrados por el sistema aparecerán aquí.')
            ->defaultSort('fecha', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLogAuditorias::route('/'),
            'view' => Pages\ViewLogAuditoria::route('/{record}'),
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
