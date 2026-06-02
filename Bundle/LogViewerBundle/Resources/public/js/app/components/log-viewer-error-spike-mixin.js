/* global document */
define([], function() {
    'use strict';

    const SPIKE_THRESHOLD = 20;
    const SCAN_INTERVAL_MS = 15000;
    const SCAN_LINES = 500;
    const LEVEL_RE = /\.(ERROR|CRITICAL):/;

    return {
        /**
         * Starts a periodic scan of the last N lines in the textarea.
         * When ERROR+CRITICAL count exceeds the threshold a pulsing badge
         * is shown next to the Exceptions button. Clears when count drops.
         */
        initErrorSpikeAlert: function() {
            const self = this;

            self._spikeScan();

            self._spikeTimer = setInterval(function() {
                self._spikeScan();
            }, SCAN_INTERVAL_MS);
        },

        _spikeScan: function() { // NOSONAR - S4144: different concern from _updateLevelCounts
            const badge = document.getElementById('lv-spike-badge');

            if (!badge || !this.textarea) {
                return;
            }

            const lines = this.textarea.value.split('\n');
            const tail = lines.slice(-SCAN_LINES);
            let count = 0;

            tail.forEach(function(line) {
                if (LEVEL_RE.test(line)) {
                    count++;
                }
            });

            if (count >= SPIKE_THRESHOLD) {
                badge.textContent = '\u26a0 ' + count + ' errors in last ' + SCAN_LINES + ' lines';
                badge.style.display = 'inline-flex';
            } else {
                badge.style.display = 'none';
            }
        }
    };
});
