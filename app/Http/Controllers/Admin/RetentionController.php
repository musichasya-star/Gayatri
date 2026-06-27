<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CRM\RetentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RetentionController extends Controller
{
    public function index(Request $request, RetentionService $retentionService): View
    {
        $days = (int) $request->integer('days', 30);
        if (! in_array($days, [30, 60, 90], true)) {
            $days = 30;
        }

        return view('admin.retention.index', [
            'days' => $days,
            'customers' => $retentionService->inactiveCustomers($days),
        ]);
    }

    public function generate(Request $request, RetentionService $retentionService): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'in:30,60,90']]);
        $count = $retentionService->generateFollowups((int) $data['days'], $request->user());

        return back()->with('status', "{$count} follow-up retention berhasil dibuat.");
    }
}
