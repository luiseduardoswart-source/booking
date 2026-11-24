<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * Web addresses typed in by users (a listing to import, an iCal calendar,
 * photo links) that the server itself fetches. Only http(s), never an
 * address inside the server's own network (127.0.0.1, 10.x, 192.168.x, the
 * cloud metadata address 169.254.169.254), also not after a redirect, and
 * never more than a set size. Otherwise someone with an admin account could
 * make the server read internal services and show them the answer.
 *
 * The name is looked up once and the connection goes to exactly that
 * address (pinned): a name that points somewhere else a moment later (DNS
 * rebinding) cannot slip past the check. A name that cannot be looked up is
 * refused, rather than leaving the lookup to the HTTP client. Redirects are
 * followed here, each step checked and pinned the same way.
 */
final class OutboundUrl
{
    private const MAX_REDIRECTS = 5;

    /** @var (Closure(string): list<string>)|null replaces the DNS lookup (tests) */
    private static ?Closure $resolver = null;

    /**
     * The addresses a name points to, for tests that cannot rely on DNS.
     * Null restores the real lookup.
     *
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /**
     * @return list<string> the addresses the host name points to, all public
     *
     * @throws InvalidArgumentException when the address may not be fetched
     */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(__('Only web addresses starting with http:// or https:// can be fetched.'));
        }

        $ips = self::addressesOf($host);

        if ($ips === []) {
            throw new InvalidArgumentException(__('This address could not be found.'));
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException(__('This address points inside the server\'s own network and cannot be fetched.'));
            }
        }

        return $ips;
    }

    /**
     * Fetches an address after the checks above: pinned to the checked
     * address, redirects followed one by one, never more than $maxBytes.
     *
     * @param  array<string, string>  $headers
     *
     * @throws InvalidArgumentException when an address (or a redirect) may not be fetched or the answer is too large
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public static function get(string $url, int $maxBytes, array $headers = [], int $timeout = 30): Response
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ips = self::check($url);

            $response = Http::timeout($timeout)
                ->withHeaders($headers)
                ->withOptions([...self::options($maxBytes), 'curl' => [CURLOPT_RESOLVE => [self::pin($url, $ips[0])]]])
                ->get($url);

            if (! $response->redirect() || ($location = $response->header('Location')) === '') {
                return $response;
            }

            $url = (string) \GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($url), new \GuzzleHttp\Psr7\Uri($location));
        }

        throw new InvalidArgumentException(__('The address redirects too often.'));
    }

    /**
     * Options for the HTTP client: no redirects of its own (get() follows
     * them, checked), and the download stops beyond $maxBytes.
     *
     * @return array<string, mixed>
     */
    public static function options(int $maxBytes): array
    {
        $tooLarge = fn () => throw new InvalidArgumentException(__('The file is larger than :size MB.', ['size' => round($maxBytes / 1048576, 1)]));

        return [
            'allow_redirects' => false,
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
        try {
            $response = self::get($url, $maxBytes, timeout: $timeout);
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

    /** "host:port:address" for curl: connect to this address, whatever DNS says later. */
    private static function pin(string $url, string $ip): string
    {
        $parts = parse_url($url);
        $host = trim((string) $parts['host'], '[]');
        $port = $parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80);

        return $host.':'.$port.':'.(str_contains($ip, ':') ? "[{$ip}]" : $ip);
    }

    /**
     * The IP addresses a host name points to (or the address itself); none
     * when the name cannot be looked up. gethostbynamel() also reads the
     * server's hosts file, but only for IPv4: a name that only points to an
     * IPv6 address there (localhost6) gives none, and is refused.
     *
     * @return list<string>
     */
    private static function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (self::$resolver !== null) {
            return array_values((self::$resolver)($host));
        }

        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
