{{--
    Pemilih barang untuk formulir sub sistem Permintaan: cari teks, saring
    bertingkat kategori (ATK/ARTK) lalu sub kategori di bawahnya, kemudian
    pilih dengan tetikus atau papan ketik (↑ ↓ Enter Esc). Daftar barang dari
    App\Support\KatalogBarang::untukPemilih() milik komponen induk.

    $sumber      : ekspresi JS daftar barang di lingkup induk, bawaan `items`
    $subkategori : KatalogBarang::subkategori(); bila kosong, sub kategori
                   diturunkan dari daftar barang
    $pilih       : pernyataan JS saat `item` dipilih, bawaan `selectedItem = item`
    $nonaktif    : ekspresi JS bernilai true bila `item` tidak boleh dipilih
    $info        : ekspresi JS teks kecil di kanan tombol saat barang terpilih
    $badge       : slot di kanan tiap baris hasil, boleh memakai `item`
    $kosong      : slot tambahan saat tidak ada barang yang cocok

    Penyaringan dikerjakan sekali per perubahan isian, di atas indeks teks
    yang dibuat saat komponen dimuat, dan hanya 50 baris pertama yang
    dirender — sisanya lewat "Tampilkan lagi".
--}}
@props([
    'sumber' => 'items',
    'pilih' => 'selectedItem = item',
    'nonaktif' => 'false',
    'info' => "selectedItem ? selectedItem.item_code : ''",
    'label' => 'Barang',
    'subkategori' => null,
])

@php
    $kategoriPilihan = ['' => ['Semua', 'Semua kategori']] + collect(\App\Enums\KategoriBarang::cases())
        ->mapWithKeys(fn ($kategori) => [$kategori->value => [$kategori->value, $kategori->label()]])
        ->all();
    $kelasKategori = collect(\App\Enums\KategoriBarang::cases())
        ->mapWithKeys(fn ($kategori) => [$kategori->value => $kategori->badgeClass()])
        ->all();
    $labelKategori = collect(\App\Enums\KategoriBarang::cases())
        ->mapWithKeys(fn ($kategori) => [$kategori->value => $kategori->label()])
        ->all();
@endphp

