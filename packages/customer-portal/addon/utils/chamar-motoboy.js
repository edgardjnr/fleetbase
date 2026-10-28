/**
 * Entregas: funções puras do painel "Chamar motoboy" do portal da loja (Portal::ChamarMotoboy; spec
 * docs/superpowers/specs/2026-10-28-chamar-motoboy-portal-design.md). Teste: scripts/teste-portal/chamar-motoboy.test.mjs.
 * O telefone, o valor e os limites seguem o servidor (App\Support\Entregas\Portal\ChamadoDaLoja): mudou lá, mude aqui.
 */

/** Espera entre a última tecla do telefone e a busca do cliente. */
export const ESPERA_DA_BUSCA_MS = 400;

/** Os mesmos limites do servidor (ChamadoDaLoja::LIMITES), no maxlength dos campos. */
export const LIMITES = { nome: 120, rua: 190, bairro: 120, cidade: 120, complemento: 190, referencia: 190, observacao: 500 };

/** R$ 100 mil, em centavos (ChamadoDaLoja::VALOR_MAXIMO_CENTAVOS): acima disso o servidor recusa o valor. */
export const VALOR_MAXIMO_CENTAVOS = 10000000;

/** Só os dígitos, com DDD: sem o 55 da frente (12 ou 13 dígitos) e sem o 0 do interurbano (11 ou 12). */
export function digitosDoTelefone(texto) {
    let digitos = String(texto ?? '').replace(/\D+/g, '');
    if (digitos.length >= 12 && digitos.startsWith('55')) digitos = digitos.slice(2);
    if ((digitos.length === 11 || digitos.length === 12) && digitos.startsWith('0')) digitos = digitos.slice(1);
    return digitos;
}

/** 10 (fixo) ou 11 (celular) dígitos, DDD sem 0 na frente. */
export function telefoneValido(texto) {
    const digitos = digitosDoTelefone(texto);
    return (digitos.length === 10 || digitos.length === 11) && digitos[0] !== '0';
}

