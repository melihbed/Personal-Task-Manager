<?php

namespace App\Services\Canvas;

use App\Models\CanvasAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reads from the Canvas REST API with a personal access token. It never writes: nothing is submitted or
 * changed in Canvas.
 */
class CanvasClient
{
    private const MAX_PAGES = 30;

    public function __construct(private readonly string $baseUrl, private readonly string $token) {}

    public static function for(CanvasAccount $account): self
    {
        return new self($account->base_url, $account->access_token);
    }

    /**
     * Turns what the user typed ("njit.instructure.com", "https://njit.instructure.com/") into the
     * canonical https address, or null when it is not a Canvas address. The token is only ever sent to
     * *.instructure.com over https.
     */
    public static function normalizeBaseUrl(string $input): ?string
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        $parts = parse_url(str_contains($input, '://') ? $input : "https://{$input}");
        $host = strtolower($parts['host'] ?? '');

        if (strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || ! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.instructure\.com$/', $host)) {
            return null;
        }

        return "https://{$host}";
    }

    /** @return array{id: int, name: string} */
    public function profile(): array
    {
        $response = $this->request('/api/v1/users/self/profile');

        return ['id' => (int) $response->json('id'), 'name' => (string) ($response->json('name') ?? '')];
    }

    /**
     * The courses the user is currently enrolled in as a student.
     *
     * @return list<array<string, mixed>>
     */
    public function courses(): array
    {
        $courses = $this->paginate('/api/v1/courses?enrollment_state=active&enrollment_type=student&include[]=term&per_page=100');

        // Courses closed to students by date come back as just an id.
        return array_values(array_filter($courses, fn (array $course) => isset($course['id'], $course['name'])));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignments(int $courseId): array
    {
        return $this->paginate("/api/v1/courses/{$courseId}/assignments?include[]=submission&per_page=100");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path): array
    {
        $items = [];
        $url = $this->baseUrl.$path;

        for ($page = 0; $url !== null && $page < self::MAX_PAGES; $page++) {
            $response = $this->send($url);
            $items = array_merge($items, $response->json() ?? []);
            $url = $this->nextLink($response);
        }

        return $items;
    }

    private function request(string $path): Response
    {
        return $this->send($this->baseUrl.$path);
    }

    private function send(string $url): Response
    {
        // Only ever follow links that stay on the Canvas host the token belongs to.
        if (parse_url($url, PHP_URL_HOST) !== parse_url($this->baseUrl, PHP_URL_HOST)) {
            throw new CanvasApiException('Canvas sent a link to another host.');
        }

        try {
            $response = Http::withToken($this->token)->acceptJson()->timeout(20)->get($url);
        } catch (ConnectionException $exception) {
            throw new CanvasApiException('Could not reach Canvas.', 0);
        }

        if ($response->status() === 401) {
            throw new CanvasReconnectRequired('Canvas rejected the access token.');
        }

        if ($response->failed()) {
            throw new CanvasApiException("Canvas answered with an error ({$response->status()}).", $response->status());
        }

        return $response;
    }

    private function nextLink(Response $response): ?string
    {
        foreach (explode(',', $response->header('Link')) as $link) {
            if (preg_match('/<([^>]+)>;\s*rel="next"/', $link, $match)) {
                return $match[1];
            }
        }

        return null;
    }
}
