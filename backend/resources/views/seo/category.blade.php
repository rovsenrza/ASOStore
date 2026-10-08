<section class="page-hero" aria-labelledby="page-title">
  @include('seo.partials.crumbs', ['trail' => [['Каталог', '/apps'], [$category->title, null]]])
  <h1 id="page-title">{{ $category->title }} для&nbsp;iPhone</h1>
  <p>{{ $category->subtitle ?: $count.' из категории «'.$category->title.'», которых нет в российском App Store. Все устанавливаются на iPhone через Ru App Store — без компьютера и джейлбрейка.' }}</p>
</section>

<div class="page-body">
  <section aria-labelledby="list-title">
    <h2 id="list-title">{{ $category->title }}: {{ $count }}</h2>
    <ul class="app-grid" role="list">
@foreach ($apps as $app)
      @include('seo.partials.app-card', ['app' => $app])
@endforeach
    </ul>
  </section>

  @include('seo.partials.categories', ['categories' => $categories, 'current' => $category->slug, 'title' => 'Другие категории'])
  @include('seo.partials.how')
</div>
