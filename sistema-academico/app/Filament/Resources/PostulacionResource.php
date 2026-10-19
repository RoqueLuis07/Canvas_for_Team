<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostulacionResource\Pages;
use App\Models\Postulacion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class PostulacionResource extends AdminOnlyResource
{
    protected static ?string $model = Postulacion::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationLabel = 'Postulaciones';

    protected static ?string $navigationGroup = 'Matriculación';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'postulación';

    protected static ?string $pluralModelLabel = 'postulaciones';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos de la postulación')
                    ->description('Aspirante, programa solicitado, período y resolución.')
                    ->schema([
                        Forms\Components\Select::make('persona_id')
                            ->label('Aspirante')
                            ->relationship('persona', 'nombre_completo')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('programa_id')
                            ->label('Programa')
                            ->relationship('programa', 'nombre')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('periodo_academico_id')
                            ->label('Período académico')
                            ->relationship('periodoAcademico', 'nombre')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('estado')
                            ->label('Estado')
                            ->options([
                                'pendiente' => 'Pendiente',
                                'admitido' => 'Admitido',
                                'rechazado' => 'Rechazado',
                            ])
                            ->required(),
                        Forms\Components\DatePicker::make('fecha_postulacion')
                            ->label('Fecha de postulación')
                            ->required(),
                        Forms\Components\Textarea::make('observaciones')
                            ->label('Observaciones')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('persona.nombre_completo')
                    ->label('Aspirante')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('programa.nombre')
                    ->label('Programa')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('periodoAcademico.nombre')
                    ->label('Período académico')
                    ->sortable(),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admitido' => 'success',
                        'rechazado' => 'danger',
                        'pendiente' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('fecha_postulacion')
                    ->label('Fecha de postulación')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'admitido' => 'Admitido',
                        'rechazado' => 'Rechazado',
                    ]),
                Tables\Filters\SelectFilter::make('programa')
                    ->label('Programa')
                    ->relationship('programa', 'nombre')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('periodoAcademico')
                    ->label('Período académico')
                    ->relationship('periodoAcademico', 'nombre')
                    ->searchable()
                    ->preload(),
                static::dateRangeFilter('fecha_postulacion', 'Fecha de postulación'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar postulación'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Eliminar seleccionadas')
                        ->color('danger'),
                ]),
            ])
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No hay postulaciones')
            ->emptyStateDescription('Las postulaciones recibidas aparecerán aquí.')
            ->defaultSort('fecha_postulacion', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPostulacions::route('/'),
            'create' => Pages\CreatePostulacion::route('/create'),
            'edit' => Pages\EditPostulacion::route('/{record}/edit'),
        ];
    }
}
