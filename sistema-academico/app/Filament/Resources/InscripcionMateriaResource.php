<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InscripcionMateriaResource\Pages;
use App\Models\Curso;
use App\Models\InscripcionMateria;
use App\Services\InscripcionMateriaService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InscripcionMateriaResource extends AdminOnlyResource
{
    protected static ?string $model = InscripcionMateria::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Alta en materias';

    protected static ?string $navigationGroup = 'Matriculación';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'inscripción de materia';

    protected static ?string $pluralModelLabel = 'inscripciones de materias';

    public static function canViewAny(): bool
    {
        $user = static::currentUser();

        return $user?->isAdminTI() || $user?->isEstudiante();
    }

    public static function canView(Model $record): bool
    {
        $user = static::currentUser();

        return $user?->isAdminTI()
            || ($user?->isEstudiante() && $record->matricula?->persona_id === $user->activePersonaIdForRole('Estudiante'));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = static::currentUser();

        if ($user?->isAdminTI()) {
            return $query;
        }

        if ($user?->isEstudiante()) {
            return $query->whereHas('matricula', fn (Builder $enrollmentQuery) => $enrollmentQuery
                ->where('persona_id', $user->activePersonaIdForRole('Estudiante')));
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Inscripción')
                    ->description('Matrícula, curso, procedencia y estado académico.')
                    ->schema([
                        Forms\Components\Select::make('matricula_id')
                            ->label('Matrícula')
                            ->relationship('matricula', 'id')
                            ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->persona->nombre_completo} — {$record->periodoAcademico->nombre}")
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('curso_id')
                            ->label('Curso')
                            ->relationship('curso', 'id')
                            ->getOptionLabelFromRecordUsing(fn (Curso $record): string => "{$record->materia->codigo} · {$record->materia->nombre} — {$record->periodoAcademico->nombre}")
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('origen')
                            ->label('Origen')
                            ->options([
                                'manual' => 'Manual',
                                'predefinida' => 'Predefinida por el Departamento Académico',
                            ])
                            ->required(),
                        Forms\Components\Select::make('estado')
                            ->label('Estado')
                            ->options([
                                'inscrita' => 'Inscrita',
                                'retirada' => 'Retirada',
                                'aprobada' => 'Aprobada',
                                'reprobada' => 'Reprobada',
                            ])
                            ->required(),
                        Forms\Components\DatePicker::make('fecha_inscripcion')
                            ->label('Fecha de inscripción')
                            ->required(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Sincronización con plataformas')
                    ->description('Fechas de la última alta registrada en Canvas y Teams.')
                    ->schema([
                        Forms\Components\DateTimePicker::make('canvas_enrollment_at')
                            ->label('Alta en Canvas')
                            ->disabled(),
                        Forms\Components\DateTimePicker::make('teams_enrollment_at')
                            ->label('Alta en Teams')
                            ->disabled(),
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
                Tables\Columns\TextColumn::make('matricula.persona.nombre_completo')
                    ->label('Alumno')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('curso.materia.nombre')
                    ->label('Materia')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('curso.periodoAcademico.nombre')
                    ->label('Período académico')
                    ->sortable(),
                Tables\Columns\TextColumn::make('origen')
                    ->label('Origen')
                    ->badge(),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'inscrita' => 'info',
                        'aprobada' => 'success',
                        'reprobada' => 'danger',
                        'retirada' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('canvas_enrollment_at')
                    ->label('Canvas')
                    ->boolean()
                    ->getStateUsing(fn (InscripcionMateria $record) => $record->estaAlDiaEnCanvas()),
                Tables\Columns\IconColumn::make('teams_enrollment_at')
                    ->label('Teams')
                    ->boolean()
                    ->getStateUsing(fn (InscripcionMateria $record) => $record->estaAlDiaEnTeams()),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'inscrita' => 'Inscrita',
                        'retirada' => 'Retirada',
                        'aprobada' => 'Aprobada',
                        'reprobada' => 'Reprobada',
                    ]),
                Tables\Filters\SelectFilter::make('origen')
                    ->label('Origen')
                    ->options([
                        'manual' => 'Manual',
                        'predefinida' => 'Predefinida por el Departamento Académico',
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
                static::dateRangeFilter('fecha_inscripcion', 'Fecha de inscripción'),
            ])
            ->actions([
                Tables\Actions\Action::make('darDeBaja')
                    ->label('Dar de baja')
                    ->icon('heroicon-o-x-circle')
                    ->tooltip('Retirar al alumno de esta materia')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (InscripcionMateria $record) => auth()->user()?->isAdminTI() && $record->estado !== 'retirada')
                    ->action(function (InscripcionMateria $record) {
                        abort_unless(auth()->user()?->isAdminTI(), 403);

                        try {
                            app(InscripcionMateriaService::class)->darDeBaja($record);
                            Notification::make()->title('Alumno dado de baja de la materia')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error al dar de baja')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('cambiarMateria')
                    ->label('Cambiar de materia')
                    ->icon('heroicon-o-arrow-path')
                    ->tooltip('Cambiar la inscripción a otro curso del mismo período')
                    ->color('warning')
                    ->visible(fn (InscripcionMateria $record) => auth()->user()?->isAdminTI() && $record->estado === 'inscrita')
                    ->form([
                        Forms\Components\Select::make('curso_nuevo_id')
                            ->label('Nuevo curso')
                            ->options(fn (InscripcionMateria $record) => Curso::query()
                                ->where('periodo_academico_id', $record->curso->periodo_academico_id)
                                ->where('id', '!=', $record->curso_id)
                                ->with('materia')
                                ->get()
                                ->mapWithKeys(fn (Curso $c) => [$c->id => "{$c->materia->codigo} · {$c->materia->nombre}"]))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (InscripcionMateria $record, array $data) {
                        abort_unless(auth()->user()?->isAdminTI(), 403);

                        try {
                            app(InscripcionMateriaService::class)->cambiarMateria(
                                $record,
                                Curso::findOrFail($data['curso_nuevo_id']),
                            );
                            Notification::make()->title('Materia cambiada correctamente')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error al cambiar de materia')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar inscripción'),
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
            ->emptyStateHeading('No hay inscripciones de materias')
            ->emptyStateDescription('Las inscripciones asociadas a matrículas y cursos aparecerán aquí.')
            ->defaultSort('fecha_inscripcion', 'desc');
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
            'index' => Pages\ListInscripcionMaterias::route('/'),
            'create' => Pages\CreateInscripcionMateria::route('/create'),
            'edit' => Pages\EditInscripcionMateria::route('/{record}/edit'),
        ];
    }
}
