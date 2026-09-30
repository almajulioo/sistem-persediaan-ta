{{--
    Avatar pengguna: foto profil bila ada, avatar inisial bila tidak (ADR 0038).

    Ukuran dan ukuran huruf ditentukan pemanggil lewat `class`, misalnya
    `<x-ui.user-avatar :user="$user" class="size-11 text-sm" />`.

    Bila baris menunjuk foto tetapi berkasnya hilang dari disk — misalnya basis
    data dipulihkan tanpa folder storage — `onerror` menukar ke inisial.
    Sengaja memakai atribut bawaan, bukan Alpine: galat gambar dapat terjadi
    sebelum Alpine selesai dimuat, dan pendengarnya akan terlewat.
--}}
@props(['user'])

@php
    $inisialClass = 'bg-brand-500 flex shrink-0 items-center justify-center rounded-full font-semibold text-white';
@endphp

@if ($user->punyaFoto())
    <img
        src="{{ $user->urlFoto() }}"
        alt="Foto profil {{ $user->fullname }}"
        onerror="this.hidden = true; this.nextElementSibling.hidden = false;"
        {{ $attributes->class('shrink-0 rounded-full object-cover') }}
    />
    <span hidden aria-hidden="true" {{ $attributes->class($inisialClass) }}>{{ $user->initials() }}</span>
@else
    <span aria-hidden="true" {{ $attributes->class($inisialClass) }}>{{ $user->initials() }}</span>
@endif
