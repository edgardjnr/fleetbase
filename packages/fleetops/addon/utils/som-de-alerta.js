// Entregas: o aviso sonoro de pedido sem motoboy no console (serviço pedido-sem-motoboy). Três toques curtos gerados
// pelo Web Audio, sem arquivo de som. O navegador só libera o som depois de um clique ou tecla na página: o serviço
// chama prepararSomDeAlerta() a cada gesto, e a partir daí o aviso toca até com a aba em segundo plano.

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
export function prepararSomDeAlerta() {
    const audio = obterContexto();

    if (audio && audio.state !== 'running') {
        audio.resume().catch(() => {});
    }
}

/** Três toques (mi, mi, lá agudos). Sem som liberado ou sem Web Audio, não faz nada. */
export function tocarSomDeAlerta() {
    const audio = obterContexto();

    if (!audio) {
        return;
    }

    if (audio.state !== 'running') {
        audio.resume().catch(() => {});
    }

    try {
        const agora = audio.currentTime;

        [
            [1318.5, 0],
            [1318.5, 0.22],
            [1760, 0.44],
        ].forEach(([frequencia, atraso]) => {
            const oscilador = audio.createOscillator();
            const volume = audio.createGain();

            oscilador.type = 'square';
            oscilador.frequency.value = frequencia;
            volume.gain.setValueAtTime(0.0001, agora + atraso);
            volume.gain.exponentialRampToValueAtTime(0.25, agora + atraso + 0.02);
            volume.gain.exponentialRampToValueAtTime(0.0001, agora + atraso + 0.18);
            oscilador.connect(volume);
            volume.connect(audio.destination);
            oscilador.start(agora + atraso);
            oscilador.stop(agora + atraso + 0.2);
        });
    } catch {
        // sem som: a notificação fixa continua avisando
    }
}
