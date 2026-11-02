<?php

namespace App\Console\Commands;

use App\Models\Request as ModelsRequest;
use App\Services\RequestWorkflowService;
use Illuminate\Console\Command;

/**
 * Membatalkan permintaan yang tidak diputuskan Kepala Unit dalam batas
 * waktunya, supaya stok yang ditahannya kembali tersedia bagi permintaan
 * lain. Aman dijalankan berkali-kali: permintaan yang sudah dibatalkan tidak
 * lagi berstatus Terkirim.
 */
class ExpireStaleRequests extends Command
{
    protected $signature = 'permintaan:kedaluwarsa';

    protected $description = 'Membatalkan permintaan yang tidak diputuskan Kepala Unit dalam batas waktunya';

    public function handle(RequestWorkflowService $workflow): int
    {
        $dibatalkan = $workflow->expireStale();

        $this->info(sprintf(
            'Permintaan yang dibatalkan karena lebih dari %d hari tidak diputuskan: %d',
            ModelsRequest::BATAS_HARI_PERSETUJUAN,
            $dibatalkan,
        ));

        return self::SUCCESS;
    }
}
