<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DiagnosticoIAResource\Pages;
use App\Models\DiagnosticoIA;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Solo lectura: historial de diagnósticos generados por el LLM (simulado).
 * Solo contiene datos ya enmascarados.
 */
class DiagnosticoIAResource extends AdminOnlyResource
{
    protected static ?string $model = DiagnosticoIA::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Diagnósticos IA';

    protected static ?string $navigationGroup = 'Auditoría';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'diagnóstico IA';

    protected static ?string $pluralModelLabel = 'diagnósticos IA';

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Diagnóstico')
                ->description('Sugerencia generada por un modelo de lenguaje. No ejecuta ninguna acción: la decisión es del operador.')
                ->schema([
                    TextEntry::make('estado')->badge(),
                    TextEntry::make('proveedor')->label('Proveedor')->placeholder('-'),
                    TextEntry::make('jobSincronizacion.tipo')->label('Tipo de tarea')->badge(),
                    TextEntry::make('solicitante.name')->label('Solicitado por')->placeholder('-'),
                    TextEntry::make('respuesta.resumen')->label('Resumen')->columnSpanFull(),
                    TextEntry::make('respuesta.causa_probable.descripcion')->label('Causa probable')->columnSpanFull(),
                    TextEntry::make('respuesta.causa_probable.confianza')->label('Confianza')->badge(),
                    TextEntry::make('respuesta.pasos_verificacion')->label('Pasos de verificación')->bulleted()->columnSpanFull(),
                    TextEntry::make('respuesta.mitigaciones')->label('Mitigaciones sugeridas')->bulleted()->columnSpanFull(),
                    TextEntry::make('respuesta.advertencias')->label('Advertencias')->bulleted()->columnSpanFull(),
                    TextEntry::make('error_saneado')->label('Error')->placeholder('-')->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Trazabilidad (datos enmascarados)')
                ->collapsed()
                ->schema([
                    TextEntry::make('version_prompt')->label('Versión del prompt'),
                    TextEntry::make('duracion_ms')->label('Duración (ms)')->placeholder('-'),
                    TextEntry::make('contexto_sanitizado')->label('Contexto enviado')
                        ->formatStateUsing(fn ($state) => json_encode($state, JSON_UNESCAPED_UNICODE))
                        ->columnSpanFull(),
                    TextEntry::make('created_at')->label('Creado')->dateTime(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Fecha')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('jobSincronizacion.tipo')->label('Tarea')->badge(),
                Tables\Columns\TextColumn::make('estado')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'completado' => 'success',
                        'error' => 'danger',
                        'en_proceso' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('respuesta.resumen')->label('Resumen')->limit(70)->wrap(),
                Tables\Columns\TextColumn::make('respuesta.causa_probable.confianza')->label('Confianza')->badge(),
                Tables\Columns\TextColumn::make('solicitante.name')->label('Solicitante')->placeholder('-'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')->options([
                    'pendiente' => 'Pendiente',
                    'en_proceso' => 'En proceso',
                    'completado' => 'Completado',
                    'error' => 'Error',
                ]),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Ver')])
            ->striped()
            ->paginated([10, 25, 50])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aún no hay diagnósticos')
            ->emptyStateDescription('Use "Diagnosticar con IA" en un trabajo de sincronización con error.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDiagnosticoIAS::route('/'),
            'view' => Pages\ViewDiagnosticoIA::route('/{record}'),
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
