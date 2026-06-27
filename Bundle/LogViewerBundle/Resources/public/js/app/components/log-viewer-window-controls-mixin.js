define([], function() {
    'use strict';

    return {
        /**
         * Wires the macOS traffic-dot controls.
         *   Yellow dot — minimize (collapse shell to titlebar only) / restore
         *   Green dot  — fullscreen (fixed viewport) / restore
         * Exits the opposite state if active before toggling.
         */
        initWindowControls: function() {
            const shell = this.shell;

            document.getElementById('btn-minimize').addEventListener('click', function() {
                if (shell.classList.contains('lv-fullscreen')) {
                    shell.classList.remove('lv-fullscreen');
                } else {
                    shell.classList.toggle('lv-minimized');
                }
            });

            document.getElementById('btn-fullscreen').addEventListener('click', function() {
                if (shell.classList.contains('lv-minimized')) {
                    shell.classList.remove('lv-minimized');
                } else {
                    shell.classList.toggle('lv-fullscreen');
                }
            });
        }
    };
});
