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
    return false;
}
return false;
