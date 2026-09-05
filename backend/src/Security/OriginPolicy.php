<?php

declare(strict_types=1);

namespace Echo\Security;

use Echo\Config\Config;
use Echo\Http\Request;
use Echo\Http\RequestException;

final class OriginPolicy
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array<string, string> */
    public function enforce(Request $request): array
    {
        if ($request->origin === null || $request->origin === '') {
            return [];
        }

        $origin = $this->normalizeOrigin($request->origin);
        $sameOrigin = $this->normalizeOrigin($request->scheme . '://' . $request->host);
        $extraAllowed = array_map([$this, 'normalizeOrigin'], $this->config->allowedOrigins);

        if ($origin === null || ($origin !== $sameOrigin && !in_array($origin, $extraAllowed, true))) {
            throw new RequestException(403, 'origin_not_allowed', 'Request origin is not allowed.');
        }

        return [
            'Access-Control-Allow-Origin' => $request->origin,
            'Vary' => 'Origin',
        ];
    }

    private function normalizeOrigin(string $origin): ?string
    {
        $parts = parse_url(trim($origin));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }
}
