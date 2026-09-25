<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaterialResource\Pages;
use App\Models\Curso;
use App\Models\Material;
use App\Services\MaterialService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MaterialResource extends Resource
{
    protected static ?string $model = Material::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Materiales';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('curso_id')
                    ->label('Curso')
                    ->relationship('curso', 'id')
                    ->getOptionLabelFromRecordUsing(fn (Curso $record) => "{$record->materia->nombre} ({$record->periodoAcademico->nombre})")
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('titulo')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('tipo')
                    ->options([
                        'documento' => 'Documento',
                        'enlace' => 'Enlace',
                        'tarea' => 'Tarea',
                        'anuncio' => 'Anuncio',
                    ])
                    ->required(),
                Forms\Components\Textarea::make('descripcion')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('url')
                    ->url()
                    ->maxLength(255),
                Forms\Components\TextInput::make('orden')
                    ->numeric()
                    ->default(0),
                Forms\Components\TextInput::make('canvas_item_id')
                    ->label('ID en Canvas')
                    ->disabled()
                    ->dehydrated()
                    ->helperText('Se completa con el botón "Publicar en Canvas".'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('curso.materia.nombre')
                    ->label('Curso')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('titulo')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tipo')
                    ->badge(),
                Tables\Columns\IconColumn::make('publicado')
                    ->label('Publicado en Canvas')
                    ->boolean()
                    ->getStateUsing(fn (Material $record) => $record->estaPublicadoEnCanvas()),
                Tables\Columns\TextColumn::make('orden')
                    ->sortable(),
            ])
            ->defaultSort('orden')
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('publicarEnCanvas')
                    ->label('Publicar en Canvas')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('info')
                    ->visible(fn (Material $record) => ! $record->estaPublicadoEnCanvas())
                    ->action(function (Material $record) {
                        try {
                            app(MaterialService::class)->publicarEnCanvas($record);
                            Notification::make()->title('Material publicado en Canvas')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error al publicar en Canvas')->body($e->getMessage())->danger()->send();
                        }
                    }),
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
            'index' => Pages\ListMaterials::route('/'),
            'create' => Pages\CreateMaterial::route('/create'),
            'edit' => Pages\EditMaterial::route('/{record}/edit'),
        ];
    }
}
