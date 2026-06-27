<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Services\CRM\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeedbackController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function index(Request $request): View
    {
        $feedback = Feedback::query()
            ->with(['customer', 'booking.service'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('rating'), fn ($query) => $query->where('rating', $request->integer('rating')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.feedback.index', [
            'feedback' => $feedback,
            'statuses' => ['requested', 'received', 'escalated', 'responded'],
        ]);
    }

    public function respond(Feedback $feedback): RedirectResponse
    {
        $oldValues = $feedback->only(['status', 'responded_at']);
        $feedback->update([
            'status' => 'responded',
            'responded_at' => now(),
        ]);
        $this->auditLogService->log(request()->user(), 'feedback.respond', $feedback, request(), $oldValues, $feedback->only(['status', 'responded_at']), 'Feedback marked responded');

        return back()->with('status', 'Feedback ditandai sudah ditangani.');
    }
}
