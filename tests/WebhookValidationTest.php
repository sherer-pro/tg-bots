<?php

use PHPUnit\Framework\TestCase;

/**
 * Тесты функции isValidWebAppData, проверяющей корректность данных из web_app.
 */
final class WebhookValidationTest extends TestCase
{
    /**
     * Базовый набор валидных данных web_app.
     *
     * @var array<string, mixed>
     */
    private array $baseData = [
        'wrist_cm'     => 16,
        'wraps'        => 2,
        // Узор задаётся числами, разделёнными точкой с запятой
        'pattern'      => '10;8',
        'magnet_mm'    => 10,
        'tolerance_mm' => 5,
        'lang'         => 'ru',
    ];

    /**
     * Подключаем файл webhook.php, определяющий функцию
     * isValidWebAppData, но без выполнения основного кода скрипта.
     */
    public static function setUpBeforeClass(): void
    {
        define('WEBHOOK_LIB', true);
        require_once __DIR__ . '/../bracelet/webhook.php';
    }

    /**
     * Проверяем, что валидный набор данных проходит валидацию.
     */
    public function testValidData(): void
    {
        $this->assertTrue(isValidWebAppData($this->baseData));
    }

    /**
     * Числовые параметры должны быть положительными
     * и не выходить за пределы допустимых значений.
     */
    public function testRejectsOutOfRangeNumbers(): void
    {
        // Превышение максимального обхвата
        $data = $this->baseData;
        $data['wrist_cm'] = 150;
        $this->assertFalse(isValidWebAppData($data));

        // Недопустимое количество витков
        $data = $this->baseData;
        $data['wraps'] = 0;
        $this->assertFalse(isValidWebAppData($data));

        // Нулевой размер магнита
        $data = $this->baseData;
        $data['magnet_mm'] = 0;
        $this->assertFalse(isValidWebAppData($data));
    }

    /**
     * Строка паттерна не должна превышать 100 символов.
     */
    public function testRejectsLongPatternString(): void
    {
        $data = $this->baseData;
        $data['pattern'] = str_repeat('1', 101);
        $this->assertFalse(isValidWebAppData($data));
    }

    /**
     * Количество элементов паттерна ограничено двадцатью.
     */
    public function testRejectsTooManyPatternItems(): void
    {
        $data = $this->baseData;
        $data['pattern'] = implode(';', array_fill(0, 25, '1'));
        $this->assertFalse(isValidWebAppData($data));
    }
}
