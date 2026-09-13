<?php
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

/**
 * Public HTML pages at the site root. Files stay *.html on disk; URLs are clean.
 *
 * @return list<array{file: string, slug: string, path: string, mtime: int}>
 */
function terboekt_public_pages(): array
{
    $pages = [];
    foreach (glob(__DIR__ . '/*.html') ?: [] as $full) {
        $file = basename($full);
        $slug = basename($file, '.html');
        $pages[] = [
            'file' => $file,
            'slug' => $slug,
            'path' => $slug === 'index' ? '/' : '/' . $slug,
            'mtime' => (int) filemtime($full),
        ];
    }
    usort($pages, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);
    return $pages;
}

function terboekt_public_origin(): string
{
    return 'https://hometerboekt.be';
}

/** Map a stored/admin URL onto the clean public path. */
function terboekt_clean_public_path(string $raw, string $fallback = '/voorwaarden'): string
{
    $value = trim($raw);
    if ($value === '') {
        return $fallback;
    }
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }
    $value = '/' . ltrim($value, '/');
    if (str_ends_with($value, '.html')) {
        $base = basename($value, '.html');
        return $base === 'index' ? '/' : '/' . $base;
    }
    return rtrim($value, '/') ?: '/';
}
