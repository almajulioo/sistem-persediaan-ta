<?php

namespace App\Models;

use App\Enums\RequestHistoryAction;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Request extends Model
{
    use HasFactory, HasUuids;

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'request_id';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'date',
        'type',
        'status',
        'request_number',
        'bast_number',
        'nd_number',
        'merged_into_request_id',
        'rejected_from_status',
        'delivered_at',
        // TODO: Tambahkan Description field di sini nanti ditambah juga di show request 
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'delivered_at' => 'datetime',
            'request_amount' => 'integer',
            'accept_amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_request_id', 'request_id');
    }

    // `details()` dan `procurementRequests()` dibuang 5 September 2026: keduanya
    // dibangun di atas premis yang keliru. `RequestDetail` ternyata bukan
    // rincian permintaan unit — ia salah nama dan salah arah FK sejak desain
    // ERD awal, dan sudah dipindah sepenuhnya ke sisi Pengadaan sebagai
    // App\Models\ProcurementRequestDetail (lihat ADR 0015). `requests` di sini
    // belum punya tabel rincian barangnya sendiri; itu keputusan yang perlu
    // diambil saat sub sistem Permintaan dirancang.

    public function requestDocuments(): HasMany
    {
        return $this->hasMany(RequestDocument::class, 'request_id', 'request_id');
    }


    public function item(): HasMany
    {
        return $this->hasMany(RequestDetail::class, 'request_id', 'request_id')
            ->with(['item', 'itemProposal']);
    }

    /**
     * Riwayat langkah beserta catatan petugas, urut dari yang paling awal.
     */
    public function histories(): HasMany
    {
        return $this->hasMany(RequestHistory::class, 'request_id', 'request_id')
            ->orderBy('created_at')
            ->orderBy('request_history_id');
    }

    public function hasItemProposals(): bool
    {
        return $this->item()->whereNotNull('item_proposal_id')->exists();
    }

    /**
     * Penanda tangan Nota Dinas dari unit peminta, yaitu akun yang berhak
     * menyetujuinya. Subbagian Umum dan TI disetujui Kasubbag Umum dan TI,
     * bukan peran Kepala Unit.
     */
    public function kepalaUnit(): ?User
    {
        $unit = $this->user?->unit;

        if ($unit === null) {
            return null;
        }

        $role = $unit === UnitKerja::SubbagianUmumTi ? UserRole::KasubbagUmumTi : UserRole::KepalaUnit;

        return User::query()
            ->where('is_active', true)
            ->where('role', $role->value)
            ->where('unit', $unit)
            ->first();
    }

    /**
     * Permintaan yang boleh dilihat akun ini: Kepala Unit hanya permintaan
     * dari unitnya, Pengguna hanya miliknya sendiri, peran lain semuanya.
     * Dipakai daftar permintaan dan dasbor supaya angkanya selalu sama.
     */
    public function scopeTerlihatOleh(Builder $query, User $user): Builder
    {
        return match (true) {
            $user->hasRole(UserRole::KepalaUnit) => $query->whereHas('user', fn (Builder $userQuery) => $userQuery->where('unit', $user->unit)),
            $user->hasRole(UserRole::Pengguna) => $query->where('user_id', $user->user_id),
            default => $query,
        };
    }

    /**
     * Label status, satu sumber untuk halaman, modal, dan log audit supaya
     * tahap yang sama tidak lagi disebut dengan nama berbeda.
     */
    public const STATUS_LABELS = [
        'submitted' => 'Terkirim',
        // Label umum, untuk tempat yang tidak terikat satu permintaan
        // (saringan, alur dasbor). Bila unit pemohonnya diketahui, pakai
        // labelStatusUntuk() supaya penyetujunya disebut tepat.
        'approved_by_head' => 'Disetujui Kepala Subbagian/Bidang',
        'processed_by_staff' => 'Disetujui Pengelola Gudang',
        'in_issuance' => 'Dalam Proses Pengeluaran',
        'delivered' => 'Diserahkan',
        'completed' => 'Selesai',
        'rejected' => 'Ditolak',
        'merged' => 'Digabung',
    ];

    public static function labelStatus(?string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));
    }

    /**
     * Label status untuk permintaan ini. Tahap persetujuan atasan disebut
     * menurut unit pemohon: "Disetujui Kepala Subbagian" atau "Disetujui
     * Kepala Bidang Pemeriksaan", dst.
     */
    public function labelStatusUntuk(?string $status): string
    {
        if ($status === 'approved_by_head' && $this->user?->unit !== null) {
            return 'Disetujui '.$this->sebutanPenyetuju();
        }

        return self::labelStatus($status);
    }

    public function statusLabel(): string
    {
        return $this->labelStatusUntuk($this->status);
    }

    /**
     * Sebutan atasan yang menyetujui permintaan ini, menurut unit pemohon.
     */
    public function sebutanPenyetuju(): string
    {
        return $this->user?->unit?->sebutanKepala() ?? 'Kepala Subbagian/Bidang';
    }

    /**
     * Warna badge status, satu sumber untuk daftar, detail, dan dasbor.
     * Tahap yang masih berjalan kuning, yang menunggu pemohon biru.
     */
    public static function statusBadgeClass(?string $status): string
    {
        return match ($status) {
            'submitted' => 'bg-blue-light-50 text-blue-light-600 dark:bg-blue-light-500/15 dark:text-blue-light-500',
            'approved_by_head', 'processed_by_staff', 'in_issuance' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            'delivered' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            'completed' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500',
            'rejected' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
            default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
        };
    }

    /**
     * Ringkasan nama barang untuk baris daftar, mis. "Kertas A4, Pulpen +2".
     */
    public function ringkasanBarang(int $tampil = 2): string
    {
        $names = $this->item
            ->map(fn (RequestDetail $detail) => $detail->item?->item_name ?? $detail->itemProposal?->item_name)
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            return '-';
        }

        $sisa = $names->count() - $tampil;

        return $names->take($tampil)->implode(', ').($sisa > 0 ? " +{$sisa}" : '');
    }

    /**
     * Nomor urut Nota Dinas yang diisi pemohon saat mengajukan: hanya angka.
     */
    public const ND_SEQUENCE_PATTERN = '/^\d{1,6}$/';

    /**
     * Nomor naskah dinas permintaan ini, mis.
     * 51/B/ND/DJPKN-V.BLP.1.4/KEU.02.02/10/2026. `B` adalah sifat surat
     * (biasa), `DJPKN-V` satuan kerja penerbit, dan `KEU.02.02` kode
     * klasifikasi arsip persediaan. Kode unit mengikuti unit pemohon,
     * sedangkan bulan dan tahun mengikuti tanggal penyusunan Nota Dinas.
     * Tanpa nomor urut, bagian depannya dibiarkan "……".
     *
     * @param  'ND'|'BAST'  $kind
     */
    public function documentNumber(string $kind, ?string $sequence = null): string
    {
        return sprintf(
            '%s/B/%s/DJPKN-V.%s/KEU.02.02/%s',
            $sequence ?? '……',
            $kind,
            $this->user?->unit?->kodeNaskah() ?? 'BLP',
            ($this->date ?? now())->format('m/Y'),
        );
    }

    /**
     * Nomor urut dari Nota Dinas yang sudah bernomor.
     */
    public function ndSequence(): ?string
    {
        return $this->nd_number !== null ? Str::before($this->nd_number, '/') : null;
    }

    /**
     * Status satu langkah sebelum status sekarang, tujuan Admin saat
     * mengembalikan status. Null berarti tidak bisa dikembalikan: permintaan
     * yang sudah diserahkan atau selesai (stok sudah keluar dan BAST terbit),
     * masih di tahap awal, permintaan umum, atau ditolak sebelum status
     * asalnya dicatat.
     */
    public function previousStatus(): ?string
    {
        if ($this->type === 'umum') {
            return null;
        }

        return match ($this->status) {
            'approved_by_head' => 'submitted',
            'processed_by_staff' => 'approved_by_head',
            'in_issuance' => 'processed_by_staff',
            'rejected' => $this->rejected_from_status,
            default => null,
        };
    }

    /**
     * Hari sejak barang diserahkan sebelum Pengelola Gudang atau Admin boleh
     * menutup permintaan atas nama pemohon yang tidak kunjung mengonfirmasi.
     */
    public const BATAS_HARI_KONFIRMASI = 7;

    /**
     * Hari sejak diajukan sebelum permintaan yang belum diputuskan Kepala Unit
     * dibatalkan otomatis, supaya stok yang ditahannya kembali tersedia
     * (RequestWorkflowService::expireStale()).
     */
    public const BATAS_HARI_PERSETUJUAN = 14;

    /**
     * Pemohon melaporkan masalah penerimaan yang belum ditanggapi Gudang.
     */
    public function hasOpenReceiptProblem(): bool
    {
        $last = $this->histories()
            ->reorder()
            ->whereIn('action', [RequestHistoryAction::MasalahDilaporkan, RequestHistoryAction::MasalahDitanggapi])
            ->latest('request_history_id')
            ->first();

        return $last?->action === RequestHistoryAction::MasalahDilaporkan;
    }

    /**
     * Saat penutupan atas nama pemohon mulai boleh dilakukan.
     */
    public function closableOnBehalfFrom(): ?Carbon
    {
        return $this->delivered_at?->copy()->addDays(self::BATAS_HARI_KONFIRMASI);
    }

    public function canBeClosedOnBehalf(): bool
    {
        return $this->status === 'delivered'
            && $this->closableOnBehalfFrom()?->lte(now()) === true
            && ! $this->hasOpenReceiptProblem();
    }
}
