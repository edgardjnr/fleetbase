{{-- Entregas RestaurantePro: teste de e-mail de Admin → Configurações → E-mail (TestMail do core-api). --}}
<x-mail-layout>
# O envio de e-mail está funcionando

Se você recebeu esta mensagem, a configuração de e-mail do Entregas RestaurantePro está certa.

Envio: {{ strtoupper($mailer) }} · ambiente: {{ app()->environment() }}
</x-mail-layout>
