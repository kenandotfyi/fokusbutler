<?php
$butlerModules = require dirname(__DIR__, 2) . '/modules.php';
foreach ($butlerModules as $butlerId => $butlerModule) {
    if ($butlerModule['toggle']) $lang['enabled_' . $butlerId] = 'Enable ' . $butlerModule['name'];
    $butlerLanguage = (static function ($file) {
        $lang = [];
        if (is_file($file)) include $file;
        return $lang;
    })(dirname(__DIR__, 2) . '/modules/' . $butlerId . '/lang/en/settings.php');
    foreach ($butlerLanguage as $butlerKey => $butlerValue) $lang[$butlerId . '_' . $butlerKey] = $butlerValue;
}
unset($butlerModules, $butlerId, $butlerModule, $butlerLanguage, $butlerKey, $butlerValue);
