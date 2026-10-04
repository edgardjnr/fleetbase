{{-- Entregas RestaurantePro: layout dos e-mails (estilo C). Usado pelas notificações (notifications::email) e pelos
Mailables do core-api (vendor/fleetbase/layout/mail.blade.php). Estilos em themes/default.css. --}}
<x-mail::layout>
{{-- Cabeçalho --}}
<x-slot:header>
<x-mail::header :url="\App\Notifications\Entregas\Email\MarcaDoEmail::urlDoConsole()">
<img src="{{ \App\Notifications\Entregas\Email\MarcaDoEmail::logo() }}" class="logo" alt="Entregas RestaurantePro" height="40" style="height: 40px; width: auto; max-width: 240px;">
</x-mail::header>
</x-slot:header>

{{-- Corpo --}}
{{ $slot }}

{{-- Link do botão por extenso --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{{ $subcopy }}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Rodapé --}}
<x-slot:footer>
<x-mail::footer>
Entregas RestaurantePro · e-mail automático, não responda.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
