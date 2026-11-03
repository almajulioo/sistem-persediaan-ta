@extends('layouts.app')

@php
    $isEdit = $isEdit ?? false;
    $requestData = $requestData ?? null;
    // Jumlah semula ikut dibawa saat mengubah, supaya Gudang melihat apa yang
    // ia ubah dari permintaan pemohon.
    $initialItems = old('items', $isEdit
        ? $requestData->item->map(fn ($detail) => [
            'item_id' => $detail->item_id,
            'qty' => (int) $detail->quantity_requested,
        ])->values()->all()
        : []);
    $originalQty = $isEdit
        ? $requestData->item->mapWithKeys(fn ($detail) => [$detail->item_id => (int) $detail->quantity_requested])->all()
        : [];
    $fieldClass = 'dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $stepperButton = 'flex size-9 shrink-0 items-center justify-center text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]';
@endphp

@section('content')
    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $isEdit ? 'Ubah Permintaan Umum' : 'Buat Permintaan Umum' }}</h2>
            <nav class="mt-1.5" aria-label="Breadcrumb">
                <ol class="text-theme-sm flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                    <li><a href="{{ route('dashboard') }}" class="hover:text-brand-500">Dasbor</a></li>
                    <li aria-hidden="true">/</li>
                    <li><a href="{{ route('request.umum.index') }}" class="hover:text-brand-500">Permintaan Umum</a></li>
                    <li aria-hidden="true">/</li>
                    <li class="text-gray-800 dark:text-white/90">{{ $isEdit ? $requestData->request_number : 'Buat' }}</li>
                </ol>
            </nav>
        </div>
        <p class="text-theme-sm max-w-md text-gray-500 dark:text-gray-400">
            {{ $isEdit
                ? 'Perbarui barang dan jumlah sebelum permintaan digabung. Setiap perubahan dicatat beserta alasannya dan dikirim ke pemohon.'
                : 'Catat kebutuhan umum unit kerja. Pengelola Gudang meninjaunya, lalu menggabungkan beberapa permintaan umum menjadi satu permintaan darurat.' }}
        </p>
    </div>

    <x-ui.flash />

    @if ($errors->any())
        <div class="bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500 mb-6 rounded-lg px-4 py-3 text-sm" role="alert">
            Periksa kembali isian formulir. Terdapat {{ $errors->count() }} kesalahan.
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ $isEdit ? route('request.umum.update', $requestData) : route('request.umum.store') }}" method="POST"
        x-data="umumRequestForm()" class="grid grid-cols-1 items-start gap-6 lg:grid-cols-5">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="space-y-6 lg:col-span-3">
            @if ($isEdit)
                {{-- Siapa yang meminta, supaya Gudang tahu permintaan siapa yang diubah. --}}
                <div class="flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    @if ($requestData->user)
                        <x-ui.user-avatar :user="$requestData->user" class="size-11 text-sm" />
                    @endif
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $requestData->user?->fullname ?? '-' }}</p>
                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                            {{ $requestData->user?->unit?->label() ?? '-' }} · diajukan {{ $requestData->date?->translatedFormat('d F Y') }}
                        </p>
                    </div>
                </div>
            @endif

            <x-form.section number="1" title="Pilih Barang" description="Cari barang yang terdaftar, isi jumlahnya, lalu tambahkan ke daftar.">
                <x-request.pemilih-barang :subkategori="$subcategories">
                    <x-slot:badge>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 font-medium text-gray-600 dark:bg-white/5 dark:text-gray-300"
                            x-text="`Stok ${item.stock_current} ${unitOf(item)}`"></span>
                    </x-slot:badge>
                    <x-slot:kosong>
                        <a href="{{ route('item-applications.create') }}" class="mt-1 block font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
                            Ajukan permohonan barang baru
                        </a>
                    </x-slot:kosong>
                </x-request.pemilih-barang>

                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="qty" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Jumlah <span class="text-error-500">*</span>
                        </label>
                        <div class="shadow-theme-xs inline-flex h-11 items-center overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700">
                            <button type="button" @click="quantity = Math.max(1, (parseInt(quantity, 10) || 1) - 1)" class="{{ $stepperButton }} h-full" aria-label="Kurangi jumlah">
                                <x-ui.icon name="minus" class="size-4" />
                            </button>
                            <input id="qty" type="number" min="1" max="999999" x-model.number="quantity" @keydown.enter.prevent="addItem"
                                class="h-full w-20 border-x border-gray-300 bg-transparent text-center text-sm text-gray-800 focus:outline-none dark:border-gray-700 dark:text-white/90">
                            <button type="button" @click="quantity = (parseInt(quantity, 10) || 0) + 1" class="{{ $stepperButton }} h-full" aria-label="Tambah jumlah">
                                <x-ui.icon name="plus" class="size-4" />
                            </button>
                        </div>
                    </div>
                    <p x-show="selectedItem" class="text-theme-sm pb-3 text-gray-500 dark:text-gray-400"
                        x-text="selectedItem ? `Stok gudang ${selectedItem.stock_current} ${unitOf(selectedItem)}` : ''"></p>
                    <button type="button" @click="addItem"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 ml-auto inline-flex h-11 items-center gap-2 rounded-lg px-4 text-sm font-medium text-white transition">
                        <x-ui.icon name="plus" class="size-4" />
                        Tambah ke Daftar
                    </button>
                </div>

                <p x-show="formError" x-text="formError" style="display: none;" class="text-theme-sm text-error-500"></p>
            </x-form.section>

            @if ($isEdit)
                {{-- Gudang mengubah permintaan orang lain; alasannya dibaca pemohon. --}}
                <x-form.section number="2" title="Alasan Perubahan" description="Wajib diisi bila ada barang atau jumlah yang diubah. Alasan ini dikirim ke pemohon.">
                    <div>
                        <textarea id="adjustment_note" name="adjustment_note" rows="4" maxlength="500" aria-label="Alasan perubahan"
                            placeholder="mis. jumlah disesuaikan dengan kebutuhan triwulan"
                            @class([$fieldClass, 'border-error-500' => $errors->has('adjustment_note')])>{{ old('adjustment_note') }}</textarea>
                        @error('adjustment_note')
                            <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </x-form.section>
            @endif
        </div>

        {{-- Daftar barang, tetap terlihat saat halaman digulir. --}}
        <aside class="lg:sticky lg:top-24 lg:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <header class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Daftar Barang</h3>
                    <span class="text-theme-xs bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400 rounded-full px-2.5 py-0.5 font-medium"
                        x-text="`${requestedItems.length} jenis`"></span>
                </header>

                <template x-if="requestedItems.length === 0">
                    <div class="flex flex-col items-center px-5 py-10 text-center">
                        <span class="flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5">
                            <x-ui.icon name="package" class="size-6" />
                        </span>
                        <p class="text-theme-sm mt-3 text-gray-500 dark:text-gray-400">Belum ada barang.<br>Pilih barang lalu tekan Tambah ke Daftar.</p>
                    </div>
                </template>

                <ul class="max-h-[28rem] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800" x-show="requestedItems.length > 0">
                    <template x-for="(item, index) in requestedItems" :key="item.item_id">
                        <li class="flex items-center gap-3 px-5 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90" x-text="nameOf(item)"></p>
                                <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                    <span x-text="unitOf(item)"></span>
                                    <template x-if="original[item.item_id] !== undefined && original[item.item_id] !== parseInt(item.qty, 10)">
                                        <span class="text-warning-600 dark:text-warning-400" x-text="` · semula ${original[item.item_id]}`"></span>
                                    </template>
                                    <template x-if="isEdit && original[item.item_id] === undefined">
                                        <span class="text-brand-600 dark:text-brand-400"> · tambahan</span>
                                    </template>
                                </p>
                                <input type="hidden" :name="`items[${index}][item_id]`" :value="item.item_id">
                            </div>
                            <div class="inline-flex h-9 items-center overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700">
                                <button type="button" @click="item.qty = Math.max(1, (parseInt(item.qty, 10) || 1) - 1)" class="{{ $stepperButton }}" aria-label="Kurangi jumlah">
                                    <x-ui.icon name="minus" class="size-3.5" />
                                </button>
                                <input type="number" min="1" max="999999" :name="`items[${index}][qty]`" x-model.number="item.qty" aria-label="Jumlah barang"
                                    class="h-full w-14 border-x border-gray-300 bg-transparent text-center text-sm text-gray-800 focus:outline-none dark:border-gray-700 dark:text-white/90">
                                <button type="button" @click="item.qty = (parseInt(item.qty, 10) || 0) + 1" class="{{ $stepperButton }}" aria-label="Tambah jumlah">
                                    <x-ui.icon name="plus" class="size-3.5" />
                                </button>
                            </div>
                            <button type="button" @click="requestedItems.splice(index, 1)" class="rounded-lg p-1.5 text-gray-400 transition hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10" aria-label="Hapus barang">
                                <x-ui.icon name="x" class="size-4" />
                            </button>
                        </li>
                    </template>
                </ul>

                <footer class="space-y-3 border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                    <div class="text-theme-sm flex items-center justify-between text-gray-500 dark:text-gray-400">
                        <span x-text="`${requestedItems.length} jenis barang`"></span>
                        <span x-text="`Total ${totalQty} unit`"></span>
                    </div>
                    @if ($isEdit)
                        <p x-show="removedCount > 0" style="display: none;" class="text-theme-xs text-warning-600 dark:text-warning-400"
                            x-text="`${removedCount} barang semula dihapus dari permintaan.`"></p>
                    @endif
                    <button type="submit" :disabled="requestedItems.length === 0"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex w-full items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                        {{ $isEdit ? 'Simpan Perubahan' : 'Kirim Permintaan Umum' }}
                        <x-ui.icon name="arrow-right" class="size-4" />
                    </button>
                    <a href="{{ route('request.umum.index') }}"
                        class="block text-center text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
                        Batal
                    </a>
                </footer>
            </div>
        </aside>
    </form>

    <script>
        function umumRequestForm() {
            return {
                items: @json($items),
                units: @json(\App\Enums\SatuanBarang::options()),
                original: @json((object) $originalQty),
                isEdit: @json($isEdit),
                selectedItem: null,
                quantity: 1,
                formError: '',
                requestedItems: @json(array_values((array) $initialItems)),

                get totalQty() {
                    return this.requestedItems.reduce((total, item) => total + (parseInt(item.qty, 10) || 0), 0);
                },

                get removedCount() {
                    const kept = new Set(this.requestedItems.map(item => item.item_id));
                    return Object.keys(this.original).filter(id => !kept.has(id)).length;
                },

                master(item) {
                    return this.items.find(i => i.item_id === item.item_id);
                },

                // Nama dan satuan diambil dari master barang, supaya daftar
                // tetap terbaca walau diisi ulang dari kiriman yang gagal.
                nameOf(item) {
                    return this.master(item)?.item_name ?? 'Barang';
                },

                unitOf(item) {
                    const unit = (this.master(item) ?? item).unit;
                    return this.units[unit] ?? unit ?? '';
                },

                addItem() {
                    const qty = parseInt(this.quantity, 10);

                    if (!this.selectedItem || !Number.isInteger(qty) || qty < 1) {
                        this.formError = 'Pilih barang dan isi jumlahnya terlebih dahulu.';
                        return;
                    }

                    // Barang yang sama digabung, sama seperti formulir permintaan.
                    const existing = this.requestedItems.find(item => item.item_id === this.selectedItem.item_id);
                    if (existing) {
                        existing.qty = (parseInt(existing.qty, 10) || 0) + qty;
                    } else {
                        this.requestedItems.push({ item_id: this.selectedItem.item_id, qty });
                    }

                    this.selectedItem = null;
                    this.quantity = 1;
                    this.formError = '';
                },
            };
        }
    </script>
@endsection
