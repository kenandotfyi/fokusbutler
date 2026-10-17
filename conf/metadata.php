<?php
$butlerModules = require dirname(__DIR__) . '/modules.php';
foreach ($butlerModules as $butlerId => $butlerModule) {
    if ($butlerModule['toggle']) $meta['enabled_' . $butlerId] = ['onoff'];
    $butlerMetadata = (static function ($file) {
        $meta = [];
        if (is_file($file)) include $file;
        return $meta;
    })(dirname(__DIR__) . '/modules/' . $butlerId . '/conf/metadata.php');
    foreach ($butlerMetadata as $butlerKey => $butlerValue) $meta[$butlerId . '_' . $butlerKey] = $butlerValue;
}
unset($butlerModules, $butlerId, $butlerModule, $butlerMetadata, $butlerKey, $butlerValue);
