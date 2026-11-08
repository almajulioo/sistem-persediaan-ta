<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\ItemApplicationStatus;
use App\Enums\ItemProposalStatus;
use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use App\Models\ItemApplication;
use App\Models\ItemApplicationDetail;
use App\Models\ItemProposal;
use App\Models\ProcurementRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use App\Support\ProcurementDraftScanner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keputusan Pengelola Gudang atas permohonan barang baru / tambah stok.
 *
 * Menyetujui meneruskan setiap baris ke jalur yang sudah ada, bukan membuat
 * jalur baru: baris tambah stok masuk ke draf usulan pengadaan (ADR 0027)
 * bersumber permohonan pengguna, dan baris barang baru menjadi usulan barang baru yang
 * tetap diputuskan lewat satu jalur `items.store` (ADR 0032/0033).
 */
class ItemApplicationWorkflowService
{
    /**
     * @throws AuthorizationException
     */
    public function approve(ItemApplication $application, User $actor): ItemApplication
    {
        /** @var Collection<int, ItemProposal> $proposals */
        $proposals = DB::transaction(function () use ($application, $actor): Collection {
            $this->lockUndecided($application);

            $application->load('details');
            $stockAdditions = $application->details->filter(fn (ItemApplicationDetail $detail) => $detail->isTambahStok());

            if ($stockAdditions->isNotEmpty()) {
                $this->addToProcurementDraft($stockAdditions);
            }

            $proposals = $application->details
                ->filter(fn (ItemApplicationDetail $detail) => $detail->isBarangBaru())
                ->map(function (ItemApplicationDetail $detail) use ($application): ItemProposal {
                    // Pengusulnya pemohon, bukan Pengelola Gudang: dialah yang
                    // menunggu kabar barangnya didaftarkan atau ditolak.
                    $proposal = ItemProposal::create([
                        'item_name' => $detail->item_name,
                        'unit' => $detail->unit,
                        'purpose_note' => $detail->note,
                        'proposed_by' => $application->user_id,
                        'status' => ItemProposalStatus::Diajukan,
                    ]);

                    $detail->update(['item_proposal_id' => $proposal->item_proposal_id]);

                    return $proposal;
                })
                ->values();

            $application->forceFill([
                'status' => ItemApplicationStatus::Disetujui,
                'decided_by' => $actor->user_id,
                'decided_at' => now(),
            ])->save();

            return $proposals;
        });

        $application = $application->fresh(['user', 'details']);
        $stockCount = $application->details->filter->isTambahStok()->count();

        AuditLogger::record(
            user: $actor,
            activity: sprintf(
                'Menyetujui permohonan %s: %d barang diteruskan ke draf usulan pengadaan, %d usulan barang baru dibuat',
                $application->application_number,
                $stockCount,
                $proposals->count(),
            ),
            module: AuditModule::Permohonan,
            event: AuditEvent::Setuju,
            subject: $application,
        );

        if ($stockCount > 0) {
            ProcurementDraftScanner::sinkronkanNotifikasi();
        }

        $proposals->each(fn (ItemProposal $proposal) => NotificationScanner::periksaUsulanBarang($proposal->fresh('proposedBy')));
        NotificationScanner::catatPermohonanDiputuskan($application, $actor);

        return $application;
    }

    /**
     * @throws AuthorizationException
     */
    public function reject(ItemApplication $application, User $actor, string $note): ItemApplication
    {
        DB::transaction(function () use ($application, $actor, $note): void {
            $this->lockUndecided($application);

            $application->forceFill([
                'status' => ItemApplicationStatus::Ditolak,
                'decided_by' => $actor->user_id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();
        });

        $application = $application->fresh('user');

        AuditLogger::record(
            user: $actor,
            activity: "Menolak permohonan {$application->application_number}. Alasan: {$note}",
            module: AuditModule::Permohonan,
            event: AuditEvent::Tolak,
            subject: $application,
        );
        NotificationScanner::catatPermohonanDiputuskan($application, $actor);

        return $application;
    }

    /**
     * Kunci baris permohonan supaya dua keputusan yang dikirim bersamaan
     * tidak sama-sama lolos dan meneruskan barisnya dua kali.
     *
     * @throws AuthorizationException
     */
    private function lockUndecided(ItemApplication $application): void
    {
        $locked = ItemApplication::query()->lockForUpdate()->findOrFail($application->getKey());

        if (! $locked->isDiajukan()) {
            throw new AuthorizationException('Permohonan ini sudah diputuskan sebelumnya.');
        }
    }

    /**
     * Tambahkan jumlah yang dimohon ke draf usulan pengadaan tunggal.
     *
     * Barisnya ditandai `is_auto = false` supaya pemindaian stok minimum tidak
     * menimpa atau membuangnya (ADR 0027). Bila barang yang sama sudah ada di
     * draf, jumlahnya dijumlahkan, bukan diganti.
     *
     * @param  Collection<int, ItemApplicationDetail>  $details
     */
    private function addToProcurementDraft(Collection $details): void
    {
        $draft = ProcurementRequest::query()->draf()->lockForUpdate()->first();

        $draft ??= ProcurementRequest::create([
            'user_id' => null,
            'status' => ProcurementRequestStatus::Draf,
        ]);

        foreach ($details as $detail) {
            $line = $draft->details()->where('item_id', $detail->item_id)->first();

            // Sumber disimpan per barang (ADR 0044). Baris yang sudah ada ikut
            // menjadi permohonan: kebutuhan pengguna yang tertaut dokumen
            // adalah alasan terkuat, dan barisnya lepas dari pemindaian.
            if ($line === null) {
                $draft->details()->create([
                    'item_id' => $detail->item_id,
                    'source' => ProcurementRequestSource::DariPermohonan,
                    'amount_request' => $detail->quantity,
                    'is_auto' => false,
                ]);
            } else {
                $line->update([
                    'source' => ProcurementRequestSource::DariPermohonan,
                    'amount_request' => (int) $line->amount_request + $detail->quantity,
                    'is_auto' => false,
                ]);
            }

            $detail->update(['procurement_request_id' => $draft->procurement_request_id]);
        }
    }
}
