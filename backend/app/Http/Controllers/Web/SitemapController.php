<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Seo\PublicUrls;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use XMLWriter;

/** sitemap.xml: the website pages, the catalog overview, every category and every app (with its icon). */
class SitemapController extends Controller
{
    public function __invoke(PublicUrls $urls): Response
    {
        $xml = Cache::remember('seo:sitemap', 3600, function () use ($urls) {
            $writer = new XMLWriter;
            $writer->openMemory();
            $writer->startDocument('1.0', 'UTF-8');
            $writer->startElement('urlset');
            $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $writer->writeAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');
            foreach ($urls->all() as $url) {
                $writer->startElement('url');
                $writer->writeElement('loc', $url['loc']);
                if ($url['lastmod'] !== null) {
                    $writer->writeElement('lastmod', $url['lastmod']->toAtomString());
                }
                $writer->writeElement('changefreq', $url['changefreq']);
                $writer->writeElement('priority', $url['priority']);
                if ($url['image'] !== null) {
                    $writer->startElement('image:image');
                    $writer->writeElement('image:loc', $url['image']);
                    $writer->endElement();
                }
                $writer->endElement();
            }
            $writer->endElement();
            $writer->endDocument();

            return $writer->outputMemory();
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
