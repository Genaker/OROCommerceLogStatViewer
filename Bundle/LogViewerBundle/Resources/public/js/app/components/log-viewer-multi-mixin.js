/* global document, globalThis */
define([], function() {
    'use strict';

    const PALETTE = [
        '#58a6ff', '#3fb950', '#f0883e', '#ff7b72',
        '#d2a8ff', '#79c0ff', '#56d364', '#ffa657',
        '#f778ba', '#a5d6ff'
    ];

    const MAX_VISIBLE_ROWS = 1000;
    const POLL_INTERVAL_MS = 10000;

    return {
        /**
         * Initialise the "Multi-tail / Multi-grep" panel.
         * Wires the toolbar button and builds the picker panel on first open.
         */
        initMultiView: function() {
            const self = this;
            const btn = document.getElementById('btn-multi-view');
            if (!btn) {
                return;
            }
            this._multiPanelOpen = false;
            this._multiPollTimer = null;
            this._multiFileOffsets = {};
            this._multiRowCount = 0;

            btn.addEventListener('click', function() {
                if (self._multiPanelOpen) {
                    self._closeMultiPanel();
                } else {
                    self._openMultiPanel();
                }
            });
        },

        _openMultiPanel: function() {
            const panel = document.getElementById('lv-multi-panel');
            if (!panel) {
                return;
            }
            const self = this;
            if (!panel._mvBuilt) {
                panel.innerHTML = self._buildMultiPanelHtml(self.options.logFiles || []);
                panel._mvBuilt = true;
                self._bindMultiPanel();
            }
            panel.style.display = 'block';
            this._multiPanelOpen = true;
            document.getElementById('btn-multi-view').classList.add('lv-btn-active');
        },

        _closeMultiPanel: function() {
            this._stopMultiTail();
            const panel = document.getElementById('lv-multi-panel');
            if (panel) {
                panel.style.display = 'none';
            }
            this._multiPanelOpen = false;
            const btn = document.getElementById('btn-multi-view');
            if (btn) {
                btn.classList.remove('lv-btn-active');
            }
        },

        _buildMultiPanelHtml: function(logFiles) {
            let checkboxes = '';
            logFiles.forEach(function(f, idx) {
                const color = PALETTE[idx % PALETTE.length];
                const fName = typeof f === 'object' ? f.file_name : String(f);
                const bStyle = 'background:' + color + '22;border-color:' + color + ';color:' + color;
                checkboxes += '<label class="lv-multi-file-label" style="--fc:' + color + '">' +
                    '<input type="checkbox" class="lv-multi-chk" value="' + _escAttrMv(fName) + '"> ' +
                    '<span class="lv-multi-file-badge" style="' + bStyle + '">' +
                    _escHtmlMv(fName) + '</span>' +
                    '</label>';
            });

            if (checkboxes === '') {
                checkboxes = '<span style="color:#8b949e; font-size:12px;">No log files found.</span>';
            }

            return '<div class="lv-multi-header">' +
                '<span style="font-weight:600;color:#c9d1d9;">&#9783; Multi-file Tail / Grep</span>' +
                '<button type="button" class="lv-btn lv-btn-sm"' +
                ' id="btn-multi-close" style="margin-left:auto;">&#x2715; Close</button>' +
                '</div>' +
                '<div class="lv-multi-picker">' + checkboxes + '</div>' +
                '<div class="lv-multi-controls">' +
                '<button type="button" class="lv-btn" id="btn-multi-select-all">All</button>' +
                '<button type="button" class="lv-btn" id="btn-multi-select-none">None</button>' +
                '<span class="lv-sep"></span>' +
                '<input type="text" id="multi-grep-input"' +
                ' class="lv-input lv-input-lg" placeholder="grep pattern (optional)…" autocomplete="off">' +
                '<label style="display:inline-flex;align-items:center;gap:4px;' +
                'font-size:11px;color:#8b949e;cursor:pointer;">' +
                '<input type="checkbox" id="multi-grep-full" style="accent-color:#58a6ff;"> full scan</label>' +
                '<span class="lv-sep"></span>' +
                '<button type="button" class="lv-btn lv-btn-primary" id="btn-multi-start">&#9654; Tail</button>' +
                '<button type="button" class="lv-btn lv-btn-primary" id="btn-multi-grep-run">&#128269; Grep</button>' +
                '<button type="button" class="lv-btn lv-btn-warn"' +
                ' id="btn-multi-stop" style="display:none;">&#9646;&#9646; Stop</button>' +
                '<button type="button" class="lv-btn" id="btn-multi-clear-rows">&#9003; Clear</button>' +
                '<span id="lv-multi-status" style="font-size:11px;color:#8b949e;margin-left:6px;"></span>' +
                '</div>' +
                '<div class="lv-multi-table-wrap">' +
                '<table class="lv-multi-table"><thead><tr>' +
                '<th class="lv-mt-col-file">File</th>' +
                '<th class="lv-mt-col-line">Line</th>' +
                '</tr></thead>' +
                '<tbody id="lv-multi-tbody"></tbody>' +
                '</table>' +
                '</div>';
        },

        _bindMultiPanel: function() {
            const self = this;

            document.getElementById('btn-multi-close').addEventListener('click', function() {
                self._closeMultiPanel();
            });

            document.getElementById('btn-multi-select-all').addEventListener('click', function() {
                document.querySelectorAll('.lv-multi-chk').forEach(function(chk) {
                    chk.checked = true;
                });
            });

            document.getElementById('btn-multi-select-none').addEventListener('click', function() {
                document.querySelectorAll('.lv-multi-chk').forEach(function(chk) {
                    chk.checked = false;
                });
            });

            document.getElementById('btn-multi-start').addEventListener('click', function() {
                self._startMultiTail();
            });

            document.getElementById('btn-multi-grep-run').addEventListener('click', function() {
                self._runMultiGrep();
            });

            document.getElementById('btn-multi-stop').addEventListener('click', function() {
                self._stopMultiTail();
            });

            document.getElementById('btn-multi-clear-rows').addEventListener('click', function() {
                const tbody = document.getElementById('lv-multi-tbody');
                if (tbody) {
                    tbody.innerHTML = '';
                }
                self._multiRowCount = 0;
                self._setMultiStatus('Cleared.');
            });
        },

        _getSelectedFiles: function() {
            const selected = [];
            document.querySelectorAll('.lv-multi-chk:checked').forEach(function(chk) {
                selected.push(chk.value);
            });
            return selected;
        },

        _startMultiTail: function() {
            const files = this._getSelectedFiles();
            if (files.length === 0) {
                this._setMultiStatus('Select at least one file.');
                return;
            }
            const self = this;

            this._multiFileOffsets = {};
            this._stopMultiTail();

            const stopBtn = document.getElementById('btn-multi-stop');
            const startBtn = document.getElementById('btn-multi-start');
            if (stopBtn) {
                stopBtn.style.display = '';
            }
            if (startBtn) {
                startBtn.style.display = 'none';
            }

            this._setMultiStatus('Tailing ' + files.length + ' file(s)…');
            this._pollMultiUpdate();

            this._multiPollTimer = globalThis.setInterval(function() {
                self._pollMultiUpdate();
            }, POLL_INTERVAL_MS);
        },

        _stopMultiTail: function() {
            if (this._multiPollTimer) {
                globalThis.clearInterval(this._multiPollTimer);
                this._multiPollTimer = null;
            }
            const stopBtn = document.getElementById('btn-multi-stop');
            const startBtn = document.getElementById('btn-multi-start');
            if (stopBtn) {
                stopBtn.style.display = 'none';
            }
            if (startBtn) {
                startBtn.style.display = '';
            }
        },

        _pollMultiUpdate: function() {
            const self = this;
            const files = this._getSelectedFiles();
            if (files.length === 0) {
                this._stopMultiTail();
                return;
            }

            const body = new globalThis.FormData();
            files.forEach(function(f) {
                body.append('files[]', f);
                body.append('offsets[' + f + ']', String(self._multiFileOffsets[f] || 0));
            });
            body.append('_token', this.csrfToken || '');

            globalThis.fetch(this.options.multiTailUrl, {method: 'POST', body: body})
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    if (data.error) {
                        self._setMultiStatus('Error: ' + data.error);
                        return;
                    }
                    if (data.offsets) {
                        Object.assign(self._multiFileOffsets, data.offsets);
                    }
                    if (data.lines && data.lines.length > 0) {
                        self._appendMultiRows(data.lines);
                        self._setMultiStatus(
                            'Live — ' + new Date().toLocaleTimeString() +
                            ' (+' + data.lines.length + ' lines)'
                        );
                    } else {
                        self._setMultiStatus('Live — ' + new Date().toLocaleTimeString() + ' (no new lines)');
                    }
                })
                .catch(function(err) {
                    self._setMultiStatus('Fetch error: ' + err.message);
                });
        },

        _runMultiGrep: function() {
            const self = this;
            const files = this._getSelectedFiles();
            const pattern = (document.getElementById('multi-grep-input') || {}).value;
            const fullScan = !!(document.getElementById('multi-grep-full') || {}).checked;

            if (files.length === 0) {
                this._setMultiStatus('Select at least one file.');
                return;
            }
            if (!pattern || !pattern.trim()) {
                this._setMultiStatus('Enter a grep pattern.');
                return;
            }

            this._setMultiStatus('Grepping…');

            const body = new globalThis.FormData();
            files.forEach(function(f) {
                body.append('files[]', f);
            });
            body.append('pattern', pattern.trim());
            body.append('fullScan', fullScan ? '1' : '0');
            body.append('_token', this.csrfToken || '');

            globalThis.fetch(this.options.multiGrepUrl, {method: 'POST', body: body})
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    if (data.error) {
                        self._setMultiStatus('Error: ' + data.error);
                        return;
                    }
                    const tbody = document.getElementById('lv-multi-tbody');
                    if (tbody) {
                        tbody.innerHTML = '';
                    }
                    self._multiRowCount = 0;

                    if (data.lines && data.lines.length > 0) {
                        self._appendMultiRows(data.lines);
                        self._setMultiStatus(
                            data.lineCount + ' match(es) across ' + files.length + ' file(s) — ' +
                            data.readMs + ' ms'
                        );
                    } else {
                        self._setMultiStatus('No matches found.');
                    }
                })
                .catch(function(err) {
                    self._setMultiStatus('Fetch error: ' + err.message);
                });
        },

        _appendMultiRows: function(lines) {
            const tbody = document.getElementById('lv-multi-tbody');
            if (!tbody) {
                return;
            }
            const self = this;
            const fileColors = self._buildFileColorMap();
            const fragment = document.createDocumentFragment();

            lines.forEach(function(entry) {
                const tr = document.createElement('tr');
                const tdF = document.createElement('td');
                const tdL = document.createElement('td');
                const fName = entry.file || '';
                const color = fileColors[fName] || '#8b949e';

                tdF.className = 'lv-mt-col-file';
                const tStyle = 'background:' + color + '22;border-color:' + color + ';color:' + color;
                tdF.innerHTML = '<span class="lv-mt-badge" style="' + tStyle + '">' +
                    _escHtmlMv(fName) + '</span>';

                tdL.className = 'lv-mt-col-line';
                tdL.textContent = entry.text || '';

                tr.appendChild(tdF);
                tr.appendChild(tdL);
                fragment.appendChild(tr);
                self._multiRowCount++;
            });

            tbody.appendChild(fragment);
            tbody.scrollTop = tbody.scrollHeight;

            // Trim oldest rows when over cap
            while (self._multiRowCount > MAX_VISIBLE_ROWS) {
                if (tbody.firstChild) {
                    tbody.removeChild(tbody.firstChild);
                }
                self._multiRowCount--;
            }
        },

        _buildFileColorMap: function() {
            const map = {};
            const files = this.options.logFiles || [];
            files.forEach(function(f, idx) {
                const fName = typeof f === 'object' ? f.file_name : String(f);
                map[fName] = PALETTE[idx % PALETTE.length];
            });
            return map;
        },

        _setMultiStatus: function(msg) {
            const el = document.getElementById('lv-multi-status');
            if (el) {
                el.textContent = msg;
            }
        }
    };

    function _escHtmlMv(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function _escAttrMv(str) {
        return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
});
