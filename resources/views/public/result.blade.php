@extends('layouts.public')

@php
    $meta = match ($certificate['status'] ?? null) {
        'valid' => [
            'class' => 'valid',
            'title' => 'CERTIFICADO VIGENTE',
            'text' => 'El certificado es auténtico y se encuentra dentro de su periodo de vigencia.',
        ],
        'expired' => [
            'class' => 'expired',
            'title' => 'CERTIFICADO EXPIRADO',
            'text' => 'Este Paz y Salvo se encuentra expirado. La información se muestra únicamente para fines de verificación.',
        ],
        'cancelled' => [
            'class' => 'cancelled',
            'title' => 'CERTIFICADO ANULADO',
            'text' => 'Este certificado fue anulado y no debe ser aceptado como válido.',
        ],
        default => [
            'class' => 'missing',
            'title' => 'Certificado no encontrado',
            'text' => 'No existe un certificado válido asociado a este código.',
        ],
    };

    $formatDate = function ($value, bool $withTime = false): string {
        if (! $value) {
            return 'No disponible';
        }

        return \Illuminate\Support\Carbon::parse($value)
            ->timezone('America/Panama')
            ->translatedFormat($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    };
@endphp

@section('title', $meta['title'])

@section('content')
<section class="result-card {{ $meta['class'] }}">
    <div class="status-icon" aria-hidden="true"></div>
    <h1>{{ $meta['title'] }}</h1>
    <p class="result-message">{{ $meta['text'] }}</p>

    @if ($certificate)
        <dl class="result-details">
            <div>
                <dt>Folio</dt>
                <dd>{{ $certificate['folio'] ?? 'No disponible' }}</dd>
            </div>
            <div>
                <dt>NAC</dt>
                <dd>{{ $certificate['client_number'] ?? 'No disponible' }}</dd>
            </div>
            <div>
                <dt>Cliente</dt>
                <dd>{{ $certificate['holder_name'] ?? 'No disponible' }}</dd>
            </div>
            <div>
                <dt>Agencia</dt>
                <dd>{{ $certificate['agency'] ?? 'No disponible' }}</dd>
            </div>
            <div>
                <dt>Fecha de emisión</dt>
                <dd>{{ $formatDate($certificate['issued_at'] ?? null, true) }}</dd>
            </div>
            <div>
                <dt>Fecha de expiración</dt>
                <dd>{{ $formatDate($certificate['expires_at'] ?? null) }}</dd>
            </div>
            @if (! empty($certificate['cancelled_at']))
                <div>
                    <dt>Fecha de anulación</dt>
                    <dd>{{ $formatDate($certificate['cancelled_at'], true) }}</dd>
                </div>
            @endif
        </dl>
    @endif

    <div class="result-actions">
        @if (! empty($certificate['pdf_url']))
            <a class="button button-secondary" href="{{ $certificate['pdf_url'] }}" target="_blank" rel="noopener noreferrer">
                Ver PDF
            </a>
        @endif
        <a class="button button-primary" href="{{ route('public.validate') }}">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                <path d="m9 11 3 3L22 4" />
            </svg>
            Validar otro Paz y Salvo
        </a>
    </div>
</section>
@endsection
