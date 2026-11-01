<?php

namespace App\Contracts;

interface CanvasClient
{
    /**
     * Crea un curso en Canvas dentro de la subcuenta configurada.
     *
     * @return string El canvas_course_id creado.
     */
    public function createCourse(string $name, string $sisCourseId): string;

    /**
     * Matricula a un usuario ya existente en Canvas dentro de un curso.
     */
    public function enrollUser(string $canvasCourseId, string $canvasUserId, string $role = 'StudentEnrollment'): void;

    /**
     * Da de baja (concluye) la matrícula de un usuario en un curso.
     */
    public function unenrollUser(string $canvasCourseId, string $canvasUserId): void;

    /**
     * Publica un material (documento, enlace, tarea, anuncio) como página
     * de contenido dentro de un curso ya creado en Canvas.
     *
     * @return string El identificador del ítem de contenido creado en Canvas.
     */
    public function createPage(string $canvasCourseId, string $title, string $body): string;

    /**
     * Busca un usuario en Canvas por su SIS user ID (cédula).
     *
     * @return array{id: string, name: string, login_id: string, email: ?string}|null
     */
    public function findUserBySisId(string $sisUserId): ?array;

    /**
     * Busca un usuario en Canvas por su login_id (correo institucional).
     *
     * @return array{id: string, name: string, login_id: string, email: ?string}|null
     */
    public function findUserByLoginId(string $loginId): ?array;

    /**
     * Crea un usuario en Canvas dentro de la cuenta configurada, con su
     * pseudónimo (login) y canal de comunicación (correo) iniciales.
     *
     * @param  array{full_name: string, login_id: string, sis_user_id: string, password: string, email: string}  $datos
     * @return string El canvas_user_id creado.
     */
    public function createUser(array $datos): string;

    /**
     * Elimina (de baja definitiva) un usuario de la cuenta de Canvas.
     */
    public function deleteUser(string $canvasUserId): void;
}
