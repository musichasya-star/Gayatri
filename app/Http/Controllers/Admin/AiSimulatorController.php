<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiPersona;
use App\Services\AI\AiAuditPrivacy;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AiSimulatorController extends Controller
{
    public function index(): View
    {
        return view('admin.ai.simulator', [
            'personas' => AiPersona::orderByDesc('is_active')->orderBy('name')->get(),
            'result' => null,
            'demoProfiles' => $this->profiles(),
            'history' => request()->session()->get('ai.simulator_history', []),
            'includeDemo' => (bool) data_get(request()->session()->get('ai.simulator_context', []), 'include_demo', false),
            'demoProfileId' => (int) data_get(request()->session()->get('ai.simulator_context', []), 'demo_profile_id', 0),
            'simulationHandoff' => (bool) data_get(request()->session()->get('ai.simulator_context', []), 'human_handled', false),
        ]);
    }

    public function test(Request $request, AiService $aiService): View
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'persona_id' => ['nullable', 'exists:ai_personas,id'],
            'reset_context' => ['nullable', 'boolean'],
            'include_demo' => ['nullable', 'boolean'],
            'demo_profile_id' => ['nullable', 'integer', 'exists:ai_demo_profiles,id'],
            'resume_context' => ['nullable', 'boolean'],
        ]);

        $persona = isset($data['persona_id']) ? AiPersona::find($data['persona_id']) : null;
        $context = $request->boolean('reset_context') ? [] : $request->session()->get('ai.simulator_context', []);
        $history = $request->boolean('reset_context') ? [] : $request->session()->get('ai.simulator_history', []);
        $includeDemo = $request->boolean('include_demo');
        abort_if($includeDemo && ! app()->environment('local', 'baileys-local', 'testing'), 403);
        $profileId = $includeDemo ? (int) ($data['demo_profile_id'] ?? 0) : 0;
        if (($context['demo_profile_id'] ?? 0) !== $profileId || ($context['expires_at'] ?? PHP_INT_MAX) < now()->timestamp) {
            $context = [];
            $history = [];
        }
        if ($request->boolean('resume_context')) {
            $context = [];
        }
        if (isset($context['lookup_encrypted'])) {
            try {
                $context['lookup'] = json_decode(Crypt::decryptString($context['lookup_encrypted']), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                unset($context['lookup']);
            }
        }
        $context['demo_profile_id'] = $profileId;
        $context['include_demo'] = $includeDemo;
        if (($context['human_handled'] ?? false) && ! app(AiInteractionPolicy::class)->allowsLegacyAutomation()) {
            $result = ['reply' => 'Simulasi sedang ditangani admin. Pilih “Lanjutkan AI pada simulator” atau mulai percakapan baru untuk menguji pesan berikutnya.',
                'status' => 'blocked', 'confidence' => 0, 'fallback_reason' => 'human_takeover', 'sources' => [], 'log_id' => null,
                'routing' => ['domain' => null, 'intent' => 'human_handoff', 'reason_code' => 'human_takeover']];
        } else {
            $result = $aiService->simulate($data['message'], $persona, $context);
        }
        if ($next = $result['next_context'] ?? null) {
            $request->session()->put('ai.simulator_context', [
                'domain' => $next['domain'], 'menu' => $next['menu'],
                'revision' => $next['expected_revision'] + 1, 'expires_at' => now()->addMinutes(30)->timestamp,
                'demo_profile_id' => $profileId,
                'include_demo' => $includeDemo,
                'clarification_count' => (int) ($next['clarification_count'] ?? 0),
                'catalog_item_id' => $next['catalog_item_id'] ?? null,
                'human_handled' => $result['status'] === 'escalated',
                'lookup_encrypted' => empty($next['lookup']) ? null : Crypt::encryptString(json_encode($next['lookup'], JSON_THROW_ON_ERROR)),
            ]);
        }
        $privacy = app(AiAuditPrivacy::class);
        $history[] = ['question' => $privacy->text($data['message']), 'answer' => $privacy->text($result['reply']),
            'domain' => $result['routing']['domain'] ?? null, 'reason' => $result['routing']['reason_code'] ?? null,
            'status' => $result['status'], 'log_id' => $result['log_id']];
        $history = array_slice($history, -10);
        $request->session()->put('ai.simulator_history', $history);

        return view('admin.ai.simulator', [
            'personas' => AiPersona::orderByDesc('is_active')->orderBy('name')->get(),
            'result' => $result,
            'message' => $data['message'],
            'personaId' => $data['persona_id'] ?? null,
            'includeDemo' => $includeDemo,
            'demoProfileId' => $profileId,
            'demoProfiles' => $this->profiles(),
            'history' => $history,
            'simulationHandoff' => (bool) data_get($request->session()->get('ai.simulator_context', []), 'human_handled', false),
        ]);
    }

    private function profiles()
    {
        return app()->environment('local', 'baileys-local', 'testing') ? DB::table('ai_demo_profiles')->orderBy('id')->get()
            : collect();
    }
}
