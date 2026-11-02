<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActaServicioTecnicoResource\Pages;
use App\Models\ActaServicioTecnico;
use App\Services\Actas\ActaServicioTecnicoService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ActaServicioTecnicoResource extends AdminOnlyResource
{
    protected static ?string $model = ActaServicioTecnico::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationLabel = 'Servicio Técnico';

    protected static ?string $navigationGroup = 'Actas';

    protected static ?string $modelLabel = 'acta de servicio técnico';

    protected static ?string $pluralModelLabel = 'actas de servicio técnico';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos del servicio')
                    ->schema([
                        Forms\Components\DatePicker::make('fecha')->required(),
                        Forms\Components\TextInput::make('hora'),
                        Forms\Components\TextInput::make('departamento_area')->label('Departamento/Área'),
                        Forms\Components\TextInput::make('ubicacion_oficina')->label('Ubicación/Oficina'),
                        Forms\Components\TextInput::make('persona_reporta')->label('Persona que reporta'),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Equipo')
                    ->schema([
                        Forms\Components\TextInput::make('tipo_equipo')->label('Tipo de equipo'),
                        Forms\Components\TextInput::make('tipo_equipo_otro')->label('Tipo de equipo (otro)'),
                        Forms\Components\TextInput::make('marca'),
                        Forms\Components\TextInput::make('modelo'),
                        Forms\Components\TextInput::make('nro_serie')->label('N.° de serie'),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Diagnóstico y trabajo')
                    ->schema([
                        Forms\Components\Textarea::make('falla_motivo')->label('Falla/Motivo')->rows(2),
                        Forms\Components\Textarea::make('trabajo_realizado')->rows(2),
                        Forms\Components\Textarea::make('repuestos_insumos')->label('Repuestos/Insumos')->rows(2),
                    ]),
                Forms\Components\Section::make('Responsables')
                    ->schema([
                        Forms\Components\TextInput::make('tecnico_responsable'),
                        Forms\Components\TextInput::make('encargado_ti_nombre')->label('Encargado TI'),
                        Forms\Components\TextInput::make('encargado_ti_ci')->label('C.I. Encargado TI'),
                        Forms\Components\TextInput::make('usuario_nombre')->label('Usuario/Solicitante'),
                        Forms\Components\TextInput::make('usuario_ci')->label('C.I. Usuario/Solicitante'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('N.°')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('fecha')->date()->sortable(),
                Tables\Columns\TextColumn::make('tipo_equipo')->label('Equipo')->searchable(),
                Tables\Columns\TextColumn::make('usuario_nombre')->label('Usuario')->searchable(),
                Tables\Columns\TextColumn::make('tecnico_responsable')->label('Técnico')->searchable(),
                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'firmado' ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options(['generado' => 'Generado', 'firmado' => 'Firmado']),
                static::dateRangeFilter('fecha', 'Fecha'),
            ])
            ->actions([
                Tables\Actions\Action::make('descargarPdf')
                    ->label('Descargar PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function (ActaServicioTecnico $record) {
                        $ruta = $record->pdf_firmado_path ?? $record->pdf_generado_path;

                        if ($ruta === null || ! Storage::disk('local')->exists($ruta)) {
                            Notification::make()->title('No se encontró el PDF')->danger()->send();

                            return;
                        }

                        return Storage::disk('local')->download($ruta, "acta_servicio_tecnico_{$record->numero}.pdf");
                    }),
                Tables\Actions\Action::make('subirFirmado')
                    ->label('Subir firmado')
                    ->icon('heroicon-o-document-check')
                    ->color('success')
                    ->form([
                        Forms\Components\FileUpload::make('firmado')
                            ->label('PDF firmado')
                            ->disk('local')
                            ->directory('actas/servicio-tecnico/firmados-tmp')
                            ->acceptedFileTypes(['application/pdf'])
                            ->required(),
                    ])
                    ->action(function (ActaServicioTecnico $record, array $data) {
                        try {
                            $ruta = $data['firmado'];
                            $contenido = Storage::disk('local')->get($ruta);
                            app(ActaServicioTecnicoService::class)->subirFirmado($record, $contenido);
                            Storage::disk('local')->delete($ruta);
                            Notification::make()->title('PDF firmado cargado')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Error al subir el PDF firmado')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActaServicioTecnicos::route('/'),
            'create' => Pages\CreateActaServicioTecnico::route('/create'),
            'edit' => Pages\EditActaServicioTecnico::route('/{record}/edit'),
        ];
    }
}
