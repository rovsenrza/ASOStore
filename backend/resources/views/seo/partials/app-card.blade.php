<li class="app-card">
  <a href="/apps/{{ $app->seo_slug }}">
@if ($app->iconUrl())
    <img src="{{ $app->iconUrl() }}" alt="" width="64" height="64" loading="lazy" decoding="async">
@else
    <span class="app-card__blank" aria-hidden="true"></span>
@endif
    <span class="app-card__text">
      <b>{{ $app->name }}</b>
@if ($app->subtitle)
      <span>{{ \Illuminate\Support\Str::limit($app->subtitle, 70) }}</span>
@endif
    </span>
  </a>
</li>
