/* global document, fetch, FormData */
define([], function() {
    'use strict';

    return {
        /**
         * Wires the Throughput graph panel button.
         * Renders a lines-per-minute bar chart as inline SVG.
         */
        initThroughput: function() {
            const self = this;
            const panel = document.getElementById('lv-throughput-panel');
            const btn = document.getElementById('btn-throughput');

            if (!panel || !btn) {
                return;
            }

            btn.addEventListener('click', function() {
                const isOpen = panel.style.display !== 'none';
                panel.style.display = isOpen ? 'none' : 'block';
                btn.classList.toggle('active', !isOpen);
                if (!isOpen) {
                    self._loadThroughput();
                }
            });
        },

        _loadThroughput: function() {
            const self = this;
            const body = document.querySelector('#lv-throughput-panel .lv-throughput-body');
            if (!body) {
                return;
            }

            body.innerHTML = '<span class="lv-exc-msg" style="padding:12px 14px;display:block;">' +
                'Analysing throughput…</span>';

            const fd = new FormData();
            fd.append('fileName', this.options.fileName);
            fd.append('scanLines', '5000');

            fetch(this.options.throughputUrl, {
                method: 'POST',
                headers: {'X-CSRF-TOKEN': this.csrfToken},
                body: fd
            })
                .then(function(res) {
                    return res.json();
                })
                .then(function(data) {
                    if (!data.labels || data.labels.length === 0) {
                        body.innerHTML = '<span class="lv-exc-empty">' +
                            'No timestamped lines found in last 5 000 lines.</span>';
                        return;
                    }
                    body.innerHTML = '';
                    const meta = document.createElement('div');
                    meta.className = 'lv-throughput-meta';
                    const peak = data.maxVal + ' lines/min';
                    meta.textContent = data.totalLines + ' lines scanned \u00b7 ' +
                        data.labels.length + ' minute buckets \u00b7 peak: ' + peak;
                    body.appendChild(meta);
                    body.appendChild(self._buildThroughputSvg(data));
                })
                .catch(function() {
                    body.innerHTML = '<span class="lv-exc-error">Failed to load throughput data.</span>';
                });
        },

        /**
         * Builds an inline SVG bar chart for lines-per-minute data.
         *
         * @param {{labels: string[], values: number[], maxVal: number}} data
         * @returns {SVGSVGElement}
         */
        _buildThroughputSvg: function(data) {
            const ns = 'http://www.w3.org/2000/svg';
            const W = 680;
            const H = 130;
            const padL = 44;
            const padR = 12;
            const padT = 14;
            const padB = 34;
            const chartW = W - padL - padR;
            const chartH = H - padT - padB;
            const labels = data.labels;
            const values = data.values;
            const maxVal = data.maxVal || 1;
            const count = labels.length;
            const barStep = chartW / count;
            const barW = Math.max(2, barStep - 1);

            const svg = document.createElementNS(ns, 'svg');
            svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
            svg.setAttribute('width', '100%');
            svg.style.maxWidth = W + 'px';
            svg.style.display = 'block';
            svg.setAttribute('role', 'img');
            svg.setAttribute('aria-label', 'Lines per minute bar chart');

            // Grid lines and Y-axis labels
            [0, 0.25, 0.5, 0.75, 1.0].forEach(function(frac) {
                const y = padT + chartH - frac * chartH;
                const val = Math.round(frac * maxVal);

                const gridLine = document.createElementNS(ns, 'line');
                gridLine.setAttribute('x1', padL);
                gridLine.setAttribute('x2', W - padR);
                gridLine.setAttribute('y1', y);
                gridLine.setAttribute('y2', y);
                gridLine.setAttribute('stroke', frac === 0 ? 'rgba(255,255,255,0.15)' : 'rgba(255,255,255,0.05)');
                gridLine.setAttribute('stroke-width', '1');
                svg.appendChild(gridLine);

                const label = document.createElementNS(ns, 'text');
                label.setAttribute('x', padL - 5);
                label.setAttribute('y', y + 4);
                label.setAttribute('text-anchor', 'end');
                label.setAttribute('font-size', '9');
                label.setAttribute('font-family', 'Consolas,monospace');
                label.setAttribute('fill', '#3f5470');
                label.textContent = val;
                svg.appendChild(label);
            });

            // Bars
            values.forEach(function(val, i) {
                const barH = Math.max(1, (val / maxVal) * chartH);
                const x = padL + i * barStep;
                const y = padT + chartH - barH;
                const pct = val / maxVal;
                const r = Math.round(88 + pct * 100);
                const g = Math.round(166 - pct * 80);
                const b = Math.round(255 - pct * 100);

                const bar = document.createElementNS(ns, 'rect');
                bar.setAttribute('x', x);
                bar.setAttribute('y', y);
                bar.setAttribute('width', barW);
                bar.setAttribute('height', barH);
                bar.setAttribute('rx', '2');
                bar.setAttribute('fill', 'rgba(' + r + ',' + g + ',' + b + ',0.8)');
                svg.appendChild(bar);

                const tip = document.createElementNS(ns, 'title');
                tip.textContent = labels[i] + ': ' + val + ' lines';
                bar.appendChild(tip);
            });

            // X-axis labels — show every Nth to avoid overlap
            const every = Math.max(1, Math.ceil(count / 10));
            labels.forEach(function(label, i) {
                if (i % every !== 0) {
                    return;
                }
                const x = padL + i * barStep + barW / 2;
                const txt = document.createElementNS(ns, 'text');
                txt.setAttribute('x', x);
                txt.setAttribute('y', H - 8);
                txt.setAttribute('text-anchor', 'middle');
                txt.setAttribute('font-size', '9');
                txt.setAttribute('font-family', 'Consolas,monospace');
                txt.setAttribute('fill', '#3f5470');
                txt.textContent = label;
                svg.appendChild(txt);
            });

            return svg;
        }
    };
});
