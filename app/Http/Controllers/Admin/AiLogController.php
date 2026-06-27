<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = AiLog::query()
            ->with(['persona', 'knowledgeBase'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.ai.logs.index', compact('logs'));
    }

    public function clear(): RedirectResponse
    {
        AiLog::query()->delete();

        return redirect()->route('admin.ai.logs.index')->with('status', 'AI logs berhasil dibersihkan.');
    }
}
