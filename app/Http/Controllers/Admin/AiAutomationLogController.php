<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAutomationLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiAutomationLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = AiAutomationLog::query()
            ->with(['rule'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('target_entity'), fn ($query) => $query->where('target_entity', $request->string('target_entity')))
            ->when($request->filled('action'), fn ($query) => $query->where('action', 'like', '%'.$request->string('action').'%'))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.ai.data-automation.logs.index', compact('logs'));
    }

    public function show(AiAutomationLog $log): View
    {
        $log->load(['rule']);

        return view('admin.ai.data-automation.logs.show', compact('log'));
    }
}
