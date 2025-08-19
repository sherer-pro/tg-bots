<?php

use PHPUnit\Framework\TestCase;
use function Bracelet\braceletText;

/**
 * Тесты функции braceletText, рассчитывающей последовательность бусин.
 */
final class BraceletTextTest extends TestCase
{
    /**
     * Выполняет переданную функцию, перехватывая возможные предупреждения.
     * Если предупреждения появляются, тест завершается неудачей.
     *
     * @param callable $fn Вычисление, которое необходимо выполнить.
     * @return mixed       Результат работы переданной функции.
     */
    private function executeWithoutWarnings(callable $fn)
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_WARNING) {
                $warnings[] = $errstr;
            }
            // Возвращаем true, чтобы обозначить, что предупреждение обработано
            return true;
        });

        $result = $fn();

        restore_error_handler();

        // Убеждаемся, что предупреждений не возникло
        $this->assertSame([], $warnings, 'Не должно возникать предупреждений');

        return $result;
    }

    /**
     * Проверка типичного набора параметров.
     */
    public function testTypicalValues(): void
    {
        // Типичный набор исходных данных пользователя
        $result = $this->executeWithoutWarnings(
            fn() => braceletText(15, 1, [10, 8], 10, 5, 'ru')
        );

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
        // Ожидаем исключение, так как число витков не может быть нулевым
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Количество витков должно быть больше нуля');
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
        // При отсутствии магнита и ненулевом допуске набор
        // бусин не удаётся собрать с нужной длиной
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Нужная длина браслета не достигнута');
        braceletText(15, 1, [10], 0, 5, 'ru');
    }

    /**
     * Граничное значение: отсутствие допуска по длине.
     */
    public function testZeroTolerance(): void
    {
        $result = $this->executeWithoutWarnings(
            fn() => braceletText(15, 1, [10], 10, 0, 'ru')
        );
        $this->assertStringContainsString('0 мм допуск', $result);
    }

    /**
     * Граничное значение: магнит не может быть больше или равен
     * общей длине браслета.
     */
    public function testMagnetTooLong(): void
    {
        // Ожидаем исключение, так как магнит занимает всю длину браслета
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Общая длина браслета должна превышать размер магнита');
        braceletText(15, 1, [10], 155, 5, 'ru');
    }

    /**
     * Проверка корректности на больших значениях параметров,
     * имитируя длинный многооборотный браслет.
     */
    public function testLargeValues(): void
    {
        $result = $this->executeWithoutWarnings(
            fn() => braceletText(20, 100, [10, 8, 6], 10, 11, 'ru')
        );

        // После повторных корректировок длины итоговое количество бусин
        // каждого диаметра может измениться, поэтому проверяем обновлённый результат
        $this->assertSame(
            'Обхват 20 см → 834 бусин Ø10 мм и 833 бусин Ø8 мм и 833 бусин Ø6 мм + 11 мм допуск + 10 мм крепление',
            $result
        );
    }

    /**
     * Сценарий, когда предел корректировок достигнут,
     * но нужная длина браслета так и не подобрана.
     */
    public function testIterationLimitReached(): void
    {
        // Ожидаем исключение из-за невозможности скорректировать длину
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Нужная длина браслета не достигнута');

        // Параметры, при которых длина будет колебаться и не войдёт в допуск
        braceletText(10, 1, [7], 3, 5, 'ru');
    }
}
