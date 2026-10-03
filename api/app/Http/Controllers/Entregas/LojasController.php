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
        // Telefone: como digitado e no formato que o Contact grava (setPhoneAttribute → Utils::formatPhoneNumber).
        $email     = mb_strtolower($dados['email']);
        $telefone  = $dados['telefone'] ?? null;
        $telefones = filled($telefone) ? array_values(array_unique([$telefone, Utils::formatPhoneNumber($telefone)])) : [];
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

                // a central é quem cadastra: o e-mail vale como verificado (o portal recusa login não verificado)
                $usuario->email_verified_at = now();
                $usuario->changePassword($dados['senha']);
                $usuario->activate();

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

        $usuario->changePassword($dados['senha']);
        // derruba as sessões abertas com a senha antiga
        $usuario->tokens()->delete();

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

        $membro->update(['status' => $ativo ? 'active' : 'inactive']);
        if ($usuario && $ativo) {
            $usuario->activate();
        } elseif ($usuario) {
            $usuario->deactivate();
            $usuario->tokens()->delete();
        }

        return response()->json(['loja' => $this->formatar($vendor->refresh())]);
    }

    protected function validarLoja(Request $request): array
    {
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
            'endereco.latitude'     => ['required', 'numeric', 'between:-90,90', 'not_in:0'],
            'endereco.longitude'    => ['required', 'numeric', 'between:-180,180', 'not_in:0'],
        ]);
    }

    /** O Place da loja: dono = o Vendor (assim o portal o reconhece como da loja), nome = nome da loja. */
    protected function salvarEndereco(Vendor $vendor, ?Place $place, array $dados): Place
    {
        $endereco = $dados['endereco'];
        $place    = $place ?: new Place(['company_uuid' => session('company')]);
        $place->fill([
            'owner_uuid'   => $vendor->uuid,
            'owner_type'   => Utils::getMutationType($vendor),
            'name'         => $dados['nome'],
            'phone'        => $dados['telefone'] ?? null,
            'street1'      => $endereco['street1'],
            'street2'      => $endereco['street2'] ?? null,
            'neighborhood' => $endereco['neighborhood'] ?? null,
            'city'         => $endereco['city'],
            'province'     => $endereco['province'] ?? null,
            'postal_code'  => $endereco['postal_code'] ?? null,
            'country'      => $endereco['country'] ?? 'BR',
            'location'     => new Point((float) $endereco['latitude'], (float) $endereco['longitude']),
        ]);
        $place->save();

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
            ->whereHas('contact', fn ($q) => $q->where(fn ($q2) => $q2->where('uuid', $contato)->orWhere('public_id', $contato)))
            ->with('contact.anyUser')
            ->firstOrFail();
    }

    protected function formatar(Vendor $vendor): array
    {
        $place   = $vendor->place_uuid ? Place::where('uuid', $vendor->place_uuid)->first() : null;
        $membros = VendorPersonnel::where('vendor_uuid', $vendor->uuid)->with('contact.anyUser')->get();

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
            'usuarios' => $membros->filter(fn ($m) => $m->contact)->map(fn ($m) => [
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
