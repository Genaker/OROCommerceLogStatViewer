define([], function() {
    'use strict';

    return {
        /**
         * Wires the instant client-side JS filter.
         * Filters originalLines in-memory — no server request.
         * Exposes this._applyJsFilter() for other mixins to call after content changes.
         */
        initJsFilter: function() {
            const self = this;
            const filterJs = document.getElementById('filter-js');
            const btnFilterClear = document.getElementById('btn-filter-clear');
            const matchCount = document.getElementById('filter-match-count');
            const textarea = this.textarea;

            function applyFilter() {
                const term = filterJs.value.trim();
                btnFilterClear.style.display = term ? '' : 'none';

                if (!term) {
                    textarea.value = self.originalLines.join('\n');
                    matchCount.textContent = '';
                    return;
                }

                const lower = term.toLowerCase();
                const matched = self.originalLines.filter(function(line) {
                    return line.toLowerCase().indexOf(lower) !== -1;
                });

                textarea.value = matched.join('\n');
                matchCount.textContent = matched.length + ' match' + (matched.length !== 1 ? 'es' : '');
                textarea.scrollTop = textarea.scrollHeight;
            }

            filterJs.addEventListener('input', applyFilter);

            btnFilterClear.addEventListener('click', function() {
                filterJs.value = '';
                applyFilter();
            });

            this._applyJsFilter = applyFilter;
            this._filterJsInput = filterJs;
        }
    };
});
