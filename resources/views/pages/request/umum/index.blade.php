@extends('layouts.app')

@php
    // Hanya permintaan yang menunggu yang bisa dipilih untuk digabung.
    $bisaGabung = $canManage && $view === '';
    $selectableIds = $bisaGabung ? $requests->getCollection()->pluck('request_id')->values()->all() : [];
    $statusBadge = [
        'submitted' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-orange-400',
        'rejected' => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500',
        'merged' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
    ];
    $tombolGaris = 'text-theme-xs rounded-lg px-3 py-1.5 font-medium ring-1 ring-inset transition';
@endphp

@section('content')
    <div x-data="umumRequestsPage()">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Permintaan Umum</h2>
                <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                    {{ $canManage
                        ? 'Kebutuhan umum dari Subbagian Umum dan TI. Tinjau, ubah, atau tolak, lalu gabungkan beberapa permintaan menjadi satu permintaan darurat.'
                        : 'Pantau permintaan umum yang Anda ajukan beserta catatan dari Pengelola Gudang.' }}
                </p>
            </div>
            <a href="{{ route('request.umum.create') }}"
                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-white transition">
                <x-ui.icon name="plus" class="size-5" />
                Buat Permintaan Umum
            </a>
        </div>

        <x-ui.flash />

        <form id="merge-form" method="POST" action="{{ route('request.umum.merge') }}"
            class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            @csrf
            <template x-for="requestId in selectedRequests" :key="requestId">
                <input type="hidden" name="request_ids[]" :value="requestId">
            </template>

            {{-- Tab --}}
            <nav class="custom-scrollbar flex gap-1 overflow-x-auto border-b border-gray-100 px-3 dark:border-gray-800 sm:px-4" aria-label="Status permintaan umum">
                @foreach ($tabs as $key => $tab)
                    @php $aktif = $view === (string) $key; @endphp
                    <a href="{{ route('request.umum.index', $key === '' ? [] : ['view' => $key]) }}" @if ($aktif) aria-current="page" @endif
                        @class([
                            'text-theme-sm -mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-3.5 font-medium transition',
                            'border-brand-500 text-brand-600 dark:text-brand-400' => $aktif,
                            'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' => ! $aktif,
                        ])>
                        {{ $tab['label'] }}
                        <span @class([
                            'text-theme-xs rounded-full px-2 py-0.5',
                            'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400' => $aktif,
                            'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400' => ! $aktif,
                        ])>{{ $tab['jumlah'] }}</span>
                    </a>
                @endforeach
            </nav>

            @if ($view === 'histori')
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Histori Permintaan Umum</h3>
                    <p class="text-theme-sm mt-0.5 text-gray-500 dark:text-gray-400">Permintaan umum yang sudah digabung menjadi permintaan darurat.</p>
                </div>
            @elseif ($bisaGabung && $requests->isNotEmpty())
                {{-- Bilah gabung: aktif begitu ada permintaan yang dipilih. --}}
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-gray-800"
                    :class="selectedRequests.length > 0 ? 'bg-brand-50 dark:bg-brand-500/10' : ''">
                    <p class="text-theme-sm text-gray-600 dark:text-gray-300">
                        <span x-show="selectedRequests.length === 0">Pilih permintaan yang akan digabung. Barang yang sama dijumlahkan menjadi satu permintaan darurat.</span>
                        <span x-show="selectedRequests.length > 0" style="display: none;">
                            <span class="font-semibold" x-text="selectedRequests.length"></span> permintaan dipilih.
                        </span>
                    </p>
                    <button type="button" @click="openMergeModal" :disabled="selectedRequests.length === 0"
                        class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-50">
                        Gabungkan
                        <span x-show="selectedRequests.length > 0" class="rounded-full bg-white/20 px-1.5 text-xs" x-text="selectedRequests.length"></span>
                    </button>
                </div>
            @endif

            @if ($requests->isEmpty())
                <div class="flex flex-col items-center px-5 py-14 text-center">
                    <span class="flex size-14 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5">
                        <x-ui.icon name="inbox" class="size-7" />
                    </span>
                    <p class="mt-4 text-sm font-medium text-gray-800 dark:text-white/90">
                        {{ match ($view) {
                            'histori' => 'Belum ada permintaan umum yang digabung.',
                            'ditolak' => 'Tidak ada permintaan umum yang ditolak.',
                            default => 'Tidak ada permintaan umum yang menunggu.',
                        } }}
                    </p>
                </div>
            @else
                {{-- Layar lebar: tabel. --}}
                <div class="custom-scrollbar hidden max-w-full overflow-x-auto lg:block">
                    <table class="w-full min-w-[960px]">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50/70 dark:border-gray-800 dark:bg-white/[0.02]">
                                @if ($bisaGabung)
                                    <th class="w-12 px-5 py-3 text-left">
                                        <input type="checkbox" :checked="allSelected" @change="toggleAll($event.target.checked)" aria-label="Pilih semua permintaan"
                                            class="size-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500">
                                    </th>
                                @endif
                                @foreach (['Permintaan', 'Peminta', 'Barang yang Diminta', 'Tanggal', 'Status', $view === 'histori' ? 'Digabung ke' : 'Aksi'] as $heading)
                                    <th class="px-5 py-3 text-left">
                                        <p class="text-theme-xs font-medium tracking-wide text-gray-500 uppercase dark:text-gray-400">{{ $heading }}</p>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($requests as $request)
                                <tr class="align-top transition hover:bg-gray-50/60 dark:hover:bg-white/[0.02]"
                                    @if ($bisaGabung) :class="selectedRequests.includes('{{ $request->request_id }}') ? 'bg-brand-50/60 dark:bg-brand-500/5' : ''" @endif>
                                    @if ($bisaGabung)
                                        <td class="px-5 py-4">
                                            <input type="checkbox" value="{{ $request->request_id }}" x-model="selectedRequests" aria-label="Pilih {{ $request->request_number }}"
                                                class="size-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500">
                                        </td>
                                    @endif
                                    <td class="px-5 py-4">
                                        <a href="{{ route('request.show', $request) }}" class="text-theme-sm font-semibold text-gray-800 hover:text-brand-600 dark:text-white/90 dark:hover:text-brand-400">
                                            {{ $request->request_number }}
                                        </a>
                                        <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">{{ $request->item->count() }} jenis barang</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            @if ($request->user)
                                                <x-ui.user-avatar :user="$request->user" class="size-9 text-xs" />
                                            @endif
                                            <div class="min-w-0">
                                                <p class="text-theme-sm truncate font-medium text-gray-800 dark:text-white/90">{{ $request->user?->fullname ?? '-' }}</p>
                                                <p class="text-theme-xs truncate text-gray-500 dark:text-gray-400">{{ $request->user?->unit?->label() ?? '-' }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4">
                                        <ul class="flex max-w-[22rem] flex-wrap gap-1.5">
                                            @foreach ($request->item->take(3) as $detail)
                                                <li class="text-theme-xs inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-2 py-1 text-gray-700 dark:bg-white/5 dark:text-gray-300">
                                                    <span class="max-w-[10rem] truncate">{{ $detail->item?->item_name ?? '-' }}</span>
                                                    <span class="font-semibold text-brand-600 dark:text-brand-400">{{ $detail->quantity_requested }}</span>
                                                </li>
                                            @endforeach
                                            @if ($request->item->count() > 3)
                                                <li class="text-theme-xs px-1 py-1 text-gray-500 dark:text-gray-400">+{{ $request->item->count() - 3 }} lainnya</li>
                                            @endif
                                        </ul>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <p class="text-theme-sm text-gray-600 dark:text-gray-300">{{ $request->date?->translatedFormat('d M Y') ?? '-' }}</p>
                                        <p class="text-theme-xs text-gray-400">{{ $request->created_at?->format('H:i') }}</p>
                                    </td>
                                    <td class="px-5 py-4">
                                        <span class="text-theme-xs inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium whitespace-nowrap {{ $statusBadge[$request->status] ?? $statusBadge['submitted'] }}">
                                            <span class="size-1.5 rounded-full bg-current"></span>
                                            {{ $request->statusLabel() }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-4">
                                        @if ($view === 'histori')
                                            @if ($request->mergedInto)
                                                <a href="{{ route('request.show', $request->mergedInto) }}" class="text-theme-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                                                    {{ $request->mergedInto->request_number }}
                                                </a>
                                                <p class="text-theme-xs mt-0.5 text-gray-400">{{ $request->updated_at?->translatedFormat('d M Y H:i') }}</p>
                                            @else
                                                <span class="text-theme-sm text-gray-400">Permintaan hasil gabungan sudah dihapus</span>
                                            @endif
                                        @elseif ($canManage && $request->status === 'submitted')
                                            <div class="flex flex-wrap gap-2">
                                                <a href="{{ route('request.umum.edit', $request) }}"
                                                    class="{{ $tombolGaris }} text-warning-600 ring-warning-200 hover:bg-warning-50 dark:text-warning-500 dark:ring-warning-500/30 dark:hover:bg-warning-500/10">
                                                    Ubah
                                                </a>
                                                <button type="button" @click="openRejectModal('{{ $request->request_id }}', @js($request->request_number))"
                                                    class="{{ $tombolGaris }} text-error-600 ring-error-200 hover:bg-error-50 dark:text-error-500 dark:ring-error-500/30 dark:hover:bg-error-500/10">
                                                    Tolak
                                                </button>
                                            </div>
                                        @else
                                            <a href="{{ route('request.show', $request) }}"
                                                class="{{ $tombolGaris }} text-gray-700 ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                                                Detail
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Layar sempit: kartu ringkas. --}}
                <ul class="divide-y divide-gray-100 lg:hidden dark:divide-gray-800">
                    @foreach ($requests as $request)
                        <li class="flex items-start gap-3 px-5 py-4">
                            @if ($bisaGabung)
                                <input type="checkbox" value="{{ $request->request_id }}" x-model="selectedRequests" aria-label="Pilih {{ $request->request_number }}"
                                    class="mt-1 size-4 rounded border-gray-300 text-brand-500 focus:ring-brand-500">
                            @endif
                            <a href="{{ route('request.show', $request) }}" class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-theme-sm font-semibold text-gray-800 dark:text-white/90">{{ $request->request_number }}</p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400">
                                            {{ $request->user?->fullname ?? '-' }} · {{ $request->date?->translatedFormat('d M Y') ?? '-' }}
                                        </p>
                                    </div>
                                    <span class="text-theme-xs inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 font-medium {{ $statusBadge[$request->status] ?? $statusBadge['submitted'] }}">
                                        <span class="size-1.5 rounded-full bg-current"></span>
                                        {{ $request->statusLabel() }}
                                    </span>
                                </div>
                                <p class="text-theme-sm mt-2 truncate text-gray-700 dark:text-gray-300">{{ $request->ringkasanBarang() }}</p>
                            </a>
                        </li>
                    @endforeach
                </ul>

                {{ $requests->links('vendor.pagination.tailadmin') }}
            @endif
        </form>

        <x-ui.modal @open-merge-modal.window="open = true" :isOpen="false" class="max-w-[520px]">
            <div class="p-6 sm:p-8">
                <p class="text-sm font-medium text-brand-600">Gabungkan permintaan umum</p>
                <h3 class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">
                    Gabungkan <span x-text="selectedRequests.length"></span> permintaan terpilih?
                </h3>
                <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400">
                    Barang yang sama akan dijumlahkan menjadi satu permintaan darurat. Permintaan asal pindah ke tab Digabung, dan pemohonnya diberi tahu.
                </p>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">Batal</button>
                    <button type="submit" form="merge-form" @click="open = false" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">Ya, Gabungkan</button>
                </div>
            </div>
        </x-ui.modal>

        @if ($canManage)
            <x-ui.modal @open-reject-modal.window="open = true" :isOpen="$errors->has('note')" class="max-w-[520px]">
                <div class="p-6 sm:p-8">
                    <p class="text-sm font-medium text-error-500">Tolak permintaan umum</p>
                    <h3 class="mt-2 text-xl font-semibold text-gray-800 dark:text-white/90">Tolak permintaan ini?</h3>
                    <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400">
                        Permintaan <span class="font-medium text-gray-700 dark:text-gray-200" x-text="rejectRequestNumber"></span> pindah ke tab Ditolak, dan alasannya dikirim ke pemohon.
                    </p>
                    <form method="POST" :action="`/permintaan-umum/${rejectRequestId}/reject`" class="mt-5">
                        @csrf
                        <input type="hidden" name="reject_request_id" :value="rejectRequestId">
                        <input type="hidden" name="reject_request_number" :value="rejectRequestNumber">
                        <label for="reject_note" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                            Alasan penolakan<span class="text-error-500">*</span>
                        </label>
                        <textarea id="reject_note" name="note" rows="3" required maxlength="500" placeholder="mis. barang serupa sudah diserahkan minggu lalu"
                            class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:text-white/90 dark:placeholder:text-white/30">{{ old('note') }}</textarea>
                        @error('note')
                            <p class="text-theme-xs text-error-500 mt-1.5">{{ $message }}</p>
                        @enderror
                        <div class="mt-6 flex justify-end gap-3">
                            <button type="button" @click="open = false" class="rounded-lg border border-gray-300 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/[0.03]">Batal</button>
                            <button type="submit" class="rounded-lg bg-error-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-error-600">Ya, Tolak</button>
                        </div>
                    </form>
                </div>
            </x-ui.modal>
        @endif
    </div>

    <script>
        function umumRequestsPage() {
            const selectable = @json($selectableIds);

            return {
                selectedRequests: [],
                rejectRequestId: @js((string) old('reject_request_id', '')),
                rejectRequestNumber: @js((string) old('reject_request_number', '')),
                get allSelected() {
                    return selectable.length > 0 && selectable.every(id => this.selectedRequests.includes(id));
                },
                toggleAll(checked) {
                    this.selectedRequests = checked ? [...selectable] : [];
                },
                openMergeModal() {
                    if (this.selectedRequests.length > 0) {
                        this.$dispatch('open-merge-modal');
                    }
                },
                openRejectModal(requestId, requestNumber) {
                    this.rejectRequestId = requestId;
                    this.rejectRequestNumber = requestNumber;
                    this.$dispatch('open-reject-modal');
                },
            };
        }
    </script>
@endsection
