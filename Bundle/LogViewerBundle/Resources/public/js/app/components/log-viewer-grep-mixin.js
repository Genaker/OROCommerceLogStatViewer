define([], function() {
    'use strict';

    return {
        /**
         * Wires server-side grep via AJAX.
         * Updates window URL with history.pushState for shareable links.
         * Restores the grep badge when the page loads with an active grep query.
         */
        initGrep: function() {
            const self = this;
            const filterGrep = document.getElementById('filter-grep');
            const btnGrep = document.getElementById('btn-grep');
            const btnGrepClear = document.getElementById('btn-grep-clear');
            const grepStatus = document.getElementById('grep-status');
            const grepStatusBadge = document.getElementById('grep-status-badge');
            const liveIndicator = document.getElementById('live-indicator');

            function escHtml(str) {
                return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            function buildBadgeHtml(pattern, fullScan) {
                return '&#9679; <strong>' + escHtml(pattern) + '</strong>' +
                    ' <em style="opacity:.7">' + (fullScan ? 'full file' : 'last 50 MB') + '</em>';
            }

            function doGrep() {
                const pattern = filterGrep.value.trim();
                if (!pattern) {
                    return;
                }

                const n = document.getElementById('lines-count').value;
                const fullScan = document.getElementById('grep-full').checked;
                const fd = new FormData();
                fd.append('fileName', self.options.fileName);
                fd.append('pattern', pattern);
                fd.append('lines', n);
                if (fullScan) {
                    fd.append('fullScan', '1');
                }

                btnGrep.textContent = '\u29d6 Searching\u2026';
                btnGrep.disabled = true;

                fetch(self.options.grepUrl, {
                    method: 'POST',
                    body: fd,
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': self.csrfToken}
                })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        self.originalLines = data.content.split('\n');
                        self.textarea.value = data.content;
                        self.textarea.scrollTop = 0;
                        self.grepActive = true;

                        const shareUrl = self.options.viewBase + '?lines=' + encodeURIComponent(n) +
                            '&grep=' + encodeURIComponent(pattern) +
                            (fullScan ? '&grepFull=1' : '');
                        history.pushState({grep: pattern}, '', shareUrl);

                        grepStatusBadge.innerHTML = buildBadgeHtml(pattern, fullScan);
                        grepStatus.style.display = 'flex';

                        const statLines = document.getElementById('stat-lines');
                        const statLastUpdate = document.getElementById('stat-last-update');
                        if (statLines) {
                            statLines.textContent = data.lineCount;
                        }
                        if (statLastUpdate) {
                            statLastUpdate.textContent = data.loadedAt;
                        }

                        if (liveIndicator) {
                            liveIndicator.className = 'live-idle';
                            liveIndicator.textContent = '\u25ce Grep: ' + data.readMs + ' ms';
                        }
                    })
                    .catch(function(err) {
                        if (liveIndicator) {
                            liveIndicator.className = 'live-err';
                            liveIndicator.textContent = '\u25cf Grep error: ' + err.message;
                        }
                    })
                    .finally(function() {
                        btnGrep.innerHTML = '&#128269; Grep';
                        btnGrep.disabled = false;
                    });
            }

            function clearGrep() {
                self.grepActive = false;
                filterGrep.value = '';
                grepStatus.style.display = 'none';
                history.pushState({}, '', self.options.viewBase);
                document.getElementById('btn-reload').click();
            }

            btnGrep.addEventListener('click', doGrep);
            filterGrep.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    doGrep();
                }
            });
            btnGrepClear.addEventListener('click', clearGrep);

            // Restore badge when page loads with an active server-rendered grep result
            if (self.options.initGrep) {
                grepStatusBadge.innerHTML = buildBadgeHtml(self.options.initGrep, self.options.initGrepFull);
                grepStatus.style.display = 'flex';
            }
        }
    };
});
