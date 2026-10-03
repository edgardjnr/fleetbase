import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

const ENDPOINT = 'entregas/lojas';
const TAMANHO_MINIMO_SENHA = 8;
// mesmos limites do servidor (LojasController::validarLoja): só o Brasil, e 0 não vale
const LIMITE_LATITUDE = [-34, 6];
const LIMITE_LONGITUDE = [-74, -34];
const FORMATO_NUMERO = /^[+-]?\d+(\.\d+)?$/;
const FORMATO_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
// "lat, lng" como o Google Maps copia no clique direito. As duas partes precisam de decimais,
// para "-21,1775" (vírgula decimal) nunca ser confundido com um par.
const FORMATO_PAR_DE_COORDENADAS = /^\s*([+-]?\d+[.,]\d+)\s*(?:[;,]\s*|\s+)([+-]?\d+[.,]\d+)\s*$/;

const texto = (valor) => String(valor ?? '').trim();
// aceita "-21.1775" e "-21,1775"; vazio ou qualquer outra coisa vira NaN (Number('') seria 0)
const numero = (valor) => {
    const digitado = texto(valor).replace(',', '.');
    return FORMATO_NUMERO.test(digitado) ? Number(digitado) : NaN;
};
const dentroDoBrasil = (valor, [minimo, maximo]) => Number.isFinite(valor) && valor !== 0 && valor >= minimo && valor <= maximo;
// conta caracteres (não unidades UTF-16), como o min:8 do servidor
const tamanhoDaSenha = (senha) => Array.from(String(senha ?? '')).length;
const emTexto = (valor) => (valor === null || valor === undefined ? '' : String(valor));

const lojaNova = () => ({
    id: null,
    nome: '',
    telefone: '',
    street1: '',
    street2: '',
    neighborhood: '',
    city: 'Ribeirão Preto',
    province: 'SP',
    postal_code: '',
    country: 'BR',
    latitude: '',
    longitude: '',
});

// loja do servidor → rascunho de edição (tudo como texto, como é digitado)
const rascunhoDaLoja = (loja) => {
    const endereco = loja.endereco;
    if (!endereco) {
        return { ...lojaNova(), id: loja.id, nome: emTexto(loja.nome), telefone: emTexto(loja.telefone) };
    }

    return {
        id: loja.id,
        nome: emTexto(loja.nome),
        telefone: emTexto(loja.telefone),
        street1: emTexto(endereco.street1),
        street2: emTexto(endereco.street2),
        neighborhood: emTexto(endereco.neighborhood),
        city: emTexto(endereco.city),
        province: emTexto(endereco.province),
        postal_code: emTexto(endereco.postal_code),
        country: emTexto(endereco.country) || 'BR',
        latitude: emTexto(endereco.latitude),
        longitude: emTexto(endereco.longitude),
    };
};

const usuarioNovo = () => ({ nome: '', email: '', telefone: '', senha: '' });

/**
 * Entregas RestaurantePro: lojas (restaurantes atendidos) e os logins do portal da loja.
 * Cada loja é um Vendor do tipo "customer" com um Local fixo de coleta; os usuários são os logins do
 * `<origem>/customer-portal`. A API (int/v1/entregas/lojas) só atende administradores.
 *
 * O formulário valida antes de enviar (os erros de validação do Laravel chegam em inglês), e a senha
 * só existe no estado dos painéis: nunca vai para notificação nem para log.
 */
export default class ManagementLojasController extends Controller {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked lojas = [];
    @tracked carregado = false;
    // loja em edição (rascunho, tudo como texto) ou nova; null = painel fechado
    @tracked editando = null;
    // sobe a cada abertura do painel de edição, para a tela rolar até ele mesmo se já estava aberto
    @tracked aberturaDoPainel = 0;
    // painel "novo usuário": a loja de destino e o rascunho do usuário
    @tracked lojaDoUsuario = null;
    @tracked novoUsuario = usuarioNovo();
    // painel "trocar senha": { loja, usuario } e a senha digitada
    @tracked senhaDe = null;
    @tracked novaSenha = '';

    get portalUrl() {
        return `${window.location.origin}/customer-portal`;
    }

    get tituloEdicao() {
        if (!this.editando?.id) {
            return this.intl.t('fleet-ops.ui.lojas.new');
        }

        const original = this.lojas.find((loja) => loja.id === this.editando.id);
        return original ? `${this.intl.t('fleet-ops.ui.lojas.edit')}: ${original.nome}` : this.intl.t('fleet-ops.ui.lojas.edit');
    }

    get camposObrigatoriosPreenchidos() {
        const loja = this.editando;
        return Boolean(loja) && Boolean(texto(loja.nome)) && Boolean(texto(loja.street1)) && Boolean(texto(loja.city));
    }

