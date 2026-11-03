@extends('layouts.app')

@php
    $fieldClass = 'dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $stepperButton = 'flex size-9 shrink-0 items-center justify-center text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]';
    $modul = \App\Enums\ModulPengajuan::Permohonan;
    $tambahStok = \App\Enums\ItemApplicationType::TambahStok;
    $barangBaru = \App\Enums\ItemApplicationType::BarangBaru;
    // Kartu jenis yang terpilih berbingkai warna jenisnya. Ditulis utuh
    // supaya terbaca pemindai Tailwind.
    $pilihanAktif = [
        $tambahStok->value => 'border-brand-500 bg-brand-50 ring-1 ring-brand-500 dark:bg-brand-500/10',
        $barangBaru->value => 'border-pink-500 bg-pink-50 ring-1 ring-pink-500 dark:bg-pink-500/10',
    ];
    $garisJenis = [
        $tambahStok->value => \App\Support\Warna::garis($tambahStok->warna()),
        $barangBaru->value => \App\Support\Warna::garis($barangBaru->warna()),
    ];
@endphp

@section('content')
    <x-modul.judul :modul="$modul" judul="Ajukan Permohonan">
        <x-slot:bawah>
            <nav class="mt-1" aria-label="Breadcrumb">
                <ol class="text-theme-sm flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                    <li><a href="{{ route('dashboard') }}" class="hover:text-violet-600">Dasbor</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('item-applications.index') }}" class="hover:text-violet-600">Permohonan</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="text-gray-800 dark:text-white/90">Ajukan</li>
                </ol>
            </nav>
        </x-slot:bawah>
    </x-modul.judul>

    {{-- Sebelum mengisi: pastikan ini pintu yang tepat, bukan Permintaan. --}}
    <x-modul.catatan :modul="$modul" class="mb-6" />

    <x-ui.flash />

    @if ($errors->any())
        <div class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mb-6 rounded-lg px-4 py-3 text-sm"
            role="alert" aria-live="assertive">
            Periksa kembali isian formulir. Terdapat {{ $errors->count() }} kesalahan.
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('item-applications.store') }}" x-data="applicationForm()"
        class="grid grid-cols-1 items-start gap-6 lg:grid-cols-5">
        @csrf

        <div class="space-y-6 lg:col-span-3">
            <x-form.section number="1" title="Keperluan" :warna="$modul->warna()" description="Jelaskan untuk apa barang-barang ini dibutuhkan.">
                <div>
                    <textarea id="purpose" name="purpose" rows="3" required maxlength="500" aria-label="Keperluan"
                        placeholder="mis. Kebutuhan ATK untuk kegiatan pemeriksaan triwulan IV"
                        @class([$fieldClass, 'border-error-500' => $errors->has('purpose')])>{{ old('purpose') }}</textarea>
                    @error('purpose')
                        <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>
            </x-form.section>

            <x-form.section number="2" title="Tambah Barang" :warna="$modul->warna()" description="Pilih jenis permohonan, isi rinciannya, lalu tambahkan ke daftar.">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Jenis permohonan">
                    @foreach ([
                        [$tambahStok, 'Tambah stok', 'Barang sudah terdaftar, stoknya kurang.'],
                        [$barangBaru, 'Barang baru', 'Barang belum ada di master barang.'],
                    ] as [$type, $label, $hint])
                        <button type="button" role="radio" :aria-checked="mode === '{{ $type->value }}'" @click="setMode('{{ $type->value }}')"
                            :class="mode === '{{ $type->value }}'
                                ? @js($pilihanAktif[$type->value])
                                : 'border-gray-200 hover:border-gray-300 dark:border-gray-800 dark:hover:border-gray-700'"
                            class="flex items-start gap-3 rounded-xl border p-4 text-left transition">
                            {{-- Ikon selalu berwarna jenisnya, pekat saat terpilih. --}}
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                :class="mode === '{{ $type->value }}' ? @js('text-white '.\App\Support\Warna::isi($type->warna())) : @js(\App\Support\Warna::lembut($type->warna()))">
                                <x-ui.icon :name="$type->icon()" class="size-5" />
                            </span>
                            <span>
                                <span class="block text-sm font-medium text-gray-800 dark:text-white/90">{{ $label }}</span>
                                <span class="text-theme-xs mt-0.5 block text-gray-500 dark:text-gray-400">{{ $hint }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>

                {{-- Tambah stok barang yang sudah ada di master barang. --}}
                <x-request.pemilih-barang :subkategori="$subcategories" x-show="mode === '{{ $tambahStok->value }}'"
                    info="selectedItem ? `Stok ${selectedItem.stock_current}` : ''">
                    <x-slot:badge>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300"
                            x-text="`Stok ${item.stock_current} ${units[item.unit] ?? ''}`"></span>
                    </x-slot:badge>
                    <x-slot:kosong>
                        <button type="button" @click="setMode('{{ $barangBaru->value }}'); tutup()"
                            class="mt-1 block w-full font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
                            Ajukan sebagai barang baru
                        </button>
                    </x-slot:kosong>
                </x-request.pemilih-barang>

                {{-- Barang yang belum ada di master barang. Setelah disetujui
                     menjadi usulan barang baru yang didaftarkan Pengelola Gudang. --}}
                <div x-show="mode === '{{ $barangBaru->value }}'" style="display: none;" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <label for="new_item_name" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Nama Barang Baru <span class="text-error-500">*</span>
                        </label>
                        <input id="new_item_name" type="text" x-model="newItemName" maxlength="255" placeholder="mis. Spidol Permanen Emas" class="h-11 {{ $fieldClass }}">
                    </div>
                    <div>
                        <label for="new_item_unit" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Satuan <span class="text-error-500">*</span>
                        </label>
                        <select id="new_item_unit" x-model="newItemUnit" class="h-11 {{ $fieldClass }}">
                            <option value="">Pilih satuan</option>
                            @foreach ($satuan as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label for="application_note" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        <span x-text="mode === '{{ $barangBaru->value }}' ? 'Spesifikasi dan Kegunaan' : 'Keterangan'"></span><span x-show="mode === '{{ $barangBaru->value }}'" class="text-error-500"> *</span>
                    </label>
                    <textarea id="application_note" x-model="note" rows="2" maxlength="500" class="{{ $fieldClass }}"
                        :placeholder="mode === '{{ $barangBaru->value }}' ? 'Jelaskan spesifikasi dan untuk apa barang ini dipakai' : 'Opsional, mis. stok habis sejak awal bulan'"></textarea>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="application_qty" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Jumlah <span class="text-error-500">*</span>
                        </label>
                        <div class="shadow-theme-xs inline-flex h-11 items-center overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700">
                            <button type="button" @click="quantity = Math.max(1, (parseInt(quantity, 10) || 1) - 1)" class="{{ $stepperButton }} h-full" aria-label="Kurangi jumlah">
                                <x-ui.icon name="minus" class="size-4" />
                            </button>
                            <input id="application_qty" type="number" min="1" max="999999" x-model.number="quantity" @keydown.enter.prevent="addRow"
                                class="h-full w-20 border-x border-gray-300 bg-transparent text-center text-sm text-gray-800 focus:outline-none dark:border-gray-700 dark:text-white/90">
                            <button type="button" @click="quantity = (parseInt(quantity, 10) || 0) + 1" class="{{ $stepperButton }} h-full" aria-label="Tambah jumlah">
                                <x-ui.icon name="plus" class="size-4" />
                            </button>
                        </div>
                    </div>
                    <button type="button" @click="addRow"
                        class="shadow-theme-xs ml-auto inline-flex h-11 items-center gap-2 rounded-lg px-4 text-sm font-medium transition {{ $modul->tombolClass() }}">
                        <x-ui.icon name="plus" class="size-4" />
                        Tambah ke Daftar
                    </button>
                </div>

                <p x-show="formError" x-text="formError" style="display: none;" class="text-theme-sm text-error-500"></p>
            </x-form.section>
        </div>

        {{-- Daftar barang, tetap terlihat saat halaman digulir. --}}
        <aside class="lg:sticky lg:top-24 lg:col-span-2">
            <div class="rounded-2xl border border-t-[3px] border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] {{ $modul->aksenClass() }}">
                <header class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Daftar Barang</h3>
                    <span class="text-theme-xs rounded-full px-2.5 py-0.5 font-medium {{ \App\Support\Warna::lembut($modul->warna()) }}"
                        x-text="`${rows.length} jenis`"></span>
                </header>

                <template x-if="rows.length === 0">
                    <div class="flex flex-col items-center px-5 py-10 text-center">
                        <span class="flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5">
                            <x-ui.icon name="clipboard-list" class="size-6" />
                        </span>
                        <p class="text-theme-sm mt-3 text-gray-500 dark:text-gray-400">Belum ada barang.<br>Isi rincian lalu tekan Tambah ke Daftar.</p>
                    </div>
                </template>

                <ul class="max-h-[28rem] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800" x-show="rows.length > 0">
                    <template x-for="(row, index) in rows" :key="row.key">
                        <li class="flex items-start gap-3 border-l-[3px] px-5 py-3" :class="@js($garisJenis)[row.type]">
                            <div class="min-w-0 flex-1">
                                @foreach ([$tambahStok, $barangBaru] as $jenis)
                                    <x-ui.pill x-show="row.type === '{{ $jenis->value }}'" :warna="$jenis->warna()" :ikon="$jenis->icon()">
                                        {{ $jenis->label() }}
                                    </x-ui.pill>
                                @endforeach
                                <p class="mt-1 truncate text-sm font-medium text-gray-800 dark:text-white/90" x-text="row.label"></p>
                                <p x-show="row.note" class="text-theme-xs truncate text-gray-500 dark:text-gray-400" x-text="row.note"></p>

                                <input type="hidden" :name="`items[${index}][type]`" :value="row.type">
                                <input type="hidden" :name="`items[${index}][item_id]`" :value="row.item_id" :disabled="row.type !== '{{ $tambahStok->value }}'">
                                <input type="hidden" :name="`items[${index}][item_name]`" :value="row.item_name" :disabled="row.type !== '{{ $barangBaru->value }}'">
                                <input type="hidden" :name="`items[${index}][unit]`" :value="row.unit" :disabled="row.type !== '{{ $barangBaru->value }}'">
                                <input type="hidden" :name="`items[${index}][note]`" :value="row.note">
                            </div>
                            <div class="inline-flex h-9 shrink-0 items-center overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700">
                                <button type="button" @click="row.quantity = Math.max(1, (parseInt(row.quantity, 10) || 1) - 1)" class="{{ $stepperButton }}" aria-label="Kurangi jumlah">
                                    <x-ui.icon name="minus" class="size-3.5" />
                                </button>
                                <input type="number" min="1" max="999999" :name="`items[${index}][quantity]`" x-model.number="row.quantity" aria-label="Jumlah dimohon"
                                    class="h-full w-14 border-x border-gray-300 bg-transparent text-center text-sm text-gray-800 focus:outline-none dark:border-gray-700 dark:text-white/90">
                                <button type="button" @click="row.quantity = (parseInt(row.quantity, 10) || 0) + 1" class="{{ $stepperButton }}" aria-label="Tambah jumlah">
                                    <x-ui.icon name="plus" class="size-3.5" />
                                </button>
                            </div>
                            <button type="button" @click="rows.splice(index, 1)" class="rounded-lg p-1.5 text-gray-400 transition hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10" aria-label="Hapus barang">
                                <x-ui.icon name="x" class="size-4" />
                            </button>
                        </li>
                    </template>
                </ul>

                <footer class="space-y-3 border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ([$tambahStok, $barangBaru] as $jenis)
                            <x-ui.pill :warna="$jenis->warna()" :ikon="$jenis->icon()">
                                <span x-text="rows.filter(r => r.type === '{{ $jenis->value }}').length"></span>
                                {{ strtolower($jenis->label()) }}
                            </x-ui.pill>
                        @endforeach
                    </div>
                    <button type="submit" :disabled="rows.length === 0"
                        class="shadow-theme-xs inline-flex w-full items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50 {{ $modul->tombolClass() }}">
                        Ajukan Permohonan
                        <x-ui.icon name="arrow-right" class="size-4" />
                    </button>
                    <a href="{{ route('item-applications.index') }}"
                        class="block text-center text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
                        Batal
                    </a>
                </footer>
            </div>
        </aside>
    </form>

    <script>
        function applicationForm() {
            const items = @json($items);
            const units = @json($satuan);

            return {
                items,
                units,
                mode: @js($tambahStok->value),
                selectedItem: null,
                newItemName: '',
                newItemUnit: '',
                note: '',
                quantity: 1,
                formError: '',
                rows: [],
                nextKey: 0,

                // Isi ulang daftar dari kiriman sebelumnya bila validasi server gagal.
                init() {
                    const old = @json(array_values((array) old('items', [])));
                    old.forEach(row => {
                        if (row.type === @js($tambahStok->value)) {
                            const item = items.find(i => i.item_id === row.item_id);
                            this.pushRow({ ...row, label: item ? `${item.item_code} — ${item.item_name}` : 'Barang' });
                        } else {
                            this.pushRow({ ...row, label: `${row.item_name ?? ''} (${units[row.unit] ?? row.unit ?? '-'})` });
                        }
                    });
                },

                setMode(mode) {
                    this.mode = mode;
                    this.formError = '';
                },

                pushRow(row) {
                    this.rows.push({ ...row, key: this.nextKey++ });
                },

                addRow() {
                    const quantity = parseInt(this.quantity, 10);
                    const note = this.note.trim();

                    if (!Number.isInteger(quantity) || quantity < 1) {
                        this.formError = 'Isi jumlah yang dimohon, minimal 1.';
                        return;
                    }

                    if (this.mode === @js($tambahStok->value)) {
                        if (!this.selectedItem) {
                            this.formError = 'Pilih barang yang ingin ditambah stoknya.';
                            return;
                        }

                        // Barang yang sama digabung, sama seperti formulir permintaan.
                        const existing = this.rows.find(r => r.type === @js($tambahStok->value) && r.item_id === this.selectedItem.item_id);
                        if (existing) {
                            existing.quantity = parseInt(existing.quantity, 10) + quantity;
                            existing.note = note || existing.note;
                        } else {
                            this.pushRow({
                                type: @js($tambahStok->value),
                                item_id: this.selectedItem.item_id,
                                label: `${this.selectedItem.item_code} — ${this.selectedItem.item_name}`,
                                note,
                                quantity,
                            });
                        }
                        this.selectedItem = null;
                    } else {
                        if (!this.newItemName.trim() || !this.newItemUnit || !note) {
                            this.formError = 'Lengkapi nama barang baru, satuan, serta spesifikasi dan kegunaannya.';
                            return;
                        }

                        this.pushRow({
                            type: @js($barangBaru->value),
                            item_name: this.newItemName.trim(),
                            unit: this.newItemUnit,
                            label: `${this.newItemName.trim()} (${units[this.newItemUnit]})`,
                            note,
                            quantity,
                        });
                        this.newItemName = '';
                        this.newItemUnit = '';
                    }

                    this.note = '';
                    this.quantity = 1;
                    this.formError = '';
                },
            };
        }
    </script>
@endsection
