<?php

namespace App\Models;

use App\Enums\MutationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
        'mutation_date', 'reference_id', 'reference_type', 'reference_number', 'notes',
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
     * Nomor dokumen sumber untuk ditampilkan; UUID `reference_id` sengaja tidak
     * pernah ditampilkan ke pengguna.
     */
    public function referensi(): string
    {
        return $this->reference_number ?? '—';
    }

    /**
     * Asal mutasi ini: otomatis dari dokumen, atau dicatat tangan.
     *
     * Nilai turunan, bukan kolom database. Membuat pembaca riwayat langsung tahu
     * mana baris yang lahir dari proses sistem dan mana yang perlu diperiksa
     * karena tidak punya dokumen sumber.
     */
    public function sumber(): string
    {
        return match (true) {
            $this->reference_type === 'pengadaan' => 'Pengadaan',
            $this->reference_type === 'permintaan' => 'Permintaan',
            $this->reference_type === 'opname' => 'Stock Opname',
            $this->mutation_type === MutationType::Penyesuaian => 'Stock Opname',
            $this->mutation_type === MutationType::SaldoAwal => 'Saldo Awal',
            $this->mutation_type->isManual() => 'Manual',
            // Cadangan bila dokumen sumber belum tertaut: jenis mutasinya sendiri
            // sudah menyatakan asalnya.
            $this->mutation_type === MutationType::Masuk => 'Pengadaan',
            $this->mutation_type === MutationType::Keluar => 'Permintaan',
            default => 'Sistem',
        };
    }

    /**
     * Kelas badge TailAdmin untuk kolom Sumber.
     */
    public function sumberBadgeClass(): string
    {
        return match ($this->sumber()) {
            'Manual' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
            'Saldo Awal' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
            default => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
        };
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

    /**
     * Dokumen sumber mutasi: Pengadaan, Permintaan, atau Stock Opname.
     *
     * Alias jenis dokumen terdaftar pada morph map di AppServiceProvider.
     * Bernilai null untuk mutasi manual dan saldo awal — itu memang wajar.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
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
