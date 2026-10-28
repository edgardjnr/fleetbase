import Service, { inject as service } from '@ember/service';
import { race, restartableTask, timeout, waitForEvent } from 'ember-concurrency';
import { espera } from '../utils/entregas-pedido';
import { ARQUIVO_DA_BUZINA, registrarAceites } from '../utils/buzina';

/** Leitura dos aceites com a aba à vista; com erro, a espera cresce (espera). */
const INTERVALO_MS = 10000;

/**
 * Entregas: a buzina de moto no portal da loja quando um motoboy aceita um pedido dela (spec
 * docs/superpowers/specs/2026-10-28-troca-com-aceite-e-buzina-design.md). Lê entregas/loja/aceites a cada 10 s com a aba à
 * vista (oculta: espera ela voltar) e toca o áudio uma vez por leitura com aceite novo; a primeira leitura não toca. O
 * navegador só libera o som depois de um clique ou tecla na página: o primeiro gesto "destrava" o áudio (play e pause
 * mudos). Iniciado e encerrado pelo Portal::Topo, que fica em todas as telas do portal.
 */
export default class EntregasBuzinaService extends Service {
    @service fetch;

    vistas = null;
    audio = null;
    destravar = null;

    @restartableTask *acompanhar() {
        let falhas = 0;
        this.escutarGestos();
        while (!this.isDestroying) {
            const oculta = document.hidden;
            if (!oculta) {
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
            }
            const proxima = timeout(espera(INTERVALO_MS, falhas));
            yield oculta ? race([proxima, waitForEvent(document, 'visibilitychange')]) : proxima;
        }
    }

    /** Saiu do portal: para de ler e esquece as vistas (a volta não toca pelo que foi aceito enquanto estava fora). */
    encerrar() {
        this.acompanhar.cancelAll();
        this.pararDeEscutarGestos();
        this.vistas = null;
    }

    obterAudio() {
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
            const audio = this.obterAudio();
            if (audio) {
                try {
                    audio.muted = true;
                    audio.play()?.then?.(
                        () => {
                            audio.pause();
                            audio.currentTime = 0;
                            audio.muted = false;
                        },
                        () => {
                            audio.muted = false;
                        }
                    );
                } catch {
                    audio.muted = false;
                }
            }
            this.pararDeEscutarGestos();
        };
        document.addEventListener('pointerdown', this.destravar, true);
        document.addEventListener('keydown', this.destravar, true);
    }

    pararDeEscutarGestos() {
        if (this.destravar) {
            document.removeEventListener('pointerdown', this.destravar, true);
            document.removeEventListener('keydown', this.destravar, true);
            this.destravar = null;
        }
    }
}