    get coordenadasValidas() {
        const loja = this.editando;
        return Boolean(loja) && dentroDoBrasil(numero(loja.latitude), LIMITE_LATITUDE) && dentroDoBrasil(numero(loja.longitude), LIMITE_LONGITUDE);
    }

    get podeSalvarLoja() {
        return this.camposObrigatoriosPreenchidos && this.coordenadasValidas;
    }

    /** O que ainda falta para salvar a loja (vazio quando pode salvar). */
    get dicasLoja() {
        const dicas = [];
        if (!this.camposObrigatoriosPreenchidos) {
            dicas.push(this.intl.t('fleet-ops.ui.lojas.required-fields'));
        }
        if (!this.coordenadasValidas) {
            dicas.push(this.intl.t('fleet-ops.ui.lojas.invalid-coordinates'));
        }
        return dicas;
    }

    /** Link para conferir as coordenadas no Google Maps; só com latitude e longitude válidas. */
    get linkDoMapa() {
        if (!this.coordenadasValidas) {
            return null;
        }

        return `https://www.google.com/maps?q=${numero(this.editando.latitude)},${numero(this.editando.longitude)}`;
    }

    get emailDoNovoUsuarioValido() {
        return FORMATO_EMAIL.test(texto(this.novoUsuario.email));
    }

    get podeAdicionarUsuario() {
        return Boolean(this.lojaDoUsuario) && Boolean(texto(this.novoUsuario.nome)) && this.emailDoNovoUsuarioValido && tamanhoDaSenha(this.novoUsuario.senha) >= TAMANHO_MINIMO_SENHA;
    }

    /** O que ainda falta para criar o usuário (vazio quando pode criar). */
    get dicasUsuario() {
        const dicas = [];
        if (!texto(this.novoUsuario.nome)) {
            dicas.push(this.intl.t('fleet-ops.ui.lojas.user-name-required'));
        }
        if (!this.emailDoNovoUsuarioValido) {
            dicas.push(this.intl.t('fleet-ops.ui.lojas.invalid-email'));
        }
        if (tamanhoDaSenha(this.novoUsuario.senha) < TAMANHO_MINIMO_SENHA) {
            dicas.push(this.intl.t('fleet-ops.ui.lojas.password-too-short'));
        }
        return dicas;
    }

    get podeTrocarSenha() {
        return Boolean(this.senhaDe) && tamanhoDaSenha(this.novaSenha) >= TAMANHO_MINIMO_SENHA;
    }

    /** Zera a tela ao sair dela: nem rascunhos nem senhas digitadas ficam para trás, e o carregamento em curso é cancelado (resposta atrasada não regrava a lista). */
    limpar() {
        this.carregar.cancelAll();
        this.lojas = [];
        this.carregado = false;
        this.editando = null;
        this.fecharNovoUsuario();
        this.fecharTrocarSenha();
    }

    @action enderecoDaLoja(loja) {
        const endereco = loja?.endereco;
        return endereco ? [endereco.street1, endereco.neighborhood, endereco.city].filter(Boolean).join(', ') : '';
    }

    /** Leva a tela e o foco até o painel que acabou de abrir. */
    @action abrirPainel(elemento) {
        elemento.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
        elemento.querySelector('input')?.focus({ preventScroll: true });
    }

    @action setCampoLoja(campo, event) {
        this.editando = { ...this.editando, [campo]: event.target.value };
    }

    /** Colar "lat, lng" (como o Google Maps copia) num dos dois campos preenche os dois. */
    @action colarCoordenadas(event) {
        const par = String(event.clipboardData?.getData('text') ?? '').match(FORMATO_PAR_DE_COORDENADAS);
        if (!par) {
            return;
        }

        event.preventDefault();
        this.editando = { ...this.editando, latitude: par[1].replace(',', '.'), longitude: par[2].replace(',', '.') };
    }

    @action novaLoja() {
        this.editando = lojaNova();
        this.aberturaDoPainel += 1;
    }

    @action editar(loja) {
        this.editando = rascunhoDaLoja(loja);
        this.aberturaDoPainel += 1;
    }

    @action cancelarEdicao() {
        this.editando = null;
    }

    @action setCampoUsuario(campo, event) {
        this.novoUsuario = { ...this.novoUsuario, [campo]: event.target.value };
    }

    // clicar de novo na mesma loja fecha o painel; outra loja começa um rascunho limpo
    @action abrirNovoUsuario(loja) {
        if (this.lojaDoUsuario?.id === loja.id) {
            this.fecharNovoUsuario();
            return;
        }

        this.lojaDoUsuario = loja;
        this.novoUsuario = usuarioNovo();
    }

    @action fecharNovoUsuario() {
        this.lojaDoUsuario = null;
        this.novoUsuario = usuarioNovo();
    }

