<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Nota Dinas</title>
    <style>
        @page {
            margin: 40px 24px 28px;
        }

        body {
            color: #000;
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            line-height: 1.25;
            margin: 0;
        }

        .header-title {
            text-align: center;
            font-weight: bold;
            font-size: 16pt;
            text-decoration: underline;
            margin-bottom: 4px;
        }

        .header-no {
            text-align: center;
            font-size: 13pt;
            margin-bottom: 35px;
        }

        .meta-table {
            width: calc(100% - 28px);
            margin: 0 14px 27px;
            border-collapse: collapse;
        }

        .meta-table td {
            vertical-align: top;
            padding: 0;
            line-height: 1.55;
        }

        .meta-label {
            width: 18%;
        }

        .meta-colon {
            width: 5%;
            text-align: center;
        }

        .meta-value {
            width: 77%;
        }

        .separator {
            border: 0;
            border-top: 1px solid #000;
            margin: 0 0 27px;
        }

        .content {
            text-align: justify;
            margin: 0 0 24px;
            text-indent: 82px;
            line-height: 1.35;
        }

        .item-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 25px;
        }

        .item-table th, .item-table td {
            border: 1px solid black;
            padding: 4px 7px;
            line-height: 1.25;
        }

        .item-table th {
            text-align: center;
            font-weight: normal;
        }

        .text-center {
            text-align: center;
        }

        .signature-container {
            margin-top: 67px;
            float: right;
            width: 48%;
            text-align: center;
        }

        .signature-image {
            display: block;
            height: 75px;
            margin: 5px auto 3px;
            width: 165px;
            object-fit: contain;
        }

        .signature-name {
            font-weight: bold;
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <div class="header-title">NOTA DINAS</div>
    <div class="header-no">No. {{ $request->request_number }}</div>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Kepada Yth.</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">Kepala Subbagian Umum dan TI</td>
        </tr>
        <tr>
            <td class="meta-label">Dari</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">{{ $request->user?->fullname ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Perihal</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">Permintaan Alat Tulis Kantor (ATK)</td>
        </tr>
        <tr>
            <td class="meta-label">Lampiran</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">-</td>
        </tr>
        <tr>
            <td class="meta-label">Tempat, Tanggal</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">Bandar Lampung, {{ $request->date?->translatedFormat('j F Y') ?? '-' }}</td>
        </tr>
    </table>

    <hr class="separator">

    <div class="content">
        Sehubungan dengan kebutuhan Alat Tulis Kantor (ATK) terkait pelaksanaan kegiatan perkantoran pada {{ $request->user?->unit ?? '-' }}, bersama ini kami sampaikan daftar permintaan ATK sebagai berikut:
    </div>

    <table class="item-table">
        <thead>
            <tr>
                <th width="10%">No.</th>
                <th width="65%">Nama Barang</th>
                <th width="25%">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach($request->item as $index => $detail)
            <tr>
                <td class="text-center">{{ $index + 1 }}.</td>
                <td>{{ $detail->item?->item_name ?? '-' }}</td>
                <td class="text-center">{{ number_format($detail->quantity_requested, 0, ',', '.') }} {{ $detail->item?->unit?->label() ?? '' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="content">
        Atas perhatian dan kerjasama yang baik, kami ucapkan terima kasih.
    </div>

    <div class="signature-container">
        <div>Kepala Bidang Pemeriksaan Lampung I,</div>
        <img class="signature-image" src="{{ public_path('images/requestdocument/ttd.png') }}" alt="Tanda tangan">
        <div class="signature-name">{{ $request->user?->fullname ?? '-' }}</div>
        <div>NIP 197507212002121003</div>
    </div>

</body>
</html>