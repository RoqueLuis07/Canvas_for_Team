<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubcuentaResource\Pages;
use App\Models\Subcuenta;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class SubcuentaResource extends AdminOnlyResource
{
    protected static ?string $model = Subcuenta::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Subcuentas de Canvas';

    protected static ?string $navigationGroup = 'Identidad académica';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'subcuenta';

    protected static ?string $pluralModelLabel = 'subcuentas';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos de la subcuenta')
                    ->description('Organización académica asociada a una sede o carrera.')
                    ->schema([
                        Forms\Components\TextInput::make('nombre')
                            ->label('Nombre')
                            ->required(),
                        Forms\Components\TextInput::make('carrera')
                            ->label('Carrera'),
                        Forms\Components\TextInput::make('sede')
                            ->label('Sede')
                            ->required(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Integración con Canvas')
                    ->description('Identificador de la subcuenta en Canvas.')
                    ->schema([
                        Forms\Components\TextInput::make('canvas_account_id')
                            ->label('ID de cuenta en Canvas')
                            ->required()
                            ->numeric()
                            ->helperText('Usa el identificador numérico proporcionado por Canvas.'),
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
                Tables\Columns\TextColumn::make('canvas_account_id')
                    ->label('ID de Canvas')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Subcuenta')
                    ->searchable(),
                Tables\Columns\TextColumn::make('carrera')
                    ->label('Carrera')
                    ->searchable(),
                Tables\Columns\TextColumn::make('sede')
                    ->label('Sede')
                    ->searchable(),
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
                Tables\Filters\SelectFilter::make('sede')
                    ->label('Sede')
                    ->options(fn (): array => Subcuenta::query()
                        ->select('sede')
                        ->distinct()
                        ->orderBy('sede')
                        ->pluck('sede', 'sede')
                        ->all())
                    ->searchable(),
                static::dateRangeFilter('created_at', 'Fecha de registro'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar subcuenta'),
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
            ->emptyStateHeading('No hay subcuentas')
            ->emptyStateDescription('Las subcuentas de Canvas aparecerán aquí.')
            ->defaultSort('nombre');
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
            'index' => Pages\ListSubcuentas::route('/'),
            'create' => Pages\CreateSubcuenta::route('/create'),
            'edit' => Pages\EditSubcuenta::route('/{record}/edit'),
        ];
    }
}
