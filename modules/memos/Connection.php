<?php
if (!defined('DOKU_INC')) die();

/** Memos 0.31 connection. Credentials never leave the PHP backend. */
class FokusButler_Memos_Connection
{
    private $url;
    private $token;
    public function __construct($url = null, $token = null)
    {
        $this->url = self::validateUrl($url ?? self::url());
        $this->token = $token ?? self::credential()['token'];
        if ($this->token === '' || strlen($this->token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $this->token)) {
            throw new RuntimeException('Configure a Memos personal access token in Fokus Butler → Jots.', 400);
        }
    }
    public static function credential()
    {
        $file = '/run/secrets/memos_api_token';
        $secret = is_readable($file) ? trim((string)file_get_contents($file)) : '';
        if ($secret !== '') return ['token' => $secret, 'source' => 'secret'];
        $token = trim((string)getenv('MEMOS_API_TOKEN'));
        if ($token !== '') return ['token' => $token, 'source' => 'environment'];
        $token = trim((string)FokusButler_Modules::config('memos', 'api_token', ''));
        return ['token' => $token, 'source' => $token === '' ? 'missing' : 'configuration'];
    }
    public static function url() { return trim((string)(getenv('MEMOS_URL') ?: FokusButler_Modules::config('memos', 'url', ''))); }
    public static function validateUrl($url)
    {
        $parts = parse_url(trim((string)$url));
        if (!$parts || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new RuntimeException('Enter the Memos instance URL, without an API path or credentials.', 400);
        }
        $url = rtrim($url, '/');
        if (preg_match('~/api(?:/v1)?$~', $url)) throw new RuntimeException('Use the instance URL without /api/v1.', 400);
        return $url;
    }
    public function baseUrl() { return $this->url; }
    public function scope() { return hash('sha256', $this->url . "\n" . $this->token); }
    public static function resource($name, $kind = 'memos')
    {
        if (!is_string($name) || !preg_match('~^' . $kind . '/[a-zA-Z0-9][a-zA-Z0-9-]{0,35}$~D', $name)) {
            throw new RuntimeException('Invalid Memos resource.', 400);
        }
        return $name;
    }
    public function request($method, $path, $data = null)
    {
        if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)
            || !preg_match('~^/(?:auth/me|memos(?:/[a-zA-Z0-9-]+)?)(?:\?.*)?$~D', $path)) throw new RuntimeException('Invalid Memos API request.', 400);
        $http = new \dokuwiki\HTTP\DokuHTTPClient();
        $http->timeout = 12;
        $http->max_redirect = 0;
        $http->max_bodysize = 16 * 1024 * 1024;
        $http->headers['Authorization'] = 'Bearer ' . $this->token;
        $http->headers['Accept'] = 'application/json';
        $http->headers['Content-Type'] = 'application/json';
        $ok = $http->sendRequest($this->url . '/api/v1' . $path, $data === null ? '' : json_encode($data, JSON_THROW_ON_ERROR), $method);
        $raw = $ok ? $http->resp_body : false;
        $status = (int)$http->status;
        if ($status < 200 || $status >= 300 || $raw === false) {
            $message = $status === 401 || $status === 403 ? 'Memos rejected the token or this operation’s permissions.'
                : ($status === 409 ? 'This note changed in Memos. Review the latest version before saving again.'
                : ($status === 404 ? 'This note is no longer available in Memos.'
                : ($status === 429 ? 'Memos is rate-limiting requests. Your changes remain saved locally; retry later.' : 'Memos could not be reached. Your local copy is retained.')));
            throw new RuntimeException($message, $status ?: 502);
        }
        // Memos DeleteMemo returns google.protobuf.Empty and may reply with an
        // empty 2xx body depending on the HTTP gateway version.
        if ($method === 'DELETE' && trim((string)$raw) === '') return [];
        $result = json_decode((string)$raw, true);
        if (!is_array($result)) throw new RuntimeException('Memos returned an invalid response. The local copy is retained.', 502);
        return $result;
    }
    /** Stream attachment bytes to private disk; never forward a token across redirects. */
    public function download(array $attachment, $destination, $limit)
    {
        self::resource($attachment['name'] ?? '', 'attachments');
        $external = $attachment['externalLink'] ?? '';
        $url = $external ?: $this->url . '/file/' . $attachment['name'] . '/' . rawurlencode($attachment['filename']);
        $base = parse_url($this->url);
        $deadline = microtime(true) + 30;
        if (!function_exists('curl_init')) throw new RuntimeException('Enable PHP cURL to cache Memos attachments.');
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $parts = parse_url($url);
            if (!$parts || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) {
                throw new RuntimeException('The attachment URL is not supported.');
            }
            $sameOrigin = $parts['scheme'] === $base['scheme'] && $parts['host'] === $base['host']
                && ($parts['port'] ?? null) === ($base['port'] ?? null);
            $addresses = $sameOrigin ? [] : self::publicExternalHost($parts['host']);
            $handle = fopen($destination, 'wb');
            if (!$handle) throw new RuntimeException('Could not create the local attachment file.');
            $bytes = 0; $location = '';
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => max(1, min(25, (int)($deadline - microtime(true)))),
                CURLOPT_HTTPHEADER => $sameOrigin ? ['Authorization: Bearer ' . $this->token] : [],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$location) {
                    if (stripos($line, 'location:') === 0) $location = trim(substr($line, 9));
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, $chunk) use ($handle, &$bytes, $limit) {
                    $bytes += strlen($chunk);
                    return $bytes > $limit ? 0 : fwrite($handle, $chunk);
                }]);
            if ($addresses) {
                // Pin the validated public address so DNS cannot change between validation and download.
                $address = strpos($addresses[0], ':') !== false ? '[' . $addresses[0] . ']' : $addresses[0];
                curl_setopt($curl, CURLOPT_RESOLVE, [$parts['host'] . ':' . ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)) . ':' . $address]);
                curl_setopt($curl, CURLOPT_PROXY, '');
            }
            $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl); fclose($handle);
            if ($ok !== false && $status === 200) return $bytes;
            @unlink($destination);
            if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
                if (strpos($location, '//') === 0) $location = $parts['scheme'] . ':' . $location;
                elseif (strpos($location, '/') === 0) $location = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $location;
                if (!preg_match('~^https?://~', $location)) throw new RuntimeException('The attachment redirect is not supported.');
                if ($parts['scheme'] === 'https' && strpos($location, 'https://') !== 0) throw new RuntimeException('An attachment redirected to an insecure URL.');
                $url = $location;
                continue;
            }
            throw new RuntimeException($bytes > $limit ? 'Attachment exceeds the configured local size limit.' : 'This attachment could not be downloaded. Retry synchronization.');
        }
        throw new RuntimeException('Too many attachment redirects.');
    }

    private static function publicExternalHost($host)
    {
        if ($host === '' || strtolower($host) === 'localhost') throw new RuntimeException('The external attachment host is not public.');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(gethostbynamel($host) ?: [], array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
        if (!$addresses) throw new RuntimeException('The attachment host could not be resolved.');
        foreach ($addresses as $address) if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('The external attachment host is not public.');
        return $addresses;
    }
}
