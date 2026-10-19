<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PersonaResource\Pages;
use App\Models\Persona;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;

class PersonaResource extends AdminOnlyResource
{
    protected static ?string $model = Persona::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Personas';

    protected static ?string $navigationGroup = 'Identidad académica';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'persona';

    protected static ?string $pluralModelLabel = 'personas';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos personales')
                    ->description('Información institucional y de contacto.')
                    ->schema([
                        Forms\Components\TextInput::make('nombre_completo')
                            ->label('Nombre completo')
                            ->required(),
                        Forms\Components\TextInput::make('cedula')
                            ->label('Cédula')
                            ->required(),
                        Forms\Components\TextInput::make('email_personal')
                            ->label('Correo personal')
                            ->email(),
                        Forms\Components\TextInput::make('email_institucional')
                            ->label('Correo institucional')
                            ->email(),
                        Forms\Components\TextInput::make('telefono')
                            ->label('Teléfono')
                            ->tel(),
                        Forms\Components\Select::make('tipo')
                            ->label('Tipo de persona')
                            ->options([
                                'aspirante' => 'Aspirante',
                                'alumno' => 'Estudiante',
                                'docente' => 'Docente',
                                'administrativo' => 'Administrativo',
                            ])
                            ->required(),
                        Forms\Components\Select::make('estado')
                            ->label('Estado')
                            ->options(['activo' => 'Activo', 'inactivo' => 'Inactivo'])
                            ->required(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Cuenta y rol')
                    ->description('Vínculo con el acceso al panel y rol institucional.')
                    ->schema([
                        Forms\Components\Select::make('user_id')
                            ->label('Cuenta de acceso')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('rol_id')
                            ->label('Rol')
                            ->relationship('rol', 'nombre')
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Identificadores de plataforma')
                    ->description('Identificadores técnicos usados para la sincronización con Canvas y Azure.')
                    ->schema([
                        Forms\Components\TextInput::make('canvas_user_id')
                            ->label('ID de usuario en Canvas')
                            ->helperText('Identificador técnico de la cuenta en Canvas.'),
                        Forms\Components\TextInput::make('azure_user_id')
                            ->label('ID de usuario en Azure')
                            ->helperText('Identificador técnico de la cuenta en Azure.'),
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
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Cuenta de acceso')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('nombre_completo')
                    ->label('Nombre completo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('cedula')
                    ->label('Cédula')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email_personal')
                    ->label('Correo personal')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email_institucional')
                    ->label('Correo institucional')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('telefono')
                    ->label('Teléfono')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'docente' => 'info',
                        'alumno' => 'success',
                        'administrativo' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('rol.nombre')
                    ->label('Rol')
                    ->placeholder('Sin asignar')
                    ->badge(),
                Tables\Columns\TextColumn::make('canvas_user_id')
                    ->label('ID de Canvas')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('azure_user_id')
                    ->label('ID de Azure')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'activo' ? 'success' : 'gray')
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
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo de persona')
                    ->options([
                        'aspirante' => 'Aspirante',
                        'alumno' => 'Estudiante',
                        'docente' => 'Docente',
                        'administrativo' => 'Administrativo',
                    ]),
                Tables\Filters\SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(['activo' => 'Activo', 'inactivo' => 'Inactivo']),
                Tables\Filters\SelectFilter::make('rol')
                    ->label('Rol')
                    ->relationship('rol', 'nombre')
                    ->searchable()
                    ->preload(),
                static::dateRangeFilter('created_at', 'Fecha de registro'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Editar')
                    ->icon('heroicon-o-pencil-square')
                    ->tooltip('Editar persona'),
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
            ->emptyStateHeading('No hay personas registradas')
            ->emptyStateDescription('Las personas vinculadas al sistema aparecerán aquí.')
            ->defaultSort('nombre_completo');
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
            'index' => Pages\ListPersonas::route('/'),
            'create' => Pages\CreatePersona::route('/create'),
            'edit' => Pages\EditPersona::route('/{record}/edit'),
        ];
    }
}