<div {{ $attributes->class('relative') }}
    x-data="pemilihBarang({ items: {{ $sumber }}, daftarSub: @js($subkategori), pilih: (item) => { {{ $pilih }} }, nonaktif: (item) => {{ $nonaktif }} })"
    @click.outside="tutup()" @keydown.escape.window="open && tutup()">
    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
        {{ $label }} <span class="text-error-500">*</span>
    </label>
    <button type="button" @click="alih()" :aria-expanded="open" aria-haspopup="listbox"
        class="shadow-theme-xs flex h-11 w-full items-center gap-3 rounded-lg border border-gray-300 bg-transparent px-4 text-left text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
        <x-ui.icon name="search" class="size-4 text-gray-400" />
        <span class="flex-1 truncate" x-text="selectedItem ? selectedItem.item_name : 'Cari dan pilih barang'" :class="!selectedItem ? 'text-gray-400' : ''"></span>
        <span x-show="selectedItem" class="text-theme-xs text-gray-500 dark:text-gray-400" x-text="{{ $info }}"></span>
        <x-ui.icon name="chevron-right" class="size-4 text-gray-400 transition" ::class="open ? '-rotate-90' : 'rotate-90'" />
    </button>

    <div x-show="open" x-transition.origin.top style="display: none;"
        class="absolute z-30 mt-1 w-full overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg dark:border-gray-800 dark:bg-gray-900">
        <div class="space-y-2.5 border-b border-gray-100 p-3 dark:border-gray-800">
            <div class="relative">
                <x-ui.icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400" />
                <input type="text" x-ref="cari" x-model.debounce.150ms="cari" autocomplete="off" aria-label="Cari barang"
                    @keydown.arrow-down.prevent="geser(1)" @keydown.arrow-up.prevent="geser(-1)" @keydown.enter.prevent="pilihAktif()"
                    placeholder="Ketik nama, kode, atau sub kategori..."
                    class="h-10 w-full rounded-lg border border-gray-300 bg-transparent pr-9 pl-9 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">
                <button type="button" x-show="cari !== ''" style="display: none;" @click="cari = ''; $refs.cari.focus()"
                    class="absolute top-1/2 right-2 -translate-y-1/2 rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/[0.05]"
                    aria-label="Kosongkan pencarian">
                    <x-ui.icon name="x" class="size-3.5" />
                </button>
            </div>

            {{-- Tingkat 1: kategori. --}}
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Kategori">
                @foreach ($kategoriPilihan as $nilai => [$singkat, $panjang])
                    <button type="button" @click="setKategori(@js($nilai))" title="{{ $panjang }}"
                        :aria-pressed="kategori === @js($nilai)"
                        :class="kategori === @js($nilai)
                            ? 'border-brand-500 bg-brand-500 text-white'
                            : 'border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.05]'"
                        class="text-theme-xs inline-flex h-8 items-center gap-1.5 rounded-full border px-3 font-medium transition">
                        {{ $singkat }}
                        <span class="rounded-full px-1.5 text-[11px] leading-4 tabular-nums"
                            :class="kategori === @js($nilai) ? 'bg-white/20' : 'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400'"
                            x-text="jumlahKategori[@js($nilai)] ?? 0"></span>
                    </button>
                @endforeach
            </div>

            {{-- Tingkat 2: sub kategori, hanya yang kolom `category`-nya sama
                 dengan kategori terpilih dan masih punya barang yang cocok. --}}
            <div x-show="kategori !== ''" style="display: none;"
                class="rounded-lg border border-gray-100 bg-gray-50 p-2 dark:border-gray-800 dark:bg-white/[0.02]">
                <p class="text-theme-xs mb-1.5 flex items-center gap-1.5 px-1 font-medium text-gray-500 dark:text-gray-400">
                    <x-ui.icon name="layers" class="size-3.5" />
                    <span x-text="`Sub kategori ${labelKategori[kategori] ?? kategori}`"></span>
                </p>
                <div class="flex max-h-28 flex-wrap gap-1.5 overflow-y-auto pr-1" role="group" aria-label="Sub kategori">
                    <button type="button" @click="setSubkategori('')" :aria-pressed="subkategori === ''"
                        :class="subkategori === ''
                            ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300'
                            : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 dark:border-gray-700 dark:bg-transparent dark:text-gray-300'"
                        class="inline-flex h-7 items-center gap-1.5 rounded-md border px-2.5 text-[11px] font-medium transition">
                        Semua
                        <span class="tabular-nums opacity-70" x-text="jumlahKategori[kategori] ?? 0"></span>
                    </button>
                    <template x-for="sub in subkategoriTampil" :key="sub.id">
                        <button type="button" @click="setSubkategori(sub.id)" :aria-pressed="subkategori === sub.id"
                            :title="`${sub.kode} — ${sub.nama}`"
                            :class="subkategori === sub.id
                                ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300'
                                : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 dark:border-gray-700 dark:bg-transparent dark:text-gray-300'"
                            class="inline-flex h-7 max-w-full items-center gap-1.5 rounded-md border px-2.5 text-[11px] font-medium transition">
                            <span class="truncate" x-text="sub.nama"></span>
                            <span class="tabular-nums opacity-70" x-text="jumlahSub[sub.id] ?? 0"></span>
                        </button>
                    </template>
                </div>
            </div>
            <p x-show="kategori === ''" class="text-theme-xs px-1 text-gray-400 dark:text-gray-500">
                Pilih ATK atau ARTK untuk menyaring sampai sub kategorinya.
            </p>
        </div>

        <div class="text-theme-xs flex items-center justify-between gap-3 px-4 pt-2.5 pb-1 text-gray-500 dark:text-gray-400">
            <span class="min-w-0 truncate" aria-live="polite">
                <span x-text="`${hasil.length.toLocaleString('id-ID')} barang`"></span>
                <span x-show="jalur" class="font-medium text-gray-700 dark:text-gray-300" x-text="`· ${jalur}`"></span>
            </span>
            <button type="button" x-show="adaSaringan" style="display: none;" @click="reset()"
                class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
                Reset penyaring
            </button>
        </div>

        <ul x-ref="daftar" role="listbox" class="max-h-72 space-y-0.5 overflow-y-auto px-2 pb-2">
            <template x-for="(item, index) in tampil" :key="item.item_id">
                <li role="option" :aria-selected="index === aktif" :data-indeks="index">
                    <button type="button" @click="pilihItem(item)" @mouseenter="aktif = index" :disabled="nonaktif(item)"
                        :class="index === aktif ? 'bg-gray-100 dark:bg-white/[0.06]' : ''"
                        class="flex w-full flex-col items-start gap-1.5 rounded-lg px-3 py-2 text-left text-sm transition disabled:cursor-not-allowed disabled:opacity-50 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                        <span class="w-full min-w-0 sm:w-auto">
                            <span class="block truncate font-medium text-gray-800 dark:text-white/90" x-text="item.item_name"></span>
                            <span class="text-theme-xs mt-0.5 flex min-w-0 items-center gap-1.5 text-gray-500 dark:text-gray-400">
                                <span x-show="item.category" class="shrink-0 rounded px-1.5 text-[10px] leading-4 font-semibold"
                                    :class="kelasKategori[item.category] ?? ''" x-text="item.category"></span>
                                <span class="shrink-0 tabular-nums" x-text="item.item_code"></span>
                                <span x-show="item.subcategory_name" class="truncate" x-text="`· ${item.subcategory_name}`"></span>
                            </span>
                        </span>
                        <span class="text-theme-xs shrink-0 sm:text-right">
                            @if (isset($badge))
                                {{ $badge }}
                            @else
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300"
                                    x-text="`Stok ${item.stock_current}`"></span>
                            @endif
                        </span>
                    </button>
                </li>
            </template>

            <li x-show="hasil.length > tampil.length" class="pt-1">
                <button type="button" @click="batas += 50"
                    class="text-theme-xs w-full rounded-lg py-2 font-medium text-brand-600 transition hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10"
                    x-text="`Tampilkan ${Math.min(50, hasil.length - tampil.length)} lagi · ${hasil.length - tampil.length} belum tampil`"></button>
            </li>

            <li x-show="hasil.length === 0" class="px-3 py-5 text-center text-sm text-gray-500 dark:text-gray-400">
                <span class="mx-auto mb-2 flex size-10 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5">
                    <x-ui.icon name="package" class="size-5" />
                </span>
                Barang tidak ditemukan.
                <button type="button" x-show="kategori !== '' || subkategori !== ''" @click="reset()"
                    class="mt-1 block w-full font-medium text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                    Cari di semua kategori
                </button>
                {{ $kosong ?? '' }}
            </li>
        </ul>

        <p class="text-theme-xs hidden border-t border-gray-100 px-4 py-2 text-gray-400 sm:block dark:border-gray-800 dark:text-gray-500">
            ↑ ↓ memilih · Enter mengambil · Esc menutup
        </p>
    </div>
