<?php

namespace App\Console\Commands;

use App\Services\AI\AiDecisionRetention;
use Illuminate\Console\Command;

class PruneAiDecisions extends Command
{
    protected $signature = 'ai:prune-decisions {--apply : Apply 90-day retention instead of only reporting the count}';

    protected $description = 'Retain active or uncertain deliveries and remove only expired completed AI decisions';

    public function handle(): int
    {
        $service = app(AiDecisionRetention::class);
        $count = $this->option('apply') ? $service->prune() : $service->query()->count();
        $this->info(($this->option('apply') ? 'Log dibersihkan: ' : 'Pratinjau log selesai >90 hari: ').$count);

        return self::SUCCESS;
    }
}
