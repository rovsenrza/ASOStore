<?php

namespace Database\Seeders;

use App\Enums\AppVisibility;
use App\Enums\SourceType;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\CatalogApp;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Illustrative demo catalog for local development, ported from the iOS
 * MockCatalog. Fictional apps; no ratings or usage numbers (PRODUCT.md).
 * Nothing here is installable: no artifacts are seeded.
 */
class DemoCatalogSeeder extends Seeder
{
    private const CATEGORIES = [
        ['productivity', 'Продуктивность', 'Работайте умнее'],
        ['photo-video', 'Фото и видео', 'Создавайте новое'],
        ['health', 'Здоровье', 'Забота о себе'],
        ['travel', 'Путешествия', 'Весь мир рядом'],
        ['weather', 'Погода', 'Прогноз под рукой'],
        ['music', 'Музыка', 'Звук для любого настроения'],
        ['business', 'Бизнес', 'Работа без лишнего'],
        ['utilities', 'Утилиты', 'Полезные инструменты'],
        ['education', 'Образование', 'Учитесь каждый день'],
        ['games-arcade', 'Аркады', 'Короткие партии', 'GAMES'],
        ['games-puzzle', 'Головоломки', 'Для ума', 'GAMES'],
        ['games-racing', 'Гонки', 'Скорость и дрифт', 'GAMES'],
    ];

    /**
     * slug, name, subtitle, category, publisher, description, release notes, version, featured rank, days since release
     */
    private const APPS = [
        ['focus-notes', 'Focus Notes', 'Спокойное пространство для идей', 'productivity', 'North Studio',
            'Собирайте мысли, планы и важные заметки в одном спокойном пространстве. Умные папки, быстрый поиск и фокус-режим помогают не терять главное.',
            'Новый режим дня, быстрые виджеты и более удобная работа с тегами.', '3.4.0', 1, 3],
        ['pixel-weather', 'Pixel Weather', 'Погода без лишнего шума', 'weather', 'Mono Labs',
            'Точный прогноз в чистом визуальном формате: почасовая погода, осадки, ветер и полезные виджеты.',
            'Добавлены интерактивная карта осадков и новые виджеты экрана блокировки.', '2.8.1', 2, 12],
        ['tempo', 'Tempo', 'Музыка для концентрации', 'music', 'Waveform House',
            'Подборки фоновой музыки и звуковых сцен для работы, чтения и отдыха.',
            'Появились совместные сессии и таймер плавного завершения.', '5.2', 3, 1],
        ['habit-garden', 'Habit Garden', 'Маленькие привычки каждый день', 'health', 'Moss Software',
            'Превращайте полезные привычки в уютный сад и наблюдайте, как он растёт вместе с вашим прогрессом.',
            'Новые растения, серии достижений и мягкие напоминания.', '1.9', 4, 20],
        ['frame', 'Frame', 'Редактор красивых историй', 'photo-video', 'Frame Collective',
            'Создавайте выразительные истории, постеры и коллажи из готовых макетов или с чистого листа.',
            'Двадцать новых шаблонов и экспорт в высоком разрешении.', '4.1', 5, 2],
        ['atlas', 'Atlas', 'Маршруты для новых открытий', 'travel', 'Atlas Maps',
            'Сохраняйте любимые места, собирайте маршруты и открывайте города в собственном темпе.',
            'Офлайн-подборки и заметки для сохранённых мест.', '2.3', null, 30],
        ['orbit-mail', 'Orbit Mail', 'Почта, которая не отвлекает', 'business', 'Orbit Systems',
            'Быстрый почтовый клиент с умной сортировкой и удобной работой с несколькими ящиками.',
            'Улучшен поиск и добавлены быстрые ответы.', '6.0', null, 45],
        ['paper-scan', 'Paper Scan', 'Сканер всегда под рукой', 'utilities', 'Quiet Tools',
            'Сканируйте документы, исправляйте перспективу и сохраняйте чистые PDF за несколько секунд.',
            'Автоматическое распознавание таблиц и улучшенная резкость.', '3.7', null, 8],
        ['lingua', 'Lingua', 'Язык через живые диалоги', 'education', 'Lingua Works',
            'Короткие ежедневные уроки, разговорные ситуации и персональный словарь.',
            'Новый курс испанского и тренировка произношения.', '7.4', null, 60],
        // Fictional demo games for the Игры tab.
        ['neon-drift', 'Neon Drift', 'Дрифт по ночному городу', 'games-racing', 'Nightline Games',
            'Аркадные гонки с дрифтом по неоновым трассам, настройкой машин и заездами на время.',
            'Новая трасса «Порт» и три автомобиля.', '2.1', 6, 4],
        ['block-quest', 'Block Quest', 'Собирай, строй, исследуй', 'games-arcade', 'Cubic Studio',
            'Исследуйте мир из блоков, стройте базы и открывайте новые биомы.',
            'Подводный биом и улучшенное освещение.', '1.12', 7, 6],
        ['word-garden', 'Word Garden', 'Слова вырастают в сад', 'games-puzzle', 'Moss Software',
            'Составляйте слова из букв и выращивайте свой сад. Сотни уровней без спешки.',
            'Ежедневные задания и тёмная тема.', '3.0', null, 9],
        ['sky-hopper', 'Sky Hopper', 'Прыжки по облакам', 'games-arcade', 'Paper Plane',
            'Лёгкая аркада на одну руку: прыгайте всё выше и собирайте звёзды.',
            'Новые скины и режим испытаний.', '1.4', null, 15],
    ];

    public function run(): void
    {
        $categories = [];
        foreach (self::CATEGORIES as $order => $category) {
            [$slug, $title, $subtitle] = $category;
            $categories[$slug] = AppCategory::updateOrCreate(
                ['slug' => $slug],
                ['title' => $title, 'subtitle' => $subtitle, 'kind' => $category[3] ?? 'APPS', 'sort_order' => $order],
            );
        }

        foreach (self::APPS as [$slug, $name, $subtitle, $category, $publisher, $description, $notes, $version, $rank, $daysAgo]) {
            $app = CatalogApp::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'subtitle' => $subtitle,
                'description' => $description,
                'category_id' => $categories[$category]->id,
                'publisher_id' => AppPublisher::firstOrCreate(['name' => $publisher])->id,
                'source_type' => SourceType::OwnBuild,
                'visibility' => AppVisibility::Published,
                'age_rating' => '4+',
                'featured_rank' => $rank,
            ]);

            $app->versions()->updateOrCreate(
                ['version' => $version, 'build_number' => str_replace('.', '', $version)],
                ['release_notes' => $notes, 'min_ios_version' => '17.0', 'released_at' => Carbon::now()->subDays($daysAgo)],
            );
        }

        // The native Storefront is itself a catalog item, hidden from the catalog (IMPLEMENTATION_PLAN §5.6).
        CatalogApp::updateOrCreate(['slug' => 'storefront'], [
            'name' => config('storefront.brand'),
            'subtitle' => 'Каталог приложений',
            'description' => 'Нативное приложение-витрина.',
            'category_id' => $categories['utilities']->id,
            'publisher_id' => AppPublisher::firstOrCreate(['name' => config('storefront.brand')])->id,
            'source_type' => SourceType::OwnBuild,
            'visibility' => AppVisibility::Hidden,
            'is_storefront' => true,
        ]);
    }
}
