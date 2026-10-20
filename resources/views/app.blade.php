@php
    $themeColor = '#14532d';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="{{ $themeColor }}">

        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        <link rel="preconnect" href="https://tile.openstreetmap.org">
        <link rel="preconnect" href="https://server.arcgisonline.com">
        <link rel="alternate" type="application/atom+xml" title="Actualités — {{ config('seo.site_name') }}" href="{{ url('/feed.xml') }}">

        @routes
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
