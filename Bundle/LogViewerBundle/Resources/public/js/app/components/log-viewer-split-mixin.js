/* global document, fetch, FormData, clearInterval, setInterval */
define([], function() {
    'use strict';

    return {
        _splitTimer: null,
        _splitOffset: 0,
        _splitPaused: false,
        _splitFileName: '',

        /**
         * Wires the Split View button.
         * Opens a second pane beside the primary log pane; the user picks a
         * file from a dropdown and the pane auto-tails it via the existing
         * live-update and reload endpoints.
         */
        initSplitView: function() {
            const self = this;
            const btn = document.getElementById('btn-split-view');
            if (!btn) {
                return;
            }

            btn.addEventListener('click', function() {
                if (document.getElementById('lv-split-pane')) {
                    self._closeSplit();
                } else {
                    self._openSplit();
                }
            });
        },

        _openSplit: function() {
            const self = this;
            const container = document.getElementById('lv-panes-container');
            if (!container || document.getElementById('lv-split-pane')) {
                return;
            }

            const pane = document.createElement('div');
            pane.id = 'lv-split-pane';
            pane.className = 'lv-split-pane';
            pane.innerHTML = self._buildSplitHtml();
            container.appendChild(pane);

            document.getElementById('log-viewer-wrap').classList.add('lv-split-active');

            const btn = document.getElementById('btn-split-view');
            if (btn) {
                btn.classList.add('active');
                btn.textContent = '\u22a1 Close Split';
            }

            pane.querySelector('#split-file-select').addEventListener('change', function() {
                const fname = this.value;
                if (fname) {
                    self._loadSplitFile(fname);
                }
            });

            pane.querySelector('#btn-split-close').addEventListener('click', function() {
                self._closeSplit();
            });

            pane.querySelector('#btn-split-reload').addEventListener('click', function() {
                if (self._splitFileName) {
                    self._loadSplitFile(self._splitFileName);
                }
            });

            pane.querySelector('#btn-split-pause').addEventListener('click', function() {
                self._splitPaused = !self._splitPaused;
                this.textContent = self._splitPaused ? '\u25b6 Resume' : '\u23f8 Pause';
                this.classList.toggle('lv-btn-warn', self._splitPaused);

                const indicator = document.getElementById('split-live-indicator');
                if (indicator) {
                    indicator.className = self._splitPaused ? 'live-idle' : '';
                    indicator.textContent = self._splitPaused ? '\u25a0 Paused' : '\u25cb waiting\u2026';
                }
            });
        },

        _buildSplitHtml: function() {
            const self = this;
            const files = (this.options.logFiles || []);
            const optsHtml = files.map(function(f) {
                return '<option value="' + self._escAttr(f.file_name) + '">' +
                    self._escHtml(f.file_name) + ' (' + self._escHtml(f.size) + ')</option>';
            }).join('');

            return '<div class="lv-split-header">' +
                '<span class="lv-label">File</span>' +
                '<select id="split-file-select" class="lv-input" style="min-width:180px;max-width:260px;">' +
                '<option value="">\u2014 select file \u2014</option>' + optsHtml +
                '</select>' +
                '<span id="split-live-indicator" style="font-size:11px;color:#5a6275;">\u25cb waiting\u2026</span>' +
                '<button type="button" class="lv-btn" id="btn-split-reload" disabled>\u21bb</button>' +
                '<button type="button" class="lv-btn" id="btn-split-pause" disabled>\u23f8 Pause</button>' +
                '<button type="button" class="lv-btn lv-btn-danger" id="btn-split-close">\u2715 Close</button>' +
                '</div>' +
                '<div class="lv-content-wrap" style="padding:0 0 0 0;">' +
                '<textarea id="log-content-split" class="lv-split-textarea" readonly>' +
                'Select a file above to begin live tail…</textarea>' +
                '</div>' +
                '<div id="split-stats" class="lv-split-stats"></div>';
        },

        _loadSplitFile: function(fileName) {
            const self = this;
            self._splitFileName = fileName;

            if (self._splitTimer) {
                clearInterval(self._splitTimer);
                self._splitTimer = null;
            }

            const indicator = document.getElementById('split-live-indicator');
            if (indicator) {
                indicator.className = '';
                indicator.textContent = '\u21bb loading\u2026';
            }

            const reloadBtn = document.getElementById('btn-split-reload');
            const pauseBtn = document.getElementById('btn-split-pause');
            if (reloadBtn) {
                reloadBtn.disabled = false;
            }
            if (pauseBtn) {
                pauseBtn.disabled = false;
            }

            const fd = new FormData();
            fd.append('fileName', fileName);
            fd.append('lines', '100');

            fetch(self.options.reloadUrl, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': self.csrfToken},
                body: fd
            })
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    const ta = document.getElementById('log-content-split');
                    if (ta) {
                        ta.value = data.content || '';
                        ta.scrollTop = ta.scrollHeight;
                    }
                    self._splitOffset = data.offset || 0;
                    self._setSplitStats(data);

                    if (indicator) {
                        indicator.textContent = '\u25cf live';
                        indicator.style.color = '#3fb950';
                    }

                    self._startSplitPoll();
                })
                .catch(function() {
                    const ta = document.getElementById('log-content-split');
                    if (ta) {
                        ta.value = 'Error loading file.';
                    }
                    if (indicator) {
                        indicator.textContent = '\u2717 error';
                        indicator.style.color = '#ff7b75';
                    }
                });
        },

        _startSplitPoll: function() {
            const self = this;
            self._splitTimer = setInterval(function() {
                if (self._splitPaused || !self._splitFileName) {
                    return;
                }
                if (typeof document.hidden !== 'undefined' && document.hidden) {
                    return;
                }
                self._pollSplitUpdate();
            }, 10000);
        },

        _pollSplitUpdate: function() {
            const self = this;
            const indicator = document.getElementById('split-live-indicator');

            const fd = new FormData();
            fd.append('fileName', self._splitFileName);
            fd.append('offset', self._splitOffset);

            fetch(self.options.liveUrl, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': self.csrfToken},
                body: fd
            })
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    if (data.newContent && data.newContent.length > 0) {
                        const ta = document.getElementById('log-content-split');
                        if (ta) {
                            const atBottom = ta.scrollHeight - ta.scrollTop - ta.clientHeight < 30;
                            ta.value += data.newContent;
                            if (atBottom) {
                                ta.scrollTop = ta.scrollHeight;
                            }
                        }
                        self._splitOffset = data.newOffset;

                        if (indicator) {
                            indicator.style.color = '#3fb950';
                            indicator.textContent = '\u25cf ' + new Date().toLocaleTimeString();
                        }
                    } else {
                        if (indicator) {
                            indicator.style.color = '#5a6275';
                            indicator.textContent = '\u25cb idle';
                        }
                    }
                })
                .catch(function() {
                    if (indicator) {
                        indicator.style.color = '#ff7b75';
                        indicator.textContent = '\u2717 err';
                    }
                });
        },

        _setSplitStats: function(data) {
            const el = document.getElementById('split-stats');
            if (!el) {
                return;
            }
            const parts = [];
            if (data.fileSize) {
                parts.push(data.fileSize);
            }
            if (data.lineCount) {
                parts.push(data.lineCount + ' lines');
            }
            if (data.readMs !== undefined) {
                parts.push(data.readMs + ' ms');
            }
            if (data.loadedAt) {
                parts.push('loaded ' + data.loadedAt);
            }
            el.textContent = parts.join(' \u00b7 ');
        },

        _closeSplit: function() {
            if (this._splitTimer) {
                clearInterval(this._splitTimer);
                this._splitTimer = null;
            }
            this._splitFileName = '';
            this._splitPaused = false;
            this._splitOffset = 0;

            const pane = document.getElementById('lv-split-pane');
            if (pane) {
                pane.remove();
            }

            document.getElementById('log-viewer-wrap').classList.remove('lv-split-active');

            const btn = document.getElementById('btn-split-view');
            if (btn) {
                btn.classList.remove('active');
                btn.textContent = '\u22a1 Split';
            }
        }
    };
});
