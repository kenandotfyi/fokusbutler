<?php
if (!defined('DOKU_INC')) die();

/** Literal full-text search over one authorized user's local mirror. */
class FokusButler_Memos_Search
{
    private static function plain($text)
    {
        $text = preg_replace('~!?\[([^\]]*)\]\([^)]*\)~u', '$1', (string)$text);
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/mu', '', $text);
        return html_entity_decode(str_replace(['**', '__', '~~', '`'], '', strip_tags($text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    public static function query(array $memos, $query, $offset = 0, $limit = 20, $mode = 'words')
    {
        $query = trim((string)$query);
        if (strlen($query) > 512 || preg_match('//u', $query) !== 1) throw new InvalidArgumentException('Search is limited to 512 UTF-8 bytes.');
        if (!in_array($mode, ['words', 'phrase'], true)) throw new InvalidArgumentException('Invalid search mode.');
        $offset = max(0, min(1000, (int)$offset)); $limit = max(1, min(50, (int)$limit));
        if ($query === '') return ['results'=>[], 'total'=>0, 'hasMore'=>false];
        $terms = $mode === 'phrase' ? [preg_replace('/\s+/u', ' ', $query)] : preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        $rows = [];
        foreach ($memos as $memo) {
            $name = $memo['name'] ?? '';
            if (!preg_match('~^memos/[a-zA-Z0-9][a-zA-Z0-9-]{0,35}$~D', $name) || !in_array($memo['state'] ?? 'NORMAL', ['NORMAL','ARCHIVED'], true)) continue;
            $plain = trim(self::plain($memo['content'] ?? ''));
            $lines = preg_split('/\R/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
            $title = trim($lines[0] ?? '') ?: 'Untitled jot';
            preg_match('/^.{0,120}/us', $title, $cut); $title = $cut[0];
            $text = preg_replace('/\s+/u', ' ', $plain);
            $tags = array_values(array_filter($memo['tags'] ?? [], 'is_string'));
            $haystack = $text . ' ' . implode(' ', $tags);
            $score = 0;
            foreach ($terms as $term) {
                $pattern = '/' . preg_quote($term, '/') . '/iu';
                if (!preg_match($pattern, $haystack)) continue 2;
                if (preg_match($pattern, $title)) $score++;
            }
            preg_match('/.{0,100}' . preg_quote($terms[0], '/') . '.{0,500}/isu', $text, $snippet);
            if (!$snippet) preg_match('/^.{0,600}/us', $text, $snippet);
            $rows[] = ['provider'=>'jots','kind'=>'jots','key'=>$name,'title'=>$title,'text'=>$snippet[0] ?? '',
                'date'=>$memo['createTime'] ?? '', 'state'=>$memo['state'] ?? 'NORMAL', 'tags'=>$tags, '_score'=>$score];
        }
        usort($rows, static function ($a, $b) { return $b['_score'] <=> $a['_score'] ?: strcmp($b['date'], $a['date']) ?: strcmp($a['key'], $b['key']); });
        $total = count($rows); $rows = array_slice($rows, $offset, $limit);
        foreach ($rows as &$row) unset($row['_score']); unset($row);
        return ['results'=>$rows, 'total'=>$total, 'hasMore'=>$offset + $limit < $total];
    }
}
