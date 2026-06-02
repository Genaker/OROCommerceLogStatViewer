/* global document */
define([], function() {
    'use strict';

    const LEVELS = [
        {id: 'btn-level-debug', level: 'DEBUG', pattern: '.DEBUG:'},
        {id: 'btn-level-info', level: 'INFO', pattern: '.INFO:'},
        {id: 'btn-level-warning', level: 'WARNING', pattern: '.WARNING:'},
        {id: 'btn-level-error', level: 'ERROR', pattern: '.ERROR:'},
        {id: 'btn-level-critical', level: 'CRITICAL', pattern: '.CRITICAL:'}
    ];

    return {
        /**
         * Wires the log level quick-filter buttons.
         *
         * Each button populates the grep input with a Monolog-compatible pattern
         * (e.g. ".ERROR:") and triggers the existing server-side grep flow.
         * Clicking an already-active level clears the grep filter.
         * Active state is removed when the user manually edits the grep input
         * or clicks the grep Clear button.
         */
        initLevelFilter: function() {
            const filterGrep = document.getElementById('filter-grep');
            const btnGrepClear = document.getElementById('btn-grep-clear');
            const levelPatternValues = LEVELS.map(function(l) {
                return l.pattern;
            });

            function clearActiveLevel() {
                LEVELS.forEach(function(l) {
                    const btn = document.getElementById(l.id);
                    if (btn) {
                        btn.classList.remove('lv-level-active');
                    }
                });
            }

            LEVELS.forEach(function(lvl) {
                const btn = document.getElementById(lvl.id);
                if (!btn) {
                    return;
                }

                btn.addEventListener('click', function() {
                    const isActive = btn.classList.contains('lv-level-active');

                    if (isActive) {
                        document.getElementById('btn-grep-clear').click();
                        return;
                    }

                    clearActiveLevel();
                    btn.classList.add('lv-level-active');
                    filterGrep.value = lvl.pattern;
                    document.getElementById('btn-grep').click();
                });
            });

            if (btnGrepClear) {
                btnGrepClear.addEventListener('click', clearActiveLevel);
            }

            if (filterGrep) {
                filterGrep.addEventListener('input', function() {
                    if (levelPatternValues.indexOf(filterGrep.value) === -1) {
                        clearActiveLevel();
                    }
                });
            }
        }
    };
});
