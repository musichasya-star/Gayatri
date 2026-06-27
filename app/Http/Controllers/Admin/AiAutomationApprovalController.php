<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAutomationApproval;
use App\Services\AI\AiAutomationApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiAutomationApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $approvals = AiAutomationApproval::query()
            ->with(['customer', 'conversation', 'extractedData'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.ai.data-automation.approvals.index', compact('approvals'));
    }

    public function show(AiAutomationApproval $approval): View
    {
        $approval->load(['customer', 'conversation', 'extractedData.message']);

        return view('admin.ai.data-automation.approvals.show', compact('approval'));
    }

    public function approve(Request $request, AiAutomationApproval $approval, AiAutomationApprovalService $approvalService): RedirectResponse
    {
        $approvalService->approve($approval, $request->user());

        return redirect()->route('admin.ai.data-automation.approvals.index')->with('status', 'Approval berhasil disetujui.');
    }

    public function editApprove(Request $request, AiAutomationApproval $approval, AiAutomationApprovalService $approvalService): RedirectResponse
    {
        $data = $request->validate([
            'booking_id' => ['nullable', 'exists:bookings,id'],
            'service_id' => ['nullable', 'exists:services,id'],
            'availability_slot_id' => ['nullable', 'exists:availability_slots,id'],
            'booking_date' => ['nullable', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
        ]);

        $proposed = $approval->proposed_data;
        foreach (['booking_id', 'service_id', 'availability_slot_id', 'booking_date', 'start_time'] as $field) {
            if (! empty($data[$field])) {
                $proposed['booking'][$field] = $field === 'start_time' ? $data[$field].':00' : $data[$field];
            }
        }

        $approvalService->approve($approval, $request->user(), $proposed);

        return redirect()->route('admin.ai.data-automation.approvals.index')->with('status', 'Approval berhasil diedit dan disetujui.');
    }

    public function reject(Request $request, AiAutomationApproval $approval, AiAutomationApprovalService $approvalService): RedirectResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $approvalService->reject($approval, $request->user(), $data['rejection_reason']);

        return redirect()->route('admin.ai.data-automation.approvals.index')->with('status', 'Approval berhasil ditolak.');
    }
}
