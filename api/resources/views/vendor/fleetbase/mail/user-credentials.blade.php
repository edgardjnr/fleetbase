{{-- Entregas RestaurantePro: dados de acesso enviados pelo admin (UserCredentialsMail do core-api). --}}
<x-mail-layout>
# Seus dados de acesso

{{ \App\Notifications\Entregas\Email\Saudacao::para($user?->name ?? null) }} Seguem seus dados para entrar:

<x-mail::table>
| | |
|:--|:--|
| E-mail | **{{ $user->email }}** |
| Senha | **{{ $plaintextPassword }}** |
</x-mail::table>

<x-mail::button :url="\App\Notifications\Entregas\Email\MarcaDoEmail::urlDoConsole()" color="primary" align="left">
Entrar
</x-mail::button>

Troque a senha no primeiro acesso, em Ver perfil.
</x-mail-layout>
