<?php

namespace App\Models;

use App\Enums\ItemApplicationStatus;
use App\Enums\ItemApplicationType;
use App\Enums\UnitKerja;
use App\Enums\UserRole;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permohonan barang baru dan/atau tambah stok dari unit kerja.
 *
 * Berbeda dari permintaan (`requests`) yang meminta barang keluar dari gudang,
 * permohonan meminta gudang mengadakan barang: menambah stok barang yang sudah
 * terdaftar, atau mendaftarkan barang yang belum ada. Disetujui berurutan oleh
 * Kepala Unit, Pengelola Gudang, dan Kasubbag Umum dan TI; baru setelah
 * keputusan akhir itu barisnya diteruskan lewat
 * App\Services\ItemApplicationWorkflowService.
 */
class ItemApplication extends Model
{
    use HasUuids;

    protected $primaryKey = 'item_application_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'application_number',
        'nd_number',
        'user_id',
        'date',
        'purpose',
        'status',
    ];

    /**
     * Tidak fillable, ditulis hanya lewat forceFill saat keputusan dibuat:
     * `head_approved_by`, `head_approved_at`, `reviewed_by`, `reviewed_at`,
     * `decided_by`, `decided_at`, `decision_note`, `nd_verification_hash`.
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => ItemApplicationStatus::class,
            'head_approved_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function isDiajukan(): bool
    {
        return $this->status === ItemApplicationStatus::Diajukan;
    }

    public function isMenunggu(): bool
    {
        return $this->status->menunggu();
    }

    public function isDisetujui(): bool
    {
        return $this->status === ItemApplicationStatus::Disetujui;
    }

    public function isDitolak(): bool
    {
        return $this->status === ItemApplicationStatus::Ditolak;
    }

    /**
     * Apakah sekarang giliran akun ini memutuskan. Admin lolos di setiap tahap,
     * dua tahap terakhir lewat Gate::before.
     */
    public function bisaDiputuskanOleh(User $user): bool
    {
        return match ($this->status) {
            ItemApplicationStatus::Diajukan => $user->isAdmin() || $this->dipimpinOleh($user),
            ItemApplicationStatus::DisetujuiKepalaUnit => $user->can('kelola-permintaan'),
            ItemApplicationStatus::DitinjauGudang => $user->can('setujui-permohonan'),
            default => false,
        };
    }

    private function dipimpinOleh(User $user): bool
    {
        $unit = $this->user?->unit;

        return $unit !== null && $user->unit === $unit && $user->hasRole(self::peranPimpinan($unit));
    }

    /**
     * Sama dengan Request::kepalaUnit(): Subbagian Umum dan TI dipimpin
     * Kasubbag Umum dan TI, bukan peran Kepala Unit.
     */
    private static function peranPimpinan(UnitKerja $unit): UserRole
    {
        return $unit === UnitKerja::SubbagianUmumTi ? UserRole::KasubbagUmumTi : UserRole::KepalaUnit;
    }

    /**
     * Akun yang berhak menyetujui di tahap pertama, yaitu pimpinan unit pemohon.
     *
     * @return Collection<int, User>
     */
    public function penyetujuUnit(): Collection
    {
        $unit = $this->user?->unit;

        if ($unit === null) {
            return new Collection;
        }

        return User::query()
            ->where('is_active', true)
            ->where('role', self::peranPimpinan($unit)->value)
            ->where('unit', $unit)
            ->get();
    }

    /**
     * Penanda tangan Nota Dinas selama belum disetujui.
     */
    public function kepalaUnit(): ?User
    {
        return $this->penyetujuUnit()->first();
    }

    /**
     * Tiga tahap persetujuan berurutan. Tahap pertama yang belum selesai adalah
     * yang sedang menunggu, atau yang menolak bila permohonannya ditolak.
     *
     * @return list<array{label: string, oleh: ?User, pada: ?\Illuminate\Support\Carbon, keadaan: 'selesai'|'menunggu'|'ditolak'|'belum'}>
     */
    public function tahapan(): array
    {
        $tahap = [
            ['label' => $this->user?->unit?->sebutanKepala() ?? 'Kepala Unit', 'oleh' => $this->headApprovedBy, 'pada' => $this->head_approved_at],
            ['label' => 'Pengelola Gudang', 'oleh' => $this->reviewedBy, 'pada' => $this->reviewed_at],
            ['label' => 'Kasubbag Umum dan TI', 'oleh' => null, 'pada' => null],
        ];

        if ($this->isDisetujui()) {
            $tahap[2] = [...$tahap[2], 'oleh' => $this->decidedBy, 'pada' => $this->decided_at];
        }

        $sudahBerhenti = false;

        return array_map(function (array $baris) use (&$sudahBerhenti): array {
            if ($baris['pada'] !== null) {
                return [...$baris, 'keadaan' => 'selesai'];
            }

            if ($sudahBerhenti) {
                return [...$baris, 'keadaan' => 'belum'];
            }

            $sudahBerhenti = true;

            return $this->isDitolak()
                ? [...$baris, 'oleh' => $this->decidedBy, 'pada' => $this->decided_at, 'keadaan' => 'ditolak']
                : [...$baris, 'keadaan' => 'menunggu'];
        }, $tahap);
    }

    /**
     * Nomor naskah dinas permohonan ini, lihat formatNomorNotaDinas().
     */
    public function nomorNotaDinas(?string $sequence = null): string
    {
        return self::formatNomorNotaDinas($this->user?->unit, $this->date ?? now(), $sequence);
    }

    /**
     * Format nomor Nota Dinas Permohonan ATK/ARTK, mis.
     * 51/B/ND/DJPKN-V.BLP.1.2/BMN.09.03/5/2026. Berbeda dari permintaan
     * (Request::documentNumber(): KEU.02.02, bulan dua digit) karena klasifikasi
     * arsipnya barang milik negara. Tanpa nomor urut, bagian depannya "……".
     */
    public static function formatNomorNotaDinas(?UnitKerja $unit, CarbonInterface $tanggal, ?string $sequence = null): string
    {
        return sprintf(
            '%s/B/ND/DJPKN-V.%s/BMN.09.03/%s',
            $sequence ?? '……',
            $unit?->kodeNaskah() ?? 'BLP',
            $tanggal->format('n/Y'),
        );
    }

    public function notaDinasPath(): string
    {
        return 'nota-dinas-permohonan/'.$this->item_application_id.'.docx';
    }

    /**
     * Perihal Nota Dinas, mis. "Permohonan ATK/ARTK Subbagian SDM Bulan Mei
     * 2026". Bulannya berbahasa Indonesia apa pun bahasa aplikasinya.
     */
    public function perihalNotaDinas(): string
    {
        return sprintf(
            'Permohonan ATK/ARTK %s Bulan %s',
            $this->user?->unit?->label() ?? '-',
            ($this->date ?? now())->copy()->locale('id')->translatedFormat('F Y'),
        );
    }

    /**
     * Ringkasan isi untuk daftar dan notifikasi, mis. "2 tambah stok, 1 barang baru".
     */
    public function ringkasan(): string
    {
        return collect($this->jumlahPerJenis())
            ->map(fn (array $baris) => $baris['jumlah'].' '.strtolower($baris['jenis']->label()))
            ->implode(', ');
    }

    /**
     * Banyak baris per jenis, urut sesuai urutan kemunculannya, untuk badge
     * jenis di daftar dan kotak ikon yang mencerminkan isi permohonan.
     *
     * @return list<array{jenis: ItemApplicationType, jumlah: int}>
     */
    public function jumlahPerJenis(): array
    {
        $details = $this->relationLoaded('details') ? $this->details : $this->details()->get();

        return $details
            ->countBy(fn (ItemApplicationDetail $detail) => $detail->type->value)
            ->map(fn (int $jumlah, string $type) => ['jenis' => ItemApplicationType::from($type), 'jumlah' => $jumlah])
            ->values()
            ->all();
    }

    /**
     * Ringkasan nama barang untuk baris daftar, mis. "Kertas A4, Spidol Emas +1".
     */
    public function ringkasanBarang(int $tampil = 2): string
    {
        $details = $this->relationLoaded('details') ? $this->details : $this->details()->with('item')->get();
        $names = $details->map(fn (ItemApplicationDetail $detail) => $detail->namaBarang())->values();

        if ($names->isEmpty()) {
            return '-';
        }

        $sisa = $names->count() - $tampil;

        return $names->take($tampil)->implode(', ').($sisa > 0 ? " +{$sisa}" : '');
    }

    /**
     * Sama dengan Request::scopeTerlihatOleh(): Kepala Unit hanya unitnya,
     * Pengguna hanya miliknya sendiri, peran lain seluruhnya.
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
     * @param  array<string, string>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                ItemApplicationStatus::tryFrom((string) ($filters['status'] ?? '')),
                fn (Builder $query, ItemApplicationStatus $status) => $query->where('status', $status),
            )
            ->when(
                trim((string) ($filters['q'] ?? '')),
                fn (Builder $query, string $term) => $query->where(function (Builder $query) use ($term) {
                    $query->where('application_number', 'like', "%{$term}%")
                        ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('fullname', 'like', "%{$term}%"));
                }),
            );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function headApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_approved_by', 'user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ItemApplicationDetail::class, 'item_application_id', 'item_application_id');
    }
}
