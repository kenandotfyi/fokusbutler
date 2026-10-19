<?php
require_once dirname(__DIR__) . '/Settings.php';
foreach (FokusButler_Preview_Settings::fields() as $key => $field) $meta[$key] = ['numeric', '_min'=>$field['min'], '_max'=>$field['max']];
