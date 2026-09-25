<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CursoResource\Pages;
use App\Models\Curso;
use App\Models\Persona;
use App\Services\CursoService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CursoResource extends Resource
{
    protected static ?string $model = Curso::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Cursos (Canvas/Teams)';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('materia_id')
                    ->relationship('materia', 'nombre')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\Select::make('periodo_academico_id')
                    ->relationship('periodoAcademico', 'nombre')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('cupo_maximo')
                    ->numeric()
                    ->helperText('Vacío = sin límite de cupo.'),
                Forms\Components\TextInput::make('canvas_course_id')
                    ->label('ID de curso en Canvas')
                    ->disabled()
                    ->dehydrated()
                    ->helperText('Se completa con el botón "Crear en Canvas".'),
                Forms\Components\TextInput::make('teams_group_id')
                    ->label('ID de equipo en Teams')
                    ->disabled()
                    ->dehydrated()
                    ->helperText('Se completa con el botón "Crear en Teams".'),
                Forms\Components\Select::make('estado')
                    ->options([
                        'planificado' => 'Planificado',
                        'publicado' => 'Publicado',
                        'cerrado' => 'Cerrado',
                    ])
                    ->required(),
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
                    ->label('Período')
                    ->sortable(),
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
                    ->label('Cupo')
                    ->getStateUsing(fn (Curso $record) => $record->cupo_maximo === null
                        ? 'Sin límite'
                        : "{$record->cupoDisponible()} / {$record->cupo_maximo}"),
                Tables\Columns\TextColumn::make('estado')
                    ->badge(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\Action::make('crearEnCanvas')
                    ->label('Crear en Canvas')
                    ->icon('heroicon-o-academic-cap')
                    ->color('info')
                    ->visible(fn (Curso $record) => ! $record->estaCreadoEnCanvas())
                    ->action(function (Curso $record) {
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
                    ->color('info')
                    ->visible(fn (Curso $record) => ! $record->estaCreadoEnTeams())
                    ->action(function (Curso $record) {
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
                    ->color('success')
                    ->visible(fn (Curso $record) => $record->estaCreadoEnCanvas() && $record->estaCreadoEnTeams())
                    ->form([
                        Forms\Components\Select::make('docente_persona_id')
                            ->label('Docente')
                            ->options(fn () => Persona::query()->where('tipo', 'docente')->pluck('nombre_completo', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Curso $record, array $data) {
                        try {
                            app(CursoService::class)->asignarDocente($record, Persona::findOrFail($data['docente_persona_id']));
                            Notification::make()->title('Docente asignado en Canvas y Teams')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Error al asignar docente')->body($e->getMessage())->danger()->send();
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
            'index' => Pages\ListCursos::route('/'),
            'create' => Pages\CreateCurso::route('/create'),
            'edit' => Pages\EditCurso::route('/{record}/edit'),
        ];
    }
}
