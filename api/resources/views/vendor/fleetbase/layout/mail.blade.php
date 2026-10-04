{{-- Entregas RestaurantePro: o <x-mail-layout> dos Mailables do core-api e do Fleet-Ops (alias registrado no
CoreServiceProvider do core-api) usa o mesmo layout das notificações: vendor/mail/html/message.blade.php. --}}
<x-mail::message>
{{ $slot }}
@isset($subcopy)
<x-slot:subcopy>
{{ $subcopy }}
</x-slot:subcopy>
@endisset
</x-mail::message>
