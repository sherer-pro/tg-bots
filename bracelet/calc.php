<?php
function braceletText(
    float $wristCm,
    int   $wraps,
    array $pattern,
    float $magnetMm,
    float $tolMm = 5,
    string $lang = 'ru'
): string {

    $wristMm = $wristCm * 10;
    $Lt      = $wraps * $wristMm + $tolMm;
    $avg     = array_sum($pattern) / count($pattern);
    $rough   = round(($Lt - $magnetMm) / $avg);

    $blocks  = intdiv($rough, count($pattern));
    $rest    = $rough %  count($pattern);
    $beads   = array_merge(
        ...array_fill(0, $blocks, $pattern),
        array_slice($pattern, 0, $rest)
    );

    for ($i = count($beads) - 1; $i >= 0; $i--) {
        if (abs($beads[$i] - $magnetMm) < 0.5) {
            array_splice($beads, $i, 1);
            break;
        }
    }

    $len = fn($b) => array_sum($b) + $magnetMm;
    while ($len($beads) > $Lt + 2) array_pop($beads);
    while ($len($beads) < $Lt - 2) $beads[] = $pattern[count($beads) % count($pattern)];

    $sizes = array_count_values($beads);
    krsort($sizes);

    $parts = [];
    foreach ($sizes as $d => $n) {
        $word = $lang === 'en' ? 'beads' : 'бусин';
        $parts[] = "$n $word Ø{$d} мм";
    }
    $parts = join($lang === 'en' ? ' and ' : ' и ', $parts);

    if ($lang === 'en') {
        return "Wrist {$wristCm} cm → {$parts} + {$tolMm} mm slack + {$magnetMm} mm clasp";
    }
    return "Обхват {$wristCm} см → {$parts} + {$tolMm} мм допуск + {$magnetMm} мм крепление";
}
