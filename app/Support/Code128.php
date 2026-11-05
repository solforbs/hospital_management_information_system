<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Minimal Code 128 (subset B) barcode as inline SVG, for specimen labels.
 * Subset B covers printable ASCII (space to ~), which includes specimen
 * numbers such as "SPM-000123". Readable by standard lab barcode scanners.
 */
class Code128
{
    /** Bar/space module widths for symbol values 0–106 (106 = stop). */
    public const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    public const START_B = 104;

    public const STOP = 106;

    /** Symbol values for the text, including start, checksum and stop. */
    public static function values(string $text): array
    {
        if ($text === '' || preg_match('/[^\x20-\x7E]/', $text)) {
            throw new InvalidArgumentException('Code 128-B supports printable ASCII only.');
        }

        $values = [self::START_B];
        $checksum = self::START_B;

        foreach (str_split($text) as $i => $char) {
            $value = ord($char) - 32;
            $values[] = $value;
            $checksum += $value * ($i + 1);
        }

        $values[] = $checksum % 103;
        $values[] = self::STOP;

        return $values;
    }

    /** Bars as [x, width] in modules, plus the total width in modules (with quiet zones). */
    public static function bars(string $text, int $quiet = 10): array
    {
        $x = $quiet;
        $bars = [];

        foreach (self::values($text) as $value) {
            foreach (str_split(self::PATTERNS[$value]) as $i => $w) {
                if ($i % 2 === 0) {
                    $bars[] = [$x, (int) $w];
                }
                $x += (int) $w;
            }
        }

        return ['bars' => $bars, 'width' => $x + $quiet];
    }

    public static function svg(string $text, int $height = 40): string
    {
        ['bars' => $bars, 'width' => $width] = self::bars($text);

        $rects = implode('', array_map(fn ($b) => '<rect x="'.$b[0].'" y="0" width="'.$b[1].'" height="'.$height.'"/>', $bars));

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' '.$height.'" preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="'.e($text).'"><g fill="#000">'.$rects.'</g></svg>';
    }
}
