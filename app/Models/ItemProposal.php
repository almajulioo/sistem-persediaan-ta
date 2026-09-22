<?php

namespace App\Models;

use App\Enums\ItemProposalStatus;
use App\Enums\SatuanBarang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Usulan barang baru — pintu depan katalog (ADR 0032).
 *
 * Diajukan oleh siapa pun yang membutuhkan barang yang belum ada di master
 * barang, diputuskan oleh Pengelola Gudang. Menyetujuinya berarti mendaftarkan
 * barangnya lewat ItemController::store(), yang sekaligus mengisi `item_id` di
 * sini — tidak ada persetujuan yang berdiri sendiri tanpa baris `items`, karena
 * persetujuan semacam itu tidak berarti apa pun.
 */
class ItemProposal extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'item_proposal_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'item_name', 'unit', 'purpose_note', 'proposed_by', 'status',
    ];

    /**
     * Tidak fillable, ditulis hanya lewat forceFill saat keputusan dibuat:
     * `item_id`, `status`, `decided_by`, `decided_at`, `decision_note`.
     */
    protected function casts(): array
    {
        return [
            'unit' => SatuanBarang::class,
            'status' => ItemProposalStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * Judul singkat untuk tampilan dan notifikasi.
     *
     * Memakai nama barangnya, bukan prefiks UUID seperti Procurement: di sini
     * namanya justru identitas yang dikenali orang, dan nama itulah yang
     * sedang dipersoalkan.
     */
    public function judul(): string
    {
        return "Usulan barang: {$this->item_name}";
    }

    public function isDiajukan(): bool
    {
        return $this->status === ItemProposalStatus::Diajukan;
    }

    public function isDisetujui(): bool
    {
        return $this->status === ItemProposalStatus::Disetujui;
    }

    public function isDitolak(): bool
    {
        return $this->status === ItemProposalStatus::Ditolak;
    }

    public function sudahDiputuskan(): bool
    {
        return ! $this->isDiajukan();
    }

    /**
     * @param  array<string, string>  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when(
                ItemProposalStatus::tryFrom((string) ($filters['status'] ?? '')),
                fn (Builder $query, ItemProposalStatus $status) => $query->where('status', $status),
            )
            ->when(
                trim((string) ($filters['q'] ?? '')),
                fn (Builder $query, string $term) => $query->where('item_name', 'like', "%{$term}%"),
            );
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by', 'user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by', 'user_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }

    public function requestDetails(): HasMany
    {
        return $this->hasMany(RequestDetail::class, 'item_proposal_id', 'item_proposal_id');
    }
}
