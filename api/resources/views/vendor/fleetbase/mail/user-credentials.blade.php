{{-- Entregas RestaurantePro: dados de acesso enviados pelo admin (UserCredentialsMail do core-api). --}}
<x-mail-layout>
# Seus dados de acesso

{{ \App\Notifications\Entregas\Email\Saudacao::para($user?->name ?? null) }} Seguem seus dados para entrar:

{{-- e-mail e senha em bloco HTML: numa tabela markdown, |, * e crase na senha a deformavam --}}
<x-mail::dados rotulo="E-mail">{{ $user->email }}</x-mail::dados>

<x-mail::dados rotulo="Senha">{{ $plaintextPassword }}</x-mail::dados>

<x-mail::button :url="\App\Notifications\Entregas\Email\MarcaDoEmail::urlDeEntrada($user ?? null)" color="primary" align="left">
Entrar
</x-mail::button>

Troque a senha no primeiro acesso, em Ver perfil.
</x-mail-layout>
