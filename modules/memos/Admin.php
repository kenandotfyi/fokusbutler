<?php
if (!defined('DOKU_INC')) die();
require_once __DIR__ . '/Connection.php';
use dokuwiki\plugin\config\core\Configuration;

class FokusButler_Module_Memos_Admin extends DokuWiki_Admin_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'memos';
    private $errors = [];
    private $notice = '';
    public function handle()
    {
        global $INPUT;
        if (!$INPUT->post->has('memos_save') && !$INPUT->post->has('memos_test')) return;
        if (!auth_isadmin() || !checkSecurityToken()) { $this->errors[] = 'Reload this page before saving settings.'; return; }
        try {
            $url = FokusButler_Memos_Connection::validateUrl(getenv('MEMOS_URL') ?: $INPUT->post->str('url'));
            $credential = FokusButler_Memos_Connection::credential();
            $token = trim($INPUT->post->str('api_token'));
            $deployed = in_array($credential['source'], ['secret', 'environment'], true);
            if ($deployed) $token = '';
            if ($token !== '' && (strlen($token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $token))) throw new RuntimeException('Enter a valid personal access token.');
            if ($INPUT->post->has('memos_test')) {
                $client = new FokusButler_Memos_Connection($url, $token ?: $credential['token']);
                $response = $client->request('GET', '/auth/me');
                if (empty($response['user']['name'])) throw new RuntimeException('Memos did not return an authenticated account.');
                $this->notice = 'Connected to Memos as ' . ($response['user']['displayName'] ?? $response['user']['username'] ?? $response['user']['name']) . '. Settings have not been changed.';
                return;
            }
            $shortcut = trim($INPUT->post->str('shortcut'));
            if (!preg_match('/^(?:(?:Ctrl|Alt|Shift|Meta)\+){1,3}[A-Za-z]$/D', $shortcut)) throw new RuntimeException('Use a shortcut such as Alt+Shift+J or Ctrl+Shift+J.');
            $owner = trim($INPUT->post->str('wiki_user'));
            if (strlen($owner) > 100 || preg_match('/[\x00-\x20\x7f]/', $owner)) throw new RuntimeException('Enter one wiki username, or leave it blank for administrator access.');
            $limit = filter_var($INPUT->post->str('attachment_max_mb'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2048]]);
            if ($limit === false) throw new RuntimeException('Attachment size must be between 1 and 2048 MiB.');
            if ($token !== '' && $INPUT->post->bool('clear_api_token')) throw new RuntimeException('Choose either replacing or clearing the token.');
            $updates = ['wiki_user' => $owner, 'shortcut' => $shortcut, 'attachment_max_mb' => $limit];
            if (!getenv('MEMOS_URL')) $updates['url'] = $url;
            if ($token !== '') $updates['api_token'] = $token;
            elseif (!$deployed && $INPUT->post->bool('clear_api_token')) $updates['api_token'] = '';
            $configuration = new Configuration();
            if ($configuration->isLocked()) throw new RuntimeException('Configuration is locked.');
            $settings = $configuration->getSettings();
            foreach ($updates as $key => $value) {
                $setting = $settings['plugin____fokusbutler____memos_' . $key] ?? null;
                if (!$setting || $setting->isProtected()) throw new RuntimeException('A Jots setting is unavailable or protected.');
            }
            $changed = false;
            foreach ($updates as $key => $value) {
                $setting = $settings['plugin____fokusbutler____memos_' . $key];
                $changed = $setting->update($value) || $changed;
                if ($setting->hasError()) throw new RuntimeException('A Jots setting is invalid.');
            }
            if ($changed) $configuration->save();
            msg('Jots settings saved. Reload wiki tabs to apply them.', 1);
            send_redirect(FokusButler_Modules::adminUrl('memos'));
        } catch (Throwable $error) { $this->errors[] = $error instanceof RuntimeException ? $error->getMessage() : 'Jots settings could not be saved.'; }
    }
    public function html()
    {
        if (!auth_isadmin()) return;
        $configuration = new Configuration();
        $settings = $configuration->getSettings();
        $credential = FokusButler_Memos_Connection::credential();
        $deployed = in_array($credential['source'], ['secret', 'environment'], true);
        echo '<h2>Jots · Memos</h2><p>Capture quick notes and browse all notes from Memos v0.31.0. Notes and attachments are mirrored in private local storage.</p>';
        foreach ($this->errors as $error) echo '<p class="error" role="alert">' . hsc($error) . '</p>';
        if ($this->notice !== '') echo '<p class="success" role="status">' . hsc($this->notice) . '</p>';
        echo '<form method="post" action="' . hsc(FokusButler_Modules::adminUrl('memos')) . '">';
        formSecurityToken();
        echo '<table class="inline"><tbody>';
        foreach (['url' => ['Memos instance URL', 'url', FokusButler_Memos_Connection::url(), 'Example: https://jots.akinci.fyi · MEMOS_URL overrides this field.'],
            'api_token' => ['Personal access token', 'password', '', 'Leave blank to keep the saved token. Credential source: ' . $credential['source'] . '.'],
            'wiki_user' => ['Wiki username', 'text', $this->getConf('wiki_user'), 'Only this wiki user can read or write these notes. Blank: wiki administrators only.'],
            'shortcut' => ['Capture shortcut', 'text', $this->getConf('shortcut'), 'Default: Alt+Shift+J. Ctrl, Meta, Alt and Shift modifiers are supported.'],
            'attachment_max_mb' => ['Local attachment limit (MiB)', 'number', $this->getConf('attachment_max_mb'), 'Default: 100 MiB per file. Larger files remain listed with a caching notice.']] as $key => $field) {
            $setting = $settings['plugin____fokusbutler____memos_' . $key] ?? null;
            $disabled = $configuration->isLocked() || !$setting || $setting->isProtected() || ($key === 'api_token' && $deployed) || ($key === 'url' && getenv('MEMOS_URL'));
            echo '<tr><th><label for="memos-' . $key . '">' . hsc($field[0]) . '</label></th><td><input id="memos-' . $key . '" name="' . $key . '" type="' . $field[1] . '" value="' . hsc((string)$field[2]) . '" autocomplete="' . ($key === 'api_token' ? 'new-password' : 'off') . '"' . ($disabled ? ' disabled' : '') . ($key === 'attachment_max_mb' ? ' min="1" max="2048"' : '') . '><p>' . hsc($field[3]) . '</p>';
            if ($key === 'api_token' && !$deployed && $credential['source'] === 'configuration') echo '<label><input type="checkbox" name="clear_api_token" value="1"' . ($disabled ? ' disabled' : '') . '> Clear saved token</label>';
            echo '</td></tr>';
        }
        echo '</tbody></table><p><button class="button" name="memos_save" value="1"' . ($configuration->isLocked() ? ' disabled' : '') . '>Save settings</button> <button class="button" name="memos_test" value="1">Test connection</button></p></form>';
        echo '<p>Deployment credentials: <code>/run/secrets/memos_api_token</code> → <code>MEMOS_API_TOKEN</code> → saved token. The browser never receives this token.</p><p>Open the Jots pane to synchronize or retry pending saves. New notes are private in Memos. Editing a note preserves its visibility and attachments.</p>';
    }
}
