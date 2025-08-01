<?php
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
    // Официально опубликованные Telegram диапазоны IPv4 и IPv6
    $ranges = [
        '149.154.160.0/20',
        '91.108.4.0/22',
        '2001:67c:4e8::/48',
        '2001:b28:f23d::/48',
        '2001:b28:f23f::/48',
    ];

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
 * @return bool true, если адрес попадает в диапазон; иначе false.
 */
function ipInRange(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) {
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

    $prefix = (int) $prefix;
    $bytes  = intdiv($prefix, 8);       // Полные байты маски
    $bits   = $prefix % 8;              // Оставшиеся биты

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
