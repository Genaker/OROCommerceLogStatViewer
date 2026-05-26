/* global document, fetch, FormData */
define([], function() {
    'use strict';

    return {
        /**
         * Wires the Exceptions panel button.
         * Clicking opens an inline panel showing aggregated exception groups
         * from the last 10 000 lines of the current log file.
         */
        initExceptionAggregator: function() {
            const self = this;
            const panel = document.getElementById('lv-exceptions-panel');
            const btn = document.getElementById('btn-exceptions');

            if (!panel || !btn) {
                return;
            }

            btn.addEventListener('click', function() {
                const isOpen = panel.style.display !== 'none';
                panel.style.display = isOpen ? 'none' : 'block';
                btn.classList.toggle('active', !isOpen);
                if (!isOpen) {
                    self._loadExceptions();
                }
            });
        },

        _loadExceptions: function() {
            const self = this;
            const body = document.querySelector('#lv-exceptions-panel .lv-exc-body');
            if (!body) {
                return;
            }

            body.innerHTML = '<span class="lv-exc-msg" style="padding:12px 14px;display:block;">' +
                'Scanning last 10 000 lines…</span>';

            const fd = new FormData();
            fd.append('fileName', this.options.fileName);
            fd.append('scanLines', '10000');

            fetch(this.options.exceptionsUrl, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': this.csrfToken},
                body: fd
            })
                .then(function(res) {
                    return res.json();
                })
                .then(function(groups) {
                    if (!Array.isArray(groups) || groups.length === 0) {
                        body.innerHTML = '<span class="lv-exc-empty">' +
                            'No exceptions detected in last 10 000 lines.</span>';
                        return;
                    }
                    body.innerHTML = groups.map(function(g) {
                        const isHigh = g.count >= 10;
                        const isMid = g.count >= 3;
                        const countCls = isHigh ? 'lv-exc-count-high' : (isMid ? 'lv-exc-count-mid' : '');
                        const msg = g.message.length > 90 ? g.message.substring(0, 90) + '\u2026' : g.message;
                        return '<div class="lv-exc-row">' +
                            '<span class="lv-exc-count ' + countCls + '" title="' +
                                g.count + ' occurrences">' + g.count + 'x</span>' +
                            '<span class="lv-exc-class">' + self._escHtml(g.class) + '</span>' +
                            '<span class="lv-exc-msg" title="' + self._escAttr(g.message) + '">' +
                                self._escHtml(msg) + '</span>' +
                            '<span class="lv-exc-time">First: ' + self._escHtml(g.firstSeen || '\u2014') + '</span>' +
                            '<span class="lv-exc-time">Last: ' + self._escHtml(g.lastSeen || '\u2014') + '</span>' +
                            '<button type="button" class="lv-copy-btn" data-copy="' +
                                self._escAttr(g.message) + '" title="Copy message">\u29c9</button>' +
                            '</div>';
                    }).join('');
                    self._bindCopyButtons(body);
                })
                .catch(function() {
                    body.innerHTML = '<span class="lv-exc-error">Failed to load exception data.</span>';
                });
        },

        _escHtml: function(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        },

        _escAttr: function(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        },

        /**
         * Attaches clipboard copy handlers to all .lv-copy-btn elements inside a container.
         *
         * @param {HTMLElement} container
         */
        _bindCopyButtons: function(container) {
            container.querySelectorAll('.lv-copy-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const text = btn.getAttribute('data-copy');
                    navigator.clipboard.writeText(text).then(function() {
                        btn.textContent = '\u2713';
                        setTimeout(function() {
                            btn.textContent = '\u29c9';
                        }, 1500);
                    }).catch(function() {});
                });
            });
        },

        /**
         * Wires the Unique Entries panel button.
         * Groups every repeated log line (timestamp stripped) and shows
         * each unique message once with its occurrence count.
         */
        initUniqueEntries: function() {
            const self = this;
            const panel = document.getElementById('lv-unique-panel');
            const btn = document.getElementById('btn-unique-entries');

            if (!panel || !btn) {
                return;
            }

            btn.addEventListener('click', function() {
                const isOpen = panel.style.display !== 'none';
                panel.style.display = isOpen ? 'none' : 'block';
                btn.classList.toggle('active', !isOpen);
                if (!isOpen) {
                    self._loadUniqueEntries();
                }
            });
        },

        _loadUniqueEntries: function() {
            const self = this;
            const body = document.getElementById('lv-unique-body');
            if (!body) {
                return;
            }

            body.innerHTML = '<span class="lv-exc-msg" style="padding:12px 14px;display:block;">' +
                'Scanning last 10 000 lines…</span>';

            const fd = new FormData();
            fd.append('fileName', this.options.fileName);
            fd.append('scanLines', '10000');

            fetch(this.options.uniqueEntriesUrl, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': this.csrfToken},
                body: fd
            })
                .then(function(res) {
                    return res.json();
                })
                .then(function(entries) {
                    if (!Array.isArray(entries) || entries.length === 0) {
                        body.innerHTML = '<span class="lv-exc-empty">' +
                            'No repeated log entries detected in last 10 000 lines.</span>';
                        return;
                    }
                    body.innerHTML = entries.map(function(e) {
                        const isHigh = e.count >= 10;
                        const isMid = e.count >= 3;
                        const countCls = isHigh
                            ? 'lv-exc-count-high'
                            : (isMid ? 'lv-exc-count-mid' : '');
                        const levelCls = e.level === 'ERROR' || e.level === 'CRITICAL'
                            ? 'lv-exc-level-error'
                            : (e.level === 'WARNING' ? 'lv-exc-level-warn' : 'lv-exc-level-info');
                        const msg = e.message.length > 120
                            ? e.message.substring(0, 120) + '\u2026'
                            : e.message;
                        const countSpan = '<span class="lv-exc-count ' + countCls +
                            '" title="' + e.count + ' occurrences">' + e.count + 'x</span>';
                        const levelSpan = e.level
                            ? '<span class="lv-exc-class ' + levelCls + '">' +
                                self._escHtml(e.level) + '</span>'
                            : '';
                        const msgSpan = '<span class="lv-exc-msg" title="' +
                            self._escAttr(e.message) + '">' + self._escHtml(msg) + '</span>';
                        const timeFirst = '<span class="lv-exc-time">First: ' +
                            self._escHtml(e.firstSeen || '\u2014') + '</span>';
                        const timeLast = '<span class="lv-exc-time">Last: ' +
                            self._escHtml(e.lastSeen || '\u2014') + '</span>';
                        const copyBtn = '<button type="button" class="lv-copy-btn" data-copy="' +
                            self._escAttr(e.message) + '" title="Copy message">\u29c9</button>';
                        return '<div class="lv-exc-row">' +
                            countSpan + levelSpan + msgSpan + timeFirst + timeLast + copyBtn +
                            '</div>';
                    }).join('');
                    self._bindCopyButtons(body);
                })
                .catch(function() {
                    body.innerHTML = '<span class="lv-exc-error">Failed to load unique entries.</span>';
                });
        }
    };
});
