<?php
require_once dirname(__DIR__, 2) . '/Settings.php';
foreach (FokusButler_Preview_Settings::fields() as $key => $field) {
    $lang[$key] = $field['label'] . ' (' . $field['unit'] . ')';
    $lang['_' . $key] = $field['help'];
}
