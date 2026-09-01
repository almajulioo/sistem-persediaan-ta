@props([
    'name',
    'label',
    'options' => [],
    'selected' => null,
    'placeholder' => 'Pilih salah satu',
    'required' => false,
    'help' => null,
])

@php
    $hasError = $errors->has($name);
    $borderClass = $hasError ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $current = (string) old($name, $selected);
@endphp

<div>
    <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
        {{ $label }}@if ($required)<span class="text-error-500">*</span>@endif
    </label>

    <div class="relative z-20 bg-transparent">
        <select
            id="{{ $name }}"
            name="{{ $name }}"
            @if ($required) required @endif
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
            {{ $attributes->merge(['class' => "dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full appearance-none rounded-lg border bg-transparent bg-none px-4 py-2.5 pr-11 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 {$borderClass}"]) }}
        >
            @if ($placeholder !== null)
                <option value="" class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">{{ $placeholder }}</option>
            @endif

            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected($current === (string) $optionValue)
                    class="text-gray-700 dark:bg-gray-900 dark:text-gray-400">
                    {{ $optionLabel }}
                </option>
            @endforeach
        </select>

        <span class="pointer-events-none absolute top-1/2 right-4 z-30 -translate-y-1/2 text-gray-700 dark:text-gray-400">
            <svg class="stroke-current" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M4.79175 7.396L10.0001 12.6043L15.2084 7.396" stroke="" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </span>
    </div>

    @if ($hasError)
        <p id="{{ $name }}-error" class="text-theme-xs text-error-500 mt-1.5">{{ $errors->first($name) }}</p>
    @elseif ($help)
        <p class="text-theme-xs mt-1.5 text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
</div>
