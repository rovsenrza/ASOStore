@php
    use App\Http\Controllers\Web\CatalogPageController as Page;
@endphp
<section class="page-hero" aria-labelledby="page-title">
  @include('seo.partials.crumbs', ['trail' => [['Каталог', null]]])
  <h1 id="page-title">Каталог приложений для&nbsp;iPhone</h1>
  <p>{{ $total }} {{ Page::plural($total, 'приложение', 'приложения', 'приложений') }}, которых нет в российском App Store: банки, маркетплейсы, соцсети, игры и сервисы. Все устанавливаются на iPhone через Ru App Store.</p>
</section>

<div class="page-body">
  @include('seo.partials.categories', ['categories' => $categories, 'current' => null, 'title' => 'Категории'])

@foreach ($categories as $category)
@php($list = $apps->get($category->id, collect()))
  <section aria-labelledby="cat-{{ $category->slug }}">
    <h2 id="cat-{{ $category->slug }}"><a href="/categories/{{ $category->slug }}">{{ $category->title }}</a></h2>
    <ul class="app-grid" role="list">
@foreach ($list->take($perCategory) as $app)
      @include('seo.partials.app-card', ['app' => $app])
@endforeach
    </ul>
@if ($list->count() > $perCategory)
    <p class="app-grid__more"><a class="text-link" href="/categories/{{ $category->slug }}">Все {{ $list->count() }} {{ Page::plural($list->count(), 'приложение', 'приложения', 'приложений') }} в категории «{{ $category->title }}»</a></p>
@endif
  </section>
@endforeach

  @include('seo.partials.how')
</div>
