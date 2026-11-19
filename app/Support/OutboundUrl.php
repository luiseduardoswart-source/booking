<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Web addresses typed in by users (a listing to import, an iCal calendar,
 * photo links) that the server itself fetches. Only http(s), never an
 * address inside the server's own network (127.0.0.1, 10.x, 192.168.x, the
 * cloud metadata address 169.254.169.254), also not after a redirect, and
 * never more than a set size. Otherwise someone with an admin account could
 * make the server read internal services and show them the answer.
 */
final class OutboundUrl
{
    /**
     * @throws InvalidArgumentException when the address may not be fetched
     */
    public static function check(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(__('Only web addresses starting with http:// or https:// can be fetched.'));
        }

        foreach (self::addressesOf($host) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException(__('This address points inside the server\'s own network and cannot be fetched.'));
            }
        }
    }

    /**
     * Options for the HTTP client: every redirect is checked the same way,
     * and the download stops beyond $maxBytes.
     *
     * @return array<string, mixed>
     */
    public static function options(int $maxBytes): array
    {
        $tooLarge = fn () => throw new InvalidArgumentException(__('The file is larger than :size MB.', ['size' => round($maxBytes / 1048576, 1)]));

        return [
            'allow_redirects' => [
                'max' => 5,
                'protocols' => ['http', 'https'],
                'on_redirect' => fn ($request, $response, UriInterface $uri) => self::check((string) $uri),
            ],
            'on_headers' => function (ResponseInterface $response) use ($maxBytes, $tooLarge): void {
                if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                    $tooLarge();
                }
            },
            'progress' => function ($downloadTotal, int $downloaded) use ($maxBytes, $tooLarge): void {
                if ($downloaded > $maxBytes) {
                    $tooLarge();
                }
            },
        ];
    }

    /**
     * Fetches an address into a temporary file after the checks above.
     *
     * @throws InvalidArgumentException
     */
    public static function download(string $url, int $maxBytes, int $timeout = 30): string
    {
        self::check($url);

        try {
            $response = Http::timeout($timeout)->withOptions(self::options($maxBytes))->get($url);
        } catch (\Throwable $e) {
            throw self::reason($e) ?? new InvalidArgumentException(__('The file could not be fetched.'));
        }

        $body = $response->body();

        if (! $response->successful() || strlen($body) > $maxBytes) {
            throw new InvalidArgumentException(__('The file could not be fetched (status :status).', ['status' => $response->status()]));
        }

        $path = tempnam(sys_get_temp_dir(), 'download');
        file_put_contents($path, $body);

        return $path;
    }

    /**
     * Our own refusal (internal address, too large) wrapped in the HTTP
     * client's exception, or null when the error was something else.
     */
    public static function reason(\Throwable $e): ?InvalidArgumentException
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof InvalidArgumentException) {
                return $cause;
            }
        }

        return null;
    }

    /**
     * The IP addresses a host name points to (or the address itself). A name
     * that does not resolve gives none: fetching it fails anyway.
     *
     * @return list<string>
     */
    private static function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }
}
