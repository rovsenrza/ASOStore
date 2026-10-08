<nav class="cat-chips" aria-labelledby="cats-{{ $current ?? 'all' }}">
  <h2 id="cats-{{ $current ?? 'all' }}">{{ $title }}</h2>
  <ul role="list">
@foreach ($categories as $category)
@if ($category->slug !== $current)
    <li><a href="/categories/{{ $category->slug }}">{{ $category->title }} <span>{{ $category->apps_count }}</span></a></li>
@endif
@endforeach
  </ul>
</nav>
