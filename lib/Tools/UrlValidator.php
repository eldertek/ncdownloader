<?php

declare(strict_types=1);

namespace OCA\NCDownloader\Tools;

final class UrlValidator
{
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'localhost.localdomain',
        'ip6-localhost',
        'ip6-loopback',
        'metadata.google.internal',
        'metadata',
    ];

    /**
     * Validate a user-supplied URL against option injection, disallowed schemes and SSRF.
     *
     * @param string $url
     * @param string[] $allowedSchemes
     * @return array{valid: bool, error: ?string}
     */
    public static function validate(string $url, array $allowedSchemes = ['http', 'https', 'ftp', 'ftps', 'magnet']): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['valid' => false, 'error' => 'URL cannot be empty'];
        }

        if (str_starts_with($url, '-') || preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return ['valid' => false, 'error' => 'Invalid URL format'];
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme === '' || !in_array($scheme, $allowedSchemes, true)) {
            return ['valid' => false, 'error' => 'Unsupported URL scheme'];
        }

        if ($scheme === 'magnet') {
            if (!preg_match('/^magnet:\?xt=urn:[a-z0-9]+:[a-z0-9]{32,64}/i', $url)) {
                return ['valid' => false, 'error' => 'Invalid magnet link'];
            }
            // Validate any embedded web-seed / tracker HTTP(S) URLs in magnet parameters
            $query = (string) parse_url($url, PHP_URL_QUERY);
            if ($query !== '') {
                foreach (explode('&', $query) as $part) {
                    $kv = explode('=', $part, 2);
                    if (count($kv) === 2 && in_array(strtolower($kv[0]), ['ws', 'as', 'xs', 'tr'], true)) {
                        $embedded = urldecode($kv[1]);
                        $embeddedScheme = strtolower((string) parse_url($embedded, PHP_URL_SCHEME));
                        if (in_array($embeddedScheme, ['http', 'https', 'ftp', 'ftps'], true)) {
                            $subCheck = self::validate($embedded, ['http', 'https', 'ftp', 'ftps']);
                            if (!$subCheck['valid']) {
                                return $subCheck;
                            }
                        }
                    }
                }
            }
            return ['valid' => true, 'error' => null];
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return ['valid' => false, 'error' => 'Missing host in URL'];
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '' || in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            return ['valid' => false, 'error' => 'Local and internal addresses are not allowed'];
        }

        foreach (['.localhost', '.local', '.internal', '.home.arpa'] as $blockedSuffix) {
            if (str_ends_with($host, $blockedSuffix)) {
                return ['valid' => false, 'error' => 'Local and internal hostnames are not allowed'];
            }
        }

        // Reject numeric/octal/hex IPv4 forms such as 2130706433 or 0x7f000001
        if (preg_match('/^(0x[0-9a-f]+|\d+)$/i', $host) === 1) {
            return ['valid' => false, 'error' => 'Encoded IP literals are not allowed'];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!self::isPublicIp($host)) {
                return ['valid' => false, 'error' => 'Private and loopback IP addresses are not allowed'];
            }
            return ['valid' => true, 'error' => null];
        }

        $ips = self::resolveHostIps($host);
        if (empty($ips)) {
            return ['valid' => false, 'error' => 'Unable to resolve hostname'];
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return ['valid' => false, 'error' => 'Target hostname resolves to a private or reserved IP address'];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    public static function isPublicIp(string $ip): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:')) {
            $mappedV4 = substr($ip, 7);
            if (filter_var($mappedV4, FILTER_VALIDATE_IP, FILTER_VALIDATE_IP | FILTER_FLAG_IPV4) !== false) {
                $ip = $mappedV4;
            }
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * @return string[]
     */
    private static function resolveHostIps(string $host): array
    {
        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if (empty($ips) && function_exists('gethostbynamel')) {
            $v4List = @gethostbynamel($host);
            if (is_array($v4List)) {
                $ips = array_merge($ips, $v4List);
            }
        }

        return array_values(array_unique($ips));
    }
}
