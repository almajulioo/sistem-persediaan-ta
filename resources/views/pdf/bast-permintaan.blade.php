<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>BAST {{ $document->document_number }}</title>
    @include('pdf._style')
</head>

<body>
    <div class="kop">
        <div class="lembaga">Badan Pemeriksa Keuangan Republik Indonesia</div>
        <div class="satker">Perwakilan Provinsi Lampung</div>
    </div>

    <div class="judul-dokumen">
        <div class="nama">Berita Acara Serah Terima Barang</div>
        <div class="nomor">Nomor: {{ $document->document_number }}</div>
    </div>

    <p class="paragraf">
        Pada hari ini, {{ $issuedAt->translatedFormat('l') }}, tanggal
        {{ $issuedAt->translatedFormat('j F Y') }}, bertempat di Kantor Badan Pemeriksa
        Keuangan Republik Indonesia Perwakilan Provinsi Lampung, yang bertanda tangan di bawah ini:
    </p>

    <table class="pihak">
        <tr>
            <td class="label">Nama</td>
            <td class="titik">:</td>
            <td>{{ $request->user?->fullname ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Unit Kerja</td>
            <td class="titik">:</td>
            <td>{{ $request->user?->unit ?? '-' }}</td>
        </tr>
    </table>

    <p class="paragraf">selanjutnya disebut <strong>PIHAK PERTAMA</strong>.</p>

    <table class="pihak">
        <tr>
            <td class="label">Nama</td>
            <td class="titik">:</td>
            <td>{{ $issuer->fullname ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Jabatan</td>
            <td class="titik">:</td>
            <td>Pengelola Gudang Persediaan</td>
        </tr>
        <tr>
            <td class="label">Instansi</td>
            <td class="titik">:</td>
            <td>BPK Perwakilan Provinsi Lampung</td>
        </tr>
    </table>

    <p class="paragraf">selanjutnya disebut <strong>PIHAK KEDUA</strong>.</p>

    <p class="paragraf">
        PIHAK KEDUA menyerahkan barang persediaan kepada PIHAK PERTAMA, dan PIHAK PERTAMA menerima
        barang tersebut sesuai permintaan barang nomor {{ $request->request_number }}, dengan rincian
        sebagai berikut:
    </p>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 8%">No</th>
                <th style="width: 20%">Kode</th>
                <th style="width: 47%">Nama Barang</th>
                <th style="width: 12%">Satuan</th>
                <th style="width: 13%" class="num">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($request->item as $index => $detail)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $detail->item?->item_code ?? '-' }}</td>
                    <td>{{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}</td>
                    <td>{{ $detail->item?->unit?->label() ?? '-' }}</td>
                    <td class="num">{{ number_format($detail->quantity_approved ?: $detail->quantity_requested, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="paragraf">
        Barang tersebut di atas telah diserahkan oleh PIHAK KEDUA dan diterima oleh PIHAK PERTAMA
        dalam keadaan baik dan lengkap. Berita acara ini dibuat dengan sebenarnya untuk dipergunakan
        sebagaimana mestinya.
    </p>

    <table class="ttd">
        <tr>
            <td class="kolom-ttd">
                PIHAK PERTAMA<br>
                Penerima Barang
                <div class="ruang"></div>
                <strong>{{ $request->user?->fullname ?? '.....................................' }}</strong>
            </td>
            <td></td>
            <td class="kolom-ttd">
                Bandar Lampung, {{ $issuedAt->translatedFormat('j F Y') }}<br>
                PIHAK KEDUA<br>
                Pengelola Gudang Persediaan
                <div class="ruang"></div>
                <strong>{{ $issuer->fullname ?? '.....................................' }}</strong>
            </td>
        </tr>
    </table>

    <div class="footer">
        Sistem Informasi Persediaan &mdash; BPK Perwakilan Provinsi Lampung
        <span class="kanan">Dicetak {{ now()->translatedFormat('d F Y H:i') }}</span>
    </div>
</body>

</html>