    @action setNovaSenha(event) {
        this.novaSenha = event.target.value;
    }

    // a senha digitada para um usuário nunca passa para outro
    @action abrirTrocarSenha(loja, usuario) {
        if (this.senhaDe?.usuario.id === usuario.id) {
            this.fecharTrocarSenha();
            return;
        }

        this.senhaDe = { loja, usuario };
        this.novaSenha = '';
    }

    @action fecharTrocarSenha() {
        this.senhaDe = null;
        this.novaSenha = '';
    }

    /** Põe a loja devolvida pela API na lista (nova ou atualizada), na ordem alfabética. */
    substituirLoja(loja) {
        const existe = this.lojas.some((atual) => atual.id === loja.id);
        const lojas = existe ? this.lojas.map((atual) => (atual.id === loja.id ? loja : atual)) : [...this.lojas, loja];
        const locale = this.intl.primaryLocale ?? 'pt-BR';

        this.lojas = lojas.sort((a, b) => String(a.nome).localeCompare(String(b.nome), locale, { sensitivity: 'base' }));
    }

    @task({ restartable: true }) *carregar() {
        try {
            const resposta = yield this.fetch.get(ENDPOINT);
            this.lojas = resposta.lojas ?? [];
            this.carregado = true;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *salvarLoja() {
        if (!this.podeSalvarLoja) {
            return;
        }

        const loja = this.editando;
        // identifica o painel que está sendo salvo (sobe a cada "Nova loja"/"Editar")
        const abertura = this.aberturaDoPainel;
        const opcional = (valor) => texto(valor) || null;
        const pais = texto(loja.country).toUpperCase();
        const corpo = {
            nome: texto(loja.nome),
            telefone: opcional(loja.telefone),
            endereco: {
                street1: texto(loja.street1),
                street2: opcional(loja.street2),
                neighborhood: opcional(loja.neighborhood),
                city: texto(loja.city),
                province: opcional(loja.province),
                postal_code: opcional(loja.postal_code),
                country: pais.length === 2 ? pais : 'BR',
                latitude: numero(loja.latitude),
                longitude: numero(loja.longitude),
            },
        };

        try {
            const resposta = yield loja.id ? this.fetch.put(`${ENDPOINT}/${loja.id}`, corpo) : this.fetch.post(ENDPOINT, corpo);
            this.substituirLoja(resposta.loja);
            // se, enquanto a API respondia, o painel passou para outra loja (ou "Nova loja"), a edição em andamento não é tocada
            if (this.editando === loja) {
                this.editando = null;
            } else if (this.editando && this.aberturaDoPainel === abertura) {
                // seguiu digitando nesta loja durante o salvamento: o painel fica aberto, já ligado à loja salva (o próximo "Salvar" atualiza, não duplica)
                this.editando = { ...this.editando, id: resposta.loja.id };
            }
            this.notifications.success(this.intl.t('fleet-ops.ui.lojas.saved'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *adicionarUsuario() {
        const loja = this.lojaDoUsuario;
        if (!loja || !this.podeAdicionarUsuario) {
            return;
        }

        const { nome, email, telefone, senha } = this.novoUsuario;

        try {
            const corpo = { nome: texto(nome), email: texto(email), telefone: texto(telefone) || null, senha };
            const resposta = yield this.fetch.post(`${ENDPOINT}/${loja.id}/usuarios`, corpo);
            this.substituirLoja(resposta.loja);
            // se, enquanto a API respondia, o painel passou para outra loja, o rascunho dela não é tocado
            if (this.lojaDoUsuario?.id === loja.id) {
                this.fecharNovoUsuario();
            }
            this.notifications.success(this.intl.t('fleet-ops.ui.lojas.user-added'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *trocarSenha() {
        if (!this.podeTrocarSenha) {
            return;
        }

        const { loja, usuario } = this.senhaDe;

        try {
            yield this.fetch.put(`${ENDPOINT}/${loja.id}/usuarios/${usuario.id}/senha`, { senha: this.novaSenha });
            // se, enquanto a API respondia, o painel passou para outro usuário, a senha dele não é tocada
            if (this.senhaDe?.usuario.id === usuario.id) {
                this.fecharTrocarSenha();
            }
            this.notifications.success(this.intl.t('fleet-ops.ui.lojas.password-changed'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task({ drop: true }) *alterarAcesso(loja, usuario, ativo) {
        try {
            const resposta = yield this.fetch.put(`${ENDPOINT}/${loja.id}/usuarios/${usuario.id}/ativo`, { ativo });
            this.substituirLoja(resposta.loja);
            this.notifications.success(ativo ? this.intl.t('fleet-ops.ui.lojas.user-reactivated') : this.intl.t('fleet-ops.ui.lojas.user-deactivated'));
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
