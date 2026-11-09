<?php

namespace App\Models;

use App\Enums\AlasanPembatalan;
use App\Enums\AlasanPengadaan;
use App\Enums\PihakBast;
use App\Enums\ProcurementDocumentType;
use App\Enums\ProcurementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Realisasi pengadaan — lahir dari satu ProcurementRequest yang disetujui,
 * atau berdiri sendiri tanpa usulan sama sekali (ADR 0031).
 *
 * Setelah disetujui, realisasi berada di ProsesBelanja sampai Pejabat
 * Pengadaan menandainya sudah dibelanjakan (ADR 0035), lalu berhenti di
 * MenungguPenerimaan sampai Pengelola Gudang mengonfirmasi penerimaan lewat
 * ProcurementReceipt; baru saat itu stok bertambah. Lihat ADR 0008 dan
 * ADR 0015.
 */
class Procurement extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'procurement_request_id', 'supplier_id', 'procurement_date',
        'basis_reason', 'basis_note', 'status', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'procurement_date' => 'date',
            'basis_reason' => AlasanPengadaan::class,
            'status' => ProcurementStatus::class,
            'reviewed_at' => 'datetime',
            'purchased_at' => 'date',
            'cancelled_at' => 'datetime',
            'cancel_reason' => AlasanPembatalan::class,
        ];
    }

    /**
     * Judul singkat untuk tampilan dan notifikasi, mis. "Realisasi #A1B2C3D4".
     */
    public function judul(): string
    {
        return 'Realisasi #'.strtoupper(substr($this->procurement_id, 0, 8));
    }

    /**
     * Realisasi langsung — dibuat Pejabat Pengadaan tanpa usulan (ADR 0031).
     *
     * Sengaja diturunkan dari `procurement_request_id`, bukan disimpan sebagai
     * kolom `source` tersendiri: jawabannya sudah ada di baris yang sama,
     * sehingga tidak butuh join dan tidak mungkin menyimpang dari kenyataan.
     * Berbeda dari ADR 0028, yang memang harus menyimpan statusnya karena
     * pertanyaannya tidak terjawab dari satu baris.
     */
    public function tanpaUsulan(): bool
    {
        return $this->procurement_request_id === null;
    }

    /**
     * Dasar pengadaan untuk ditampilkan: judul usulan asalnya, atau label
     * alasan bagi realisasi langsung.
     */
    public function dasarRingkas(): string
    {
        if (! $this->tanpaUsulan()) {
            return $this->procurementRequest?->judul() ?? 'Usulan tidak ditemukan';
        }

        return 'Langsung — '.($this->basis_reason?->label() ?? 'tanpa alasan tercatat');
    }

    public function isMenungguPersetujuan(): bool
    {
        return $this->status === ProcurementStatus::MenungguPersetujuan;
    }

    public function isProsesBelanja(): bool
    {
        return $this->status === ProcurementStatus::ProsesBelanja;
    }

    public function isMenungguPenerimaan(): bool
    {
        return $this->status === ProcurementStatus::MenungguPenerimaan;
    }

    public function isDiterima(): bool
    {
        return $this->status === ProcurementStatus::Diterima;
    }

    public function isDitolak(): bool
    {
        return $this->status === ProcurementStatus::Ditolak;
    }

    public function isDibatalkan(): bool
    {
        return $this->status === ProcurementStatus::Dibatalkan;
    }

    /**
     * Masih boleh diubah maupun dibatalkan — lihat ProcurementStatus.
     */
    public function masihBerjalan(): bool
    {
        return $this->status->masihBerjalan();
    }

    /**
     * Sudah pernah diputuskan Kasubbag Umum dan TI, disetujui maupun ditolak.
     *
     * Bukan sekadar `! isMenungguPersetujuan()`: realisasi yang ditolak lalu
     * diperbaiki kembali menunggu persetujuan dengan jejak review yang sudah
     * dikosongkan, dan saat itu memang belum ada keputusan yang berlaku.
     */
    public function sudahDireview(): bool
    {
        return $this->reviewed_at !== null;
    }

    /**
     * Barangnya sudah dibelanjakan, apa pun statusnya sekarang.
     *
     * Ditanyakan dari `purchased_at`, bukan dari status: realisasi yang sudah
     * dibelanjakan lalu diubah kembali menunggu persetujuan, dan saat itu
     * statusnya tidak lagi mengatakan apa pun tentang belanjanya. Karena itu
     * pula persetujuan ulang langsung membawanya ke MenungguPenerimaan —
     * mengubah dokumen mengulang penilaian, bukan mengulang belanja.
     * Lihat ADR 0035.
     */
    public function sudahDibelanjakan(): bool
    {
        return $this->purchased_at !== null;
    }

    /**
     * Jumlah foto belanja yang sudah diunggah — syarat menandai realisasi
     * sudah dibelanjakan (ADR 0035).
     */
    public function jumlahFotoBelanja(): int
    {
        return $this->documents()
            ->where('type', ProcurementDocumentType::FotoBelanja)
            ->count();
    }

    /**
     * Penyaring daftar sekaligus penyaring laporan.
     *
     * Rentang tanggal memakai `procurement_date` (tanggal realisasi), bukan
     * `created_at`: yang dilaporkan adalah kapan pengadaannya terjadi, bukan
     * kapan barisnya diketik. Berbeda dari buku mutasi, yang justru harus
     * dibaca menurut urutan pencatatan (ADR 0005) karena `stock_after` hanya
     * koheren dalam urutan itu.
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                ProcurementStatus::tryFrom((string) ($filters['status'] ?? '')),
                fn (Builder $query, ProcurementStatus $status) => $query->where('status', $status),
            )
            // Dasar pengadaan: langsung versus dari usulan (ADR 0031). Nilai
            // lain diabaikan supaya query string sembarangan tidak mengosongkan
            // daftar tanpa penjelasan.
            ->when(
                in_array($sumber = trim((string) ($filters['sumber'] ?? '')), ['langsung', 'usulan'], true),
                fn (Builder $query) => $sumber === 'langsung'
                    ? $query->whereNull('procurement_request_id')
                    : $query->whereNotNull('procurement_request_id'),
            )
            // BAST yang menunggu TTE satu pihak — tautan antrean dasbor
            // (ADR 0051). Definisinya `scopeMenungguTte()`, sama dengan
            // notifikasinya, supaya angka dasbor sama dengan isi daftar.
            ->when(
                PihakBast::dariPenyaring(trim((string) ($filters['tte'] ?? ''))),
                fn (Builder $query, PihakBast $pihak) => $query->menungguTte($pihak),
            )
            ->when(
                trim((string) ($filters['supplier'] ?? '')),
                fn (Builder $query, string $supplier) => $query->where('supplier_id', $supplier),
            )
            ->when(
                trim((string) ($filters['from'] ?? '')),
                fn (Builder $query, string $from) => $query->whereDate('procurement_date', '>=', $from),
            )
            ->when(
                trim((string) ($filters['to'] ?? '')),
                fn (Builder $query, string $to) => $query->whereDate('procurement_date', '<=', $to),
            );
    }

    /**
     * Nilai seluruh barang yang dipesan, dalam rupiah bulat.
     */
    public function nilaiTotal(): int
    {
        return $this->details->sum(fn (ProcurementDetail $detail) => $detail->subtotal());
    }

    /**
     * Nilai barang yang benar-benar diterima, dalam rupiah bulat.
     *
     * Dihitung dari `amount_received`, sehingga berbeda dari `nilaiTotal()`
     * begitu penyedia mengirim kurang. Nol selama penerimaan belum
     * dikonfirmasi. Lihat ADR 0017.
     */
    public function nilaiDiterima(): int
    {
        return $this->details->sum(
            fn (ProcurementDetail $detail) => ($detail->amount_received ?? 0) * $detail->unit_price,
        );
    }

    /**
     * Apakah ada barang yang datang kurang dari yang dipesan.
     *
     * Bukan status tersendiri: pertanyaannya dijawab dari rincian, supaya tidak
     * ada dua sumber kebenaran. Lihat ADR 0017.
     */
    public function diterimaSebagian(): bool
    {
        return $this->details->contains(fn (ProcurementDetail $detail) => $detail->kurangKirim() > 0);
    }

    /**
     * Apakah berkas BAST bertanda tangan sudah diunggah.
     *
     * Penerimaan sengaja tidak menunggu berkas ini — lihat ADR 0018 — sehingga
     * keadaan "sudah diterima tetapi BAST belum diunggah" memang sah dan perlu
     * bisa ditanyakan.
     */
    public function bastSudahDiunggah(): bool
    {
        return $this->documents()
            ->where('type', ProcurementDocumentType::Bast)
            ->exists();
    }

    /**
     * Apakah nomor BAST masih boleh diisi atau dikoreksi.
     *
     * BAST digital (ADR 0051): selama realisasi menunggu penerimaan dan hasil
     * pemeriksaannya sudah dicatat. Setelah kedua pihak bertanda tangan,
     * realisasinya `Diterima` dan nomornya terkunci bersama isinya. Mengubah
     * nomor yang sudah ditandatangani satu pihak mencabut TTE itu.
     *
     * BAST lama (sudah diterima sebelum ADR 0051, tanpa TTE): seperti
     * ADR 0042, terkunci begitu pindaian bertanda tangan diunggah dan terbuka
     * lagi bila berkas itu dihapus.
     */
    public function nomorBastDapatDiubah(): bool
    {
        if ($this->receipt === null) {
            return false;
        }

        if ($this->isMenungguPenerimaan()) {
            return true;
        }

        return $this->bastLama() && ! $this->bastSudahDiunggah();
    }

    /**
     * BAST yang diterima sebelum ADR 0051: tanpa TTE, ditandatangani basah lalu
     * dipindai. Penerimaan di alur baru tidak mungkin `Diterima` tanpa dua
     * TTE, jadi ketiadaan baris tanda tangan cukup untuk mengenalinya.
     */
    public function bastLama(): bool
    {
        return $this->isDiterima()
            && $this->receipt !== null
            && ! $this->receipt->bertandaTanganElektronik();
    }

    /**
     * Seluruh barang sudah punya jumlah hasil pemeriksaan — syarat nomor BAST
     * dan TTE (ADR 0051). Barang tambahan dari realisasi yang diubah sesudah
     * pemeriksaan membuatnya belum lengkap lagi.
     */
    public function hasilPemeriksaanLengkap(): bool
    {
        return $this->receipt !== null
            && $this->details->isNotEmpty()
            && $this->details->every(fn (ProcurementDetail $detail) => $detail->sudahDiperiksa());
    }

    /**
     * BAST ini menunggu TTE satu pihak: hasil pemeriksaannya lengkap dan pihak
     * itu belum bertanda tangan. Nomor yang belum diisi tidak mengecualikan —
     * kedua pihak sama-sama boleh mengisinya. Pasangan kuerinya
     * `scopeMenungguTte()`; keduanya harus selalu sama.
     */
    public function menungguTte(PihakBast $pihak): bool
    {
        return $this->isMenungguPenerimaan()
            && $this->hasilPemeriksaanLengkap()
            && $this->receipt->tteAktif($pihak) === null;
    }

    public function scopeMenungguTte(Builder $query, PihakBast $pihak): Builder
    {
        return $query
            ->where('status', ProcurementStatus::MenungguPenerimaan)
            ->whereHas('receipt', fn (Builder $receipt) => $receipt->whereDoesntHave(
                'signatures',
                fn (Builder $ttd) => $ttd->where('party', $pihak)->whereNull('revoked_at'),
            ))
            ->whereHas('details')
            ->whereDoesntHave('details', fn (Builder $detail) => $detail->whereNull('amount_checked'));
    }

    public function procurementRequest(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class, 'procurement_request_id', 'procurement_request_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function purchasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchased_by', 'user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ProcurementDetail::class, 'procurement_id', 'procurement_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(ProcurementReceipt::class, 'procurement_id', 'procurement_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProcurementDocument::class, 'procurement_id', 'procurement_id');
    }
}
