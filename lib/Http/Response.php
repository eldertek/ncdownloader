<?php

declare(strict_types=1);

namespace OCA\NCDownloader\Http;

final class Response
{
    private int $statusCode;
    private string $body;

    public function __construct(int $statusCode, string $body)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContent(): string
    {
        return $this->body;
    }

    public function toArray(): array
    {
        $decoded = json_decode($this->body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response');
        }
        return $decoded;
    }
}
