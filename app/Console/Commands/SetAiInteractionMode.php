<?php

namespace App\Console\Commands;

use App\Services\AI\AiLegacyHold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetAiInteractionMode extends Command
{
    protected $signature = 'ai:interaction-mode {mode? : information_web or off}';

    protected $description = 'Inspect or change the safe AI mode and hold legacy automation without modifying transactions.';

    public function handle(AiLegacyHold $hold): int
    {
        $mode = $this->argument('mode');
        if ($mode !== null && ! in_array($mode, ['information_web', 'off'], true)) {
            $this->error('Hanya information_web atau off yang diperbolehkan.');

            return self::FAILURE;
        }
        if ($mode !== null) {
            $counts = DB::transaction(function () use ($mode, $hold) {
                $policy = DB::table('ai_interaction_policies')->where('id', 1)->lockForUpdate()->first();
                if (! $policy) {
                    throw new \RuntimeException('Jalankan migrasi kebijakan AI terlebih dahulu.');
                }
                if ($policy->mode !== $mode) {
                    DB::table('ai_interaction_policies')->where('id', 1)->update(['mode' => $mode, 'revision' => $policy->revision + 1, 'updated_at' => now()]);
                }

                return $hold->all();
            });
            $this->info(json_encode($counts, JSON_THROW_ON_ERROR));
        }
        $policy = DB::table('ai_interaction_policies')->where('id', 1)->first(['mode', 'revision']);
        $this->info(json_encode($policy, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
