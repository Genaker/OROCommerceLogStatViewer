define([], function() {
    'use strict';

    return {
        /**
         * Wires light/dark theme toggle and word-wrap toggle.
         * Both preferences are persisted to localStorage.
         */
        initThemeWrap: function() {
            const shell = this.shell;
            const textarea = this.textarea;
            const btnTerm = document.getElementById('btn-terminal');
            const btnWrap = document.getElementById('btn-wrap');

            btnTerm.addEventListener('click', function() {
                const isLight = shell.classList.toggle('light-mode');
                btnTerm.classList.toggle('active', isLight);
                btnTerm.textContent = isLight ? '\u263d Dark' : '\u2638 Light';
                localStorage.setItem('logViewer.terminalMode', isLight ? '1' : '0');
            });

            btnWrap.addEventListener('click', function() {
                const isWrap = textarea.style.whiteSpace === 'pre-wrap';
                textarea.style.whiteSpace = isWrap ? 'pre' : 'pre-wrap';
                localStorage.setItem('logViewer.wrapMode', isWrap ? '0' : '1');
            });

            // Light mode is the default; only suppress it when the user has
            // explicitly chosen dark (stored as '0').
            if (localStorage.getItem('logViewer.terminalMode') !== '0') {
                shell.classList.add('light-mode');
                btnTerm.classList.add('active');
                btnTerm.textContent = '\u263d Dark';
            }

            if (localStorage.getItem('logViewer.wrapMode') === '1') {
                textarea.style.whiteSpace = 'pre-wrap';
            }
        }
    };
});
