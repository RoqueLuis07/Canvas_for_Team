<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InscripcionMateriaResource\Pages;
use App\Models\Curso;
use App\Models\InscripcionMateria;
use App\Services\InscripcionMateriaService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InscripcionMateriaResource extends Resource
{
    protected static ?string $model = InscripcionMateria::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Alta en materias';

    protected static ?string $navigationGroup = 'Matriculación';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('matricula_id')
                    ->relationship('matricula', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->persona->nombre_completo} — {$record->periodoAcademico->nombre}")
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('curso_id')
                    ->label('Curso')
                    ->relationship('curso', 'id')
                    ->getOptionLabelFromRecordUsing(fn (Curso $record) => "{$record->materia->nombre} ({$record->periodoAcademico->nombre})")
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('origen')
                    ->options([
                        'manual' => 'Manual',
                        'predefinida' => 'Predefinida (Departamento Académico)',
                    ])
                    ->required(),
                Forms\Components\Select::make('estado')
                    ->options([
                        'inscrita' => 'Inscrita',
                        'retirada' => 'Retirada',
                        'aprobada' => 'Aprobada',
                        'reprobada' => 'Reprobada',
                    ])
                    ->required(),
                Forms\Components\DatePicker::make('fecha_inscripcion')
                    ->required(),
                Forms\Components\DateTimePicker::make('canvas_enrollment_at')
                    ->label('Alta en Canvas')
                    ->disabled(),
                Forms\Components\DateTimePicker::make('teams_enrollment_at')
                    ->label('Alta en Teams')
                    ->disabled(),
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
                    ->label('Período')
                    ->sortable(),
                Tables\Columns\TextColumn::make('origen')
                    ->badge(),
                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'inscrita' => 'info',
                        'aprobada' => 'success',
                        'reprobada', 'retirada' => 'danger',
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
                //
            ])
            ->actions([
                Tables\Actions\Action::make('darDeBaja')
                    ->label('Dar de baja')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (InscripcionMateria $record) => $record->estado !== 'retirada')
                    ->action(function (InscripcionMateria $record) {
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
                    ->color('warning')
                    ->visible(fn (InscripcionMateria $record) => $record->estado === 'inscrita')
                    ->form([
                        Forms\Components\Select::make('curso_nuevo_id')
                            ->label('Nuevo curso')
                            ->options(fn (InscripcionMateria $record) => Curso::query()
                                ->where('periodo_academico_id', $record->curso->periodo_academico_id)
                                ->where('id', '!=', $record->curso_id)
                                ->with('materia')
                                ->get()
                                ->mapWithKeys(fn (Curso $c) => [$c->id => $c->materia->nombre]))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (InscripcionMateria $record, array $data) {
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
            'index' => Pages\ListInscripcionMaterias::route('/'),
            'create' => Pages\CreateInscripcionMateria::route('/create'),
            'edit' => Pages\EditInscripcionMateria::route('/{record}/edit'),
        ];
    }
}
