import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class MapDrawerComponent extends Component {
    @service mapDrawer;
    @service universe;
    @service('universe/menu-service') menuService;
    @service intl;

    #tab(id, titleKey, options) {
        // keep the English-derived id stable so the cached active tab survives locale changes
        return { ...this.universe._createMenuItem(this.intl.t(titleKey), null, options), id };
    }

    get tabs() {
        const registeredTabs = this.menuService.getMenuItems('fleet-ops:component:map:drawer');
        return [
            this.#tab('vehicles', 'fleet-ops.ui.map.drawer.tab-vehicles', { icon: 'car', component: 'map/drawer/vehicle-listing' }),
            this.#tab('drivers', 'fleet-ops.ui.map.drawer.tab-drivers', { icon: 'id-card', component: 'map/drawer/driver-listing' }),
            this.#tab('places', 'fleet-ops.ui.map.drawer.tab-places', { icon: 'building', component: 'map/drawer/place-listing' }),
            this.#tab('positions', 'fleet-ops.ui.map.drawer.tab-positions', { icon: 'map-marker', component: 'map/drawer/position-listing' }),
            this.#tab('geofences', 'fleet-ops.ui.map.drawer.tab-geofences', { icon: 'map-pin', component: 'map/drawer/geofence-event-listing' }),
            this.#tab('events', 'fleet-ops.ui.map.drawer.tab-events', { icon: 'stream', component: 'map/drawer/device-event-listing' }),
            ...(isArray(registeredTabs) ? registeredTabs : []),
        ];
    }

    @action setDrawerContext(drawerContextApi) {
        this.mapDrawer.setDrawer(drawerContextApi, this);
    }
}
