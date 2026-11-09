{{-- Berita Acara Serah Terima Barang Persediaan dengan format resmi BPK
     Perwakilan Provinsi Lampung (ADR 0042). Halaman 1 menyalin format aslinya
     persis, kalimatnya juga; rincian barang ada di lampiran halaman 2.

     Sejak ADR 0051 ditandatangani elektronik: kolom tanda tangan yang sudah
     dibubuhkan TTE berisi QR yang menunjuk halaman verifikasi. Datanya datang
     dari satu view-model (`BastPengadaan::untukCetak()`): data hidup selama
     belum ada TTE, isi yang dibekukan setelahnya. Versi "kosong" memakai
     template yang sama tanpa QR, untuk tanda tangan basah.

     Serah terimanya internal: PIHAK PERTAMA adalah Pelaksana Belanja dan PIHAK
     KEDUA adalah Petugas Persediaan. Penyedia tidak ikut menandatangani.

     Seluruh jumlah di lampiran adalah yang benar-benar datang, bukan `amount`
     yang dipesan (ADR 0017). Yang belum terisi — nomor, tanggal, jumlah —
     dicetak sebagai titik-titik, supaya BAST tetap bisa dilihat di setiap
     tahap dan tetap bisa diisi tangan.

     CSS-nya sengaja berdiri sendiri, bukan `pdf._style`: dokumen dinas ini
     memakai Times 12 pt dan margin resmi, sedangkan `_style` melayani laporan
     (dan BAST permintaan milik Alma masih bergantung padanya). Times di sini
     adalah font inti PDF bawaan dompdf, yang metriknya sama dengan Times New
     Roman — tidak perlu menyematkan berkas font. --}}
