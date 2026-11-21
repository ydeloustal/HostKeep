<?php
declare(strict_types=1);

/**
 * Installable app (PWA): the <head> tags shared by every page, and versioned asset URLs.
 * The ?v=<version> suffix changes at each Hostkeep update, so no browser or proxy cache can keep
 * serving an old app.js / app.css; the service worker (public/sw.js) then fetches network first.
 */
function asset(string $path): string
{
    return $path . '?v=' . rawurlencode(app_version());
}

function pwa_head(string $appName): string
{
    $n = h($appName);
    return <<<HTML
<link rel="manifest" href="/assets/manifest.json">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#131720" media="(prefers-color-scheme: dark)">
<meta name="application-name" content="$n">
<meta name="apple-mobile-web-app-title" content="$n">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="format-detection" content="telephone=no">

HTML;
}
