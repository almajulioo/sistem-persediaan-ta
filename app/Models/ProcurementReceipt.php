<?php

namespace App\Models;

use App\Enums\PihakBast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Penerimaan barang pengadaan oleh gudang, sekaligus BAST-nya.
 *
 * Sejak ADR 0051 barisnya lahir lebih awal: saat Pengelola Gudang mencatat
 * **hasil pemeriksaan** (`received_by`, `received_at`, `notes`, dan
 * `procurement_details.amount_checked`). Itu masih draf — stok baru bertambah
 * setelah kedua pihak membubuhkan TTE (`signatures`), dan saat itulah
 * realisasinya berstatus `Diterima`. Jadi "ada baris penerimaan" tidak lagi
 * berarti "barang sudah diterima"; tanyakan status realisasinya.
 *
 * `content`/`content_hash` adalah isi BAST yang dibekukan saat TTE pertama,
 * dan dikosongkan lagi bila TTE dicabut. Selama kosong, BAST dirender dari
 * data hidup.
 *
 * BAST-nya serah terima internal dari Pelaksana Belanja kepada Petugas
 * Persediaan, bukan dari penyedia (ADR 0042). `received_by` sengaja terpisah
 * dari `Procurement::user_id` — siapa yang mengadakan bukan siapa yang
 * memeriksa barangnya (ADR 0008, ADR 0015).
 *
 * BAST lama — sudah `Diterima` sebelum ADR 0051, tanpa TTE — tetap berjalan
 * dengan cara ADR 0042: dicetak, ditandatangani basah, lalu pindaiannya
 * diunggah ke `procurement_documents` (ADR 0018).
 */
class ProcurementReceipt extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_receipt_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'procurement_id', 'bast_number', 'received_by', 'received_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'content' => 'array',
        ];
    }

    /**
     * Sidik SHA-256 isi BAST.
     *
     * Kunci objek diurutkan lebih dulu, bertingkat: kolom JSON MySQL menyimpan
     * objek dalam bentuk biner dan mengurutkan ulang kuncinya, sehingga JSON
     * yang dibaca kembali tidak selalu sama persis bunyinya dengan yang
     * ditulis. Urutan daftar (rincian barang) tetap dipertahankan.
     *
     * @param  array<mixed>  $isi
     */
    public static function sidik(array $isi): string
    {
        return hash('sha256', json_encode(
            self::urutkanKunci($isi),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  array<mixed>  $nilai
     * @return array<mixed>
     */
    private static function urutkanKunci(array $nilai): array
    {
        if (! array_is_list($nilai)) {
            ksort($nilai, SORT_STRING);
        }

        return array_map(fn ($isi) => is_array($isi) ? self::urutkanKunci($isi) : $isi, $nilai);
    }

    public function sudahDibekukan(): bool
    {
        return $this->content !== null && $this->content_hash !== null;
    }

    /**
     * Isi yang dibekukan masih cocok dengan sidiknya. Bila tidak, ada yang
     * mengubah baris ini di luar aplikasi, dan tidak satu pun TTE-nya sah
     * (PP 71/2019 Pasal 60: perubahan setelah penandatanganan harus dapat
     * diketahui).
     */
    public function isiUtuh(): bool
    {
        return $this->sudahDibekukan() && hash_equals($this->content_hash, self::sidik($this->content));
    }

    /**
     * TTE yang berlaku untuk satu pihak, atau null.
     */
    public function tteAktif(PihakBast $pihak): ?ProcurementReceiptSignature
    {
        return $this->signatures
            ->first(fn (ProcurementReceiptSignature $ttd) => $ttd->party === $pihak && ! $ttd->dicabut());
    }

    public function jumlahTteAktif(): int
    {
        return $this->signatures->reject(fn (ProcurementReceiptSignature $ttd) => $ttd->dicabut())->count();
    }

    /**
     * BAST ini lahir di alur TTE (ADR 0051). BAST lama tidak pernah punya baris
     * tanda tangan sama sekali, dicabut maupun tidak.
     */
    public function bertandaTanganElektronik(): bool
    {
        return $this->signatures->isNotEmpty();
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(Procurement::class, 'procurement_id', 'procurement_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by', 'user_id');
    }

    /**
     * Seluruh TTE, termasuk yang sudah dicabut, urut waktu.
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(ProcurementReceiptSignature::class, 'procurement_receipt_id', 'procurement_receipt_id')
            ->orderBy('signed_at')
            ->orderBy('signature_id');
    }
}
