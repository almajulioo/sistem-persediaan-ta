@extends('layouts.app')

@section('content')
    <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Ajukan Usulan Pengadaan</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Pilih barang yang perlu diadakan beserta jumlah yang diminta. Usulan ini akan menunggu
            persetujuan Pejabat Pengadaan.
        </p>
    </div>

    <x-ui.flash />

    @if ($prefillItem)
        <div class="mb-6 rounded-2xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-500/30 dark:bg-warning-500/15">
            <p class="text-theme-sm text-warning-700 dark:text-warning-400">
                Diajukan dari pemantauan stok: <strong>{{ $prefillItem->item_name }}</strong> sudah dipilih di bawah
                — isi jumlah yang diminta lalu klik Tambah.
            </p>
        </div>
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
        <form action="{{ route('procurement-requests.store') }}" method="POST" x-data="procurementRequestForm()">
            @csrf
            <input type="hidden" name="source" value="{{ $source }}">

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-[1fr_160px_auto] sm:items-end">
                {{-- Pemilih barang --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Barang
                    </label>

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
                                    class="flex cursor-pointer items-center justify-between rounded-md px-3 py-2 text-sm text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                                    <span x-text="`${item.item_code} — ${item.item_name}`"></span>
                                    <span class="text-xs text-gray-400" x-text="`Stok: ${item.stock_current}`"></span>
                                </li>
                            </template>

                            <template x-if="filteredItems.length === 0">
                                <li class="px-3 py-2 text-center text-sm text-gray-400">Barang tidak ditemukan.</li>
                            </template>
                        </ul>
                    </div>
                </div>

                <x-form.text-field name="amount_input" label="Jumlah Diminta" type="number" min="1"
                    x-model="inputAmount" placeholder="0" />

                <button type="button" @click="addItem"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center justify-center gap-2 rounded-lg px-4 text-sm font-medium text-white transition disabled:opacity-50">
                    Tambah
                </button>
            </div>

            <p x-show="errorMessage" x-cloak x-text="errorMessage" class="text-theme-xs text-error-500 mt-2"></p>

            {{-- Daftar barang diusulkan --}}
            <div class="mt-6">
                <label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Barang yang Diusulkan
                </label>

                <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                    <template x-if="requestedList.length === 0">
                        <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                            Belum ada barang yang ditambahkan.
                        </div>
                    </template>

                    <template x-if="requestedList.length > 0">
                        <div class="divide-y divide-gray-200 dark:divide-gray-800">
                            <template x-for="(row, index) in requestedList" :key="row.item_id">
                                <div class="flex items-center justify-between bg-white px-4 py-3 dark:bg-gray-900">
                                    <div>
                                        <span class="font-medium text-gray-800 dark:text-white/90" x-text="row.item_name"></span>
                                        <input type="hidden" :name="`items[${index}][item_id]`" :value="row.item_id">
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <span class="text-sm text-gray-600 dark:text-gray-400" x-text="`Diminta ${row.amount_request}`"></span>
                                        <input type="hidden" :name="`items[${index}][amount_request]`" :value="row.amount_request">
                                        <button type="button" @click="removeItem(index)" class="text-error-500 hover:text-error-600 text-sm">
                                            Hapus
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('procurement-requests.index') }}"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Batal
                </a>
                <button type="submit"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center justify-center rounded-lg px-6 py-2.5 text-sm font-medium text-white transition">
                    Ajukan Usulan
                </button>
            </div>
        </form>
    </div>

    <script>
        function procurementRequestForm() {
            return {
                items: @json($items),
                selectedItemId: @json($prefillItem->item_id ?? ''),
                selectedItemText: @json($prefillItem ? "{$prefillItem->item_code} — {$prefillItem->item_name}" : ''),
                searchQuery: '',
                inputAmount: '',
                errorMessage: '',
                requestedList: [],

                get filteredItems() {
                    if (!this.searchQuery) return this.items;
                    const q = this.searchQuery.toLowerCase();
                    return this.items.filter(item =>
                        item.item_name.toLowerCase().includes(q) || item.item_code.toLowerCase().includes(q)
                    );
                },

                selectItem(item) {
                    this.selectedItemId = item.item_id;
                    this.selectedItemText = `${item.item_code} — ${item.item_name}`;
                },

                addItem() {
                    this.errorMessage = '';

                    if (!this.selectedItemId || !this.inputAmount || this.inputAmount <= 0) {
                        this.errorMessage = 'Pilih barang dan isi jumlah diminta dengan benar.';
                        return;
                    }

                    const selected = this.items.find(i => i.item_id === this.selectedItemId);
                    if (!selected) return;

                    const existingIndex = this.requestedList.findIndex(i => i.item_id === selected.item_id);
                    if (existingIndex !== -1) {
                        this.requestedList[existingIndex].amount_request =
                            parseInt(this.requestedList[existingIndex].amount_request) + parseInt(this.inputAmount);
                    } else {
                        this.requestedList.push({
                            item_id: selected.item_id,
                            item_name: selected.item_name,
                            amount_request: parseInt(this.inputAmount),
                        });
                    }

                    this.selectedItemId = '';
                    this.selectedItemText = '';
                    this.searchQuery = '';
                    this.inputAmount = '';
                },

                removeItem(index) {
                    this.requestedList.splice(index, 1);
                },
            };
        }
    </script>
@endsection
