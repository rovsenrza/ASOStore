<?php

namespace App\Services\TelegramStore\Bot;

final class Screen
{
    /**
     * @param  list<list<array<string, string>>>  $rows  inline keyboard rows
     */
    public function __construct(
        public readonly string $text,
        public readonly array $rows = [],
    ) {}

    /** @return array{inline_keyboard: list<list<array<string, string>>>}|null */
    public function keyboard(): ?array
    {
        return $this->rows === [] ? null : ['inline_keyboard' => $this->rows];
    }

    /** @return array{text: string, callback_data: string} */
    public static function button(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }

    /** @return array{text: string, url: string} */
    public static function link(string $text, string $url): array
    {
        return ['text' => $text, 'url' => $url];
    }
}
