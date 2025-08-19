<?php

declare(strict_types=1);

namespace Bracelet;

/**
 * DTO, инкапсулирующий данные входящего запроса Telegram.
 *
 * Объект хранит исходное сообщение и ключевые идентификаторы,
 * необходимые для дальнейшей обработки.
 */
class Request
{
    /**
     * Весь массив `message` из обновления Telegram.
     *
     * @var array
     */
    public array $message;

    /**
     * Уникальный идентификатор чата, откуда пришёл запрос.
     */
    public int $chatId;

    /**
     * Идентификатор пользователя Telegram.
     */
    public int $userId;

    /**
     * Предпочитаемый язык пользователя (ISO 639-1).
     */
    public string $userLang;

    /**
     * Конструктор заполняет все свойства DTO.
     *
     * @param array  $message  Исходное сообщение Telegram.
     * @param int    $chatId   Идентификатор чата.
     * @param int    $userId   Идентификатор пользователя.
     * @param string $userLang Код языка пользователя.
     */
    public function __construct(array $message, int $chatId, int $userId, string $userLang)
    {
        $this->message  = $message;
        $this->chatId   = $chatId;
        $this->userId   = $userId;
        $this->userLang = $userLang;
    }
}
