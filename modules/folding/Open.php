<?php
if (!defined('DOKU_INC')) die();

require_once __DIR__ . '/SyntaxBase.php';

/** Block fold that starts expanded: ++++ title | ... ++++ */
class FokusButler_Module_Folding_Open extends FokusButler_Module_Folding_SyntaxBase
{
    protected $component = 'open';
    protected $delimiter = '++++';
    protected $openByDefault = true;
}
