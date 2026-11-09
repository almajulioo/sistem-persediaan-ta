                <!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Nota Dinas Permohonan</title>
    {{-- Templat awal, kerangkanya mengikuti notadinas-permintaan dan isinya
         masih akan direvisi. Sengaja berdiri sendiri supaya revisinya tidak
         menyentuh Nota Dinas permintaan. --}}
    <style>
        @page {
            margin: 68pt 65pt 14pt 67pt;
        }

        body {
            color: #000;
            font-family: "Times New Roman", Times, serif;
            font-size: 12pt;
            line-height: 13.8pt;
            margin: 0;
            padding: 0;
        }

        .header-title {
            text-align: center;
            font-weight: bold;
            padding-top: 4pt;
        }

        .header-no {
            text-align: center;
            font-weight: bold;
            margin-top: 2pt;
            margin-bottom: 15.5pt;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .meta-table td {
            vertical-align: top;
            padding: 8.9pt 0 0 0;
            line-height: 13.8pt;
        }

        .meta-label {
            width: 90.7pt;
            padding-left: 10.4pt !important;
        }

        .meta-colon {
            width: 6.3pt;
            text-align: left;
        }

        .separator {
            border: 0;
            border-top: 1.06pt solid #000;
            height: 0;
            margin: 5pt 0 11pt 5pt;
            width: 453pt;
        }

        .content {
            text-align: left;
            margin: 0 0 10.7pt 5pt;
            text-indent: 36pt;
        }

        .content-closing {
            text-align: left;
            margin: 11pt 0 20pt 5pt;
            text-indent: 36pt;
            line-height: 13pt;
        }

        .item-table {
            width: 454pt;
            margin: 0 0 0 5pt;
            border-collapse: collapse;
        }

        .item-table th,
        .item-table td {
            border: 1pt solid #000;
            padding: 2pt 4pt;
            vertical-align: top;
            line-height: 13pt;
        }

        .item-table th {
            font-weight: bold;
            text-align: center;
        }

        .col-no {
            width: 26pt;
        }

        .col-name {
            width: 150pt;
        }

        .col-type {
            width: 70pt;
        }

        .col-qty {
            width: 60pt;
        }

        .text-center {
            text-align: center;
        }

        .signature-container {
            float: right;
            width: 181pt;
            text-align: center;
            font-weight: bold;
            line-height: 13pt;
            page-break-inside: avoid;
        }

        .signature-container .sig-head {
            line-height: 13.8pt;
        }

        .verification-qr {
            display: block;
            height: 62pt;
            width: 62pt;
            margin: 6pt auto 6.2pt;
        }

        .signature-name {
            font-weight: bold;
        }
    </style>
</head>
<body>

    <div class="header-title">NOTA DINAS</div>
    <div class="header-no">No. {{ $application->nd_number ?? $application->nomorNotaDinas() }}</div>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Kepada Yth.</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">Kepala Subbagian Umum dan TI</td>
        </tr>
        <tr>
            <td class="meta-label">Dari</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">{{ $application->user?->unit?->label() ?? '-' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Perihal</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">{{ $application->perihalNotaDinas() }}</td>
        </tr>
        <tr>
            <td class="meta-label">Tempat/Tanggal</td>
            <td class="meta-colon">:</td>
            <td class="meta-value">Bandar Lampung, {{ $application->date?->translatedFormat('j F Y') ?? '-' }}</td>
        </tr>
    </table>

    <hr class="separator">

    <div class="content">
        Sehubungan dengan kebutuhan barang persediaan pada {{ $application->user?->unit?->label() ?? '-' }}, bersama ini kami sampaikan {{ strtolower($application->perihalNotaDinas()) }} sebagai berikut:
    </div>

    <table class="item-table">
        <thead>
            <tr>
                <th class="col-no">No.</th>
                <th class="col-name">Nama Barang</th>
                <th class="col-type">Jenis</th>
                <th class="col-qty">Jumlah</th>
                <th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($application->details as $index => $detail)
                <tr>
                    <td class="text-center">{{ $index + 1 }}</td>
                    <td>{{ $detail->namaBarang() }}</td>
                    <td class="text-center">{{ $detail->type->label() }}</td>
                    <td class="text-center">{{ number_format($detail->quantity, 0, ',', '.') }} {{ $detail->satuan()?->label() }}</td>
                    <td>{{ $detail->note ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="content-closing">
        Keperluan: {{ $application->purpose }}
    </div>

    <div class="content-closing">
        Atas perhatian dan kerjasama yang baik, kami ucapkan terima kasih.
    </div>

    {{-- Ditandatangani pimpinan unit pemohon, sama dengan Nota Dinas
         permintaan. Jabatannya diambil dari unit pemohon supaya tetap tepat
         walau yang menyetujui di sistem adalah Admin. --}}
    <div class="signature-container">
        <div class="sig-head">{{ $application->user?->unit?->jabatanKepala() ?? $approvedBy?->unit?->jabatanKepala() ?? '-' }},</div>
        @if ($qrCodeDataUri)
            <img style="position: relative; left: 50px;" class="verification-qr" src="{{ $qrCodeDataUri }}" alt="QR verifikasi persetujuan">
        @else
            <div class="verification-qr" aria-hidden="true"></div>
        @endif
        <div class="signature-name">{{ ($approvedBy ?? $kepalaUnit)?->fullname ?? '-' }}</div>
        <div>NIP: {{ ($approvedBy ?? $kepalaUnit)?->nip ?? '-' }}</div>
    </div>

</body>
</html>
