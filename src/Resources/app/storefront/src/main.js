import CurbstoneInlinePaymentPlugin from './curbstone-plugin/curbstone-inline-payment.plugin';
const PluginManager = window.PluginManager;

PluginManager.register(
    'CurbstoneInlinePayment',
    CurbstoneInlinePaymentPlugin,
    '[data-curbstone-inline-payment="true"]'
);
