<?php
require_once dirname(__DIR__) . '/Settings.php';
foreach (FokusButler_Preview_Settings::fields() as $key => $field) $conf[$key] = $field['default'];
