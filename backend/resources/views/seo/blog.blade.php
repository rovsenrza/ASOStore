<section class="page-hero" aria-labelledby="page-title">
  @include('seo.partials.crumbs', ['trail' => [['Блог', null]]])
  <h1 id="page-title">Блог Ru App Store</h1>
  <p>Инструкции и честные ответы: как скачать на iPhone приложения, которых нет в App Store, и как делать это безопасно.</p>
</section>

<div class="page-body">
  <section aria-labelledby="posts-title">
    <h2 id="posts-title" class="visually-hidden">Статьи</h2>
    <ul class="post-cards" role="list">
@foreach ($posts as $post)
      @include('seo.partials.post-card', ['post' => $post, 'heading' => 'h3'])
@endforeach
    </ul>
  </section>

  @include('seo.partials.how')
</div>
