<?php

namespace App\Models;

use App\Enums\MutationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris buku mutasi stok.
 *
 * Baris mutasi bersifat immutable: tidak pernah diubah atau dihapus. Kesalahan
 * pencatatan dikoreksi dengan mutasi lawan berjenis KoreksiPencatatan.
 */
class StockMutation extends Model
{
    use HasUuids;

    protected $primaryKey = 'mutation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'item_id', 'user_id', 'mutation_type', 'amount', 'stock_after',
        'mutation_date', 'reference', 'reference_type', 'reference_number', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'mutation_type' => MutationType::class,
            'amount' => 'integer',
            'stock_after' => 'integer',
            'mutation_date' => 'date',
        ];
    }

    /**
     * Jumlah mutasi dengan tanda eksplisit, mis. "+50" atau "-10".
     */
    public function signedAmount(): string
    {
        return ($this->amount > 0 ? '+' : '').$this->amount;
    }

    /**
     * Nama pencatat. Mutasi dari seeder atau proses sistem tidak punya pengguna.
     */
    public function petugas(): string
    {
        return $this->user?->fullname ?? 'Sistem';
    }

    /**
     * Nomor dokumen sumber untuk ditampilkan; UUID `reference` sengaja tidak
     * pernah ditampilkan ke pengguna.
     */
    public function referensi(): string
    {
        return $this->reference_number ?? '—';
    }

    /**
     * Penyaring bersama yang dipakai layar riwayat, PDF, dan CSV, supaya isi
     * laporan selalu identik dengan yang dilihat pengguna.
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                filled($filters['q'] ?? null),
                fn (Builder $query) => $query->whereHas(
                    'item',
                    fn (Builder $item) => $item->search($filters['q']),
                ),
            )
            ->when(
                MutationType::tryFrom((string) ($filters['type'] ?? '')),
                fn (Builder $query, MutationType $type) => $query->where('mutation_type', $type),
            )
            ->when(
                filled($filters['from'] ?? null),
                fn (Builder $query) => $query->whereDate('mutation_date', '>=', $filters['from']),
            )
            ->when(
                filled($filters['to'] ?? null),
                fn (Builder $query) => $query->whereDate('mutation_date', '<=', $filters['to']),
            );
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
