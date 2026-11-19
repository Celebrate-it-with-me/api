<!doctype html>
<html lang="{{ str_starts_with($locale, 'en') ? 'en' : 'es' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $url }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $siteName }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:image" content="{{ $image }}">
    @if ($imageWidth && $imageHeight)
    <meta property="og:image:width" content="{{ $imageWidth }}">
    <meta property="og:image:height" content="{{ $imageHeight }}">
    @endif
    <meta property="og:image:alt" content="{{ $imageAlt }}">
    <meta property="og:locale" content="{{ $locale }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $image }}">
    <meta name="twitter:image:alt" content="{{ $imageAlt }}">

    <meta http-equiv="refresh" content="0;url={{ $url }}">
</head>
<body>
    <p><a href="{{ $url }}">{{ $title }}</a></p>
    <script>window.location.replace(@json($url));</script>
</body>
</html>
