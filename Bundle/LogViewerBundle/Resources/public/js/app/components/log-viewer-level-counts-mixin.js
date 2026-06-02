/* global document */
define([], function() {
    'use strict';

    const SCAN_INTERVAL_MS = 15000;
    const ERROR_RE = /\.(ERROR|CRITICAL):/g;
    const WARN_RE = /\.WARNING:/g;
    const INFO_RE = /\.INFO:/g;

    return {
        /**
         * Shows live ERROR / WARNING / INFO counts as coloured pills in the
         * title bar. Refreshed every 15 s and immediately on initialisation.
         */
        initLevelCounts: function() {
            const self = this;

            self._updateLevelCounts();

            setInterval(function() {
                self._updateLevelCounts();
            }, SCAN_INTERVAL_MS);
        },

        _updateLevelCounts: function() {
            if (!this.textarea) {
                return;
            }

            const text = this.textarea.value;
            const errorCount = (text.match(ERROR_RE) || []).length;
            const warnCount = (text.match(WARN_RE) || []).length;
            const infoCount = (text.match(INFO_RE) || []).length;

            const elError = document.getElementById('lv-count-error');
            const elWarn = document.getElementById('lv-count-warn');
            const elInfo = document.getElementById('lv-count-info');

            if (elError) {
                elError.textContent = errorCount;
                elError.style.display = errorCount > 0 ? 'inline-flex' : 'none';
            }

            if (elWarn) {
                elWarn.textContent = warnCount;
                elWarn.style.display = warnCount > 0 ? 'inline-flex' : 'none';
            }

            if (elInfo) {
                elInfo.textContent = infoCount;
                elInfo.style.display = infoCount > 0 ? 'inline-flex' : 'none';
            }
        }
    };
});
