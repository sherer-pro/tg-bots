<?php

use PHPUnit\Framework\TestCase;

/**
 * Тесты функции braceletText, рассчитывающей последовательность бусин.
 */
final class BraceletTextTest extends TestCase
{
    /**
     * Проверка типичного набора параметров.
     */
    public function testTypicalValues(): void
    {
        // Типичный набор исходных данных пользователя
        $result = braceletText(15, 1, [10, 8], 10, 5, 'ru');

        // Ожидаем точное текстовое описание с количеством бусин каждого размера
        $this->assertSame(
            'Обхват 15 см → 8 бусин Ø10 мм и 8 бусин Ø8 мм + 5 мм допуск + 10 мм крепление',
            $result
        );
    }

    /**
     * Граничное значение: нулевое количество витков недопустимо
     * и приводит к ошибке вычисления.
     */
    public function testZeroWraps(): void
    {
        $this->expectException(ValueError::class);
        braceletText(15, 0, [10], 10, 5, 'ru');
    }

    /**
     * Граничное значение: пустой паттерн должен вызывать исключение.
     */
    public function testEmptyPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        braceletText(15, 1, [], 10, 5, 'ru');
    }

    /**
     * Граничное значение: отсутствие магнита (0 мм).
     */
    public function testZeroMagnet(): void
    {
        $result = braceletText(15, 1, [10], 0, 5, 'ru');
        $this->assertStringContainsString('0 мм крепление', $result);
    }

    /**
     * Граничное значение: отсутствие допуска по длине.
     */
    public function testZeroTolerance(): void
    {
        $result = braceletText(15, 1, [10], 10, 0, 'ru');
        $this->assertStringContainsString('0 мм допуск', $result);
    }

    /**
     * Граничное значение: параметры приводят к пустому набору бусин.
     */
    public function testEmptyBeads(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Подбираем параметры так, чтобы длина браслета совпадала
        // с размером магнита. В результате массив с бусинами пуст.
        braceletText(15, 1, [10], 155, 5, 'ru');
    }

    /**
     * Проверка корректности на больших значениях параметров,
     * имитируя длинный многооборотный браслет.
     */
    public function testLargeValues(): void
    {
        $result = braceletText(20, 100, [10, 8, 6], 10, 5, 'ru');

        // После повторных корректировок длины итоговое количество бусин
        // каждого диаметра может измениться, поэтому проверяем обновлённый результат
        $this->assertSame(
            'Обхват 20 см → 833 бусин Ø10 мм и 833 бусин Ø8 мм и 833 бусин Ø6 мм + 5 мм допуск + 10 мм крепление',
            $result
        );
    }
}
