@php
    $isEdit = $isEdit ?? false;
    $existingItems = $isEdit && isset($request)
        ? $request->item->map(fn ($detail) => [
            'item_id' => $detail->item_id,
            'item_name' => $detail->item?->item_name ?? 'Barang',
            'qty' => (int) $detail->quantity_requested,
        ])->values()->all()
        : [];

@endphp

<form action="{{ $action }}" method="POST" x-data="requestForm()">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif
    <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">
        {{ $isEdit ? 'Ubah Kuantitas Permintaan Barang' : 'Tambah Barang' }}
    </h2>

    <div class="space-y-6">
        <!-- Input Form Section -->
        <div class="grid grid-cols-1 gap-5">
            
            <!-- Custom Searchable Select dengan Debounce -->
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                    Nama Barang <span class="text-error-500">*</span>
                </label>

                <!-- Trigger Input Field -->
                <div @click="open = !open" 
                    class="flex h-11 w-full cursor-pointer items-center justify-between rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                    <span x-text="selectedItemText || 'Pilih Barang'" :class="!selectedItemCode ? 'text-gray-400' : ''"></span>
                    <svg class="size-4 text-gray-500 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </div>

                <!-- Dropdown Card -->
                <div x-show="open" 
                    x-transition 
                    class="absolute z-30 mt-1 w-full rounded-lg border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-800 dark:bg-gray-900"
                    style="display: none;">
                    
                    <!-- Search Field dengan Debounce 300ms -->
                    <div class="p-1">
                        <input type="text" 
                            x-model.debounce.300ms="searchQuery" 
                            placeholder="Cari barang..." 
                            class="h-9 w-full rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90" />
                    </div>

                    <!-- Options List -->
                    <ul class="mt-1 max-h-48 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="item in filteredItems" :key="item.item_id">
                            <li @click="selectItem(item); open = false" 
                                class="cursor-pointer px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800 rounded-md transition-colors flex justify-between items-center">
                                <span x-text="item.item_name"></span>
                                <span class="text-xs text-gray-400" x-text="`Stok: ${item.stock_current}`"></span>
                            </li>
                        </template>

                        <template x-if="filteredItems.length === 0">
                            <li class="px-3 py-2 text-sm text-gray-400 text-center">
                                Barang tidak ditemukan.
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            <!-- Input Quantity -->
            <div>
                <x-form.text-field name="qty_input" label="Jumlah Barang" type="number" min="1"
                    placeholder="Inputkan Jumlah Barang" x-model="inputQty" />
            </div>
        </div>

        <!-- Add Button -->
        <div class="flex justify-end">
            <button type="button" @click="addItem"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-500/50 disabled:opacity-50">
                Tambah Barang
            </button>
        </div>

        <!-- Requested Items Table/List -->
        <div>
            <label class="mb-3 block text-sm font-medium text-gray-700 dark:text-gray-400">
                List Barang Yang Diminta
            </label>

            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-800">
                <template x-if="requestedList.length === 0">
                    <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                        Belum ada barang yang ditambahkan.
                    </div>
                </template>

                <template x-if="requestedList.length > 0">
                    <div class="divide-y divide-gray-200 dark:divide-gray-800">
                        <template x-for="(item, index) in requestedList" :key="item.item_id">
                            <div class="flex items-center justify-between bg-white px-4 py-3 dark:bg-gray-900">
                                <div>
                                    <span class="font-medium text-gray-800 dark:text-white" x-text="item.item_name"></span>
                                    <input type="hidden" :name="`items[${index}][item_id]`" :value="item.item_id">
                                </div>
                                <div class="flex items-center gap-4">
                                    <input type="number" min="1" max="999999" :name="`items[${index}][qty]`"
                                        x-model.number="item.qty" @change="updateQty(index, item.qty)"
                                        class="h-9 w-24 rounded-md border border-gray-300 px-3 text-right text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
                                        aria-label="Jumlah barang">
                                    
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

        <!-- Submit Button -->
        <div class="pt-4 flex justify-center">
            <button type="submit"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-6 py-3 text-sm font-medium text-white shadow-theme-xs hover:bg-brand-600 focus:outline-none">
                Submit Permintaan
            </button>
        </div>
    </div>
</form>

<x-ui.modal
    @show-request-validation-modal.window="message = $event.detail.message; open = true"
    :isOpen="false" :showCloseButton="false" class="max-w-md">
    <div class="p-6 text-center sm:p-8">
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-warning-50 text-warning-500 dark:bg-warning-500/15">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M12 9V13M12 17H12.01M10.29 3.86L1.82 18A2 2 0 0 0 3.54 21H20.46A2 2 0 0 0 22.18 18L13.71 3.86A2 2 0 0 0 10.29 3.86Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Periksa Input</h3>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400" x-text="message"></p>
        <button type="button" @click="open = false"
            class="mt-6 inline-flex justify-center rounded-lg bg-brand-500 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-600">
            Mengerti
        </button>
    </div>
</x-ui.modal>

<script>
    function requestForm() {
        return {
            items: @json($items),
            selectedItemCode: '',
            selectedItemText: '',
            searchQuery: '',
            inputQty: '',
            requestedList: @json($existingItems),

            init() {
                const mergedItems = new Map();
                this.requestedList.forEach(item => {
                    const current = mergedItems.get(item.item_id);
                    if (current) {
                        current.qty += parseInt(item.qty, 10) || 0;
                    } else {
                        mergedItems.set(item.item_id, {
                            ...item,
                            qty: parseInt(item.qty, 10) || 0,
                        });
                    }
                });
                this.requestedList = Array.from(mergedItems.values());
            },

            get filteredItems() {
                if (!this.searchQuery) return this.items;
                return this.items.filter(item => 
                    item.item_name.toLowerCase().includes(this.searchQuery.toLowerCase()) ||
                    item.item_id.toLowerCase().includes(this.searchQuery.toLowerCase())
                );
            },

            selectItem(item) {
                this.selectedItemCode = item.item_id;
                this.selectedItemText = `${item.item_name} (Stok: ${item.stock_current})`;
            },

            addItem() {
                if (!this.selectedItemCode || !this.inputQty || this.inputQty <= 0) {
                    this.$dispatch('show-request-validation-modal', {
                        message: 'Pilih barang dan isi jumlah barang dengan benar!'
                    });
                    return;
                }

                const selectedItem = this.items.find(i => i.item_id == this.selectedItemCode);
                if (!selectedItem) return;

                const existingIndex = this.requestedList.findIndex(i => i.item_id == selectedItem.item_id);
                if (existingIndex !== -1) {
                    const newQty = parseInt(this.requestedList[existingIndex].qty) + parseInt(this.inputQty);
                    
                    if (newQty > selectedItem.stock_current) {
                        this.$dispatch('show-request-validation-modal', {
                            message: `Total permintaan melebihi stok yang tersedia (${selectedItem.stock_current})!`
                        });
                        return;
                    }
                    
                    this.requestedList[existingIndex].qty = newQty;
                } else {
                    this.requestedList.push({
                        item_id: selectedItem.item_id,
                        item_name: selectedItem.item_name,
                        qty: parseInt(this.inputQty)
                    });
                }

                // Reset state input
                this.selectedItemCode = '';
                this.selectedItemText = '';
                this.searchQuery = '';
                this.inputQty = '';
            },

            removeItem(index) {
                this.requestedList.splice(index, 1);
            },

            updateQty(index, value) {
                const qty = parseInt(value, 10);
                if (!Number.isInteger(qty) || qty < 1) {
                    this.requestedList[index].qty = 1;
                }
            }
        }
    }
</script>