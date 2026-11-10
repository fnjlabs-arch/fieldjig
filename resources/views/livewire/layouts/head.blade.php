<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
<meta name="csrf-token" content="{{ csrf_token() }}" />
<meta name="vapid-public-key" content="{{ (string) config('webpush.vapid.public_key') }}" />
<meta name="push-subscribe-url" content="{{ route('push-subscriptions.store') }}" />
<meta name="push-unsubscribe-url" content="{{ route('push-subscriptions.destroy') }}" />
@auth
    <meta name="push-authenticated" content="1" />
@endauth
<meta name="application-name" content="{{ config('app.name', 'SadSha Build') }}" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="default" />
<meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'SadSha Build') }}" />
<meta name="mobile-web-app-capable" content="yes" />
<meta name="theme-color" content="#171717" media="(prefers-color-scheme: dark)" />
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)" />
<meta name="msapplication-TileColor" content="#171717" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'SadSha Build') : config('app.name', 'SadSha Build') }}
</title>

<link rel="manifest" href="/manifest.json">
<link rel="icon" href="/favicon.ico?v=20260517-2" sizes="any">
<link rel="icon" href="/favicon.svg?v=20260517-2" type="image/svg+xml">
<link rel="icon" href="/ms-icon 1.png?v=20260517-2" type="image/png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=20260517-2">
<meta name="msapplication-TileImage" content="/icon-192.png?v=20260517-2" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance