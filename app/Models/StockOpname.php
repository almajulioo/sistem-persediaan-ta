<?php

namespace App\Models;

use App\Enums\OpnameStatus;
use App\Enums\PeriodeOpname;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen stok opname untuk satu periode.
 *
 * Berstatus Draf selama pencacahan berlangsung, lalu menjadi Selesai setelah
 * penyesuaian diposting ke buku mutasi. Setelah itu bersifat final.
 */
class StockOpname extends Model
{
    use HasUuids;

    protected $primaryKey = 'opname_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'opname_date', 'user_id', 'status', 'period_type', 'period_year', 'notes', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'opname_date' => 'date',
            'status' => OpnameStatus::class,
            'period_type' => PeriodeOpname::class,
            'period_year' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * Judul dokumen, mis. "Stok Opname Triwulan III 2026".
     *
     * Dipakai sebagai judul laporan cetak sekaligus `reference_number` pada
     * mutasi penyesuaian yang dihasilkan.
     */
    public function judul(): string
    {
        return sprintf('Stok Opname %s %d', $this->period_type->label(), $this->period_year);
    }

    public function isDraf(): bool
    {
        return $this->status === OpnameStatus::Draf;
    }

    public function isSelesai(): bool
    {
        return $this->status === OpnameStatus::Selesai;
    }

    /**
     * Ringkasan hasil pencacahan.
     *
     * @return array<string, int>
     */
    public function ringkasan(): array
    {
        $details = $this->relationLoaded('details') ? $this->details : $this->details()->get();

        $dicacah = $details->filter(fn (OpnameDetail $d) => $d->sudahDicacah());

        return [
            'barang' => $details->count(),
            'dicacah' => $dicacah->count(),
            'belum_dicacah' => $details->count() - $dicacah->count(),
            'selisih' => $dicacah->filter(fn (OpnameDetail $d) => $d->adaSelisih())->count(),
            'lebih' => (int) $dicacah->where('difference', '>', 0)->sum('difference'),
            'kurang' => (int) abs($dicacah->where('difference', '<', 0)->sum('difference')),
        ];
    }

    /**
     * Apakah seluruh barang sudah dicacah — syarat untuk diposting.
     */
    public function siapDiposting(): bool
    {
        return $this->isDraf() && $this->details()->whereNull('stock_real')->doesntExist();
    }

    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                PeriodeOpname::tryFrom((string) ($filters['period'] ?? '')),
                fn (Builder $query, PeriodeOpname $periode) => $query->where('period_type', $periode),
            )
            ->when(
                filled($filters['year'] ?? null),
                fn (Builder $query) => $query->where('period_year', (int) $filters['year']),
            )
            ->when(
                OpnameStatus::tryFrom((string) ($filters['status'] ?? '')),
                fn (Builder $query, OpnameStatus $status) => $query->where('status', $status),
            );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(OpnameDetail::class, 'opname_id', 'opname_id');
    }

    /**
     * Mutasi penyesuaian yang lahir dari posting opname ini.
     */
    public function mutations(): HasMany
    {
        return $this->hasMany(StockMutation::class, 'reference_id', 'opname_id')
            ->where('reference_type', 'opname');
    }
}
