<?php

final class FokusButler_Preview_Settings
{
    public static function fields()
    {
        return [
            'width' => ['label'=>'Window width', 'default'=>560, 'min'=>320, 'max'=>1600, 'unit'=>'px', 'help'=>'Preferred width. The window shrinks to fit the screen.'],
            'height' => ['label'=>'Maximum window height', 'default'=>480, 'min'=>200, 'max'=>1200, 'unit'=>'px', 'help'=>'Short pages fit their content. Longer pages scroll inside the window, up to this height or the available screen height.'],
            'open_delay' => ['label'=>'Opening delay', 'default'=>750, 'min'=>0, 'max'=>5000, 'unit'=>'ms', 'help'=>'Time over a link before its preview appears. Zero opens immediately.'],
            'close_delay' => ['label'=>'Closing grace period', 'default'=>300, 'min'=>0, 'max'=>3000, 'unit'=>'ms', 'help'=>'Time to move from the link into the preview before it closes. Pinned windows stay open.'],
        ];
    }

    public static function validate($key, $value)
    {
        $field = self::fields()[$key];
        if (!preg_match('/^\d+$/D', (string)$value) || (float)$value < $field['min'] || (float)$value > $field['max']) {
            throw new InvalidArgumentException($field['label'] . ' must be a whole number between ' . $field['min'] . ' and ' . $field['max'] . ' ' . $field['unit'] . '.');
        }
        return (int)$value;
    }

    public static function values()
    {
        $values = [];
        foreach (self::fields() as $key => $field) {
            try { $values[$key] = self::validate($key, FokusButler_Modules::config('preview', $key, $field['default'])); }
            catch (InvalidArgumentException $error) { $values[$key] = $field['default']; }
        }
        return $values;
    }
}
