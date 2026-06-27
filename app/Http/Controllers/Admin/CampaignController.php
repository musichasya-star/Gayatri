<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Promo;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\CampaignService;
use App\Support\CampaignStatus;
use App\Support\CustomerStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(): View
    {
        return view('admin.campaigns.index', [
            'campaigns' => Campaign::with('promo')->latest()->paginate(15),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Campaign(['status' => CampaignStatus::DRAFT, 'audience_filters' => []]));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['created_by'] = $request->user()->id;
        $data['status'] = CampaignStatus::DRAFT;
        $data['audience_filters'] = $this->filters($data);
        unset($data['filter_status'], $data['filter_tag'], $data['filter_service_interest'], $data['filter_inactive_days']);

        $campaign = Campaign::create($data);
        $this->auditLogService->log($request->user(), 'campaign.create', $campaign, $request, [], $campaign->only(['name', 'status', 'promo_id', 'audience_filters']), 'Campaign draft created');

        return redirect()->route('admin.campaigns.index')->with('status', 'Campaign draft berhasil dibuat.');
    }

    public function edit(Campaign $campaign): View
    {
        return $this->form($campaign);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        if ($campaign->status !== CampaignStatus::DRAFT) {
            return back()->withErrors(['campaign' => 'Campaign yang sudah dijadwalkan tidak bisa diedit.']);
        }

        $data = $this->validatedData($request);
        $data['audience_filters'] = $this->filters($data);
        unset($data['filter_status'], $data['filter_tag'], $data['filter_service_interest'], $data['filter_inactive_days']);
        $oldValues = $campaign->only(['name', 'message_template', 'promo_id', 'audience_filters']);
        $campaign->update($data);
        $this->auditLogService->log($request->user(), 'campaign.update', $campaign, $request, $oldValues, $campaign->only(['name', 'message_template', 'promo_id', 'audience_filters']), 'Campaign draft updated');

        return redirect()->route('admin.campaigns.index')->with('status', 'Campaign draft berhasil diperbarui.');
    }

    public function schedule(Request $request, Campaign $campaign): RedirectResponse
    {
        if ($campaign->status !== CampaignStatus::APPROVED) {
            return back()->withErrors(['campaign' => 'Campaign harus disetujui owner/manager sebelum dijadwalkan.']);
        }

        $data = $request->validate(['scheduled_at' => ['nullable', 'date']]);
        $count = $this->campaignService->schedule($campaign, $data['scheduled_at'] ?? null);
        $this->auditLogService->log($request->user(), 'campaign.schedule', $campaign->refresh(), $request, [], ['scheduled_at' => $campaign->scheduled_at, 'recipient_count' => $count], 'Campaign scheduled');

        return back()->with('status', "Campaign dijadwalkan untuk {$count} recipient.");
    }

    public function approve(Request $request, Campaign $campaign): RedirectResponse
    {
        if ($campaign->status !== CampaignStatus::DRAFT) {
            return back()->withErrors(['campaign' => 'Hanya campaign draft yang bisa di-approve.']);
        }

        $campaign->update([
            'status' => CampaignStatus::APPROVED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);
        $this->auditLogService->log($request->user(), 'campaign.approve', $campaign, $request, [], ['approved_by' => $request->user()->id, 'approved_at' => $campaign->approved_at], 'Campaign approved');

        return back()->with('status', 'Campaign berhasil di-approve.');
    }

    public function cancel(Campaign $campaign): RedirectResponse
    {
        if (! in_array($campaign->status, [CampaignStatus::DRAFT, CampaignStatus::APPROVED, CampaignStatus::SCHEDULED], true)) {
            return back()->withErrors(['campaign' => 'Campaign berjalan atau selesai tidak bisa dibatalkan.']);
        }

        $campaign->update(['status' => CampaignStatus::CANCELLED]);
        $this->auditLogService->log(request()->user(), 'campaign.cancel', $campaign, request(), [], ['status' => CampaignStatus::CANCELLED], 'Campaign cancelled');

        return back()->with('status', 'Campaign berhasil dibatalkan.');
    }

    private function form(Campaign $campaign): View
    {
        return view('admin.campaigns.form', [
            'campaign' => $campaign,
            'promos' => Promo::query()->where('is_active', true)->orderBy('title')->get(),
            'statuses' => CustomerStatus::all(),
            'previewTargets' => $this->campaignService->previewTargets($campaign->audience_filters ?? []),
        ]);
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'promo_id' => ['nullable', 'exists:promos,id'],
            'name' => ['required', 'string', 'max:255'],
            'message_template' => ['required', 'string', 'max:5000'],
            'filter_status' => ['nullable', Rule::in(CustomerStatus::all())],
            'filter_tag' => ['nullable', 'string', 'max:100'],
            'filter_service_interest' => ['nullable', 'string', 'max:100'],
            'filter_inactive_days' => ['nullable', 'integer', 'in:30,60,90'],
        ]);
    }

    private function filters(array $data): array
    {
        return array_filter([
            'status' => $data['filter_status'] ?? null,
            'tag' => $data['filter_tag'] ?? null,
            'service_interest' => $data['filter_service_interest'] ?? null,
            'inactive_days' => $data['filter_inactive_days'] ?? null,
        ], fn ($value) => filled($value));
    }
}
