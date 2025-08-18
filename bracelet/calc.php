<?php

/**
 * Рассчитывает оптимальную последовательность бусин и формирует
 * человекочитаемое описание браслета. Используется в Telegram‑боте
 * для подбора набора бусин по параметрам пользователя.
 *
 * @param float   $wristCm  Обхват запястья в сантиметрах.
 * @param int     $wraps    Количество витков браслета вокруг запястья.
 * @param float[] $pattern  Массив диаметров бусин в миллиметрах, задающий
 *                          повторяющийся узор.
 * @param float   $magnetMm Размер магнитного замка в миллиметрах.
 * @param float   $tolMm    Допустимое отклонение длины в миллиметрах.
 * @param string  $lang     Язык результата ('ru' или 'en').
 *
 * @return string Описание с количеством бусин каждого размера и допуском.
 *
 * @throws InvalidArgumentException Если число витков не больше нуля,
 *                                  массив с паттерном пуст, общая длина
 *                                  браслета не превышает размер магнита
 *                                  или невозможно подобрать набор бусин.
 */
function braceletText(
    float $wristCm,
    int   $wraps,
    array $pattern,
    float $magnetMm,
    float $tolMm = 5,
    string $lang = 'ru'
): string {

    /**
     * Проверяем, что число витков положительно.
     *
     * @param int $wraps Количество витков браслета.
     * @throws InvalidArgumentException если значение не больше нуля.
     */
    if ($wraps <= 0) {
        throw new InvalidArgumentException('Количество витков должно быть больше нуля');
    }

    // Переводим обхват запястья из сантиметров в миллиметры
    $wristMm = $wristCm * 10;

    // Расчёт общей требуемой длины браслета с допуском
    $Lt = $wraps * $wristMm + $tolMm;

    // Проверяем, что рассчитанная общая длина больше размера магнита.
    // Иначе набор бусин разместить физически невозможно.
    if ($Lt <= $magnetMm) {
        throw new InvalidArgumentException(
            'Общая длина браслета должна превышать размер магнита'
        );
    }

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

    // Функция нормализации диаметра бусины: округляем до сотых
    // и приводим к строке, чтобы использовать как ключ массива
    $normalize = static fn(float $d): string => (string) round($d, 2);

    // Ассоциативный массив счётчиков "нормализованный диаметр => количество"
    $counts = [];

    // Общая длина всех учтённых бусин без магнита
    $beadsLen = 0.0;

    // Число элементов в паттерне для удобства
    $patternLen = count($pattern);

    // Добавляем полные блоки паттерна
    foreach ($pattern as $diameter) {
        $key = $normalize($diameter);
        $counts[$key] = ($counts[$key] ?? 0) + $blocks;
        $beadsLen += $diameter * $blocks;
    }

    // Позиция в паттерне, с которой начнётся добавление новых бусин
    $seqIndex = 0;

    // Добавляем остаток паттерна
    for ($i = 0; $i < $rest; $i++) {
        $diameter = $pattern[$i];
        $key = $normalize($diameter);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        $beadsLen += $diameter;
        $seqIndex++;
    }

    // Проверяем, что после расчётов получился непустой набор бусин.
    if (array_sum($counts) === 0) {
        throw new InvalidArgumentException(
            'Невозможно подобрать набор бусин с указанными параметрами'
        );
    }

    // Удаляем последнюю бусину, если её диаметр почти совпадает с размером магнита
    $lastIndex = ($seqIndex - 1 + $patternLen) % $patternLen;
    $lastBead = $pattern[$lastIndex];
    if (abs($lastBead - $magnetMm) < 0.5) {
        $key = $normalize($lastBead);
        if (--$counts[$key] === 0) {
            unset($counts[$key]);
        }
        $beadsLen -= $lastBead;
        $seqIndex = $lastIndex;
    }

    // Текущая длина собранного браслета
    $currentLen = $beadsLen + $magnetMm;

    // Разница между требуемой длиной и текущей
    $delta = $Lt - $currentLen;

    // Максимальное количество попыток корректировки, чтобы избежать бесконечного цикла
    $maxIterations = 10;

    // Повторяем корректировку длины браслета, пока отклонение не окажется в пределах допуска
    for ($i = 0; $i < $maxIterations && abs($delta) > 2; $i++) {
        if ($delta < -2) {
            // Браслет длиннее допустимого. Чтобы понять,
            // сколько элементов убрать, делим излишек длины
            // на средний диаметр бусины. Округляем вверх,
            // чтобы гарантированно удалить достаточно бусин.
            $remove = (int) ceil(($currentLen - ($Lt + 2)) / $avg);
            for ($j = 0; $j < $remove; $j++) {
                $seqIndex = ($seqIndex - 1 + $patternLen) % $patternLen;
                $diameter = $pattern[$seqIndex];
                $key = $normalize($diameter);
                if (--$counts[$key] === 0) {
                    unset($counts[$key]);
                }
                $beadsLen -= $diameter;
            }
        } elseif ($delta > 2) {
            // Браслет короче допустимого. Недостающую длину
            // делим на средний диаметр бусины и округляем вверх,
            // получая количество элементов для добавления.
            $add = (int) ceil(($Lt - 2 - $currentLen) / $avg);
            for ($j = 0; $j < $add; $j++) {
                $diameter = $pattern[$seqIndex];
                $key = $normalize($diameter);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $beadsLen += $diameter;
                $seqIndex = ($seqIndex + 1) % $patternLen;
            }
        }

        // Повторный расчёт текущей длины и отклонения после корректировки
        $currentLen = $beadsLen + $magnetMm;
        $delta = $Lt - $currentLen;
    }

    // Сортируем размеры в порядке убывания для наглядности
    uksort($counts, static fn(string $a, string $b): int => (float) $b <=> (float) $a);

    $parts = [];
    foreach ($counts as $d => $n) {
        $word = $lang === 'en' ? 'beads' : 'бусин';
        // Формируем фрагмент описания количества и диаметра
        // Используем фигурные скобки вокруг переменных, чтобы избежать
        // проблем с символами пробелов в строке
        $parts[] = "{$n} {$word} Ø{$d} мм";
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
