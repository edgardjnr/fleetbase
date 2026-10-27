import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { isBlank } from '@ember/utils';
import { later } from '@ember/runloop';
import toBoolean from '../utils/to-boolean';
import config from 'ember-get-config';

export default class SocketService extends Service {
    @tracked channels = [];

    constructor() {
        super(...arguments);
        this.socket = this.createSocketClusterClient();
    }

    instance() {
        return this.socket;
    }

    createSocketClusterClient() {
        const socketConfig = { ...config.socket };

        if (isBlank(socketConfig.hostname)) {
            socketConfig.hostname = window.location.hostname;
        }

        socketConfig.secure = toBoolean(socketConfig.secure);

        return socketClusterClient.create(socketConfig);
    }

    /**
     * Entregas: `options.aceitarJaInscrito` faz o callback valer também quando outra parte do console já se inscreveu no
     * canal. Sem ela, o `listener('subscribe').once()` espera um "inscrito" que não vem de novo e o callback nunca é
     * ligado: o sino (notification-tray) se inscreve no `user.<id>` antes da bandeja de mensagens, e a bandeja não ouvia
     * nada desse canal. O padrão continua o do Fleetbase (quem chama de novo para o mesmo canal não ganha outro ouvinte).
     */
    async listen(channelId, callback, options = {}) {
        later(
            this,
            async () => {
                const channel = this.socket.subscribe(channelId);

                // Track channel
                this.channels.pushObject(channel);

                // Listen to channel for events
                if (!(options.aceitarJaInscrito && channel.state === 'subscribed')) {
                    await channel.listener('subscribe').once();
                }

                // Listen for channel subscription
                (async () => {
                    for await (let output of channel) {
                        if (typeof callback === 'function') {
                            callback(output);
                        }
                    }
                })();
            },
            300
        );
    }

    closeChannels() {
        for (let i = 0; i < this.channels.length; i++) {
            const channel = this.channels.objectAt(i);

            channel.close();
        }
    }
}
