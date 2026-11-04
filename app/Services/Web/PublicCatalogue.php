<?php

namespace App\Services\Web;

use App\Http\Controllers\CatalogueController;
use App\Models\Branch;
use App\Models\LandingPageSetting;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;

/** Read-only choices and internal navigation for public service pages. */
final class PublicCatalogue
{
    public static function branches(): Builder
    {
        // Hide the unchanged starter outlet as well as explicitly marked demo records.
        $starter = Branch::where('code', 'GAY-JKT')->where('name', 'Gayatri Mom & Baby SPA Jakarta')
            ->where('address', 'Jl. Mawar No. 10, Jakarta Selatan')->where('phone', '0215550123')->select('id');

        return Branch::where('status', 'active')->where(fn ($q) => $q->whereNull('code')->orWhere('code', 'not like', 'DEMO%'))
            ->whereRaw('UPPER(TRIM(name)) NOT LIKE ?', ['[DEMO]%'])->whereNotIn('id', $starter);
    }

    public static function services(): Builder
    {
        return Service::where('is_active', true)->whereRaw('UPPER(TRIM(name)) NOT LIKE ?', ['[DEMO]%'])
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', self::branches()->select('id')));
    }

    public static function ready(Service $service): bool
    {
        return ! $service->requiresManualBooking() && $service->duration_minutes > 0 && $service->price >= 0;
    }

    public static function bookableServices(): Builder
    {
        return self::services()->bookable()->where('duration_minutes', '>', 0)->where('price', '>=', 0);
    }

    public static function familyId(Service $service): string
    {
        return 'family-'.sha1($service->service_group.'|'.$service->familyName());
    }

    public static function serviceUrl(Service $service, array $filters): string
    {
        if (! self::ready($service)) {
            return CatalogueController::inquiry($service->name);
        }
        $return = route('catalogue.index', array_replace($filters, ['selected_service' => $service->id]), false).'#'.self::familyId($service);

        return route('landing.booking-page', ['service_group' => $service->service_group, 'service_id' => $service->id, 'return_to' => $return]).'#booking-form';
    }

    public static function backUrl(mixed $value, string $fallback): string
    {
        if (! is_string($value) || strlen($value) > 2500 || preg_match('/[\x00-\x20\\\\]/', $value)) {
            return $fallback;
        }
        $parts = parse_url($value);
        if (! $parts || isset($parts['host']) || isset($parts['scheme']) || ! in_array($parts['path'] ?? '', ['/layanan', '/rental'], true)) {
            return $fallback;
        }
        parse_str($parts['query'] ?? '', $query);
        $query = array_intersect_key($query, array_flip(['type', 'group', 'category', 'search', 'kind', 'readiness', 'page', 'selected_service', 'start_date', 'end_date']));
        foreach ($query as $item) {
            if (! is_string($item) || mb_strlen($item) > 150) {
                return $fallback;
            }
        }
        $fragment = $parts['fragment'] ?? '';
        if ($fragment && ! preg_match('/^(family-[a-f0-9]{40}|product-\d+)$/D', $fragment)) {
            return $fallback;
        }

        return url($parts['path']).($query ? '?'.http_build_query($query) : '').($fragment ? '#'.$fragment : '');
    }

    public function contact(): array
    {
        $key = self::class.'.contact';
        if (! request()->attributes->has($key)) {
            $page = LandingPageSetting::where('slug', 'home')->where('is_published', true)
                ->with(['sections' => fn ($q) => $q->where('is_active', true)->whereIn('type', ['location_contact', 'footer'])])->first();
            request()->attributes->set($key, app(LandingPresentation::class)->contact($page));
        }

        return request()->attributes->get($key);
    }
}
