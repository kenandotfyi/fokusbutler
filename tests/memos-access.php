<?php
namespace dokuwiki\HTTP {
    class DokuHTTPClient {
        public static $response = ['status' => 200, 'body' => '{"memos":[]}'];
        public static $last;
        public $timeout, $max_redirect, $max_bodysize, $status, $resp_body, $headers = [];
        public function sendRequest($url, $data, $method) {
            self::$last = [$url, $data, $method, $this->headers, $this->max_redirect];
            $this->status = self::$response['status']; $this->resp_body = self::$response['body']; return true;
        }
    }
}
namespace {
    define('DOKU_INC', __DIR__);
    class DokuWiki_Action_Plugin {}
    class FokusButler_Modules {
        public static $values = ['wiki_user' => 'ken']; public static $enabled = true;
        public static function config($module, $key, $default = false) { return self::$values[$key] ?? $default; }
        public static function enabled($module) { return self::$enabled; }
    }
    $admin = false;
    function auth_isadmin() { global $admin; return $admin; }
    require dirname(__DIR__) . '/core/ModuleContext.php';
    require dirname(__DIR__) . '/modules/memos/Action.php';
    $checks = 0;
    function check($condition) { global $checks; if (!$condition) throw new RuntimeException('Access/connection assertion failed at ' . ($checks + 1)); $checks++; }
    function rejects($run, $code) { try { $run(); } catch (RuntimeException $e) { check($e->getCode() === $code); return; } throw new RuntimeException('Expected rejection'); }
    unset($_SERVER['REMOTE_USER']); check(!FokusButler_Module_Memos_Action::allowed());
    check((new FokusButler_Module_Memos_Action())->getSummaryStats() === null);
    check(!(new FokusButler_Module_Memos_Action())->searchAvailable());
    rejects(function () { (new FokusButler_Module_Memos_Action())->searchLocal('private'); }, 403);
    $_SERVER['REMOTE_USER'] = 'another-reader'; check(!FokusButler_Module_Memos_Action::allowed());
    rejects(function () { (new FokusButler_Module_Memos_Action())->searchLocal('private'); }, 403);
    $_SERVER['REMOTE_USER'] = 'ken'; check(FokusButler_Module_Memos_Action::allowed());
    FokusButler_Modules::$enabled = false; check(!FokusButler_Module_Memos_Action::allowed());
    rejects(function () { (new FokusButler_Module_Memos_Action())->searchLocal('private'); }, 403); FokusButler_Modules::$enabled = true;
    FokusButler_Modules::$values['wiki_user'] = ''; check(!FokusButler_Module_Memos_Action::allowed()); $admin = true; check(FokusButler_Module_Memos_Action::allowed());
    $client = new FokusButler_Memos_Connection('https://memos.example', 'fixture-token');
    check($client->request('GET', '/memos?pageSize=100') === ['memos' => []]);
    $request = \dokuwiki\HTTP\DokuHTTPClient::$last;
    check($request[0] === 'https://memos.example/api/v1/memos?pageSize=100');
    check($request[3]['Authorization'] === 'Bearer fixture-token'); check($request[4] === 0);
    \dokuwiki\HTTP\DokuHTTPClient::$response = ['status' => 200, 'body' => '{"name":"memos/test","content":"hello"}'];
    $client->request('PATCH', '/memos/test?updateMask=content', ['content' => 'hello']);
    check(\dokuwiki\HTTP\DokuHTTPClient::$last[2] === 'PATCH'); check(json_decode(\dokuwiki\HTTP\DokuHTTPClient::$last[1], true) === ['content' => 'hello']);
    \dokuwiki\HTTP\DokuHTTPClient::$response = ['status' => 403, 'body' => 'fixture-token must not leak'];
    try { $client->request('GET', '/memos'); } catch (RuntimeException $e) { check($e->getCode() === 403); check(strpos($e->getMessage(), 'fixture-token') === false); }
    rejects(function () use ($client) { $client->request('GET', '/admin/users'); }, 400);
    $original = getenv('MEMOS_API_TOKEN');
    try {
        FokusButler_Modules::$values['api_token'] = 'saved-fixture'; putenv('MEMOS_API_TOKEN=environment-fixture');
        check(FokusButler_Memos_Connection::credential()['source'] === 'environment');
        check(FokusButler_Memos_Connection::credential()['token'] === 'environment-fixture');
        putenv('MEMOS_API_TOKEN'); check(FokusButler_Memos_Connection::credential()['token'] === 'saved-fixture');
    } finally { $original === false ? putenv('MEMOS_API_TOKEN') : putenv('MEMOS_API_TOKEN=' . $original); }
    echo "PASS: $checks Memos account-access and HTTP-contract checks.\n";
}
