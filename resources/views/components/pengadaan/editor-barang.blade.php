{{--
    Editor daftar barang usulan pengadaan, dipakai formulir usulan manual dan
    draf usulan. Dipasang di dalam <form>; baris dikirim sebagai
    `items[i][item_id|amount_request|note]`.

    $katalog     : KatalogBarang::untukPemilih(), sumber x-request.pemilih-barang
    $subkategori : KatalogBarang::subkategori()
    $baris       : baris awal — item_id, item_code, item_name, unit,
                   amount_request, source, note, permohonan[]
    $judul       : judul kartu daftar
    $kosong      : teks saat daftar kosong
    $tampilSumber: tampilkan badge dan penyaring sumber; formulir manual
                   tidak memerlukannya karena semua barisnya Manual
    $footer      : slot tombol di bawah daftar, boleh memakai `dirty` dan `rows`

    Barang yang ditambahkan di sini selalu bersumber Manual (ADR 0044).
    Keterangannya sengaja tidak dikosongkan setelah barang ditambahkan:
    beberapa barang yang diusulkan bersamaan biasanya punya alasan yang sama,
    jadi cukup diketik sekali. Keterangan yang sudah dipakai muncul sebagai
    saran sekali klik, dan dapat diterapkan ke barang yang belum berketerangan.
--}}
@props([
    'katalog',
    'subkategori' => null,
    'baris',
    'judul' => 'Barang yang Diusulkan',
    'kosong' => 'Belum ada barang.',
    'tampilSumber' => true,
])

@php
    $manual = \App\Enums\ProcurementRequestSource::Manual;
    $sumberSemua = \App\Enums\ProcurementRequestSource::cases();
    $garisSumber = collect($sumberSemua)
        ->mapWithKeys(fn ($sumber) => [$sumber->value => \App\Support\Warna::garis($sumber->warna())])
        ->all();
    $satuan = \App\Enums\SatuanBarang::options();
    $fieldClass = 'dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $stepperButton = 'flex size-9 shrink-0 items-center justify-center text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]';
@endphp

