<?php

namespace App\Services\Seo;

use App\Models\AppCategory;

/**
 * Headings and introductions for category pages, written for people searching for that kind of
 * app («банковские приложения на айфон»). Categories without their own copy get a generic one.
 */
class CategoryCopy
{
    /** slug => [heading, introduction] */
    private const COPY = [
        'finance' => ['Банковские и финансовые приложения для iPhone',
            'Мобильные банки, кошельки и инвестиции, которых нет в российском App Store. Скачайте приложение банка на айфон через Ru App Store: установка идёт прямо с телефона, без компьютера и джейлбрейка, а обновления приходят в каталог.'],
        'russkie-prilozheniya' => ['Русские приложения для iPhone',
            'Российские приложения, которые пропали из App Store или не скачиваются в России: СберБанк Онлайн, Т-Банк, Альфа-Банк, ПСБ, сервисы VK, MAX и другие. Все они устанавливаются на iPhone через Ru App Store и обновляются из каталога.'],
        'social' => ['Соцсети и мессенджеры для iPhone',
            'Приложения для общения, которых нет в российском App Store. Скачайте соцсеть или мессенджер на айфон через Ru App Store и получайте новые версии прямо в каталоге.'],
        'utilities' => ['Утилиты и сервисы для iPhone',
            'Маркетплейсы, доставка, инструменты и полезные сервисы, которых нет в App Store вашего региона. Установка на айфон — через Ru App Store, без компьютера.'],
        'photo-video' => ['Приложения для фото и видео на iPhone',
            'Фото- и видеосоцсети, редакторы и стриминг, недоступные в российском App Store. Скачайте их на айфон через Ru App Store.'],
        'games-arcade' => ['Аркадные игры для iPhone',
            'Аркады, раннеры и казуальные игры, которых нет в российском App Store. Скачайте игру на айфон через Ru App Store и обновляйте её из каталога.'],
        'games-action' => ['Экшен-игры для iPhone',
            'Экшен-игры, недоступные в российском App Store. Скачайте их на айфон через Ru App Store и получайте обновления в каталоге.'],
        'productivity' => ['Приложения для работы и учёбы на iPhone',
            'Госсервисы, почта, документы и заметки, которых нет в App Store вашего региона. Установка на айфон через Ru App Store — без компьютера и джейлбрейка.'],
        'business' => ['Бизнес-приложения для iPhone',
            'Кабинеты продавцов маркетплейсов, рабочие мессенджеры и сервисы для предпринимателей, которых нет в российском App Store.'],
        'music' => ['Музыкальные приложения для iPhone',
            'Стриминг, подкасты и плееры, недоступные в российском App Store. Скачайте их на айфон через Ru App Store.'],
        'travel' => ['Приложения для путешествий на iPhone',
            'Билеты, карты и сервисы для поездок, которых нет в российском App Store.'],
        'education' => ['Образовательные приложения для iPhone',
            'Обучение, подготовка к экзаменам и курсы, которых нет в российском App Store.'],
        'entertainment' => ['Развлекательные приложения для iPhone',
            'Кино, сериалы и видео, которые можно установить на айфон через Ru App Store.'],
        'health' => ['Приложения для здоровья на iPhone',
            'Приложения для здоровья и фитнеса, которых нет в российском App Store.'],
    ];

    public static function heading(AppCategory $category): string
    {
        return self::COPY[$category->slug][0] ?? $category->title.' для iPhone';
    }

    public static function intro(AppCategory $category, string $count): string
    {
        return self::COPY[$category->slug][1]
            ?? $count.' из категории «'.$category->title.'», которых нет в российском App Store. Все устанавливаются на iPhone через Ru App Store — без компьютера и джейлбрейка.';
    }
}
