<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Reminder;
use App\Services\CRM\ReminderService;
use App\Support\ReminderStatus;
use App\Support\ReminderType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReminderController extends Controller
{
    public function index(Request $request): View
    {
        $reminders = Reminder::query()
            ->with(['booking.service', 'customer', 'conversation'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');

                $query->where(function ($query) use ($search) {
                    $query->where('message', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('whatsapp_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('booking', function ($query) use ($search) {
                            $query->where('booking_code', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('due'), function ($query) use ($request) {
                $due = (string) $request->input('due');

                return match ($due) {
                    'due_now' => $query->whereIn('status', [ReminderStatus::PENDING, ReminderStatus::SCHEDULED])->where('scheduled_at', '<=', now()),
                    'upcoming' => $query->whereIn('status', [ReminderStatus::PENDING, ReminderStatus::SCHEDULED])->where('scheduled_at', '>', now()),
                    'failed' => $query->where('status', ReminderStatus::FAILED),
                    default => $query,
                };
            })
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('scheduled_at', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('scheduled_at', '<=', $request->string('date_to')))
            ->latest('scheduled_at')
            ->paginate(15)
            ->withQueryString();

        $baseQuery = Reminder::query();

        return view('admin.reminders.index', [
            'reminders' => $reminders,
            'statuses' => ReminderStatus::all(),
            'types' => ReminderType::labels(),
            'stats' => [
                'total' => (clone $baseQuery)->count(),
                'due_now' => (clone $baseQuery)->whereIn('status', [ReminderStatus::PENDING, ReminderStatus::SCHEDULED])->where('scheduled_at', '<=', now())->count(),
                'failed' => (clone $baseQuery)->where('status', ReminderStatus::FAILED)->count(),
                'sent_today' => (clone $baseQuery)->where('status', ReminderStatus::SENT)->whereDate('sent_at', today())->count(),
            ],
            'customers' => Customer::query()->orderBy('name')->limit(100)->get(),
            'bookings' => Booking::query()->with('customer')->latest('booking_date')->limit(100)->get(),
            'previewReminderService' => app(ReminderService::class),
        ]);
    }

    public function store(Request $request, ReminderService $reminderService): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'booking_id' => ['nullable', 'exists:bookings,id'],
            'type' => ['required', 'in:'.implode(',', ReminderType::all())],
            'scheduled_at' => ['required', 'date'],
            'message' => ['nullable', 'string', 'max:5000'],
            'channel' => ['required', 'in:whatsapp'],
        ]);

        $reminderService->createManual($data);

        return back()->with('status', 'Reminder manual berhasil dibuat.');
    }

    public function retry(Reminder $reminder, ReminderService $reminderService): RedirectResponse
    {
        $reminderService->retry($reminder);

        return back()->with('status', 'Reminder dijadwalkan ulang untuk dikirim.');
    }

    public function sendNow(Reminder $reminder, ReminderService $reminderService): RedirectResponse
    {
        $reminderService->sendNow($reminder);

        return back()->with('status', 'Reminder diproses untuk dikirim sekarang.');
    }

    public function cancel(Reminder $reminder, ReminderService $reminderService): RedirectResponse
    {
        $reminderService->cancel($reminder);

        return back()->with('status', 'Reminder berhasil dibatalkan.');
    }
}
