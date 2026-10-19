<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EquipoTeamsResource\Pages;
use App\Models\Curso;
use App\Models\EquipoTeams;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class EquipoTeamsResource extends AdminOnlyResource
{
    protected static ?string $model = EquipoTeams::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Equipos de Teams';

    protected static ?string $navigationGroup = 'Canvas ↔ Teams';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'equipo de Teams';

    protected static ?string $pluralModelLabel = 'equipos de Teams';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos del equipo')
                    ->description('Equipo de Teams asociado a una oferta académica.')
                    ->schema([
                        Forms\Components\Select::make('curso_id')
                            ->label('Curso')
                            ->relationship('curso', 'id')
                            ->getOptionLabelFromRecordUsing(fn ($record): string => "{$record->materia->codigo} · {$record->materia->nombre} — {$record->periodoAcademico->nombre}")
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('nombre')
                            ->label('Nombre del equipo')
                            ->required(),
                        Forms\Components\Select::make('visibilidad')
                            ->label('Visibilidad')
                            ->options(['Private' => 'Privado', 'Public' => 'Público'])
                            ->required(),
                        Forms\Components\DateTimePicker::make('fecha_creacion')
                            ->label('Fecha de creación')
                            ->required(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Identificador de Teams')
                    ->description('Identificador técnico del grupo en Microsoft Teams.')
                    ->schema([
                        Forms\Components\TextInput::make('teams_group_id')
                            ->label('ID del grupo')
                            ->helperText('Mantén el valor que identifica a este equipo en Teams.')
                            ->required(),
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
                Tables\Columns\TextColumn::make('teams_group_id')
                    ->label('ID de Teams')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('curso.materia.nombre')
                    ->label('Materia')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('nombre')
                    ->label('Nombre del equipo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('visibilidad')
                    ->label('Visibilidad')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Public' ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('fecha_creacion')
                    ->label('Fecha de creación')
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
                Tables\Filters\SelectFilter::make('visibilidad')
                    ->label('Visibilidad')
                    ->options(['Private' => 'Privado', 'Public' => 'Público']),
                Tables\Filters\SelectFilter::make('curso_id')
                    ->label('Curso')
                    ->options(fn (): array => Curso::query()
                        ->with(['materia', 'periodoAcademico'])
                        ->get()
                        ->mapWithKeys(fn (Curso $curso): array => [
                            $curso->id => "{$curso->materia->codigo} · {$curso->materia->nombre} — {$curso->periodoAcademico->nombre}",
                        ])
                        ->all())
                    ->searchable(),
                static::dateRangeFilter('fecha_creacion', 'Fecha de creación'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar equipo de Teams'),
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
            ->emptyStateHeading('No hay equipos de Teams')
            ->emptyStateDescription('Los equipos asociados a cursos aparecerán aquí.')
            ->defaultSort('fecha_creacion', 'desc');
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
            'index' => Pages\ListEquipoTeams::route('/'),
            'create' => Pages\CreateEquipoTeams::route('/create'),
            'edit' => Pages\EditEquipoTeams::route('/{record}/edit'),
        ];
    }
}
