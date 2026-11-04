<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAiLookups extends Command
{
    protected $signature = 'ai:prune-lookups';

    protected $description = 'Remove expired encrypted lookup state and retain only 30 days of anonymous lookup attempts';

    public function handle(): int
    {
        DB::table('ai_conversation_contexts')->where('lookup_expires_at', '<', now())->update(['lookup_payload' => null, 'lookup_expires_at' => null]);
        DB::table('ai_lookup_limits')->where('window_started_at', '<', now()->subDay())->delete();
        DB::table('ai_lookup_attempts')->where('created_at', '<', now()->subDays(30))->delete();
        $this->info('Retensi pengecekan status selesai.');

        return self::SUCCESS;
    }
}
