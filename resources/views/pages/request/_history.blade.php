{{--
    Riwayat & Catatan satu permintaan: siapa memutuskan apa, kapan, dan
    alasannya. Terlihat oleh pemohon; inilah jalur balik dari petugas.

    $histories : Collection<RequestHistory> dengan relasi `user` termuat.
--}}
<div class="mt-6 rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-800">
        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Riwayat &amp; Catatan</h3>
    </div>

    @if ($histories->isEmpty())
        <p class="px-6 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
            Riwayat belum tercatat untuk permintaan ini.
        </p>
    @else
        <ol class="px-6 py-5">
            @foreach ($histories as $history)
                <li class="relative flex gap-4 pb-6 last:pb-0">
                    @unless ($loop->last)
                        <span class="absolute top-4 left-[5px] h-full w-px bg-gray-200 dark:bg-gray-800" aria-hidden="true"></span>
                    @endunless
                    <span class="relative mt-1.5 size-3 shrink-0 rounded-full {{ $history->action->dotClass() }}" aria-hidden="true"></span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-800 dark:text-white/90">{{ $history->judul() }}</p>
                        <p class="text-theme-xs mt-0.5 text-gray-500 dark:text-gray-400">
                            {{ $history->action->olehSistem() ? 'Sistem' : ($history->user?->fullname ?? 'Akun dihapus') }}
                            @if ($history->user)
                                · {{ $history->user->role->label() }}
                            @endif
                            · {{ $history->created_at?->translatedFormat('d M Y, H:i') }}
                        </p>

                        @if ($history->rincianPerubahan() !== [])
                            <ul class="text-theme-xs mt-2 space-y-0.5 text-gray-600 dark:text-gray-300">
                                @foreach ($history->rincianPerubahan() as $rincian)
                                    <li>{{ $rincian }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($history->note)
                            <p @class([
                                'mt-2 rounded-lg px-3 py-2 text-sm whitespace-pre-line',
                                'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400' => $history->action->dotClass() === 'bg-error-500',
                                'bg-gray-50 text-gray-700 dark:bg-white/[0.04] dark:text-gray-300' => $history->action->dotClass() !== 'bg-error-500',
                            ])>{{ $history->note }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
