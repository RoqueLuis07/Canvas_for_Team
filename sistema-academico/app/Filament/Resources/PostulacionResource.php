<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostulacionResource\Pages;
use App\Models\Postulacion;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PostulacionResource extends Resource
{
    protected static ?string $model = Postulacion::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Matriculación';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('persona_id')
                    ->relationship('persona', 'id')
                    ->required(),
                Forms\Components\Select::make('programa_id')
                    ->relationship('programa', 'id')
                    ->required(),
                Forms\Components\Select::make('periodo_academico_id')
                    ->relationship('periodoAcademico', 'id')
                    ->required(),
                Forms\Components\TextInput::make('estado')
                    ->required(),
                Forms\Components\DatePicker::make('fecha_postulacion')
                    ->required(),
                Forms\Components\Textarea::make('observaciones')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('persona.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('programa.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('periodoAcademico.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('estado')
                    ->searchable(),
                Tables\Columns\TextColumn::make('fecha_postulacion')
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
            'index' => Pages\ListPostulacions::route('/'),
            'create' => Pages\CreatePostulacion::route('/create'),
            'edit' => Pages\EditPostulacion::route('/{record}/edit'),
        ];
    }
}
