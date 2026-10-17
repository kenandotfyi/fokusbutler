<?php
if (!defined('DOKU_INC')) die();

/** Shared block behavior for Fokus Fold's open and closed syntaxes. */
abstract class FokusButler_Module_Folding_SyntaxBase extends DokuWiki_Syntax_Plugin
{
    use FokusButler_ModuleContext;
    protected const MODULE = 'folding';
    protected $component = '';
    protected $delimiter = '';
    protected $openByDefault = false;

    public function getType() { return 'container'; }
    public function getPType() { return 'stack'; }
    public function getSort() { return 80; }

    public function getAllowedTypes()
    {
        return ['container', 'substition', 'protected', 'disabled', 'paragraphs', 'formatting'];
    }

    public function accepts($mode)
    {
        // A fold's closing delimiter is identical to its opener. Do not let
        // another Fokus Fold mode start at that delimiter before it can close
        // the current block; sequential folds will still parse after the exit.
        if (in_array($mode, ['plugin_fokusbutler_foldclosed', 'plugin_fokusbutler_foldopen'], true)) return false;
        return parent::accepts($mode);
    }

    public function connectTo($mode)
    {
        $marker = preg_quote($this->delimiter, '/');
        $this->Lexer->addEntryPattern(
            $marker . '[ \\t]*.*?\\|(?=.*' . $marker . ')',
            $mode,
            'plugin_fokusbutler_fold' . $this->component
        );
    }

    public function postConnect()
    {
        $this->Lexer->addExitPattern(
            preg_quote($this->delimiter, '/'),
            'plugin_fokusbutler_fold' . $this->component
        );
    }

    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        if ($state === DOKU_LEXER_ENTER) {
            $title = trim(substr($match, strlen($this->delimiter)));
            if (substr($title, -1) === '|') $title = substr($title, 0, -1);
            return [$state, trim($title)];
        }

        if ($state === DOKU_LEXER_UNMATCHED) {
            $handler->addCall('cdata', [$match], $pos);
            return false;
        }

        return [$state, ''];
    }

    public function render($mode, Doku_Renderer $renderer, $data)
    {
        if (empty($data)) return false;
        [$state, $title] = $data;

        if ($mode !== 'xhtml') {
            if ($state === DOKU_LEXER_ENTER && $title !== '') $renderer->cdata($title);
            return true;
        }

        if ($state === DOKU_LEXER_ENTER) {
            $enabled = FokusButler_Modules::enabled('folding');
            $open = $this->openByDefault || !$enabled;
            $expanded = $open ? 'true' : 'false';
            $hidden = $open ? '' : ' hidden';
            $class = $open ? ' is-open' : '';
            [$titleTag, $titleMarkup] = self::renderTitle($title);
            $headingClass = $titleTag === 'span' ? '' : ' fokus-fold--heading-title';
            $headingOpen = $titleTag === 'span' ? '' : '<' . $titleTag . ' class="fokus-fold__title-heading">';
            $headingClose = $titleTag === 'span' ? '' : '</' . $titleTag . '>';
            $indicator = '<span class="fokus-fold__indicator" aria-hidden="true">';
            $indicator .= self::icon('caret-right-duotone.svg', 'fokus-fold__caret fokus-fold__caret-right');
            $indicator .= self::icon('caret-down-duotone.svg', 'fokus-fold__caret fokus-fold__caret-down');
            $indicator .= '</span>';
            if (!$enabled) $indicator = '';

            $renderer->doc .= '<section class="fokus-fold fokus-fold-' . hsc($this->component) . $headingClass . $class . '" data-fokus-fold data-default-open="' . ($open ? 'true' : 'false') . '">';
            $renderer->doc .= '<div class="fokus-fold__header">';
            $renderer->doc .= $headingOpen;
            if ($enabled) $renderer->doc .= '<button class="fokus-fold__toggle" type="button" data-fokus-fold-toggle aria-expanded="' . $expanded . '">';
            $renderer->doc .= '<span class="fokus-fold__title">' . $titleMarkup . '</span>';
            if ($enabled) $renderer->doc .= '</button>';
            if ($titleTag !== 'span') $renderer->doc .= $indicator;
            $renderer->doc .= $headingClose;
            if ($titleTag === 'span') $renderer->doc .= $indicator;
            $renderer->doc .= '</div>';
            $renderer->doc .= '<div class="fokus-fold__content"' . $hidden . '>';
            return true;
        }

        if ($state === DOKU_LEXER_EXIT) {
            $renderer->doc .= '</div></section>';
            return true;
        }

        return false;
    }

    private static function icon($filename, $class)
    {
        static $icons = [];
        if (!array_key_exists($filename, $icons)) {
            $path = __DIR__ . '/icons/' . $filename;
            $icons[$filename] = is_file($path) ? file_get_contents($path) : '';
        }

        if ($icons[$filename] === '') return '';
        return preg_replace('/<svg\\b/', '<svg class="' . hsc($class) . '" focusable="false"', $icons[$filename], 1);
    }

    /** Render DokuWiki inline syntax in the title, including detected heading syntax. */
    private static function renderTitle($title)
    {
        $tag = 'span';
        $title = trim($title);

        // DokuWiki headings use two to six equals signs; render their level
        // without a page anchor or section side effects inside the fold title.
        if (preg_match('/^(={2,6})(?![=])\\s*(.*?)\\s*(?<![=])\\1$/s', $title, $heading)) {
            $tag = 'h' . max(1, min(5, 7 - strlen($heading[1])));
            $title = trim($heading[2]);
        }

        $renderInfo = [];
        $html = p_render('xhtml', p_get_instructions($title), $renderInfo);
        $html = trim($html);

        // Some DokuWiki setups also recognize Markdown ATX headings (# title).
        // The normal parser emits a heading plus an empty section wrapper; lift
        // the rendered heading into the fold header so the caret inherits its
        // typography, and discard the parser-generated page anchor.
        if ($tag === 'span' && preg_match('/^<h([1-6])\\b[^>]*>(.*?)<\\/h\\1>\\s*(?:<div class="level[0-9]+">\\s*<\\/div>\\s*)*$/s', $html, $renderedHeading)) {
            $tag = 'h' . $renderedHeading[1];
            $html = $renderedHeading[2];
        }

        if (preg_match('/^<p(?:\\s[^>]*)?>(.*)<\\/p>$/s', $html, $paragraph)) {
            $html = $paragraph[1];
        }

        return [$tag, $html];
    }
}
