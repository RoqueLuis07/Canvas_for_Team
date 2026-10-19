<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PeriodoAcademicoResource\Pages;
use App\Models\PeriodoAcademico;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class PeriodoAcademicoResource extends AdminOnlyResource
{
    protected static ?string $model = PeriodoAcademico::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Períodos académicos';

    protected static ?string $navigationGroup = 'Oferta académica';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'período académico';

    protected static ?string $pluralModelLabel = 'períodos académicos';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Período académico')
                    ->description('Calendario y estado de habilitación del período.')
                    ->schema([
                        Forms\Components\TextInput::make('nombre')
                            ->label('Nombre del período')
                            ->helperText('Usa el código académico definido por la institución, por ejemplo 2026-2.')
                            ->required(),
                        Forms\Components\Select::make('estado')
                            ->label('Estado')
                            ->options([
                                'planificado' => 'Planificado',
                                'inscripciones_abiertas' => 'Inscripciones abiertas',
                                'en_curso' => 'En curso',
                                'cerrado' => 'Cerrado',
                            ])
                            ->required(),
                        Forms\Components\DatePicker::make('fecha_inicio')
                            ->label('Fecha de inicio')
                            ->required(),
                        Forms\Components\DatePicker::make('fecha_fin')
                            ->label('Fecha de finalización')
                            ->required(),
                        Forms\Components\DatePicker::make('fecha_limite_inscripcion_materias')
                            ->label('Fecha límite para inscribir materias'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Período')
                    ->searchable(),
                Tables\Columns\TextColumn::make('fecha_inicio')
                    ->label('Inicio')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fecha_fin')
                    ->label('Finalización')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fecha_limite_inscripcion_materias')
                    ->label('Límite de inscripción')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'inscripciones_abiertas' => 'success',
                        'en_curso' => 'info',
                        'planificado' => 'warning',
                        'cerrado' => 'gray',
                        default => 'gray',
                    })
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
                        'planificado' => 'Planificado',
                        'inscripciones_abiertas' => 'Inscripciones abiertas',
                        'en_curso' => 'En curso',
                        'cerrado' => 'Cerrado',
                    ]),
                static::dateRangeFilter('fecha_inicio', 'Fecha de inicio'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar período académico'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Eliminar seleccionados')
                        ->color('danger'),
                ]),
            ])
            ->striped()
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No hay períodos académicos')
            ->emptyStateDescription('Los períodos definidos por la institución aparecerán aquí.')
            ->defaultSort('fecha_inicio', 'desc');
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
            'index' => Pages\ListPeriodoAcademicos::route('/'),
            'create' => Pages\CreatePeriodoAcademico::route('/create'),
            'edit' => Pages\EditPeriodoAcademico::route('/{record}/edit'),
        ];
    }
}