@php
    $isi = $bast['isi'];
    $kosong = '..............................................';
    $titik = '..........';

    $nomor = $isi['nomor'] ?? null;
    $tanggal = \App\Support\BastPengadaan::tanggal($isi['tanggal'] ?? null);
    $tanggalRealisasi = \App\Support\BastPengadaan::tanggal($isi['realisasi']['tanggal'] ?? null);

    $kalimatTanggal = $tanggal === null
        ? "{$titik} tanggal {$titik} bulan {$titik} tahun {$titik}"
        : sprintf(
            '%s tanggal %s bulan %s tahun %s',
            $tanggal->translatedFormat('l'),
            ucfirst(\App\Support\Terbilang::angka($tanggal->day)),
            $tanggal->translatedFormat('F'),
            ucfirst(\App\Support\Terbilang::angka($tanggal->year)),
        );

    $pihak = [
        ['nomor' => '1.', 'data' => $bast['pihak']['pertama']],
        ['nomor' => '2.', 'data' => $bast['pihak']['kedua']],
    ];

    // Data URI, bukan path berkas: dompdf membatasi berkas lokal pada
    // chroot-nya, dan data URI tidak bergantung pada konfigurasi itu.
    $logo = 'data:image/jpeg;base64,'.base64_encode(file_get_contents(public_path('images/logo_bpk_kop.jpeg')));

    $rincian = collect($isi['rincian'] ?? []);
    // Sebelum hasil pemeriksaan lengkap, seluruh barang dicantumkan dengan
    // jumlah kosong. Sesudahnya, hanya barang yang memang datang (ADR 0042).
    $terisi = $rincian->isNotEmpty() && $rincian->every(fn (array $baris) => $baris['diterima'] !== null);
    $baris = $terisi ? $rincian->filter(fn (array $baris) => $baris['diterima'] > 0)->values() : $rincian;
    $kurangKirim = $terisi ? $rincian->filter(fn (array $baris) => $baris['diterima'] < $baris['dipesan']) : collect();
    $total = $terisi ? $rincian->sum(fn (array $baris) => $baris['diterima'] * $baris['harga']) : null;
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>BAST {{ $nomor ?? $isi['realisasi']['judul'] }}</title>
    <style>
        @page { margin: 1.5cm 2cm; }

        body {
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            line-height: 1.2;
            color: #000;
            margin: 0;
        }

        p { margin: 0; }

        .kop { text-align: center; line-height: 1.1; }
        .kop img { height: 2.5cm; }
        .kop .lembaga { font-size: 16pt; font-weight: bold; margin-top: 3pt; }
        .kop .satker { font-size: 14pt; font-weight: bold; }
        .kop .alamat { font-size: 10pt; }
        .garis-kop { border-top: 2.25pt solid #000; margin-top: 3pt; }

        .judul { margin-top: 20pt; text-align: center; font-weight: bold; text-decoration: underline; }
        .tengah { text-align: center; }
        .blok { margin-top: 11pt; }
        .isi { line-height: 1.15; }
        .rata { text-align: justify; }
        .menjorok { text-indent: 1.27cm; }
        .utuh { white-space: nowrap; }

        table.pihak { border-collapse: collapse; }
        table.pihak td { padding: 0; vertical-align: top; }
        table.pihak .no { width: 0.65cm; }
        table.pihak .label { width: 1.95cm; }
        table.pihak .titik { width: 0.3cm; }

        table.ttd { width: 100%; border-collapse: collapse; }
        table.ttd td { width: 50%; padding: 0; text-align: center; vertical-align: top; }
        table.ttd .ruang { height: 2.5cm; }
        /* QR TTE muat di ruang tanda tangan yang sama, supaya tata letak
           halaman 1 tidak bergeser antara BAST kosong dan yang ber-TTE. */
        table.ttd .qr { width: 1.9cm; height: 1.9cm; margin-top: 0.1cm; }
        table.ttd .ket-tte { font-size: 7.5pt; line-height: 1.1; }
        .nama-ttd { font-weight: bold; text-decoration: underline; }
        .catatan-tte { margin-top: 14pt; font-size: 9pt; font-style: italic; text-align: center; }

        /* --- Lampiran --- */
        .lampiran { page-break-before: always; }

        table.kepala-lampiran { width: 100%; border-collapse: collapse; }
        table.kepala-lampiran td { padding: 0; vertical-align: top; }
        table.kepala-lampiran .label { width: 1.7cm; }
        table.kepala-lampiran .titik { width: 0.3cm; }

        table.identitas { border-collapse: collapse; }
        table.identitas td { padding: 0; vertical-align: top; }
        table.identitas .label { width: 2.2cm; }
        table.identitas .titik { width: 0.3cm; }

        table.rincian { width: 100%; border-collapse: collapse; }
        table.rincian thead { display: table-header-group; }
        table.rincian th,
        table.rincian td { border: 0.75pt solid #000; padding: 3pt 4pt; vertical-align: top; }
        table.rincian th { font-weight: bold; text-align: center; vertical-align: middle; }
        table.rincian .angka { text-align: right; }
        table.rincian .tengah { text-align: center; }
        table.rincian tr.jumlah td { font-weight: bold; }
    </style>
</head>

<body>
    {{-- ============================ Halaman 1 ============================ --}}
    <div class="kop">
        <img src="{{ $logo }}" alt="Logo BPK">
        <div class="lembaga">BADAN PEMERIKSA KEUANGAN</div>
        <div class="satker">PERWAKILAN PROVINSI LAMPUNG</div>
        <div class="alamat">Jl. Pangeran Emir M. Noor No.12B, Telp/Fax. (0721) 472872, Bandar Lampung -35215</div>
    </div>
    <div class="garis-kop"></div>

    <p class="judul">BERITA ACARA SERAH TERIMA BARANG PERSEDIAAN</p>
    <p class="tengah">Nomor : {{ $nomor ?? $kosong }}</p>

    {{-- Teks isi, dari "Pada hari ini" sampai "sebagaimana mestinya.", berspasi 1,15. --}}
    <div class="isi">
    <p class="blok">
        {{-- Tanggal angkanya tidak boleh terpotong di tanda hubung. --}}
        Pada hari ini, {{ $kalimatTanggal }} <span class="utuh">({{ $tanggal?->format('d-m-Y') ?? $titik }}),</span>
        yang bertanda tangan di bawah ini :
    </p>

    @foreach ($pihak as $p)
        <table class="pihak blok">
            <tr>
                <td class="no">{{ $p['nomor'] }}</td>
                <td class="label">Nama</td>
                <td class="titik">:</td>
                <td>{{ $p['data']['nama'] ?? $kosong }}</td>
            </tr>
            <tr>
                <td></td>
                <td class="label">NIP</td>
                <td class="titik">:</td>
                <td>{{ $p['data']['nip'] ?? $kosong }}</td>
            </tr>
            <tr>
                <td></td>
                <td class="label">Jabatan</td>
                <td class="titik">:</td>
                <td>{{ $p['data']['jabatan'] ?? $kosong }}</td>
            </tr>
        </table>

        {{-- Kalimatnya berbeda antara kedua pihak, persis seperti format aslinya. --}}
        @if ($loop->first)
            <p>Yang selanjutnya disebut sebagai <strong>PIHAK PERTAMA</strong>.</p>
        @else
            <p>yang selanjutnya disebut <strong>PIHAK KEDUA.</strong></p>
        @endif
    @endforeach

    <p class="blok rata">
        PIHAK KEDUA telah menerima barang persediaan yang diserahkan oleh PIHAK PERTAMA,
        dengan rincian sebagaimana dokumen tagihan yang terlampir.
    </p>

    <p class="blok rata menjorok">
        Demikianlah Berita Acara Serah Terima Pekerjaan ini dibuat untuk dipergunakan
        sebagaimana mestinya.
    </p>
    </div>

    <table class="ttd blok">
        <tr>
            @foreach ($pihak as $p)
                <td>{{ $p['data']['sebutan'] }}</td>
            @endforeach
        </tr>
        <tr>
            @foreach ($pihak as $p)
                <td class="ruang">
                    @if ($p['data']['tte'])
                        <img class="qr" src="{{ $p['data']['tte']['qr'] }}" alt="QR TTE {{ $p['data']['sebutan'] }}">
                        <div class="ket-tte">
                            Ditandatangani secara elektronik<br>
                            {{ $p['data']['tte']['waktu']->translatedFormat('j M Y H:i') }} WIB
                        </div>
                    @endif
                </td>
            @endforeach
        </tr>
        <tr>
            @foreach ($pihak as $p)
                <td><span class="nama-ttd">{{ $p['data']['nama'] ?? $kosong }}</span></td>
            @endforeach
        </tr>
        <tr>
            @foreach ($pihak as $p)
                <td><strong>NIP {{ $p['data']['nip'] ?? $kosong }}</strong></td>
            @endforeach
        </tr>
    </table>

    @if ($bast['adaTte'])
        <p class="catatan-tte">
            Dokumen ini ditandatangani secara elektronik. Keaslian tanda tangan dapat diperiksa
            dengan memindai kode QR.
        </p>
    @endif

    {{-- ============================ Lampiran ============================= --}}
    <div class="lampiran">
        <table class="kepala-lampiran">
            <tr>
                <td style="width: 41%"></td>
                <td>
                    <table class="kepala-lampiran">
                        <tr>
                            <td colspan="3">Lampiran Berita Acara Serah Terima Barang Persediaan</td>
                        </tr>
                        <tr>
                            <td class="label">Nomor</td>
                            <td class="titik">:</td>
                            <td>{{ $nomor ?? $kosong }}</td>
                        </tr>
                        <tr>
                            <td class="label">Tanggal</td>
                            <td class="titik">:</td>
                            <td>{{ $tanggal?->translatedFormat('j F Y') ?? $kosong }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <p class="judul">RINCIAN BARANG PERSEDIAAN YANG DITERIMA</p>

        <table class="identitas blok">
            <tr>
                <td class="label">Dasar</td>
                <td class="titik">:</td>
                <td>{{ $isi['realisasi']['judul'] }} tanggal {{ $tanggalRealisasi?->translatedFormat('j F Y') }}</td>
            </tr>
            <tr>
                <td class="label">Penyedia</td>
                <td class="titik">:</td>
                <td>{{ $isi['penyedia'] ?? '-' }}</td>
            </tr>
        </table>

        <table class="rincian blok">
            <thead>
                {{-- Kode barang BPK 16 digit; kolomnya dilebarkan supaya tidak
                     menyempitkan Nama Barang. --}}
                <tr>
                    <th style="width: 6%">No</th>
                    <th style="width: 21%">Kode Barang</th>
                    <th>Nama Barang</th>
                    <th style="width: 9%">Jumlah</th>
                    <th style="width: 10%">Satuan</th>
                    <th style="width: 13%">Harga Satuan (Rp)</th>
                    <th style="width: 14%">Jumlah Harga (Rp)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($baris as $i => $detail)
                    <tr>
                        <td class="tengah">{{ $i + 1 }}</td>
                        <td>{{ $detail['kode'] ?? '-' }}</td>
                        <td>{{ $detail['nama'] }}</td>
                        <td class="angka">{{ $detail['diterima'] === null ? '' : number_format($detail['diterima'], 0, ',', '.') }}</td>
                        <td>{{ $detail['satuan'] ?? '-' }}</td>
                        <td class="angka">{{ number_format($detail['harga'], 0, ',', '.') }}</td>
                        <td class="angka">{{ $detail['diterima'] === null ? '' : number_format($detail['diterima'] * $detail['harga'], 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="tengah">Tidak ada barang yang diterima.</td>
                    </tr>
                @endforelse
                <tr class="jumlah">
                    <td colspan="6" class="angka">Jumlah</td>
                    <td class="angka">{{ $total === null ? '' : number_format($total, 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>

        @if ($kurangKirim->isNotEmpty())
            <p class="blok rata">
                Catatan: jumlah yang diterima kurang dari pesanan untuk {{ $kurangKirim->count() }} jenis
                barang, yaitu
                @foreach ($kurangKirim as $detail)
                    {{ $detail['nama'] }} kurang
                    {{ number_format($detail['dipesan'] - $detail['diterima'], 0, ',', '.') }} {{ $detail['satuan'] }}{{ $loop->last ? '.' : ',' }}
                @endforeach
                Kekurangan tersebut belum termasuk dalam serah terima ini.
            </p>
        @endif

        @if (! empty($isi['catatan']))
            <p class="blok rata">Keterangan: {{ $isi['catatan'] }}</p>
        @endif
    </div>
</body>

</html>
