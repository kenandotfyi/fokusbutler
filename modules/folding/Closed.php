<?php
if (!defined('DOKU_INC')) die();

require_once __DIR__ . '/SyntaxBase.php';

/** Block fold that starts collapsed: ;;;; title | ... ;;;; */
class FokusButler_Module_Folding_Closed extends FokusButler_Module_Folding_SyntaxBase
{
    protected $component = 'closed';
    protected $delimiter = ';;;;';
    protected $openByDefault = false;
}
