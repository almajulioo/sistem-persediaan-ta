<?php

namespace App\Support;

use App\Models\ItemApplication;
use App\Models\ItemApplicationDetail;
use App\Models\User;
use DOMElement;
use DOMXPath;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;

/**
 * Nota Dinas permohonan barang baru / tambah stok, dibuat dari
 * resources/document/template-nd-permohonan.docx. Template itu diturunkan dari
 * "ND Surat Permohonan ATK/ARTK" dengan mengganti isinya menjadi placeholder;
 * tata letaknya tidak diubah.
 *
 * Terbit tanpa tanda tangan saat diajukan, lalu dicetak ulang saat disetujui
 * pimpinan unit dengan QR verifikasi di ruang tanda tangannya. Hanya tanda
 * tangan pimpinan unit itu yang ber-QR.
 */
class ItemApplicationDocumentGenerator
{
    /**
     * Sisi QR tanda tangan, 2 cm dalam EMU.
     */
    private const SISI_QR = 720000;

    /**
     * Jarak QR dari margin kiri, supaya tepi kanannya sejajar nama penanda
     * tangan yang rata kanan (indentasi kanan 725 twip, lebar teks 9.280 twip).
     */
    private const KIRI_QR = 4712000;

    private const VARIABEL = [
        'nd_number', 'dari', 'perihal', 'tanggal', 'unit',
        'no', 'nama_barang', 'jumlah',
        'jabatan', 'penanda_nama', 'penanda_nip',
    ];

    public function generateNotaDinas(ItemApplication $application): void
    {
        $this->render($application, null, null);
    }

    public function approveNotaDinas(ItemApplication $application, User $approver): void
    {
        $verificationToken = Str::random(64);
        $application->forceFill(['nd_verification_hash' => hash('sha256', $verificationToken)])->save();

        $qrPng = (new Builder)->build(
            data: route('item-applications.nota-dinas.verify', [
                'itemApplication' => $application,
                'token' => $verificationToken,
            ]),
            // Rendah supaya modulnya lebih besar dan mudah dipindai saat dicetak.
            errorCorrectionLevel: ErrorCorrectionLevel::Low,
            size: 300,
            margin: 10,
        )->getString();

        $this->render($application, $approver, $qrPng);
    }

    private function render(ItemApplication $application, ?User $approver, ?string $qrPng): void
    {
        $application->loadMissing(['user', 'details.item']);

        $unit = $application->user?->unit;
        $jabatan = $unit?->jabatanKepala() ?? 'Kepala Unit';
        // Sebelum disetujui, penanda tangannya pimpinan unit pemohon.
        $penanda = $approver ?? $application->kepalaUnit();

        $template = new TemplateProcessor(resource_path('document/template-nd-permohonan.docx'));
        $kurang = array_diff(self::VARIABEL, $template->getVariables());

        if ($kurang !== []) {
            throw new RuntimeException('Template Nota Dinas permohonan belum lengkap. Placeholder yang belum tersedia: ${'.implode('}, ${', $kurang).'}');
        }

        $template->setValues(array_map(self::xml(...), [
            'nd_number' => $application->nd_number ?? $application->nomorNotaDinas(),
            'dari' => $jabatan,
            'perihal' => $application->perihalNotaDinas(),
            'tanggal' => ($application->date ?? now())->copy()->locale('id')->translatedFormat('j F Y'),
            'unit' => $unit?->label() ?? '-',
            'jabatan' => $jabatan,
            'penanda_nama' => $penanda?->fullname ?? '……',
            'penanda_nip' => $penanda?->nip ?? '……',
        ]));
        $template->cloneRowAndSetValues('no', $application->details->values()->map(
            fn (ItemApplicationDetail $detail, int $i): array => [
                'no' => (string) ($i + 1),
                'nama_barang' => self::xml($detail->namaBarang()),
                'jumlah' => self::xml(trim(number_format($detail->quantity, 0, ',', '.').' '.($detail->satuan()?->label() ?? ''))),
            ],
        )->all());

        $temporaryBasePath = tempnam(sys_get_temp_dir(), 'nd-');
        if ($temporaryBasePath === false) {
            throw new RuntimeException('Lokasi sementara untuk membuat Nota Dinas tidak dapat disiapkan.');
        }
        unlink($temporaryBasePath);
        $docxPath = $temporaryBasePath.'.docx';

        try {
            $template->saveAs($docxPath);

            if ($qrPng !== null) {
                (new DocxQrStamper)->stamp($docxPath, fn (DOMXPath $xpath): array => [[
                    'nama' => 'kepala-unit',
                    'paragraf' => $this->ruangTandaTangan($xpath, $jabatan),
                    'png' => $qrPng,
                    'sisi' => self::SISI_QR,
                    'mendatar' => DocxQrStamper::dariKiri(self::KIRI_QR, 'margin'),
                ]]);
            }

            $docx = file_get_contents($docxPath);
            if ($docx === false || ! Storage::disk('local')->put($application->notaDinasPath(), $docx)) {
                throw new RuntimeException('Nota Dinas permohonan tidak dapat disimpan ke penyimpanan aplikasi.');
            }
        } finally {
            if (is_file($docxPath)) {
                unlink($docxPath);
            }
        }
    }

    /**
     * Paragraf kosong pertama di bawah jabatan penanda tangan.
     */
    private function ruangTandaTangan(DOMXPath $xpath, string $jabatan): DOMElement
    {
        foreach ($xpath->query('//w:body/w:p') as $paragraf) {
            if (trim($paragraf->textContent) === $jabatan.',') {
                $ruang = $xpath->query('following-sibling::w:p[1]', $paragraf)->item(0);

                if ($ruang instanceof DOMElement) {
                    return $ruang;
                }
            }
        }

        throw new RuntimeException('Ruang tanda tangan pada template Nota Dinas permohonan tidak ditemukan.');
    }

    /**
     * PhpWord tidak meng-escape isi secara bawaan, padahal nama barang bisa
     * memuat "&" atau "<" yang merusak DOCX.
     */
    private static function xml(string $teks): string
    {
        return htmlspecialchars($teks, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
