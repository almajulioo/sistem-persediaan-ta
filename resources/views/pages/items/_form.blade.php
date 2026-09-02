{{--
    Formulir barang yang dipakai bersama halaman tambah dan ubah.

    $item   : App\Models\Item  — instance kosong saat menambah
    $isEdit : bool
    $action : string           — URL tujuan submit
--}}
<form method="POST" action="{{ $action }}" novalidate>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
        <x-form.text-field name="item_code" label="Kode Barang" :value="$item->item_code" required
            placeholder="mis. ATK-001" autocomplete="off"
            help="Huruf besar, angka, titik, tanda hubung, dan garis miring." />

        <x-form.text-field name="item_name" label="Nama Barang" :value="$item->item_name" required
            placeholder="mis. Kertas HVS A4 70 gram" />

        <x-form.select-field name="unit" label="Satuan" :options="\App\Enums\SatuanBarang::options()"
            :selected="$item->unit?->value" required placeholder="Pilih satuan" />

        {{-- Stok: hanya bisa diisi saat menambah barang --}}
        @if ($isEdit)
            <div>
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Stok Saat Ini
                </label>
                <div class="flex h-11 items-center gap-3">
                    <span class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">
                        {{ number_format($item->stock_current, 0, ',', '.') }}
                    </span>
                    <span class="text-theme-sm text-gray-500 dark:text-gray-400">{{ $item->unit->label() }}</span>
                    <span class="text-theme-xs rounded-full px-2.5 py-1 font-medium {{ $item->stockStatus()->badgeClass() }}">
                        {{ $item->stockStatus()->label() }}
                    </span>
                </div>
                <p class="text-theme-xs mt-1.5 text-gray-500 dark:text-gray-400">
                    Stok tidak dapat diubah di sini.
                    <a href="{{ route('items.show', $item) }}" class="text-brand-500 hover:text-brand-600 dark:text-brand-400">
                        Lihat riwayat mutasi
                    </a>
                </p>
            </div>
        @else
            <x-form.text-field name="stock_awal" label="Stok Awal" type="number" :value="$item->stock_current ?? 0"
                required min="0" placeholder="0"
                help="Dicatat sebagai mutasi Saldo Awal. Isi 0 bila stok belum ada." />
        @endif

        <x-form.text-field name="stock_min" label="Stok Minimum" type="number" :value="$item->stock_min ?? 0"
            required min="0" placeholder="0"
            help="Stok di bawah atau sama dengan angka ini ditandai menipis." />

        <x-form.text-field name="stock_max" label="Stok Maksimum" type="number" :value="$item->stock_max ?? 0"
            required min="0" placeholder="0" help="Isi 0 bila tidak dibatasi." />

        <div>
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                Status Barang<span class="text-error-500">*</span>
            </label>

            <div x-data="{ active: {{ old('is_active', $item->is_active ?? true) ? 'true' : 'false' }} }"
                class="flex h-11 items-center">
                {{-- value awal jadi cadangan bila Alpine belum sempat memuat --}}
                <input type="hidden" name="is_active"
                    value="{{ old('is_active', $item->is_active ?? true) ? 1 : 0 }}" :value="active ? 1 : 0" />

                <button type="button" @click="active = !active" :aria-pressed="active ? 'true' : 'false'"
                    class="flex cursor-pointer items-center gap-3">
                    <span :class="active ? 'bg-brand-500' : 'bg-gray-300 dark:bg-gray-700'"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors">
                        <span :class="active ? 'translate-x-5' : 'translate-x-1'"
                            class="inline-block size-4 rounded-full bg-white transition-transform"></span>
                    </span>
                    <span class="text-theme-sm text-gray-700 dark:text-gray-400"
                        x-text="active ? 'Aktif' : 'Diarsipkan'"></span>
                </button>
            </div>

            @error('is_active')
                <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
            @else
                <p class="text-theme-xs mt-1.5 text-gray-500 dark:text-gray-400">
                    Barang yang diarsipkan tidak muncul pada transaksi baru.
                </p>
            @enderror
        </div>
    </div>

    <div class="mt-8 flex items-center justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
        <a href="{{ route('items.index') }}"
            class="rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            Batal
        </a>
        <button type="submit"
            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-5 py-3 text-sm font-medium text-white transition">
            {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Barang' }}
        </button>
    </div>
</form>