</div>

@once
    <script>
        const kelasKategoriBarang = @json($kelasKategori);
        const labelKategoriBarang = @json($labelKategori);

        function pemilihBarang({ items, daftarSub, pilih, nonaktif }) {
            const normal = (teks) => String(teks ?? '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
            // Daftar mentah, bukan proksi reaktif: disaring di setiap ketikan.
            const daftar = window.Alpine?.raw ? window.Alpine.raw(items) : items;
            // Indeks teks dibuat sekali, sejajar dengan urutan `daftar`.
            const indeksTeks = daftar.map(item => normal(`${item.item_name} ${item.item_code} ${item.subcategory_name ?? ''}`));
            const indeksKode = daftar.map(item => normal(item.item_code));
            // Pengelompokan memakai kolom `category` milik sub kategori dari
            // server. Cadangannya diturunkan dari barang bila tidak dikirim.
            const subkategoriSemua = (daftarSub ?? [...new Map(daftar
                .filter(item => item.subcategory_id)
                .map(item => [item.subcategory_id, {
                    id: item.subcategory_id,
                    kode: item.subcategory_code ?? '',
                    nama: item.subcategory_name ?? '',
                    kategori: item.category,
                }])).values()])
                .slice()
                .sort((a, b) => a.kode.localeCompare(b.kode));
            const subkategoriPerId = new Map(subkategoriSemua.map(sub => [sub.id, sub]));

            return {
                open: false,
                cari: '',
                kategori: '',
                subkategori: '',
                hasil: [],
                batas: 50,
                aktif: 0,
                jumlahKategori: {},
                jumlahSub: {},
                kelasKategori: kelasKategoriBarang,
                labelKategori: labelKategoriBarang,
                nonaktif,

                init() {
                    this.saring();
                    ['cari', 'kategori', 'subkategori'].forEach(kunci => this.$watch(kunci, () => this.saring()));
                },

                get tampil() {
                    return this.hasil.slice(0, this.batas);
                },

                // Sub kategori di bawah kategori terpilih yang masih punya barang
                // cocok; yang sedang dipilih tetap tampil walau hasilnya nol.
                get subkategoriTampil() {
                    if (!this.kategori) return [];
                    return subkategoriSemua.filter(sub => sub.kategori === this.kategori
                        && (this.jumlahSub[sub.id] > 0 || sub.id === this.subkategori));
                },

                // Jalur penyaring yang sedang berlaku, mis. "ATK › ALAT TULIS".
                get jalur() {
                    if (!this.kategori) return '';
                    const sub = subkategoriPerId.get(this.subkategori);
                    return sub ? `${this.kategori} › ${sub.nama}` : this.kategori;
                },

                get adaSaringan() {
                    return this.cari !== '' || this.kategori !== '' || this.subkategori !== '';
                },

                // Satu putaran atas seluruh barang: mencocokkan setiap kata,
                // menghitung jumlah per kategori dan sub kategori untuk chip
                // dan pilihan, lalu menaikkan barang yang nama atau kodenya
                // diawali kata pertama.
                saring() {
                    const kata = normal(this.cari).split(/\s+/).filter(Boolean);
                    const kategori = this.kategori;
                    const subkategori = this.subkategori;
                    const jumlahKategori = { '': 0 };
                    const jumlahSub = {};
                    const diawali = [];
                    const memuat = [];

                    for (let i = 0; i < daftar.length; i++) {
                        if (kata.length && !kata.every(k => indeksTeks[i].includes(k))) continue;

                        const item = daftar[i];
                        jumlahKategori[''] += 1;
                        jumlahKategori[item.category] = (jumlahKategori[item.category] ?? 0) + 1;
                        if (kategori && item.category !== kategori) continue;

                        if (item.subcategory_id) jumlahSub[item.subcategory_id] = (jumlahSub[item.subcategory_id] ?? 0) + 1;
                        if (subkategori && item.subcategory_id !== subkategori) continue;

                        const awal = kata.length && (indeksTeks[i].startsWith(kata[0]) || indeksKode[i].startsWith(kata[0]));
                        (awal ? diawali : memuat).push(item);
                    }

                    this.jumlahKategori = jumlahKategori;
                    this.jumlahSub = jumlahSub;
                    this.hasil = diawali.concat(memuat);
                    this.batas = 50;
                    this.aktif = 0;
                    if (this.$refs.daftar) this.$refs.daftar.scrollTop = 0;
                },

                // Sub kategori berada di bawah kategori: berganti kategori
                // (termasuk kembali ke Semua) selalu melepas sub kategorinya.
                setKategori(nilai) {
                    if (nilai === this.kategori) return;
                    this.kategori = nilai;
                    this.subkategori = '';
                },

                // Menekan sub kategori yang sedang aktif melepasnya kembali.
                setSubkategori(id) {
                    this.subkategori = this.subkategori === id ? '' : id;
                },

                reset() {
                    this.cari = '';
                    this.kategori = '';
                    this.subkategori = '';
                    this.$nextTick(() => this.$refs.cari?.focus());
                },

                buka() {
                    this.open = true;
                    this.$nextTick(() => this.$refs.cari?.focus());
                },

                tutup() {
                    this.open = false;
                },

                alih() {
                    this.open ? this.tutup() : this.buka();
                },

                geser(arah) {
                    if (!this.open || this.tampil.length === 0) return;
                    this.aktif = Math.min(Math.max(this.aktif + arah, 0), this.tampil.length - 1);
                    this.$nextTick(() => this.$refs.daftar
                        ?.querySelector(`[data-indeks="${this.aktif}"]`)
                        ?.scrollIntoView({ block: 'nearest' }));
                },

                pilihAktif() {
                    const item = this.tampil[this.aktif];
                    if (item) this.pilihItem(item);
                },

                // Penyaring kategori sengaja dipertahankan: barang berikutnya
                // sering dari sub kategori yang sama.
                pilihItem(item) {
                    if (this.nonaktif(item)) return;
                    pilih(item);
                    this.cari = '';
                    this.tutup();
                },
            };
        }
    </script>
@endonce
