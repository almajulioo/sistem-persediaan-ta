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
use App\Models\Request as ModelsRequest;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ItemApplicationDocumentGenerator;
use App\Support\NotificationScanner;
use App\Support\ProcurementDraftScanner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    public function __construct(
        private readonly ItemApplicationDocumentGenerator $documents,
    ) {}

    /**
     * Tetapkan nomor Nota Dinas dari nomor urut yang diisi pemohon.
     *
     * Permintaan dan permohonan memakai agenda unit yang sama dengan format
     * berbeda (KEU.02.02 dan BMN.09.03), jadi yang dibandingkan nomor urut,
     * unit, bulan, dan tahunnya di kedua tabel.
     *
     * @throws ValidationException
     */
    public function assignNotaDinasNumber(ItemApplication $application, string $sequence): void
    {
        $ndNumber = $application->nomorNotaDinas($sequence);
        $setaraPermintaan = (new ModelsRequest(['date' => $application->date]))
            ->setRelation('user', $application->user)
            ->documentNumber('ND', $sequence);

        if (ItemApplication::query()->where('nd_number', $ndNumber)->exists()
            || ModelsRequest::query()->where('nd_number', $setaraPermintaan)->exists()) {
            throw ValidationException::withMessages([
                'nd_sequence' => "Nomor Nota Dinas {$ndNumber} sudah dipakai.",
            ]);
        }

        $application->update(['nd_number' => $ndNumber]);
    }

    /**
     * Setujui tahap yang sedang berjalan.
     *
     * @throws AuthorizationException
     */
    public function approve(ItemApplication $application, User $actor): ItemApplication
    {
        return match ($application->status) {
            ItemApplicationStatus::Diajukan => $this->approveByHead($application, $actor),
            ItemApplicationStatus::DisetujuiKepalaUnit => $this->review($application, $actor),
            ItemApplicationStatus::DitinjauGudang => $this->approveFinal($application, $actor),
            default => throw new AuthorizationException('Permohonan ini sudah diputuskan sebelumnya.'),
        };
    }

    /**
     * @throws AuthorizationException
     */
    private function approveByHead(ItemApplication $application, User $actor): ItemApplication
    {
        DB::transaction(function () use ($application, $actor): void {
            $this->lockTurn($application, $actor);

            $application->forceFill([
                'status' => ItemApplicationStatus::DisetujuiKepalaUnit,
                'head_approved_by' => $actor->user_id,
                'head_approved_at' => now(),
            ])->save();

            $this->documents->approveNotaDinas($application, $actor);
        });

        return $this->recordStage($application, $actor, 'Menyetujui permohonan %s dan menandatangani Nota Dinasnya');
    }

    /**
     * @throws AuthorizationException
     */
    private function review(ItemApplication $application, User $actor): ItemApplication
    {
        DB::transaction(function () use ($application, $actor): void {
            $this->lockTurn($application, $actor);

            $application->forceFill([
                'status' => ItemApplicationStatus::DitinjauGudang,
                'reviewed_by' => $actor->user_id,
                'reviewed_at' => now(),
            ])->save();
        });

        return $this->recordStage($application, $actor, 'Meninjau permohonan %s dan meneruskannya ke Kasubbag Umum dan TI');
    }

    private function recordStage(ItemApplication $application, User $actor, string $activity): ItemApplication
    {
        $application = $application->fresh(['user', 'details']);

        AuditLogger::record(
            user: $actor,
            activity: sprintf($activity, $application->application_number),
            module: AuditModule::Permohonan,
            event: AuditEvent::Setuju,
            subject: $application,
        );
        NotificationScanner::periksaPermohonan($application);
        NotificationScanner::catatPermohonanMajuTahap($application, $actor);

        return $application;
    }

    /**
     * Keputusan akhir: barisnya baru diteruskan ke pengadaan di sini.
     *
     * @throws AuthorizationException
     */
    private function approveFinal(ItemApplication $application, User $actor): ItemApplication
    {
        /** @var Collection<int, ItemProposal> $proposals */
        $proposals = DB::transaction(function () use ($application, $actor): Collection {
            $this->lockTurn($application, $actor);

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
            $this->lockTurn($application, $actor);

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
     * Kunci baris permohonan supaya dua keputusan yang dikirim bersamaan tidak
     * sama-sama lolos, lalu pastikan tahapnya masih sama dan memang giliran
     * akun ini.
     *
     * @throws AuthorizationException
     */
    private function lockTurn(ItemApplication $application, User $actor): void
    {
        $locked = ItemApplication::query()->with('user')->lockForUpdate()->findOrFail($application->getKey());

        if (! $locked->isMenunggu() || $locked->status !== $application->status) {
            throw new AuthorizationException('Permohonan ini sudah diputuskan sebelumnya.');
        }

        if (! $locked->bisaDiputuskanOleh($actor)) {
            throw new AuthorizationException('Anda tidak memiliki izin memutuskan permohonan pada tahap ini.');
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
