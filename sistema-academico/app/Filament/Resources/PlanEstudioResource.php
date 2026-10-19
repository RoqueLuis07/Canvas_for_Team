<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanEstudioResource\Pages;
use App\Models\PlanEstudio;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class PlanEstudioResource extends AdminOnlyResource
{
    protected static ?string $model = PlanEstudio::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = 'Planes de estudio';

    protected static ?string $navigationGroup = 'Oferta académica';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'plan de estudio';

    protected static ?string $pluralModelLabel = 'planes de estudio';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Plan de estudio')
                    ->description('Programa académico, versión y vigencia del plan.')
                    ->schema([
                        Forms\Components\Select::make('programa_id')
                            ->label('Programa')
                            ->relationship('programa', 'nombre')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('nombre')
                            ->label('Nombre del plan')
                            ->required(),
                        Forms\Components\TextInput::make('version')
                            ->label('Versión')
                            ->required()
                            ->numeric(),
                        Forms\Components\DatePicker::make('vigente_desde')
                            ->label('Vigente desde')
                            ->required(),
                        Forms\Components\DatePicker::make('vigente_hasta')
                            ->label('Vigente hasta'),
                        Forms\Components\Toggle::make('activo')
                            ->label('Plan activo')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('programa.nombre')
                    ->label('Programa')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Plan de estudio')
                    ->searchable(),
                Tables\Columns\TextColumn::make('version')
                    ->label('Versión')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vigente_desde')
                    ->label('Vigente desde')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vigente_hasta')
                    ->label('Vigente hasta')
                    ->date()
                    ->sortable(),
                Tables\Columns\IconColumn::make('activo')
                    ->label('Activo')
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
                Tables\Filters\SelectFilter::make('programa')
                    ->label('Programa')
                    ->relationship('programa', 'nombre')
                    ->searchable()
                    ->preload(),
                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),
                static::dateRangeFilter('vigente_desde', 'Inicio de vigencia'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar plan de estudio'),
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
            ->emptyStateHeading('No hay planes de estudio')
            ->emptyStateDescription('Los planes registrados para los programas aparecerán aquí.')
            ->defaultSort('vigente_desde', 'desc');
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
            'index' => Pages\ListPlanEstudios::route('/'),
            'create' => Pages\CreatePlanEstudio::route('/create'),
            'edit' => Pages\EditPlanEstudio::route('/{record}/edit'),
        ];
    }
}
