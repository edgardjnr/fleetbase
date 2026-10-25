/* eslint-env node */
'use strict';
const { name, fleetbase } = require('../package');

module.exports = function (environment) {
    let ENV = {
        modulePrefix: name,
        environment,
        mountedEngineRoutePrefix: getMountedEngineRoutePrefix(),

        defaultValues: {
            driverImage: getenv('DEFAULT_DRIVER_IMAGE', '/images/entregas/padrao/static/no-avatar.png'),
            userImage: getenv('DEFAULT_USER_IMAGE', '/images/entregas/padrao/static/no-avatar.png'),
            contactImage: getenv('DEFAULT_CONTACT_IMAGE', '/images/entregas/padrao/static/no-avatar.png'),
            vendorImage: getenv('DEFAULT_VENDOR_IMAGE', '/images/entregas/padrao/static/no-avatar.png'),
            vehicleImage: getenv('DEFAULT_VEHICLE_IMAGE', '/images/entregas/padrao/static/vehicle-placeholder.png'),
            entityImage: getenv('DEFAULT_ENTITY_IMAGE', '/images/entregas/padrao/static/parcels/medium.png'),
            vehicleAvatar: getenv('DEFAUL_VEHICLE_AVATAR', '/images/entregas/padrao/static/vehicle-icons/mini_bus.svg'),
            driverAvatar: getenv('DEFAUL_DRIVER_AVATAR', '/images/entregas/padrao/static/driver-icons/moto-driver.png'),
            placeAvatar: getenv('DEFAUL_PLACE_AVATAR', '/images/entregas/padrao/static/place-icons/basic-building.png'),
        },
    };

    return ENV;
};

function getMountedEngineRoutePrefix() {
    let mountedEngineRoutePrefix = 'fleet-ops';
    if (fleetbase && typeof fleetbase.route === 'string') {
        mountedEngineRoutePrefix = fleetbase.route;
    }

    return `console.${mountedEngineRoutePrefix}`;
}

function getenv(variable, defaultValue = null) {
    return process.env[variable] !== undefined ? process.env[variable] : defaultValue;
}
