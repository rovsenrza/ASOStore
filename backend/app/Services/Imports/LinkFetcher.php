<?php

namespace App\Services\Imports;

/**
 * Downloads an IPA from a customer-supplied link without letting the link reach our own network
 * (SSRF). Only https on port 443; every hop's host is resolved here, each address must be public,
 * and curl is pinned to the checked address so DNS cannot change between check and connect.
 * Redirects are followed by hand so each one is checked the same way. The body is capped while it
 * streams, and must start like a zip (an IPA is one) so a web page is not mistaken for the file.
 */
class LinkFetcher
{
    public const MAX_REDIRECTS = 5;

    /** Ranges no link may reach, besides what PHP flags as private or reserved. */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32',
        'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Rewrites share links of the clouds we support into their direct-download form, then checks
     * the link is one we may fetch. Returns the URL to download.
     */
    public function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            throw new LinkFetchFailed('Ссылка выглядит неверно.');
        }
        $host = strtolower($parts['host']);

        // Dropbox: ?dl=0 opens a web page; dl=1 is the file.
        if (in_array($host, ['www.dropbox.com', 'dropbox.com'], true)) {
            parse_str($parts['query'] ?? '', $query);
            $query['dl'] = '1';
            $url = 'https://'.$host.($parts['path'] ?? '/').'?'.http_build_query($query);
        }
        // Google Drive: /file/d/{id}/view or ?id={id} → the direct download host.
        if ($host === 'drive.google.com') {
            parse_str($parts['query'] ?? '', $query);
            $id = preg_match('~/file/d/([A-Za-z0-9_-]+)~', $parts['path'] ?? '', $match) === 1 ? $match[1] : ($query['id'] ?? null);
            if (is_string($id) && preg_match('/^[A-Za-z0-9_-]+$/', $id) === 1) {
                $url = 'https://drive.usercontent.google.com/download?'.http_build_query(['id' => $id, 'export' => 'download', 'confirm' => 't']);
            }
        }

        $this->assertAcceptable($url);

        return $url;
    }

    /** Syntax checks that need no network: https, a host name, no credentials, port 443. */
    public function assertAcceptable(string $url): void
    {
        if (strlen($url) > 2048) {
            throw new LinkFetchFailed('Ссылка слишком длинная.');
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new LinkFetchFailed('Ссылка выглядит неверно.');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw new LinkFetchFailed('Нужна ссылка, начинающаяся с https://.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new LinkFetchFailed('Ссылки с логином и паролем не поддерживаются.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new LinkFetchFailed('Ссылки на нестандартный порт не поддерживаются.');
        }
        $host = trim($parts['host'], '[]');
        if ($host === '' || strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.localhost')
            || str_ends_with(strtolower($host), '.local') || str_ends_with(strtolower($host), '.internal')) {
            throw new LinkFetchFailed('Эта ссылка недоступна для загрузки.');
        }
    }

    /**
     * Resolves the host and returns one public address to connect to; refuses the host when any
     * of its addresses is private, so a name cannot mix a public and an internal record.
     */
    public function vettedAddress(string $host): string
    {
        $host = trim($host, '[]');
        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw LinkFetchFailed::retryable('Не удалось найти сервер по ссылке.');
        }
        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw new LinkFetchFailed('Эта ссылка недоступна для загрузки.');
            }
        }
        $ipv4 = array_values(array_filter($addresses, fn (string $address) => str_contains($address, '.')));

        return $ipv4[0] ?? $addresses[0];
    }

    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        foreach (self::BLOCKED as $cidr) {
            if (self::inRange($address, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $address, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $ip = inet_pton($address);
        $net = inet_pton($network);
        if ($ip === false || $net === false || strlen($ip) !== strlen($net)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($ip, $net, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ip[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    /**
     * Downloads the link into $destination (a writable local path).
     *
     * @return array{size_bytes: int, sha256: string, filename: string}
     */
    public function fetch(string $url, string $destination, int $maxBytes): array
    {
        $url = $this->normalize($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $response = $this->request($url, $destination, $maxBytes);
            if ($response['location'] === null) {
                return $this->accept($response, $destination, $url);
            }
            $url = $this->resolveLocation($url, $response['location']);
            $this->assertAcceptable($url);
        }

        throw new LinkFetchFailed('Слишком много перенаправлений по ссылке.');
    }

    /**
     * One request with no redirect following. Returns the redirect target, or null once the body
     * has been written to $destination.
     *
     * @return array{status: int, location: ?string, content_type: ?string, disposition: ?string}
     */
    protected function request(string $url, string $destination, int $maxBytes): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $address = $this->vettedAddress($host);
        $file = fopen($destination, 'wb');
        if ($file === false) {
            throw LinkFetchFailed::retryable('Сервер временно не может сохранить файл.');
        }

        $headers = [];
        $written = 0;
        $tooLarge = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => [trim($host, '[]').':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)],
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 1500,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'RuAppStore-Import/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream, */*'],
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($file, &$written, &$tooLarge, $maxBytes) {
                $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                if ($status >= 300 && $status < 400) {
                    return strlen($chunk); // A redirect body is discarded.
                }
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    $tooLarge = true;

                    return 0; // Aborts the transfer.
                }

                return fwrite($file, $chunk);
            },
        ]);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        fclose($file);

        if ($tooLarge) {
            throw new LinkFetchFailed('Файл по ссылке больше 3 ГБ.');
        }
        if ($ok === false || $error !== 0) {
            throw in_array($error, [CURLE_SSL_CACERT, CURLE_SSL_PEER_CERTIFICATE], true)
                ? new LinkFetchFailed('У сайта по ссылке неверный сертификат.')
                : LinkFetchFailed::retryable('Не удалось скачать файл по ссылке. Попробуем ещё раз.');
        }
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            if (! isset($headers['location']) || $headers['location'] === '') {
                throw new LinkFetchFailed('Сайт по ссылке вернул неверное перенаправление.');
            }

            return ['status' => $status, 'location' => $headers['location'], 'content_type' => null, 'disposition' => null];
        }
        if ($status === 404 || $status === 410) {
            throw new LinkFetchFailed('Файл по ссылке не найден.');
        }
        if ($status === 401 || $status === 403) {
            throw new LinkFetchFailed('Файл по ссылке закрыт. Нужна общедоступная ссылка.');
        }
        if ($status >= 500 || $status === 429) {
            throw LinkFetchFailed::retryable('Сайт по ссылке временно недоступен. Попробуем ещё раз.');
        }
        if ($status !== 200) {
            throw new LinkFetchFailed('Сайт по ссылке не отдал файл (код '.$status.').');
        }

        return ['status' => $status, 'location' => null, 'content_type' => $headers['content-type'] ?? null, 'disposition' => $headers['content-disposition'] ?? null];
    }

    /**
     * @param  array{status: int, location: ?string, content_type: ?string, disposition: ?string}  $response
     * @return array{size_bytes: int, sha256: string, filename: string}
     */
    private function accept(array $response, string $destination, string $url): array
    {
        $size = (int) filesize($destination);
        $handle = fopen($destination, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 4);
        if ($handle !== false) {
            fclose($handle);
        }
        if ($size === 0 || $magic !== "PK\x03\x04") {
            throw new LinkFetchFailed('По ссылке не файл IPA. Нужна прямая ссылка на скачивание.');
        }

        return [
            'size_bytes' => $size,
            'sha256' => (string) hash_file('sha256', $destination),
            'filename' => $this->filename($response['disposition'], $url),
        ];
    }

    private function filename(?string $disposition, string $url): string
    {
        $name = null;
        if ($disposition !== null) {
            if (preg_match("/filename\\*=(?:UTF-8'')?([^;]+)/i", $disposition, $match) === 1) {
                $name = rawurldecode(trim($match[1], " \"'"));
            } elseif (preg_match('/filename="?([^";]+)"?/i', $disposition, $match) === 1) {
                $name = trim($match[1]);
            }
        }
        $name ??= rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        $name = basename(str_replace('\\', '/', $name));
        if (preg_match('/\.ipa$/i', $name) !== 1) {
            $name = ($name === '' || $name === '/' || $name === 'download' ? 'Импорт' : preg_replace('/\.[^.]*$/', '', $name)).'.ipa';
        }

        return mb_substr($name, -120);
    }

    private function resolveLocation(string $base, string $location): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $directory = preg_replace('~/[^/]*$~', '/', $parts['path'] ?? '/');

        return $origin.$directory.$location;
    }
}
