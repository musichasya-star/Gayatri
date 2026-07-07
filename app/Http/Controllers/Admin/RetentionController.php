<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
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

        $search = trim((string) $request->string('q'));
        $followupStatus = trim((string) $request->string('followup_status', 'all'));
        $tag = trim((string) $request->string('tag'));

        $allowedStatuses = ['all', 'active', 'open', 'sent', 'completed', 'none'];
        if (! in_array($followupStatus, $allowedStatuses, true)) {
            $followupStatus = 'all';
        }

        $customers = $retentionService->inactiveCustomersQuery($days, $search, $followupStatus, $tag, true)
            ->paginate(15)
            ->withQueryString();

        $tagOptions = $retentionService->retentionTagOptions($days, $search, $followupStatus);

        $quickFollowups = [];
        foreach ($customers->getCollection() as $customer) {
            if ($customer instanceof Customer) {
                $quickFollowups[$customer->id] = $retentionService->generateQuickFollowupPayload(
                    $customer,
                    $days,
                    $request->user()->id,
                );
            }
        }

        return view('admin.retention.index', [
            'days' => $days,
            'search' => $search,
            'followupStatus' => $followupStatus,
            'tag' => $tag,
            'tagOptions' => $tagOptions,
            'quickFollowups' => $quickFollowups,
            'customers' => $customers,
        ]);
    }

    public function generate(Request $request, RetentionService $retentionService): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'in:30,60,90']]);
        $count = $retentionService->generateFollowups((int) $data['days'], $request->user());

        return back()->with('status', "{$count} follow-up retention berhasil dibuat.");
    }
}
