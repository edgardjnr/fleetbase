import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';

export default class CfgEditButtonsHelper extends Helper {
    @service intl;

    compute([cfg], { permission, disabled, onClick }) {
        return cfgEditButtons(cfg, { permission, disabled, onClick }, this.intl.t('ember-ui.common.edit'));
    }
}

function cfgEditButtons(cfg, { permission, disabled, onClick }, editText = 'Edit') {
    return [
        {
            type: 'default',
            text: editText,
            icon: 'pencil',
            iconPrefix: 'fas',
            permission,
            disabled,
            onClick: () => {
                if (typeof onClick === 'function') {
                    return onClick(cfg);
                }
                // default fallback
                cfg.isEditing = true;
            },
        },
    ];
}
