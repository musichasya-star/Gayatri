<?php

namespace App\Http\Controllers;

use App\Models\LandingPageSetting;
use App\Models\RentalProduct;
use App\Services\Web\PublicSeo;
use Illuminate\Http\Response;

final class PublicDiscoveryController extends Controller
{
    public function robots(): Response
    {
        return response("User-agent: *\nDisallow:\nSitemap: ".PublicSeo::absolute(route('public.sitemap', [], false))."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function sitemap(): Response
    {
        $urls = [];
        if (LandingPageSetting::where('slug', 'home')->where('is_published', true)->exists()) {
            $urls[] = PublicSeo::absolute(route('landing.show', [], false));
        }
        foreach (array_keys(PublicSeo::CATALOGUES) as $catalogue) {
            $urls[] = PublicSeo::catalogueUrl($catalogue);
        }
        $urls[] = PublicSeo::absolute(route('rental.index', [], false));
        $products = RentalProduct::where('is_active', true)->whereRaw('UPPER(TRIM(name)) NOT LIKE ?', ['[DEMO]%'])->orderBy('id')->limit(50000 - count($urls))->pluck('id');
        foreach ($products as $id) {
            $urls[] = PublicSeo::absolute(route('rental.show', ['product' => $id], false));
        }
        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, 'urlset', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        foreach ($urls as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', $url);
            $xml->endElement();
        }
        $xml->endDocument();

        return response($xml->outputMemory(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
