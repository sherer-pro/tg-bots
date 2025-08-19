<?php

use PHPUnit\Framework\TestCase;
use function Bracelet\processStep;

/**
 * Тесты функции processStep, реализующей пошаговый сценарий.
 */
final class ScenarioTest extends TestCase
{
    /**
     * Проверяем переход на следующий шаг при валидном вводе на первом шаге.
     */
    public function testStep1Valid(): void
    {
        $result = processStep(1, '15', [], 'ru');
        $this->assertSame(2, $result['next']);
        $this->assertSame(15.0, $result['data']['wrist_cm']);
    }

    /**
     * Проверяем, что некорректный ввод на шаге 1 остаётся на том же шаге.
     */
    public function testStep1Invalid(): void
    {
        $result = processStep(1, 'abc', [], 'ru');
        $this->assertSame(1, $result['next']);
        $this->assertStringContainsString('Некорректный обхват', $result['text']);
    }

    /**
     * Некорректное количество витков должно оставить пользователя на шаге 2.
     */
    public function testStep2Invalid(): void
    {
        $data = ['wrist_cm' => 15.0];
        $result = processStep(2, '0', $data, 'ru');
        $this->assertSame(2, $result['next']);
        $this->assertStringContainsString('Некорректное число витков', $result['text']);
    }

    /**
     * Некорректный узор не переводит на следующий шаг.
     */
    public function testStep3InvalidPattern(): void
    {
        $data = ['wrist_cm' => 15.0, 'wraps' => 1];
        $result = processStep(3, 'bad;pattern', $data, 'ru');
        $this->assertSame(3, $result['next']);
        $this->assertStringContainsString('Некорректный узор', $result['text']);
    }

    /**
     * Проходим весь сценарий с валидными значениями до финального результата.
     */
    public function testFullValidScenario(): void
    {
        $data = [];
        $result = processStep(1, '15', $data, 'ru');
        $this->assertSame(2, $result['next']);
        $data = $result['data'];

        $result = processStep(2, '2', $data, 'ru');
        $this->assertSame(3, $result['next']);
        $data = $result['data'];

        $result = processStep(3, '10;8', $data, 'ru');
        $this->assertSame(4, $result['next']);
        $data = $result['data'];

        $result = processStep(4, '10', $data, 'ru');
        $this->assertSame(5, $result['next']);
        $data = $result['data'];

        // Используем чуть больший допуск, чтобы гарантировать
        // достижение требуемой длины без ошибок
        $result = processStep(5, '6', $data, 'ru');
        $this->assertSame(0, $result['next']);
        $this->assertStringContainsString('Обхват 15', $result['text']);
    }

    /**
     * Некорректный шаг приводит к сбросу состояния.
     */
    public function testResetOnInvalidStep(): void
    {
        $result = processStep(99, 'anything', ['wrist_cm' => 15.0], 'ru');
        $this->assertSame(0, $result['next']);
        $this->assertSame([], $result['data']);
        $this->assertStringContainsString('/start', $result['text']);
    }
}

