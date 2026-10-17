<?php
if (!defined('DOKU_INC')) die();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once FOKUSBUTLER_ROOT . '/modules/todoist/Action.php';
class action_plugin_fokusbutler_todoist extends FokusButler_Module_Todoist_Action {}
