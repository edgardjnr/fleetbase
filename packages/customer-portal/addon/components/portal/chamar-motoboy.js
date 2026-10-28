import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task, timeout } from 'ember-concurrency';
import {
    ESPERA_DA_BUSCA_MS,
    LIMITES,
    centavosDoTexto,
    corpoDoChamado,
    digitosDoTelefone,
    enderecoDaSugestao,
    enderecoDoCliente,
    formatarReais,
    formatarTelefone,
    linhaDoEndereco,
    novaChave,
    pontoValido,
    problemaDoPasso,
    semNumero,
    telefoneValido,
    trocoDoMotoboy,
} from '../../utils/chamar-motoboy';

/** O endereço com campos @tracked: o EnderecoGoogleInput liga o @value da rua em duas vias. */
class EnderecoDoChamado {
    @tracked rua = '';
    @tracked bairro = '';
    @tracked cidade = '';
    @tracked uf = '';
    @tracked cep = '';
    @tracked latitude = null;
    @tracked longitude = null;

    constructor(dados = {}) {
        Object.assign(this, dados);
    }

    get dados() {
        return { rua: this.rua, bairro: this.bairro, cidade: this.cidade, uf: this.uf, cep: this.cep, latitude: this.latitude, longitude: this.longitude };
    }
}

const NUMERO_DO_PASSO = { cliente: 1, endereco: 2, pagamento: 3 };

/**
 * Entregas: painel "Chamar motoboy" do portal da loja (rota portal.orders.new; spec
 * docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md). Passos cliente → endereço → pagamento e a tela
 * "Procurando motoboy". O cliente que já pediu vem do GET entregas/loja/clientes; o pedido sai do POST
 * entregas/loja/chamar-motoboy com a chave do painel (a repetição pela rede devolve o mesmo pedido; o "Novo pedido"
 * troca a chave). Funções puras em utils/chamar-motoboy.js. Argumento: @loja (loja/minha-loja: o nome e o centro do mapa).
 */
export default class PortalChamarMotoboyComponent extends Component {
    @service fetch;
    @service notifications;
    @service hostRouter;
    @service customerPortalOrderCreation;

    LIMITES = LIMITES;

    @tracked passo = 'cliente';
    @tracked chave = novaChave();
    @tracked telefone = '';
    @tracked nome = '';
    @tracked endereco = new EnderecoDoChamado();
    @tracked complemento = '';
    @tracked referencia = '';
    @tracked pago = null;
    @tracked valor = '';
    @tracked trocoPara = '';
    @tracked forma = 'dinheiro';
    @tracked observacao = '';
    /** O "Já pediu antes" (loja/clientes), ou null. */
    @tracked cliente = null;
    /** A resposta do chamar-motoboy (a tela "Procurando motoboy"). */
    @tracked chamado = null;

    get estado() {
        return {
            passo: this.passo,
            chave: this.chave,
            telefone: this.telefone,
            nome: this.nome,
            endereco: this.endereco.dados,
            complemento: this.complemento,
            referencia: this.referencia,
            pago: this.pago,
            valor: this.valor,
            trocoPara: this.trocoPara,
            forma: this.forma,
            observacao: this.observacao,
        };
    }

    get nomeDaLoja() {
        return String(this.args.loja?.nome ?? '').trim() || null;
    }

    get numeroDoPasso() {
        return NUMERO_DO_PASSO[this.passo] ?? 3;
    }

    get passoFeitoCliente() {
        return this.numeroDoPasso > 1;
    }

    get passoFeitoEndereco() {
        return this.numeroDoPasso > 2;
    }

    get problema() {
        return problemaDoPasso(this.passo, this.estado);
    }

    get podeContinuar() {
        return this.problema === null;
    }

    get avisoDoTelefone() {
        return digitosDoTelefone(this.telefone).length >= 8 && !telefoneValido(this.telefone);
    }

    get telefoneFormatado() {
        return formatarTelefone(this.telefone);
    }

    get centroDoMapa() {
        const coleta = this.args.loja?.coleta;
        const latitude = Number(coleta?.latitude);
        const longitude = Number(coleta?.longitude);
        return coleta && pontoValido(latitude, longitude) ? { latitude, longitude } : null;
    }

    get temPonto() {
        return pontoValido(this.endereco.latitude, this.endereco.longitude);
    }

    get ruaSemNumero() {
        return semNumero(this.endereco.rua);
    }

