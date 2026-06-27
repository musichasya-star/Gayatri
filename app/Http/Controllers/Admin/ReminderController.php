<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Reminder;
use App\Services\CRM\ReminderService;
use App\Support\ReminderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReminderController extends Controller
{
    public function index(Request $request): View
    {
        $reminders = Reminder::query()
            ->with(['booking.service', 'customer'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('scheduled_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.reminders.index', [
            'reminders' => $reminders,
            'statuses' => ReminderStatus::all(),
        ]);
    }

    public function retry(Reminder $reminder, ReminderService $reminderService): RedirectResponse
    {
        $reminderService->retry($reminder);

        return back()->with('status', 'Reminder dijadwalkan ulang untuk dikirim.');
    }
}
