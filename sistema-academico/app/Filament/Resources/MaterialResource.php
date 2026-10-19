<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MaterialResource\Pages;
use App\Models\Curso;
use App\Models\Material;
use App\Services\MaterialService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MaterialResource extends AdminOnlyResource
{
    protected static ?string $model = Material::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Materiales';

    protected static ?string $navigationGroup = 'Canvas ↔ Teams';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'material';

    protected static ?string $pluralModelLabel = 'materiales';

    public static function canViewAny(): bool
    {
        $user = static::currentUser();

        return $user?->isAdminTI() || $user?->isDocente();
    }

    public static function canView(Model $record): bool
    {
        $user = static::currentUser();

        return $user?->isAdminTI()
            || ($user?->isDocente() && $record->curso?->docente_persona_id === $user->activePersonaIdForRole('Docente'));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = static::currentUser();

        if ($user?->isAdminTI()) {
            return $query;
        }

        if ($user?->isDocente()) {
            return $query->whereHas('curso', fn (Builder $courseQuery) => $courseQuery
                ->where('docente_persona_id', $user->activePersonaIdForRole('Docente')));
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Material académico')
                    ->description('Contenido asociado a una oferta de curso.')
                    ->schema([
                        Forms\Components\Select::make('curso_id')
                            ->label('Curso')
                            ->relationship('curso', 'id')
                            ->getOptionLabelFromRecordUsing(fn (Curso $record): string => "{$record->materia->codigo} · {$record->materia->nombre} — {$record->periodoAcademico->nombre}")
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('titulo')
                            ->label('Título')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('tipo')
                            ->label('Tipo de material')
                            ->options([
                                'documento' => 'Documento',
                                'enlace' => 'Enlace',
                                'tarea' => 'Tarea',
                                'anuncio' => 'Anuncio',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('url')
                            ->label('Enlace')
                            ->url()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('orden')
                            ->label('Orden de publicación')
                            ->numeric()
                            ->default(0),
                        Forms\Components\Textarea::make('descripcion')
                            ->label('Descripción')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Publicación en Canvas')
                    ->description('El identificador se asigna al publicar el material en Canvas.')
                    ->schema([
                        Forms\Components\TextInput::make('canvas_item_id')
                            ->label('ID del material en Canvas')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Se completa con la acción «Publicar en Canvas».'),
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
                Tables\Columns\TextColumn::make('curso.materia.nombre')
                    ->label('Curso')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('titulo')
                    ->label('Título')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'documento' => 'info',
                        'enlace' => 'primary',
                        'tarea' => 'warning',
                        'anuncio' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('publicado')
                    ->label('Publicado en Canvas')
                    ->boolean()
                    ->getStateUsing(fn (Material $record) => $record->estaPublicadoEnCanvas()),
                Tables\Columns\TextColumn::make('orden')
                    ->label('Orden')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo de material')
                    ->options([
                        'documento' => 'Documento',
                        'enlace' => 'Enlace',
                        'tarea' => 'Tarea',
                        'anuncio' => 'Anuncio',
                    ]),
                Tables\Filters\SelectFilter::make('curso_id')
                    ->label('Curso')
                    ->options(fn (): array => Curso::query()
                        ->whereIn('id', static::getEloquentQuery()->distinct()->pluck('curso_id'))
                        ->with(['materia', 'periodoAcademico'])
                        ->get()
                        ->mapWithKeys(fn (Curso $curso): array => [
                            $curso->id => "{$curso->materia->codigo} · {$curso->materia->nombre} — {$curso->periodoAcademico->nombre}",
                        ])
                        ->all())
                    ->searchable(),
                static::dateRangeFilter('created_at', 'Fecha de registro'),
            ])
            ->actions([
                Tables\Actions\Action::make('publicarEnCanvas')
                    ->label('Publicar en Canvas')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->tooltip('Publicar este material en Canvas')
                    ->color('info')
                    ->visible(fn (Material $record) => auth()->user()?->isAdminTI() && ! $record->estaPublicadoEnCanvas())
                    ->action(function (Material $record) {
                        abort_unless(auth()->user()?->isAdminTI(), 403);

                        try {
                            app(MaterialService::class)->publicarEnCanvas($record);
                            Notification::make()->title('Material publicado en Canvas')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error al publicar en Canvas')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar material'),
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
            ->emptyStateHeading('No hay materiales')
            ->emptyStateDescription('Los materiales académicos registrados aparecerán aquí.')
            ->defaultSort('orden');
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
