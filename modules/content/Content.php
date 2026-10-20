<?php
if (!defined('DOKU_INC')) die();

/** Full article content, with a small allowlist for read-only preview HTML. */
final class FokusButler_Content_Provider
{
    public static function wikipediaTarget($url)
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || !preg_match('/^([a-z][a-z0-9-]{0,30})(?:\.m)?\.wikipedia\.org$/D', strtolower($parts['host'] ?? ''), $match)) {
            throw new RuntimeException('Unsupported Wikipedia link.', 400);
        }
        $path = $parts['path'] ?? '';
        if (strpos($path, '/wiki/') === 0) $title = rawurldecode(substr($path, 6));
        elseif ($path === '/w/index.php') { parse_str($parts['query'] ?? '', $query); $title = $query['title'] ?? ''; }
        else $title = '';
        if (!is_string($title) || trim($title) === '' || strlen($title) > 600 || preg_match('/[\x00-\x1f\x7f]/', $title)) {
            throw new RuntimeException('Unsupported Wikipedia article.', 400);
        }
        $host = $match[1] . '.wikipedia.org';
        return ['host' => $host, 'title' => $title, 'url' => 'https://' . $host . '/wiki/' . str_replace('%2F', '/', rawurlencode($title))];
    }

    public static function wikipedia($url)
    {
        global $conf;
        $target = self::wikipediaTarget($url);
        $file = rtrim($conf['cachedir'], '/') . '/fokusbutler/preview/' . hash('sha256', $target['url']) . '.json';
        if (is_file($file) && time() - filemtime($file) < 3600) {
            $saved = json_decode((string)file_get_contents($file), true);
            if (is_array($saved) && isset($saved['html'], $saved['title'])) return $saved;
        }
        $http = new \dokuwiki\HTTP\DokuHTTPClient();
        $http->timeout = 10;
        $http->max_redirect = 0;
        $http->max_bodysize = 8 * 1024 * 1024;
        $http->agent = 'FokusButler/0.1 (DokuWiki article previews; https://www.dokuwiki.org/)';
        $http->headers['Accept'] = 'text/html';
        $requestUrl = 'https://' . $target['host'] . '/w/rest.php/v1/page/' . rawurlencode($target['title']) . '/html';
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $html = $http->get($requestUrl);
            if (!in_array((int)$http->status, [301, 302, 303, 307, 308], true)) break;
            $requestUrl = self::wikipediaRedirect($http->resp_headers['location'] ?? '', $target['host']);
        }
        if ($html === false || (int)$http->status !== 200) {
            throw new RuntimeException((int)$http->status === 404 ? 'Wikipedia article not found.' : 'Wikipedia could not be reached. Open the article to read it.', (int)$http->status === 404 ? 404 : 502);
        }
        $result = [
            'provider' => 'wikipedia', 'title' => str_replace('_', ' ', $target['title']),
            'url' => $target['url'], 'html' => self::sanitize($html, $target['host']),
            'attribution' => 'Wikipedia contributors · CC BY-SA · Article and attribution',
        ];
        io_mkdir_p(dirname($file));
        io_saveFile($file, json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
        return $result;
    }

    public static function wikipediaSearch($language, $query)
    {
        global $conf;
        if (!is_string($language) || !preg_match('/^[a-z][a-z0-9-]{0,30}$/D', $language)
            || !is_string($query) || trim($query) === '' || strlen($query) > 600 || preg_match('/[\x00-\x1f\x7f]/', $query)) {
            throw new RuntimeException('Unsupported Wikipedia search.', 400);
        }
        $query = trim($query);
        $host = $language . '.wikipedia.org';
        $url = 'https://' . $host . '/w/index.php?' . http_build_query(['search' => $query], '', '&', PHP_QUERY_RFC3986);
        $file = rtrim($conf['cachedir'], '/') . '/fokusbutler/preview/search-' . hash('sha256', $url) . '.json';
        if (is_file($file) && time() - filemtime($file) < 300) {
            $saved = json_decode((string)file_get_contents($file), true);
            if (is_array($saved) && isset($saved['results'], $saved['title'])) return $saved;
        }
        $http = new \dokuwiki\HTTP\DokuHTTPClient();
        $http->timeout = 10;
        $http->max_redirect = 0;
        $http->max_bodysize = 1024 * 1024;
        $http->agent = 'FokusButler/1.0 (DokuWiki Wikipedia search)';
        $http->headers['Accept'] = 'application/json';
        $response = $http->get('https://' . $host . '/w/rest.php/v1/search/page?' . http_build_query(['q' => $query, 'limit' => 20], '', '&', PHP_QUERY_RFC3986));
        $data = is_string($response) ? json_decode($response, true) : null;
        if ((int)$http->status !== 200 || !is_array($data) || !isset($data['pages']) || !is_array($data['pages'])) {
            throw new RuntimeException('Wikipedia search could not be loaded. Please try again.', 502);
        }
        $results = [];
        foreach (array_slice($data['pages'], 0, 20) as $page) {
            $title = $page['key'] ?? $page['title'] ?? null;
            if (!is_string($title) || trim($title) === '' || strlen($title) > 600 || preg_match('/[\x00-\x1f\x7f]/', $title)) continue;
            $results[] = [
                'title' => is_string($page['title'] ?? null) ? $page['title'] : str_replace('_', ' ', $title),
                'url' => 'https://' . $host . '/wiki/' . str_replace('%2F', '/', rawurlencode($title)),
                'description' => is_string($page['description'] ?? null) ? $page['description'] : '',
                'excerpt' => is_string($page['excerpt'] ?? null) ? html_entity_decode(strip_tags($page['excerpt']), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '',
            ];
        }
        $result = ['provider' => 'wikipedia', 'kind' => 'search', 'title' => 'Search: ' . $query,
            'query' => $query, 'language' => $language, 'url' => $url, 'results' => $results];
        io_mkdir_p(dirname($file));
        io_saveFile($file, json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
        return $result;
    }

    /** Follow article aliases only within the same edition's article HTML API. */
    public static function wikipediaRedirect($location, $host)
    {
        if (!is_string($location)) throw new RuntimeException('Wikipedia returned an invalid article redirect.', 502);
        if (strpos($location, '/w/rest.php/v1/page/') === 0) $location = 'https://' . $host . $location;
        $parts = parse_url($location);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== $host
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || !preg_match('~^/w/rest\.php/v1/page/[^\x00-\x20]+/html$~D', $parts['path'] ?? '')) {
            throw new RuntimeException('Wikipedia returned an unsupported article redirect.', 502);
        }
        return $location;
    }

    public static function sanitize($html, $wikipediaHost = '')
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) return '';
        $allowed = array_flip(explode(' ', 'article section div span p h1 h2 h3 h4 h5 h6 a button img figure figcaption table caption thead tbody tfoot tr th td ul ol li dl dt dd blockquote pre code strong b em i u s del ins small sub sup abbr time br hr details summary svg path math mrow mi mo mn mfrac msup msub msubsup msqrt mroot mtext mspace mtable mtr mtd mover munder munderover semantics annotation'));
        $drop = array_flip(explode(' ', 'script style link meta base iframe object embed form input textarea select option video audio source canvas template noscript'));
        $walk = function ($parent) use (&$walk, $allowed, $drop, $wikipediaHost) {
            foreach (iterator_to_array($parent->childNodes) as $node) {
                if ($node instanceof DOMComment || $node instanceof DOMProcessingInstruction) { $parent->removeChild($node); continue; }
                if (!$node instanceof DOMElement) continue;
                $tag = strtolower($node->tagName);
                if (isset($drop[$tag])) { $parent->removeChild($node); continue; }
                $walk($node);
                if (!isset($allowed[$tag])) {
                    while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
                    $parent->removeChild($node); continue;
                }
                foreach (iterator_to_array($node->attributes) as $attribute) {
                    $name = strtolower($attribute->name);
                    if (!in_array($name, ['id', 'class', 'title', 'href', 'src', 'alt', 'width', 'height', 'colspan', 'rowspan', 'open', 'hidden', 'type', 'datetime', 'aria-expanded', 'data-wiki-id', 'data-fokus-fold', 'data-fokus-fold-toggle', 'data-default-open', 'viewbox', 'd', 'fill', 'opacity', 'focusable', 'display', 'encoding', 'xmlns'], true)
                        || ($name === 'href' && $tag !== 'a') || ($name === 'src' && $tag !== 'img')) $node->removeAttribute($attribute->name);
                    elseif ($name === 'fill' && !preg_match('/^(?:currentcolor|#[0-9a-f]{3,8}|[a-z]{1,20})$/i', $attribute->value)) $node->removeAttribute($attribute->name);
                }
                foreach (['href', 'src'] as $attribute) {
                    if (!$node->hasAttribute($attribute)) continue;
                    $value = self::safeUrl($node->getAttribute($attribute), $wikipediaHost, $tag === 'a' && $attribute === 'href' && $wikipediaHost === '');
                    if ($value === '') $node->removeAttribute($attribute);
                    else $node->setAttribute($attribute, $value);
                }
                if ($tag === 'img') { $node->setAttribute('loading', 'lazy'); $node->setAttribute('referrerpolicy', 'no-referrer'); }
                if ($tag === 'a' && $wikipediaHost !== '') {
                    $node->setAttribute('rel', 'noopener noreferrer');
                    if (strpos($node->getAttribute('href'), '#') !== 0) $node->setAttribute('target', '_blank');
                }
            }
        };
        $walk($body);
        $result = '';
        foreach ($body->childNodes as $node) $result .= $doc->saveHTML($node);
        return $result;
    }

    private static function safeUrl($value, $host, $allowZotero = false)
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '' || preg_match('/[\x00-\x20\x7f]/', $value)) return '';
        if ($allowZotero && preg_match('~^zotero://(?:open-pdf|select)/(?:library|groups/[0-9]+)/items/[A-Z0-9]{8}(?:[?#].*)?$~i', $value)) return $value;
        if ($value[0] === '#') return $value;
        if (strpos($value, '//') === 0) $value = 'https:' . $value;
        if ($host !== '') {
            if (strpos($value, './') === 0) $value = 'https://' . $host . '/wiki/' . substr($value, 2);
            elseif ($value[0] === '/') $value = 'https://' . $host . $value;
        }
        if ($value[0] === '/' && strpos($value, '//') !== 0) return $value;
        if (!preg_match('~^https?://~i', $value)) return '';
        $parsed = parse_url($value);
        if (!$parsed || isset($parsed['user']) || isset($parsed['pass'])) return '';
        return $value;
    }
}
