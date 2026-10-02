<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProgramaResource\Pages;
use App\Models\Programa;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProgramaResource extends Resource
{
    protected static ?string $model = Programa::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Oferta académica';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('codigo')
                    ->required(),
                Forms\Components\TextInput::make('nombre')
                    ->required(),
                Forms\Components\TextInput::make('tipo')
                    ->required(),
                Forms\Components\TextInput::make('sede'),
                Forms\Components\TextInput::make('canvas_account_id'),
                Forms\Components\Toggle::make('activo')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('codigo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('nombre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tipo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('sede')
                    ->searchable(),
                Tables\Columns\TextColumn::make('canvas_account_id')
                    ->searchable(),
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
            'index' => Pages\ListProgramas::route('/'),
            'create' => Pages\CreatePrograma::route('/create'),
            'edit' => Pages\EditPrograma::route('/{record}/edit'),
        ];
    }
}
