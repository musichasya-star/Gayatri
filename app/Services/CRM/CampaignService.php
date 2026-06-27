<?php

namespace App\Services\CRM;

use App\Jobs\ProcessCampaignRecipientJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Support\CampaignStatus;

class CampaignService
{
    public function previewTargets(array $filters)
    {
        return $this->targetQuery($filters)->get();
    }

    public function schedule(Campaign $campaign, ?string $scheduledAt = null): int
    {
        $customers = $this->previewTargets($campaign->audience_filters ?? []);

        foreach ($customers as $customer) {
            CampaignRecipient::firstOrCreate(
                ['campaign_id' => $campaign->id, 'customer_id' => $customer->id],
                ['status' => 'pending']
            );
        }

        $campaign->update([
            'status' => CampaignStatus::SCHEDULED,
            'scheduled_at' => $scheduledAt ?: now(),
            'recipient_count' => $customers->count(),
        ]);

        return $customers->count();
    }

    public function dispatchDue(): int
    {
        $campaigns = Campaign::query()
            ->where('status', CampaignStatus::SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->get();

        $count = 0;
        $rateLimit = max(0, (int) config('crm.campaign_rate_limit_seconds', 10));
        foreach ($campaigns as $campaign) {
            $campaign->update(['status' => CampaignStatus::RUNNING, 'started_at' => now()]);
            foreach ($campaign->recipients()->where('status', 'pending')->get() as $recipient) {
                ProcessCampaignRecipientJob::dispatch($recipient->id)->delay(now()->addSeconds($count * $rateLimit));
                $count++;
            }
        }

        return $count;
    }

    public function markCampaignIfComplete(Campaign $campaign): void
    {
        $pending = $campaign->recipients()->whereIn('status', ['pending', 'processing'])->exists();

        if (! $pending) {
            $campaign->update([
                'status' => CampaignStatus::COMPLETED,
                'finished_at' => now(),
                'sent_count' => $campaign->recipients()->where('status', 'sent')->count(),
                'failed_count' => $campaign->recipients()->where('status', 'failed')->count(),
            ]);
        }
    }

    private function targetQuery(array $filters)
    {
        return Customer::query()
            ->whereNotIn('status', ['blacklisted', 'blocked'])
            ->whereNotNull('whatsapp_number')
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['tag']), function ($query) use ($filters) {
                $query->where('tags', 'like', '%'.$filters['tag'].'%');
            })
            ->when(! empty($filters['service_interest']), function ($query) use ($filters) {
                $interest = '%'.$filters['service_interest'].'%';
                $query->where(function ($query) use ($interest) {
                    $query->where('tags', 'like', $interest)
                        ->orWhereHas('bookings.service', function ($query) use ($interest) {
                            $query->where('name', 'like', $interest)
                                ->orWhere('category', 'like', $interest);
                        });
                });
            })
            ->when(! empty($filters['inactive_days']), function ($query) use ($filters) {
                $query->where(function ($query) use ($filters) {
                    $query->whereNull('last_booking_at')
                        ->orWhere('last_booking_at', '<=', now()->subDays((int) $filters['inactive_days']));
                });
            })
            ->orderBy('name');
    }
}
