<?php

namespace App\Services\TelegramStore\Bot;

use Carbon\CarbonInterface;

final class Format
{
    /** Start of the day $daysAgo days back in the display timezone, in the app timezone for queries. */
    public static function dayStart(int $daysAgo = 0): CarbonInterface
    {
        return now((string) config('telegram_store.display_timezone', 'Europe/Moscow'))
            ->startOfDay()
            ->subDays($daysAgo)
            ->setTimezone((string) config('app.timezone'));
    }

    public static function plural(int $number, string $one, string $few, string $many): string
    {
        $mod100 = $number % 100;
        $mod10 = $number % 10;
        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        return match (true) {
            $mod10 === 1 => $one,
            $mod10 >= 2 && $mod10 <= 4 => $few,
            default => $many,
        };
    }

    public static function months(int $months): string
    {
        return $months.' '.self::plural($months, 'месяц', 'месяца', 'месяцев');
    }

    public static function days(int $days): string
    {
        return $days.' '.self::plural($days, 'день', 'дня', 'дней');
    }

    public static function rub(int $amount): string
    {
        return number_format($amount, 0, '', ' ').'₽';
    }

    public static function time(?CarbonInterface $time): string
    {
        if ($time === null) {
            return '—';
        }
        $zone = (string) config('telegram_store.display_timezone', 'Europe/Moscow');
        $label = $zone === 'Europe/Moscow' ? 'МСК' : $zone;

        return $time->copy()->setTimezone($zone)->format('H:i').' '.$label;
    }

    public static function date(?CarbonInterface $time): string
    {
        return $time ? $time->copy()->setTimezone((string) config('telegram_store.display_timezone', 'Europe/Moscow'))->format('d.m.Y') : '—';
    }

    public static function status(string $status): string
    {
        return match ($status) {
            'PENDING' => '⏳ ждёт оплаты',
            'REVIEW' => '🔎 проверяется',
            'PAID' => '✅ оплачен',
            'EXPIRED' => '⌛ истёк',
            'CANCELLED' => '✖️ отменён',
            'REJECTED' => '❌ оплата не найдена',
            default => $status,
        };
    }
}
