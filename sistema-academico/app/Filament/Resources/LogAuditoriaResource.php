<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LogAuditoriaResource\Pages;
use App\Models\LogAuditoria;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Solo lectura (HU-05): historial de auditoría de sincronizaciones,
 * consultable por usuario o entidad afectada. Los registros se crean desde
 * SincronizacionService, no manualmente desde el panel.
 */
class LogAuditoriaResource extends Resource
{
    protected static ?string $model = LogAuditoria::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Auditoría';

    protected static ?string $navigationGroup = 'Auditoría';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('usuario.name')->label('Usuario')->placeholder('Sistema'),
                TextEntry::make('accion'),
                TextEntry::make('entidad_afectada')->label('Entidad afectada'),
                TextEntry::make('fecha')->dateTime(),
                TextEntry::make('detalle')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('fecha')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('usuario.name')
                    ->label('Usuario')
                    ->placeholder('Sistema')
                    ->searchable(),
                Tables\Columns\TextColumn::make('accion')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('entidad_afectada')
                    ->label('Entidad afectada')
                    ->searchable(),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
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
}
