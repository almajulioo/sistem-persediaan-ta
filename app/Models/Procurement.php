<?php

namespace App\Models;

use App\Enums\ProcurementStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Realisasi pengadaan — lahir begitu satu ProcurementRequest disetujui.
 *
 * Berhenti di status MenungguPenerimaan sampai Pengelola Gudang
 * mengonfirmasi penerimaan lewat ProcurementReceipt; baru saat itu stok
 * bertambah. Lihat ADR 0008 dan ADR 0015.
 */
class Procurement extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'procurement_request_id', 'procurement_date', 'status', 'additional_doc', 'user_id', 'supplier',
    ];

    protected function casts(): array
    {
        return [
            'procurement_date' => 'date',
            'status' => ProcurementStatus::class,
        ];
    }

    public function isMenungguPenerimaan(): bool
    {
        return $this->status === ProcurementStatus::MenungguPenerimaan;
    }

    public function isDiterima(): bool
    {
        return $this->status === ProcurementStatus::Diterima;
    }

    public function procurementRequest(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class, 'procurement_request_id', 'procurement_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(ProcurementDetail::class, 'procurement_id', 'procurement_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(ProcurementReceipt::class, 'procurement_id', 'procurement_id');
    }
}
