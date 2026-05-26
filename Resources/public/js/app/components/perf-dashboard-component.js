/* global document */
define([
    'oroui/js/app/components/base/component'
], function(BaseComponent) {
    'use strict';

    const POLL_INTERVAL_MS = 30000;
    const LOAD_WARN = 2.0;
    const LOAD_CRIT = 5.0;
    const MEM_WARN = 75;
    const MEM_CRIT = 90;
    const STALE_WARN_S = 90;
    const STALE_OLD_S = 600;

    /**
     * Performance Dashboard component.
     *
     * Default state: paused. User clicks Auto-refresh or Refresh to load data.
     * Auto-refresh polls every 30 s with a visual countdown bar.
     */
    const PerfDashboardComponent = BaseComponent.extend({
        constructor: function PerfDashboardComponent(options) {
            PerfDashboardComponent.__super__.constructor.call(this, options);
        },

        options: {
            instancesUrl: ''
        },

        initialize: function(options) {
            PerfDashboardComponent.__super__.initialize.call(this, options);
            this.options = Object.assign({}, this.options, options);
            this.cardsEl = document.getElementById('pd-cards');
            this.statusEl = document.getElementById('pd-status');
            this._playing = false;
            this._pollTimer = null;
            this._countdownTimer = null;

            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            this.csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

            this._bindControls();
            this._initThemeToggle();
            this._fetchInstances();
        },

        _initThemeToggle: function() {
            const shell = document.querySelector('.pd-shell');
            const btn = document.getElementById('pd-btn-theme');
            const iconEl = document.getElementById('pd-theme-icon');

            if (!shell || !btn) {
                return;
            }

            if (iconEl) {
                iconEl.textContent = '\u263d';
            }

            btn.addEventListener('click', function() {
                const nowDark = shell.classList.toggle('pd-dark');
                if (iconEl) {
                    iconEl.textContent = nowDark ? '\u2600' : '\u263d';
                }
            });
        },

        _bindControls: function() {
            const self = this;

            this.cardsEl.addEventListener('click', function(e) {
                const toggle = e.target.closest('.pd-proc-toggle');
                if (!toggle) {
                    return;
                }
                const wrap = toggle.nextElementSibling;
                const isOpen = toggle.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (wrap) {
                    wrap.classList.toggle('is-open', isOpen);
                }
            });

            const btnRefresh = document.getElementById('pd-btn-refresh');
            if (btnRefresh) {
                btnRefresh.addEventListener('click', function() {
                    self._fetchInstances();
                });
            }

            const btnPlay = document.getElementById('pd-btn-play');
            if (btnPlay) {
                btnPlay.addEventListener('click', function() {
                    self._togglePolling();
                });
            }
        },

        _togglePolling: function() {
            if (this._playing) {
                this._stopPolling();
            } else {
                this._startPolling();
            }
        },

        _startPolling: function() {
            const self = this;
            this._playing = true;
            this._updatePlayButton();
            this._fetchInstances();
            this._resetCountdown();

            clearInterval(this._pollTimer);
            this._pollTimer = setInterval(function() {
                self._fetchInstances();
                self._resetCountdown();
            }, POLL_INTERVAL_MS);
        },

        _stopPolling: function() {
            this._playing = false;
            clearInterval(this._pollTimer);
            this._pollTimer = null;
            clearInterval(this._countdownTimer);
            this._countdownTimer = null;
            this._updatePlayButton();
            this._hideCountdown();
        },

        _updatePlayButton: function() {
            const btn = document.getElementById('pd-btn-play');
            const iconEl = document.getElementById('pd-play-icon');
            const labelEl = document.getElementById('pd-btn-play-label');
            if (!btn) {
                return;
            }

            if (this._playing) {
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-success');
                if (iconEl) {
                    iconEl.textContent = '\u23f8';
                }
                if (labelEl) {
                    labelEl.textContent = 'Pause';
                }
            } else {
                btn.classList.remove('btn-success');
                btn.classList.add('btn-primary');
                if (iconEl) {
                    iconEl.textContent = '\u25b6';
                }
                if (labelEl) {
                    labelEl.textContent = 'Auto-refresh';
                }
            }
        },

        _resetCountdown: function() {
            const wrap = document.getElementById('pd-countdown-wrap');
            const bar = document.getElementById('pd-countdown-bar');
            if (!wrap || !bar) {
                return;
            }

            clearInterval(this._countdownTimer);
            wrap.style.display = '';
            bar.classList.remove('pd-counting');
            bar.style.width = '100%';

            setTimeout(function() {
                bar.classList.add('pd-counting');
                bar.style.transitionDuration = POLL_INTERVAL_MS + 'ms';
                bar.style.width = '0%';
            }, 30);

            this._countdownTimer = setInterval(function() {
                bar.classList.remove('pd-counting');
                bar.style.width = '100%';
                setTimeout(function() {
                    bar.classList.add('pd-counting');
                    bar.style.transitionDuration = POLL_INTERVAL_MS + 'ms';
                    bar.style.width = '0%';
                }, 30);
            }, POLL_INTERVAL_MS);
        },

        _hideCountdown: function() {
            const wrap = document.getElementById('pd-countdown-wrap');
            if (wrap) {
                wrap.style.display = 'none';
            }
        },

        _fetchInstances: function() {
            const self = this;
            const refreshEl = document.getElementById('pd-last-refresh');

            fetch(this.options.instancesUrl, {
                headers: {'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json'}
            })
                .then(function(res) {
                    return res.json();
                })
                .then(function(instances) {
                    if (refreshEl) {
                        refreshEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
                    }
                    self._render(instances);
                    self._setStatus('ok');
                })
                .catch(function() {
                    self._setStatus('error');
                });
        },

        _render: function(instances) {
            const self = this;
            if (!this.cardsEl) {
                return;
            }

            this._updateStats(instances);

            if (!instances || instances.length === 0) {
                const emptyMsg = '&#9675; No live instances found.' +
                    ' Metrics are reported automatically on every PHP request.';
                this.cardsEl.innerHTML = '<div class="pd-empty">' + emptyMsg + '</div>';

                return;
            }

            this.cardsEl.innerHTML = instances.map(function(inst) {
                return self._renderCard(inst);
            }).join('');
        },

        _renderCard: function(inst) {
            const load = inst.load || {};
            const mem = inst.memory || {};
            const cpu = inst.cpu || {};
            const procs = inst.processes || [];

            const ageSeconds = this._ageSeconds(inst.collectedAt);
            const ageCls = ageSeconds >= STALE_OLD_S ? 'pd-card-old'
                : (ageSeconds >= STALE_WARN_S ? 'pd-card-stale' : '');
            const dotCls = ageSeconds >= STALE_OLD_S ? 'pd-dot-old'
                : (ageSeconds >= STALE_WARN_S ? 'pd-dot-stale' : 'pd-dot-live');
            const dotChar = ageSeconds >= STALE_WARN_S ? '\u25cb' : '\u25cf';

            const loadCls = load.m1 >= LOAD_CRIT ? 'pd-crit'
                : (load.m1 >= LOAD_WARN ? 'pd-warn' : 'pd-ok');
            const memCls = mem.usedPct >= MEM_CRIT ? 'pd-crit'
                : (mem.usedPct >= MEM_WARN ? 'pd-warn' : 'pd-ok');

            const memBar = Math.min(100, mem.usedPct || 0);
            const cores = cpu.cores || 1;
            const maxLoad = cores * LOAD_CRIT;

            const barH = function(val) {
                return Math.max(2, Math.round(Math.min(val / maxLoad, 1) * 18));
            };
            const barCls = function(val) {
                return val >= LOAD_CRIT ? 'pd-crit' : (val >= LOAD_WARN ? 'pd-warn' : '');
            };

            const lb1 = '<div class="pd-load-bar ' + barCls(load.m1) + '"' +
                ' style="height:' + barH(load.m1) + 'px" title="1 min: ' + load.m1 + '"></div>';
            const lb5 = '<div class="pd-load-bar ' + barCls(load.m5) + '"' +
                ' style="height:' + barH(load.m5) + 'px" title="5 min: ' + load.m5 + '"></div>';
            const lb15 = '<div class="pd-load-bar ' + barCls(load.m15) + '"' +
                ' style="height:' + barH(load.m15) + 'px" title="15 min: ' + load.m15 + '"></div>';
            const loadBars = '<div class="pd-load-bars">' + lb1 + lb5 + lb15 + '</div>';

            const procRows = procs.map(function(p) {
                return '<tr>' +
                    '<td class="pd-pt-pid">' + p.pid + '</td>' +
                    '<td class="pd-pt-user">' + p.user + '</td>' +
                    '<td class="pd-pt-cpu">' + p.cpu + '%</td>' +
                    '<td class="pd-pt-mem">' + p.mem + '%</td>' +
                    '<td class="pd-pt-cmd">' + p.command + '</td>' +
                    '</tr>';
            }).join('');

            return '<div class="pd-card ' + ageCls + '">' +

                '<div class="pd-card-header">' +
                    '<span class="pd-card-hostname">&#128187; ' +
                        inst.hostname + '</span>' +
                    '<span class="pd-card-ip">' + inst.ip + '</span>' +
                    '<span class="pd-card-age">' +
                        '<span class="' + dotCls + '">' + dotChar + '</span>' +
                        '<span class="pd-age-italic">' +
                            this._formatAge(ageSeconds) + '</span>' +
                    '</span>' +
                '</div>' +

                '<div class="pd-metrics">' +

                    '<div class="pd-metric">' +
                        '<span class="pd-metric-label">Load avg 1/5/15</span>' +
                        '<span class="pd-metric-val ' + loadCls + '">' +
                            load.m1 + '<small> / ' + load.m5 + ' / ' + load.m15 +
                            '</small></span>' +
                        loadBars +
                        '<span class="pd-metric-sub">' + cores + ' core' + (cores !== 1 ? 's' : '') + '</span>' +
                    '</div>' +

                    '<div class="pd-metric">' +
                        '<span class="pd-metric-label">Memory</span>' +
                        '<span class="pd-metric-val ' + memCls + '">' +
                            mem.usedPct + '<small>%</small></span>' +
                        '<div class="pd-bar-track"><div class="pd-bar-fill ' +
                            memCls + '" style="width:' + memBar + '%"></div></div>' +
                        '<span class="pd-metric-sub">' +
                            this._kbToGb(mem.used) + ' / ' + this._kbToGb(mem.total) +
                            '</span>' +
                    '</div>' +

                    '<div class="pd-metric">' +
                        '<span class="pd-metric-label">Free mem</span>' +
                        '<span class="pd-metric-val">' + this._kbToGb(mem.available) + '</span>' +
                        '<span class="pd-metric-sub">avail &nbsp;&#183;&nbsp; ' +
                            this._kbToGb(mem.free) + ' free</span>' +
                    '</div>' +

                '</div>' +

                (procRows
                    ? '<div class="pd-proc-toggle" role="button" aria-expanded="false">' +
                        '<span class="pd-proc-arrow">&#9654;</span>' +
                        'Top Processes (' + procs.length + ')' +
                        '</div>' +
                        '<div class="pd-proc-wrap">' +
                        '<table class="pd-proc-table">' +
                        '<thead><tr>' +
                        '<th>PID</th><th>User</th>' +
                        '<th class="pd-pt-cpu">CPU%</th>' +
                        '<th class="pd-pt-mem">MEM%</th>' +
                        '<th>Command</th>' +
                        '</tr></thead>' +
                        '<tbody>' + procRows + '</tbody>' +
                        '</table></div>'
                    : '') +

                '</div>';
        },

        _updateStats: function(instances) {
            const total = instances.length;
            let liveCnt = 0;
            let staleCnt = 0;
            let offlineCnt = 0;
            let maxLoad = 0;
            let maxMem = 0;
            let sumMem = 0;

            instances.forEach(function(inst) {
                const age = inst.collectedAt
                    ? Math.max(0, Math.round(
                        (Date.now() - new Date(inst.collectedAt.replace(' ', 'T') + 'Z').getTime()) / 1000
                    ))
                    : 9999;

                if (age >= STALE_OLD_S) {
                    offlineCnt++;
                } else if (age >= STALE_WARN_S) {
                    staleCnt++;
                } else {
                    liveCnt++;
                }

                if (inst.load && inst.load.m1 > maxLoad) {
                    maxLoad = inst.load.m1;
                }
                if (inst.memory) {
                    if (inst.memory.usedPct > maxMem) {
                        maxMem = inst.memory.usedPct;
                    }
                    sumMem += inst.memory.usedPct || 0;
                }
            });

            const avgMem = total > 0 ? Math.round(sumMem / total * 10) / 10 : 0;
            const loadCls = maxLoad >= LOAD_CRIT ? 'pd-crit' : (maxLoad >= LOAD_WARN ? 'pd-warn' : 'pd-ok');
            const memCls = maxMem >= MEM_CRIT ? 'pd-crit' : (maxMem >= MEM_WARN ? 'pd-warn' : 'pd-ok');

            this._setText('pd-s-hosts', String(total));
            this._setText('pd-s-live', String(liveCnt));
            this._setText('pd-s-stale', String(staleCnt));
            this._setText('pd-s-offline', String(offlineCnt));
            this._setTextCls('pd-s-load', total > 0 ? String(maxLoad) : '—', loadCls);
            this._setTextCls('pd-s-mem', total > 0 ? maxMem + '%' : '—', memCls);
            this._setText('pd-s-avgmem', total > 0 ? avgMem + '%' : '—');
        },

        _setText: function(id, text) {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = text;
            }
        },

        _setTextCls: function(id, text, cls) {
            const el = document.getElementById(id);
            if (!el) {
                return;
            }
            el.textContent = text;
            el.className = 'pd-spv ' + cls;
        },

        _kbToGb: function(kb) {
            if (!kb || kb <= 0) {
                return '0 B';
            }
            if (kb < 1024) {
                return kb + ' KB';
            }
            if (kb < 1048576) {
                return (kb / 1024).toFixed(0) + ' MB';
            }

            return (kb / 1048576).toFixed(1) + ' GB';
        },

        _ageSeconds: function(collectedAt) {
            if (!collectedAt) {
                return 0;
            }
            const parsed = new Date(collectedAt.replace(' ', 'T') + 'Z');
            if (isNaN(parsed.getTime())) {
                return 0;
            }

            return Math.max(0, Math.round((Date.now() - parsed.getTime()) / 1000));
        },

        _formatAge: function(ageSeconds) {
            if (ageSeconds < 60) {
                return 'just now';
            }
            const mins = Math.round(ageSeconds / 60);
            if (mins === 1) {
                return '1 min ago';
            }

            return mins + ' min ago';
        },

        _setStatus: function(state) {
            const el = this.statusEl;
            if (!el) {
                return;
            }
            if (state === 'ok') {
                el.textContent = '\u25cf';
                el.className = 'pd-status-dot pd-s-ok';
            } else {
                el.textContent = '\u25cf';
                el.className = 'pd-status-dot pd-s-err';
            }
        }
    });

    return PerfDashboardComponent;
});
