<title>{{ $page->title }}</title>
  <meta name="description" content="{{ $page->description }}">
  <meta name="robots" content="{{ $page->robots }}">
  <link rel="canonical" href="{{ $page->canonical }}">
  <meta property="og:type" content="{{ $page->ogType }}">
  <meta property="og:locale" content="ru_RU">
  <meta property="og:site_name" content="Ru App Store">
  <meta property="og:title" content="{{ $page->title }}">
  <meta property="og:description" content="{{ $page->description }}">
  <meta property="og:url" content="{{ $page->canonical }}">
  <meta property="og:image" content="{{ $page->image ?? config('seo.base_url').'/assets/og/ru-app-store-og.jpg' }}">
  <meta name="twitter:card" content="{{ $page->image && $page->ogType !== 'article' ? 'summary' : 'summary_large_image' }}">
@if ($page->schema !== [])
  <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@graph' => $page->schema], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
@endif
