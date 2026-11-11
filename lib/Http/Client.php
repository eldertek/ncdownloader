<?php

declare(strict_types=1);

namespace OCA\NCDownloader\Http;

use OCA\NCDownloader\Tools\UrlValidator;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;

final class Client
{
    private IClient $client;
    private array $defaultOptions;

    public function __construct(?array $options = null)
    {
        /** @var IClientService $clientService */
        $clientService = \OC::$server->get(IClientService::class);
        $this->client = $clientService->newClient();
        $this->defaultOptions = $this->configure($options ?? []);
    }

    public static function create(?array $options = null): self
    {
        return new self($options);
    }

    private function defaultUserAgent(): string
    {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
    }

    private function configure(array $options): array
    {
        $headers = $options['headers'] ?? [];
        $headers['User-Agent'] = $options['useragent'] ?? ($headers['User-Agent'] ?? $this->defaultUserAgent());

        $settings = [
            'headers' => $headers,
            'timeout' => (int) ($options['timeout'] ?? 20),
            'connect_timeout' => (int) ($options['connect_timeout'] ?? 10),
            'nextcloud' => [
                'allow_local_address' => false,
            ],
        ];

        if (isset($options['max_redirects'])) {
            $settings['allow_redirects'] = [
                'max' => (int) $options['max_redirects'],
            ];
        }

        if (!empty($options['ipv4']) || !empty($options['force_ipv4'])) {
            if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
                $settings['curl'] = [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
            }
        }

        return $settings;
    }

    public function request(string $method, string $url, ?array $options = null): Response
    {
        $check = UrlValidator::validate($url, ['http', 'https']);
        if (!$check['valid']) {
            throw new \InvalidArgumentException($check['error'] ?? 'Invalid URL');
        }

        $reqOptions = array_replace_recursive($this->defaultOptions, $options ?? []);
        $reqOptions['nextcloud']['allow_local_address'] = false;

        $upperMethod = strtoupper(trim($method));
        if ($upperMethod === 'POST') {
            $resp = $this->client->post($url, $reqOptions);
        } elseif ($upperMethod === 'HEAD') {
            $resp = $this->client->head($url, $reqOptions);
        } else {
            $resp = $this->client->get($url, $reqOptions);
        }

        $body = $resp->getBody();
        if (is_resource($body)) {
            $body = (string) stream_get_contents($body);
        }

        return new Response($resp->getStatusCode(), (string) ($body ?? ''));
    }
}
