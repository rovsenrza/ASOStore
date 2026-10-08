@php
    use App\Http\Controllers\Web\CatalogPageController as Page;
    $name = $app->name;
    $intro = "{$name} можно скачать на айфон через Ru App Store, даже если приложение пропало из App Store или не скачивается в России. "
        ."Мы подписываем {$name} для вашего iPhone: нужна iOS".($facts['min_ios'] ? " {$facts['min_ios']} или новее" : '')
        .', компьютер и джейлбрейк не нужны.';
    if ($facts['version']) {
        $intro .= " В каталоге — версия {$facts['version']}"
            .($facts['updated'] ? ' от '.$facts['updated']->locale('ru')->translatedFormat('j F Y') : '').'.';
    }
@endphp
<section class="page-hero app-hero" aria-labelledby="page-title">
  @include('seo.partials.crumbs', ['trail' => [['Каталог', '/apps'], [$app->category->title, $categoryUrl], [$name, null]]])
  <div class="app-head">
@if ($app->iconUrl())
    <img class="app-head__icon" src="{{ $app->iconUrl() }}" alt="Иконка {{ $name }}" width="128" height="128" fetchpriority="high">
@endif
    <div class="app-head__text">
      <h1 id="page-title">{{ $name }} для&nbsp;iPhone</h1>
      <p>{{ $app->subtitle ?: 'Установка на iPhone через Ru App Store — без компьютера и джейлбрейка.' }}</p>
      <ul class="app-facts" aria-label="О приложении">
        <li><span>Категория</span> <a href="{{ $categoryUrl }}">{{ $app->category->title }}</a></li>
@if ($facts['version'])
        <li><span>Версия</span> {{ $facts['version'] }}</li>
@endif
@if ($facts['min_ios'])
        <li><span>Требуется</span> iOS {{ $facts['min_ios'] }} или новее</li>
@endif
@if ($facts['size'])
        <li><span>Размер</span> {{ Page::megabytes($facts['size']) }} МБ</li>
@endif
@if ($app->publisher)
        <li><span>Разработчик</span> {{ $app->publisher->name }}</li>
@endif
      </ul>
      <div class="app-head__actions">
        <a class="btn btn--white" href="/buy.html">Получить доступ{{ $price ? ' — от '.$price.' ₽/мес' : '' }}</a>
        <a class="btn btn--on-blue" href="#install">Как установить</a>
      </div>
    </div>
  </div>
</section>

<div class="page-body">
  <section class="prose" aria-labelledby="about-title">
    <h2 id="about-title">{{ $name }} на iPhone без App&nbsp;Store</h2>
    <p>{{ $intro }}</p>
@if ($paragraphs !== [])
    <h3>О приложении {{ $name }}</h3>
@foreach (array_slice($paragraphs, 0, 12) as $paragraph)
    <p>{{ $paragraph }}</p>
@endforeach
@endif
  </section>

@if ($app->screenshots->isNotEmpty())
  <section aria-labelledby="shots-title">
    <h2 id="shots-title">Скриншоты {{ $name }}</h2>
    <ul class="shots" role="list">
@foreach ($app->screenshots->take(8) as $shot)
      <li><img src="{{ $shot->url() }}" alt="{{ $name }} на iPhone — скриншот {{ $loop->iteration }}" width="{{ $shot->width ?: 390 }}" height="{{ $shot->height ?: 844 }}" loading="lazy" decoding="async"></li>
@endforeach
    </ul>
  </section>
@endif

  <section id="install" aria-labelledby="install-title">
    <h2 id="install-title">Как скачать и установить {{ $name }} на&nbsp;iPhone</h2>
    <ol class="install-steps">
      <li><b>Купите доступ к Ru App Store.</b> Любой тариф{{ $price ? ' (от '.$price.' ₽ в месяц)' : '' }} открывает весь каталог. <a href="/buy.html">Тарифы и оплата</a></li>
      <li><b>Зарегистрируйте iPhone.</b> Откройте сайт в Safari и установите профиль регистрации — это занимает пару минут. <a href="/activate.html">Регистрация iPhone</a></li>
      <li><b>Установите приложение Ru App Store.</b> Оно появится на экране «Домой», как обычное приложение. <a href="/install.html">Установка</a></li>
      <li><b>Найдите «{{ $name }}» в каталоге</b> и нажмите «Установить». Обновления приходят туда же.</li>
    </ol>
  </section>

  <section aria-labelledby="faq-title">
    <h2 id="faq-title">Вопросы об установке {{ $name }}</h2>
    <div class="faq">
@foreach ($faq as [$question, $answer])
      <details{{ $loop->first ? ' open' : '' }}><summary>{{ $question }}</summary><p>{{ $answer }}</p></details>
@endforeach
    </div>
  </section>

@if ($related->isNotEmpty())
  <section aria-labelledby="related-title">
    <h2 id="related-title">Похожие приложения</h2>
    <ul class="app-grid" role="list">
@foreach ($related as $item)
      @include('seo.partials.app-card', ['app' => $item])
@endforeach
    </ul>
    <p class="app-grid__more"><a class="text-link" href="{{ $categoryUrl }}">Все приложения в категории «{{ $app->category->title }}»</a></p>
  </section>
@endif

  <p class="page-meta">{{ $name }} и его логотип — товарные знаки их правообладателя. Ru App Store не связан с разработчиком приложения.</p>
</div>
