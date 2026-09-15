@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Realisasi Pengadaan</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Merealisasikan {{ $procurementRequest->judul() }} kepada penyedia. Pembelian barangnya sendiri
            terjadi di luar sistem; realisasi ini akan menunggu konfirmasi penerimaan dari Pengelola Gudang.
        </p>
    </div>

    <x-ui.flash />

    @php
        // Dihitung dulu di @php, bukan langsung di dalam @json(): direktif itu
        // memecah argumennya dengan explode(',', ...) tanpa memperhatikan
        // kurung/array bersarang, sehingga ekspresi dengan banyak koma di
        // dalamnya terpotong diam-diam.
        $itemPicker = $items->map(fn ($item) => [
            'item_id' => $item->item_id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
        ])->values();

        $usulanItemIds = $rincianDisetujui->pluck('item_id')->values();

        // Barang tambahan yang sempat diketik sebelum galat validasi
        // dipulihkan dari old(), supaya tidak hilang begitu saja.
        $tambahanAwal = collect(old('items', []))
            ->filter(fn ($baris) => ! empty($baris['item_id'] ?? null))
            ->map(function ($baris) use ($items) {
                $item = $items->firstWhere('item_id', $baris['item_id']);

                return [
                    'item_id' => $baris['item_id'],
                    'item_name' => $item ? "{$item->item_code} — {$item->item_name}" : 'Barang tidak dikenal',
                    'amount' => $baris['amount'] ?? '',
                    'unit_price' => $baris['unit_price'] ?? '',
                ];
            })
            ->values();
    @endphp

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6"
        x-data="realisasiForm()">
        <form action="{{ route('procurements.store') }}" method="POST">
            @csrf
            <input type="hidden" name="procurement_request_id" value="{{ $procurementRequest->procurement_request_id }}">

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-form.select-field name="supplier_id" label="Penyedia"
                    :options="$suppliers->pluck('supplier_name', 'supplier_id')->all()" required
                    placeholder="Pilih penyedia" />

                <x-form.text-field name="procurement_date" label="Tanggal Realisasi" type="date"
                    :value="now()->toDateString()" required />
            </div>

            <div class="mt-6">
                <label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Barang dari Usulan
                </label>

                <div class="custom-scrollbar max-w-full overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                    <table class="w-full min-w-[640px]">
                        <thead>
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                @foreach (['Barang', 'Disetujui', 'Jumlah Pesan', 'Harga Satuan (Rp)'] as $heading)
                                    <th class="px-5 py-3 text-left sm:px-6">
                                        <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">{{ $heading }}</p>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rincianDisetujui as $index => $detail)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="hidden" name="items[{{ $index }}][procurement_request_detail_id]"
                                            value="{{ $detail->procurement_request_detail_id }}">
                                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}
                                        </p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $detail->item?->item_code }}</p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ number_format($detail->amount_granted, 0, ',', '.') }} {{ $detail->item?->unit->label() }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="number" min="1"
                                            name="items[{{ $index }}][amount]"
                                            value="{{ old("items.$index.amount", $detail->amount_granted) }}"
                                            class="h-10 w-28 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                        @error("items.$index.amount")
                                            <p class="text-theme-xs text-error-500 mt-1">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="number" min="0" name="items[{{ $index }}][unit_price]"
                                            value="{{ old("items.$index.unit_price") }}" placeholder="0"
                                            class="h-10 w-32 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                        @error("items.$index.unit_price")
                                            <p class="text-theme-xs text-error-500 mt-1">{{ $message }}</p>
                                        @enderror
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @error('items')
                    <p class="text-theme-xs text-error-500 mt-2">{{ $message }}</p>
                @enderror

                <p class="text-theme-xs mt-3 text-gray-500 dark:text-gray-400">
                    Jumlah pesan boleh melebihi jumlah yang disetujui usulan. Barang dari usulan wajib ada dan
                    tidak dapat dihapus di sini.
                </p>
            </div>

            <div class="mt-8">
                <label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Tambah Barang di Luar Usulan
                </label>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_140px_160px_auto] sm:items-end">
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Barang</label>

                        <div @click="open = !open"
                            class="flex h-11 w-full cursor-pointer items-center justify-between rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <span x-text="selectedItemText || 'Pilih barang'" :class="!selectedItemId ? 'text-gray-400' : ''"></span>
                            <svg class="size-4 text-gray-500 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>

                        <div x-show="open" x-transition
                            class="absolute z-30 mt-1 w-full rounded-lg border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-800 dark:bg-gray-900"
                            style="display: none;">
                            <div class="p-1">
                                <input type="text" x-model.debounce.300ms="searchQuery" placeholder="Cari barang..."
                                    class="h-9 w-full rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                            </div>

                            <ul class="mt-1 max-h-48 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                                <template x-for="item in filteredItems" :key="item.item_id">
                                    <li @click="selectItem(item); open = false"
                                        class="cursor-pointer rounded-md px-3 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                                        <span x-text="`${item.item_code} — ${item.item_name}`"></span>
                                    </li>
                                </template>

                                <template x-if="filteredItems.length === 0">
                                    <li class="px-3 py-2 text-center text-sm text-gray-400">Barang tidak ditemukan.</li>
                                </template>
                            </ul>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Jumlah</label>
                        <input type="number" min="1" x-model="inputAmount" placeholder="0"
                            class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">Harga Satuan (Rp)</label>
                        <input type="number" min="0" x-model="inputPrice" placeholder="0"
                            class="h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                    </div>

                    <button type="button" @click="addItem"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center justify-center rounded-lg px-4 text-sm font-medium text-white transition">
                        Tambah
                    </button>
                </div>

                <p x-show="errorMessage" x-cloak x-text="errorMessage" class="text-theme-xs text-error-500 mt-2"></p>

                <template x-if="tambahan.length > 0">
                    <div class="mt-4 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                        <template x-for="(row, index) in tambahan" :key="row.item_id">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 bg-white px-4 py-3 last:border-b-0 dark:border-gray-800 dark:bg-gray-900">
                                <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="row.item_name"></p>

                                <div class="flex items-center gap-3">
                                    <input type="number" min="1" x-model="row.amount"
                                        :name="`items[${usulanCount + index}][amount]`"
                                        class="h-9 w-24 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                    <input type="number" min="0" x-model="row.unit_price"
                                        :name="`items[${usulanCount + index}][unit_price]`"
                                        class="h-9 w-28 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                    <input type="hidden" :name="`items[${usulanCount + index}][item_id]`" :value="row.item_id">
                                    <button type="button" @click="tambahan.splice(index, 1)"
                                        class="text-error-500 hover:text-error-600 text-sm">
                                        Hapus
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('procurement-requests.show', $procurementRequest) }}"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Batal
                </a>
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center justify-center rounded-lg px-6 py-2.5 text-sm font-medium text-white transition">
                    Buat Realisasi
                </button>
            </div>
        </form>
    </div>

    <script>
        function realisasiForm() {
            const items = @json($itemPicker);
            const usulanItemIds = @json($usulanItemIds);
            const tambahanAwal = @json($tambahanAwal);

            return {
                items,
                usulanCount: {{ $rincianDisetujui->count() }},
                selectedItemId: '',
                selectedItemText: '',
                searchQuery: '',
                inputAmount: '',
                inputPrice: '',
                errorMessage: '',
                tambahan: tambahanAwal,

                get filteredItems() {
                    const dipakai = new Set([...usulanItemIds, ...this.tambahan.map(row => row.item_id)]);
                    const q = this.searchQuery.toLowerCase();

                    return this.items.filter(item =>
                        !dipakai.has(item.item_id) &&
                        (!q || item.item_name.toLowerCase().includes(q) || item.item_code.toLowerCase().includes(q))
                    );
                },

                selectItem(item) {
                    this.selectedItemId = item.item_id;
                    this.selectedItemText = `${item.item_code} — ${item.item_name}`;
                },

                addItem() {
                    this.errorMessage = '';

                    if (!this.selectedItemId) {
                        this.errorMessage = 'Pilih barang yang akan ditambahkan.';
                        return;
                    }

                    if (!this.inputAmount || this.inputAmount <= 0) {
                        this.errorMessage = 'Isi jumlah pesan dengan benar.';
                        return;
                    }

                    if (this.inputPrice === '' || this.inputPrice < 0) {
                        this.errorMessage = 'Isi harga satuan dengan benar.';
                        return;
                    }

                    this.tambahan.push({
                        item_id: this.selectedItemId,
                        item_name: this.selectedItemText,
                        amount: parseInt(this.inputAmount),
                        unit_price: parseInt(this.inputPrice),
                    });

                    this.selectedItemId = '';
                    this.selectedItemText = '';
                    this.searchQuery = '';
                    this.inputAmount = '';
                    this.inputPrice = '';
                },
            };
        }
    </script>
@endsection
