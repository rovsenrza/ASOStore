#if DEBUG
/// Illustrative demo data for previews and mock mode. Fictional apps, no ratings
/// or usage numbers (PRODUCT.md). Replaced by API data in Phase 4.
enum MockCatalog {
    static let featured = StoreApp(
        id: "focus-notes",
        name: "Focus Notes",
        subtitle: "Спокойное пространство для идей",
        category: "Продуктивность",
        developer: "North Studio",
        description: "Собирайте мысли, планы и важные заметки в одном спокойном пространстве. Умные папки, быстрый поиск и фокус-режим помогают не терять главное.",
        whatsNew: "Новый режим дня, быстрые виджеты и более удобная работа с тегами.",
        version: "3.4.0",
        ageRating: "4+",
        size: "84 МБ",
        systemImage: "note.text",
        artwork: .blue,
        screenshotTitles: ["Все мысли — рядом", "Планируйте день", "Фокус без шума"],
        installState: .get
    )

    static let pixelWeather = StoreApp(
        id: "pixel-weather",
        name: "Pixel Weather",
        subtitle: "Погода без лишнего шума",
        category: "Погода",
        developer: "Mono Labs",
        description: "Точный прогноз в чистом визуальном формате: почасовая погода, осадки, ветер и полезные виджеты.",
        whatsNew: "Добавлены интерактивная карта осадков и новые виджеты экрана блокировки.",
        version: "2.8.1",
        ageRating: "4+",
        size: "46 МБ",
        systemImage: "sun.max.fill",
        artwork: .orange,
        screenshotTitles: ["Прогноз с первого взгляда", "Точные осадки", "Красивые виджеты"],
        installState: .preparing(progress: 0.42)
    )

    static let tempo = StoreApp(
        id: "tempo",
        name: "Tempo",
        subtitle: "Музыка для концентрации",
        category: "Музыка",
        developer: "Waveform House",
        description: "Подборки фоновой музыки и звуковых сцен для работы, чтения и отдыха.",
        whatsNew: "Появились совместные сессии и таймер плавного завершения.",
        version: "5.2",
        ageRating: "4+",
        size: "128 МБ",
        systemImage: "waveform",
        artwork: .indigo,
        screenshotTitles: ["Найдите свой ритм", "Музыка для фокуса", "Сессии с друзьями"],
        installState: .readyToInstall
    )

    static let habitGarden = StoreApp(
        id: "habit-garden",
        name: "Habit Garden",
        subtitle: "Маленькие привычки каждый день",
        category: "Здоровье",
        developer: "Moss Software",
        description: "Превращайте полезные привычки в уютный сад и наблюдайте, как он растёт вместе с вашим прогрессом.",
        whatsNew: "Новые растения, серии достижений и мягкие напоминания.",
        version: "1.9",
        ageRating: "4+",
        size: "72 МБ",
        systemImage: "leaf.fill",
        artwork: .green,
        screenshotTitles: ["Вырасти новую привычку", "Следите за сериями", "Ваш личный сад"],
        installState: .updateAvailable
    )

    static let frame = StoreApp(
        id: "frame",
        name: "Frame",
        subtitle: "Редактор красивых историй",
        category: "Фото и видео",
        developer: "Frame Collective",
        description: "Создавайте выразительные истории, постеры и коллажи из готовых макетов или с чистого листа.",
        whatsNew: "Двадцать новых шаблонов и экспорт в высоком разрешении.",
        version: "4.1",
        ageRating: "4+",
        size: "215 МБ",
        systemImage: "camera.filters",
        artwork: .pink,
        screenshotTitles: ["Истории с характером", "Сотни макетов", "Экспорт без потерь"],
        installState: .delivered
    )

    static let recommended = [pixelWeather, tempo, habitGarden, frame]

    static let allApps = recommended + [
        StoreApp(
            id: "atlas",
            name: "Atlas",
            subtitle: "Маршруты для новых открытий",
            category: "Путешествия",
            developer: "Atlas Maps",
            description: "Сохраняйте любимые места, собирайте маршруты и открывайте города в собственном темпе.",
            whatsNew: "Офлайн-подборки и заметки для сохранённых мест.",
            version: "2.3",
            ageRating: "4+",
            size: "164 МБ",
            systemImage: "map.fill",
            artwork: .cyan,
            screenshotTitles: ["Город в вашем ритме", "Сохраняйте места", "Маршруты офлайн"],
            installState: .notEligible(reason: .deviceNotEligible)
        ),
        StoreApp(
            id: "orbit-mail",
            name: "Orbit Mail",
            subtitle: "Почта, которая не отвлекает",
            category: "Бизнес",
            developer: "Orbit Systems",
            description: "Быстрый почтовый клиент с умной сортировкой и удобной работой с несколькими ящиками.",
            whatsNew: "Улучшен поиск и добавлены быстрые ответы.",
            version: "6.0",
            ageRating: "4+",
            size: "98 МБ",
            systemImage: "paperplane.fill",
            artwork: .purple,
            screenshotTitles: ["Порядок во входящих", "Умная сортировка", "Быстрые ответы"],
            installState: .failed(reason: .incompatibleDevice)
        ),
        StoreApp(
            id: "paper-scan",
            name: "Paper Scan",
            subtitle: "Сканер всегда под рукой",
            category: "Утилиты",
            developer: "Quiet Tools",
            description: "Сканируйте документы, исправляйте перспективу и сохраняйте чистые PDF за несколько секунд.",
            whatsNew: "Автоматическое распознавание таблиц и улучшенная резкость.",
            version: "3.7",
            ageRating: "4+",
            size: "51 МБ",
            systemImage: "doc.viewfinder.fill",
            artwork: .graphite,
            screenshotTitles: ["Сканирование за секунды", "Чистый результат", "Удобный экспорт"],
            installState: .unavailable
        ),
        StoreApp(
            id: "lingua",
            name: "Lingua",
            subtitle: "Язык через живые диалоги",
            category: "Образование",
            developer: "Lingua Works",
            description: "Короткие ежедневные уроки, разговорные ситуации и персональный словарь.",
            whatsNew: "Новый курс испанского и тренировка произношения.",
            version: "7.4",
            ageRating: "4+",
            size: "187 МБ",
            systemImage: "character.bubble.fill",
            artwork: .red,
            screenshotTitles: ["Говорите с первого дня", "Уроки по 10 минут", "Умный словарь"],
            installState: .notEligible(reason: .unauthenticated)
        )
    ]

    static let newReleases = Array(allApps.suffix(4))
    static let updates = allApps.filter { $0.installState == .updateAvailable }
    static let delivered = allApps.filter { $0.installState == .delivered }

    static let categories = [
        StoreCategory(id: "productivity", title: "Продуктивность", subtitle: "Работайте умнее", systemImage: "checkmark.circle.fill", artwork: .blue),
        StoreCategory(id: "creative", title: "Творчество", subtitle: "Создавайте новое", systemImage: "paintpalette.fill", artwork: .pink),
        StoreCategory(id: "health", title: "Здоровье", subtitle: "Забота о себе", systemImage: "heart.fill", artwork: .green),
        StoreCategory(id: "travel", title: "Путешествия", subtitle: "Весь мир рядом", systemImage: "airplane", artwork: .orange)
    ]

    static let trendingSearches = ["фоторедактор", "погода", "заметки", "изучение языков", "сканер документов"]
}
#endif
