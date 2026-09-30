{{--
    Tombol "Hapus foto" beserta modal konfirmasinya (ADR 0038).

    Dipakai di dua tempat dengan rute berbeda: profil sendiri
    (`profile.avatar.destroy`) dan moderasi oleh Admin pada halaman Ubah
    Pengguna (`users.avatar.destroy`). Karena membawa `<form>` sendiri,
    komponen ini tidak boleh diletakkan di dalam formulir lain.

    `:tombol="false"` menyembunyikan tombolnya; modal lalu dibuka dari mana
    saja lewat `$dispatch('buka-hapus-foto')` — dipakai menu avatar profil.
--}}
@props(['action', 'pesan' => null, 'tombol' => true])

<div x-data="{ open: false }" @buka-hapus-foto.window="open = true">
    @if ($tombol)
        <button type="button" @click="open = true"
            class="text-theme-sm text-error-600 dark:text-error-500 ring-error-200 dark:ring-error-500/30 hover:bg-error-50 dark:hover:bg-error-500/10 rounded-lg px-4 py-2.5 font-medium ring-1 ring-inset transition">
            Hapus foto
        </button>
    @endif

    <div x-show="open" x-cloak @keydown.escape.window="open = false"
        class="fixed inset-0 z-99999 flex items-center justify-center p-5">
        <div @click="open = false" class="fixed inset-0 bg-gray-900/50"></div>

        <div @click.stop role="dialog" aria-modal="true"
            class="relative w-full max-w-md rounded-2xl bg-white p-6 dark:bg-gray-900">
            <h3 class="text-theme-xl font-semibold text-gray-800 dark:text-white/90">Hapus foto profil?</h3>
            @if ($pesan)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $pesan }}</p>
            @endif

            <div class="mt-6 flex justify-end gap-3">
                <button type="button" @click="open = false"
                    class="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset transition hover:bg-gray-50 dark:text-gray-400 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    Batal
                </button>
                <form method="POST" action="{{ $action }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                        class="bg-error-500 hover:bg-error-600 rounded-lg px-4 py-2.5 text-sm font-medium text-white transition">
                        Ya, hapus
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
