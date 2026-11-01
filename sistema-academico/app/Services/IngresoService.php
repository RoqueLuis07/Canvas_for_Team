<?php

namespace App\Services;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Exceptions\IngresoException;
use App\Models\Persona;

/**
 * Alta de cuentas institucionales (Canvas y/o Teams) para una Persona ya
 * registrada en el sistema — equivalente al módulo "Nuevo Ingreso" del
 * sistema FastAPI en producción.
 *
 * Flujo:
 *   1. Genera credenciales institucionales únicas (correo/login_id/password)
 *      a partir del nombre completo y la cédula, resolviendo colisiones de
 *      correo probando sufijos derivados del propio nombre.
 *   2. Si la persona ya tiene cuenta en Canvas por su cédula (reingreso), se
 *      reutiliza ese correo en vez de generar uno nuevo.
 *   3. Crea la cuenta en Canvas (si corresponde) y en Teams/Azure AD (si
 *      corresponde), guardando los IDs devueltos en la Persona.
 *
 * El envío del correo de credenciales es una acción aparte (no la hace este
 * servicio), igual que en producción.
 */
class IngresoService
{
    /** Contraseña fija para docentes — a diferencia de los alumnos, que usan el esquema cédula-Iniciales. */
    protected const TEACHER_DEFAULT_PASSWORD = 'Usil2026*';

    public function __construct(
        protected CanvasClient $canvas,
        protected TeamsClient $teams,
        protected CredentialGenerator $credenciales,
        protected SincronizacionService $sincronizacion,
    ) {}

    /**
     * Genera credenciales únicas para una persona, resolviendo colisiones de
     * correo. Si la persona ya tiene cuenta en Canvas por su cédula
     * (reingreso), se reutiliza su correo existente en vez de generar uno
     * nuevo.
     *
     * @return array{
     *     full_name: string, cedula: string, login_id: string,
     *     email: string, password: string, display_name: string
     * }
     */
    public function generarCredencialesUnicas(string $nombreCompleto, string $cedula, string $plataforma = 'both'): array
    {
        $domain = config('services.canvas.institutional_domain');

        // Reingreso: ya existe en Canvas por cédula -> se reutiliza su correo.
        if (in_array($plataforma, ['canvas', 'both'], true)) {
            $existente = $this->canvas->findUserBySisId($cedula);

            if ($existente !== null && ($existente['login_id'] ?? '') !== '') {
                $loginId = $existente['login_id'];

                return [
                    'full_name' => $nombreCompleto,
                    'cedula' => $cedula,
                    'login_id' => explode('@', $loginId)[0],
                    'email' => $loginId,
                    'password' => $this->credenciales->generatePassword($cedula, $nombreCompleto),
                    'display_name' => $nombreCompleto,
                ];
            }
        }

        [$primerNombre, $apellido, $palabrasExtra] = $this->credenciales->parseName($nombreCompleto);

        $sufijos = [''];

        foreach ([...$palabrasExtra, $primerNombre, $apellido] as $palabra) {
            $palabra = mb_strtolower($palabra);

            for ($i = 1; $i <= mb_strlen($palabra); $i++) {
                $candidato = mb_substr($palabra, 0, $i);

                if (! in_array($candidato, $sufijos, true)) {
                    $sufijos[] = $candidato;
                }
            }
        }

        foreach ($sufijos as $sufijo) {
            $creds = $this->credenciales->generateCredentials($nombreCompleto, $cedula, $domain, $sufijo);

            if (! $this->correoYaTomado($creds['email'], $plataforma)) {
                return $creds;
            }
        }

        throw new IngresoException("No se pudo generar un correo institucional único para \"{$nombreCompleto}\".");
    }

    protected function correoYaTomado(string $email, string $plataforma): bool
    {
        $loginId = explode('@', $email)[0];

        if (in_array($plataforma, ['canvas', 'both'], true) && $this->canvas->findUserByLoginId($loginId) !== null) {
            return true;
        }

        if (in_array($plataforma, ['teams', 'both'], true) && $this->teams->findUserByUpn($email) !== null) {
            return true;
        }

        return false;
    }

