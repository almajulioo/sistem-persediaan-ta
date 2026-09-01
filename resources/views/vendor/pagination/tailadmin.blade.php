@if ($paginator->hasPages())
    @php
        $baseClass = 'inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-3 text-theme-sm font-medium transition';
        $inactive = 'text-gray-700 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5';
        $active = 'bg-brand-500 text-white';
        $disabled = 'cursor-not-allowed text-gray-300 dark:text-gray-600';
    @endphp

    <nav role="navigation" aria-label="Navigasi halaman"
        class="flex flex-col items-center justify-between gap-3 border-t border-gray-100 px-5 py-4 sm:flex-row sm:px-6 dark:border-gray-800">
        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
            Menampilkan {{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }}
            dari {{ $paginator->total() }} data
        </p>

        <div class="flex items-center gap-1">
            {{-- Sebelumnya --}}
            @if ($paginator->onFirstPage())
                <span class="{{ $baseClass }} {{ $disabled }}" aria-disabled="true">Sebelumnya</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $baseClass }} {{ $inactive }}">Sebelumnya</a>
            @endif

            {{-- Nomor halaman --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="{{ $baseClass }} {{ $disabled }}">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="{{ $baseClass }} {{ $active }}" aria-current="page">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="{{ $baseClass }} {{ $inactive }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Berikutnya --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $baseClass }} {{ $inactive }}">Berikutnya</a>
            @else
                <span class="{{ $baseClass }} {{ $disabled }}" aria-disabled="true">Berikutnya</span>
            @endif
        </div>
    </nav>
@endif
