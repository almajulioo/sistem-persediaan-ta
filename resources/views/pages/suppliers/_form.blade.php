{{--
    Formulir penyedia yang dipakai bersama halaman tambah dan ubah.

    $supplier : App\Models\Supplier  — instance kosong saat menambah
    $isEdit   : bool
    $action   : string               — URL tujuan submit
--}}
<form method="POST" action="{{ $action }}" novalidate>
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
        <x-form.text-field name="supplier_name" label="Nama Penyedia" :value="$supplier->supplier_name" required
            placeholder="mis. CV Sumber Rejeki" class="md:col-span-2" />

        <div class="md:col-span-2">
            <label for="address" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                Alamat<span class="text-error-500">*</span>
            </label>
            <textarea id="address" name="address" rows="3" required
                placeholder="Alamat lengkap penyedia"
                @class([
                    'dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30',
                    'border-error-500' => $errors->has('address'),
                    'border-gray-300 dark:border-gray-700' => !$errors->has('address'),
                ])>{{ old('address', $supplier->address) }}</textarea>

            @error('address')
                <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        <x-form.text-field name="contact_person" label="Narahubung" :value="$supplier->contact_person"
            placeholder="mis. Budi Santoso" />

        <x-form.text-field name="phone" label="Nomor Telepon" :value="$supplier->phone"
            placeholder="mis. 0721-123456" />

        <x-form.text-field name="email" label="Surel" type="email" :value="$supplier->email"
            placeholder="mis. penyedia@contoh.co.id" />

        <x-form.text-field name="npwp" label="NPWP" :value="$supplier->npwp"
            placeholder="mis. 01.234.567.8-901.000" />

        <div class="md:col-span-2">
            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                Status Penyedia<span class="text-error-500">*</span>
            </label>

            <div x-data="{ active: {{ old('is_active', $supplier->is_active ?? true) ? 'true' : 'false' }} }"
                class="flex h-11 items-center">
                {{-- value awal jadi cadangan bila Alpine belum sempat memuat --}}
                <input type="hidden" name="is_active"
                    value="{{ old('is_active', $supplier->is_active ?? true) ? 1 : 0 }}" :value="active ? 1 : 0" />

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
            @enderror
        </div>
    </div>

    <div class="mt-8 flex items-center justify-end gap-3 border-t border-gray-100 pt-6 dark:border-gray-800">
        <a href="{{ route('suppliers.index') }}"
            class="rounded-lg px-4 py-3 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
            Batal
        </a>
        <button type="submit"
            class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 rounded-lg px-5 py-3 text-sm font-medium text-white transition">
            {{ $isEdit ? 'Simpan Perubahan' : 'Simpan Penyedia' }}
        </button>
    </div>
</form>
