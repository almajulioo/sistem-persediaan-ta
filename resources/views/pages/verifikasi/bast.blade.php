{{-- Halaman verifikasi QR TTE BAST pengadaan (ADR 0051). Publik dan berdiri
     sendiri — tanpa layout aplikasi, tanpa Vite — karena dibuka dari ponsel
     pemeriksa yang tidak punya akun. Isinya dibaca dari isi BAST yang
     dibekukan, sehingga sama persis dengan dokumen yang ditandatangani.
     Isi dokumen hanya tampil untuk TTE yang sah. --}}
@php
    $rupiah = fn (int $nilai) => number_format($nilai, 0, ',', '.');
    $rincian = collect($isi['rincian'] ?? []);
    $total = $rincian->sum(fn (array $baris) => ($baris['diterima'] ?? 0) * $baris['harga']);
    $kurang = $rincian->filter(fn (array $baris) => $baris['diterima'] !== null && $baris['diterima'] < $baris['dipesan']);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    @include('layouts.favicon')
    <style>
        :root {
            --bg: #f2f4f7;
            --surface: #ffffff;
            --text: #1d2939;
            --muted: #667085;
            --border: #e4e7ec;
            --ok: #027a48;
            --ok-bg: #ecfdf3;
            --bad: #b42318;
            --bad-bg: #fef3f2;
            --wait: #b54708;
            --wait-bg: #fffaeb;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0c111d;
                --surface: #161b26;
                --text: #f5f5f6;
                --muted: #94969c;
                --border: #2b303b;
                --ok: #47cd89;
                --ok-bg: #053321;
                --bad: #f97066;
                --bad-bg: #55160c;
                --wait: #fdb022;
                --wait-bg: #4e1d09;
            }
        }

        * { box-sizing: border-box; }
        body { background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; margin: 0; line-height: 1.5; }
        main { margin: 32px auto; max-width: 720px; padding: 0 16px; }
        header { align-items: center; display: flex; gap: 12px; margin-bottom: 16px; }
        header img { height: 44px; width: auto; }
        header p { margin: 0; }
        .lembaga { font-weight: 700; }
        .kecil { color: var(--muted); font-size: 13px; }
        .kartu { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; margin-top: 16px; padding: 20px; }
        .status { border-radius: 16px; display: flex; gap: 12px; padding: 16px 20px; }
        .status svg { flex: none; height: 28px; width: 28px; }
        .status h1 { font-size: 20px; margin: 0; }
        .status p { margin: 4px 0 0; }
        .sah { background: var(--ok-bg); color: var(--ok); }
        .tidak { background: var(--bad-bg); color: var(--bad); }
        h2 { font-size: 15px; margin: 0 0 12px; }
        dl { display: grid; gap: 8px 16px; grid-template-columns: 160px 1fr; margin: 0; }
        dt { color: var(--muted); }
        dd { font-weight: 600; margin: 0; overflow-wrap: anywhere; }
        .pihak { border-top: 1px solid var(--border); display: flex; flex-wrap: wrap; gap: 4px 16px; justify-content: space-between; padding: 12px 0; }
        .pihak:first-of-type { border-top: 0; padding-top: 0; }
        .chip { border-radius: 999px; font-size: 12px; font-weight: 600; padding: 2px 10px; white-space: nowrap; }
        .chip.ok { background: var(--ok-bg); color: var(--ok); }
        .chip.wait { background: var(--wait-bg); color: var(--wait); }
        .gulir { overflow-x: auto; }
        table { border-collapse: collapse; font-size: 14px; min-width: 560px; width: 100%; }
        th, td { border-bottom: 1px solid var(--border); padding: 8px 6px; text-align: left; vertical-align: top; }
        th { color: var(--muted); font-size: 12px; font-weight: 600; }
        .angka { text-align: right; white-space: nowrap; }
        tfoot td { border-bottom: 0; font-weight: 700; }
        .sidik { font-family: ui-monospace, Consolas, monospace; font-size: 12px; overflow-wrap: anywhere; }
        footer { color: var(--muted); font-size: 12px; margin: 24px 0; text-align: center; }
        /* Di ponsel rincian barang tampil bertumpuk: jumlah dan nilai —
           yang paling perlu dicocokkan pemeriksa — tidak boleh tersembunyi
           di balik gulir tabel. */
        .daftar { display: none; list-style: none; margin: 0; padding: 0; }
        .daftar li { border-bottom: 1px solid var(--border); padding: 10px 0; }
        .daftar .baris { display: flex; gap: 12px; justify-content: space-between; }
        .daftar .total { font-weight: 700; padding-top: 10px; border-bottom: 0; }
        @media (max-width: 560px) {
            main { margin-top: 16px; }
            dl { grid-template-columns: 1fr; gap: 2px; }
            dd { margin-bottom: 8px; }
            .gulir { display: none; }
            .daftar { display: block; }
        }
    </style>
