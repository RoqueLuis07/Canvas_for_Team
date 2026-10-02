<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EquipoTeamsResource\Pages;
use App\Models\EquipoTeams;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class EquipoTeamsResource extends Resource
{
    protected static ?string $model = EquipoTeams::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Canvas ↔ Teams';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('teams_group_id')
                    ->required(),
                Forms\Components\Select::make('curso_id')
                    ->relationship('curso', 'canvas_course_id')
                    ->required(),
                Forms\Components\TextInput::make('nombre')
                    ->required(),
                Forms\Components\Select::make('visibilidad')
                    ->options(['Private' => 'Private', 'Public' => 'Public'])
                    ->required(),
                Forms\Components\DateTimePicker::make('fecha_creacion')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('teams_group_id')
                    ->searchable(),
                Tables\Columns\TextColumn::make('curso.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('nombre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('visibilidad')
                    ->searchable(),
                Tables\Columns\TextColumn::make('fecha_creacion')
                    ->dateTime()
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
            'index' => Pages\ListEquipoTeams::route('/'),
            'create' => Pages\CreateEquipoTeams::route('/create'),
            'edit' => Pages\EditEquipoTeams::route('/{record}/edit'),
        ];
    }
}
