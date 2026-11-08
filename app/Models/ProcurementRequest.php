<?php

namespace App\Models;

use App\Enums\ProcurementRequestSource;
use App\Enums\ProcurementRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * Usulan pengadaan — permintaan dari Gudang kepada Pejabat Pengadaan.
 *
 * Tidak berelasi ke sub sistem Permintaan lewat foreign key di tabel ini.
 * Asal setiap barang disimpan per baris rincian (ADR 0044); barang yang
 * datang dari permohonan pengguna ditunjuk balik oleh
 * `item_application_details.procurement_request_id` milik sub sistem itu.
 */
class ProcurementRequest extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_request_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProcurementRequestStatus::class,
        ];
    }

    /**
     * Judul singkat untuk tampilan dan notifikasi, mis. "Usulan #A1B2C3D4".
     */
    public function judul(): string
    {
        return 'Usulan #'.strtoupper(substr($this->procurement_request_id, 0, 8));
    }

    public function isDiajukan(): bool
    {
        return $this->status === ProcurementRequestStatus::Diajukan;
    }

    public function isDisetujuiMenungguRealisasi(): bool
    {
        return $this->status === ProcurementRequestStatus::DisetujuiMenungguRealisasi;
    }

    public function isDisetujuiSelesai(): bool
    {
        return $this->status === ProcurementRequestStatus::DisetujuiSelesai;
    }

    /**
     * Salah satu dari dua rupa "disetujui" — dipakai tempat yang cuma peduli
     * keputusan Pejabat Pengadaan-nya positif, tanpa peduli sudah
     * direalisasikan atau belum (ADR 0028).
     */
    public function sudahDisetujui(): bool
    {
        return $this->status->sudahDisetujui();
    }

    public function isDitolak(): bool
    {
        return $this->status === ProcurementRequestStatus::Ditolak;
    }

    public function isDraf(): bool
    {
        return $this->status === ProcurementRequestStatus::Draf;
    }

    /**
     * Ringkasan rincian barang, dipakai tampilan daftar dan isi notifikasi.
     *
     * `amount_granted` null (belum diputuskan) dihitung 0 pada jumlah disetujui.
     *
     * @return array<string, int>
     */
    public function ringkasan(): array
    {
        $details = $this->relationLoaded('details') ? $this->details : $this->details()->get();

        return [
            'barang' => $details->count(),
            'barang_disetujui' => $details->filter(fn (ProcurementRequestDetail $detail) => $detail->disetujui())->count(),
            'diminta' => (int) $details->sum('amount_request'),
            'disetujui' => (int) $details->sum(fn (ProcurementRequestDetail $detail) => $detail->amount_granted ?? 0),
        ];
    }

    /**
     * Banyak barang per sumber, urut sesuai urutan kasus enum dan hanya yang
     * ada isinya, untuk chip ringkasan di detail usulan dan banner draf.
     *
     * @return list<array{sumber: ProcurementRequestSource, jumlah: int}>
     */
    public function jumlahPerSumber(): array
    {
        $details = $this->relationLoaded('details') ? $this->details : $this->details()->get();
        $jumlah = $details->countBy(fn (ProcurementRequestDetail $detail) => $detail->source->value);

        return collect(ProcurementRequestSource::cases())
            ->filter(fn (ProcurementRequestSource $sumber) => $jumlah->has($sumber->value))
            ->map(fn (ProcurementRequestSource $sumber) => ['sumber' => $sumber, 'jumlah' => $jumlah->get($sumber->value)])
            ->values()
            ->all();
    }

    public function scopeDraf(Builder $query): Builder
    {
        return $query->where('status', ProcurementRequestStatus::Draf);
    }

    /**
     * Usulan sungguhan — mengecualikan draf otomatis yang belum diajukan
     * (ADR 0027). Dipakai daftar dan ringkasan usulan supaya draf tidak ikut
     * terhitung sebagai dokumen usulan.
     */
    public function scopeBukanDraf(Builder $query): Builder
    {
        return $query->where('status', '!=', ProcurementRequestStatus::Draf);
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query->when(
            ProcurementRequestStatus::tryFrom((string) ($filters['status'] ?? '')),
            fn (Builder $query, ProcurementRequestStatus $status) => $query->where('status', $status),
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ProcurementRequestDetail::class, 'procurement_request_id', 'procurement_request_id');
    }

    public function procurement(): HasOne
    {
        return $this->hasOne(Procurement::class, 'procurement_request_id', 'procurement_request_id');
    }

    /**
     * Baris permohonan pengguna yang diteruskan ke usulan ini. Satu usulan
     * bisa menampung beberapa permohonan, jadi asalnya ditunjuk per barang.
     */
    public function applicationDetails(): HasMany
    {
        return $this->hasMany(ItemApplicationDetail::class, 'procurement_request_id', 'procurement_request_id');
    }

    /**
     * Permohonan yang menyumbang setiap barang usulan ini, dikelompokkan per
     * `item_id`, untuk ditampilkan di draf dan detail usulan.
     *
     * @return Collection<string, Collection<int, ItemApplicationDetail>>
     */
    public function permohonanPerBarang(): Collection
    {
        return $this->applicationDetails()
            ->with('application.user')
            ->get()
            ->groupBy('item_id');
    }
}
