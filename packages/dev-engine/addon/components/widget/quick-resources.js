import Component from '@glimmer/component';

export default class WidgetQuickResourcesComponent extends Component {
    resources = [
        { labelKey: 'developers.ui.resources-api-keys', route: 'api-keys.index', icon: 'key' },
        { labelKey: 'developers.ui.resources-webhooks', route: 'webhooks.index', icon: 'globe' },
        { labelKey: 'developers.ui.resources-logs', route: 'logs.index', icon: 'terminal' },
        { labelKey: 'developers.ui.resources-events', route: 'events.index', icon: 'bolt' },
        { labelKey: 'developers.ui.resources-sockets', route: 'sockets.index', icon: 'plug' },
    ];
}