    /**
     * Da de alta las cuentas institucionales de una Persona ya registrada:
     * genera credenciales, crea la cuenta en Canvas y/o Teams, y guarda los
     * identificadores devueltos.
     *
     * @throws IngresoException
     */
    public function darDeAlta(Persona $persona, string $plataforma = 'both'): Persona
    {
        if (! in_array($plataforma, ['canvas', 'teams', 'both'], true)) {
            throw new IngresoException("Plataforma inválida '{$plataforma}'.");
        }

        if (trim($persona->nombre_completo) === '' || trim($persona->cedula) === '') {
            throw new IngresoException('La persona necesita nombre completo y cédula para dar de alta sus cuentas.');
        }

        if ($persona->canvas_user_id && $persona->azure_user_id) {
            throw new IngresoException("{$persona->nombre_completo} ya tiene cuentas institucionales creadas.");
        }

        $esDocente = $persona->tipo === 'docente';
        $creds = $this->generarCredencialesUnicas($persona->nombre_completo, $persona->cedula, $plataforma);

        if ($esDocente) {
            $creds['password'] = self::TEACHER_DEFAULT_PASSWORD;
        }

        $sisUserId = $esDocente ? $creds['email'] : $persona->cedula;

        $resultadosPorPlataforma = [];
        $plataformaActual = 'canvas';

        $this->sincronizacion->ejecutar(
            'ingreso', 'ingreso_alta_cuentas', "Persona#{$persona->id}",
            function () use ($persona, $creds, $sisUserId, $plataforma, &$resultadosPorPlataforma, &$plataformaActual) {
                if (in_array($plataforma, ['canvas', 'both'], true) && ! $persona->canvas_user_id) {
                    try {
                        $existente = $this->canvas->findUserBySisId($sisUserId);

                        $canvasUserId = $existente['id'] ?? $this->canvas->createUser([
                            'full_name' => $creds['full_name'],
                            'login_id' => $creds['login_id'],
                            'sis_user_id' => $sisUserId,
                            'password' => $creds['password'],
                            'email' => $creds['email'],
                        ]);

                        $persona->canvas_user_id = $canvasUserId;
                        $resultadosPorPlataforma['canvas'] = ['estado' => 'completado', 'resultado' => $existente !== null ? 'ya_existia' : 'alta_ok'];
                    } catch (\Throwable $e) {
                        $resultadosPorPlataforma['canvas'] = ['estado' => 'error', 'error' => $e->getMessage()];

                        throw $e;
                    }
                }

                $plataformaActual = 'teams';

                if (in_array($plataforma, ['teams', 'both'], true) && ! $persona->azure_user_id) {
                    try {
                        $existente = $this->teams->findUserByUpn($creds['email']);

                        $azureUserId = $existente['id'] ?? $this->teams->createUser([
                            'display_name' => $creds['full_name'],
                            'upn' => $creds['email'],
                            'mail_nickname' => str_replace('.', '_', $creds['login_id']),
                            'password' => $creds['password'],
                            'cedula' => $persona->cedula,
                        ]);

                        $persona->azure_user_id = $azureUserId;
                        $resultadosPorPlataforma['teams'] = ['estado' => 'completado', 'resultado' => $existente !== null ? 'ya_existia' : 'alta_ok'];
                    } catch (\Throwable $e) {
                        $resultadosPorPlataforma['teams'] = ['estado' => 'error', 'error' => $e->getMessage()];

                        throw $e;
                    }
                }

                $persona->email_institucional = $creds['email'];
                $persona->estado = 'activo';
                $persona->save();

                $this->sincronizacion->registrarAuditoriaPorPlataforma(
                    'ingreso_alta_cuentas',
                    "Persona#{$persona->id}",
                    $resultadosPorPlataforma,
                    [
                        'canvas_user_id' => $persona->canvas_user_id,
                        'azure_user_id' => $persona->azure_user_id,
                    ],
                );
            },
        );

        return $persona->refresh();
    }
}
