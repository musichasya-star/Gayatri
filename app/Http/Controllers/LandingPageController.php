<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\LandingPageSetting;
use App\Models\Service;
use App\Services\CRM\BookingService;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use App\Support\PaymentStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class LandingPageController extends Controller
{
    public function show(Request $request): View
    {
        return $this->renderLanding($request->boolean('preview'));
    }

    public function bookingPage(Request $request): View
    {
        return view('landing.booking', [
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
            'booking' => $this->pendingSessionBooking($request),
        ]);
    }

    public function booking(Request $request, BookingService $bookingService): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'baby_name' => ['nullable', 'string', 'max:150'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'service_id' => ['required', 'exists:services,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $phone = preg_replace('/\D+/', '', $data['phone']);
        if (is_string($phone) && str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }

        $customer = Customer::updateOrCreate(
            ['whatsapp_number' => $phone ?: $data['phone']],
            [
                'branch_id' => $data['branch_id'] ?: null,
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'baby_name' => $data['baby_name'] ?? null,
                'status' => CustomerStatus::LEAD,
                'last_interaction_at' => now(),
                'notes' => trim('Lead dari landing page booking online.'.PHP_EOL.($data['notes'] ?? '')),
            ]
        );

        try {
            $booking = $bookingService->create([
                'customer_id' => $customer->id,
                'branch_id' => $data['branch_id'] ?: null,
                'service_id' => $data['service_id'],
                'booking_date' => $data['booking_date'],
                'start_time' => $data['start_time'],
                'status' => BookingStatus::PENDING_CONFIRMATION,
                'payment_status' => PaymentStatus::UNPAID,
                'source' => 'landing_page',
                'notes' => $data['notes'] ?? null,
            ], null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()])->withInput();
        }

        $request->session()->put('landing_booking_id', $booking->id);

        return redirect()->route('landing.booking-page')->with('booking_status', 'Request booking diterima. Admin Gayatri akan konfirmasi via WhatsApp. Selama belum dikonfirmasi, Bunda masih bisa mengubah jadwal di halaman ini.');
    }

    public function reschedule(Request $request, BookingService $bookingService): RedirectResponse
    {
        $booking = $this->pendingSessionBooking($request);

        if (! $booking || $booking->status !== BookingStatus::PENDING_CONFIRMATION) {
            return redirect()->route('landing.booking-page')->withErrors(['booking' => 'Request booking tidak ditemukan atau sudah dikonfirmasi admin. Silakan buat request baru jika perlu.']);
        }

        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'service_id' => ['required', 'exists:services,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $bookingService->update($booking, [
                'customer_id' => $booking->customer_id,
                'branch_id' => $data['branch_id'] ?: null,
                'service_id' => $data['service_id'],
                'therapist_id' => $booking->therapist_id,
                'promo_id' => $booking->promo_id,
                'availability_slot_id' => null,
                'booking_date' => $data['booking_date'],
                'start_time' => $data['start_time'],
                'status' => BookingStatus::PENDING_CONFIRMATION,
                'payment_status' => $booking->payment_status,
                'notes' => trim(($booking->notes ? $booking->notes.PHP_EOL : '').'Perubahan jadwal dari landing page: '.($data['notes'] ?? '-')),
            ]);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['booking' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('landing.booking-page')->with('booking_status', 'Jadwal berhasil diperbarui. Admin akan melihat perubahan terbaru dan mengonfirmasi via WhatsApp.');
    }

    public function renderLanding(bool $includeDraft = false): View
    {
        $page = LandingPageSetting::query()
            ->with(['sections' => fn ($query) => $query->when(! $includeDraft, fn ($inner) => $inner->where('is_active', true)), 'media' => fn ($query) => $query->where('is_active', true)])
            ->where('slug', 'home')
            ->when(! $includeDraft, fn ($query) => $query->where('is_published', true))
            ->firstOrFail();

        return view('landing.show', [
            'page' => $page,
            'sections' => $page->sections,
            'services' => Service::query()->where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::query()->orderBy('name')->get(),
        ]);
    }

    private function pendingSessionBooking(Request $request): ?Booking
    {
        $bookingId = $request->session()->get('landing_booking_id');
        if (! $bookingId) {
            return null;
        }

        $booking = Booking::query()
            ->with(['customer', 'service', 'branch'])
            ->whereKey($bookingId)
            ->first();

        if (! $booking || $booking->status !== BookingStatus::PENDING_CONFIRMATION) {
            return $booking;
        }

        return $booking;
    }
}
