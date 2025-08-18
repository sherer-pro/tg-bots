<?php

declare(strict_types=1);

/**
 * Обрабатывает пользовательский ввод на конкретном шаге сценария.
 * Возвращает следующий шаг, текст ответа и обновлённые данные пользователя.
 *
 * @param int    $step  Текущий шаг диалога.
 * @param string $input Введённое пользователем значение.
 * @param array  $data  Накопленные значения предыдущих шагов.
 * @param string $lang  Язык сообщений ("ru" или "en").
 *
 * @return array{next:int,text:string,data:array}
 */
function processStep(int $step, string $input, array $data, string $lang = 'ru'): array
{
    $input = trim($input);

    switch ($step) {
        case 1:
            // Шаг 1 — ввод обхвата запястья в сантиметрах
            $val = str_replace(',', '.', $input);
            if (!is_numeric($val) || ($v = (float)$val) <= 0 || $v >= 100) {
                $text = $lang === 'en'
                    ? 'Invalid value. Enter wrist circumference in centimeters.'
                    : 'Некорректный обхват. Введи число в сантиметрах.';
                return ['next' => 1, 'text' => $text, 'data' => $data];
            }
            $data['wrist_cm'] = (float)$val;
            $text = $lang === 'en'
                ? 'How many wraps will the bracelet have?'
                : 'Сколько будет витков?';
            return ['next' => 2, 'text' => $text, 'data' => $data];

        case 2:
            // Шаг 2 — количество витков
            if (!ctype_digit($input) || ($v = (int)$input) <= 0 || $v > 10) {
                $text = $lang === 'en'
                    ? 'Invalid wraps count. Enter a positive integer not greater than 10.'
                    : 'Некорректное число витков. Введи положительное целое число не больше 10.';
                return ['next' => 2, 'text' => $text, 'data' => $data];
            }
            $data['wraps'] = $v;
            $text = $lang === 'en'
                ? 'Enter bead pattern in millimeters separated by semicolons (e.g., 10;8).'
                : 'Введи узор: размеры бусин в мм через точку с запятой (например 10;8).';
            return ['next' => 3, 'text' => $text, 'data' => $data];

        case 3:
            // Шаг 3 — описание узора
            $patternStr = str_replace(' ', '', str_replace(',', '.', $input));
            $parts = array_filter(array_map('trim', explode(';', $patternStr)), 'strlen');
            $valid = !empty($parts) && count($parts) <= 20;
            if ($valid) {
                foreach ($parts as $p) {
                    if (!is_numeric($p)) {
                        $valid = false;
                        break;
                    }
                    $pv = (float)$p;
                    if ($pv <= 0 || $pv >= 100) {
                        $valid = false;
                        break;
                    }
                }
            }
            if (!$valid) {
                $text = $lang === 'en' ? 'Invalid pattern.' : 'Некорректный узор.';
                return ['next' => 3, 'text' => $text, 'data' => $data];
            }
            $data['pattern'] = implode(';', $parts);
            $text = $lang === 'en'
                ? 'Enter magnet size in millimeters.'
                : 'Укажи размер магнита в миллиметрах.';
            return ['next' => 4, 'text' => $text, 'data' => $data];

        case 4:
            // Шаг 4 — размер магнита
            $val = str_replace(',', '.', $input);
            if (!is_numeric($val) || ($v = (float)$val) <= 0 || $v >= 100) {
                $text = $lang === 'en'
                    ? 'Invalid magnet size.'
                    : 'Некорректный размер магнита.';
                return ['next' => 4, 'text' => $text, 'data' => $data];
            }
            $data['magnet_mm'] = (float)$val;
            $text = $lang === 'en'
                ? 'Enter allowable length tolerance in millimeters.'
                : 'Введи допуск по длине в миллиметрах.';
            return ['next' => 5, 'text' => $text, 'data' => $data];

        case 5:
            // Шаг 5 — допуск по длине и финальный расчёт
            $val = str_replace(',', '.', $input);
            if (!is_numeric($val) || ($v = (float)$val) <= 0 || $v >= 100) {
                $text = $lang === 'en'
                    ? 'Invalid tolerance.'
                    : 'Некорректный допуск.';
                return ['next' => 5, 'text' => $text, 'data' => $data];
            }
            $data['tolerance_mm'] = (float)$val;
            $pattern = array_map('floatval', explode(';', $data['pattern']));
            $text = braceletText(
                (float)$data['wrist_cm'],
                (int)$data['wraps'],
                $pattern,
                (float)$data['magnet_mm'],
                (float)$data['tolerance_mm'],
                $lang
            );
            return ['next' => 0, 'text' => $text, 'data' => $data];

        default:
            // Любой другой шаг означает, что состояние потеряно.
            $text = $lang === 'en'
                ? 'Send /start to begin.'
                : 'Отправь /start, чтобы начать.';
            return ['next' => 0, 'text' => $text, 'data' => []];
    }
}

