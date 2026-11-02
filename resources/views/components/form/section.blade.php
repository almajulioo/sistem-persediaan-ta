{{--
    Kartu satu bagian formulir, dengan nomor langkah opsional di judulnya.
    Dipakai formulir permintaan dan permohonan, misalnya
    `<x-form.section number="1" title="Nota Dinas" description="...">`.
--}}
@props(['number' => null, 'title', 'description' => null])

<section {{ $attributes->class('rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]') }}>
    <header class="flex items-start gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
        @if ($number)
            <span class="bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400 flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold">
                {{ $number }}
            </span>
        @endif
        <div class="min-w-0">
            <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h3>
            @if ($description)
                <p class="text-theme-sm mt-0.5 text-gray-500 dark:text-gray-400">{{ $description }}</p>
            @endif
        </div>
    </header>
    <div class="space-y-5 p-5">
        {{ $slot }}
    </div>
</section>
