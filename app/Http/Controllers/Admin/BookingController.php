<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiAutomationApproval;
use App\Models\AvailabilitySlot;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Promo;
use App\Models\Service;
use App\Models\Therapist;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\BookingService;
use App\Support\BookingStatus;
use App\Support\PaymentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

class BookingController extends Controller
{
    public function __construct(
        private readonly BookingService $bookingService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(Request $request): View
    {
        $bookings = Booking::query()
            ->with(['customer', 'service', 'therapist', 'branch'])
            ->when($request->filled('date'), fn ($query) => $query->whereDate('booking_date', $request->date('date')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('booking_date')
            ->paginate(15)
            ->withQueryString();

        $bookingApprovalMap = AiAutomationApproval::query()
            ->where('status', 'pending')
            ->where('target_entity', 'booking')
            ->whereIn('action', ['create_booking_draft', 'create_booking_confirmed', 'reschedule_booking', 'cancel_booking'])
            ->latest()
            ->get()
            ->flatMap(function (AiAutomationApproval $approval) {
                $booking = $approval->proposed_data['booking'] ?? [];

                return collect([
                    $booking['booking_id'] ?? null,
                    $booking['booking_code'] ?? null,
                ])->filter()->mapWithKeys(fn ($key) => [(string) $key => $approval]);
            });

        return view('admin.bookings.index', ['bookings' => $bookings, 'statuses' => BookingStatus::all(), 'bookingApprovalMap' => $bookingApprovalMap]);
    }

    public function create(Request $request): View
    {
        return $this->form(new Booking([
            'customer_id' => $request->integer('customer_id') ?: null,
            'conversation_id' => $request->integer('conversation_id') ?: null,
            'booking_date' => now()->toDateString(),
            'start_time' => '09:00:00',
            'status' => BookingStatus::DRAFT,
            'payment_status' => PaymentStatus::UNPAID,
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $booking = $this->bookingService->create($this->validatedData($request), $request->user()->id);
            $this->auditLogService->log($request->user(), 'booking.create', $booking, $request, [], $booking->only(['customer_id', 'service_id', 'booking_date', 'start_time', 'status']), 'Booking created');
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('admin.bookings.index')->with('status', 'Booking berhasil dibuat.');
    }

    public function edit(Booking $booking): View
    {
        $booking->load('addOns');

        return $this->form($booking);
    }

    public function update(Request $request, Booking $booking): RedirectResponse
    {
        try {
            $oldValues = $booking->only(['customer_id', 'service_id', 'therapist_id', 'booking_date', 'start_time', 'status', 'payment_status']);
            $this->bookingService->update($booking, $this->validatedData($request));
            $this->auditLogService->log($request->user(), 'booking.update', $booking->refresh(), $request, $oldValues, $booking->only(['customer_id', 'service_id', 'therapist_id', 'booking_date', 'start_time', 'status', 'payment_status']), 'Booking updated');
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('admin.bookings.index')->with('status', 'Booking berhasil diperbarui.');
    }

    public function destroy(Request $request, Booking $booking): RedirectResponse
    {
        $oldValues = $booking->only(['booking_code', 'customer_id', 'service_id', 'therapist_id', 'booking_date', 'start_time', 'status', 'payment_status']);
        $this->bookingService->delete($booking);
        $this->auditLogService->log($request->user(), 'booking.delete', null, $request, $oldValues, [], 'Booking deleted');

        return redirect()->route('admin.bookings.index')->with('status', 'Booking berhasil dihapus.');
    }

    public function calendar(Request $request): View
    {
        $date = $request->date('date') ?: now();
        $bookings = Booking::query()
            ->with(['customer', 'service', 'therapist'])
            ->whereDate('booking_date', $date->toDateString())
            ->orderBy('start_time')
            ->get();

        return view('admin.bookings.calendar', compact('bookings', 'date'));
    }

    private function form(Booking $booking): View
    {
        return view('admin.bookings.form', [
            'booking' => $booking,
            'customers' => Customer::orderBy('name')->get(),
            'branches' => Branch::orderBy('name')->get(),
            'services' => Service::query()
                ->with(['activeAddOns.addonService'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'therapists' => Therapist::orderBy('name')->get(),
            'promos' => Promo::query()->where('is_active', true)->orderBy('title')->get(),
            'availabilitySlots' => AvailabilitySlot::query()
                ->with(['service', 'branch', 'therapist'])
                ->where('status', 'available')
                ->whereDate('slot_date', '>=', now()->toDateString())
                ->orderBy('slot_date')
                ->orderBy('start_time')
                ->limit(200)
                ->get(),
            'statuses' => BookingStatus::all(),
            'paymentStatuses' => PaymentStatus::all(),
        ]);
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'service_id' => ['required', 'exists:services,id'],
            'therapist_id' => ['nullable', 'exists:therapists,id'],
            'conversation_id' => ['nullable', 'exists:conversations,id'],
            'promo_id' => ['nullable', 'exists:promos,id'],
            'availability_slot_id' => ['nullable', 'exists:availability_slots,id'],
            'booking_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'status' => ['required', Rule::in(BookingStatus::all())],
            'payment_status' => ['required', Rule::in(PaymentStatus::all())],
            'notes' => ['nullable', 'string', 'max:5000'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['integer', 'exists:service_addons,id'],
        ]);
    }
}
