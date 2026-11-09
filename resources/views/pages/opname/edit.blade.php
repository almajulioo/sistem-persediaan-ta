@extends('layouts.app')

@php
    // Baris pencacahan untuk Alpine. Draf memuat seluruh barang aktif — bisa
    // ribuan — jadi tabel dirender di klien sebagian demi sebagian, dan yang
    // dikirim hanya baris yang berubah, sebagai satu JSON di `hasil_cacah`
    // (max_input_vars PHP memotong ribuan isian diam-diam, ADR 0056).
    //
    // Setelah validasi gagal, isian dipulihkan dari JSON kiriman itu; `asal`
    // tetap nilai tersimpan supaya baris tersebut terhitung berubah lagi.
    $kiriman = json_decode((string) old('hasil_cacah', ''), true);
    $lama = is_array($kiriman) ? $kiriman : (array) old('details', []);

    // Galat angka (stok sistem berubah sejak halaman dibuka) dipisah dari
    // galat alasan: yang pertama perlu diperiksa ulang walau barisnya sah.
    $galatBaris = [];
    $galatStok = [];
    foreach ($errors->getMessages() as $kunci => $pesan) {
        if (preg_match('/^details\.([^.]+)\.stock_real$/', $kunci, $cocok)) {
            $galatStok[$cocok[1]] ??= $pesan[0];
        } elseif (preg_match('/^details\.([^.]+)\./', $kunci, $cocok)) {
            $galatBaris[$cocok[1]] ??= $pesan[0];
        }
    }

    $baris = $opname->details->map(function ($detail) use ($lama, $galatBaris, $galatStok) {
        $id = $detail->opname_detail_id;

        $tersimpan = [
            'real' => $detail->stock_real === null ? '' : (string) $detail->stock_real,
            'reason' => $detail->adjustment_reason?->value ?? '',
            'note' => (string) $detail->adjustment_note,
        ];

        $isian = array_key_exists($id, $lama) && is_array($lama[$id])
            ? [
                'real' => (string) ($lama[$id]['stock_real'] ?? ''),
                'reason' => (string) ($lama[$id]['adjustment_reason'] ?? ''),
                'note' => (string) ($lama[$id]['adjustment_note'] ?? ''),
            ]
            : $tersimpan;

        return [
            'id' => $id,
            'kode' => $detail->item->item_code,
            'nama' => $detail->item->item_name,
            'satuan' => $detail->item->unit->label(),
            // Pembanding tersimpan: stok sistem pada waktu barang ini dihitung
            // (ADR 0057). Hanya bermakna bila barisnya sudah dicacah.
            'stored' => $detail->stock_stored,
            'kini' => $detail->item->stock_current,
            'dicacah' => $detail->counted_at?->translatedFormat('d M Y H:i'),
            'real' => $isian['real'],
            'reason' => $isian['reason'],
            'note' => $isian['note'],
            'asal' => $tersimpan,
            'galat' => $galatBaris[$id] ?? null,
            'galatStok' => $galatStok[$id] ?? null,
        ];
    })->values()->all();

    $alasan = \App\Enums\AlasanSelisih::options();

    // Hanya opname insidental yang barang kosongnya mengikuti stok sistem;
    // opname lain wajib mengisi setiap barang (ADR 0057).
    $bolehKosong = $opname->bolehKosong();

    $penyaring = [
        'semua' => ['Semua', 'total'],
        'diisi' => ['Diisi', 'diisi'],
        'berselisih' => ['Berselisih', 'berselisih'],
        'kosong' => [$bolehKosong ? 'Ikut Stok Sistem' : 'Belum Diisi', 'kosong'],
    ];

    // Batas paling awal "Dihitung pada": pukul 00:00 tanggal pencacahan.
    $waktuAwal = $opname->opname_date->format('Y-m-d').'T00:00';

    $kelasInput = 'dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 h-10 rounded-lg border bg-transparent px-3 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
@endphp

