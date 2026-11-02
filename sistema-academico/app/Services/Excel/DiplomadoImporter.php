<?php

namespace App\Services\Excel;

use App\Contracts\TeamsClient;
use App\Exceptions\DiplomadoImportException;
use App\Models\Persona;
use App\Services\IngresoService;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;
use Throwable;

/**
 * Alta de Diplomados: lee el listado de alumnos directamente desde una
 * planilla compartida de OneDrive (no se sube ningún archivo), crea la
 * cuenta de Teams/Azure AD de cada alumno nuevo (Diplomados es SOLO
 * Teams — a diferencia de Ingreso/Excel masivo, no crea cuenta en Canvas)
 * y los agrega al equipo de Teams del Diplomado, creándolo si todavía no
 * existe. Equivalente a /excel/diplomados(/preview) en producción.
 *
 * ── Adaptación deliberada vs. producción (IMPORTANTE) ──────────────────
 * Producción usa la propia planilla de OneDrive como "libro de estados":
 * en cada corrida escribe de vuelta el Usuario/Contraseña/Estado
 * generado en columnas nuevas o existentes de ESA MISMA planilla, así la
 * próxima vez que se descarga, las filas ya procesadas se reconocen
 * leyendo esas celdas. Ese patrón (spreadsheet-como-base-de-datos) es
 * incompatible con la arquitectura relacional de este sistema, donde
 * Persona es el registro de verdad — así que acá NO se escribe nada de
 * vuelta en OneDrive.
 *
 * En cambio:
 *   - Se SIGUE leyendo la señal de "ya procesado" que ya esté en la
 *     planilla (para que una planilla que el sistema de producción ya
 *     procesó se siga reconociendo correctamente como tal).
 *   - La idempotencia de una corrida a otra de ESTE importador depende de
 *     que la Persona ya tenga `azure_user_id` (mismo resguardo que ya usa
 *     IngresoService::darDeAlta) — no de releer la planilla.
 *   - Consecuencia aceptada: si se vuelve a correr la importación sobre
 *     el MISMO archivo sin que nadie edite manualmente las columnas
 *     Estado/Usuario, las filas que esta corrida ya proceso pueden volver
 *     a intentar agregarse al equipo de Teams (Graph puede devolver un
 *     error de "ya es miembro", que cae en failed[] fila por fila sin
 *     abortar el resto) — no hay forma de evitarlo sin reintroducir el
 *     patrón de escritura en la planilla que se decidió no replicar.
 */
class DiplomadoImporter
{
    /** Mismo límite de seguridad que /excel/* en producción, contado desde la fila de encabezados. */
    public const MAX_FILAS = 10000;

    /** Cuántas filas se escanean buscando la fila de encabezados (planillas con un título en la fila 1). */
    public const MAX_FILAS_ENCABEZADO = 15;

    /** Propietarios fijos de todo equipo de Diplomado nuevo, sin excepción. */
    protected const PROPIETARIOS_POR_DEFECTO = [
        'ldure@usil.edu.py',
        'jfleitas@usil.edu.py',
        'ralvarez@usil.edu.py',
    ];

    /** Propietario adicional exclusivo de los equipos de MBA (se suma a los de arriba, no los reemplaza). */
    protected const PROPIETARIO_EXTRA_MBA = 'amichelena@usil.edu.py';

    protected const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Valores de la columna Estado que marcan una fila como ya procesada,
     * además de "contiene ✅", "contiene 'creado ok'" o "contiene 'ya
     * exist'" (chequeados aparte, no son coincidencia exacta). Puerto de
     * la condición de /excel/diplomados/preview y _process_diplomados_bg.
     */
    protected const ESTADOS_PROCESADO = ['creado', 'ok', 'si', 'yes', 'true', 'enviado'];

    public function __construct(
        protected TeamsClient $teams,
        protected IngresoService $ingreso,
        protected ExcelHeaderMatcher $matcher,
    ) {}

    /**
     * @return list<string>
     *
     * @throws DiplomadoImportException
     */
    public function listarPestanas(string $shareUrl): array
    {
        $ruta = $this->descargarATemporal($shareUrl);

        try {
            $nombres = [];
            $reader = $this->abrir($ruta);

            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    $nombres[] = $sheet->getName();
                }
            } finally {
                $reader->close();
            }

