<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MiembroEquipoResource\Pages;
use App\Models\MiembroEquipo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class MiembroEquipoResource extends AdminOnlyResource
{
    protected static ?string $model = MiembroEquipo::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'Miembros de equipos';

    protected static ?string $navigationGroup = 'Canvas ↔ Teams';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'miembro de equipo';

    protected static ?string $pluralModelLabel = 'miembros de equipos';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Miembro del equipo')
                    ->description('Persona, equipo y rol que tendrá en Teams.')
                    ->schema([
                        Forms\Components\Select::make('equipo_id')
                            ->label('Equipo de Teams')
                            ->relationship('equipo', 'nombre')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('persona_id')
                            ->label('Persona')
                            ->relationship('persona', 'nombre_completo')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\Select::make('rol_teams')
                            ->label('Rol en Teams')
                            ->options(['Owner' => 'Propietario', 'Member' => 'Miembro'])
                            ->required(),
                        Forms\Components\DateTimePicker::make('fecha_alta')
                            ->label('Fecha de incorporación')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('equipo.nombre')
                    ->label('Equipo')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('persona.nombre_completo')
                    ->label('Persona')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rol_teams')
                    ->label('Rol en Teams')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'Owner' ? 'Propietario' : 'Miembro')
                    ->color(fn (string $state): string => $state === 'Owner' ? 'success' : 'info'),
                Tables\Columns\TextColumn::make('fecha_alta')
                    ->label('Fecha de incorporación')
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
                Tables\Filters\SelectFilter::make('rol_teams')
                    ->label('Rol en Teams')
                    ->options(['Owner' => 'Propietario', 'Member' => 'Miembro']),
                Tables\Filters\SelectFilter::make('equipo')
                    ->label('Equipo')
                    ->relationship('equipo', 'nombre')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('persona')
                    ->label('Persona')
                    ->relationship('persona', 'nombre_completo')
                    ->searchable()
                    ->preload(),
                static::dateRangeFilter('fecha_alta', 'Fecha de incorporación'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar miembro de equipo'),
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
            ->emptyStateHeading('No hay miembros de equipos')
            ->emptyStateDescription('Las personas asociadas a equipos de Teams aparecerán aquí.')
            ->defaultSort('fecha_alta', 'desc');
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
            'index' => Pages\ListMiembroEquipos::route('/'),
            'create' => Pages\CreateMiembroEquipo::route('/create'),
            'edit' => Pages\EditMiembroEquipo::route('/{record}/edit'),
        ];
    }
}
