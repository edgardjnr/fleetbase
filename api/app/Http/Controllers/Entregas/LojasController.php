<?php

namespace App\Http\Controllers\Entregas;

use App\Http\Controllers\Controller;
use Fleetbase\FleetOps\Exceptions\CustomerUserConflictException;
use Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Models\VendorPersonnel;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Setting;
use Fleetbase\Support\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Entregas RestaurantePro: cadastro das lojas (restaurantes) atendidas, só para administradores.
 *
 * Loja = Vendor type=customer (o mesmo tipo que o Portal do Cliente usa) com um Place próprio
 * (dono = o Vendor), que é o local fixo de coleta.
 * Usuário da loja = Contact type=customer + VendorPersonnel. O login (User type=customer) nasce no
 * ContactObserver com senha aleatória e status pending; aqui recebe a senha inicial, é marcado como
 * verificado (o portal recusa login não verificado e não há e-mail configurado) e é ativado.
 * O Fleetbase não barra usuário inativo; quem barra é o ProtegerPortalLoja. Por isso desativar e
 * trocar a senha também apagam os tokens (sessões abertas).
 *
 * O login do contato é lido sempre por Contact::anyUser, nunca por Contact::user: esta filtra
 * users.type pelo type do próprio contato ($this->type), que vem nulo em eager loading (with, load,
 * refresh) e em whereHas, e então nunca acha o usuário (o próprio Fleetbase usa anyUser nesses casos).
 */
class LojasController extends Controller
{
    /** Tipo de Vendor das lojas. */
    public const TIPO_LOJA = 'customer';

