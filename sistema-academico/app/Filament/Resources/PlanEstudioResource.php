<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanEstudioResource\Pages;
use App\Models\PlanEstudio;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PlanEstudioResource extends Resource
{
    protected static ?string $model = PlanEstudio::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Oferta académica';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('programa_id')
                    ->relationship('programa', 'id')
                    ->required(),
                Forms\Components\TextInput::make('nombre')
                    ->required(),
                Forms\Components\TextInput::make('version')
                    ->required()
                    ->numeric(),
                Forms\Components\DatePicker::make('vigente_desde')
                    ->required(),
                Forms\Components\DatePicker::make('vigente_hasta'),
                Forms\Components\Toggle::make('activo')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('programa.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('nombre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('version')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vigente_desde')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vigente_hasta')
                    ->date()
                    ->sortable(),
                Tables\Columns\IconColumn::make('activo')
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
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
