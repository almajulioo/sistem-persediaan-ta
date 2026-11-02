@php
    $isEdit = $isEdit ?? false;
    $canCreateEmergency = $canCreateEmergency ?? false;
    $users = $users ?? collect();
    $satuanLabels = \App\Enums\SatuanBarang::options();
    $existingItems = $isEdit && isset($request)
        ? $request->item->map(fn ($detail) => [
            'item_id' => $detail->item_id,
            'item_name' => $detail->item?->item_name ?? 'Barang',
            'unit' => $detail->item?->unit?->label() ?? '',
            // Mulai dari jumlah yang sudah diputuskan bila ada (permintaan
            // darurat bisa diubah lebih dari sekali sebelum diselesaikan).
            'qty' => (int) ($detail->quantity_approved ?? $detail->quantity_requested),
            // Batas atas jumlah disetujui adalah jumlah yang diminta.
            'max' => (int) $detail->quantity_requested,
        ])->values()->all()
        : [];
    $fieldClass = 'dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $stepperButton = 'flex size-9 shrink-0 items-center justify-center text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 disabled:opacity-40 dark:text-gray-400 dark:hover:bg-white/[0.05]';
@endphp

{{--
    Dua kolom: isian di kiri, daftar barang di kanan yang tetap terlihat saat
    halaman digulir. Dipakai Buat Permintaan dan Ubah Kuantitas.
--}}
<form action="{{ $action }}" method="POST" x-data="requestForm()" class="grid grid-cols-1 items-start gap-6 lg:grid-cols-5">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="space-y-6 lg:col-span-3">
        @if ($canCreateEmergency && ! $isEdit)
            <div class="rounded-2xl border border-warning-200 bg-warning-50 p-5 dark:border-warning-500/30 dark:bg-warning-500/10">
                <input type="hidden" name="emergency" :value="isEmergency ? '1' : '0'">
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" x-model="isEmergency" class="mt-0.5 size-4 rounded border-gray-300 text-warning-500 focus:ring-warning-500">
                    <span>
                        <span class="block text-sm font-medium text-gray-800 dark:text-white/90">Permintaan darurat</span>
                        <span class="mt-1 block text-xs text-gray-600 dark:text-gray-400">Centang bila permintaan dibuat atas nama peminta lain.</span>
                    </span>
                </label>

                <div x-show="isEmergency" x-transition class="relative mt-4" x-data="{ open: false }" @click.outside="open = false" style="display: none;">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Peminta <span class="text-error-500">*</span>
                    </label>
                    <input type="hidden" name="requester_id" :value="selectedRequesterId">
                    <button type="button" @click="open = !open" class="flex h-11 w-full items-center justify-between rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-left text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <span x-text="selectedRequesterText || 'Pilih peminta'" :class="!selectedRequesterId ? 'text-gray-400' : ''"></span>
                        <x-ui.icon name="chevron-right" class="size-4 rotate-90 text-gray-400" />
                    </button>
                    <div x-show="open" x-transition class="absolute z-30 mt-1 w-full rounded-lg border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-800 dark:bg-gray-900" style="display: none;">
                        <input type="text" x-model="requesterSearch" placeholder="Cari nama, username, atau unit..." class="h-9 w-full rounded-md border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90">
                        <ul class="mt-1 max-h-48 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                            <template x-for="user in filteredRequesters" :key="user.user_id">
                                <li @click="selectRequester(user); open = false" class="cursor-pointer rounded-md px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">
                                    <span class="block" x-text="user.fullname"></span>
                                    <span class="text-xs text-gray-400" x-text="`@${user.username} - ${user.unit}`"></span>
                                </li>
                            </template>
                            <template x-if="filteredRequesters.length === 0">
                                <li class="px-3 py-2 text-center text-sm text-gray-400">Peminta tidak ditemukan.</li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <x-form.section number="1" title="Nota Dinas"
            :description="$isEdit ? 'Sudah terisi nomor dari pemohon; ubah hanya bila keliru.' : 'Nomor urut Nota Dinas dari agenda unit Anda. Nomor BAST memakai nomor urut yang sama.'">
            @if ($isEdit)
                <x-form.text-field name="nd_sequence" label="Nomor Urut Nota Dinas" required inputmode="numeric"
                    :value="$request->ndSequence()" placeholder="mis. 51"
                    :help="'Nomor lengkap: '.($request->nd_number ?? $request->documentNumber('ND'))" />
            @else
                <x-form.text-field name="nd_sequence" label="Nomor Urut Nota Dinas" required inputmode="numeric"
                    maxlength="6" placeholder="mis. 51" x-model="ndSequence" />
                <div class="rounded-lg bg-gray-50 px-4 py-3 dark:bg-white/[0.03]">
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">Nomor lengkap</p>
                    <p class="mt-0.5 font-mono text-sm break-all text-gray-800 dark:text-white/90" x-text="ndPreview"></p>
                </div>
            @endif
        </x-form.section>

        @if ($isEdit)
            {{-- Alasan ini dibaca pemohon di riwayat permintaan dan notifikasinya. --}}
            <x-form.section number="2" title="Alasan Perubahan"
                description="Wajib diisi bila ada jumlah yang tidak sama dengan permintaan. Isi 0 untuk barang yang tidak diberikan.">
                <div>
                    <textarea id="adjustment_note" name="adjustment_note" rows="4" maxlength="500" aria-label="Alasan perubahan"
                        placeholder="mis. stok kertas tinggal 5 rim, sisanya menunggu pengadaan"
                        @class([$fieldClass, 'border-error-500' => $errors->has('adjustment_note')])>{{ old('adjustment_note') }}</textarea>
                    @error('adjustment_note')
                        <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>
            </x-form.section>
        @else
            <x-form.section number="2" title="Pilih Barang" description="Cari barang yang terdaftar, isi jumlahnya, lalu tambahkan ke daftar.">
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                        Barang <span class="text-error-500">*</span>
                    </label>
                    <button type="button" @click="open = !open; $nextTick(() => $refs.itemSearch?.focus())"
                        class="shadow-theme-xs flex h-11 w-full items-center gap-3 rounded-lg border border-gray-300 bg-transparent px-4 text-left text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                        <x-ui.icon name="search" class="size-4 text-gray-400" />
                        <span class="flex-1 truncate" x-text="selectedItem ? selectedItem.item_name : 'Cari dan pilih barang'" :class="!selectedItem ? 'text-gray-400' : ''"></span>
                        <span x-show="selectedItem" class="text-theme-xs text-gray-500 dark:text-gray-400" x-text="selectedItem ? selectedItem.item_code : ''"></span>
                    </button>

                    <div x-show="open" x-transition style="display: none;"
                        class="absolute z-30 mt-1 w-full rounded-xl border border-gray-200 bg-white p-2 shadow-lg dark:border-gray-800 dark:bg-gray-900">
                        <input type="text" x-ref="itemSearch" x-model.debounce.200ms="searchQuery" placeholder="Ketik nama atau kode barang..."
                            class="h-10 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:border-brand-300 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90">
                        <ul class="mt-2 max-h-64 space-y-0.5 overflow-y-auto">
                            <template x-for="item in filteredItems" :key="item.item_id">
                                <li>
                                    <button type="button" @click="selectItem(item); open = false" :disabled="item.available < 1"
                                        class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-left text-sm transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-50 dark:hover:bg-white/[0.05]">
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-gray-800 dark:text-white/90" x-text="item.item_name"></span>
                                            <span class="text-theme-xs text-gray-500 dark:text-gray-400" x-text="item.item_code"></span>
                                        </span>
                                        <span class="text-theme-xs shrink-0 text-right">
                                            <span class="block rounded-full px-2 py-0.5 font-medium"
                                                :class="item.available < 1 ? 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400' : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300'"
                                                x-text="stockLabel(item)"></span>
                                            <span x-show="item.reserved > 0" class="mt-0.5 block text-gray-400" x-text="`${item.reserved} ditahan permintaan lain`"></span>
                                        </span>
                                    </button>
                                </li>
                            </template>
                            <template x-if="filteredItems.length === 0">
                                <li class="px-3 py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Barang tidak ditemukan.
                                    <a href="{{ route('item-applications.create') }}" class="mt-1 block font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
                                        Ajukan permohonan barang baru
                                    </a>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="qty_input" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Jumlah <span class="text-error-500">*</span>
                        </label>
                        <div class="shadow-theme-xs inline-flex h-11 items-center overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700">
                            <button type="button" @click="inputQty = Math.max(1, (parseInt(inputQty, 10) || 1) - 1)" class="{{ $stepperButton }} h-full" aria-label="Kurangi jumlah">
                                <x-ui.icon name="minus" class="size-4" />
                            </button>
                            <input id="qty_input" type="number" min="1" x-model.number="inputQty" @keydown.enter.prevent="addItem"
                                class="h-full w-20 border-x border-gray-300 bg-transparent text-center text-sm text-gray-800 focus:outline-none dark:border-gray-700 dark:text-white/90">
                            <button type="button" @click="inputQty = (parseInt(inputQty, 10) || 0) + 1" class="{{ $stepperButton }} h-full" aria-label="Tambah jumlah">
                                <x-ui.icon name="plus" class="size-4" />
                            </button>
                        </div>
                    </div>
                    <p x-show="selectedItem" class="text-theme-sm pb-3 text-gray-500 dark:text-gray-400"
                        x-text="selectedItem ? `Tersedia ${selectedItem.available} dari ${selectedItem.stock_current} ${unitOf(selectedItem)}` : ''"></p>
                    <button type="button" @click="addItem"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 ml-auto inline-flex h-11 items-center gap-2 rounded-lg px-4 text-sm font-medium text-white transition">
                        <x-ui.icon name="plus" class="size-4" />
                        Tambah ke Daftar
                    </button>
                </div>

                {{-- Barang yang belum terdaftar dan kekurangan stok tidak
                     ditampung di sini; keduanya dimohonkan lewat Permohonan. --}}
                <div class="flex items-start gap-3 rounded-lg border border-dashed border-gray-300 px-4 py-3 dark:border-gray-700">
                    <x-ui.icon name="clipboard-list" class="mt-0.5 size-5 text-gray-400" />
                    <p class="text-theme-sm text-gray-600 dark:text-gray-400">
                        Barang belum terdaftar atau stoknya tidak mencukupi?
                        <a href="{{ route('item-applications.create') }}" class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">
                            Ajukan lewat menu Permohonan
                        </a>.
                    </p>
                </div>
            </x-form.section>
        @endif
    </div>

    {{-- Daftar barang, tetap terlihat saat halaman digulir. --}}
    <aside class="lg:sticky lg:top-24 lg:col-span-2">
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <header class="flex items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">
                    {{ $isEdit ? 'Jumlah Disetujui' : 'Daftar Barang' }}
                </h3>
                <span class="text-theme-xs bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400 rounded-full px-2.5 py-0.5 font-medium"
                    x-text="`${requestedList.length} jenis`"></span>
            </header>

            <template x-if="requestedList.length === 0">
                <div class="flex flex-col items-center px-5 py-10 text-center">
                    <span class="flex size-12 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5">
                        <x-ui.icon name="package" class="size-6" />
                    </span>
                    <p class="text-theme-sm mt-3 text-gray-500 dark:text-gray-400">Belum ada barang.<br>Pilih barang lalu tekan Tambah ke Daftar.</p>
                </div>
            </template>

            <ul class="max-h-[28rem] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800" x-show="requestedList.length > 0">
                <template x-for="(item, index) in requestedList" :key="item.item_id">
                    <li class="flex items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90" x-text="item.item_name"></p>
                            <p class="text-theme-xs text-gray-500 dark:text-gray-400"
                                x-text="item.max !== undefined ? `Diminta ${item.max} ${item.unit}` : `Tersedia ${maxFor(item)} ${unitOf(item)}`"></p>
                            <input type="hidden" :name="`items[${index}][item_id]`" :value="item.item_id">
                        </div>
                        <div class="inline-flex h-9 items-center overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700">
                            <button type="button" @click="step(index, -1)" class="{{ $stepperButton }}" aria-label="Kurangi jumlah">
                                <x-ui.icon name="minus" class="size-3.5" />
                            </button>
                            <input type="number" :min="isEdit ? 0 : 1" :max="maxFor(item)" :name="`items[${index}][qty]`"
                                x-model.number="item.qty" @change="updateQty(index, item.qty)" aria-label="Jumlah barang"
                                class="h-full w-14 border-x border-gray-300 bg-transparent text-center text-sm text-gray-800 focus:outline-none dark:border-gray-700 dark:text-white/90">
                            <button type="button" @click="step(index, 1)" class="{{ $stepperButton }}" aria-label="Tambah jumlah">
                                <x-ui.icon name="plus" class="size-3.5" />
                            </button>
                        </div>
                        {{-- Saat mengubah, barang tidak dihapus melainkan diisi 0
                             (tidak diberikan), supaya tercatat di riwayat. --}}
                        @unless ($isEdit)
                            <button type="button" @click="removeItem(index)" class="rounded-lg p-1.5 text-gray-400 transition hover:bg-error-50 hover:text-error-600 dark:hover:bg-error-500/10" aria-label="Hapus barang">
                                <x-ui.icon name="x" class="size-4" />
                            </button>
                        @endunless
                    </li>
                </template>
            </ul>

            <footer class="space-y-3 border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                <div class="text-theme-sm flex items-center justify-between text-gray-500 dark:text-gray-400">
                    <span x-text="`${requestedList.length} jenis barang`"></span>
                    <span x-text="`Total ${totalQty} unit`"></span>
                </div>
                <button type="submit" :disabled="requestedList.length === 0"
                    class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex w-full items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                    {{ $isEdit ? 'Simpan Kuantitas' : 'Ajukan Permintaan' }}
                    <x-ui.icon name="arrow-right" class="size-4" />
                </button>
            </footer>
        </div>
    </aside>
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
            units: @json($satuanLabels),
            requesters: @json($users),
            isEmergency: false,
            selectedRequesterId: '',
            selectedRequesterText: '',
            requesterSearch: '',
            selectedItem: null,
            searchQuery: '',
            inputQty: 1,
            ndTemplate: @json($ndTemplate ?? ''),
            ndSequence: @json((string) old('nd_sequence', '')),
            requestedList: @json($existingItems),
            isEdit: @json($isEdit),

            init() {
                const mergedItems = new Map();
                this.requestedList.forEach(item => {
                    const current = mergedItems.get(item.item_id);
                    if (current) {
                        current.qty += parseInt(item.qty, 10) || 0;
                        current.max += parseInt(item.max, 10) || 0;
                    } else {
                        mergedItems.set(item.item_id, {
                            ...item,
                            qty: parseInt(item.qty, 10) || 0,
                        });
                    }
                });
                this.requestedList = Array.from(mergedItems.values());
            },

            get totalQty() {
                return this.requestedList.reduce((total, item) => total + (parseInt(item.qty, 10) || 0), 0);
            },

            unitOf(item) {
                const master = this.items.find(i => i.item_id == item.item_id) ?? item;
                return this.units[master.unit] ?? master.unit ?? '';
            },

            // Saat mengubah, batasnya jumlah yang diminta; saat membuat,
            // batasnya stok tersedia: stok fisik dikurangi yang ditahan
            // permintaan lain yang masih berjalan.
            maxFor(item) {
                if (item.max !== undefined) return item.max;
                return this.items.find(i => i.item_id == item.item_id)?.available ?? 0;
            },

            stockLabel(item) {
                if (item.stock_current < 1) return 'Stok habis';
                if (item.available < 1) return 'Seluruhnya ditahan';
                return `Tersedia ${item.available} dari ${item.stock_current} ${this.unitOf(item)}`;
            },

            showStockError(item) {
                const master = this.items.find(i => i.item_id == item.item_id) ?? item;
                const ditahan = master.reserved > 0 ? ` ${master.reserved} ditahan permintaan lain yang masih berjalan.` : '';
                this.$dispatch('show-request-validation-modal', {
                    message: `Jumlah melebihi stok ${item.item_name} yang tersedia (${master.available ?? 0}).${ditahan} Untuk kekurangannya, ajukan permohonan tambah stok lewat menu Permohonan.`
                });
            },

            get filteredItems() {
                const query = this.searchQuery.toLowerCase().trim();
                if (!query) return this.items;
                return this.items.filter(item =>
                    item.item_name.toLowerCase().includes(query) ||
                    (item.item_code || '').toLowerCase().includes(query)
                );
            },

            // Kode unit pada nomor mengikuti peminta: diri sendiri, atau peminta
            // yang dipilih untuk permintaan darurat.
            get ndPreview() {
                const requester = this.isEmergency
                    ? this.requesters.find(user => user.user_id === this.selectedRequesterId)
                    : null;
                const template = requester?.nd_template ?? this.ndTemplate;
                return template.replace('__SEQ__', this.ndSequence.trim() || '……');
            },

            get filteredRequesters() {
                const query = this.requesterSearch.toLowerCase().trim();
                if (!query) return this.requesters;
                return this.requesters.filter(user =>
                    [user.fullname, user.username, user.unit].some(value =>
                        (value || '').toLowerCase().includes(query)
                    )
                );
            },

            selectRequester(user) {
                this.selectedRequesterId = user.user_id;
                this.selectedRequesterText = `${user.fullname} (${user.unit})`;
            },

            selectItem(item) {
                this.selectedItem = item;
                this.searchQuery = '';
            },

            addItem() {
                const qty = parseInt(this.inputQty, 10);

                if (!this.selectedItem || !Number.isInteger(qty) || qty <= 0) {
                    this.$dispatch('show-request-validation-modal', {
                        message: 'Pilih barang dan isi jumlahnya terlebih dahulu.'
                    });
                    return;
                }

                const selectedItem = this.selectedItem;
                const existingIndex = this.requestedList.findIndex(i => i.item_id == selectedItem.item_id);
                const currentQty = existingIndex !== -1 ? parseInt(this.requestedList[existingIndex].qty, 10) : 0;
                const newQty = currentQty + qty;

                if (newQty > selectedItem.available) {
                    this.showStockError(selectedItem);
                    return;
                }

                if (existingIndex !== -1) {
                    this.requestedList[existingIndex].qty = newQty;
                } else {
                    this.requestedList.push({
                        item_id: selectedItem.item_id,
                        item_name: selectedItem.item_name,
                        qty: qty,
                    });
                }

                this.selectedItem = null;
                this.inputQty = 1;
            },

            removeItem(index) {
                this.requestedList.splice(index, 1);
            },

            step(index, delta) {
                const item = this.requestedList[index];
                this.updateQty(index, (parseInt(item.qty, 10) || 0) + delta);
            },

            updateQty(index, value) {
                const item = this.requestedList[index];
                const qty = parseInt(value, 10);
                const max = this.maxFor(item);
                // Saat mengubah, 0 berarti barang itu tidak diberikan.
                const min = this.isEdit ? 0 : 1;
                if (!Number.isInteger(qty) || qty < min) {
                    item.qty = min;
                } else if (qty > max) {
                    item.qty = max;
                    if (item.max === undefined) {
                        this.showStockError(item);
                    }
                } else {
                    item.qty = qty;
                }
            }
        }
    }
</script>
