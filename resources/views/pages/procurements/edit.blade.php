@extends('layouts.app')

@section('content')
    @php
        // Barang dari usulan yang benar-benar disetujui (amount_granted > 0) —
        // harus sama persis dengan UpdateProcurementRequest::rincianDisetujui(),
        // karena inilah yang menentukan barang mana yang tidak boleh dihapus.
        $disetujui = $procurement->procurementRequest?->details
            ->filter(fn ($detail) => $detail->disetujui())
            ->keyBy('item_id') ?? collect();

        $usulanDetails = $procurement->details->filter(fn ($detail) => $disetujui->has($detail->item_id))->values();
        $tambahanDetails = $procurement->details->reject(fn ($detail) => $disetujui->has($detail->item_id))->values();

        // Dihitung dulu di @php, bukan langsung di dalam @json() — lihat
        // catatan yang sama pada procurements/create.blade.php.
        $itemPicker = $items->map(fn ($item) => [
            'item_id' => $item->item_id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
        ])->values();

        $usulanItemIds = $usulanDetails->pluck('item_id')->values();

        $tambahanAwal = old('items') !== null
            ? collect(old('items', []))
                ->filter(function ($baris) use ($procurement, $disetujui) {
                    $detailId = $baris['procurement_detail_id'] ?? null;

                    if (empty($detailId)) {
                        return ! empty($baris['item_id'] ?? null);
                    }

                    $detail = $procurement->details->firstWhere('procurement_detail_id', $detailId);

                    return $detail !== null && ! $disetujui->has($detail->item_id);
                })
                ->map(function ($baris) use ($procurement, $items) {
                    $detailId = $baris['procurement_detail_id'] ?? null;
                    $detail = $detailId ? $procurement->details->firstWhere('procurement_detail_id', $detailId) : null;
                    $itemId = $detail?->item_id ?? ($baris['item_id'] ?? null);
                    $item = $detail?->item ?? $items->firstWhere('item_id', $itemId);

                    return [
                        'procurement_detail_id' => $detailId,
                        'item_id' => $itemId,
                        'item_name' => $item ? "{$item->item_code} — {$item->item_name}" : 'Barang tidak dikenal',
                        'amount' => $baris['amount'] ?? '',
                        'unit_price' => $baris['unit_price'] ?? '',
                    ];
                })
                ->values()
            : $tambahanDetails->map(fn ($detail) => [
                'procurement_detail_id' => $detail->procurement_detail_id,
                'item_id' => $detail->item_id,
                'item_name' => ($detail->item?->item_code ?? '?').' — '.($detail->item?->item_name ?? 'Barang tidak dikenal'),
                'amount' => $detail->amount,
                'unit_price' => $detail->unit_price,
            ])->values();
    @endphp

    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Ubah {{ $procurement->judul() }}</h2>
    </div>

    <x-ui.flash />

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6"
        x-data="realisasiEditForm()">
        <form action="{{ route('procurements.update', $procurement) }}" method="POST">
            @csrf
            @method('PUT')

            {{-- Usulan sumber tidak dapat dipindah: ia mengunci identitas
                 dokumen ini. Realisasi langsung pun selamanya langsung, jadi
                 keduanya sama-sama tampil sebagai keterangan, bukan pilihan
                 (ADR 0025, ADR 0031). --}}
            <div class="bg-gray-50 dark:bg-white/[0.03] mb-5 rounded-lg p-4">
                <p class="text-theme-xs text-gray-500 dark:text-gray-400">Usulan Sumber</p>
                <p class="text-theme-sm mt-0.5 font-medium text-gray-800 dark:text-white/90">
                    {{ $procurement->tanpaUsulan() ? 'Langsung, tanpa usulan' : ($procurement->procurementRequest?->judul() ?? '—') }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-form.select-field name="supplier_id" label="Penyedia"
                    :options="$suppliers->pluck('supplier_name', 'supplier_id')->all()"
                    :selected="$procurement->supplier_id" required placeholder="Pilih penyedia" />

                <x-form.text-field name="procurement_date" label="Tanggal Realisasi" type="date"
                    :value="$procurement->procurement_date->toDateString()" required />
            </div>

            @if ($procurement->tanpaUsulan())
                {{-- Dasar pengadaan ikut dapat diperbaiki. Mengubahnya pun
                     mengembalikan realisasi ke MenungguPersetujuan seperti
                     perubahan lain, sehingga dasar yang sudah disetujui tidak
                     bisa ditukar diam-diam setelahnya (ADR 0030, ADR 0031). --}}
                <div class="mt-6 rounded-xl border border-gray-200 p-5 dark:border-gray-800">
                    <h4 class="text-theme-sm font-medium text-gray-800 dark:text-white/90">Dasar Pengadaan</h4>

                    <div class="mt-4">
                        <x-form.select-field name="basis_reason" label="Alasan" required
                            :options="\App\Enums\AlasanPengadaan::options()"
                            :selected="$procurement->basis_reason?->value" placeholder="Pilih dasar pengadaan" />
                    </div>

                    <div class="mt-4">
                        <label for="basis_note" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Keterangan<span class="text-error-500">*</span>
                        </label>
                        <textarea id="basis_note" name="basis_note" rows="3" required
                            class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('basis_note', $procurement->basis_note) }}</textarea>
                        @error('basis_note')
                            <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            @endif

            @unless ($procurement->tanpaUsulan())
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
                            @foreach ($usulanDetails as $index => $detail)
                                @php $granted = $disetujui->get($detail->item_id)?->amount_granted; @endphp
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="hidden" name="items[{{ $index }}][procurement_detail_id]"
                                            value="{{ $detail->procurement_detail_id }}">
                                        <p class="text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ $detail->item?->item_name ?? 'Barang tidak dikenal' }}
                                        </p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $detail->item?->item_code }}</p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                                            {{ $granted !== null ? number_format($granted, 0, ',', '.') : '—' }}
                                            {{ $detail->item?->unit->label() }}
                                        </p>
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="number" min="1"
                                            name="items[{{ $index }}][amount]"
                                            value="{{ old("items.$index.amount", $detail->amount) }}"
                                            class="h-10 w-28 rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                                        @error("items.$index.amount")
                                            <p class="text-theme-xs text-error-500 mt-1">{{ $message }}</p>
                                        @enderror
                                    </td>
                                    <td class="px-5 py-4 sm:px-6">
                                        <input type="number" min="0" name="items[{{ $index }}][unit_price]"
                                            value="{{ old("items.$index.unit_price", $detail->unit_price) }}"
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

            </div>
            @endunless

            <div class="mt-8">
                <label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    {{ $procurement->tanpaUsulan() ? 'Daftar Barang' : 'Barang di Luar Usulan' }}
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
                                    <li class="px-3 py-2 text-center text-sm text-gray-400">
                                        Barang tidak ditemukan.
                                        <a href="{{ route('item-proposals.create') }}" target="_blank" rel="noopener"
                                            class="text-brand-500 hover:text-brand-600 dark:text-brand-400 mt-1 block">
                                            Usulkan barang baru
                                        </a>
                                    </li>
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
                                    <template x-if="row.procurement_detail_id">
                                        <input type="hidden" :name="`items[${usulanCount + index}][procurement_detail_id]`" :value="row.procurement_detail_id">
                                    </template>
                                    <button type="button" @click="tambahan.splice(index, 1)"
                                        class="text-error-500 hover:text-error-600 text-sm">
                                        Hapus
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Satu tempat untuk galat `items`, terlihat pada kedua rupa
                     formulir: aturan cakupan usulan maupun "minimal satu
                     barang" pada realisasi langsung. --}}
                @error('items')
                    <p class="text-theme-xs text-error-500 mt-3">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('procurements.show', $procurement) }}"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Batal
                </a>
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center justify-center rounded-lg px-6 py-2.5 text-sm font-medium text-white transition">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>

    <script>
        function realisasiEditForm() {
            const items = @json($itemPicker);
            const usulanItemIds = @json($usulanItemIds);
            const tambahanAwal = @json($tambahanAwal);

            return {
                items,
                usulanCount: {{ $usulanDetails->count() }},
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
                        procurement_detail_id: null,
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
