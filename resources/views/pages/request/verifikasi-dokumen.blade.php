{{--
    Halaman publik tujuan QR verifikasi: Nota Dinas permintaan, Nota Dinas
    permohonan, dan BAST. Berdiri sendiri dengan CSS inline, tanpa aset Vite,
    karena dibuka dari ponsel lewat pindaian QR.

    $jenis      : nama dokumen, mis. "Berita Acara Serah Terima Persediaan"
    $keterangan : kalimat di bawah status
    $bagian     : list<array{judul: 'Dokumen'|'Pemohon'|'Persetujuan', rincian: array<string, string>}>
    $catatan    : (opsional) peringatan yang harus dibaca, mis. penutupan oleh petugas
--}}
@php
    $ikon = [
        'Dokumen' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 13h4"/><path d="M10 17h4"/>',
        'Pemohon' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'Persetujuan' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    ];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>{{ $title }}</title>
    <style>
        :root {
            --latar: #f2f4f7; --kartu: #ffffff; --garis: #e4e7ec; --teks: #101828; --redup: #667085;
            --sukses: #039855; --sukses-lembut: #ecfdf3; --sukses-garis: #abefc6;
            --awas: #b54708; --awas-lembut: #fffaeb; --awas-garis: #fedf89;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --latar: #0c111d; --kartu: #161b26; --garis: #1f242f; --teks: #f5f5f6; --redup: #94969c;
                --sukses: #47cd89; --sukses-lembut: rgba(3, 152, 85, .14); --sukses-garis: rgba(71, 205, 137, .3);
                --awas: #fdb022; --awas-lembut: rgba(247, 144, 9, .12); --awas-garis: rgba(253, 176, 34, .3);
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--latar); color: var(--teks); font: 15px/1.5 "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; }
        main { max-width: 600px; margin: 40px auto; padding: 0 16px; }
        .kartu { background: var(--kartu); border: 1px solid var(--garis); border-radius: 16px; overflow: hidden; }
        .kop { display: flex; align-items: center; gap: 12px; padding: 16px 24px; border-bottom: 1px solid var(--garis); }
        .kop img { width: 40px; height: 40px; object-fit: contain; }
        .kop p { margin: 0; }
        .kop .instansi { font-weight: 600; font-size: 14px; }
        .kop .sistem { color: var(--redup); font-size: 13px; }
        .status { padding: 28px 24px; text-align: center; background: var(--sukses-lembut); border-bottom: 1px solid var(--sukses-garis); }
        .status .lencana { display: inline-flex; width: 56px; height: 56px; align-items: center; justify-content: center; border-radius: 999px; background: var(--sukses); color: #fff; }
        .status h1 { margin: 12px 0 2px; font-size: 22px; color: var(--sukses); }
        .status .jenis { margin: 0; font-weight: 600; }
        .status .keterangan { margin: 8px auto 0; max-width: 440px; color: var(--redup); font-size: 14px; }
        .catatan { display: flex; gap: 10px; margin: 20px 24px 0; padding: 12px 14px; border: 1px solid var(--awas-garis); border-radius: 12px; background: var(--awas-lembut); color: var(--awas); font-size: 14px; font-weight: 500; }
        .catatan svg { flex: none; margin-top: 2px; }
        .bagian { padding: 20px 24px; }
        .bagian + .bagian { border-top: 1px solid var(--garis); }
        .bagian h2 { display: flex; align-items: center; gap: 8px; margin: 0 0 12px; font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--redup); }
        .bagian h2 svg { color: var(--sukses); }
        dl { margin: 0; }
        .baris { display: grid; grid-template-columns: 170px 1fr; gap: 12px; padding: 8px 0; }
        .baris + .baris { border-top: 1px dashed var(--garis); }
        dt { color: var(--redup); }
        dd { margin: 0; font-weight: 600; overflow-wrap: anywhere; }
        footer { padding: 16px 24px; border-top: 1px solid var(--garis); color: var(--redup); font-size: 13px; }
        footer p { margin: 0; }
        footer p + p { margin-top: 4px; }
        @media (max-width: 520px) {
            main { margin: 16px auto; }
            .baris { grid-template-columns: 1fr; gap: 0; }
        }
    </style>
</head>
<body>
    <main>
        <div class="kartu">
            <div class="kop">
                <img src="{{ asset('images/logo/logo-bpk.webp') }}" alt="Logo BPK">
                <div>
                    <p class="instansi">BPK Perwakilan Provinsi Lampung</p>
                    <p class="sistem">{{ config('app.name') }}</p>
                </div>
            </div>

            <section class="status">
                <span class="lencana" aria-hidden="true">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </span>
                <h1>Dokumen Terverifikasi</h1>
                <p class="jenis">{{ $jenis }}</p>
                <p class="keterangan">{{ $keterangan }}</p>
            </section>

            @if ($catatan ?? null)
                <div class="catatan" role="note">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                    <span>{{ $catatan }}</span>
                </div>
            @endif

            @foreach ($bagian as $isi)
                <section class="bagian">
                    <h2>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $ikon[$isi['judul']] ?? '' !!}</svg>
                        {{ $isi['judul'] }}
                    </h2>
                    <dl>
                        @foreach ($isi['rincian'] as $label => $nilai)
                            <div class="baris">
                                <dt>{{ $label }}</dt>
                                <dd>{{ $nilai }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endforeach

            <footer>
                <p>Diperiksa {{ now()->locale('id')->translatedFormat('j F Y, H.i') }} WIB.</p>
                <p>Cocokkan rincian ini dengan dokumen yang Anda pegang. Bila berbeda, dokumen tersebut tidak dapat dianggap sah.</p>
            </footer>
        </div>
    </main>
</body>
</html>
