<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MateriaResource\Pages;
use App\Models\Materia;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class MateriaResource extends AdminOnlyResource
{
    protected static ?string $model = Materia::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Materias';

    protected static ?string $navigationGroup = 'Oferta académica';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'materia';

    protected static ?string $pluralModelLabel = 'materias';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos de la materia')
                    ->description('Identificación, ubicación curricular y estado de la materia.')
                    ->schema([
                        Forms\Components\Select::make('plan_estudio_id')
                            ->label('Plan de estudio')
                            ->relationship('planEstudio', 'nombre')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('codigo')
                            ->label('Código')
                            ->required(),
                        Forms\Components\TextInput::make('nombre')
                            ->label('Nombre de la materia')
                            ->required(),
                        Forms\Components\TextInput::make('creditos')
                            ->label('Créditos')
                            ->required()
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('semestre_sugerido')
                            ->label('Semestre sugerido')
                            ->required()
                            ->numeric()
                            ->default(1),
                        Forms\Components\Toggle::make('activa')
                            ->label('Materia activa')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('planEstudio.nombre')
                    ->label('Plan de estudio')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable(),
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Materia')
                    ->searchable(),
                Tables\Columns\TextColumn::make('creditos')
                    ->label('Créditos')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('semestre_sugerido')
                    ->label('Semestre sugerido')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('activa')
                    ->label('Activa')
                    ->boolean(),
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
                Tables\Filters\SelectFilter::make('planEstudio')
                    ->label('Plan de estudio')
                    ->relationship('planEstudio', 'nombre')
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('activa')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),
                static::dateRangeFilter('created_at', 'Fecha de registro'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar materia'),
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
            ->emptyStateHeading('No hay materias')
            ->emptyStateDescription('Las materias asociadas a planes de estudio aparecerán aquí.')
            ->defaultSort('codigo');
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
            'index' => Pages\ListMaterias::route('/'),
            'create' => Pages\CreateMateria::route('/create'),
            'edit' => Pages\EditMateria::route('/{record}/edit'),
        ];
    }
}
