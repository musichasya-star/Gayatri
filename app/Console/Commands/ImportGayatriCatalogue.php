<?php

namespace App\Console\Commands;

use App\Services\CRM\CatalogueImport;
use Illuminate\Console\Command;

class ImportGayatriCatalogue extends Command
{
    protected $signature = 'catalogue:import-gayatri {--apply} {--archive-seed-examples}';

    protected $description = 'Preview/import the reviewed Gayatri catalogue locally, without inventing duration, stock, or deposit.';

    public function handle(CatalogueImport $import): int
    {
        $manifest = $import->manifest();
        $summary = collect(['services', 'rentals', 'retail', 'laundry'])->mapWithKeys(fn ($key) => [$key => count($manifest[$key])])->all();
        if ($this->option('apply')) {
            $summary = $import->apply((bool) $this->option('archive-seed-examples'));
        }
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
