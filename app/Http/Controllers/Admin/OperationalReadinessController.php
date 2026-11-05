<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CRM\OperationalReadiness;

class OperationalReadinessController extends Controller
{
    public function index(OperationalReadiness $readiness)
    {
        return view('admin.readiness', ['report' => $readiness->report()]);
    }

    public function export(OperationalReadiness $readiness)
    {
        return response()->streamDownload(function () use ($readiness) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Modul', 'Kode', 'Nama', 'Perlu dilengkapi', 'Halaman admin'], ';', '"', '');
            foreach ($readiness->missingRows() as $row) {
                $safe = array_map(fn ($value) => preg_match('/^[\s\x00-\x1f]*[=+@-]/u', (string) $value) ? "'".$value : $value, $row);
                fputcsv($out, $safe, ';', '"', '');
            }
            fclose($out);
        }, 'kelengkapan-operasional-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
