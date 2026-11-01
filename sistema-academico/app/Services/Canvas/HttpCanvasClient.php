<?php

namespace App\Services\Canvas;

use App\Contracts\CanvasClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpCanvasClient implements CanvasClient
{
    protected string $baseUrl;

    protected string $accountId;

    protected ?string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.canvas.base_url'), '/').'/api/v1';
        $this->accountId = config('services.canvas.account_id');
        $this->token = config('services.canvas.access_token');
    }

    protected function client()
    {
        if (! $this->token) {
            throw new RuntimeException('CANVAS_ACCESS_TOKEN no está configurado.');
        }

        return Http::baseUrl($this->baseUrl)->withToken($this->token)->acceptJson();
    }

    public function createCourse(string $name, string $sisCourseId): string
    {
        $response = $this->client()->post("/accounts/{$this->accountId}/courses", [
            'course' => [
                'name' => $name,
                'course_code' => $name,
                'sis_course_id' => $sisCourseId,
            ],
        ])->throw();

        return (string) $response->json('id');
    }

    public function enrollUser(string $canvasCourseId, string $canvasUserId, string $role = 'StudentEnrollment'): void
    {
        $this->client()->post("/courses/{$canvasCourseId}/enrollments", [
            'enrollment' => [
                'user_id' => $canvasUserId,
                'type' => $role,
                'enrollment_state' => 'active',
                'notify' => false,
            ],
        ])->throw();
    }

    public function unenrollUser(string $canvasCourseId, string $canvasUserId): void
    {
        $enrollments = $this->client()
            ->get("/courses/{$canvasCourseId}/enrollments", ['user_id' => $canvasUserId])
            ->throw()
            ->json();

        foreach ($enrollments as $enrollment) {
            $this->client()
                ->delete("/courses/{$canvasCourseId}/enrollments/{$enrollment['id']}", ['task' => 'conclude'])
                ->throw();
        }
    }

    public function createPage(string $canvasCourseId, string $title, string $body): string
    {
        $response = $this->client()->post("/courses/{$canvasCourseId}/pages", [
            'wiki_page' => [
                'title' => $title,
                'body' => $body,
                'published' => true,
            ],
        ])->throw();

        return (string) $response->json('page_id');
    }

    public function findUserBySisId(string $sisUserId): ?array
    {
        $response = $this->client()->get("/accounts/{$this->accountId}/users/sis_user_id:{$sisUserId}");

        if ($response->status() === 404) {
            return null;
        }

        $user = $response->throw()->json();

        return $this->mapUser($user);
    }

    public function findUserByLoginId(string $loginId): ?array
    {
        $response = $this->client()
            ->get("/accounts/{$this->accountId}/users", ['search_term' => $loginId])
            ->throw();

        foreach ((array) $response->json() as $user) {
            if (strcasecmp($user['login_id'] ?? '', $loginId) === 0) {
                return $this->mapUser($user);
            }
        }

        return null;
    }

    public function createUser(array $datos): string
    {
        $response = $this->client()->post("/accounts/{$this->accountId}/users", [
            'user' => [
                'name' => $datos['full_name'],
                'short_name' => $datos['full_name'],
            ],
            'pseudonym' => [
                'unique_id' => $datos['login_id'],
                'sis_user_id' => $datos['sis_user_id'],
                'password' => $datos['password'],
                'send_confirmation' => false,
            ],
            'communication_channel' => [
                'type' => 'email',
                'address' => $datos['email'],
                'skip_confirmation' => true,
            ],
        ])->throw();

        return (string) $response->json('id');
    }

    public function deleteUser(string $canvasUserId): void
    {
        $this->client()->delete("/accounts/{$this->accountId}/users/{$canvasUserId}")->throw();
    }

    /**
     * @param  array<string, mixed>  $user
     * @return array{id: string, name: string, login_id: string, email: ?string}
     */
    protected function mapUser(array $user): array
    {
        return [
            'id' => (string) ($user['id'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'login_id' => (string) ($user['login_id'] ?? ''),
            'email' => $user['email'] ?? null,
        ];
    }
}
