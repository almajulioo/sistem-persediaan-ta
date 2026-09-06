{{-- CSS bersama untuk seluruh laporan PDF. Dompdf tidak mengenal Tailwind,
     jadi seluruh gaya ditulis sebagai CSS biasa. --}}
<style>
    @page { margin: 22mm 14mm 18mm 14mm; }

    body {
        font-family: DejaVu Sans, sans-serif;
        font-size: 9.5px;
        color: #1d2939;
        margin: 0;
    }

    .kop {
        text-align: center;
        border-bottom: 2px solid #1d2939;
        padding-bottom: 8px;
        margin-bottom: 14px;
    }
    .kop .lembaga { font-size: 13px; font-weight: bold; letter-spacing: .5px; }
    .kop .satker { font-size: 11px; margin-top: 2px; }
    .kop .judul { font-size: 12px; font-weight: bold; margin-top: 10px; text-transform: uppercase; }
    .kop .periode { font-size: 9.5px; margin-top: 3px; color: #475467; }

    .meta { width: 100%; margin-bottom: 10px; border-collapse: collapse; }
    .meta td { padding: 1px 0; font-size: 9px; color: #475467; vertical-align: top; }
    .meta .label { width: 90px; }

    .rekap { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .rekap td {
        border: 1px solid #d0d5dd;
        padding: 6px 8px;
        width: 25%;
        text-align: center;
    }
    .rekap .label { font-size: 8.5px; color: #475467; display: block; }
    .rekap .nilai { font-size: 12px; font-weight: bold; display: block; margin-top: 2px; }

    table.data { width: 100%; border-collapse: collapse; }
    table.data th {
        background: #f2f4f7;
        border: 1px solid #d0d5dd;
        padding: 5px 6px;
        font-size: 8.5px;
        text-align: left;
        text-transform: uppercase;
    }
    table.data td {
        border: 1px solid #d0d5dd;
        padding: 4px 6px;
        font-size: 9px;
        vertical-align: top;
    }
    table.data tbody tr:nth-child(even) td { background: #fcfcfd; }

    .num { text-align: right; }
    .center { text-align: center; }
    .masuk { color: #027a48; font-weight: bold; }
    .keluar { color: #b42318; font-weight: bold; }
    .kosong { text-align: center; padding: 18px; color: #667085; }

    .ttd { margin-top: 22px; width: 100%; }
    .ttd td { font-size: 9px; vertical-align: top; }
    .ttd .kolom-ttd { width: 40%; text-align: center; }
    .ttd .ruang { height: 46px; }

    .footer {
        position: fixed;
        bottom: -12mm;
        left: 0;
        right: 0;
        font-size: 8px;
        color: #667085;
        border-top: 1px solid #d0d5dd;
        padding-top: 3px;
    }
    .footer .kanan { float: right; }

    /* --- Khusus BAST: dokumen naratif, bukan tabel laporan --- */
    .judul-dokumen { text-align: center; margin-bottom: 14px; }
    .judul-dokumen .nama {
        font-size: 12px;
        font-weight: bold;
        text-transform: uppercase;
        text-decoration: underline;
        letter-spacing: .3px;
    }
    .judul-dokumen .nomor { font-size: 10px; margin-top: 4px; }

    p.paragraf { font-size: 10px; line-height: 1.6; text-align: justify; margin: 8px 0; }

    table.pihak { width: 100%; border-collapse: collapse; margin: 6px 0 6px 20px; }
    table.pihak td { font-size: 10px; padding: 1px 0; vertical-align: top; }
    table.pihak .label { width: 110px; }
    table.pihak .titik { width: 12px; }

    table.data tr.total td { background: #f2f4f7; font-weight: bold; }
</style>
