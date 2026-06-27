<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiPersona;
use App\Services\AI\AiService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiSimulatorController extends Controller
{
    public function index(): View
    {
        return view('admin.ai.simulator', [
            'personas' => AiPersona::orderByDesc('is_active')->orderBy('name')->get(),
            'result' => null,
        ]);
    }

    public function test(Request $request, AiService $aiService): View
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'persona_id' => ['nullable', 'exists:ai_personas,id'],
        ]);

        $persona = isset($data['persona_id']) ? AiPersona::find($data['persona_id']) : null;

        return view('admin.ai.simulator', [
            'personas' => AiPersona::orderByDesc('is_active')->orderBy('name')->get(),
            'result' => $aiService->simulate($data['message'], $persona),
            'message' => $data['message'],
            'personaId' => $data['persona_id'] ?? null,
        ]);
    }
}
