define(function(require) {
    'use strict';

    const $ = require('jquery');
    const routing = require('routing');
    const __ = require('orotranslation/js/translator');
    const messenger = require('oroui/js/messenger');
    const BaseComponent = require('oroui/js/app/components/base/component');

    /**
     * Adds a "Test Connection" button next to a System Configuration field
     * (OSRM Base URL / Google API Key) that posts the field's current value
     * to genaker_comivoyager_test_connection and shows the result via the
     * standard flash messenger.
     */
    const TestConnectionComponent = BaseComponent.extend({
        /**
         * @param {Object} options
         * @param {jQuery} options._sourceElement
         * @param {string} options.provider - 'osrm' or 'google'
         * @param {string} [options.buttonLabel]
         */
        initialize: function(options) {
            TestConnectionComponent.__super__.initialize.call(this, options);

            this.provider = options.provider;
            this.$field = options._sourceElement;

            this.$button = $('<button>', {
                type: 'button',
                class: 'btn test-connection-button',
                text: __(options.buttonLabel || 'genaker.comivoyager.system_configuration.test_connection.button')
            });

            this.$field.after(this.$button);
            this.$button.on('click', this.onTestConnectionClick.bind(this));
        },

        onTestConnectionClick: function() {
            if (this.$button.is(':disabled')) {
                return;
            }

            const value = String(this.$field.val() || '');
            const originalLabel = this.$button.text();

            this.$button.prop('disabled', true)
                .text(__('genaker.comivoyager.system_configuration.test_connection.testing'));

            $.ajax({
                url: routing.generate('genaker_comivoyager_test_connection', {provider: this.provider}),
                method: 'POST',
                data: {value: value},
                success: response => {
                    messenger.notificationFlashMessage(
                        response && response.success ? 'success' : 'error',
                        response && response.message
                            ? response.message
                            : __('genaker.comivoyager.system_configuration.test_connection.error')
                    );
                },
                error: () => {
                    messenger.notificationFlashMessage(
                        'error',
                        __('genaker.comivoyager.system_configuration.test_connection.error')
                    );
                },
                complete: () => {
                    this.$button.prop('disabled', false).text(originalLabel);
                }
            });
        },

        dispose: function() {
            if (this.disposed) {
                return;
            }

            this.$button.remove();

            TestConnectionComponent.__super__.dispose.call(this);
        }
    });

    return TestConnectionComponent;
});
