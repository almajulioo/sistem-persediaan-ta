<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>Laporan Riwayat Mutasi Stok</title>
    @include('pdf._style')
</head>

<body>
    @php
        $periode = match (true) {
            filled($filters['from']) && filled($filters['to']) =>
                \Carbon\Carbon::parse($filters['from'])->translatedFormat('d F Y') . ' s.d. ' . \Carbon\Carbon::parse($filters['to'])->translatedFormat('d F Y'),
            filled($filters['from']) => 'Sejak ' . \Carbon\Carbon::parse($filters['from'])->translatedFormat('d F Y'),
            filled($filters['to']) => 'Sampai ' . \Carbon\Carbon::parse($filters['to'])->translatedFormat('d F Y'),
            default => 'Seluruh periode',
        };

        $jenis = \App\Enums\MutationType::tryFrom($filters['type'])?->label();
    @endphp

    <div class="kop">
        <div class="lembaga">Badan Pemeriksa Keuangan Republik Indonesia</div>
        <div class="satker">Perwakilan Provinsi Lampung</div>
        <div class="judul">Laporan Riwayat Mutasi Stok Persediaan</div>
        <div class="periode">{{ $periode }}</div>
    </div>

    <table class="meta">
        @if ($jenis)
            <tr>
                <td class="label">Jenis Mutasi</td>
                <td>: {{ $jenis }}</td>
            </tr>
        @endif
        @if (filled($filters['q']))
            <tr>
                <td class="label">Kata Kunci</td>
                <td>: {{ $filters['q'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Jumlah Baris</td>
            <td>: {{ number_format($mutations->count(), 0, ',', '.') }} mutasi</td>
        </tr>
    </table>

    <table class="rekap">
        <tr>
            <td>
                <span class="label">Total Masuk</span>
                <span class="nilai masuk">+{{ number_format($summary['masuk'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Total Keluar</span>
                <span class="nilai keluar">-{{ number_format($summary['keluar'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Netto</span>
                <span class="nilai">{{ $summary['netto'] > 0 ? '+' : '' }}{{ number_format($summary['netto'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Jumlah Transaksi</span>
                <span class="nilai">{{ number_format($summary['transaksi'], 0, ',', '.') }}</span>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 4%">No</th>
                <th style="width: 9%">Tanggal</th>
                <th style="width: 9%">Kode</th>
                <th style="width: 17%">Nama Barang</th>
                <th style="width: 12%">Jenis Mutasi</th>
                <th style="width: 8%">Sumber</th>
                <th style="width: 8%" class="num">Jumlah</th>
                <th style="width: 8%" class="num">Stok Setelah</th>
                <th style="width: 9%">Referensi</th>
                <th style="width: 15%">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($mutations as $i => $mutation)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $mutation->mutation_date->format('d/m/Y') }}</td>
                    <td>{{ $mutation->item->item_code }}</td>
                    <td>{{ $mutation->item->item_name }}</td>
                    <td>{{ $mutation->mutation_type->label() }}</td>
                    <td>{{ $mutation->sumber() }}</td>
                    <td class="num {{ $mutation->amount > 0 ? 'masuk' : 'keluar' }}">
                        {{ $mutation->signedAmount() }} {{ $mutation->item->unit->label() }}
                    </td>
                    <td class="num">{{ number_format($mutation->stock_after, 0, ',', '.') }}</td>
                    <td>{{ $mutation->referensi() }}</td>
                    <td>{{ $mutation->notes ?? '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="kosong">Tidak ada mutasi pada periode dan penyaring yang dipilih.</td>
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
