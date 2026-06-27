<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAutomationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AiAutomationRuleController extends Controller
{
    public function index(Request $request): View
    {
        $rules = AiAutomationRule::query()
            ->when($request->filled('target_entity'), fn ($query) => $query->where('target_entity', $request->string('target_entity')))
            ->when($request->filled('mode'), fn ($query) => $query->where('mode', $request->string('mode')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.ai.data-automation.rules.index', compact('rules'));
    }

    public function create(): View
    {
        return view('admin.ai.data-automation.rules.form', [
            'rule' => new AiAutomationRule([
                'trigger_event' => 'message_extracted',
                'mode' => 'need_confirmation',
                'confidence_threshold' => 0.75,
                'is_active' => true,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['created_by'] = $request->user()->id;

        AiAutomationRule::create($data);

        return redirect()->route('admin.ai.data-automation.rules.index')->with('status', 'Automation rule berhasil dibuat.');
    }

    public function edit(AiAutomationRule $rule): View
    {
        return view('admin.ai.data-automation.rules.form', compact('rule'));
    }

    public function update(Request $request, AiAutomationRule $rule): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['updated_by'] = $request->user()->id;

        $rule->update($data);

        return redirect()->route('admin.ai.data-automation.rules.index')->with('status', 'Automation rule berhasil diperbarui.');
    }

    public function toggle(AiAutomationRule $rule): RedirectResponse
    {
        $rule->update(['is_active' => ! $rule->is_active]);

        return back()->with('status', 'Status automation rule berhasil diubah.');
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trigger_event' => ['required', 'string', 'max:100'],
            'target_entity' => ['required', Rule::in(['customer', 'booking', 'followup'])],
            'action' => ['required', 'string', 'max:100'],
            'mode' => ['required', Rule::in(['auto_create', 'need_confirmation', 'human_only'])],
            'confidence_threshold' => ['required', 'numeric', 'min:0', 'max:1'],
            'required_fields' => ['nullable', 'string'],
            'forbidden_intents' => ['nullable', 'string'],
            'condition_intents' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['required_fields'] = $this->csv($data['required_fields'] ?? '');
        $data['forbidden_intents'] = $this->csv($data['forbidden_intents'] ?? '');
        $conditionIntents = $this->csv($data['condition_intents'] ?? '');
        $data['conditions'] = $conditionIntents ? ['intents' => $conditionIntents] : null;
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        unset($data['condition_intents']);

        return $data;
    }

    private function csv(string $value): array
    {
        return collect(explode(',', $value))->map(fn ($item) => trim($item))->filter()->values()->all();
    }
}
