<?php

namespace App\Console\Commands;

use App\Services\AI\AiKnowledgeReadiness;
use Illuminate\Console\Command;

class AuditAiKnowledge extends Command
{
    protected $signature = 'ai:audit-knowledge {--json}';

    protected $description = 'Read-only coverage check of public, non-demo knowledge without exposing customer data.';

    public function handle(AiKnowledgeReadiness $audit): int
    {
        $report = $audit->report();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Layanan', 'Terbit layak', 'Demo', 'Perlu tinjauan', 'Topik belum tercakup'], collect($report['domains'])->map(fn ($row) => [
                $row['label'], $row['published_usable'], $row['demo'], $row['review_required'], implode(', ', $row['topic_gaps']),
            ])->all());
            $this->line($report['note']);
        }

        return self::SUCCESS;
    }
}
