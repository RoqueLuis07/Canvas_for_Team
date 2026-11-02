<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActaEntregaEquipoResource\Pages;
use App\Models\ActaEntregaEquipo;
use App\Services\Actas\ActaEntregaEquipoService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ActaEntregaEquipoResource extends AdminOnlyResource
{
    protected static ?string $model = ActaEntregaEquipo::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Entrega de Equipo';

    protected static ?string $navigationGroup = 'Actas';

    protected static ?string $modelLabel = 'acta de entrega de equipo';

    protected static ?string $pluralModelLabel = 'actas de entrega de equipo';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Entrega')
                    ->schema([
                        Forms\Components\DatePicker::make('fecha_emision')->required(),
                        Forms\Components\TextInput::make('entrega_nombre')->label('Entrega (nombre)'),
                        Forms\Components\TextInput::make('entrega_ci')->label('Entrega (C.I.)'),
                        Forms\Components\TextInput::make('entrega_cargo')->label('Entrega (cargo)'),
                        Forms\Components\TextInput::make('recibe_nombre')->label('Recibe (nombre)'),
                        Forms\Components\TextInput::make('recibe_ci')->label('Recibe (C.I.)'),
                        Forms\Components\TextInput::make('recibe_cargo')->label('Recibe (cargo)'),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Equipo')
                    ->schema([
                        Forms\Components\TextInput::make('insumo')->label('Insumo/Equipo'),
                        Forms\Components\TextInput::make('marca'),
                        Forms\Components\TextInput::make('modelo'),
                        Forms\Components\TextInput::make('nro_serie')->label('N.° de serie'),
                        Forms\Components\Textarea::make('especificaciones')->columnSpanFull()->rows(2),
                        Forms\Components\TagsInput::make('accesorios')->label('Accesorios entregados'),
                        Forms\Components\TextInput::make('accesorios_otros')->label('Otros accesorios'),
                        Forms\Components\Select::make('estado_equipo')
                            ->options(['nuevo' => 'Nuevo', 'bueno' => 'Bueno', 'regular' => 'Regular', 'malo' => 'Malo']),
                        Forms\Components\Textarea::make('observaciones_entrega')->columnSpanFull()->rows(2),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Devolución (si aplica)')
                    ->schema([
                        Forms\Components\DatePicker::make('fecha_devolucion'),
                        Forms\Components\TextInput::make('motivo_devolucion'),
                        Forms\Components\Select::make('estado_equipo_devolucion')
                            ->label('Estado del equipo (devolución)')
                            ->options(['nuevo' => 'Nuevo', 'bueno' => 'Bueno', 'regular' => 'Regular', 'malo' => 'Malo']),
                        Forms\Components\Textarea::make('observaciones_devolucion')->columnSpanFull()->rows(2),
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
                Tables\Columns\TextColumn::make('numero')->label('N.°')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('fecha_emision')->date()->sortable(),
                Tables\Columns\TextColumn::make('insumo')->label('Equipo')->searchable(),
                Tables\Columns\TextColumn::make('recibe_nombre')->label('Recibe')->searchable(),
                Tables\Columns\TextColumn::make('estado')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'firmado' ? 'success' : 'gray'),
                Tables\Columns\IconColumn::make('fecha_devolucion')
                    ->label('Devuelto')
                    ->boolean()
                    ->getStateUsing(fn (ActaEntregaEquipo $record) => $record->fecha_devolucion !== null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('estado')
                    ->options(['generado' => 'Generado', 'firmado' => 'Firmado']),
                static::dateRangeFilter('fecha_emision', 'Fecha de emisión'),
            ])
            ->actions([
                Tables\Actions\Action::make('descargarPdf')
                    ->label('Descargar PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function (ActaEntregaEquipo $record) {
                        $ruta = $record->pdf_firmado_path ?? $record->pdf_generado_path;

                        if ($ruta === null || ! Storage::disk('local')->exists($ruta)) {
                            Notification::make()->title('No se encontró el PDF')->danger()->send();

                            return;
                        }

                        return Storage::disk('local')->download($ruta, "acta_entrega_equipo_{$record->numero}.pdf");
                    }),
                Tables\Actions\Action::make('subirFirmado')
                    ->label('Subir firmado')
                    ->icon('heroicon-o-document-check')
                    ->color('success')
                    ->form([
                        Forms\Components\FileUpload::make('firmado')
                            ->label('PDF firmado')
                            ->disk('local')
                            ->directory('actas/entrega-equipo/firmados-tmp')
                            ->acceptedFileTypes(['application/pdf'])
                            ->required(),
                    ])
                    ->action(function (ActaEntregaEquipo $record, array $data) {
                        try {
                            $ruta = $data['firmado'];
                            $contenido = Storage::disk('local')->get($ruta);
                            app(ActaEntregaEquipoService::class)->subirFirmado($record, $contenido);
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
            'index' => Pages\ListActaEntregaEquipos::route('/'),
            'create' => Pages\CreateActaEntregaEquipo::route('/create'),
            'edit' => Pages\EditActaEntregaEquipo::route('/{record}/edit'),
        ];
    }
}
