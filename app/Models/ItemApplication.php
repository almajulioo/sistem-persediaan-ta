<?php

namespace App\Models;

use App\Enums\ItemApplicationStatus;
use App\Enums\ItemApplicationType;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Permohonan barang baru dan/atau tambah stok dari unit kerja.
 *
 * Berbeda dari permintaan (`requests`) yang meminta barang keluar dari gudang,
 * permohonan meminta gudang mengadakan barang: menambah stok barang yang sudah
 * terdaftar, atau mendaftarkan barang yang belum ada. Setelah disetujui
 * Pengelola Gudang, barisnya diteruskan lewat App\Services\ItemApplicationWorkflowService.
 */
class ItemApplication extends Model
{
    use HasUuids;

    protected $primaryKey = 'item_application_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'application_number',
        'user_id',
        'date',
        'purpose',
        'status',
    ];

    /**
     * Tidak fillable, ditulis hanya lewat forceFill saat keputusan dibuat:
     * `decided_by`, `decided_at`, `decision_note`.
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => ItemApplicationStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function isDiajukan(): bool
    {
        return $this->status === ItemApplicationStatus::Diajukan;
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
     * Ringkasan isi untuk daftar dan notifikasi, mis. "2 tambah stok, 1 barang baru".
     */
    public function ringkasan(): string
    {
        $details = $this->relationLoaded('details') ? $this->details : $this->details()->get();

        return $details
            ->countBy(fn (ItemApplicationDetail $detail) => $detail->type->value)
            ->map(fn (int $jumlah, string $type) => $jumlah.' '.strtolower(ItemApplicationType::from($type)->label()))
            ->implode(', ');
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

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ItemApplicationDetail::class, 'item_application_id', 'item_application_id');
    }
}
