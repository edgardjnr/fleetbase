import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
// Entregas: a regra de "mensagem lida" (o 100% visível do Fleetbase falhava por fração de pixel)
import mensagemFoiVista, { LIMIARES } from '../../utils/chat-mensagem-vista';

export default class ChatWindowMessageComponent extends Component {
    @service store;
    @tracked chatMessage;
    @tracked chatParticipant;
    @tracked channelFeedContainerElement;
    @tracked observer;

    constructor(owner, { record, chatParticipant, channelFeedContainerElement }) {
        super(...arguments);
        this.chatMessage = record;
        this.chatParticipant = chatParticipant;
        this.channelFeedContainerElement = channelFeedContainerElement;
    }

    willDestroy() {
        if (this.observer) {
            this.observer.disconnect();
        }
        super.willDestroy();
    }

    // Entregas: drop + recibo guardado na mensagem, porque o observer agora avisa a cada 5% (sem POST repetido)
    @task({ drop: true }) *createReadReceipt() {
        // if the chat participant is the message sender no read receipt is required
        if (this.chatParticipant && this.chatMessage.sender_uuid === this.chatParticipant.id) {
            return;
        }

        if (this.chatMessage.doesntHaveReadReceipt(this.chatParticipant)) {
            const newReadReceipt = this.store.createRecord('chat-receipt', {
                participant_uuid: this.chatParticipant.id,
                chat_message_uuid: this.chatMessage.id,
            });

            yield newReadReceipt.save();
            if (!this.chatMessage.receipts.includes(newReadReceipt)) {
                this.chatMessage.receipts.pushObject(newReadReceipt);
            }
        }
    }

    @action trackVisibility(chatMessageElement) {
        this.observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (mensagemFoiVista(entry)) {
                        this.createReadReceipt.perform();
                    }
                });
            },
            {
                root: this.getChannelFeedContainerElement(),
                rootMargin: '0px',
                threshold: LIMIARES,
            }
        );

        this.observer.observe(chatMessageElement);
    }

    getChannelFeedContainerElement() {
        if (this.channelFeedContainerElement) {
            return this.channelFeedContainerElement;
        }
        return document.getElementById(`channel-${this.chatMessage.chat_channel_uuid}-feed`);
    }
}