@section('content')
    <div x-data="pencacahanOpname()"
        @submit="terkirim = true"
        @pageshow.window="if ($event.persisted && terkirim) location.reload()">

        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $opname->judul() }}</h2>
                <nav class="mt-1.5">
                    <ol class="flex items-center gap-1.5">
                        <li>
                            <a href="{{ route('opname.index') }}"
                                class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                                Stok Opname
                                <svg class="stroke-current" width="17" height="16" viewBox="0 0 17 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M6.0765 12.667L10.2432 8.50033L6.0765 4.33366" stroke="" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </a>
                        </li>
                        <li class="text-sm text-gray-800 dark:text-white/90">Pencacahan</li>
                    </ol>
                </nav>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">
                    {{ $opname->opname_date->translatedFormat('d M Y') }} &middot; {{ $opname->user?->fullname ?? '—' }}
                </span>
                <span class="text-theme-xs inline-flex items-center rounded-full px-2.5 py-1 font-medium {{ $opname->status->badgeClass() }}">
                    {{ $opname->status->label() }}
                </span>
            </div>
        </div>

        <x-ui.flash />

        @if ($errors->any())
            <div class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mb-6 rounded-lg px-4 py-3 text-sm"
                role="alert" aria-live="assertive">
                Periksa kembali isian. Terdapat {{ $errors->count() }} kesalahan &mdash; {{ $errors->first() }}
            </div>
        @endif

        {{-- Ringkasan berjalan, dihitung dari isian di layar --}}
        <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4 md:gap-6">
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">Total Barang</span>
                <h4 class="text-title-sm mt-2 font-bold text-gray-800 dark:text-white/90" x-text="angka(statistik.total)">
                    {{ number_format(count($baris), 0, ',', '.') }}
                </h4>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">Diisi</span>
                <h4 class="text-title-sm mt-2 font-bold text-gray-800 dark:text-white/90" x-text="angka(statistik.diisi)"></h4>
                <p class="text-theme-xs mt-1 text-gray-500 dark:text-gray-400"
                    x-text="angka(statistik.sesuai) + ' sesuai · ' + angka(statistik.berselisih) + ' berselisih'"></p>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <span class="text-theme-sm text-gray-500 dark:text-gray-400">Selisih Lebih / Kurang</span>
                <h4 class="text-title-sm mt-2 font-bold">
                    <span class="text-success-600 dark:text-success-500" x-text="'+' + angka(statistik.lebih)"></span>
                    <span class="text-gray-400">/</span>
                    <span class="text-error-600 dark:text-error-500" x-text="'-' + angka(statistik.kurang)"></span>
                </h4>
            </div>
            @if ($bolehKosong)
                <div class="border-brand-200 bg-brand-50 dark:border-brand-500/30 dark:bg-brand-500/10 rounded-2xl border p-5">
                    <span class="text-theme-sm text-brand-700 dark:text-brand-400">Ikut Stok Sistem</span>
                    <h4 class="text-title-sm text-brand-700 dark:text-brand-400 mt-2 font-bold" x-text="angka(statistik.kosong)"></h4>
                    <p class="text-theme-xs text-brand-700/80 dark:text-brand-400/80 mt-1">tidak diisi</p>
                </div>
            @else
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">Belum Diisi</span>
                    <h4 class="text-title-sm mt-2 font-bold"
                        :class="statistik.kosong > 0 ? 'text-warning-600 dark:text-orange-400' : 'text-gray-800 dark:text-white/90'"
                        x-text="angka(statistik.kosong)"></h4>
                </div>
            @endif
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-5 sm:px-6">
                <h3 class="text-base font-medium text-gray-800 dark:text-white/90">Hasil Pencacahan Fisik</h3>

                <div class="flex flex-wrap items-center gap-3">
                    <span x-show="statistik.berubah > 0" x-cloak
                        class="text-theme-xs text-warning-600 dark:text-orange-400 font-medium"
                        x-text="angka(statistik.berubah) + ' perubahan belum disimpan'"></span>

                    {{-- Waktu di lembar cacah. Wajib setiap kali angka Stok Fisik
                         berubah dan sengaja tanpa isian bawaan: lembar kertas
                         pagi yang dibandingkan dengan stok sore memunculkan
                         selisih palsu (ADR 0057). --}}
                    <div class="flex items-center gap-2">
                        <label for="dihitung-pada" class="text-theme-sm font-medium whitespace-nowrap text-gray-700 dark:text-gray-400">
                            Dihitung Pada
                        </label>
                        <input type="datetime-local" id="dihitung-pada" x-model="waktu" min="{{ $waktuAwal }}"
                            :aria-invalid="waktuKurang"
                            :class="waktuKurang || (waktu !== '' && ! waktuSah) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700'"
                            class="{{ $kelasInput }} w-52 tabular-nums" />
                    </div>

                    <button type="submit" form="form-cacah" name="aksi" value="simpan"
                        x-bind:disabled="statistik.berubah === 0 || waktuKurang || terkirim"
                        class="rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Simpan Draf
                    </button>
                    <button type="button" @click="konfirmasi = true"
                        x-bind:disabled="terkirim"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-5 py-3 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                        Posting Penyesuaian
                    </button>
                </div>
            </div>

            @error('dihitung_pada')
                <p class="text-theme-xs text-error-500 -mt-3 mb-4 px-5 text-right sm:px-6">{{ $message }}</p>
            @enderror

            @if ($bolehKosong)
                {{-- Satu-satunya kalimat petunjuk di layar ini, diminta Narendra
                     (9 Nov 2026) supaya petugas tahu barang kosong tidak berarti
                     terlewat: ia mengikuti stok sistem (ADR 0056). Sejak ADR
                     0057 hanya berlaku — dan hanya tampil — pada opname
                     insidental. --}}
                <div class="bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400 mx-5 mb-5 flex items-start gap-3 rounded-lg px-4 py-3 text-sm sm:mx-6">
                    <x-ui.icon name="info" class="mt-0.5 size-4" />
                    <p>
                        Isi <span class="font-semibold">Stok Fisik</span> hanya untuk barang yang jumlahnya berbeda dari
                        <span class="font-semibold">Stok Sistem</span>. Barang yang dikosongkan dianggap sesuai dan
                        mengikuti stok sistem saat diposting.
                    </p>
                </div>
            @endif

            {{-- Pencarian dan penyaring. Daftar disaring ulang hanya saat
                 penyaring atau kata kunci berganti, bukan saat mengetik angka,
                 supaya baris tidak lenyap di tengah pengisian. --}}
            <div class="flex flex-col gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between dark:border-gray-800">
                <div class="relative w-full lg:max-w-xs">
                    <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-gray-400">
                        <x-ui.icon name="search" class="size-4" />
                    </span>
                    <input type="search" x-model.debounce.200ms="cari" placeholder="Cari kode atau nama barang"
                        aria-label="Cari kode atau nama barang"
                        class="{{ $kelasInput }} w-full border-gray-300 pl-9 dark:border-gray-700" />
                </div>

                <div class="flex flex-wrap gap-2" role="group" aria-label="Saring barang">
                    @foreach ($penyaring as $kunci => [$label, $hitung])
                        <button type="button" @click="pilih('{{ $kunci }}')"
                            :aria-pressed="saring === '{{ $kunci }}'"
                            :class="saring === '{{ $kunci }}'
                                ? 'bg-brand-500 text-white ring-brand-500'
                                : 'text-gray-700 ring-gray-300 hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]'"
                            class="text-theme-sm inline-flex items-center gap-1.5 rounded-lg px-3 py-2 font-medium ring-1 ring-inset transition">
                            {{ $label }}
                            <span class="text-theme-xs rounded-full px-1.5 tabular-nums"
                                :class="saring === '{{ $kunci }}' ? 'bg-white/20' : 'bg-gray-100 dark:bg-gray-800'"
                                x-text="angka(statistik.{{ $hitung }})"></span>
                        </button>
                    @endforeach

                    <button type="button" x-show="statistik.tinjau > 0" x-cloak @click="pilih('perlu')"
                        :aria-pressed="saring === 'perlu'"
                        :class="saring === 'perlu'
                            ? 'bg-error-500 text-white ring-error-500'
                            : 'text-error-600 ring-error-300 hover:bg-error-50 dark:text-error-500 dark:ring-error-500/40 dark:hover:bg-error-500/10'"
                        class="text-theme-sm inline-flex items-center gap-1.5 rounded-lg px-3 py-2 font-medium ring-1 ring-inset transition">
                        <x-ui.icon name="alert-triangle" class="size-4" />
                        Perlu Dilengkapi
                        <span class="text-theme-xs rounded-full px-1.5 tabular-nums"
                            :class="saring === 'perlu' ? 'bg-white/20' : 'bg-error-50 dark:bg-error-500/15'"
                            x-text="angka(statistik.tinjau)"></span>
                    </button>
                </div>
            </div>

            <div class="custom-scrollbar max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                <table class="w-full min-w-[1120px]">
                    <thead>
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach (['Kode', 'Nama Barang', 'Satuan', 'Stok Sistem', 'Stok Fisik', 'Selisih', 'Alasan Selisih', 'Keterangan'] as $heading)
                                <th class="px-5 py-3 text-left sm:px-6">
                                    <p class="text-theme-xs font-medium whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in tampil" :key="row.id">
                            <tr class="border-b border-gray-100 dark:border-gray-800"
                                :class="perlu(row) || row.galatStok ? 'bg-error-50/60 dark:bg-error-500/5' : ''">
                                <td class="px-5 py-3 sm:px-6">
                                    <span class="text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="row.kode"></span>
                                </td>
                                <td class="min-w-56 px-5 py-3 sm:px-6">
                                    <span class="text-theme-sm text-gray-800 dark:text-white/90" x-text="row.nama"></span>
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <span class="text-theme-sm text-gray-500 dark:text-gray-400" x-text="row.satuan"></span>
                                </td>
                                {{-- Stok sistem pada waktu cacah: pembanding tersimpan
                                     untuk baris yang sudah dicacah, atau stok pada
                                     "Dihitung Pada" untuk angka baru. Stok kini
                                     ditampilkan sebagai data bila sudah bergerak. --}}
                                <td class="px-5 py-3 sm:px-6" :title="tersimpan(row) && row.dicacah ? 'Dihitung ' + row.dicacah : ''">
                                    <span class="text-theme-sm font-medium text-gray-800 tabular-nums dark:text-white/90" x-text="angka(pembanding(row))"></span>
                                    <span x-show="pembanding(row) !== row.kini" x-cloak
                                        class="text-theme-xs block whitespace-nowrap text-gray-500 tabular-nums dark:text-gray-400"
                                        x-text="'kini ' + angka(row.kini)"></span>
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <input type="text" inputmode="numeric" autocomplete="off" maxlength="6"
                                        :id="'fisik-' + row.id"
                                        x-model="row.real"
                                        :placeholder="BOLEH_KOSONG ? angka(pembanding(row)) : ''"
                                        :aria-label="'Stok fisik ' + row.nama"
                                        :aria-invalid="! angkaSah(row)"
                                        @keydown.enter.prevent="lanjut(row, i)"
                                        :class="angkaSah(row) && ! row.galatStok ? 'border-gray-300 dark:border-gray-700' : 'border-error-500'"
                                        class="{{ $kelasInput }} w-24 tabular-nums" />
                                    <p x-show="row.galatStok" x-cloak class="text-theme-xs text-error-500 mt-1 max-w-64" x-text="row.galatStok"></p>
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <span x-show="BOLEH_KOSONG && kosong(row)"
                                        class="text-theme-xs bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-400 inline-flex rounded-full px-2.5 py-0.5 font-medium whitespace-nowrap">
                                        Ikut sistem
                                    </span>
                                    <span x-show="! BOLEH_KOSONG && kosong(row)" class="text-theme-sm text-gray-400">—</span>
                                    <span x-show="! angkaSah(row)" class="text-theme-xs text-error-600 dark:text-error-500 font-medium">
                                        Bukan angka bulat
                                    </span>
                                    <span x-show="selisih(row) === 0"
                                        class="text-theme-xs bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-500 inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 font-medium">
                                        <x-ui.icon name="check" class="size-3" />
                                        Sesuai
                                    </span>
                                    <span x-show="berselisih(row)"
                                        class="text-theme-sm font-semibold tabular-nums"
                                        :class="selisih(row) > 0 ? 'text-success-600 dark:text-success-500' : 'text-error-600 dark:text-error-500'"
                                        x-text="tanda(selisih(row))"></span>
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <select x-show="berselisih(row)"
                                        :id="'alasan-' + row.id"
                                        x-model="row.reason"
                                        :aria-label="'Alasan selisih ' + row.nama"
                                        :class="row.reason === '' ? 'border-error-500' : 'border-gray-300 dark:border-gray-700'"
                                        class="{{ $kelasInput }} w-48">
                                        <option value="">Pilih alasan</option>
                                        @foreach ($alasan as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <span x-show="! berselisih(row)" class="text-theme-sm text-gray-400">—</span>
                                </td>
                                <td class="px-5 py-3 sm:px-6">
                                    <input type="text" maxlength="500" x-show="berselisih(row)"
                                        :id="'keterangan-' + row.id"
                                        x-model="row.note"
                                        :aria-label="'Keterangan selisih ' + row.nama"
                                        placeholder="Rincian penyebab selisih"
                                        @keydown.enter.prevent="lanjutBaris(i)"
                                        :class="row.note.trim() === '' ? 'border-error-500' : 'border-gray-300 dark:border-gray-700'"
                                        class="{{ $kelasInput }} w-64" />
                                    <span x-show="! berselisih(row)" class="text-theme-sm text-gray-400">—</span>
                                    <p x-show="row.galat && perlu(row)" class="text-theme-xs text-error-500 mt-1" x-text="row.galat"></p>
                                </td>
                            </tr>
                        </template>

                        <tr x-show="tampil.length === 0" x-cloak>
                            <td colspan="8" class="px-5 py-10 text-center sm:px-6">
                                <p class="text-theme-sm text-gray-500 dark:text-gray-400">Tidak ada barang yang cocok.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div x-show="daftar.length > 0" class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
                <p class="text-theme-sm text-gray-500 dark:text-gray-400"
                    x-text="'Menampilkan ' + angka(tampil.length) + ' dari ' + angka(daftar.length) + ' barang'"></p>
                <button type="button" x-show="tampil.length < daftar.length" @click="tambah()"
                    class="text-theme-sm rounded-lg px-4 py-2.5 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Tampilkan lebih banyak
                </button>
            </div>
        </div>

        {{-- Satu formulir untuk simpan dan posting: posting ikut menyimpan
             isian di layar, jadi tidak ada lagi "simpan dulu sebelum
             memposting" (ADR 0056). --}}
        <form id="form-cacah" method="POST" action="{{ route('opname.update', $opname) }}" class="hidden">
            @csrf
            @method('PUT')
            <input type="hidden" name="dihitung_pada" :value="waktu">
            <input type="hidden" name="hasil_cacah" :value="kiriman">
        </form>

        {{-- Konfirmasi posting: ringkasan dari isian di layar. Pada opname
             insidental disebut berapa barang yang akan mengikuti stok sistem;
             pada opname lain barang yang belum diisi menghalangi posting. --}}
        <div x-show="konfirmasi" x-cloak @keydown.escape.window="konfirmasi = false"
            class="fixed inset-0 z-99999 flex items-center justify-center p-5"
            role="dialog" aria-modal="true" aria-labelledby="judul-konfirmasi-posting">
            <div @click="konfirmasi = false" class="fixed inset-0 bg-gray-900/50"></div>

            <div @click.stop class="relative w-full max-w-lg rounded-2xl bg-white p-6 dark:bg-gray-900">
                <h3 id="judul-konfirmasi-posting" class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">
                    Posting {{ $opname->judul() }}?
                </h3>

                <dl class="mt-5 divide-y divide-gray-100 overflow-hidden rounded-xl border border-gray-200 text-sm dark:divide-gray-800 dark:border-gray-800">
                    <div class="flex items-center justify-between px-4 py-3">
                        <dt class="font-medium text-gray-800 dark:text-white/90">Diisi petugas</dt>
                        <dd class="font-semibold text-gray-800 tabular-nums dark:text-white/90" x-text="angka(statistik.diisi) + ' barang'"></dd>
                    </div>
                    <div class="flex items-center justify-between px-4 py-2.5 pl-8">
                        <dt class="text-gray-500 dark:text-gray-400">Sesuai</dt>
                        <dd class="text-gray-700 tabular-nums dark:text-gray-300" x-text="angka(statistik.sesuai)"></dd>
                    </div>
                    <div class="flex items-center justify-between px-4 py-2.5 pl-8">
                        <dt class="text-gray-500 dark:text-gray-400">Berselisih</dt>
                        <dd class="text-gray-700 tabular-nums dark:text-gray-300">
                            <span x-text="angka(statistik.berselisih)"></span>
                            <span x-show="statistik.berselisih > 0" class="text-theme-xs text-gray-500 dark:text-gray-400"
                                x-text="'(+' + angka(statistik.lebih) + ' / -' + angka(statistik.kurang) + ')'"></span>
                        </dd>
                    </div>
                    @if ($bolehKosong)
                        <div class="bg-brand-50 dark:bg-brand-500/10 flex items-center justify-between px-4 py-3">
                            <dt class="text-brand-700 dark:text-brand-400 font-medium">Mengikuti stok sistem</dt>
                            <dd class="text-brand-700 dark:text-brand-400 font-semibold tabular-nums" x-text="angka(statistik.kosong) + ' barang'"></dd>
                        </div>
                    @else
                        <div class="flex items-center justify-between px-4 py-3">
                            <dt class="font-medium text-gray-800 dark:text-white/90">Belum diisi</dt>
                            <dd class="font-semibold text-gray-800 tabular-nums dark:text-white/90" x-text="angka(statistik.kosong) + ' barang'"></dd>
                        </div>
                    @endif
                </dl>

                @unless ($bolehKosong)
                    <div x-show="statistik.kosong > 0"
                        class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mt-4 flex items-start justify-between gap-3 rounded-lg px-4 py-3 text-sm">
                        <span x-text="angka(statistik.kosong) + ' barang belum diisi.'"></span>
                        <button type="button" @click="konfirmasi = false; pilih('kosong')" class="shrink-0 font-medium underline">
                            Tampilkan
                        </button>
                    </div>
                @endunless

                <div x-show="statistik.perlu > 0"
                    class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mt-4 flex items-start justify-between gap-3 rounded-lg px-4 py-3 text-sm">
                    <span x-text="angka(statistik.perlu) + ' barang perlu dilengkapi: angka tidak sah, atau selisih tanpa alasan dan keterangan.'"></span>
                    <button type="button" @click="konfirmasi = false; pilih('perlu')" class="shrink-0 font-medium underline">
                        Tampilkan
                    </button>
                </div>

                <div x-show="waktuKurang"
                    class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mt-4 flex items-start justify-between gap-3 rounded-lg px-4 py-3 text-sm">
                    <span>Dihitung pada belum diisi.</span>
                    <button type="button" @click="konfirmasi = false; fokus('dihitung-pada')" class="shrink-0 font-medium underline">
                        Isi
                    </button>
                </div>

                <p class="text-theme-sm mt-4 text-gray-500 dark:text-gray-400">
                    Penyesuaian akan masuk ke buku mutasi, dan opname yang sudah diposting tidak dapat diubah atau dihapus.
                </p>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" @click="konfirmasi = false"
                        class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                        Batal
                    </button>
                    <button type="submit" form="form-cacah" name="aksi" value="posting"
                        x-bind:disabled="! bisaPosting || terkirim"
                        class="bg-brand-500 hover:bg-brand-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                        Ya, Posting
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function pencacahanOpname() {
            const PER_HALAMAN = 50;
            const BOLEH_KOSONG = @js($bolehKosong);
            const WAKTU_AWAL = @js($waktuAwal);
            const rows = @json($baris);
            // Mutasi tiap baris sejak 00:00 tanggal cacah, [waktu, jumlah],
            // untuk menghitung stok sistem pada "Dihitung Pada" di klien.
            const MUTASI = @json($mutasi);
            const format = new Intl.NumberFormat('id-ID');

            return {
                BOLEH_KOSONG,
                rows,
                daftar: [],
                cari: '',
                saring: 'semua',
                batas: PER_HALAMAN,
                konfirmasi: false,
                terkirim: false,
                waktu: @js((string) old('dihitung_pada', '')),

                init() {
                    // Setelah validasi gagal, langsung tampilkan baris yang
                    // perlu dibetulkan atau diperiksa ulang.
                    if (this.rows.some(row => this.perlu(row) || row.galatStok)) {
                        this.saring = 'perlu';
                    }

                    this.saringUlang();
                    this.$watch('cari', () => this.saringUlang());

                    window.addEventListener('beforeunload', (event) => {
                        if (! this.terkirim && this.statistik.berubah > 0) {
                            event.preventDefault();
                            event.returnValue = '';
                        }
                    });
                },

                isi(row) { return String(row.real ?? '').trim(); },
                kosong(row) { return this.isi(row) === ''; },
                angkaSah(row) { return this.kosong(row) || /^\d{1,6}$/.test(this.isi(row)); },

                get waktuSah() {
                    return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(this.waktu) && this.waktu >= WAKTU_AWAL;
                },
                // Angka tersimpan yang tidak diubah tetap memakai pembanding
                // tersimpannya.
                tersimpan(row) { return row.asal.real !== '' && this.isi(row) === row.asal.real; },
                // Angka baru yang akan dibandingkan pada "Dihitung Pada".
                angkaBaru(row) { return ! this.kosong(row) && this.angkaSah(row) && ! this.tersimpan(row); },
                get waktuKurang() { return this.statistik.baru > 0 && ! this.waktuSah; },

                // Stok sistem pada akhir menit "Dihitung Pada": stok kini
                // dikurangi mutasi sesudahnya — rumus yang sama dengan
                // StockLedger::stokPada() di server. Waktu dibandingkan
                // sebagai teks berformat sama.
                stokPada(row) {
                    const sesudah = this.waktu + ':59';
                    let stok = row.kini;
                    for (const [waktu, jumlah] of (MUTASI[row.id] ?? [])) {
                        if (waktu > sesudah) stok -= jumlah;
                    }
                    return stok;
                },
                pembanding(row) {
                    if (this.tersimpan(row)) return row.stored;
                    return this.waktuSah ? this.stokPada(row) : row.kini;
                },
                selisih(row) {
                    return this.kosong(row) || ! this.angkaSah(row) ? null : Number(this.isi(row)) - this.pembanding(row);
                },
                berselisih(row) {
                    const selisih = this.selisih(row);
                    return selisih !== null && selisih !== 0;
                },
                perlu(row) {
                    return ! this.angkaSah(row)
                        || (this.berselisih(row) && (row.reason === '' || row.note.trim() === ''));
                },
                // Alasan dan keterangan hanya dihitung pada baris berselisih;
                // pada baris lain server membuangnya.
                berubah(row) {
                    if (this.isi(row) !== row.asal.real) return true;
                    return this.berselisih(row)
                        && (row.reason !== row.asal.reason || row.note.trim() !== row.asal.note);
                },

                get statistik() {
                    const hasil = { total: this.rows.length, diisi: 0, sesuai: 0, berselisih: 0, lebih: 0, kurang: 0, perlu: 0, tinjau: 0, berubah: 0, baru: 0 };

                    for (const row of this.rows) {
                        if (! this.kosong(row)) hasil.diisi++;

                        const selisih = this.selisih(row);
                        if (selisih === 0) {
                            hasil.sesuai++;
                        } else if (selisih !== null) {
                            hasil.berselisih++;
                            if (selisih > 0) hasil.lebih += selisih; else hasil.kurang -= selisih;
                        }

                        const perlu = this.perlu(row);
                        if (perlu) hasil.perlu++;
                        if (perlu || row.galatStok) hasil.tinjau++;
                        if (this.berubah(row)) hasil.berubah++;
                        if (this.angkaBaru(row)) hasil.baru++;
                    }

                    hasil.kosong = hasil.total - hasil.diisi;

                    return hasil;
                },

                // Opname selain insidental wajib mengisi setiap barang (ADR
                // 0057); server menolaknya juga.
                get bisaPosting() {
                    const s = this.statistik;
                    return s.perlu === 0 && ! this.waktuKurang && (BOLEH_KOSONG || s.kosong === 0);
                },

                // Hanya baris yang berubah, sebagai satu JSON (lihat komentar
                // di atas). Baris yang dikosongkan dikirim dengan angka kosong.
                // Angka baru membawa stok sistem yang ditampilkan, supaya
                // server dapat menolak bila ternyata sudah berbeda.
                get kiriman() {
                    const isi = {};

                    for (const row of this.rows) {
                        if (! this.berubah(row)) continue;

                        const berselisih = this.berselisih(row);
                        isi[row.id] = {
                            stock_real: this.isi(row),
                            stok_sistem: this.angkaBaru(row) ? String(this.pembanding(row)) : '',
                            adjustment_reason: berselisih ? row.reason : '',
                            adjustment_note: berselisih ? row.note.trim() : '',
                        };
                    }

                    return JSON.stringify(isi);
                },

                saringUlang() {
                    const kata = this.cari.trim().toLowerCase();

                    this.daftar = this.rows.filter(row => {
                        if (kata !== '' && ! row.kode.toLowerCase().includes(kata) && ! row.nama.toLowerCase().includes(kata)) {
                            return false;
                        }

                        switch (this.saring) {
                            case 'diisi': return ! this.kosong(row);
                            case 'berselisih': return this.berselisih(row);
                            case 'kosong': return this.kosong(row);
                            case 'perlu': return this.perlu(row) || row.galatStok;
                            default: return true;
                        }
                    });

                    this.batas = PER_HALAMAN;
                },
                pilih(saring) {
                    this.saring = saring;
                    this.saringUlang();
                },
                get tampil() { return this.daftar.slice(0, this.batas); },
                tambah() { this.batas += PER_HALAMAN; },

                // Enter berpindah ke isian berikutnya: alasan dan keterangan
                // dulu bila barisnya berselisih, lalu baris di bawahnya.
                lanjut(row, i) {
                    if (this.berselisih(row) && row.reason === '') return this.fokus('alasan-' + row.id);
                    if (this.berselisih(row) && row.note.trim() === '') return this.fokus('keterangan-' + row.id);
                    this.lanjutBaris(i);
                },
                lanjutBaris(i) {
                    const berikut = this.tampil[i + 1];
                    if (berikut) this.fokus('fisik-' + berikut.id);
                },
                fokus(id) {
                    requestAnimationFrame(() => document.getElementById(id)?.focus());
                },

                angka(nilai) { return format.format(nilai); },
                tanda(nilai) { return (nilai > 0 ? '+' : '-') + format.format(Math.abs(nilai)); },
            };
        }
    </script>
@endsection
