{{--
    Formulir sub kategori barang yang dipakai bersama halaman tambah dan ubah.

    $subcategory : App\Models\ItemSubcategory — instance kosong saat menambah
    $isEdit      : bool
    $action      : string                     — URL tujuan submit
--}}
<form method="POST" action="{{ $action }}" novalidate>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
        <x-form.text-field name="subcategory_code" label="Kode Sub Kategori" :value="$subcategory->subcategory_code" required
            placeholder="mis. 1010301001" autocomplete="off" />

        <x-form.select-field name="category" label="Kategori" :options="\App\Enums\KategoriBarang::options()"
            :selected="$subcategory->category?->value" required placeholder="Pilih kategori" />

        <div class="md:col-span-2">
            <x-form.text-field name="subcategory_name" label="Nama Sub Kategori" :value="$subcategory->subcategory_name" required
                placeholder="mis. ALAT TULIS" />
        </div>
    </div>

    {{-- Mengubah kategori ikut memindahkan kategori seluruh barangnya (ADR 0043). --}}
    @if ($isEdit && $subcategory->items_count > 0)
        <p class="text-theme-xs mt-4 text-gray-500 dark:text-gray-400">
            Dipakai {{ number_format($subcategory->items_count, 0, ',', '.') }} barang. Kategori barang-barang itu ikut berubah bila kategori di sini diubah.
        </p>
    @endif

    <div class="mt-8 flex items-center justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
        <a href="{{ route('item-subcategories.index') }}"
            class="rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            Batal
        </a>
        <button type="submit"
            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-5 py-3 text-sm font-medium text-white transition">
            {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Sub Kategori' }}
        </button>
    </div>
</form>
