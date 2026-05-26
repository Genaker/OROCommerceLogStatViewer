define([], function() {
    'use strict';

    /** Milliseconds before an in-flight poll/reload request is aborted. */
    const FETCH_TIMEOUT_MS = 25000;

    /**
     * Returns an AbortSignal that fires after `ms` milliseconds.
     * Falls back gracefully when AbortController is unavailable.
     *
     * @param {number} ms
     * @returns {{signal: AbortSignal|undefined, abort: function}}
     */
    function makeTimeoutSignal(ms) { // NOSONAR - S7721: AMD architectural pattern
        if (typeof AbortController === 'undefined') {
            return {signal: undefined, abort: function() {}};
        }
        const ctrl = new AbortController();
        const timer = setTimeout(function() {
            ctrl.abort();
        }, ms);
        return {
            signal: ctrl.signal,
            abort: function() {
                clearTimeout(timer);
                ctrl.abort();
            }
        };
    }

    return {
        /**
         * Wires live-tail polling (10 s interval), pause/resume, AJAX reload,
         * clear mode, and the max-rows auto-flush buffer.
         *
         * Polling is paused by default; click Resume/Pause to toggle live mode.
         * A new poll is never sent while a previous one is still in-flight.
         * All fetch calls are aborted after FETCH_TIMEOUT_MS milliseconds.
         */
        initLiveTail: function() {
            const self = this;
            const textarea = this.textarea;
            const liveIndicator = document.getElementById('live-indicator');
            const statLastUpdate = document.getElementById('stat-last-update');
            const statBytes = document.getElementById('stat-bytes');
            const statLines = document.getElementById('stat-lines');
            const btnClearMode = document.getElementById('btn-clear-mode');
            const btnPause = document.getElementById('btn-pause');
            const btnReload = document.getElementById('btn-reload');
            const maxRowsInput = document.getElementById('max-rows');
            const linesCount = document.getElementById('lines-count');
            let totalBytes = 0;
            let pollInFlight = false;

            // Auto-update is paused by default — user must click Resume to start.
            let livePaused = true;

            // ── Clear mode ───────────────────────────────────────────────
            let clearModeActive = localStorage.getItem('logViewer.clearMode') === '1';

            function syncClearModeBtn() {
                btnClearMode.classList.toggle('active', clearModeActive);
            }
            syncClearModeBtn();

            btnClearMode.addEventListener('click', function() {
                clearModeActive = !clearModeActive;
                localStorage.setItem('logViewer.clearMode', clearModeActive ? '1' : '0');
                syncClearModeBtn();
            });

            // ── Pause / resume ───────────────────────────────────────────
            function syncPauseBtn() {
                if (livePaused) {
                    btnPause.innerHTML = '&#9654; Resume';
                    btnPause.classList.add('lv-btn-danger');
                    liveIndicator.className = 'live-idle';
                    liveIndicator.textContent = '\u25a0 Paused';
                } else {
                    btnPause.innerHTML = '&#9646;&#9646; Pause';
                    btnPause.classList.remove('lv-btn-danger');
                }
            }

            // Reflect initial paused state in the UI.
            syncPauseBtn();

            btnPause.addEventListener('click', function() {
                livePaused = !livePaused;
                syncPauseBtn();
            });

            // ── AJAX reload ──────────────────────────────────────────────
            btnReload.addEventListener('click', function() {
                const n = linesCount.value;
                const fd = new FormData();
                fd.append('fileName', self.options.fileName);
                fd.append('lines', n);

                btnReload.textContent = '\u29d6 Loading\u2026';
                btnReload.disabled = true;

                const reloadSignal = makeTimeoutSignal(FETCH_TIMEOUT_MS);

                fetch(self.options.reloadUrl, {
                    method: 'POST',
                    body: fd,
                    signal: reloadSignal.signal,
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': self.csrfToken}
                })
                    .then(function(r) {
                        reloadSignal.abort();
                        return r.json();
                    })
                    .then(function(data) {
                        self.originalLines = data.content.split('\n');
                        textarea.value = data.content;
                        self.logOffset = data.offset;
                        textarea.scrollTop = textarea.scrollHeight;
                        if (statLines) {
                            statLines.textContent = data.lineCount;
                        }
                        if (statLastUpdate) {
                            statLastUpdate.textContent = data.loadedAt;
                        }
                        liveIndicator.className = 'live-ok';
                        liveIndicator.textContent = '\u25cf Reloaded ' + data.loadedAt;
                    })
                    .catch(function(err) {
                        const msg = err.name === 'AbortError' ? 'Reload timed out' : err.message;
                        liveIndicator.className = 'live-err';
                        liveIndicator.textContent = '\u25cf Reload error: ' + msg;
                    })
                    .finally(function() {
                        btnReload.innerHTML = '&#8635; Reload';
                        btnReload.disabled = false;
                    });
            });

            // ── Max rows — trim immediately on change ────────────────────
            maxRowsInput.addEventListener('change', function() {
                const maxRows = parseInt(maxRowsInput.value, 10) || 20000;
                if (self.originalLines.length > maxRows) {
                    self.originalLines = self.originalLines.slice(-maxRows);
                    if (!self._filterJsInput || !self._filterJsInput.value.trim()) {
                        textarea.value = self.originalLines.join('\n');
                        textarea.scrollTop = textarea.scrollHeight;
                    }
                    if (statLines) {
                        statLines.textContent = self.originalLines.length;
                    }
                }
            });

            // ── Buffer helpers ───────────────────────────────────────────
            function flushToNewContent(newContent) {
                self.originalLines = newContent.split('\n');
                textarea.value = newContent;
            }

            function appendContent(newContent) {
                const maxRows = parseInt(maxRowsInput.value, 10) || 20000;
                const newLines = newContent.split('\n');
                self.originalLines = self.originalLines.concat(newLines);
                if (self.originalLines.length > maxRows) {
                    self.originalLines = self.originalLines.slice(-maxRows);
                    if (!self._filterJsInput || !self._filterJsInput.value.trim()) {
                        textarea.value = self.originalLines.join('\n');
                    }
                } else if (!self._filterJsInput || !self._filterJsInput.value.trim()) {
                    textarea.value += newContent;
                }
            }

            function formatBytes(n) {
                if (n >= 1048576) {
                    return (n / 1048576).toFixed(1) + ' MB';
                }
                if (n >= 1024) {
                    return (n / 1024).toFixed(1) + ' KB';
                }
                return n + ' B';
            }

            // ── Polling interval ─────────────────────────────────────────
            setInterval(function() {
                if (livePaused || self.grepActive) {
                    return;
                }

                if (document.visibilityState === 'hidden') {
                    liveIndicator.className = 'live-sleep';
                    liveIndicator.textContent = '\u25cc Sleeping';
                    return;
                }

                // Skip this tick if the previous request has not completed yet.
                if (pollInFlight) {
                    liveIndicator.className = 'live-sleep';
                    liveIndicator.textContent = '\u25cc Waiting\u2026';
                    return;
                }

                const fd = new FormData();
                fd.append('fileName', self.options.fileName);
                fd.append('offset', String(self.logOffset));

                const pollSignal = makeTimeoutSignal(FETCH_TIMEOUT_MS);
                pollInFlight = true;

                fetch(self.options.liveUrl, {
                    method: 'POST',
                    body: fd,
                    signal: pollSignal.signal,
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': self.csrfToken}
                })
                    .then(function(r) {
                        pollSignal.abort();
                        if (!r.ok) {
                            throw new Error('HTTP ' + r.status);
                        }
                        return r.json();
                    })
                    .then(function(data) {
                        const now = new Date().toLocaleTimeString();
                        if (data.newContent) {
                            if (clearModeActive) {
                                flushToNewContent(data.newContent);
                            } else {
                                appendContent(data.newContent);
                            }
                            textarea.scrollTop = textarea.scrollHeight;
                            self.logOffset = data.newOffset;
                            totalBytes += data.newContent.length;
                            if (statLastUpdate) {
                                statLastUpdate.textContent = now;
                            }
                            if (statLines) {
                                statLines.textContent = self.originalLines.length;
                            }
                            if (statBytes) {
                                statBytes.textContent = formatBytes(totalBytes);
                            }
                            liveIndicator.className = 'live-ok';
                            liveIndicator.textContent = '\u25cf Updated ' + now;
                        } else {
                            liveIndicator.className = 'live-sleep';
                            liveIndicator.textContent = '\u25cb ' + now;
                        }
                    })
                    .catch(function(err) {
                        const msg = err.name === 'AbortError' ? 'Poll timed out' : err.message;
                        liveIndicator.className = 'live-err';
                        liveIndicator.textContent = '\u25cf Error: ' + msg;
                    })
                    .finally(function() {
                        pollInFlight = false;
                    });
            }, 10000);
        }
    };
});
