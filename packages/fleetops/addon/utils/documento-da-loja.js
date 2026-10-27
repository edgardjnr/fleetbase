/**
 * Entregas RestaurantePro: CPF ou CNPJ da loja e o modelo de cobrança (pré-pago ou pós-pago), na tela Lojas.
 * Funções puras (sem import do Ember), testadas em scripts/teste-portal/documento-da-loja.test.mjs.
 *
 * Mesma regra do servidor (`App\Support\Entregas\PrePago\PrePago::documento` e `::definir`):
 * - o documento vale com ou sem pontuação: tudo que não é dígito sai antes de conferir;
 * - 11 dígitos = CPF, 14 = CNPJ, com os dígitos verificadores certos; todos iguais ("111.111.111-11") não vale;
 * - pré-pago exige documento válido, o digitado ou o já gravado; vazio mantém o gravado.
 */

export const MODELOS_DE_COBRANCA = ['pos', 'pre'];
export const MODELO_PADRAO = 'pos';
// o servidor aceita no máximo 20 caracteres no campo (com a pontuação)
export const TAMANHO_MAXIMO_DO_CAMPO = 20;

/** Só os dígitos do que foi digitado ("529.982.247-25" → "52998224725"). */
export function soDigitos(valor) {
    return String(valor ?? '').replace(/\D/g, '');
}

const todosIguais = (digitos) => /^(\d)\1*$/.test(digitos);

/** Dígito verificador pelo módulo 11 (resto < 2 → 0, senão 11 − resto), com os pesos da posição. */
const digitoVerificador = (digitos, pesos) => {
    const soma = pesos.reduce((total, peso, indice) => total + Number(digitos[indice]) * peso, 0);
    const resto = soma % 11;
    return resto < 2 ? 0 : 11 - resto;
};

const PESOS_CPF_1 = [10, 9, 8, 7, 6, 5, 4, 3, 2];
const PESOS_CPF_2 = [11, 10, 9, 8, 7, 6, 5, 4, 3, 2];
const PESOS_CNPJ_1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
const PESOS_CNPJ_2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

/** CPF de 11 dígitos (só dígitos) com os dois verificadores certos. */
export function cpfValido(digitos) {
    if (!/^\d{11}$/.test(digitos) || todosIguais(digitos)) {
        return false;
    }
    return digitoVerificador(digitos, PESOS_CPF_1) === Number(digitos[9]) && digitoVerificador(digitos, PESOS_CPF_2) === Number(digitos[10]);
}

/** CNPJ de 14 dígitos (só dígitos) com os dois verificadores certos. */
export function cnpjValido(digitos) {
    if (!/^\d{14}$/.test(digitos) || todosIguais(digitos)) {
        return false;
    }
    return digitoVerificador(digitos, PESOS_CNPJ_1) === Number(digitos[12]) && digitoVerificador(digitos, PESOS_CNPJ_2) === Number(digitos[13]);
}

/** 'cpf', 'cnpj' ou null (vazio, tamanho errado ou verificador errado). */
export function tipoDoDocumento(valor) {
    const digitos = soDigitos(valor);
    if (cpfValido(digitos)) {
        return 'cpf';
    }
    if (cnpjValido(digitos)) {
        return 'cnpj';
    }
    return null;
}

/** Documento válido (CPF ou CNPJ), com ou sem pontuação. */
export function documentoValido(valor) {
    return tipoDoDocumento(valor) !== null;
}

/** Só os dígitos do documento válido, ou null (é o que vai ao servidor). */
export function documentoParaEnviar(valor) {
    return documentoValido(valor) ? soDigitos(valor) : null;
}

/**
 * Documento com a pontuação ("529.982.247-25", "11.222.333/0001-81"). Pelo tamanho, sem conferir o verificador; outro
 * tamanho volta como foi digitado.
 */
export function formatarDocumento(valor) {
    const digitos = soDigitos(valor);
    if (digitos.length === 11) {
        return digitos.replace(/^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4');
    }
    if (digitos.length === 14) {
        return digitos.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/, '$1.$2.$3/$4-$5');
    }
    return String(valor ?? '');
}

/**
 * Documento com o começo e o fim escondidos, para o card da loja: CPF "***.456.789-**", CNPJ "**.345.678/0001-**".
 * Vazio ou de outro tamanho: ''.
 */
export function mascararDocumento(valor) {
    const digitos = soDigitos(valor);
    if (digitos.length === 11) {
        return `***.${digitos.slice(3, 6)}.${digitos.slice(6, 9)}-**`;
    }
    if (digitos.length === 14) {
        return `**.${digitos.slice(2, 5)}.${digitos.slice(5, 8)}/${digitos.slice(8, 12)}-**`;
    }
    return '';
}

/** Modelo de cobrança da loja vinda de `GET entregas/lojas` ('pre' ou 'pos'; sem o campo, pós-pago, o padrão). */
export function modeloDaLoja(loja) {
    return loja?.cobranca?.modelo === 'pre' ? 'pre' : MODELO_PADRAO;
}

/**
 * O que impede salvar a cobrança (vazio = pode salvar):
 * - 'modelo-invalido': nem 'pos' nem 'pre';
 * - 'documento-invalido': digitou algo que não é CPF nem CNPJ válido (vale nos dois modelos, como no servidor);
 * - 'documento-obrigatorio': pré-pago sem documento digitado e sem documento gravado.
 */
export function errosDaCobranca({ modelo, documento } = {}, documentoGravado = null) {
    const erros = [];
    if (!MODELOS_DE_COBRANCA.includes(modelo)) {
        erros.push('modelo-invalido');
    }

    const digitado = soDigitos(documento);
    if (digitado !== '' && !documentoValido(digitado)) {
        erros.push('documento-invalido');
    } else if (modelo === 'pre' && digitado === '' && !documentoValido(documentoGravado)) {
        erros.push('documento-obrigatorio');
    }

    return erros;
}

/** Corpo `cobranca` do POST/PUT de `entregas/lojas`: o modelo e o documento só com dígitos (vazio → null, mantém o gravado). */
export function cobrancaParaEnviar({ modelo, documento } = {}) {
    const digitos = soDigitos(documento);
    return { modelo: MODELOS_DE_COBRANCA.includes(modelo) ? modelo : MODELO_PADRAO, documento: digitos === '' ? null : digitos };
}
