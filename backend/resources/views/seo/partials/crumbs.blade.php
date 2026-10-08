{{-- $trail: list of [label, url|null]; the last item is the current page. --}}
<nav class="crumbs" aria-label="Навигационная цепочка">
  <ol>
    <li><a href="/">Ru App Store</a></li>
@foreach ($trail as [$label, $url])
    <li>@if ($url && ! $loop->last)<a href="{{ $url }}">{{ $label }}</a>@else<span aria-current="page">{{ $label }}</span>@endif</li>
@endforeach
  </ol>
</nav>