</head>
<body>
    <main>
        <header>
            <img src="{{ asset('images/logo_bpk_kop.jpeg') }}" alt="Logo BPK">
            <div>
                <p class="lembaga">BPK Perwakilan Provinsi Lampung</p>
                <p class="kecil">Verifikasi Tanda Tangan Elektronik</p>
            </div>
        </header>

        @if ($keadaan === 'sah')
            <div class="status sah" role="status">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                <div>
                    <h1>Tanda tangan elektronik sah</h1>
                    <p>Berita Acara Serah Terima Barang Persediaan</p>
                </div>
            </div>
        @elseif ($keadaan === 'tidak_berlaku')
            <div class="status tidak" role="status">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
                <div>
                    <h1>Tanda tangan tidak berlaku</h1>
                    @if ($ttd->dicabut())
                        <p>Dicabut {{ $ttd->revoked_at->translatedFormat('j F Y H:i') }} WIB — {{ $ttd->revoke_reason }}.</p>
                    @else
                        <p>Isi dokumen tidak cocok dengan isi yang ditandatangani.</p>
                    @endif
                </div>
            </div>
        @else
            <div class="status tidak" role="status">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                <div>
                    <h1>Kode verifikasi tidak dikenal</h1>
                    <p>Dokumen ini tidak tercatat sebagai BAST yang ditandatangani di sistem.</p>
                </div>
            </div>
        @endif

        @if ($ttd)
            <section class="kartu">
                <h2>Penanda Tangan</h2>
                <dl>
                    <dt>Nama</dt>
                    <dd>{{ $ttd->signer_name }}</dd>
                    <dt>NIP</dt>
                    <dd>{{ $ttd->signer_nip ?? '-' }}</dd>
                    <dt>Jabatan</dt>
                    <dd>{{ $ttd->signer_position ?? '-' }}</dd>
                    <dt>Sebagai</dt>
                    <dd>{{ $ttd->party->label() }} — {{ $ttd->party->jabatanTtd() }}</dd>
                    <dt>Waktu TTE</dt>
                    <dd>{{ $ttd->signed_at->translatedFormat('j F Y H:i') }} WIB</dd>
                </dl>
            </section>
        @endif

        @if ($keadaan === 'sah')
            <section class="kartu">
                <h2>Dokumen</h2>
                <dl>
                    <dt>Nomor BAST</dt>
                    <dd>{{ $isi['nomor'] }}</dd>
                    <dt>Tanggal</dt>
                    <dd>{{ $tanggal?->translatedFormat('j F Y') ?? '-' }}</dd>
                    <dt>Dasar</dt>
                    <dd>{{ $isi['realisasi']['judul'] }}</dd>
                    <dt>Penyedia</dt>
                    <dd>{{ $isi['penyedia'] ?? '-' }}</dd>
                    <dt>Status</dt>
                    <dd>{{ $lengkap ? 'Lengkap, ditandatangani kedua pihak' : 'Menunggu tanda tangan pihak lain' }}</dd>
                </dl>
            </section>

            <section class="kartu">
                <h2>Para Pihak</h2>
                @foreach ($pihak as $baris)
                    <div class="pihak">
                        <div>
                            <div class="kecil">{{ $baris['pihak']->label() }} · {{ $baris['pihak']->jabatanTtd() }}</div>
                            <div><strong>{{ $baris['ttd']?->signer_name ?? '-' }}</strong></div>
                        </div>
                        @if ($baris['ttd'])
                            <span class="chip ok">TTE {{ $baris['ttd']->signed_at->translatedFormat('j M Y H:i') }}</span>
                        @else
                            <span class="chip wait">Belum bertanda tangan</span>
                        @endif
                    </div>
                @endforeach
            </section>

            <section class="kartu">
                <h2>Rincian Barang yang Diterima</h2>
                <div class="gulir">
                    <table>
                        <thead>
                            <tr>
                                <th>Kode</th>
                                <th>Nama Barang</th>
                                <th class="angka">Jumlah</th>
                                <th>Satuan</th>
                                <th class="angka">Harga (Rp)</th>
                                <th class="angka">Jumlah (Rp)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rincian as $baris)
                                <tr>
                                    <td>{{ $baris['kode'] ?? '-' }}</td>
                                    <td>{{ $baris['nama'] }}</td>
                                    <td class="angka">{{ $rupiah((int) $baris['diterima']) }}</td>
                                    <td>{{ $baris['satuan'] ?? '-' }}</td>
                                    <td class="angka">{{ $rupiah($baris['harga']) }}</td>
                                    <td class="angka">{{ $rupiah((int) $baris['diterima'] * $baris['harga']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="5" class="angka">Jumlah</td>
                                <td class="angka">{{ $rupiah($total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <ul class="daftar">
                    @foreach ($rincian as $baris)
                        <li>
                            <div><strong>{{ $baris['nama'] }}</strong></div>
                            <div class="kecil">{{ $baris['kode'] ?? '-' }}</div>
                            <div class="baris">
                                <span>{{ $rupiah((int) $baris['diterima']) }} {{ $baris['satuan'] }} × Rp{{ $rupiah($baris['harga']) }}</span>
                                <span class="angka">Rp{{ $rupiah((int) $baris['diterima'] * $baris['harga']) }}</span>
                            </div>
                        </li>
                    @endforeach
                    <li class="baris total">
                        <span>Jumlah</span>
                        <span class="angka">Rp{{ $rupiah($total) }}</span>
                    </li>
                </ul>

                @if ($kurang->isNotEmpty())
                    <p class="kecil">
                        Kurang dari pesanan:
                        @foreach ($kurang as $baris)
                            {{ $baris['nama'] }} kurang {{ $rupiah($baris['dipesan'] - $baris['diterima']) }} {{ $baris['satuan'] }}{{ $loop->last ? '.' : ',' }}
                        @endforeach
                    </p>
                @endif

                @if (! empty($isi['catatan']))
                    <p class="kecil">Keterangan: {{ $isi['catatan'] }}</p>
                @endif
            </section>

            <section class="kartu">
                <h2>Sidik Dokumen (SHA-256)</h2>
                <p class="sidik">{{ $sidik }}</p>
            </section>
        @endif

        <footer>Sistem Informasi Persediaan · BPK Perwakilan Provinsi Lampung</footer>
    </main>
</body>
</html>
