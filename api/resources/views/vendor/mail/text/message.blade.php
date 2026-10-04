<x-mail::layout>
{{-- Cabeçalho --}}
<x-slot:header>
<x-mail::header :url="\App\Notifications\Entregas\Email\MarcaDoEmail::urlDoConsole()">
Entregas RestaurantePro
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
