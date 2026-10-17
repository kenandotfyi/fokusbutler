<?php
if (!defined('DOKU_INC')) die();
require_once dirname(__DIR__) . '/bootstrap.php';
require_once FOKUSBUTLER_ROOT . '/modules/todoist/Syntax.php';
class syntax_plugin_fokusbutler_todoist extends FokusButler_Module_Todoist_Syntax {}
