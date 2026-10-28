import Service, { inject as service } from '@ember/service';
import { restartableTask, timeout } from 'ember-concurrency';
import { espera } from '../utils/entregas-pedido';
import { ARQUIVO_DA_BUZINA, registrarAceites } from '../utils/buzina';

/** Leitura dos aceites (o limitador do servidor é de 30 por minuto); com erro, a espera cresce (espera). */
const INTERVALO_MS = 10000;

/**
 * Os gestos que liberam o som: touchend (toque), click (mouse e toque) e keydown. Sem pointerdown: no iPhone/iPad ele chega
 * antes do touchend, não libera mídia e mesmo assim o play mudo dá certo, e a destrava acabaria nele, sem liberar nada.
 */
const GESTOS = ['touchend', 'click', 'keydown'];

/**
 * Entregas: a buzina de moto no portal da loja quando um motoboy aceita um pedido dela (spec
 * docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md). Lê entregas/loja/aceites a cada 10 s, também com
 * a aba em segundo plano (o operador costuma ficar noutra aba; o navegador pode espaçar o timer da aba oculta), e toca o
 * áudio uma vez por leitura com aceite novo; a primeira leitura não toca. O navegador só libera o som depois de um gesto
 * na página: o gesto "destrava" o áudio (play e pause mudos) e, se o navegador recusar, o próximo gesto tenta de novo.
 * Iniciado e encerrado pelo Portal::Topo, que fica em todas as telas do portal.
 */
export default class EntregasBuzinaService extends Service {
    @service fetch;

    vistas = null;
    audio = null;
    destravar = null;
    /** O play mudo da destrava está em andamento (o tocar() o desliga, para a destrava não pausar a buzina de verdade). */
    destravando = false;

    @restartableTask *acompanhar() {
        let falhas = 0;
        this.escutarGestos();
        while (!this.isDestroying) {
            // também com a aba oculta, como a lista de Conversas
            try {
                const resposta = yield this.fetch.get('entregas/loja/aceites');
                const { vistas, tocar } = registrarAceites(this.vistas, resposta);
                this.vistas = vistas;
                falhas = 0;
                if (tocar) {
                    this.tocar();
                }
            } catch {
                falhas++;
            }
            yield timeout(espera(INTERVALO_MS, falhas));
        }
    }

    /** Saiu do portal: para de ler e esquece as vistas (a volta não toca pelo que foi aceito enquanto estava fora). */
    encerrar() {
        this.acompanhar.cancelAll();
        this.pararDeEscutarGestos();
        this.vistas = null;
    }

    obterAudio() {
        // o arquivo falhou (404 ou 502 num deploy, por exemplo): recria, para não silenciar a sessão inteira
        if (this.audio?.error) {
            this.audio = null;
        }
        if (!this.audio && typeof Audio !== 'undefined') {
            try {
                this.audio = new Audio(ARQUIVO_DA_BUZINA);
                this.audio.preload = 'auto';
            } catch {
                this.audio = null;
            }
        }

        return this.audio;
    }

    /** Toca do começo. Sem som liberado pelo navegador ou sem o arquivo, não faz nada. */
    tocar() {
        const audio = this.obterAudio();
        if (!audio) {
            return;
        }
        try {
            this.destravando = false;
            audio.muted = false;
            audio.currentTime = 0;
            audio.play()?.catch?.(() => {});
        } catch {
            // sem som liberado: nada
        }
    }

    escutarGestos() {
        if (this.destravar) {
            return;
        }
        this.destravar = () => {
            // um toque dispara touchend e click: com a destrava em andamento, os outros esperam o resultado
            if (this.destravando) {
                return;
            }
            const audio = this.obterAudio();
            if (!audio) {
                return;
            }
            // já está tocando (a buzina de verdade): o som está liberado, sem pausar
            if (!audio.paused) {
                this.pararDeEscutarGestos();

                return;
            }

            const liberado = () => {
                if (this.destravando) {
                    audio.pause();
                    audio.currentTime = 0;
                }
                this.destravando = false;
                audio.muted = false;
                this.pararDeEscutarGestos();
            };
            // recusado: continua ouvindo, o próximo gesto tenta de novo
            const recusado = () => {
                this.destravando = false;
                audio.muted = false;
            };

            this.destravando = true;
            try {
                audio.muted = true;
                const promessa = audio.play();
                if (typeof promessa?.then === 'function') {
                    promessa.then(liberado, recusado);
                } else {
                    // navegador antigo, sem promessa: o play já começou
                    liberado();
                }
            } catch {
                recusado();
            }
        };
        for (const gesto of GESTOS) {
            document.addEventListener(gesto, this.destravar, true);
        }
    }

    pararDeEscutarGestos() {
        if (this.destravar) {
            for (const gesto of GESTOS) {
                document.removeEventListener(gesto, this.destravar, true);
            }
            this.destravar = null;
        }
    }
}
