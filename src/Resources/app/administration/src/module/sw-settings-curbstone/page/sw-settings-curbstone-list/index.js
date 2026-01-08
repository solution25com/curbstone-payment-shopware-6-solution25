import template from './sw-settings-credova-list.html.twig';
import '../../components/sw-settings-curbstone-form/index';
import './sw-settings-curbstone-list.scss';

const {Component, Mixin} = Shopware;

Component.register('sw-settings-curbstone-list', {
    template,
    mixins: [Mixin.getByName('notification')],
    data() {
        return {isSaving: false};
    },

    methods: {
        async onSave() {
            this.isSaving = true;
            try {
                await this.$refs.form.saveConfig();
            } finally {
                this.isSaving = false;
            }
        },
    }
});