            return $nombres;
        } finally {
            @unlink($ruta);
        }
    }

    /**
     * Descarga y analiza la planilla SIN crear nada — para que el admin
     * vea cuántos alumnos hay por procesar antes de confirmar la corrida
     * real.
     *
     * @return array{titulo: string, sheet_name: string, students_to_process: int, students_already_processed: int, sample: list<array<string, mixed>>}
     *
     * @throws DiplomadoImportException
     */
    public function previsualizar(string $shareUrl, string $sheetName): array
    {
        $hoja = $this->leerHojaDesdeUrl($shareUrl, $sheetName);
        $campos = $this->resolverCampos($hoja['headers']);
        $this->validarCamposObligatorios($campos);

        $aProcesar = 0;
        $yaProcesados = 0;
        $muestra = [];

        foreach ($hoja['filas'] as $fila) {
            $nombre = $this->valorColumna($fila, $campos['nombre']);
            $cedula = $this->valorColumna($fila, $campos['cedula']);

            if ($nombre === null || $cedula === null) {
                continue;
            }

            $procesado = $this->yaFueProcesada($fila, $campos);

            $procesado ? $yaProcesados++ : $aProcesar++;

            if (count($muestra) < 10) {
                $muestra[] = ['nombre' => $nombre, 'cedula' => $cedula, 'ya_procesado' => $procesado];
            }
        }

        return [
            'titulo' => $hoja['titulo'],
            'sheet_name' => $sheetName,
            'students_to_process' => $aProcesar,
            'students_already_processed' => $yaProcesados,
            'sample' => $muestra,
        ];
    }

    /**
     * Corrida real: resuelve/crea el equipo de Teams del Diplomado, y para
     * cada fila todavía no procesada da de alta la cuenta de Teams del
     * alumno (si no la tenía) y lo agrega al equipo.
     *
     * @return array{succeeded: list<array<string, mixed>>, failed: list<array{input: array<string, mixed>, error: string}>}
     *
     * @throws DiplomadoImportException
     */
    public function importar(string $shareUrl, string $sheetName): array
    {
        $hoja = $this->leerHojaDesdeUrl($shareUrl, $sheetName);
        $campos = $this->resolverCampos($hoja['headers']);
        $this->validarCamposObligatorios($campos);

        $esMba = str_contains(mb_strtolower($sheetName), 'mba') || str_contains(mb_strtolower($hoja['titulo']), 'mba');
        $teamId = $this->resolverEquipo($hoja['titulo'], $esMba);

        $resultado = ['succeeded' => [], 'failed' => []];

        foreach ($hoja['filas'] as $fila) {
            $nombre = $this->valorColumna($fila, $campos['nombre']);
            $cedula = $this->valorColumna($fila, $campos['cedula']);

            if ($nombre === null || $cedula === null || $this->yaFueProcesada($fila, $campos)) {
                continue;
            }

            try {
                $resultado['succeeded'][] = $this->procesarAlumno($nombre, $cedula, $teamId);
            } catch (Throwable $e) {
                $resultado['failed'][] = ['input' => ['nombre' => $nombre, 'cedula' => $cedula], 'error' => $e->getMessage()];
            }
        }

        return $resultado;
    }

    /**
     * @return array{titulo: string, headers: array<string, int>, filas: list<array<int, mixed>>}
     */
    protected function leerHojaDesdeUrl(string $shareUrl, string $sheetName): array
    {
        $ruta = $this->descargarATemporal($shareUrl);

        try {
            return $this->leerHoja($ruta, $sheetName);
        } finally {
            @unlink($ruta);
        }
    }

    /**
     * @return array{titulo: string, headers: array<string, int>, filas: list<array<int, mixed>>}
     */
    protected function leerHoja(string $path, string $sheetName): array
    {
        $reader = $this->abrir($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() === $sheetName) {
                    return $this->leerFilasDeHoja($sheet);
                }
            }
        } finally {
            $reader->close();
        }

        throw new DiplomadoImportException("La pestaña \"{$sheetName}\" no existe en el archivo.");
    }

    /**
     * Escanea las primeras MAX_FILAS_ENCABEZADO filas buscando la que
     * tiene columnas de Nombre Y Cédula reconocibles (distintas plantillas
     * traen un título en la fila 1, antes de los encabezados reales).
     * Simplificación deliberada vs. producción: el criterio acá es
     * "ambas columnas obligatorias resuelven", en vez del conteo de
     * palabras clave que usa _find_header_row_and_headers — más simple y
     * reusa ExcelHeaderMatcher en vez de duplicar otra heurística.
     *
     * @return array{titulo: string, headers: array<string, int>, filas: list<array<int, mixed>>}
     *
     * @throws DiplomadoImportException
     */
    protected function leerFilasDeHoja(Sheet $sheet): array
    {
        $titulo = '';
        $headers = [];
        $headerRowNumber = null;
        $filas = [];
        $rowNumber = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $rowNumber++;
            $valores = $row->toArray();

            if ($rowNumber === 1) {
                $titulo = $this->extraerTitulo($valores);
            }

            if ($headerRowNumber === null && $rowNumber <= self::MAX_FILAS_ENCABEZADO) {
                $candidatos = $this->indexarEncabezados($valores);

                if ($this->matcher->matchColumn($candidatos, 'nombre', 'alumno', 'estudiante') !== null
                    && $this->matcher->matchColumn($candidatos, 'cedula', 'cédula', 'ci') !== null) {
                    $headerRowNumber = $rowNumber;
                    $headers = $candidatos;

                    continue; // la fila de encabezados no es una fila de datos.
                }
            }

            if ($headerRowNumber === null) {
                continue; // todavía buscando la fila de encabezados.
            }

            if (($rowNumber - $headerRowNumber) > self::MAX_FILAS) {
                throw new DiplomadoImportException('Máximo '.self::MAX_FILAS.' filas permitidas por planilla.');
            }

            $filas[] = $valores;
        }

        if ($headerRowNumber === null) {
            throw new DiplomadoImportException(
                'No se encontró la fila de encabezados (se esperaban columnas de Nombre y Cédula) en las primeras '.self::MAX_FILAS_ENCABEZADO.' filas.'
            );
        }

        return ['titulo' => $titulo, 'headers' => $headers, 'filas' => $filas];
    }

    /**
     * @param  array<int, mixed>  $valores
     * @return array<string, int>
     */
    protected function indexarEncabezados(array $valores): array
    {
        $headers = [];

        foreach ($valores as $index => $value) {
            $normalizado = $this->matcher->normalize((string) $value);

            if ($normalizado !== '') {
                $headers[$normalizado] = $index;
            }
        }

        return $headers;
    }

    /**
     * Devuelve la primera celda con texto de la fila 1 que no sea, a su
     * vez, el GUID del equipo de Teams — distintas plantillas insertan
     * columnas nuevas antes del título, corriéndolo de la columna A hacia
     * la derecha. Puerto de `_find_row1_title`.
     *
     * @param  array<int, mixed>  $valores
     */
    protected function extraerTitulo(array $valores): string
    {
        $max = min(30, count($valores));

        for ($i = 0; $i < $max; $i++) {
            $valor = trim((string) ($valores[$i] ?? ''));

            if ($valor !== '' && ! preg_match(self::UUID_REGEX, $valor)) {
                return $valor;
            }
        }

        return '';
    }

    /**
     * @param  array<string, int>  $headers
     * @return array{nombre: ?int, cedula: ?int, estado: ?int, usuario: ?int}
     */
    protected function resolverCampos(array $headers): array
    {
        return [
            'nombre' => $this->matcher->matchColumn($headers, 'nombre', 'alumno', 'estudiante'),
            'cedula' => $this->matcher->matchColumn($headers, 'cedula', 'cédula', 'ci'),
            // Simplificación deliberada: producción también reconoce un
            // encabezado de Estado por la combinación "contiene 'canvas' Y
            // 'id'" (ej. "ID Canvas") — un caso raro que queda cubierto en
            // la enorme mayoría de planillas reales por "estado"/"enviado".
            'estado' => $this->matcher->matchColumn($headers, 'estado', 'enviado'),
            'usuario' => $this->matcher->matchColumn($headers, 'usuario'),
        ];
    }

    /**
     * @param  array{nombre: ?int, cedula: ?int, estado: ?int, usuario: ?int}  $campos
     *
     * @throws DiplomadoImportException
     */
    protected function validarCamposObligatorios(array $campos): void
    {
        if ($campos['nombre'] === null || $campos['cedula'] === null) {
            throw new DiplomadoImportException('No se encontraron las columnas requeridas (Nombre y Cédula) en la pestaña seleccionada.');
        }
    }

    /**
     * @param  array<int, mixed>  $fila
     */
    protected function valorColumna(array $fila, ?int $indice): ?string
    {
        if ($indice === null) {
            return null;
        }

        $valor = trim((string) ($fila[$indice] ?? ''));

        return $valor === '' ? null : $valor;
    }

    /**
     * Puerto de la condición de "ya procesado" usada en /excel/diplomados/
     * preview y _process_diplomados_bg.
     *
     * @param  array<int, mixed>  $fila
     * @param  array{nombre: ?int, cedula: ?int, estado: ?int, usuario: ?int}  $campos
     */
    protected function yaFueProcesada(array $fila, array $campos): bool
    {
        $estadoCrudo = (string) ($campos['estado'] !== null ? ($fila[$campos['estado']] ?? '') : '');
        $estado = mb_strtolower(trim($estadoCrudo));
        $usuario = (string) ($campos['usuario'] !== null ? ($fila[$campos['usuario']] ?? '') : '');
        $usuario = trim($usuario);

        if (str_contains($estadoCrudo, '✅')) {
            return true;
        }

        if (in_array($estado, self::ESTADOS_PROCESADO, true)) {
            return true;
        }

        if (str_contains($estado, 'creado ok') || str_contains($estado, 'ya exist')) {
            return true;
        }

        return $usuario !== '' && str_contains($usuario, '@');
    }

    /**
     * Resuelve el equipo de Teams del Diplomado por su título (coincidencia
     * exacta normalizada); si no existe, lo crea y le agrega los
     * propietarios fijos (+ el propietario extra de MBA si corresponde).
     * Un propietario que no se puede resolver en Azure AD se omite sin
     * abortar la creación del equipo.
     *
     * @throws DiplomadoImportException
     */
    protected function resolverEquipo(string $titulo, bool $esMba): string
    {
        if (trim($titulo) === '') {
            throw new DiplomadoImportException('No se pudo determinar el nombre del Diplomado/equipo a partir del título de la planilla (fila 1).');
        }

        $existente = $this->teams->findGroupByName($titulo);

        if ($existente !== null) {
            return $existente;
        }

        $teamId = $this->teams->createTeam($titulo);

        $propietarios = self::PROPIETARIOS_POR_DEFECTO;

        if ($esMba) {
            $propietarios[] = self::PROPIETARIO_EXTRA_MBA;
        }

        foreach ($propietarios as $upn) {
            $usuario = $this->teams->findUserByUpn($upn);

            if ($usuario === null) {
                continue;
            }

            $this->teams->addMember($teamId, $usuario['id'], 'Owner');
        }

        return $teamId;
    }

    /**
     * @return array<string, mixed>
     */
    protected function procesarAlumno(string $nombre, string $cedula, string $teamId): array
    {
        $cedulaLimpia = preg_replace('/[.\-\s]/', '', $cedula) ?? $cedula;

        $persona = Persona::query()->firstOrNew(['cedula' => $cedulaLimpia]);
        $persona->nombre_completo = $nombre;

        if (! $persona->exists) {
            $persona->tipo = 'alumno';
            $persona->estado = 'activo';
        }

        $persona->save();

        if (! $persona->azure_user_id) {
            $persona = $this->ingreso->darDeAlta($persona, 'teams');
        }

        $this->teams->addMember($teamId, $persona->azure_user_id, 'Member');

        return [
            'id' => $persona->id,
            'nombre_completo' => $persona->nombre_completo,
            'cedula' => $persona->cedula,
            'azure_user_id' => $persona->azure_user_id,
            'equipo_id' => $teamId,
        ];
    }

    /**
     * @throws DiplomadoImportException
     */
    protected function descargarATemporal(string $shareUrl): string
    {
        if (trim($shareUrl) === '' || ! str_contains($shareUrl, 'http')) {
            throw new DiplomadoImportException('URL inválida.');
        }

        try {
            $contenido = $this->teams->downloadSharedFile($shareUrl);
        } catch (DiplomadoImportException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new DiplomadoImportException(
                "No se pudo descargar el archivo de OneDrive. Verificá la URL y los permisos. Detalle: {$e->getMessage()}",
                previous: $e,
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'diplomado_').'.xlsx';
        file_put_contents($path, $contenido);

        return $path;
    }

    /**
     * @throws DiplomadoImportException
     */
    protected function abrir(string $path): Reader
    {
        $reader = new Reader();

        try {
            $reader->open($path);
        } catch (Throwable $e) {
            throw new DiplomadoImportException('El archivo descargado no es una planilla .xlsx válida.', previous: $e);
        }

        return $reader;
    }
}