    public function index(Request $request)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }

        $lojas = Vendor::where('company_uuid', session('company'))->where('type', static::TIPO_LOJA)->orderBy('name')->get();
        // o place já vem pelo $with do Vendor; os usuários de todas as lojas, de uma vez
        $lojas->loadMissing('vendorPersonnel.contact.anyUser');

        return response()->json(['lojas' => $lojas->map(fn ($vendor) => $this->formatar($vendor))->values()]);
    }

    public function store(Request $request)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $dados = $this->validarLoja($request);

        $vendor = DB::transaction(function () use ($dados) {
            $vendor = Vendor::create([
                'company_uuid' => session('company'),
                'name'         => $dados['nome'],
                'phone'        => $dados['telefone'] ?? null,
                'type'         => static::TIPO_LOJA,
                'status'       => 'active',
            ]);
            $place = $this->salvarEndereco($vendor, null, $dados);
            $vendor->update(['place_uuid' => $place->uuid]);

            return $vendor;
        });

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }

    public function update(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);
        $dados  = $this->validarLoja($request);

        DB::transaction(function () use ($vendor, $dados) {
            $vendor->update(['name' => $dados['nome'], 'phone' => $dados['telefone'] ?? null]);
            $atual = $vendor->place_uuid ? Place::where('uuid', $vendor->place_uuid)->first() : null;
            $place = $this->salvarEndereco($vendor, $atual, $dados);
            if ($vendor->place_uuid !== $place->uuid) {
                $vendor->update(['place_uuid' => $place->uuid]);
            }
        });

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }

    public function adicionarUsuario(Request $request, string $id)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor = $this->acharLoja($id);
        $dados  = $request->validate([
            'nome'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'max:190'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'senha'    => ['required', 'string', 'min:8', 'max:100'],
        ]);

        // O guard do Fleetbase não pega contato de cliente repetido (o whereHas('user') dele filtra users.type nulo):
        // o mesmo login ficaria ligado a duas lojas e veria os pedidos das duas (PortalOrderService::accountCustomerUuids).
        // Telefone: como digitado e no formato que o Contact grava (setPhoneAttribute → Utils::formatPhoneNumber), sem valores vazios.
        $email     = mb_strtolower($dados['email']);
        $telefone  = $dados['telefone'] ?? null;
        $telefones = array_values(array_filter(array_unique([$telefone, filled($telefone) ? Utils::formatPhoneNumber($telefone) : null]), 'filled'));
        $repetido  = Contact::where('company_uuid', session('company'))
            ->where('type', 'customer')
            ->where(fn ($q) => $q->where('email', $email)->when($telefones, fn ($q2) => $q2->orWhereIn('phone', $telefones)))
            ->exists();
        if ($repetido) {
            return response()->json(['errors' => ['Já existe um usuário com este e-mail ou telefone.']], 422);
        }

        try {
            DB::transaction(function () use ($vendor, $dados, $email) {
                $contato = Contact::create([
                    'company_uuid' => session('company'),
                    'name'         => $dados['nome'],
                    'email'        => $email,
                    'phone'        => $dados['telefone'] ?? null,
                    'type'         => 'customer',
                ]);
                // consulta nova ao usuário que o ContactObserver criou (sem refresh(), que recarregaria a relação user() com type nulo)
                $usuario = $contato->anyUser()->first();
                abort_if(!$usuario, 422, 'Não foi possível criar o login do usuário.');

                // Um login, uma loja, conferido pelo resultado: o observer acha o login por e-mail OU telefone e pode ligar o contato
                // novo a um usuário que já existe (login com e-mail alterado, contato apagado no Fleet-Ops com usuário órfão).
                // Se esse login já é de outro contato da empresa, o abort desfaz a transação inteira.
                $outroContato = Contact::where('company_uuid', session('company'))
                    ->where('user_uuid', $usuario->uuid)
                    ->where('uuid', '!=', $contato->uuid)
                    ->exists();
                abort_if($outroContato, 422, 'Este e-mail ou telefone já pertence ao login de outro usuário.');

                // a central é quem cadastra: o e-mail vale como verificado (o portal recusa login não verificado)
                $usuario->email_verified_at = now();
                $usuario->changePassword($dados['senha']);
                // o login pode não ser novo e os tokens Sanctum não expiram aqui: derruba as sessões que ele já tinha
                $usuario->tokens()->delete();
                $usuario->activate();
                // o portal da loja é em português; sem isto o users/me devolve en-us (mesma chave e valor do seletor de idioma)
                Setting::configure('user.' . $usuario->uuid . '.locale', 'pt-br');

                VendorPersonnel::updateOrCreate(
                    ['vendor_uuid' => $vendor->uuid, 'contact_uuid' => $contato->uuid],
                    ['role' => 'member', 'status' => 'active', 'invited_by_uuid' => session('user')]
                );
            });
        } catch (CustomerUserConflictException $e) {
            // vem antes: é subclasse de UserAlreadyExistsException e o catch abaixo a engoliria
            return response()->json(['errors' => ['Este e-mail ou telefone pertence a um usuário da central.']], 422);
        } catch (UserAlreadyExistsException $e) {
            return response()->json(['errors' => ['Já existe um usuário com este e-mail ou telefone.']], 422);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'not available')) {
                return response()->json(['errors' => ['Este e-mail ou telefone já está em uso.']], 422);
            }
            throw $e;
        }

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }

    public function trocarSenha(Request $request, string $id, string $contato)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor  = $this->acharLoja($id);
        $dados   = $request->validate(['senha' => ['required', 'string', 'min:8', 'max:100']]);
        $usuario = $this->acharMembro($vendor, $contato)->contact?->anyUser;
        abort_if(!$usuario, 404, 'Usuário sem login.');
        abort_if($usuario->type !== 'customer', 422, 'Este login não é de usuário de loja.');

        DB::transaction(function () use ($usuario, $dados) {
            $usuario->changePassword($dados['senha']);
            // o portal recusa login não verificado (manualVerify grava email_verified_at = agora)
            if ($usuario->isNotVerified()) {
                $usuario->manualVerify();
            }
            // derruba as sessões abertas com a senha antiga
            $usuario->tokens()->delete();
        });

        return response()->json(['ok' => true]);
    }

    public function alterarAcesso(Request $request, string $id, string $contato)
    {
        if ($erro = $this->negarSeNaoAdmin($request)) {
            return $erro;
        }
        $vendor  = $this->acharLoja($id);
        $ativo   = (bool) $request->validate(['ativo' => ['required', 'boolean']])['ativo'];
        $membro  = $this->acharMembro($vendor, $contato);
        $usuario = $membro->contact?->anyUser;
        abort_if($usuario && $usuario->type !== 'customer', 422, 'Este login não é de usuário de loja.');

        DB::transaction(function () use ($membro, $usuario, $ativo) {
            if ($usuario && !$ativo) {
                // primeiro o login e as sessões, depois o vínculo com a loja
                $usuario->deactivate();
                $usuario->tokens()->delete();
            }

            $membro->update(['status' => $ativo ? 'active' : 'inactive']);

            if ($usuario && $ativo) {
                // o portal recusa login não verificado
                if ($usuario->isNotVerified()) {
                    $usuario->manualVerify();
                }
                $usuario->activate();
            }
        });

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }

    protected function validarLoja(Request $request): array
    {
        // latitude e longitude do Brasil (a faixa também pega o erro comum de digitá-las trocadas); 0 é o valor vazio do Fleetbase
        return $request->validate([
            'nome'                  => ['required', 'string', 'max:120'],
            'telefone'              => ['nullable', 'string', 'max:30'],
            'endereco.street1'      => ['required', 'string', 'max:190'],
            'endereco.street2'      => ['nullable', 'string', 'max:190'],
            'endereco.neighborhood' => ['nullable', 'string', 'max:120'],
            'endereco.city'         => ['required', 'string', 'max:120'],
            'endereco.province'     => ['nullable', 'string', 'max:60'],
            'endereco.postal_code'  => ['nullable', 'string', 'max:20'],
            'endereco.country'      => ['nullable', 'string', 'size:2'],
            'endereco.latitude'     => ['required', 'numeric', 'between:-34,6', 'not_in:0'],
            'endereco.longitude'    => ['required', 'numeric', 'between:-74,-34', 'not_in:0'],
        ], [
            'endereco.latitude.required'  => 'Informe a latitude do endereço da loja.',
            'endereco.latitude.numeric'   => 'A latitude precisa ser um número.',
            'endereco.latitude.between'   => 'Latitude fora do Brasil: confira se latitude e longitude não estão trocadas.',
            'endereco.latitude.not_in'    => 'Informe a latitude do endereço da loja (0 não vale).',
            'endereco.longitude.required' => 'Informe a longitude do endereço da loja.',
            'endereco.longitude.numeric'  => 'A longitude precisa ser um número.',
            'endereco.longitude.between'  => 'Longitude fora do Brasil: confira se latitude e longitude não estão trocadas.',
            'endereco.longitude.not_in'   => 'Informe a longitude do endereço da loja (0 não vale).',
        ]);
    }

    /**
     * O Place da loja: dono = o Vendor (assim o portal o reconhece como da loja), nome = nome da loja.
     *
     * O Place atual só é reaproveitado se for da loja (dono = o Vendor, mesma empresa) e as coordenadas não mudaram.
     * O km dos pedidos já feitos sai das coordenadas do Place deles, então a loja que se muda ganha um Place novo e o
     * antigo é solto (sem dono: some dos endereços da loja e fica só com os pedidos antigos). Um Place que não é da loja
     * (Vendor antigo, Place compartilhado) nunca é alterado: cria-se um novo.
     * Nome e endereço vão em maiúsculas com mb_strtoupper: o PlaceObserver só faz strtoupper (ASCII) ao criar e gravaria
     * "SãO"; assim fica igual na criação e na edição.
     */
    protected function salvarEndereco(Vendor $vendor, ?Place $atual, array $dados): Place
    {
        $endereco  = $dados['endereco'];
        $latitude  = (float) $endereco['latitude'];
        $longitude = (float) $endereco['longitude'];

        $daLoja = $atual
            && $atual->owner_uuid === $vendor->uuid
            && $atual->owner_type === Utils::getMutationType($vendor)
            && $atual->company_uuid === session('company');
        $mesmoLugar = $daLoja
            && $atual->location !== null
            && abs($atual->location->getLat() - $latitude) < 0.000001
            && abs($atual->location->getLng() - $longitude) < 0.000001;

        $maiusculo = fn (?string $texto) => $texto === null ? null : mb_strtoupper($texto, 'UTF-8');

        $place = $mesmoLugar ? $atual : new Place(['company_uuid' => session('company')]);
        $place->fill([
            'owner_uuid'   => $vendor->uuid,
            'owner_type'   => Utils::getMutationType($vendor),
            'name'         => $maiusculo($dados['nome']),
            'phone'        => $dados['telefone'] ?? null,
            'street1'      => $maiusculo($endereco['street1']),
            'street2'      => $maiusculo($endereco['street2'] ?? null),
            'neighborhood' => $maiusculo($endereco['neighborhood'] ?? null),
            'city'         => $maiusculo($endereco['city']),
            'province'     => $maiusculo($endereco['province'] ?? null),
            'postal_code'  => $maiusculo($endereco['postal_code'] ?? null),
            'country'      => $maiusculo($endereco['country'] ?? 'BR'),
            'location'     => new Point($latitude, $longitude),
        ]);
        $place->save();

        if ($daLoja && !$mesmoLugar) {
            $atual->update(['owner_uuid' => null, 'owner_type' => null]);
        }

        return $place;
    }

    protected function acharLoja(string $id): Vendor
    {
        return Vendor::where('company_uuid', session('company'))
            ->where('type', static::TIPO_LOJA)
            ->where(fn ($q) => $q->where('uuid', $id)->orWhere('public_id', $id))
            ->firstOrFail();
    }

    protected function acharMembro(Vendor $vendor, string $contato): VendorPersonnel
    {
        return VendorPersonnel::where('vendor_uuid', $vendor->uuid)
            ->whereHas('contact', fn ($q) => $q->where('type', 'customer')->where(fn ($q2) => $q2->where('uuid', $contato)->orWhere('public_id', $contato)))
            ->with('contact.anyUser')
            ->firstOrFail();
    }

    protected function formatar(Vendor $vendor): array
    {
        // o place vem pelo $with do Vendor e os usuários, no index, já vêm carregados para todas as lojas
        $vendor->loadMissing('place', 'vendorPersonnel.contact.anyUser');
        $place = $vendor->place;

        return [
            'id'       => $vendor->public_id,
            'nome'     => $vendor->name,
            'telefone' => $vendor->phone,
            'endereco' => $place ? [
                'street1'      => $place->street1,
                'street2'      => $place->street2,
                'neighborhood' => $place->neighborhood,
                'city'         => $place->city,
                'province'     => $place->province,
                'postal_code'  => $place->postal_code,
                'country'      => $place->country,
                'latitude'     => $place->location?->getLat(),
                'longitude'    => $place->location?->getLng(),
            ] : null,
            'usuarios' => $vendor->vendorPersonnel->filter(fn ($m) => $m->contact)->map(fn ($m) => [
                'id'       => $m->contact->public_id,
                'nome'     => $m->contact->name,
                'email'    => $m->contact->email,
                'telefone' => $m->contact->phone,
                'ativo'    => $m->status === 'active' && $m->contact->anyUser?->status === 'active',
            ])->values(),
        ];
    }

    protected function negarSeNaoAdmin(Request $request)
    {
        $usuario = Auth::getUserFromSession($request);

        if (!$usuario || $usuario->isNotAdmin()) {
            return response()->json(['errors' => ['Somente administradores podem gerenciar as lojas.']], 403);
        }

        return null;
    }
}
