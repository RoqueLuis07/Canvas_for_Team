<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProgramaResource\Pages;
use App\Models\Programa;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class ProgramaResource extends AdminOnlyResource
{
    protected static ?string $model = Programa::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Programas';

    protected static ?string $navigationGroup = 'Oferta académica';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'programa';

    protected static ?string $pluralModelLabel = 'programas';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Programa académico')
                    ->description('Identificación, nivel académico y sede del programa.')
                    ->schema([
                        Forms\Components\TextInput::make('codigo')
                            ->label('Código')
                            ->required(),
                        Forms\Components\TextInput::make('nombre')
                            ->label('Nombre del programa')
                            ->required(),
                        Forms\Components\Select::make('tipo')
                            ->label('Tipo de programa')
                            ->options([
                                'grado' => 'Grado',
                                'mba' => 'MBA',
                                'diplomado' => 'Diplomado',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('sede')
                            ->label('Sede'),
                        Forms\Components\Toggle::make('activo')
                            ->label('Programa activo')
                            ->required(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Integración con Canvas')
                    ->description('Cuenta de Canvas asociada al programa.')
                    ->schema([
                        Forms\Components\TextInput::make('canvas_account_id')
                            ->label('ID de cuenta en Canvas')
                            ->helperText('Identificador de la cuenta de Canvas vinculada a este programa.'),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('codigo')
                    ->label('Código')
                    ->searchable(),
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Programa')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'grado' => 'info',
                        'mba' => 'primary',
                        'diplomado' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('sede')
                    ->label('Sede')
                    ->searchable(),
                Tables\Columns\TextColumn::make('canvas_account_id')
                    ->label('ID de cuenta en Canvas')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo de programa')
                    ->options([
                        'grado' => 'Grado',
                        'mba' => 'MBA',
                        'diplomado' => 'Diplomado',
                    ]),
                Tables\Filters\TernaryFilter::make('activo')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),
                static::dateRangeFilter('created_at', 'Fecha de registro'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar programa'),
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
            ->emptyStateHeading('No hay programas')
            ->emptyStateDescription('Los programas académicos disponibles aparecerán aquí.')
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
            'index' => Pages\ListProgramas::route('/'),
            'create' => Pages\CreatePrograma::route('/create'),
            'edit' => Pages\EditPrograma::route('/{record}/edit'),
        ];
    }
}
