{{-- Entregas RestaurantePro: corpo das notificações por e-mail. Assunto, título (greeting), linhas e botão vêm em
pt-BR do CanalEmailEntregas; notificação ainda sem tradução sai com o texto original neste mesmo layout. --}}
<x-mail::message>
{{-- Título --}}
# {{ ! empty($greeting) ? $greeting : 'Olá!' }}

{{-- Linhas antes do botão --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Botão --}}
@isset($actionText)
<x-mail::button :url="$actionUrl" color="primary" align="left">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Linhas depois do botão --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Link do botão por extenso --}}
@isset($actionText)
<x-slot:subcopy>
Se o botão "{{ $actionText }}" não abrir, copie este endereço no navegador: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
