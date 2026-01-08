export default class CurbstonePaymentPlugin extends window.PluginBaseClass {
    static options = {
        confirmFormId: 'confirmOrderForm',
        parentWrapperId: 'curbstone-payment',
        hiddenInputId: 'paymentUrl',
    };

    init() {
        this._registerElements();
        this._registerEvents();
    }

    _registerElements() {
        this.confirmOrderForm = document.getElementById(this.options.confirmFormId);
        this.parentWrapper = document.getElementById(this.options.parentWrapperId);
        this.hiddenInput = document.getElementById(this.options.hiddenInputId);

        this.token = this.parentWrapper?.getAttribute('data-token') ?? '';
    }

    _registerEvents() {
        if (!this.confirmOrderForm) {
            return;
        }

        this.confirmOrderForm.addEventListener('submit', () => {
            if (this.hiddenInput) {
                this.hiddenInput.value = this.token;
            }
        });
    }
}
