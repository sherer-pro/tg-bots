<?php

namespace Bracelet;

/**
 * Функции для проверки принадлежности IP-адресов диапазонам Telegram.
 *
 * @package Bracelet
 */

/**
 * Проверяет, принадлежит ли IP-адрес диапазонам Telegram.
 *
 * @param string $ip IP-адрес клиента.
 *
 * @return bool true, если адрес принадлежит одному из известных диапазонов Telegram.
 */
function isTelegramIP(string $ip): bool {
    // Загружаем официально опубликованные Telegram диапазоны IPv4 и IPv6
    // из конфигурационного файла. Файл возвращает массив строк в формате CIDR.
    $ranges = require __DIR__ . '/config/telegram_ips.php';

    foreach ($ranges as $range) {
        if (ipInRange($ip, $range)) {
            return true;
        }
    }
    return false;
}

/**
 * Проверяет, входит ли IP‑адрес в заданный диапазон CIDR.
 * Поддерживает как IPv4, так и IPv6.
 *
 * @param string $ip   Проверяемый IP-адрес.
 * @param string $cidr Диапазон в формате CIDR.
 *
 * @return bool true, если адрес попадает в диапазон; false, если адрес или
 *              префикс некорректны или не соответствуют друг другу.
*/
function ipInRange(string $ip, string $cidr): bool {
    if (!str_contains($cidr, '/')) {
        return false; // Некорректный формат диапазона
    }

    [$subnet, $prefix] = explode('/', $cidr, 2);

    $ipBin     = inet_pton($ip);
    $subnetBin = inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) {
        return false; // Один из адресов некорректен
    }

    // IPv4 имеет длину 4 байта, IPv6 — 16. Если длины не совпадают, значит
    // типы адресов различаются и сравнивать их нет смысла.
    if (strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    // Определяем длину IP-адреса в байтах и допустимый максимум префикса.
    // Для IPv4 это 4 байта (32 бита), для IPv6 — 16 байт (128 бит).
    $addrLength = strlen($ipBin);
    $maxPrefix  = $addrLength * 8;

    $prefix = (int) $prefix;            // Префикс, приведённый к целому числу

    // Проверяем, что префикс находится в допустимом диапазоне
    // (0–32 для IPv4 и 0–128 для IPv6). В противном случае дальнейшие
    // вычисления не имеют смысла.
    if ($prefix < 0 || $prefix > $maxPrefix) {
        return false;
    }

    $bytes = intdiv($prefix, 8);        // Количество полных байт маски
    $bits  = $prefix % 8;               // Число оставшихся битов

    // Формируем двоичную маску: сначала нужное количество байт, заполненных 1,
    // затем один байт с необходимым числом старших единичных битов и дополняем
    // нулевыми байтами до длины адреса.
    $mask = str_repeat("\xff", $bytes);
    if ($bits > 0) {
        $mask .= chr((0xff << (8 - $bits)) & 0xff);
    }
    $mask = str_pad($mask, strlen($ipBin), "\0");

    // Применяем маску к обоим адресам и сравниваем результат
    return ($ipBin & $mask) === ($subnetBin & $mask);
}
