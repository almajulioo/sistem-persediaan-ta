@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'placeholder' => '',
    'required' => false,
    'help' => null,
    'autocomplete' => 'off',
])

@php
    $hasError = $errors->has($name);
    $borderClass = $hasError ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
@endphp

<div>
    <label for="{{ $name }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
        {{ $label }}@if ($required)<span class="text-error-500">*</span>@endif
    </label>

    <input
        type="{{ $type }}"
        id="{{ $name }}"
        name="{{ $name }}"
        value="{{ old($name, $value) }}"
        placeholder="{{ $placeholder }}"
        autocomplete="{{ $autocomplete }}"
        @if ($required) required @endif
        @if ($hasError) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
        {{ $attributes->merge(['class' => "dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 {$borderClass}"]) }}
    />

    @if ($hasError)
        <p id="{{ $name }}-error" class="text-theme-xs text-error-500 mt-1.5">{{ $errors->first($name) }}</p>
    @elseif ($help)
        <p class="text-theme-xs mt-1.5 text-gray-500 dark:text-gray-400">{{ $help }}</p>
    @endif
</div>
