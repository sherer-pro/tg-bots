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
 *                                  массив с паттерном пуст или невозможно
 *                                  подобрать набор бусин.
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
    // Сначала собираем массив повторов паттерна, затем добавляем остаток
    // и разворачиваем получившийся массив при слиянии
    $beadChunks = array_fill(0, $blocks, $pattern);
    $beadChunks[] = array_slice($pattern, 0, $rest);
    $beads = array_merge(...$beadChunks);

    // Проверяем, что после расчётов получился непустой набор бусин.
    // Если в массиве нет ни одного элемента, значит параметры подобраны
    // некорректно и браслет построить невозможно.
    if (count($beads) === 0) {
        throw new InvalidArgumentException(
            'Невозможно подобрать набор бусин с указанными параметрами'
        );
    }

    // Удаляем последнюю бусину, если её диаметр почти совпадает с размером магнита
    for ($i = count($beads) - 1; $i >= 0; $i--) {
        if (abs($beads[$i] - $magnetMm) < 0.5) {
            array_splice($beads, $i, 1);
            break;
        }
    }

    // Локальная функция для подсчёта общей длины браслета
    $len = fn($b) => array_sum($b) + $magnetMm;

    // Текущая длина собранного браслета
    $currentLen = $len($beads);

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
            if ($remove > 0) {
                $beads = array_slice($beads, 0, count($beads) - $remove);
            }
        } elseif ($delta > 2) {
            // Браслет короче допустимого. Недостающую длину
            // делим на средний диаметр бусины и округляем вверх,
            // получая количество элементов для добавления.
            $add = (int) ceil(($Lt - 2 - $currentLen) / $avg);
            if ($add > 0) {
                $addChunks = array_fill(0, intdiv($add, count($pattern)), $pattern);
                $addChunks[] = array_slice($pattern, 0, $add % count($pattern));
                $beads = array_merge($beads, ...$addChunks);
            }
        }

        // Повторный расчёт текущей длины и отклонения после корректировки
        $currentLen = $len($beads);
        $delta = $Lt - $currentLen;
    }

    // Подсчитываем количество бусин каждого диаметра
    $sizes = array_count_values($beads);

    // Сортируем размеры в порядке убывания для наглядности
    krsort($sizes);

    $parts = [];
    foreach ($sizes as $d => $n) {
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
