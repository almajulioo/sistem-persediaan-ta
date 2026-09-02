<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Kartu Persediaan {{ $item->item_code }}</title>
    @include('pdf._style')
</head>

<body>
    <div class="kop">
        <div class="lembaga">Badan Pemeriksa Keuangan Republik Indonesia</div>
        <div class="satker">Perwakilan Provinsi Lampung</div>
        <div class="judul">Kartu Persediaan Barang</div>
    </div>

    <table class="meta">
        <tr>
            <td class="label">Kode Barang</td>
            <td>: <strong>{{ $item->item_code }}</strong></td>
            <td class="label">Stok Minimum</td>
            <td>: {{ number_format($item->stock_min, 0, ',', '.') }} {{ $item->unit->label() }}</td>
        </tr>
        <tr>
            <td class="label">Nama Barang</td>
            <td>: <strong>{{ $item->item_name }}</strong></td>
            <td class="label">Stok Maksimum</td>
            <td>: {{ $item->stock_max > 0 ? number_format($item->stock_max, 0, ',', '.') . ' ' . $item->unit->label() : 'Tidak dibatasi' }}</td>
        </tr>
        <tr>
            <td class="label">Satuan</td>
            <td>: {{ $item->unit->label() }}</td>
            <td class="label">Stok Saat Ini</td>
            <td>: <strong>{{ number_format($item->stock_current, 0, ',', '.') }} {{ $item->unit->label() }}</strong>
                ({{ $item->stockStatus()->label() }})</td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 4%">No</th>
                <th style="width: 10%">Tanggal</th>
                <th style="width: 15%">Jenis Mutasi</th>
                <th style="width: 9%" class="num">Masuk</th>
                <th style="width: 9%" class="num">Keluar</th>
                <th style="width: 10%" class="num">Saldo</th>
                <th style="width: 11%">Referensi</th>
                <th style="width: 14%">Petugas</th>
                <th style="width: 18%">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($mutations as $i => $mutation)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $mutation->mutation_date->format('d/m/Y') }}</td>
                    <td>{{ $mutation->mutation_type->label() }}</td>
                    <td class="num masuk">{{ $mutation->amount > 0 ? number_format($mutation->amount, 0, ',', '.') : '' }}</td>
                    <td class="num keluar">{{ $mutation->amount < 0 ? number_format(abs($mutation->amount), 0, ',', '.') : '' }}</td>
                    <td class="num"><strong>{{ number_format($mutation->stock_after, 0, ',', '.') }}</strong></td>
                    <td>{{ $mutation->referensi() }}</td>
                    <td>{{ $mutation->petugas() }}</td>
                    <td>{{ $mutation->notes ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="kosong">Belum ada mutasi stok untuk barang ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="ttd">
        <tr>
            <td></td>
            <td class="kolom-ttd">
                Bandar Lampung, {{ now()->translatedFormat('d F Y') }}<br>
                Pengelola Gudang Persediaan
                <div class="ruang"></div>
                <strong>{{ $dicetakOleh }}</strong>
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
