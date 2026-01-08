import './page/sw-settings-curbstone-list'
const {Module} = Shopware

Module.register('sw-settings-curbstone', {
    type: 'plugin',
    name: 'Curbstone',
    description: 'Curbstone',
    version: '1.0.0',
    targetVersion: '1.0.0',
    icon: 'regular-cog',
    routes: {
        index: {
            component: 'sw-settings-curbstone-list',
            path: 'index',
        }
    },
    settingsItem: {
        group: 'curbstone',
        to: 'sw.settings.curbstone.index',
        icon: 'regular-credit-card',
        label: 'curbstone'
    }
});