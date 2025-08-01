<?php

/**
 * Формирует текстовое описание браслета на основе размеров и схемы.
 *
 * @param float  $wristCm  Обхват запястья в сантиметрах.
 * @param int    $wraps    Количество витков браслета вокруг запястья.
 * @param float[] $pattern Массив диаметров бусин в миллиметрах.
 * @param float  $magnetMm Размер магнитного замка в миллиметрах.
 * @param float  $tolMm    Допустимое отклонение длины в миллиметрах.
 * @param string $lang     Язык результата ('ru' или 'en').
 *
 * @return string Готовое текстовое описание браслета.
 *
 * @throws InvalidArgumentException Если массив с паттерном пуст.
 */
function braceletText(
    float $wristCm,
    int   $wraps,
    array $pattern,
    float $magnetMm,
    float $tolMm = 5,
    string $lang = 'ru'
): string {

    // Переводим обхват запястья из сантиметров в миллиметры
    $wristMm = $wristCm * 10;

    // Расчёт общей требуемой длины браслета с допуском
    $Lt = $wraps * $wristMm + $tolMm;

    // Проверяем, что паттерн не пуст, иначе нет данных для расчёта
    if (count($pattern) === 0) {
        throw new InvalidArgumentException('Паттерн должен содержать хотя бы один размер бусины');
    }

    // Средний диаметр бусин в паттерне
    $avg = array_sum($pattern) / count($pattern);

    // Приблизительное количество бусин, необходимое для заданной длины
    $rough = round(($Lt - $magnetMm) / $avg);

    // Количество полных повторов паттерна
    $blocks = intdiv($rough, count($pattern));

    // Остаток бусин, не образующий полный паттерн
    $rest = $rough % count($pattern);

    // Формируем итоговый массив бусин
    $beads = array_merge(
        ...array_fill(0, $blocks, $pattern),
        ...array_slice($pattern, 0, $rest)
    );

    // Удаляем последнюю бусину, если её диаметр почти совпадает с размером магнита
    for ($i = count($beads) - 1; $i >= 0; $i--) {
        if (abs($beads[$i] - $magnetMm) < 0.5) {
            array_splice($beads, $i, 1);
            break;
        }
    }

    // Локальная функция для подсчёта общей длины браслета
    $len = fn($b) => array_sum($b) + $magnetMm;

    // Убираем лишние бусины, если браслет слишком длинный
    while ($len($beads) > $Lt + 2) array_pop($beads);

    // Добавляем бусины, если браслет слишком короткий
    while ($len($beads) < $Lt - 2) $beads[] = $pattern[count($beads) % count($pattern)];

    // Подсчитываем количество бусин каждого диаметра
    $sizes = array_count_values($beads);

    // Сортируем размеры в порядке убывания для наглядности
    krsort($sizes);

    $parts = [];
    foreach ($sizes as $d => $n) {
        $word = $lang === 'en' ? 'beads' : 'бусин';
        // Формируем фрагмент описания количества и диаметра
        $parts[] = "$n $word Ø{$d} мм";
    }

    // Объединяем фрагменты описания в строку
    $parts = join($lang === 'en' ? ' and ' : ' и ', $parts);

    if ($lang === 'en') {
        // Текст результата на английском языке
        return "Wrist {$wristCm} cm → {$parts} + {$tolMm} mm slack + {$magnetMm} mm clasp";
    }
    // Текст результата на русском языке
    return "Обхват {$wristCm} см → {$parts} + {$tolMm} мм допуск + {$magnetMm} мм крепление";
}
