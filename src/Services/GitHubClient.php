<?php

namespace Community\EggBrowser\Services;

use Community\EggBrowser\Exceptions\GitHubException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GitHubClient
{
    public function __construct(
        protected ?string $token = null,
        protected ?int $timeout = null,
        protected ?int $connectTimeout = null,
        protected ?int $retries = null,
        protected ?int $retrySleepMs = null,
        protected ?string $userAgent = null,
    ) {
        $this->token ??= config('egg-browser.github_token');
        $this->timeout ??= (int) config('egg-browser.http.timeout', 20);
        $this->connectTimeout ??= (int) config('egg-browser.http.connect_timeout', 5);
        $this->retries ??= (int) config('egg-browser.http.retries', 2);
        $this->retrySleepMs ??= (int) config('egg-browser.http.retry_sleep_ms', 500);
        $this->userAgent ??= (string) config('egg-browser.http.user_agent', 'Pelican-Egg-Browser/1.0');
    }

    /**
     * @return array{sha: string, tree: list<array<string, mixed>>, truncated: bool}
     */
    public function getRecursiveTree(string $owner, string $repo, string $branch = 'main'): array
    {
        $response = $this->request()->get(
            "https://api.github.com/repos/{$owner}/{$repo}/git/trees/{$branch}",
            ['recursive' => '1']
        );

        $this->throwIfFailed($response, "tree {$owner}/{$repo}@{$branch}");

        /** @var array{sha: string, tree: list<array<string, mixed>>, truncated?: bool} $json */
        $json = $response->json();

        return [
            'sha' => (string) ($json['sha'] ?? ''),
            'tree' => $json['tree'] ?? [],
            'truncated' => (bool) ($json['truncated'] ?? false),
        ];
    }

    /**
     * @return array{default_branch: string, full_name: string, description: ?string, html_url: string}
     */
    public function getRepository(string $owner, string $repo): array
    {
        $response = $this->request()->get("https://api.github.com/repos/{$owner}/{$repo}");
        $this->throwIfFailed($response, "repo {$owner}/{$repo}");

        $json = $response->json();

        return [
            'default_branch' => (string) ($json['default_branch'] ?? 'main'),
            'full_name' => (string) ($json['full_name'] ?? "{$owner}/{$repo}"),
            'description' => $json['description'] ?? null,
            'html_url' => (string) ($json['html_url'] ?? "https://github.com/{$owner}/{$repo}"),
        ];
    }

    public function getRawFile(string $owner, string $repo, string $path, string $ref = 'main'): string
    {
        $url = "https://raw.githubusercontent.com/{$owner}/{$repo}/{$ref}/{$path}";

        $response = $this->rawRequest()->get($url);
        $this->throwIfFailed($response, "raw {$owner}/{$repo}/{$path}");

        return $response->body();
    }

    /**
     * @return array{rate_limit: ?int, remaining: ?int, reset: ?int, authenticated: bool}
     */
    public function rateLimitStatus(): array
    {
        try {
            $response = $this->request()->get('https://api.github.com/rate_limit');
            if (!$response->successful()) {
                return [
                    'rate_limit' => null,
                    'remaining' => null,
                    'reset' => null,
                    'authenticated' => filled($this->token),
                ];
            }

            $core = $response->json('resources.core') ?? [];

            return [
                'rate_limit' => isset($core['limit']) ? (int) $core['limit'] : null,
                'remaining' => isset($core['remaining']) ? (int) $core['remaining'] : null,
                'reset' => isset($core['reset']) ? (int) $core['reset'] : null,
                'authenticated' => filled($this->token),
            ];
        } catch (\Throwable) {
            return [
                'rate_limit' => null,
                'remaining' => null,
                'reset' => null,
                'authenticated' => filled($this->token),
            ];
        }
    }

    protected function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->withHeaders([
                'User-Agent' => $this->userAgent,
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry($this->retries, $this->retrySleepMs, function ($exception, $request) {
                if ($exception instanceof RequestException) {
                    $status = $exception->response?->status();

                    // Retry transient errors and rate limits.
                    return in_array($status, [408, 425, 429, 500, 502, 503, 504], true);
                }

                return $exception instanceof ConnectionException;
            }, throw: false);

        if (filled($this->token)) {
            $request = $request->withToken($this->token);
        }

        return $request;
    }

    protected function rawRequest(): PendingRequest
    {
        $request = Http::withHeaders([
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/vnd.github.raw',
        ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->retry($this->retries, $this->retrySleepMs, function ($exception) {
                if ($exception instanceof RequestException) {
                    return in_array($exception->response?->status(), [408, 425, 429, 500, 502, 503, 504], true);
                }

                return $exception instanceof ConnectionException;
            }, throw: false);

        if (filled($this->token)) {
            $request = $request->withToken($this->token);
        }

        return $request;
    }

    protected function throwIfFailed(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body = $response->json('message') ?? $response->body();
        $message = is_string($body) ? $body : json_encode($body);

        if ($status === 403 || $status === 429) {
            $reset = $response->header('X-RateLimit-Reset');
            $remaining = $response->header('X-RateLimit-Remaining');
            throw GitHubException::rateLimited($context, $status, $message, $remaining, $reset);
        }

        if ($status === 404) {
            throw GitHubException::notFound($context, $message);
        }

        throw GitHubException::requestFailed($context, $status, $message);
    }
}
