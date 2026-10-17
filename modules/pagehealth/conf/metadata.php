<?php

/** Page Health appearance options for Configuration Manager. */
$meta['empty_color'] = ['string', '_pattern' => '/^#[0-9a-fA-F]{6}$/'];
$meta['empty_hover_color'] = ['string', '_pattern' => '/^#[0-9a-fA-F]{6}$/'];
$meta['empty_opacity'] = ['string', '_pattern' => '/^(?:0\.[2-9]\d*|1(?:\.0+)?)$/'];
$meta['empty_decoration'] = ['multichoice', '_choices' => ['none', 'underline', 'overline', 'line-through']];
$meta['empty_decoration_style'] = ['multichoice', '_choices' => ['solid', 'dotted', 'dashed', 'wavy']];
$meta['empty_italic'] = ['onoff'];
