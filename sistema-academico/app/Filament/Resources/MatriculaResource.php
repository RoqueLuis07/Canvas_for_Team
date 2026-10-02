<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MatriculaResource\Pages;
use App\Models\Matricula;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MatriculaResource extends Resource
{
    protected static ?string $model = Matricula::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'Matriculación';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('persona_id')
                    ->relationship('persona', 'id')
                    ->required(),
                Forms\Components\Select::make('plan_estudio_id')
                    ->relationship('planEstudio', 'id')
                    ->required(),
                Forms\Components\Select::make('periodo_academico_id')
                    ->relationship('periodoAcademico', 'id')
                    ->required(),
                Forms\Components\Select::make('postulacion_id')
                    ->relationship('postulacion', 'id'),
                Forms\Components\TextInput::make('estado')
                    ->required(),
                Forms\Components\DatePicker::make('fecha_matricula')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('persona.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('planEstudio.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('periodoAcademico.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('postulacion.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('estado')
                    ->searchable(),
                Tables\Columns\TextColumn::make('fecha_matricula')
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
            'index' => Pages\ListMatriculas::route('/'),
            'create' => Pages\CreateMatricula::route('/create'),
            'edit' => Pages\EditMatricula::route('/{record}/edit'),
        ];
    }
}
