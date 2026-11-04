<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiPersona;
use App\Services\AI\AiChatCustomization;
use App\Services\AI\AiConversationPreferences;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiService;
use App\Services\AppSettingService;
use App\Services\CRM\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AiAutoReplyController extends Controller
{
    public function __construct(
        private readonly AiChatCustomization $customization,
        private readonly AppSettingService $settings,
        private readonly AuditLogService $audit,
    ) {}

    public function index(): View
    {
        return $this->page($this->customization->settings());
    }

    public function update(Request $request): RedirectResponse
    {
        if (! app(AiInteractionPolicy::class)->allowsLegacyAutomation()) {
            $data = $this->validatedModern($request);
            DB::transaction(function () use ($request, $data) {
                $policy = DB::table('ai_interaction_policies')->where('id', 1)->lockForUpdate()->first();
                abort_unless($policy, 503);
                if ((int) $policy->revision !== $data['revision']) {
                    throw ValidationException::withMessages(['revision' => 'Pengaturan sudah berubah. Muat ulang halaman sebelum menyimpan.']);
                }
                $old = $this->customization->settings();
                $updated = array_replace($old, ['enabled' => $data['enabled'], 'service_domains' => $data['service_domains']]);
                $this->settings->set(AiChatCustomization::SETTING_KEY, json_encode($updated, JSON_THROW_ON_ERROR));
                $this->settings->set(AiConversationPreferences::KEY, json_encode($data['preferences'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
                $this->audit->log($request->user(), 'ai.auto_reply.update', null, $request,
                    ['revision' => $policy->revision], ['revision' => $policy->revision + 1, 'fields' => array_keys($data['preferences']), 'enabled' => $data['enabled']],
                    'Gaya dan panduan percakapan diperbarui; balasan lama dalam antrean perlu diperiksa ulang.');
            });

            return redirect()->route('admin.ai.auto-replies.index')->with('status', 'Pengaturan disimpan. Gaya baru berlaku pada pesan berikutnya.');
        }
        $data = $this->validated($request);
        $old = $this->customization->settings();
        DB::transaction(function () use ($data, $old, $request) {
            $this->settings->set(AiChatCustomization::SETTING_KEY, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            DB::table('ai_interaction_policies')->where('id', 1)->increment('revision');
            $this->audit->log($request->user(), 'ai.auto_reply.update', null, $request, $old, $data, 'Pengaturan balasan otomatis AI diperbarui');
        });

        return redirect()->route('admin.ai.auto-replies.index')->with('status', 'Pengaturan balasan otomatis disimpan. Berlaku untuk pesan baru berikutnya.');
    }

    public function preview(Request $request, AiService $aiService): View
    {
        $data = app(AiInteractionPolicy::class)->allowsLegacyAutomation() ? $this->validated($request) : $this->validatedModern($request);
        $sample = $request->validate([
            'preview_message' => ['required', 'string', 'max:2000'],
            'preview_customer' => ['nullable', 'string', 'max:100'],
        ]);
        $result = $aiService->preview($sample['preview_message'], $data, $sample['preview_customer'] ?? 'Pelanggan');

        return $this->page($data, $result, $sample['preview_message'], $sample['preview_customer'] ?? 'Pelanggan');
    }

    private function page(array $settings, ?array $preview = null, string $message = 'Halo', string $customer = 'Alya'): View
    {
        $settings['preferences'] ??= app(AiConversationPreferences::class)->settings();
        $settings['revision'] ??= app(AiInteractionPolicy::class)->snapshot()['revision'];

        return view('admin.ai.auto-replies', [
            'settings' => $settings,
            'tones' => AiChatCustomization::TONES,
            'activePersona' => AiPersona::where('is_active', true)->first(),
            'preview' => $preview,
            'previewMessage' => $message,
            'previewCustomer' => $customer,
        ]);
    }

    private function validatedModern(Request $request): array
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'enabled' => 'required|boolean',
            'service_domains' => 'required|array:spa,lactation,rental,laundry', 'service_domains.*' => 'required|boolean',
            'preferences' => 'required|array', 'instructions' => 'prohibited', 'rules' => 'prohibited', 'signature' => 'prohibited']);
        $old = $this->customization->settings();
        $data['enabled'] = (bool) $data['enabled'];
        $data['service_domains'] = array_map(fn ($v) => (bool) $v, array_replace($old['service_domains'], $data['service_domains']));
        abort_if(! $request->user()->hasRole(['owner', 'manager']) && ($data['enabled'] !== $old['enabled'] || $data['service_domains'] !== $old['service_domains']), 403);
        $data['preferences'] = app(AiConversationPreferences::class)->validated($data['preferences']);
        $data['revision'] = (int) $data['revision'];

        return $data;
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'assistant_name' => ['required', 'string', 'max:80'],
            'business_name' => ['required', 'string', 'max:80'],
            'salutation' => ['required', 'string', 'max:40'],
            'tone' => ['required', Rule::in(array_keys(AiChatCustomization::TONES))],
            'use_emoji' => ['required', 'boolean'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'greeting' => ['nullable', 'string', 'max:2000'],
            'thanks' => ['nullable', 'string', 'max:2000'],
            'fallback' => ['nullable', 'string', 'max:2000'],
            'error' => ['nullable', 'string', 'max:2000'],
            'signature' => ['nullable', 'string', 'max:500'],
            'rules' => ['nullable', 'array', 'max:20'],
            'service_domains' => ['nullable', 'array:spa,lactation,rental,laundry'],
            'service_domains.*' => ['required', 'boolean'],
            'rules.*.name' => ['required', 'string', 'max:100'],
            'rules.*.keywords' => ['required', 'string', 'max:500'],
            'rules.*.reply' => ['required', 'string', 'max:1500'],
            'rules.*.match_type' => ['required', Rule::in(['exact', 'contains'])],
            'rules.*.priority' => ['required', 'integer', 'min:0', 'max:100'],
            'rules.*.is_active' => ['required', 'boolean'],
        ]);
        $data['enabled'] = (bool) $data['enabled'];
        $data['use_emoji'] = (bool) $data['use_emoji'];
        $data['service_domains'] = array_map(fn ($enabled) => (bool) $enabled,
            array_replace($this->customization->settings()['service_domains'], $data['service_domains'] ?? []));
        foreach (['instructions', 'greeting', 'thanks', 'fallback', 'error', 'signature'] as $key) {
            $data[$key] = trim((string) ($data[$key] ?? ''));
        }
        $data['rules'] = array_values(array_map(function ($rule) {
            $keywords = collect(preg_split('/[,\r\n]+/u', $rule['keywords']))
                ->map(fn ($keyword) => Str::of($keyword)->lower()->squish()->toString())
                ->filter()->unique()->values()->all();
            if ($keywords === []) {
                throw ValidationException::withMessages(['rules' => 'Setiap aturan memerlukan minimal satu kata kunci.']);
            }

            return [
                'name' => trim($rule['name']), 'keywords' => $keywords, 'reply' => trim($rule['reply']),
                'match_type' => $rule['match_type'], 'priority' => (int) $rule['priority'], 'is_active' => (bool) $rule['is_active'],
            ];
        }, $data['rules'] ?? []));
        if (strlen(json_encode($data, JSON_UNESCAPED_UNICODE)) > 60000) {
            throw ValidationException::withMessages(['rules' => 'Total pengaturan terlalu panjang. Ringkas template atau aturan kata kunci.']);
        }

        return $data;
    }
}
