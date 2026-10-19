<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MatriculaResource\Pages;
use App\Models\Matricula;
use App\Models\PeriodoAcademico;
use App\Models\Persona;
use App\Models\PlanEstudio;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MatriculaResource extends AdminOnlyResource
{
    protected static ?string $model = Matricula::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationLabel = 'Matrículas';

    protected static ?string $navigationGroup = 'Matriculación';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'matrícula';

    protected static ?string $pluralModelLabel = 'matrículas';

    public static function canViewAny(): bool
    {
        $user = static::currentUser();

        return $user?->isAdminTI() || $user?->isEstudiante();
    }

    public static function canView(Model $record): bool
    {
        $user = static::currentUser();

        return $user?->isAdminTI()
            || ($user?->isEstudiante() && $record->persona_id === $user->activePersonaIdForRole('Estudiante'));
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = static::currentUser();

        if ($user?->isAdminTI()) {
            return $query;
        }

        if ($user?->isEstudiante()) {
            return $query->where('persona_id', $user->activePersonaIdForRole('Estudiante'));
        }

        return $query->whereRaw('1 = 0');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos de la matrícula')
                    ->description('Alumno, plan de estudio, período y estado de matrícula.')
                    ->schema([
                        Forms\Components\Select::make('persona_id')
                            ->label('Alumno')
                            ->relationship('persona', 'nombre_completo')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('plan_estudio_id')
                            ->label('Plan de estudio')
                            ->relationship('planEstudio', 'nombre')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('periodo_academico_id')
                            ->label('Período académico')
                            ->relationship('periodoAcademico', 'nombre')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('postulacion_id')
                            ->label('Postulación de origen')
                            ->relationship('postulacion', 'fecha_postulacion')
                            ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->persona->nombre_completo} — {$record->fecha_postulacion->format('d/m/Y')}")
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('estado')
                            ->label('Estado')
                            ->options([
                                'activa' => 'Activa',
                                'retirada' => 'Retirada',
                                'finalizada' => 'Finalizada',
                            ])
                            ->required(),
                        Forms\Components\DatePicker::make('fecha_matricula')
                            ->label('Fecha de matrícula')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('persona.nombre_completo')
                    ->label('Alumno')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('planEstudio.nombre')
                    ->label('Plan de estudio')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('periodoAcademico.nombre')
                    ->label('Período académico')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('postulacion.fecha_postulacion')
                    ->label('Fecha de postulación')
                    ->date()
                    ->placeholder('No aplica')
                    ->sortable(),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'activa' => 'success',
                        'retirada' => 'gray',
                        'finalizada' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('fecha_matricula')
                    ->label('Fecha de matrícula')
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
                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options([
                        'activa' => 'Activa',
                        'retirada' => 'Retirada',
                        'finalizada' => 'Finalizada',
                    ]),
                Tables\Filters\SelectFilter::make('persona_id')
                    ->label('Alumno')
                    ->options(fn (): array => Persona::query()
                        ->whereIn('id', static::getEloquentQuery()->distinct()->pluck('persona_id'))
                        ->orderBy('nombre_completo')
                        ->pluck('nombre_completo', 'id')
                        ->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('plan_estudio_id')
                    ->label('Plan de estudio')
                    ->options(fn (): array => PlanEstudio::query()
                        ->whereIn('id', static::getEloquentQuery()->distinct()->pluck('plan_estudio_id'))
                        ->orderBy('nombre')
                        ->pluck('nombre', 'id')
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
                static::dateRangeFilter('fecha_matricula', 'Fecha de matrícula'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar matrícula'),
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
            ->emptyStateHeading('No hay matrículas registradas')
            ->emptyStateDescription('Las matrículas aparecerán aquí al ser creadas.')
            ->defaultSort('fecha_matricula', 'desc');
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
