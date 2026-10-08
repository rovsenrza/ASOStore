<li class="post-card">
  <a href="/blog/{{ $post->slug }}">
    <img src="{{ $post->image }}" alt="{{ $post->imageAlt }}" width="1200" height="630" loading="lazy" decoding="async">
    <span class="post-card__text">
      <span class="post-card__meta"><time datetime="{{ $post->published->toDateString() }}">{{ $post->published->locale('ru')->translatedFormat('j F Y') }}</time> · {{ $post->readingMinutes() }} мин чтения</span>
      <{{ $heading }} class="post-card__title">{{ $post->title }}</{{ $heading }}>
      <span class="post-card__lead">{{ $post->description }}</span>
    </span>
  </a>
</li>
