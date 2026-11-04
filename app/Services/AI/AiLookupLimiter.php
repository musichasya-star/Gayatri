<?php

namespace App\Services\AI;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AiLookupLimiter
{
    public function consume(array $identity, ?int $sessionId): bool
    {
        $key = (string) config('app.key');
        $scopes = [
            hash_hmac('sha256', 'global', $key) => 500,
            hash_hmac('sha256', 'session:'.($sessionId ?? 'demo'), $key) => 100,
            hash_hmac('sha256', 'sender:'.$identity['phone'], $key) => 5,
        ];
        ksort($scopes);

        return DB::transaction(function () use ($scopes) {
            $rows = [];
            foreach ($scopes as $hash => $limit) {
                DB::table('ai_lookup_limits')->insertOrIgnore(['scope_hash' => $hash, 'attempts' => 0, 'window_started_at' => now()]);
                $row = DB::table('ai_lookup_limits')->where('scope_hash', $hash)->lockForUpdate()->first();
                $count = Carbon::parse($row->window_started_at)->lt(now()->subMinutes(10)) ? 0 : (int) $row->attempts;
                if ($count >= $limit) {
                    return false;
                }
                $rows[$hash] = [$row, $count];
            }
            foreach ($rows as $hash => [$row, $count]) {
                DB::table('ai_lookup_limits')->where('scope_hash', $hash)->update(['attempts' => $count + 1,
                    'window_started_at' => $count === 0 ? now() : $row->window_started_at]);
            }

            return true;
        });
    }
}
