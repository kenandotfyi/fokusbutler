<?php
/** Use an isolated DokuWiki fixture, never a production wiki. */
$root = getenv('DOKUWIKI_TEST_ROOT');
if (!$root || !is_file($root . '/inc/init.php')) exit(2);
define('DOKU_INC', rtrim($root, '/') . '/');
define('NOSESSION', 1);
require DOKU_INC . 'inc/init.php';
$ACT = 'show'; $ID = 'start';
function checkEquation($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$action = plugin_load('action', 'fokusbutler_equations');
checkEquation((bool)$action, 'Equation action not discovered');
checkEquation(FokusButler_Modules::enabled('equations'), 'Equation module not enabled by default');
$data = []; $toolbar = new Doku_Event('TOOLBAR_DEFINE', $data);
$action->addToolbarButton($toolbar, null); $action->addToolbarButton($toolbar, null);
checkEquation(array_column($toolbar->data, 'type') === ['fokusbutlerequations'], 'Toolbar duplicated');
$data = ['link' => [], 'script' => []]; $assets = new Doku_Event('TPL_METAHEADER_OUTPUT', $data);
$loader = plugin_load('action', 'fokusbutler_assets'); $loader->addAssets($assets, null); $loader->addAssets($assets, null);
$scripts = array_filter(array_column($assets->data['script'], 'src'), static fn($src) => str_contains($src, '/equations/'));
checkEquation(count($scripts) === 1, 'Equation script missing or duplicated');
$conf['plugin']['fokusbutler']['enabled_equations'] = 0;
$data = ['link' => [], 'script' => []]; $disabled = new Doku_Event('TPL_METAHEADER_OUTPUT', $data);
$loader->addAssets($disabled, null);
checkEquation(!array_filter(array_column($disabled->data['script'], 'src'), static fn($src) => str_contains($src, '/equations/')), 'Disabled module still loads');
echo "PASS: equation discovery, default switch, toolbar deduplication, assets, and disabling.\n";