export function formatarTelefone(texto) {
    const d = digitosDoTelefone(texto);
    if (d.length === 11) return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
    if (d.length === 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
    return String(texto ?? '');
}

/** "58,90", "R$ 1.234,56", "58.9" ou 58.9 em centavos; vazio, inválido ou acima de R$ 100 mil = null. */
export function centavosDoTexto(texto) {
    let centavos;
    if (typeof texto === 'number') {
        centavos = Number.isFinite(texto) && texto >= 0 ? Math.round(texto * 100) : null;
    } else {
        let limpo = String(texto ?? '').replace(/R\$|\s/g, '');
        if (limpo === '') return null;
        if (limpo.includes(',')) limpo = limpo.replace(/\./g, '').replace(',', '.');
        // sem vírgula, "1.500" é milhar (R$ 1.500,00), como no servidor; "58.9" e "58.90" seguem decimais
        else if (/^\d{1,3}(\.\d{3})+$/.test(limpo)) limpo = limpo.replace(/\./g, '');
        centavos = /^\d+(\.\d+)?$/.test(limpo) ? Math.round(parseFloat(limpo) * 100) : null;
    }
    return centavos !== null && centavos <= VALOR_MAXIMO_CENTAVOS ? centavos : null;
}

export function formatarReais(centavos) {
    const n = Math.round(Number(centavos) || 0);
    const reais = Math.floor(Math.abs(n) / 100)
        .toString()
        .replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const resto = String(Math.abs(n) % 100).padStart(2, '0');
    return `${n < 0 ? '-' : ''}R$ ${reais},${resto}`;
}

/** O que o motoboy leva de troco (troco para − valor), só quando o troco é maior que o valor. */
export function trocoDoMotoboy(valorCentavos, trocoParaCentavos) {
    if (!Number.isInteger(valorCentavos) || !Number.isInteger(trocoParaCentavos) || trocoParaCentavos <= valorCentavos) return null;
    return trocoParaCentavos - valorCentavos;
}

/** Rua digitada sem nenhum número (o Google às vezes devolve só a rua). */
export function semNumero(rua) {
    const texto = String(rua ?? '').trim();
    return texto !== '' && !/\d/.test(texto);
}

/** Números dentro da faixa e fora do (0, 0), o "sem GPS" do Fleetbase (App\Support\Entregas\Coordenada::valida). */
export function pontoValido(latitude, longitude) {
    const lat = typeof latitude === 'number' ? latitude : NaN;
    const lng = typeof longitude === 'number' ? longitude : NaN;
    return Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && (Math.abs(lat) > 0.0001 || Math.abs(lng) > 0.0001);
}

/** {latitude, longitude} do GeoJSON do Google (`location.coordinates` = [lng, lat]) ou dos campos soltos; senão null. */
export function pontoDoEndereco(endereco) {
    const coordenadas = endereco?.location?.coordinates;
    if (Array.isArray(coordenadas) && coordenadas.length === 2) {
        const [lng, lat] = coordenadas.map(Number);
        if (pontoValido(lat, lng)) return { latitude: lat, longitude: lng };
    }
    const lat = Number(endereco?.latitude);
    const lng = Number(endereco?.longitude);
    return endereco?.latitude != null && endereco?.longitude != null && pontoValido(lat, lng) ? { latitude: lat, longitude: lng } : null;
}

/** O que impede de seguir no passo (null = pode): telefone, nome, rua, ponto, pago, valor ou troco. */
export function problemaDoPasso(passo, e) {
    if (passo === 'cliente') {
        if (!telefoneValido(e.telefone)) return 'telefone';
        if (!String(e.nome ?? '').trim()) return 'nome';
        return null;
    }
    if (passo === 'endereco') {
        if (!String(e.endereco?.rua ?? '').trim()) return 'rua';
        if (!pontoValido(e.endereco?.latitude, e.endereco?.longitude)) return 'ponto';
        return null;
    }
    if (passo === 'pagamento') {
        if (e.pago !== true && e.pago !== false) return 'pago';
        if (e.pago) return null;
        const valor = centavosDoTexto(e.valor);
        if (!valor) return 'valor';
        // como o servidor: troco vazio, 0 ou igual ao valor = sem troco; menor que o valor ou inválido = recusa
        if (e.forma === 'dinheiro' && String(e.trocoPara ?? '').trim() !== '') {
            const troco = centavosDoTexto(e.trocoPara);
            if (troco === null || (troco !== 0 && troco < valor)) return 'troco';
        }
        return null;
    }
    return null;
}

/** O corpo do POST entregas/loja/chamar-motoboy (o que o ChamadoDaLoja::validar lê). */
export function corpoDoChamado(e) {
    const texto = (valor) => String(valor ?? '').trim();
    const ouNull = (valor) => texto(valor) || null;
    const cobrar = e.pago === false;
    const cobranca = cobrar ? { forma: e.forma, valor: texto(e.valor) } : { forma: 'pago' };
    if (cobrar && e.forma === 'dinheiro' && texto(e.trocoPara)) cobranca.troco_para = texto(e.trocoPara);

    return {
        chave: e.chave,
        cliente: { nome: texto(e.nome), telefone: digitosDoTelefone(e.telefone) },
        entrega: {
            rua: texto(e.endereco.rua),
            bairro: ouNull(e.endereco.bairro),
            cidade: ouNull(e.endereco.cidade),
            uf: ouNull(e.endereco.uf),
            cep: ouNull(e.endereco.cep),
            latitude: e.endereco.latitude,
            longitude: e.endereco.longitude,
            complemento: ouNull(e.complemento),
            referencia: ouNull(e.referencia),
        },
        cobranca,
        observacao: ouNull(e.observacao),
    };
}

/** "Rua Olinda, 45 · Ap 12 · Centro". */
export function linhaDoEndereco(endereco, complemento) {
    return [endereco?.rua, complemento, endereco?.bairro]
        .map((parte) => String(parte ?? '').trim())
        .filter(Boolean)
        .join(' · ');
}

/** O endereço do painel a partir da sugestão escolhida no EnderecoGoogleInput (sem ponto, fica o que havia). */
export function enderecoDaSugestao(sugestao, atual = {}) {
    const ponto = pontoDoEndereco(sugestao);
    return {
        rua: sugestao?.street1 ?? atual?.rua ?? '',
        bairro: sugestao?.neighborhood ?? '',
        cidade: sugestao?.city ?? '',
        uf: sugestao?.province ?? '',
        cep: sugestao?.postal_code ?? '',
        latitude: ponto?.latitude ?? atual?.latitude ?? null,
        longitude: ponto?.longitude ?? atual?.longitude ?? null,
    };
}

/** O último endereço do "Já pediu antes" (GET loja/clientes, PedidosDoPortal::clienteDoTelefone) no formato do painel; sem ponto, null. */
export function enderecoDoCliente(cliente) {
    const e = cliente?.endereco;
    const ponto = e ? pontoDoEndereco(e) : null;
    if (!ponto) return null;
    return {
        endereco: { rua: e.rua ?? '', bairro: e.bairro ?? '', cidade: e.cidade ?? '', uf: e.uf ?? '', cep: e.cep ?? '', ...ponto },
        complemento: e.complemento ?? '',
        referencia: e.referencia ?? '',
    };
}

/** uuid v4 do chamado (a repetição pela rede devolve o mesmo pedido). */
export function novaChave() {
    if (typeof globalThis.crypto?.randomUUID === 'function') return globalThis.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    globalThis.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const h = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}
