/**
 * Entregas RestaurantePro: funções puras do bloco "Integração" do card da loja na tela Lojas (componente
 * loja/integracao). Os dados vêm de GET int/v1/entregas/lojas/{id}/integracao (PainelDaIntegracao::daLoja).
 * Sem imports: testado no Node (scripts/teste-portal/integracao-da-loja.test.mjs).
 */

/** 'sem-chave' (nenhuma chave ativa), 'aguardando' (chave gerada, o sistema ainda não chamou POST conexao) ou 'conectada'. */
export function situacaoDaIntegracao(integracao) {
    if (!integracao?.ativa) {
        return 'sem-chave';
    }

    return integracao.conectada ? 'conectada' : 'aguardando';
}

/**
 * Quanto tempo passou desde o instante ISO, para o "último uso há 3 min": {unidade: 'agora'|'minutos'|'horas'|'dias', n}.
 * Data inválida ou ausente: null. Instante no futuro (relógio do PC atrasado) conta como "agora".
 */
export function tempoDesde(iso, agora) {
    const quando = Date.parse(iso ?? '');
    if (!Number.isFinite(quando)) {
        return null;
    }
    const segundos = Math.max(0, Math.floor((Number(agora) - quando) / 1000));
    if (segundos < 60) {
        return { unidade: 'agora', n: 0 };
    }
    const minutos = Math.floor(segundos / 60);
    if (minutos < 60) {
        return { unidade: 'minutos', n: minutos };
    }
    const horas = Math.floor(minutos / 60);
    if (horas < 24) {
        return { unidade: 'horas', n: horas };
    }

    return { unidade: 'dias', n: Math.floor(horas / 24) };
}
