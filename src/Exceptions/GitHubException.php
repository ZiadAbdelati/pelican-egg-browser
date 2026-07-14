<?php

namespace Community\EggBrowser\Exceptions;

use Exception;

class GitHubException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $context = '',
        public readonly ?int $status = null,
        public readonly bool $rateLimited = false,
        public readonly ?string $remaining = null,
        public readonly ?string $reset = null,
    ) {
        parent::__construct($message, $status ?? 0);
    }

    public static function rateLimited(string $context, int $status, string $message, ?string $remaining, ?string $reset): self
    {
        $extra = [];
        if ($remaining !== null) {
            $extra[] = "remaining={$remaining}";
        }
        if ($reset !== null) {
            $extra[] = 'reset_at=' . date('c', (int) $reset);
        }

        $suffix = $extra ? ' (' . implode(', ', $extra) . ')' : '';

        return new self(
            "GitHub rate limit hit while fetching {$context}: {$message}{$suffix}. Configure EGG_BROWSER_GITHUB_TOKEN to raise limits.",
            $context,
            $status,
            true,
            $remaining,
            $reset,
        );
    }

    public static function notFound(string $context, string $message): self
    {
        return new self("GitHub resource not found ({$context}): {$message}", $context, 404);
    }

    public static function requestFailed(string $context, int $status, string $message): self
    {
        return new self("GitHub request failed for {$context} [HTTP {$status}]: {$message}", $context, $status);
    }
}
