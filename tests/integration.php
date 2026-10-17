<?php
/** Run against an isolated DokuWiki installation, never the production wiki. */
$root = getenv('DOKUWIKI_TEST_ROOT');
if (!$root || !is_file($root . '/inc/init.php')) {
    fwrite(STDERR, "Set DOKUWIKI_TEST_ROOT to an isolated DokuWiki installation.\n");
    exit(2);
}
define('DOKU_INC', rtrim($root, '/') . '/');
define('NOSESSION', 1);
require DOKU_INC . 'inc/init.php';

$checks = 0;
function verify($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function renderSyntax($text)
{
    $info = [];
    return p_render('xhtml', p_get_instructions($text), $info);
}
function domFor($html)
{
    $dom = new DOMDocument();
    $dom->loadHTML('<html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($dom);
}

try {
    $ID = 'start';
    $ACT = 'show';
    $auth = plugin_load('auth', 'authplain');
    $INPUT->server->set('REMOTE_USER', 'admin');
    $USERINFO = ['grps' => ['admin', 'user'], 'name' => 'Test admin', 'mail' => 'admin@example.test'];
    $INFO = pageinfo();
    verify(auth_isadmin(), 'Test admin authentication failed');
    $butler = plugin_load('helper', 'fokusbutler');
    verify($butler && count($butler->modules()) === 7, 'Module manifest unavailable');
    foreach (['assets', 'pagehealth', 'imagepop', 'notegraph', 'snips', 'todoist'] as $module) {
        verify((bool)plugin_load('action', 'fokusbutler_' . $module), 'Missing action: ' . $module);
    }
    foreach (['foldclosed', 'foldopen', 'pagehealth', 'todoist'] as $component) {
        verify((bool)plugin_load('syntax', 'fokusbutler_' . $component), 'Missing syntax: ' . $component);
    }
    $adminEntries = array_values(array_filter(plugin_list('admin'), static function ($id) { return strpos($id, 'fokusbutler') === 0; }));
    verify($adminEntries === ['fokusbutler'], 'Module internals appeared as separate admin plugins');
    $settings = (new dokuwiki\plugin\config\core\Configuration())->getSettings();
    foreach (['enabled_folding', 'enabled_todoist', 'pagehealth_empty_color', 'imagepop_image_selector', 'todoist_api_token', 'todoist_project_name'] as $key) {
        verify(isset($settings['plugin____fokusbutler____' . $key]), 'Configuration key missing: ' . $key);
    }
    verify(FokusButler_Modules::defaults()['todoist_project_name'] === 'Wiki', 'Module default lookup failed');
    $opacity = $settings['plugin____fokusbutler____pagehealth_empty_opacity'];
    $opacity->update('0.7');
    verify(!$opacity->hasError(), 'Fractional Page Health opacity was rejected');
    $opacity->update('0.1');
    verify($opacity->hasError(), 'Out-of-range Page Health opacity was accepted');
    verify(plugin_load('helper', 'fokusbutler_snips')->getLang('toolbar_title') === 'Snips', 'Module language lookup failed');
    $parsedDefaults = (new dokuwiki\plugin\config\core\ConfigParser())->parse(FOKUSBUTLER_ROOT . '/conf/default.php');
    verify(($parsedDefaults['enabled_folding'] ?? null) == 1, 'Configuration Manager could not parse module defaults');
    verify(($parsedDefaults['todoist_project_name'] ?? null) === 'Wiki', 'Configuration Manager lost a module string default');
    $conf['plugin']['fokusbutler']['imagepop_image_selector'] = '.custom-test-image';
    verify(plugin_load('action', 'fokusbutler_imagepop')->getConf('image_selector') === '.custom-test-image', 'Module configuration override failed');

    $folds = ";;;; #### First |\nFirst body.\n;;;;\n\n;;;; Second |\nSecond body.\n;;;;\n\n++++ ## Third |\nThird body.\n++++\n\nOutside paragraph.";
    $xpath = domFor(renderSyntax($folds));
    verify($xpath->query('//section[@data-fokus-fold]')->length === 3, 'Sequential folds were not independent');
    verify($xpath->query('//section[@data-fokus-fold]//section[@data-fokus-fold]')->length === 0, 'Sequential folds became nested');
    verify($xpath->query('//section[@data-fokus-fold][1]/div[contains(@class,"content")][@hidden]')->length === 1, 'Closed fold was not hidden');
    verify($xpath->query('//section[@data-fokus-fold][3]/div[contains(@class,"content")][not(@hidden)]')->length === 1, 'Open fold was hidden');
    verify($xpath->query('//h4[contains(@class,"title-heading")]/span[contains(@class,"indicator")]')->length === 1, 'Markdown heading caret did not inherit its heading wrapper');
    verify($xpath->query('//button//h1|//button//h2|//button//h3|//button//h4|//button//div')->length === 0, 'Block-level title output leaked into a button');
    verify(strpos(renderSyntax('~~PAGEHEALTH~~'), 'data-pagehealth-summary') !== false, 'Health summary syntax failed');
    verify(strpos(renderSyntax('~~LAST30~~'), '~~LAST30~~') === false, 'Recent changes syntax was not handled');
    verify(strpos(renderSyntax('{{todoistcompanion>Test task|id=abcd1234}}'), 'data-todoistcompanion-task="abcd1234"') !== false, 'Task syntax changed');
    verify(strpos(renderSyntax('~~TODOS~~'), 'todoistcompanion-todos') !== false, 'Task-list syntax failed');

    $assetAction = plugin_load('action', 'fokusbutler_assets');
    $data = ['link' => [], 'script' => []];
    $event = new Doku_Event('TPL_METAHEADER_OUTPUT', $data);
    $assetAction->addAssets($event, null);
    $assetAction->addAssets($event, null);
    $sources = array_column($event->data['script'], 'src');
    verify(count($sources) === 7 && count(array_unique($sources)) === 7, 'Module scripts were missing or loaded twice');
    verify(count(array_filter($sources, static function ($src) { return strpos($src, '?') !== false; })) === 0, 'Asset version parameters were added');
    verify(count($event->data['link']) === 6, 'Separate module stylesheets were not loaded');

    $toolbarData = [];
    $toolbar = new Doku_Event('TOOLBAR_DEFINE', $toolbarData);
    plugin_load('action', 'fokusbutler_snips')->addToolbarPicker($toolbar, null);
    plugin_load('action', 'fokusbutler_todoist')->addToolbarButton($toolbar, null);
    verify(array_column($toolbar->data, 'type') === ['fokusbutlersnips', 'fokusbutlertodoist'], 'Toolbar component names did not match the package');
    foreach ($toolbar->data as $definition) {
        $function = 'addBtnAction' . ucfirst($definition['type']);
        $module = $definition['type'] === 'fokusbutlersnips' ? 'snips' : 'todoist';
        verify(strpos(file_get_contents(FOKUSBUTLER_ROOT . '/modules/' . $module . '/script.js'), 'window.' . $function . ' = ') !== false, 'Toolbar handler not exported: ' . $function);
    }

    foreach (['settings', 'pagehealth', 'imagepop', 'snips', 'todoist'] as $module) {
        $panel = FokusButler_Modules::admin($module);
        verify((bool)$panel, 'Admin panel missing: ' . $module);
        ob_start();
        $panel->html();
        $html = ob_get_clean();
        verify(strlen($html) > 100, 'Empty module panel: ' . $module);
        verify(strpos($html, 'name="module" value="' . $module . '"') !== false || strpos($html, 'module=' . $module) !== false, 'Admin form route missing: ' . $module);
    }

    foreach (['folding', 'todoist', 'pagehealth', 'snips', 'imagepop', 'notegraph'] as $module) $conf['plugin']['fokusbutler']['enabled_' . $module] = 0;
    $disabledFolds = domFor(renderSyntax($folds));
    verify($disabledFolds->query('//div[contains(@class,"fokus-fold__content")][@hidden]')->length === 0, 'Disabled folding hid existing content');
    verify($disabledFolds->query('//button[@data-fokus-fold-toggle]')->length === 0, 'Disabled folding still rendered interactive toggles');
    verify(strpos(renderSyntax('~~PAGEHEALTH~~'), 'Page Health is disabled.') !== false, 'Disabled health summary retained a loading state');
    $task = renderSyntax('{{todoistcompanion>Test task|id=abcd1234}}');
    verify(strpos($task, 'Todoist integration disabled.') !== false && strpos($task, 'Loading task status') === false, 'Disabled tasks were not readable');
    $data = ['link' => [], 'script' => []];
    $disabledAssets = new Doku_Event('TPL_METAHEADER_OUTPUT', $data);
    $assetAction->addAssets($disabledAssets, null);
    verify(count(array_filter(array_column($disabledAssets->data['script'], 'src'), static function ($src) { return strpos($src, '/modules/') !== false; })) === 0, 'Disabled modules still loaded scripts');
    verify(count($disabledAssets->data['link']) === 3, 'Disabled syntax modules lost their presentation styles');

    echo "PASS: $checks integration checks against real DokuWiki.\n";
} catch (Throwable $error) {
    if (ob_get_level()) ob_end_clean();
    fwrite(STDERR, $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    exit(1);
}
