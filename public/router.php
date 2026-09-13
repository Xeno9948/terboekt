<?php
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($uri === '/admin') {
    header('Location: /admin/', true, 302);
    return true;
}
if ($uri === '/admin/') {
    require __DIR__ . '/admin/index.php';
    return true;
}
if ($uri === '/sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    return true;
}
if ($uri === '/calendar/unavailable.ics' || str_starts_with($uri, '/api')) {
    require __DIR__ . '/api/index.php';
    return true;
}
if (preg_match('#^/([a-z0-9-]+)\.html$#', $uri, $match) === 1) {
    $html = __DIR__ . '/' . $match[1] . '.html';
    if (is_file($html)) {
        $target = $match[1] === 'index' ? '/' : '/' . $match[1];
        header('Location: ' . $target, true, 301);
        return true;
    }
}
if (preg_match('#^/([a-z0-9-]+)/?$#', $uri, $match) === 1) {
    $html = __DIR__ . '/' . $match[1] . '.html';
    if (is_file($html)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($html);
        return true;
    }
}
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $types = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'mjs' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'webp' => 'image/webp',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
    ];
    $publicAsset = preg_match('#^/(assets|components)/#', $uri) === 1;
    if ($publicAsset && isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
        $cache = $ext === 'json' ? 'public, max-age=60' : 'public, max-age=31536000, immutable';
        header('Cache-Control: ' . $cache);
        readfile($file);
        return true;
    }
    return false;
}
return false;
