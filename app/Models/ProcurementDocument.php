<?php

namespace App\Models;

use App\Enums\ProcurementDocumentType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Berkas pendukung sebuah pengadaan.
 *
 * `file_path` menunjuk berkas di disk privat, bukan URL publik. Berkasnya
 * hanya dapat diunduh lewat rute terautentikasi. Lihat ADR 0018.
 */
class ProcurementDocument extends Model
{
    use HasUuids;

    protected $primaryKey = 'procurement_document_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'procurement_id', 'type', 'file_path', 'original_name', 'file_size', 'mime_type', 'uploaded_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProcurementDocumentType::class,
            'file_size' => 'integer',
        ];
    }

    /**
     * Ukuran berkas yang terbaca manusia, untuk ditampilkan di daftar dokumen.
     */
    public function ukuranTerbaca(): string
    {
        $bytes = $this->file_size;

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 0, ',', '.').' KB';
        }

        return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
    }

    /**
     * Jenis isi yang aman ditampilkan langsung di peramban: gambar raster dan
     * PDF. HEIC tidak termasuk karena kebanyakan peramban belum dapat
     * membukanya — berkas seperti itu tetap diunduh. `mime_type` berasal dari
     * isi berkas yang diperiksa saat unggah, bukan dari ekstensi kiriman
     * klien. Lihat ADR 0035.
     */
    public function dapatDitampilkan(): bool
    {
        return in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);
    }

    public function procurement(): BelongsTo
    {
        return $this->belongsTo(Procurement::class, 'procurement_id', 'procurement_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by', 'user_id');
    }
}
