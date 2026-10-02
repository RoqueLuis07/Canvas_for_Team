<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MiembroEquipoResource\Pages;
use App\Models\MiembroEquipo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MiembroEquipoResource extends Resource
{
    protected static ?string $model = MiembroEquipo::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('equipo_id')
                    ->relationship('equipo', 'nombre')
                    ->required(),
                Forms\Components\Select::make('persona_id')
                    ->relationship('persona', 'nombre_completo')
                    ->required(),
                Forms\Components\Select::make('rol_teams')
                    ->options(['Owner' => 'Owner', 'Member' => 'Member'])
                    ->required(),
                Forms\Components\DateTimePicker::make('fecha_alta')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('equipo.nombre')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('persona.nombre_completo')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rol_teams')
                    ->searchable(),
                Tables\Columns\TextColumn::make('fecha_alta')
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
            'index' => Pages\ListMiembroEquipos::route('/'),
            'create' => Pages\CreateMiembroEquipo::route('/create'),
            'edit' => Pages\EditMiembroEquipo::route('/{record}/edit'),
        ];
    }
}
