<?php

namespace App\Services;

use App\Enums\AuditEvent;
use App\Enums\AuditModule;
use App\Enums\MutationType;
use App\Enums\RequestHistoryAction;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStockException;
use App\Models\Request as ModelsRequest;
use App\Models\RequestHistory;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\NotificationScanner;
use App\Support\RequestDocumentGenerator;
use App\Support\StockLedger;
use App\Support\StockReservation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RequestWorkflowService
{
    public function __construct(
        private readonly RequestDocumentGenerator $documentGenerator,
    ) {
    }

    /**
     * @param  string|null  $ndSequence  nomor urut Nota Dinas, wajib saat
     *                                    menyetujui permintaan yang sudah disetujui Kepala Unit
     *                                    tetapi belum bernomor (data lama atau hasil penggabungan
     *                                    permintaan umum); permintaan biasa sudah bernomor sejak diajukan
     * @param  string|null  $note  catatan untuk pemohon; wajib saat menolak, opsional saat menyetujui
     *
     * @throws ValidationException
     */
    public function respond(
        ModelsRequest $request,
        User $actor,
        string $action,
        ?string $ndSequence = null,
        ?string $note = null,
    ): ModelsRequest
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Aksi tidak valid.');
        }

        $note = $this->validNote($note, required: $action === 'reject');

        $request->loadMissing('user');

        if ($request->status !== 'submitted' && $request->hasItemProposals()) {
            throw new AuthorizationException('Permintaan belum dapat diproses karena masih ada usulan barang yang harus diselesaikan atau dihapus dari permintaan.');
        }

        $this->assertCanRespond($request, $actor);

        // Permintaan yang keluar dari tahap Disetujui Kepala Unit, baik normal
        // maupun darurat, harus sudah mendapat nomor Nota Dinas resmi. Nomor
        // diisi pemohon saat mengajukan; di sini hanya cadangan untuk yang
        // belum bernomor.
        $assignsNumber = $action === 'approve'
            && $request->status === 'approved_by_head'
            && $request->nd_number === null;

        DB::transaction(function () use ($request, $actor, $action, $assignsNumber, $ndSequence, $note): void {
            $fromStatus = $request->status;

            if ($action === 'reject') {
                $request->update([
                    'status' => 'rejected',
                    'rejected_from_status' => $fromStatus,
                ]);
                RequestHistory::catat($request, $actor, RequestHistoryAction::Ditolak, $fromStatus, 'rejected', $note);

                return;
            }

            if ($assignsNumber) {
                $this->assignNotaDinasNumber($request, $ndSequence);
            }

            $nextStatus = $this->nextStatus($request, $actor);

            if ($nextStatus === 'processed_by_staff'
                || ($nextStatus === 'delivered' && $request->type === 'emergency')) {
                $this->fillUndecidedQuantities($request);
            }

            // Stok keluar saat barang diserahkan, bukan saat pemohon
            // mengonfirmasi: buku mutasi harus mengikuti isi gudang.
            $request->update([
                'status' => $nextStatus,
                ...($nextStatus === 'delivered' ? ['delivered_at' => now()] : []),
            ]);

            if ($actor->hasRole(UserRole::KepalaUnit)
                || ($actor->hasRole(UserRole::KasubbagUmumTi)
                    && $actor->unit === UnitKerja::SubbagianUmumTi
                    && $nextStatus === 'approved_by_head')
                || ($actor->isAdmin() && $nextStatus === 'approved_by_head')) {
                $this->documentGenerator->approveNotaDinas($request, $actor);
            } elseif ($nextStatus === 'delivered') {
                $this->recordIssuance($request, $actor);
                $this->documentGenerator->generateBast($request, $actor);
            }

            if ($assignsNumber) {
                $this->documentGenerator->renumberNotaDinas($request);
            }

            RequestHistory::catat($request, $actor, RequestHistoryAction::Disetujui, $fromStatus, $nextStatus, $note);
        });

        $request = $request->fresh('user');

        AuditLogger::record(
            user: $actor,
            activity: sprintf(
                '%s permintaan barang %s (status menjadi %s)%s',
                $action === 'approve' ? 'Menyetujui' : 'Menolak',
                $request->request_number,
                $request->statusLabel(),
                $note !== null ? ". Catatan: {$note}" : '',
            ),
            module: AuditModule::Permintaan,
            event: $action === 'approve' ? AuditEvent::Setuju : AuditEvent::Tolak,
            subject: $request,
        );
        NotificationScanner::periksaPermintaan($request);

        return $request;
    }

    /**
     * Akhir alur permintaan setelah barang diserahkan.
     *
     * - `confirm`: pemohon menyatakan barang sudah diterima → Selesai.
     * - `report`: pemohon melaporkan barang kurang, rusak, atau belum sampai.
     * - `reply`: Pengelola Gudang/Admin menanggapi laporan itu.
     * - `close`: Pengelola Gudang/Admin menutup atas nama pemohon yang tidak
     *   kunjung mengonfirmasi, setelah BATAS_HARI_KONFIRMASI hari.
     *
     * Stok sudah keluar saat diserahkan, jadi tidak ada satu pun aksi di sini
     * yang menyentuh StockLedger.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function receipt(ModelsRequest $request, User $actor, string $action, ?string $note = null): ModelsRequest
    {
        if (! in_array($action, ['confirm', 'report', 'reply', 'close'], true)) {
            throw new InvalidArgumentException('Aksi tidak valid.');
        }

        if ($request->status !== 'delivered') {
            throw new AuthorizationException('Permintaan ini tidak sedang menunggu konfirmasi penerimaan.');
        }

        $isRequester = $request->user_id === $actor->user_id;
        $isOfficer = $actor->can('kelola-permintaan');

        [$allowed, $denied] = match ($action) {
            'confirm', 'report' => [$isRequester, 'Hanya pemohon yang dapat mengonfirmasi penerimaan barangnya.'],
            'reply' => [$isOfficer && $request->hasOpenReceiptProblem(), 'Tidak ada laporan masalah yang perlu ditanggapi.'],
            'close' => [$isOfficer && $request->canBeClosedOnBehalf(), sprintf(
                'Permintaan baru dapat ditutup atas nama pemohon %d hari setelah diserahkan dan bila tidak ada masalah yang belum ditanggapi.',
                ModelsRequest::BATAS_HARI_KONFIRMASI,
            )],
        };

        if (! $allowed) {
            throw new AuthorizationException($denied);
        }

        $note = $this->validNote($note, required: $action !== 'confirm', message: match ($action) {
            'report' => 'Jelaskan masalahnya, mis. jumlah kurang atau barang rusak, supaya Gudang dapat menindaklanjutinya.',
            'reply' => 'Tulis tanggapan untuk pemohon.',
            default => 'Alasan penutupan wajib diisi.',
        });

        [$historyAction, $toStatus, $event, $activity] = match ($action) {
            'confirm' => [RequestHistoryAction::Diterima, 'completed', AuditEvent::Terima, 'Mengonfirmasi penerimaan barang'],
            'report' => [RequestHistoryAction::MasalahDilaporkan, 'delivered', AuditEvent::Ubah, 'Melaporkan masalah penerimaan barang'],
            'reply' => [RequestHistoryAction::MasalahDitanggapi, 'delivered', AuditEvent::Ubah, 'Menanggapi masalah penerimaan barang'],
            'close' => [RequestHistoryAction::DitutupPetugas, 'completed', AuditEvent::UbahStatus, 'Menutup atas nama pemohon permintaan barang'],
        };

        DB::transaction(function () use ($request, $actor, $historyAction, $toStatus, $note): void {
            if ($toStatus !== 'delivered') {
                $request->update(['status' => $toStatus]);
            }

            RequestHistory::catat($request, $actor, $historyAction, 'delivered', $toStatus, $note);
        });

        $request = $request->fresh('user');

        AuditLogger::record(
            user: $actor,
            activity: "{$activity} {$request->request_number}".($note !== null ? ". Catatan: {$note}" : ''),
            module: AuditModule::Permintaan,
            event: $event,
            subject: $request,
        );
        NotificationScanner::periksaPermintaan($request);

        return $request;
    }

    /**
     * Membatalkan permintaan yang tidak diputuskan Kepala Unit dalam
     * BATAS_HARI_PERSETUJUAN hari, supaya stok yang ditahannya kembali
     * tersedia. Dicatat sebagai penolakan dari tahap Terkirim, sehingga Admin
     * tetap bisa mengembalikannya bila perlu. Dipanggil perintah terjadwal
     * `permintaan:kedaluwarsa`; aman dijalankan berkali-kali.
     *
     * @return int jumlah permintaan yang dibatalkan
     */
    public function expireStale(): int
    {
        $batas = now()->subDays(ModelsRequest::BATAS_HARI_PERSETUJUAN);
        $dibatalkan = 0;

        ModelsRequest::query()
            ->with('user')
            ->where('type', '!=', 'umum')
            ->where('status', 'submitted')
            ->where('created_at', '<=', $batas)
            // Diambil sekaligus, bukan per halaman: status yang disaring ikut
            // berubah di dalam perulangan, sehingga penomoran halaman akan
            // melompati sebagian baris.
            ->get()
            ->each(function (ModelsRequest $request) use (&$dibatalkan): void {
                DB::transaction(function () use ($request): void {
                    $request->update([
                        'status' => 'rejected',
                        'rejected_from_status' => 'submitted',
                    ]);

                    RequestHistory::catat(
                        $request,
                        null,
                        RequestHistoryAction::Kedaluwarsa,
                        'submitted',
                        'rejected',
                        sprintf(
                            'Tidak diputuskan %s dalam %d hari; stok yang ditahan dilepas. Ajukan kembali bila barangnya masih dibutuhkan.',
                            $request->sebutanPenyetuju(),
                            ModelsRequest::BATAS_HARI_PERSETUJUAN,
                        ),
                    );
                });

                NotificationScanner::periksaPermintaan($request->fresh('user'));
                $dibatalkan++;
            });

        return $dibatalkan;
    }

    /**
     * Admin mengembalikan permintaan satu langkah ke status sebelumnya untuk
     * membatalkan salah klik atau salah input, sekaligus membatalkan efek
     * samping langkah tersebut. Permintaan yang sudah selesai tidak bisa
     * dikembalikan karena stok sudah keluar dan BAST sudah terbit.
     */
    public function revert(ModelsRequest $request, User $actor, string $reason): ModelsRequest
    {
        if (! $actor->isAdmin()) {
            throw new AuthorizationException('Hanya Admin yang dapat mengembalikan status permintaan.');
        }

        $fromStatus = $request->status;
        $toStatus = $request->previousStatus();

        if ($toStatus === null) {
            throw new AuthorizationException('Status permintaan ini tidak dapat dikembalikan.');
        }

        DB::transaction(function () use ($request, $actor, $reason, $fromStatus, $toStatus): void {
            $request->update([
                'status' => $toStatus,
                'rejected_from_status' => null,
            ]);

            // Penolakan tidak punya efek samping, jadi pemulihannya cukup
            // mengembalikan status.
            if ($fromStatus === 'approved_by_head') {
                // Nota Dinas dibuat ulang tanpa persetujuan; tautan QR lama mati.
                $this->documentGenerator->generateNotaDinas($request);
            } elseif ($fromStatus === 'processed_by_staff') {
                // Kembali ke kondisi sebelum Pengelola Gudang memproses:
                // jumlah disetujui belum diputuskan dan barang tambahan dari
                // staf dibuang.
                $request->item()->where('quantity_requested', 0)->delete();
                $request->item()->update(['quantity_approved' => null]);
            }

            RequestHistory::catat($request, $actor, RequestHistoryAction::Dikembalikan, $fromStatus, $toStatus, $reason);
        });

        $request = $request->fresh('user');

        AuditLogger::record(
            user: $actor,
            activity: sprintf(
                'Mengembalikan status permintaan barang %s dari %s ke %s. Alasan: %s',
                $request->request_number,
                ModelsRequest::labelStatus($fromStatus),
                ModelsRequest::labelStatus($toStatus),
                $reason,
            ),
            module: AuditModule::Permintaan,
            event: AuditEvent::UbahStatus,
            subject: $request,
        );
        NotificationScanner::periksaPermintaan($request);

        return $request;
    }

    /**
     * Menetapkan nomor Nota Dinas dari nomor urut yang diisi pemohon saat
     * mengajukan (atau Pengelola Gudang, untuk permintaan yang belum bernomor).
     * Nomor BAST ikut ditetapkan dengan nomor urut yang sama, dan keduanya
     * dibekukan di sini supaya tidak berubah walau unit pemohon diganti.
     *
     * @throws ValidationException
     */
    public function assignNotaDinasNumber(ModelsRequest $request, ?string $sequence): void
    {
        $sequence = trim((string) $sequence);

        if (preg_match(ModelsRequest::ND_SEQUENCE_PATTERN, $sequence) !== 1) {
            throw ValidationException::withMessages([
                'nd_sequence' => 'Nomor urut Nota Dinas wajib diisi berupa angka, paling banyak 6 digit.',
            ]);
        }

        $request->loadMissing('user');
        $ndNumber = $request->documentNumber('ND', $sequence);

        if (ModelsRequest::query()->where('nd_number', $ndNumber)->whereKeyNot($request->getKey())->exists()) {
            throw ValidationException::withMessages([
                'nd_sequence' => "Nomor Nota Dinas {$ndNumber} sudah dipakai permintaan lain.",
            ]);
        }

        $request->update([
            'nd_number' => $ndNumber,
            'bast_number' => $request->documentNumber('BAST', $sequence),
        ]);
    }

    /**
     * Rapikan catatan keputusan. Catatan inilah yang dibaca pemohon, jadi
     * penolakan tanpa alasan tidak diterima.
     *
     * @throws ValidationException
     */
    private function validNote(
        ?string $note,
        bool $required,
        string $message = 'Alasan penolakan wajib diisi supaya pemohon tahu mengapa permintaannya ditolak.',
    ): ?string {
        $note = trim((string) $note);

        if ($required && $note === '') {
            throw ValidationException::withMessages(['note' => $message]);
        }

        if (mb_strlen($note) > 500) {
            throw ValidationException::withMessages([
                'note' => 'Catatan paling banyak 500 karakter.',
            ]);
        }

        return $note === '' ? null : $note;
    }

    private function assertCanRespond(ModelsRequest $request, User $actor): void
    {
        // Permintaan umum punya jalurnya sendiri (ubah, tolak, gabung) di
        // Daftar Permintaan Umum, bukan alur persetujuan bertahap ini.
        if ($request->type === 'umum') {
            throw new AuthorizationException('Permintaan umum diproses lewat Daftar Permintaan Umum.');
        }

        $canRespond = match (true) {
            $actor->isAdmin() => in_array($request->status, [
                'submitted',
                'approved_by_head',
                'processed_by_staff',
                'in_issuance',
            ], true),
            $actor->hasRole(UserRole::KepalaUnit) =>
                $request->status === 'submitted'
                    && $actor->unit === $request->user?->unit,
            // Setelah Kasubbag memulai pengeluaran, penyerahan barang adalah
            // giliran Pengelola Gudang yang memegang fisiknya, bukan Kasubbag.
            $actor->hasRole(UserRole::PengelolaGudang) =>
                in_array($request->status, ['approved_by_head', 'in_issuance'], true),
            $actor->hasRole(UserRole::KasubbagUmumTi) =>
                ($request->status === 'submitted' && $actor->unit === UnitKerja::SubbagianUmumTi)
                || $request->status === 'processed_by_staff'
                || ($request->type === 'emergency' && $request->status === 'approved_by_head'),
            default => false,
        };

        // Permintaan darurat yang sudah disetujui Kepala Unit tetap boleh
        // ditolak (dengan alasan), supaya tidak buntu saat stoknya ternyata
        // tidak cukup.
        if (! $canRespond) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk memproses permintaan ini.');
        }
    }

    private function nextStatus(ModelsRequest $request, User $actor): string
    {
        if ($request->type === 'emergency' && $request->status === 'approved_by_head') {
            return 'delivered';
        }

        if ($actor->isAdmin()) {
            return match ($request->status) {
                'submitted' => 'approved_by_head',
                'approved_by_head' => 'processed_by_staff',
                'processed_by_staff' => 'in_issuance',
                default => 'delivered',
            };
        }

        if ($actor->hasRole(UserRole::KepalaUnit)) {

            return 'approved_by_head';
        }

        if ($actor->hasRole(UserRole::KasubbagUmumTi)
            && $actor->unit === UnitKerja::SubbagianUmumTi
            && $request->status === 'submitted') {
            return 'approved_by_head';
        }

        if ($actor->hasRole(UserRole::PengelolaGudang)) {
            return $request->status === 'in_issuance'
                ? 'delivered'
                : 'processed_by_staff';
        }

        return $request->status === 'processed_by_staff'
            ? 'in_issuance'
            : 'delivered';
    }

    private function recordIssuance(ModelsRequest $request, User $actor): void
    {
        $request->loadMissing(['user', 'item.item']);

        foreach ($request->item as $detail) {
            if ($detail->item === null) {
                continue;
            }

            $amount = (int) $detail->quantity_approved;

            if ($amount <= 0) {
                continue;
            }

            $referenceNumber = $request->nd_number ?: $request->request_number;

            StockLedger::record(
                item: $detail->item,
                type: MutationType::Keluar,
                amount: $amount,
                recordedBy: $actor,
                reference: $request,
                referenceNumber: $referenceNumber,
                notes: "Penyerahan ke ".($request->user?->unit?->label() ?? 'unit pemohon')." sesuai {$referenceNumber}",
            );
        }
    }

    /**
     * Menyetujui tanpa Ubah Kuantitas berarti memberikan sesuai permintaan.
     * Jumlah yang sudah diputuskan, termasuk 0 (tidak diberikan), dibiarkan.
     */
    private function fillUndecidedQuantities(ModelsRequest $request): void
    {
        $request->loadMissing('item');

        foreach ($request->item as $detail) {
            if (! $detail->isDecided()) {
                $detail->loadMissing('item');

                // Stok tersedia, bukan stok fisik: permintaan lain yang masih
                // berjalan menahan jatahnya. Tahanan permintaan ini sendiri
                // tidak ikut dihitung.
                $available = $detail->item !== null
                    ? StockReservation::availableFor($detail->item, $request->request_id)
                    : null;

                if ($available !== null && (int) $detail->quantity_requested > $available) {
                    throw new InsufficientStockException(
                        $detail->item,
                        (int) $detail->quantity_requested,
                        $available,
                    );
                }

                $detail->update([
                    'quantity_approved' => $detail->quantity_requested,
                ]);
            }
        }
    }

}
