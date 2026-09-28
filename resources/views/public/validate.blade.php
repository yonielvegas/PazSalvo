@extends('layouts.public')

@section('title', 'Validar Paz y Salvo')

@section('content')
<section class="form-card">
    <p class="eyebrow">CONSULTA MANUAL</p>
    <h1>Validar Paz y Salvo</h1>
    <p class="section-copy">Ingrese el folio y la fecha de emisión tal como aparecen en el documento.</p>

    <form class="validation-form" method="POST" action="{{ route('public.validate.submit') }}" novalidate>
        @csrf

        <div class="field-group">
            <label for="folio">Folio</label>
            <input
                id="folio"
                name="folio"
                type="text"
                value="{{ old('folio') }}"
                placeholder="CC-000000-2XXX"
                maxlength="14"
                autocomplete="off"
                inputmode="numeric"
                required
                @class(['has-error' => $errors->has('folio')])
            >
            <p class="field-help">Ejemplo: CC-000000-2026</p>
            @error('folio')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="field-group">
            <label for="fecha_emision">Fecha de emisión</label>
            <input
                id="fecha_emision"
                name="fecha_emision"
                type="text"
                value="{{ old('fecha_emision') }}"
                placeholder="dd/mm/aaaa"
                maxlength="10"
                autocomplete="off"
                inputmode="numeric"
                required
                @class(['has-error' => $errors->has('fecha_emision')])
            >
            @error('fecha_emision')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <button class="button button-primary button-wide" type="submit">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                <path d="m9 11 3 3L22 4" />
            </svg>
            Validar
        </button>
    </form>
</section>

@if (session('not_found_message'))
    <div class="modal-backdrop is-open" data-not-found-id="{{ session('not_found_id') }}">
        <section class="modal-dialog" role="alertdialog" aria-modal="true" aria-labelledby="not-found-title">
            <h2 id="not-found-title">Paz y Salvo no encontrado</h2>
            <p>{{ session('not_found_message') }}</p>
            <button class="button button-primary" type="button" data-close-modal>Aceptar</button>
        </section>
    </div>
@endif
@endsection

@push('scripts')
@vite('resources/js/public-validate.js')
@endpush
