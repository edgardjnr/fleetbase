// Entregas: o aviso sonoro de mensagem nova no chat da loja (serviço entregas-conversas). Dois toques curtos gerados
// pelo Web Audio, sem arquivo de som. O navegador só libera o som depois de um clique ou tecla na página: o serviço
// chama prepararSom() no primeiro gesto, e a partir daí o aviso toca até com a aba em segundo plano.

let contexto = null;

function obterContexto() {
    const Contexto = globalThis.AudioContext ?? globalThis.webkitAudioContext;

    if (!Contexto) {
        return null;
    }

    if (!contexto) {
        try {
            contexto = new Contexto();
        } catch {
            return null;
        }
    }

    return contexto;
}

/** Libera o som (chamado num clique ou tecla do usuário). */
export function prepararSom() {
    const audio = obterContexto();

    if (audio?.state === 'suspended') {
        audio.resume().catch(() => {});
    }
}

/** Dois toques curtos (lá e mi agudos). Sem som liberado ou sem Web Audio, não faz nada. */
export function tocarSomDeMensagem() {
    const audio = obterContexto();

    if (!audio) {
        return;
    }

    if (audio.state === 'suspended') {
        audio.resume().catch(() => {});
    }

    try {
        const agora = audio.currentTime;

        [
            [880, 0],
            [1318.5, 0.16],
        ].forEach(([frequencia, atraso]) => {
            const oscilador = audio.createOscillator();
            const volume = audio.createGain();

            oscilador.type = 'sine';
            oscilador.frequency.value = frequencia;
            volume.gain.setValueAtTime(0.0001, agora + atraso);
            volume.gain.exponentialRampToValueAtTime(0.3, agora + atraso + 0.02);
            volume.gain.exponentialRampToValueAtTime(0.0001, agora + atraso + 0.35);
            oscilador.connect(volume);
            volume.connect(audio.destination);
            oscilador.start(agora + atraso);
            oscilador.stop(agora + atraso + 0.4);
        });
    } catch {
        // sem som: o total de não lidas no botão Conversas continua avisando
    }
}
