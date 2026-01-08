import template from "./sw-settings-curbstone-form.html.twig";
import "./sw-settings-curbstone-form.scss";

const { Component, Mixin } = Shopware;

Component.register("sw-settings-curbstone-form", {
    template,
    inject: ["systemConfigApiService"],
    mixins: [Mixin.getByName("notification")],

    data() {
        return {
            isLoading: false,
            salesChannelId: null,
            form: {
                "Curbstone.config.enabled": false,
                "Curbstone.config.sandbox": true,
                "Curbstone.config.dsiKey": "",
                "Curbstone.config.customerId": "",
                "Curbstone.config.merchantCode": "",
                "Curbstone.config.authCaptureFlow": "auth_only",
                "Curbstone.config.plpMode": "embedded",
                "Curbstone.config.requireCaptcha": true,
                "Curbstone.config.checkoutIntegration": "plp",
            }
        };
    },

    computed: {
        configDomain() {
            return "Curbstone.config";
        },
        authFlowOptions() {
            return [
                { value: "auth_only",    label: "Auth only" },
                { value: "auth_capture", label: "Auth & Capture" }
            ];
        },
        plpModeOptions() {
            return [
                { value: "embedded", label: "Embedded (iFrame)" },
                { value: "redirect", label: "Redirect (full page)" }
            ];
            },
            checkoutIntegrationOptions(){
                return[
                    { value: "plp", label: "PLP" },
                    { value: "dsi", label: "DSI" }
                ];
            }
    },

    created() {
        this.salesChannelId = null;
        this.loadConfig();
    },

    methods: {
        async onSalesChannelChanged(salesChannelId) {
            this.salesChannelId = salesChannelId || null;
            await this.loadConfig();
        },

        async loadConfig() {
            this.isLoading = true;
            try {
                const values = await this.systemConfigApiService.getValues(
                    this.configDomain,
                    this.salesChannelId
                );

                this.form["Curbstone.config.enabled"] =
                    values["Curbstone.config.enabled"] ?? false;

                this.form["Curbstone.config.sandbox"] =
                    values["Curbstone.config.sandbox"] ?? true;

                this.form["Curbstone.config.dsiKey"] =
                    values["Curbstone.config.dsiKey"] ?? "";

                this.form["Curbstone.config.customerId"] =
                    values["Curbstone.config.customerId"] ?? "";

                this.form["Curbstone.config.merchantCode"] =
                    values["Curbstone.config.merchantCode"] ?? "";

                this.form["Curbstone.config.authCaptureFlow"] =
                    values["Curbstone.config.authCaptureFlow"] ?? "auth_only";

                this.form["Curbstone.config.plpMode"] =
                    values["Curbstone.config.plpMode"] ?? "embedded";

                this.form["Curbstone.config.requireCaptcha"] =
                    values["Curbstone.config.requireCaptcha"] ?? true;
                this.form["Curbstone.config.checkoutIntegration"] =
                    values["Curbstone.config.checkoutIntegration"] ?? "plp";

            } catch (e) {
                this.createNotificationError({
                    title: "Curbstone",
                    message: "Failed to load configuration."
                });
                throw e;

            } finally {
                this.isLoading = false;
            }
        },

        async saveConfig() {
            if (this.isLoading) return false;
            this.isLoading = true;
            try {
                await this.systemConfigApiService.saveValues(
                    {
                        "Curbstone.config.enabled": this.form["Curbstone.config.enabled"],
                        "Curbstone.config.sandbox": this.form["Curbstone.config.sandbox"],
                        "Curbstone.config.dsiKey": this.form["Curbstone.config.dsiKey"],
                        "Curbstone.config.customerId": this.form["Curbstone.config.customerId"],
                        "Curbstone.config.merchantCode": this.form["Curbstone.config.merchantCode"],
                        "Curbstone.config.authCaptureFlow": this.form["Curbstone.config.authCaptureFlow"],
                        "Curbstone.config.plpMode": this.form["Curbstone.config.plpMode"],
                        "Curbstone.config.requireCaptcha": this.form["Curbstone.config.requireCaptcha"],
                        "Curbstone.config.checkoutIntegration": this.form["Curbstone.config.checkoutIntegration"]
                    },
                    this.salesChannelId
                );

                this.createNotificationSuccess({
                    title: "Curbstone",
                    message: "Configuration saved."
                });
                return true;
            } catch (e) {
                this.createNotificationError({
                    title: "Curbstone",
                    message: "Failed to save configuration."
                });
                throw e;
            } finally {
                this.isLoading = false;
            }
        }
    }
});