<div x-data="editorBarangUsulan()" {{ $attributes->class('grid grid-cols-1 items-start gap-6 lg:grid-cols-5') }}>
    {{-- Tambah barang, tetap terlihat saat daftar panjang digulir. --}}
    <section class="rounded-2xl border border-gray-200 bg-white lg:sticky lg:top-24 lg:col-span-2 dark:border-gray-800 dark:bg-white/[0.03]">
        <header class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Tambah Barang</h3>
            <x-ui.pill :warna="$manual->warna()" :ikon="$manual->icon()">{{ $manual->label() }}</x-ui.pill>
        </header>

        <div class="space-y-4 p-5">
            <x-request.pemilih-barang :subkategori="$subkategori" pilih="pilihBarang(item)"
                info="selectedItem ? `Stok ${selectedItem.stock_current}` : ''" />

            <div>
                <label for="jumlah-usulan" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Jumlah <span class="text-error-500">*</span>
                </label>
                <div class="flex items-center gap-3">
                    <div class="shadow-theme-xs inline-flex h-11 items-center overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700">
                        <button type="button" @click="jumlah = Math.max(1, (parseInt(jumlah, 10) || 1) - 1)" class="{{ $stepperButton }} h-full" aria-label="Kurangi jumlah">
                            <x-ui.icon name="minus" class="size-4" />
                        </button>
                        <input id="jumlah-usulan" type="number" min="1" max="999999" x-model.number="jumlah" @keydown.enter.prevent="tambah()"
                            class="h-full w-20 border-x border-gray-300 bg-transparent text-center text-sm text-gray-800 focus:outline-none dark:border-gray-700 dark:text-white/90">
                        <button type="button" @click="jumlah = (parseInt(jumlah, 10) || 0) + 1" class="{{ $stepperButton }} h-full" aria-label="Tambah jumlah">
                            <x-ui.icon name="plus" class="size-4" />
                        </button>
                    </div>
                    <span class="text-theme-sm text-gray-500 dark:text-gray-400" x-text="selectedItem ? (satuan[selectedItem.unit] ?? '') : ''"></span>
                </div>
            </div>

            <div>
                <label for="catatan-usulan" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Keterangan <span class="font-normal text-gray-400 dark:text-gray-500">(opsional)</span>
                </label>
                <div class="relative">
                    <input id="catatan-usulan" type="text" x-model="catatan" maxlength="500" autocomplete="off" @keydown.enter.prevent="tambah()"
                        placeholder="mis. persiapan rapat kerja triwulan IV" class="{{ $fieldClass }} h-11 pr-10">
                    <button type="button" x-show="catatan !== ''" style="display: none;" @click="catatan = ''; $nextTick(() => document.getElementById('catatan-usulan')?.focus())"
                        class="absolute top-1/2 right-2 -translate-y-1/2 rounded-md p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/[0.05]"
                        aria-label="Kosongkan keterangan">
                        <x-ui.icon name="x" class="size-3.5" />
                    </button>
                </div>

                <div x-show="saranCatatan.length > 0" style="display: none;" class="mt-2 flex flex-wrap gap-1.5" role="group" aria-label="Keterangan sebelumnya">
                    <template x-for="teks in saranCatatan" :key="teks">
                        <button type="button" @click="catatan = teks" :title="teks"
                            class="text-theme-xs inline-flex max-w-full items-center gap-1.5 rounded-full border border-gray-200 px-2.5 py-1 text-gray-600 transition hover:border-teal-300 hover:bg-teal-50 hover:text-teal-700 dark:border-gray-700 dark:text-gray-300 dark:hover:border-teal-500/40 dark:hover:bg-teal-500/10 dark:hover:text-teal-300">
                            <x-ui.icon name="history" class="size-3.5" />
                            <span class="max-w-[14rem] truncate" x-text="teks"></span>
                        </button>
                    </template>
                </div>

                <button type="button" x-show="catatan.trim() !== '' && tanpaCatatan.length > 0" style="display: none;" @click="terapkanCatatan()"
                    class="text-theme-xs mt-2 inline-flex items-center gap-1.5 font-medium text-teal-600 hover:text-teal-700 dark:text-teal-400 dark:hover:text-teal-300">
                    <x-ui.icon name="check" class="size-3.5" />
                    <span x-text="`Pakai untuk ${tanpaCatatan.length} barang tanpa keterangan`"></span>
                </button>
            </div>

            <p x-show="galat" x-text="galat" style="display: none;" class="text-theme-sm text-error-500" role="alert"></p>

            <button type="button" @click="tambah()"
                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg px-4 text-sm font-medium text-white transition">
                <x-ui.icon name="plus" class="size-4" />
                Tambah ke Daftar
            </button>
        </div>
    </section>

    {{-- Daftar barang. --}}
    <section class="rounded-2xl border border-gray-200 bg-white lg:col-span-3 dark:border-gray-800 dark:bg-white/[0.03]">
        <header class="space-y-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $judul }}</h3>
                <span class="text-theme-xs rounded-full bg-gray-100 px-2.5 py-0.5 font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300"
                    x-text="`${rows.length} barang`"></span>
            </div>

            @if ($tampilSumber)
                {{-- Penyaring hanya berarti bila daftarnya bercampur. Baris
                     yang disembunyikan tetap terkirim bersama formulir. --}}
                <div x-show="banyakSumber > 1" style="display: none;" class="flex flex-wrap gap-1.5" role="group" aria-label="Saring sumber">
                    @foreach ($sumberSemua as $sumber)
                        <button type="button" x-show="(hitung[@js($sumber->value)] ?? 0) > 0"
                            @click="saring = saring === @js($sumber->value) ? '' : @js($sumber->value)"
                            :aria-pressed="saring === @js($sumber->value)"
                            :class="saring === @js($sumber->value) ? 'ring-2 ring-current ring-offset-1 ring-offset-white dark:ring-offset-gray-900' : (saring ? 'opacity-50 hover:opacity-100' : '')"
                            class="text-theme-xs inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium transition {{ $sumber->badgeClass() }}">
                            <x-ui.icon :name="$sumber->icon()" class="size-3.5" />
                            {{ $sumber->label() }}
                            <span class="tabular-nums" x-text="hitung[@js($sumber->value)] ?? 0"></span>
                        </button>
                    @endforeach
                </div>
            @endif
        </header>

        <div x-show="rows.length === 0" class="flex flex-col items-center px-5 py-10 text-center">
            <span class="flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5">
                <x-ui.icon name="clipboard-list" class="size-6" />
            </span>
            <p class="text-theme-sm mt-3 text-gray-500 dark:text-gray-400">{{ $kosong }}</p>
        </div>

        <ul x-show="rows.length > 0" class="divide-y divide-gray-100 dark:divide-gray-800">
            <template x-for="(row, index) in rows" :key="row.item_id">
                <li x-show="!saring || row.source === saring" :data-baris="row.item_id"
                    class="flex items-start gap-3 border-l-[3px] px-5 py-3.5 transition-colors duration-700"
                    :class="[garis[row.source] ?? '', sorot === row.item_id ? 'bg-brand-50 dark:bg-brand-500/10' : '']">
                    <div class="min-w-0 flex-1">
                        @if ($tampilSumber)
                            <div class="mb-1">
                                @foreach ($sumberSemua as $sumber)
                                    {{-- Atribut komponen tidak mengompilasi direktif seperti @js, jadi nilainya digema. --}}
                                    <x-ui.pill x-show="row.source === '{{ $sumber->value }}'" :warna="$sumber->warna()" :ikon="$sumber->icon()">
                                        {{ $sumber->label() }}
                                    </x-ui.pill>
                                @endforeach
                            </div>
                        @endif

                        <p class="text-sm font-medium text-gray-800 dark:text-white/90" x-text="row.item_name"></p>
                        <p class="text-theme-xs text-gray-500 tabular-nums dark:text-gray-400" x-text="row.item_code"></p>

                        <ul x-show="row.permohonan.length > 0" class="text-theme-xs mt-1.5 space-y-0.5 text-gray-500 dark:text-gray-400">
                            <template x-for="asal in row.permohonan" :key="asal.nomor">
                                <li>
                                    <a :href="asal.url" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400" x-text="asal.nomor"></a>
                                    <span x-text="`· ${asal.pemohon} · ${asal.jumlah}`"></span>
                                </li>
                            </template>
                        </ul>

                        {{-- Keterangan hanya milik baris manual; klik untuk menyunting di tempat. --}}
                        <template x-if="row.source === @js($manual->value)">
                            <div class="mt-1.5">
                                <template x-if="sunting !== row.item_id">
                                    <button type="button" @click="sunting = row.item_id"
                                        class="text-theme-xs group flex max-w-full items-start gap-1.5 text-left"
                                        :class="row.note ? 'text-gray-600 dark:text-gray-300' : 'font-medium text-teal-600 hover:text-teal-700 dark:text-teal-400'">
                                        <x-ui.icon name="message-square" class="mt-0.5 size-3.5 text-teal-500 dark:text-teal-400" />
                                        <span x-text="row.note || 'Tambah keterangan'" :class="row.note ? 'italic' : ''"></span>
                                        <x-ui.icon name="pencil" class="mt-0.5 size-3 text-gray-400 opacity-0 transition group-hover:opacity-100" x-show="row.note" />
                                    </button>
                                </template>
                                <template x-if="sunting === row.item_id">
                                    <input type="text" x-model="row.note" maxlength="500" placeholder="Keterangan" aria-label="Keterangan barang"
                                        x-init="$nextTick(() => $el.focus())"
                                        @keydown.enter.prevent="sunting = null" @keydown.escape.prevent="sunting = null" @blur="sunting = null"
                                        class="{{ $fieldClass }} text-theme-xs h-8 px-3">
                                </template>
                            </div>
                        </template>

                        <input type="hidden" :name="`items[${index}][item_id]`" :value="row.item_id">
                        <input type="hidden" :name="`items[${index}][note]`" :value="row.note" :disabled="row.source !== @js($manual->value)">
                    </div>

                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <div class="inline-flex h-9 items-center overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700">
                            <button type="button" @click="ubahJumlah(row, -1)" class="{{ $stepperButton }}" aria-label="Kurangi jumlah">
                                <x-ui.icon name="minus" class="size-3.5" />
                            </button>
                            <input type="number" min="1" max="999999" :name="`items[${index}][amount_request]`" x-model.number="row.amount_request"
                                @keydown.enter.prevent aria-label="Jumlah diminta"
                                class="h-full w-14 border-x border-gray-300 bg-transparent text-center text-sm text-gray-800 focus:outline-none dark:border-gray-700 dark:text-white/90">
                            <button type="button" @click="ubahJumlah(row, 1)" class="{{ $stepperButton }}" aria-label="Tambah jumlah">
                                <x-ui.icon name="plus" class="size-3.5" />
                            </button>
                        </div>
                        <span class="text-theme-xs text-gray-400 dark:text-gray-500" x-text="satuan[row.unit] ?? ''"></span>
                    </div>

                    <button type="button" @click="hapus(index)"
                        class="hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10 rounded-lg p-1.5 text-gray-400 transition" aria-label="Hapus barang">
                        <x-ui.icon name="x" class="size-4" />
                    </button>
                </li>
            </template>
        </ul>

        @isset($footer)
            <footer class="space-y-3 border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                {{ $footer }}
            </footer>
        @endisset
    </section>
