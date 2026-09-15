@extends('layouts.app')

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-white/90">Usulan Tambah Barang</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Daftar barang yang diusulkan karena belum tersedia pada master barang.</p>
        </div>
        <a href="{{ route('request.item-proposals.create') }}" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600">Usulkan Barang Baru</a>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px]">
                <thead>
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        @foreach (['Nama Barang', 'Satuan', 'Pengusul', 'Status', 'Diajukan', 'Aksi'] as $heading)
                            <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 sm:px-6">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($proposals as $proposal)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-5 py-4 text-sm font-medium text-gray-800 dark:text-white/90 sm:px-6">{{ $proposal->item_name }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400 sm:px-6">{{ $proposal->unit?->label() ?? '-' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400 sm:px-6">{{ $proposal->user?->fullname ?? '-' }}</td>
                            <td class="px-5 py-4 sm:px-6">
                                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $proposal->status === 'diajukan' ? 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-500' : ($proposal->status === 'ditolak' ? 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-500' : 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-500') }}">
                                    {{ ['diajukan' => 'Diajukan', 'ditindaklanjuti' => 'Diselesaikan', 'ditolak' => 'Ditolak'][$proposal->status] ?? $proposal->status }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-500 dark:text-gray-400 sm:px-6">{{ $proposal->created_at?->translatedFormat('d M Y H:i') }}</td>
                            <td class="px-5 py-4 sm:px-6">
                                @if ($canManage && $proposal->status === 'diajukan')
                                    <form method="POST" action="{{ route('request.item-proposals.decide', $proposal) }}" class="flex flex-wrap gap-2">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" name="action" value="complete" class="rounded-lg px-3 py-1.5 text-xs font-medium text-success-600 ring-1 ring-success-200 ring-inset hover:bg-success-50 dark:text-success-500 dark:ring-success-500/30 dark:hover:bg-success-500/10">Selesaikan</button>
                                        <button type="submit" name="action" value="reject" class="rounded-lg px-3 py-1.5 text-xs font-medium text-error-600 ring-1 ring-error-200 ring-inset hover:bg-error-50 dark:text-error-500 dark:ring-error-500/30 dark:hover:bg-error-500/10">Tolak</button>
                                    </form>
                                @elseif (in_array($proposal->status, ['ditindaklanjuti', 'ditolak'], true))
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $proposal->followedUpBy?->fullname ?? 'Selesai' }}</span>
                                @else
                                    <span class="text-xs text-gray-400">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500 dark:text-gray-400">Belum ada usulan barang.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($proposals->hasPages())
            <div class="border-t border-gray-100 p-4 dark:border-gray-800">{{ $proposals->links() }}</div>
        @endif
    </div>
@endsection
