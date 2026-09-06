{{-- Berita Acara Serah Terima barang pengadaan, dicetak dari sistem untuk
     ditandatangani, dipindai, lalu diunggah kembali (ADR 0018).

     Seluruh jumlah pada dokumen ini adalah `amount_received` — yang
     benar-benar datang — bukan `amount` yang dipesan. Menandatangani jumlah
     pesanan atas kiriman yang kurang justru membuat berita acaranya berbohong
     (ADR 0017). --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>BAST {{ $receipt->bast_number }}</title>
    @include('pdf._style')
</head>

<body>
    @php
        $penyedia = $procurement->supplier;
        $penerima = $receipt->receivedBy;
        $kurangKirim = $procurement->details->filter(fn ($detail) => $detail->kurangKirim() > 0);
    @endphp

    <div class="kop">
        <div class="lembaga">Badan Pemeriksa Keuangan Republik Indonesia</div>
        <div class="satker">Perwakilan Provinsi Lampung</div>
    </div>

    <div class="judul-dokumen">
        <div class="nama">Berita Acara Serah Terima Barang</div>
        <div class="nomor">Nomor: {{ $receipt->bast_number }}</div>
    </div>

    <p class="paragraf">
        Pada hari ini, {{ $receipt->received_at->translatedFormat('l') }}, tanggal
        {{ $receipt->received_at->translatedFormat('j F Y') }}, bertempat di Kantor Badan Pemeriksa
        Keuangan Republik Indonesia Perwakilan Provinsi Lampung, yang bertanda tangan di bawah ini:
    </p>

    <table class="pihak">
        <tr>
            <td class="label">Nama</td>
            <td class="titik">:</td>
            <td>{{ $penyedia?->contact_person ?? '.....................................' }}</td>
        </tr>
        <tr>
            <td class="label">Penyedia</td>
            <td class="titik">:</td>
            <td>{{ $penyedia?->supplier_name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Alamat</td>
            <td class="titik">:</td>
            <td>{{ $penyedia?->address ?? '-' }}</td>
        </tr>
        @if ($penyedia?->npwp)
            <tr>
                <td class="label">NPWP</td>
                <td class="titik">:</td>
                <td>{{ $penyedia->npwp }}</td>
            </tr>
        @endif
    </table>

    <p class="paragraf">selanjutnya disebut <strong>PIHAK PERTAMA</strong>.</p>

    <table class="pihak">
        <tr>
            <td class="label">Nama</td>
            <td class="titik">:</td>
            <td>{{ $penerima?->fullname ?? '-' }}</td>
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
        PIHAK PERTAMA menyerahkan kepada PIHAK KEDUA, dan PIHAK KEDUA menerima dari PIHAK PERTAMA,
        barang persediaan sesuai {{ $procurement->judul() }} tanggal
        {{ $procurement->procurement_date->translatedFormat('j F Y') }}, dengan rincian sebagai berikut:
    </p>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 6%">No</th>
                <th style="width: 14%">Kode</th>
                <th style="width: 34%">Nama Barang</th>
                <th style="width: 10%">Satuan</th>
                <th style="width: 10%" class="num">Jumlah</th>
                <th style="width: 13%" class="num">Harga Satuan</th>
                <th style="width: 13%" class="num">Jumlah Harga</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($procurement->details as $i => $detail)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $detail->item?->item_code ?? '-' }}</td>
                    <td>{{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}</td>
                    <td>{{ $detail->item?->unit->label() ?? '-' }}</td>
                    <td class="num">{{ number_format($detail->amount_received ?? 0, 0, ',', '.') }}</td>
                    <td class="num">Rp{{ number_format($detail->unit_price, 0, ',', '.') }}</td>
                    <td class="num">
                        Rp{{ number_format(($detail->amount_received ?? 0) * $detail->unit_price, 0, ',', '.') }}
                    </td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="6" class="num">Jumlah</td>
                <td class="num">Rp{{ number_format($procurement->nilaiDiterima(), 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    @if ($kurangKirim->isNotEmpty())
        <p class="paragraf">
            Catatan kekurangan kiriman: PIHAK PERTAMA belum menyerahkan seluruh jumlah yang dipesan atas
            {{ $kurangKirim->count() }} jenis barang, yaitu
            @foreach ($kurangKirim as $detail)
                {{ $detail->item?->item_name }} sebanyak
                {{ number_format($detail->kurangKirim(), 0, ',', '.') }} {{ $detail->item?->unit->label() }}@if (! $loop->last), @else. @endif
            @endforeach
            Kekurangan tersebut belum termasuk dalam serah terima ini.
        </p>
    @endif

    @if ($receipt->notes)
        <p class="paragraf">Keterangan: {{ $receipt->notes }}</p>
    @endif

    <p class="paragraf">
        Barang tersebut di atas telah diperiksa oleh PIHAK KEDUA dan diterima dalam keadaan baik. Berita
        acara ini dibuat dengan sebenarnya untuk dipergunakan sebagaimana mestinya.
    </p>

    <table class="ttd">
        <tr>
            <td class="kolom-ttd">
                PIHAK PERTAMA<br>
                {{ $penyedia?->supplier_name ?? 'Penyedia' }}
                <div class="ruang"></div>
                <strong>{{ $penyedia?->contact_person ?? '.....................................' }}</strong>
            </td>
            <td></td>
            <td class="kolom-ttd">
                Bandar Lampung, {{ $receipt->received_at->translatedFormat('j F Y') }}<br>
                PIHAK KEDUA
                <div class="ruang"></div>
                <strong>{{ $penerima?->fullname ?? '.....................................' }}</strong>
            </td>
        </tr>
    </table>

    <div class="footer">
        Sistem Informasi Persediaan &mdash; BPK Perwakilan Provinsi Lampung
        <span class="kanan">
            Dicetak {{ now()->translatedFormat('d F Y H:i') }} oleh {{ $dicetakOleh }}
        </span>
    </div>
</body>

</html>