    get linhaDoEndereco() {
        return linhaDoEndereco(this.endereco, this.complemento);
    }

    get linhaDoEnderecoDoCliente() {
        const e = this.cliente?.endereco;
        return e ? linhaDoEndereco(e, e.complemento) : null;
    }

    get podeUsarEnderecoDoCliente() {
        return enderecoDoCliente(this.cliente) !== null;
    }

    get cobrar() {
        return this.pago === false;
    }

    get emDinheiro() {
        return this.forma === 'dinheiro';
    }

    get trocoDoMotoboy() {
        if (!this.cobrar || !this.emDinheiro) return null;
        const troco = trocoDoMotoboy(centavosDoTexto(this.valor), centavosDoTexto(this.trocoPara));
        return troco === null ? null : formatarReais(troco);
    }

    @action aoDigitarTelefone(evento) {
        this.cliente = null;
        this.buscarCliente.perform(evento?.target?.value ?? this.telefone);
    }

    /** Pergunta quem é o telefone depois da última tecla; sem resposta, a loja segue digitando (sem aviso). */
    @task({ restartable: true }) *buscarCliente(texto) {
        const digitos = digitosDoTelefone(texto);
        if (!telefoneValido(digitos)) return;
        yield timeout(ESPERA_DA_BUSCA_MS);
        try {
            const resposta = yield this.fetch.get('entregas/loja/clientes', { telefone: digitos });
            this.cliente = resposta?.cliente ?? null;
            if (this.cliente?.nome && !String(this.nome).trim()) {
                this.nome = this.cliente.nome;
            }
        } catch {
            this.cliente = null;
        }
    }

    @action usarEnderecoDoCliente() {
        const salvo = enderecoDoCliente(this.cliente);
        if (!salvo) return;
        this.endereco = new EnderecoDoChamado(salvo.endereco);
        this.complemento = salvo.complemento;
        this.referencia = salvo.referencia;
        if (!String(this.nome).trim()) this.nome = this.cliente.nome ?? '';
        this.passo = 'pagamento';
    }

    @action outroEndereco() {
        if (!String(this.nome).trim()) this.nome = this.cliente?.nome ?? '';
        this.endereco = new EnderecoDoChamado();
        this.complemento = '';
        this.referencia = '';
        this.passo = 'endereco';
    }

    @action continuar() {
        if (!this.podeContinuar) return;
        this.passo = this.passo === 'cliente' ? 'endereco' : 'pagamento';
    }

    @action voltar() {
        this.passo = this.passo === 'pagamento' ? 'endereco' : 'cliente';
    }

    @action irPara(passo) {
        this.passo = passo;
    }

    @action escolherSugestao(sugestao) {
        this.endereco = new EnderecoDoChamado(enderecoDaSugestao(sugestao, this.endereco.dados));
    }

    @action moverPonto({ latitude, longitude }) {
        this.endereco.latitude = latitude;
        this.endereco.longitude = longitude;
    }

    @action escolherPago(pago) {
        this.pago = pago;
    }

    @action escolherForma(forma) {
        this.forma = forma;
    }

    /** O segundo toque é descartado; o servidor confere de novo e o 422 traz a frase pronta. */
    @task({ drop: true }) *chamar() {
        if (this.problema !== null) return;
        try {
            const resposta = yield this.fetch.post('entregas/loja/chamar-motoboy', corpoDoChamado(this.estado));
            this.chamado = resposta?.pedido ?? null;
            this.passo = 'chamado';
        } catch (erro) {
            this.notifications.serverError(erro);
        }
    }

    @action verNoMapa() {
        if (this.chamado?.id) {
            // como o submit do formulário antigo: o rascunho (o P da loja no mapa grande) sai antes de abrir o pedido
            this.customerPortalOrderCreation.reset();
            this.hostRouter.transitionTo('customer-portal.portal.orders.details', this.chamado.id);
        }
    }

    @action novoPedido() {
        this.passo = 'cliente';
        this.chave = novaChave();
        this.telefone = '';
        this.nome = '';
        this.endereco = new EnderecoDoChamado();
        this.complemento = '';
        this.referencia = '';
        this.pago = null;
        this.valor = '';
        this.trocoPara = '';
        this.forma = 'dinheiro';
        this.observacao = '';
        this.cliente = null;
        this.chamado = null;
    }

    @action fechar() {
        this.customerPortalOrderCreation.reset();
        this.hostRouter.transitionTo('customer-portal.portal.orders.index');
    }
}
