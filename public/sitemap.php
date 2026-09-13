<?php
declare(strict_types=1);

require_once __DIR__ . '/_pages.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: no-store');

$origin = terboekt_public_origin();
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach (terboekt_public_pages() as $page) {
    $loc = htmlspecialchars($origin . $page['path'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $lastmod = gmdate('Y-m-d', $page['mtime']);
    echo "    <url>\n";
    echo "        <loc>{$loc}</loc>\n";
    echo "        <lastmod>{$lastmod}</lastmod>\n";
    echo "    </url>\n";
}
echo "</urlset>\n";
