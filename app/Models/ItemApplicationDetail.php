<?php

namespace App\Models;

use App\Enums\ItemApplicationType;
use App\Enums\SatuanBarang;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemApplicationDetail extends Model
{
    protected $primaryKey = 'item_application_detail_id';

    protected $fillable = [
        'item_application_id',
        'type',
        'item_id',
        'item_name',
        'unit',
        'note',
        'quantity',
        'item_proposal_id',
        'procurement_request_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ItemApplicationType::class,
            'unit' => SatuanBarang::class,
            'quantity' => 'integer',
        ];
    }

    public function isTambahStok(): bool
    {
        return $this->type === ItemApplicationType::TambahStok;
    }

    public function isBarangBaru(): bool
    {
        return $this->type === ItemApplicationType::BarangBaru;
    }

    /**
     * Nama yang ditampilkan: nama di master untuk tambah stok, nama yang
     * diketik pemohon untuk barang baru.
     */
    public function namaBarang(): string
    {
        return $this->item?->item_name ?? $this->item_name ?? '-';
    }

    public function satuan(): ?SatuanBarang
    {
        return $this->item?->unit ?? $this->unit;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ItemApplication::class, 'item_application_id', 'item_application_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'item_id');
    }

    public function itemProposal(): BelongsTo
    {
        return $this->belongsTo(ItemProposal::class, 'item_proposal_id', 'item_proposal_id');
    }

    public function procurementRequest(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequest::class, 'procurement_request_id', 'procurement_request_id');
    }
}
