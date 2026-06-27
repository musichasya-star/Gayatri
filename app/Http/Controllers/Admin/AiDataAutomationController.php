<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAutomationApproval;
use App\Models\AiAutomationLog;
use App\Models\AiAutomationRule;
use App\Models\AiExtractedData;
use Illuminate\View\View;

class AiDataAutomationController extends Controller
{
    public function index(): View
    {
        return view('admin.ai.data-automation.index', [
            'activeRules' => AiAutomationRule::where('is_active', true)->count(),
            'extractedCount' => AiExtractedData::count(),
            'pendingApprovals' => AiAutomationApproval::where('status', 'pending')->count(),
            'guardrailHits' => AiExtractedData::whereIn('intent', ['medical', 'refund', 'complaint'])->count(),
            'latestApprovals' => AiAutomationApproval::with('customer')->latest()->limit(5)->get(),
            'latestLogs' => AiAutomationLog::latest()->limit(5)->get(),
        ]);
    }
}
