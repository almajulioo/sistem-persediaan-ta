<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <title>{{ $opname->judul() }}</title>
    @include('pdf._style')
</head>

<body>
    @php
        $ringkasan = $opname->ringkasan();
        $adaYangBerubah = $opname->details->contains(fn ($d) => $d->stokBerubahSejakDicacah());
    @endphp

    <div class="kop">
        <div class="lembaga">Badan Pemeriksa Keuangan Republik Indonesia</div>
        <div class="satker">Perwakilan Provinsi Lampung</div>
        <div class="judul">Laporan Stok Opname Persediaan</div>
        <div class="periode">{{ $opname->period_type->label() }} {{ $opname->period_year }}</div>
    </div>

    <table class="meta">
        <tr>
            <td class="label">Tanggal Cacah</td>
            <td>: {{ $opname->opname_date->translatedFormat('d F Y') }}</td>
        </tr>
        <tr>
            <td class="label">Petugas</td>
            <td>: {{ $opname->user?->fullname ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Status</td>
            <td>: {{ $opname->status->label() }}@if ($opname->posted_at), diposting {{ $opname->posted_at->translatedFormat('d F Y H:i') }}@endif</td>
        </tr>
        @if ($opname->notes)
            <tr>
                <td class="label">Catatan</td>
                <td>: {{ $opname->notes }}</td>
            </tr>
        @endif
    </table>

    <table class="rekap">
        <tr>
            <td>
                <span class="label">Barang Dicacah</span>
                <span class="nilai">{{ $ringkasan['dicacah'] }} / {{ $ringkasan['barang'] }}</span>
            </td>
            <td>
                <span class="label">Barang Berselisih</span>
                <span class="nilai">{{ $ringkasan['selisih'] }}</span>
            </td>
            <td>
                <span class="label">Selisih Lebih</span>
                <span class="nilai masuk">+{{ number_format($ringkasan['lebih'], 0, ',', '.') }}</span>
            </td>
            <td>
                <span class="label">Selisih Kurang</span>
                <span class="nilai keluar">-{{ number_format($ringkasan['kurang'], 0, ',', '.') }}</span>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th style="width: 4%">No</th>
                <th style="width: 9%">Kode</th>
                <th style="width: 20%">Nama Barang</th>
                <th style="width: 7%">Satuan</th>
                <th style="width: 8%" class="num">Tercatat</th>
                <th style="width: 8%" class="num">Fisik</th>
                <th style="width: 7%" class="num">Selisih</th>
                <th style="width: 8%" class="num">Penyesuaian</th>
                <th style="width: 12%">Alasan</th>
                <th style="width: 17%">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($opname->details as $i => $detail)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td>{{ $detail->item->item_code }}</td>
                    <td>
                        {{ $detail->item->item_name }}
                        @if ($detail->stokBerubahSejakDicacah())
                            <br><em style="font-size: 8px; color: #b54708;">
                                stok berubah sejak dicacah ({{ $detail->stock_stored }} &rarr; {{ $detail->stock_at_posting }})
                            </em>
                        @endif
                    </td>
                    <td>{{ $detail->item->unit->label() }}</td>
                    <td class="num">{{ number_format($detail->stock_stored, 0, ',', '.') }}</td>
                    <td class="num">{{ $detail->sudahDicacah() ? number_format($detail->stock_real, 0, ',', '.') : '-' }}</td>
                    <td class="num {{ $detail->sudahDicacah() && $detail->difference > 0 ? 'masuk' : ($detail->sudahDicacah() && $detail->difference < 0 ? 'keluar' : '') }}">
                        {{ $detail->signedDifference() }}
                    </td>
                    <td class="num">
                        @if ($detail->adjustment_amount === null)
                            -
                        @elseif ($detail->adjustment_amount === 0)
                            0
                        @else
                            {{ $detail->adjustment_amount > 0 ? '+' : '' }}{{ $detail->adjustment_amount }}
                        @endif
                    </td>
                    <td>{{ $detail->adjustment_reason?->label() ?? '-' }}</td>
                    <td>{{ $detail->adjustment_note ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($adaYangBerubah)
        <p style="font-size: 8.5px; color: #475467; margin-top: 8px;">
            Catatan: sebagian barang mengalami perubahan stok setelah dicacah, misalnya karena ada
            penyerahan barang saat opname berlangsung. Yang dikoreksi opname hanyalah selisih yang
            ditemukan saat mencacah, sehingga transaksi sah setelah pencacahan tidak ikut dibatalkan.
        </p>
    @endif

    <table class="ttd">
        <tr>
            <td class="kolom-ttd">
                Mengetahui,<br>
                Kepala Subbagian Umum dan TU
                <div class="ruang"></div>
                <strong>&nbsp;</strong>
            </td>
            <td></td>
            <td class="kolom-ttd">
                Bandar Lampung, {{ now()->translatedFormat('d F Y') }}<br>
                Pengelola Gudang Persediaan
                <div class="ruang"></div>
                <strong>{{ $opname->user?->fullname ?? $dicetakOleh }}</strong>
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
