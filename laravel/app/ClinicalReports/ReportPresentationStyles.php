<?php

namespace App\ClinicalReports;

use Illuminate\Validation\ValidationException;

final class ReportPresentationStyles
{
    public const FONT_SIZES = [8, 9, 10, 11, 12, 14, 16, 18];

    public const TEXT_BLOCKS = ['trade_name', 'address', 'phone', 'header', 'title', 'footer'];

    public static function defaults(): array
    {
        return array_fill_keys(self::TEXT_BLOCKS, ['align' => 'left', 'font_size_pt' => 9])
            + ['logo' => ['align' => 'right', 'width_mm' => 18]];
    }

    public static function validate(mixed $styles): void
    {
        $invalid = static fn () => throw ValidationException::withMessages(['styles' => ['Use exactly the supported blocks, alignments, font sizes and logo widths.']]);
        $defaults = self::defaults();
        if (! is_array($styles) || array_diff_key($styles, $defaults) || array_diff_key($defaults, $styles)) {
            $invalid();
        }
        foreach ($defaults as $block => $default) {
            $value = $styles[$block];
            if (! is_array($value) || array_diff_key($value, $default) || array_diff_key($default, $value)
                || ! in_array($value['align'], ['left', 'center', 'right'], true)) {
                $invalid();
            }
            $size = $value[$block === 'logo' ? 'width_mm' : 'font_size_pt'];
            if (! is_int($size) || ($block === 'logo' ? ! in_array($size, [18, 25, 35], true) : ! in_array($size, self::FONT_SIZES, true))) {
                $invalid();
            }
        }
    }
}
