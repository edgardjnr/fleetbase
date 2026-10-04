{{-- Entregas RestaurantePro: e-mail de código (VerificationMail do core-api). Textos por tipo em CodigosPorEmail; o
$content em inglês e o botão de verificar do original ficam de fora (o código é digitado na tela). Assunto em
AssuntoDosEmailsEmPortugues. --}}
@php
$textos = \App\Notifications\Entregas\Email\CodigosPorEmail::textos($type ?? null, $user?->name ?? null);
@endphp
<x-mail-layout>
# {{ $textos['titulo'] }}

{{ $textos['texto'] }}

<x-mail::codigo>{{ $code }}</x-mail::codigo>

{{ $textos['observacao'] }}
</x-mail-layout>