</div>

<script>
    function editorBarangUsulan() {
        const items = @json($katalog);
        const awal = @json($baris);
        const satuan = @json($satuan);
        const garis = @json($garisSumber);
        const manual = @js($manual->value);

        const salin = (rows) => rows.map(row => ({ ...row, note: row.note ?? '', permohonan: [...(row.permohonan ?? [])] }));
        const jejak = (rows) => JSON.stringify(rows.map(row => [row.item_id, Number(row.amount_request), (row.note ?? '').trim()]));
        const jejakAwal = jejak(salin(awal));

        return {
            items,
            satuan,
            garis,
            rows: salin(awal),
            selectedItem: null,
            jumlah: 1,
            catatan: '',
            galat: '',
            saring: '',
            sunting: null,
            sorot: null,

            get dirty() {
                return jejak(this.rows) !== jejakAwal;
            },

            get hitung() {
                return this.rows.reduce((hasil, row) => ({ ...hasil, [row.source]: (hasil[row.source] ?? 0) + 1 }), {});
            },

            get banyakSumber() {
                return Object.keys(this.hitung).length;
            },

            // Keterangan yang sudah dipakai di daftar ini, terbaru dulu.
            get saranCatatan() {
                const dipakai = this.rows
                    .filter(row => row.source === manual && row.note.trim() !== '')
                    .map(row => row.note.trim())
                    .reverse();

                return [...new Set(dipakai)].filter(teks => teks !== this.catatan.trim()).slice(0, 3);
            },

            get tanpaCatatan() {
                return this.rows.filter(row => row.source === manual && row.note.trim() === '');
            },

            pilihBarang(item) {
                this.selectedItem = item;
                this.galat = '';
                this.$nextTick(() => document.getElementById('jumlah-usulan')?.select());
            },

            tambah() {
                this.galat = '';
                const jumlah = parseInt(this.jumlah, 10);

                if (!this.selectedItem) {
                    this.galat = 'Pilih barang terlebih dahulu.';
                    return;
                }

                if (!(jumlah > 0)) {
                    this.galat = 'Jumlah minimal 1.';
                    return;
                }

                const catatan = this.catatan.trim();
                const ada = this.rows.find(row => row.item_id === this.selectedItem.item_id);

                if (ada) {
                    ada.amount_request = (parseInt(ada.amount_request, 10) || 0) + jumlah;
                    if (ada.source === manual && ada.note.trim() === '') ada.note = catatan;
                } else {
                    // Barang draf yang dibuang lalu ditambahkan lagi kembali ke
                    // sumber asalnya, sama dengan yang akan disimpan server.
                    const asal = awal.find(row => row.item_id === this.selectedItem.item_id);
                    const sumber = asal?.source ?? manual;

                    this.rows.push({
                        item_id: this.selectedItem.item_id,
                        item_code: this.selectedItem.item_code,
                        item_name: this.selectedItem.item_name,
                        unit: this.selectedItem.unit,
                        amount_request: jumlah,
                        source: sumber,
                        note: sumber === manual ? (catatan || asal?.note || '') : (asal?.note ?? ''),
                        permohonan: [...(asal?.permohonan ?? [])],
                    });
                }

                const id = this.selectedItem.item_id;
                this.selectedItem = null;
                this.jumlah = 1;
                this.saring = '';
                this.sorot = id;
                this.$nextTick(() => document.querySelector(`[data-baris="${id}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
                setTimeout(() => { if (this.sorot === id) this.sorot = null; }, 1200);
            },

            hapus(index) {
                this.rows.splice(index, 1);
            },

            ubahJumlah(row, arah) {
                row.amount_request = Math.max(1, (parseInt(row.amount_request, 10) || 1) + arah);
            },

            terapkanCatatan() {
                const catatan = this.catatan.trim();
                if (catatan === '') return;
                this.tanpaCatatan.forEach(row => { row.note = catatan; });
            },
        };
    }
</script>
