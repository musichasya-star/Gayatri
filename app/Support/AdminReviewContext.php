<?php

namespace App\Support;

use App\Models\LaundryTariff;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class AdminReviewContext
{
    private const ROUTES = ['knowledge' => 'admin.ai.knowledge.index', 'ai-logs' => 'admin.ai.logs.index', 'customers' => 'admin.customers.index', 'users' => 'admin.users.index', 'services' => 'admin.services.index', 'rental-products' => 'admin.rentals.products.index', 'laundry-tariffs' => 'admin.laundry.tariffs.index', 'retail' => 'admin.retail.index'];

    private static function rules(string $module): array
    {
        $common = ['page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', $module === 'laundry-tariffs' ? 'max:100' : 'max:150']];

        return $common + match ($module) {
            'services' => ['service_group' => ['nullable', Rule::in(array_keys(ServiceGroup::LABELS))], 'category' => ['nullable', 'string', 'max:255'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'active' => ['nullable', 'boolean'], 'kind' => ['nullable', Rule::in(array_keys(Service::KINDS))], 'readiness' => ['nullable', Rule::in(array_keys(Service::READINESS))], 'family' => ['nullable', 'string', 'max:255']],
            'rental-products' => ['category' => ['nullable', 'string', 'max:100'], 'active' => ['nullable', 'boolean'], 'readiness' => ['nullable', 'in:ready,incomplete,empty,busy']],
            'laundry-tariffs' => ['audience' => ['nullable', Rule::in(array_keys(LaundryTariff::AUDIENCES))], 'unit' => ['nullable', Rule::in(array_keys(LaundryTariff::UNITS))], 'active' => ['nullable', 'boolean'], 'readiness' => ['nullable', 'in:ready,admin,incomplete']],
            'retail' => ['category' => ['nullable', 'string', 'max:150'], 'active' => ['nullable', 'boolean']],
            'knowledge' => ['domain' => ['nullable', Rule::in(array_keys(AiDomain::LABELS))], 'publication_status' => ['nullable', 'in:draft,needs_review,published,archived'], 'status' => ['nullable', 'in:active,inactive'], 'demo' => ['nullable', 'in:real,demo']],
            'customers' => ['status' => ['nullable', Rule::in(CustomerStatus::all())], 'tag' => ['nullable', 'string', 'max:100']],
            'users' => ['role' => ['nullable', Rule::in(UserRole::all())], 'status' => ['nullable', Rule::in(UserStatus::all())]],
            'ai-logs' => ['domain' => ['nullable', Rule::in(array_keys(AiDomain::LABELS))], 'intent' => ['nullable', 'string', 'max:40'], 'reason_code' => ['nullable', 'string', 'max:80'], 'message_id' => ['nullable', 'integer', 'min:1'], 'conversation_id' => ['nullable', 'integer', 'min:1'], 'delivery' => ['nullable', 'string', 'max:40'], 'mode' => ['nullable', 'in:live,simulation,operations_demo'], 'status' => ['nullable', 'string', 'max:30']],
        };
    }

    public static function carry(Request $request, string $module): array
    {
        $input = $request->routeIs(self::ROUTES[$module]) ? $request->query() : ($request->input('_list') === $module ? $request->input('_filters', []) : []);
        if (! is_array($input)) {
            return [];
        }
        $validator = Validator::make($input, self::rules($module));
        if ($validator->fails()) {
            return [];
        }
        $filters = array_filter($validator->validated(), fn ($value) => $value !== null && $value !== '');
        // An explicitly empty publication filter means "all", not the default "published".
        if (array_key_exists('active', self::rules($module)) && array_key_exists('active', $input) && ($input['active'] === null || $input['active'] === '')) {
            $filters['active'] = '';
        }

        return $filters ? ['_list' => $module, '_filters' => $filters] : [];
    }

    public static function back(Request $request, string $module, array $defaults = []): string
    {
        return route(self::ROUTES[$module], array_replace($defaults, self::carry($request, $module)['_filters'] ?? []));
    }
}
