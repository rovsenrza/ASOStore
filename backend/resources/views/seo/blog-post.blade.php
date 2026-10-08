<section class="page-hero post-hero" aria-labelledby="page-title">
  @include('seo.partials.crumbs', ['trail' => [['Блог', '/blog'], [$post->title, null]]])
  <h1 id="page-title">{{ $post->title }}</h1>
  <p>{{ $post->description }}</p>
  <p class="post-meta">
    <time datetime="{{ $post->published->toDateString() }}">{{ $post->published->locale('ru')->translatedFormat('j F Y') }}</time>
@if (! $post->updated->isSameDay($post->published))
    · обновлено <time datetime="{{ $post->updated->toDateString() }}">{{ $post->updated->locale('ru')->translatedFormat('j F Y') }}</time>
@endif
    · {{ $post->readingMinutes() }} мин чтения · Редакция Ru App Store
  </p>
</section>

<div class="page-body post-body">
  <figure class="post-cover">
    <img src="{{ $post->image }}" alt="{{ $post->imageAlt }}" width="1200" height="630" fetchpriority="high">
  </figure>

@if (count($post->toc) >= 3)
  <nav class="post-toc" aria-labelledby="toc-title">
    <h2 id="toc-title">Содержание</h2>
    <ol>
@foreach ($post->toc as $item)
      <li><a href="#{{ $item['id'] }}">{{ $item['title'] }}</a></li>
@endforeach
    </ol>
  </nav>
@endif

  <article class="prose post-article">
{!! $post->html !!}
  </article>

@if ($apps->isNotEmpty())
  <section aria-labelledby="apps-title">
    <h2 id="apps-title">Приложения из статьи</h2>
    <ul class="app-grid" role="list">
@foreach ($apps as $app)
      @include('seo.partials.app-card', ['app' => $app])
@endforeach
    </ul>
  </section>
@endif

  @include('seo.partials.how')

@if ($others->isNotEmpty())
  <section aria-labelledby="more-title">
    <h2 id="more-title">Ещё по теме</h2>
    <ul class="post-cards" role="list">
@foreach ($others as $other)
      @include('seo.partials.post-card', ['post' => $other, 'heading' => 'h3'])
@endforeach
    </ul>
  </section>
@endif
</div>
