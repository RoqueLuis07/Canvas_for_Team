<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CursoResource\Pages;
use App\Models\Curso;
use App\Models\Materia;
use App\Models\PeriodoAcademico;
use App\Models\Persona;
use App\Services\CursoService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CursoResource extends AdminOnlyResource
{
    protected static ?string $model = Curso::class;

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationLabel = 'Cursos';

    protected static ?string $navigationGroup = 'Canvas ↔ Teams';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'curso';

    protected static ?string $pluralModelLabel = 'cursos';

    public static function canViewAny(): bool
    {
        $user = static::currentUser();

        return $user?->isAdminTI() || $user?->isDocente();
    }

    public static function canView(Model $record): bool
    {
        $user = static::currentUser();

        return $user?->isAdminTI()
            || ($user?->isDocente() && $record->docente_persona_id === $user->activePersonaIdForRole('Docente'));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = static::currentUser();

        if ($user?->isAdminTI()) {
            return $query;
        }

        if ($user?->isDocente()) {
            return $query->where('docente_persona_id', $user->activePersonaIdForRole('Docente'));
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Oferta académica')
                    ->description('Materia, período y capacidad del curso.')
                    ->schema([
                        Forms\Components\Select::make('materia_id')
                            ->label('Materia')
                            ->relationship('materia', 'nombre')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('periodo_academico_id')
                            ->label('Período académico')
                            ->relationship('periodoAcademico', 'nombre')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('subcuenta_id')
                            ->label('Subcuenta de Canvas')
                            ->relationship('subcuenta', 'nombre')
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('cupo_maximo')
                            ->label('Cupo máximo')
                            ->numeric()
                            ->helperText('Déjalo vacío si no hay límite de cupo.'),
                        Forms\Components\Select::make('estado')
                            ->label('Estado')
                            ->options([
                                'planificado' => 'Planificado',
                                'publicado' => 'Publicado',
                                'cerrado' => 'Cerrado',
                            ])
                            ->required(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Integración con Canvas y Teams')
                    ->description('Los identificadores se completan al crear el curso y el equipo en sus plataformas.')
                    ->schema([
                        Forms\Components\TextInput::make('canvas_course_id')
                            ->label('ID de curso en Canvas')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Se completa con la acción «Crear en Canvas».'),
                        Forms\Components\TextInput::make('teams_group_id')
                            ->label('ID de equipo en Teams')
                            ->disabled()
                            ->dehydrated()
                            ->helperText('Se completa con la acción «Crear en Teams».'),
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
                Tables\Columns\TextColumn::make('materia.nombre')
                    ->label('Materia')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('periodoAcademico.nombre')
                    ->label('Período académico')
                    ->sortable(),
                Tables\Columns\TextColumn::make('subcuenta.nombre')
                    ->label('Subcuenta')
                    ->placeholder('Sin asignar')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('canvas_course_id')
                    ->label('Canvas')
                    ->boolean()
                    ->getStateUsing(fn (Curso $record) => $record->estaCreadoEnCanvas()),
                Tables\Columns\IconColumn::make('teams_group_id')
                    ->label('Teams')
                    ->boolean()
                    ->getStateUsing(fn (Curso $record) => $record->estaCreadoEnTeams()),
                Tables\Columns\TextColumn::make('docente.nombre_completo')
                    ->label('Docente')
                    ->placeholder('Sin asignar')
                    ->searchable(),
                Tables\Columns\TextColumn::make('cupo_maximo')
                    ->label('Cupos disponibles')
                    ->getStateUsing(fn (Curso $record) => $record->cupo_maximo === null
                        ? 'Sin límite'
                        : "{$record->cupoDisponible()} / {$record->cupo_maximo}"),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'planificado' => 'warning',
                        'publicado' => 'success',
                        'cerrado' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'planificado' => 'Planificado',
                        'publicado' => 'Publicado',
                        'cerrado' => 'Cerrado',
                    ]),
                Tables\Filters\SelectFilter::make('materia_id')
                    ->label('Materia')
                    ->options(fn (): array => Materia::query()
                        ->whereIn('id', static::getEloquentQuery()->distinct()->pluck('materia_id'))
                        ->orderBy('nombre')
                        ->get()
                        ->mapWithKeys(fn (Materia $materia): array => [
                            $materia->id => "{$materia->codigo} · {$materia->nombre}",
                        ])
                        ->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('periodo_academico_id')
                    ->label('Período académico')
                    ->options(fn (): array => PeriodoAcademico::query()
                        ->whereIn('id', static::getEloquentQuery()->distinct()->pluck('periodo_academico_id'))
                        ->orderByDesc('fecha_inicio')
                        ->pluck('nombre', 'id')
                        ->all())
                    ->searchable(),
                static::dateRangeFilter('created_at', 'Fecha de creación'),
            ])
            ->actions([
                Tables\Actions\Action::make('crearEnCanvas')
                    ->label('Crear en Canvas')
                    ->icon('heroicon-o-academic-cap')
                    ->tooltip('Crear este curso en Canvas')
                    ->color('info')
                    ->visible(fn (Curso $record) => auth()->user()?->isAdminTI() && ! $record->estaCreadoEnCanvas())
                    ->action(function (Curso $record) {
                        abort_unless(auth()->user()?->isAdminTI(), 403);

                        try {
                            app(CursoService::class)->crearEnCanvas($record);
                            Notification::make()->title('Curso creado en Canvas')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error al crear en Canvas')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('crearEnTeams')
                    ->label('Crear en Teams')
                    ->icon('heroicon-o-user-group')
                    ->tooltip('Crear el equipo del curso en Teams')
                    ->color('info')
                    ->visible(fn (Curso $record) => auth()->user()?->isAdminTI() && ! $record->estaCreadoEnTeams())
                    ->action(function (Curso $record) {
                        abort_unless(auth()->user()?->isAdminTI(), 403);

                        try {
                            app(CursoService::class)->crearEnTeams($record);
                            Notification::make()->title('Equipo creado en Teams')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error al crear en Teams')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('asignarDocente')
                    ->label('Asignar docente')
                    ->icon('heroicon-o-user-plus')
                    ->tooltip('Asignar al docente en Canvas y Teams')
                    ->color('success')
                    ->visible(fn (Curso $record) => auth()->user()?->isAdminTI() && $record->estaCreadoEnCanvas() && $record->estaCreadoEnTeams())
                    ->form([
                        Forms\Components\Select::make('docente_persona_id')
                            ->label('Docente')
                            ->helperText('Selecciona una persona con perfil docente.')
                            ->options(fn () => Persona::query()->where('tipo', 'docente')->pluck('nombre_completo', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Curso $record, array $data) {
                        abort_unless(auth()->user()?->isAdminTI(), 403);

                        try {
                            app(CursoService::class)->asignarDocente($record, Persona::findOrFail($data['docente_persona_id']));
                            Notification::make()->title('Docente asignado en Canvas y Teams')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error al asignar docente')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar curso'),
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
            ->emptyStateHeading('No hay cursos')
            ->emptyStateDescription('Los cursos creados para Canvas y Teams aparecerán aquí.')
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListCursos::route('/'),
            'create' => Pages\CreateCurso::route('/create'),
            'edit' => Pages\EditCurso::route('/{record}/edit'),
        ];
    }
}
