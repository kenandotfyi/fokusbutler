<?php
/** Generate literal defaults because DokuWiki's Configuration Manager parses assignments. */
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
$modules = require $root . '/modules.php';
$output = "<?php\n/** Generated from module-local defaults by bin/build-config.php. */\n";
foreach ($modules as $id => $module) {
    if ($module['toggle']) $output .= '$conf[' . var_export('enabled_' . $id, true) . "] = 1;\n";
    $defaults = (static function ($file) {
        $conf = [];
        if (is_file($file)) include $file;
        return $conf;
    })($root . '/modules/' . $id . '/conf/default.php');
    foreach ($defaults as $key => $value) $output .= '$conf[' . var_export($id . '_' . $key, true) . '] = ' . var_export($value, true) . ";\n";
}
if (file_put_contents($root . '/conf/default.php', $output) === false) {
    fwrite(STDERR, "Could not write generated defaults.\n");
    exit(1);
}
echo "Generated DokuWiki-compatible defaults.\n";
