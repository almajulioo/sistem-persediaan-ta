<?php

namespace App\Models;

use App\Enums\PihakBast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu tanda tangan elektronik (TTE) pada BAST pengadaan (ADR 0051).
 *
 * Barisnya adalah pernyataan yang berdiri sendiri: siapa (identitasnya disalin
 * saat TTE), sebagai pihak mana, kapan, dan atas isi BAST yang mana
 * (`content_hash`). QR di BAST menunjuk `verification_code`-nya.
 *
 * Tidak pernah dihapus. TTE yang gugur karena datanya diubah ditandai
 * `revoked_at` beserta alasannya, sehingga QR pada cetakan lama tetap bisa
 * menjelaskan bahwa tanda tangannya tidak berlaku lagi.
 */
class ProcurementReceiptSignature extends Model
{
    use HasUuids;

    protected $primaryKey = 'signature_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'procurement_receipt_id', 'party', 'user_id',
        'signer_name', 'signer_nip', 'signer_position',
        'content_hash', 'verification_code', 'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'party' => PihakBast::class,
            'signed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function dicabut(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * TTE sah: tidak dicabut, dan isi yang ditandatanganinya masih isi BAST
     * yang berlaku. Pemeriksaan sidiknya sendiri ada di
     * `ProcurementReceipt::isiUtuh()`.
     */
    public function sah(): bool
    {
        $receipt = $this->receipt;

        return ! $this->dicabut()
            && $receipt !== null
            && $receipt->content_hash !== null
            && hash_equals($receipt->content_hash, $this->content_hash)
            && $receipt->isiUtuh();
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ProcurementReceipt::class, 'procurement_receipt_id', 'procurement_receipt_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
