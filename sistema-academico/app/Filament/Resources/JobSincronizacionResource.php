<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JobSincronizacionResource\Pages;
use App\Models\JobSincronizacion;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Solo lectura: los JobSincronizacion se crean y actualizan desde
 * SincronizacionService, no manualmente desde el panel.
 */
class JobSincronizacionResource extends Resource
{
    protected static ?string $model = JobSincronizacion::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?string $navigationLabel = 'Jobs de sincronización';

    protected static ?string $navigationGroup = 'Auditoría';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                TextEntry::make('tipo')->badge(),
                TextEntry::make('estado')->badge(),
                TextEntry::make('usuarioSolicitante.name')->label('Solicitante')->placeholder('Sistema'),
                TextEntry::make('fecha_inicio')->dateTime(),
                TextEntry::make('fecha_fin')->dateTime(),
                TextEntry::make('resultado')->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tipo')
                    ->badge(),
                Tables\Columns\TextColumn::make('estado')
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
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fecha_fin')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('resultado')
                    ->limit(60)
                    ->wrap(),
            ])
            ->defaultSort('fecha_inicio', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'en_proceso' => 'En proceso',
                        'completado' => 'Completado',
                        'error' => 'Error',
                    ]),
                Tables\Filters\SelectFilter::make('tipo')
                    ->options([
                        'alta_usuario' => 'Alta de usuario',
                        'crear_curso' => 'Crear curso',
                        'crear_team' => 'Crear team',
                        'matricula' => 'Matrícula',
                        'baja' => 'Baja',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
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
}
