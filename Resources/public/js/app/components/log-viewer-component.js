/* global document, globalThis */
define([
    'oroui/js/app/components/base/component',
    'genakerlogviewer/js/app/components/log-viewer-download-btn-mixin',
    'genakerlogviewer/js/app/components/log-viewer-window-controls-mixin',
    'genakerlogviewer/js/app/components/log-viewer-theme-wrap-mixin',
    'genakerlogviewer/js/app/components/log-viewer-js-filter-mixin',
    'genakerlogviewer/js/app/components/log-viewer-grep-mixin',
    'genakerlogviewer/js/app/components/log-viewer-live-tail-mixin',
    'genakerlogviewer/js/app/components/log-viewer-level-filter-mixin',
    'genakerlogviewer/js/app/components/log-viewer-exception-aggregator-mixin',
    'genakerlogviewer/js/app/components/log-viewer-throughput-mixin',
    'genakerlogviewer/js/app/components/log-viewer-split-mixin',
    'genakerlogviewer/js/app/components/log-viewer-multi-mixin',
    'genakerlogviewer/js/app/components/log-viewer-error-spike-mixin',
    'genakerlogviewer/js/app/components/log-viewer-level-counts-mixin'
], function() {
    'use strict';

    /* eslint-disable prefer-rest-params */
    const [
        BaseComponent,
        downloadBtnMixin,
        windowControlsMixin,
        themeWrapMixin,
        jsFilterMixin,
        grepMixin,
        liveTailMixin,
        levelFilterMixin,
        exceptionAggregatorMixin,
        throughputMixin,
        splitMixin,
        multiMixin,
        errorSpikeMixin,
        levelCountsMixin
    ] = arguments;
    /* eslint-enable prefer-rest-params */

    /**
     * Log Viewer page component.
     *
     * Bound to #log-viewer-wrap via data-page-component-module.
     * All PHP-rendered values (URLs, initial offset, grep query) are injected
     * through data-page-component-options as JSON.
     *
     * Behaviour is split across focused mixins:
     *   - log-viewer-download-btn-mixin    download button spinner
     *   - log-viewer-window-controls-mixin minimize / fullscreen dots
     *   - log-viewer-theme-wrap-mixin      light/dark theme + word-wrap toggle
     *   - log-viewer-js-filter-mixin       instant client-side line filter
     *   - log-viewer-grep-mixin            AJAX server-side grep + URL push state
     *   - log-viewer-live-tail-mixin       10 s polling, pause, reload, clear mode
     */
    return BaseComponent.extend(Object.assign(
        {},
        downloadBtnMixin,
        windowControlsMixin,
        themeWrapMixin,
        jsFilterMixin,
        grepMixin,
        liveTailMixin,
        levelFilterMixin,
        exceptionAggregatorMixin,
        throughputMixin,
        splitMixin,
        multiMixin,
        errorSpikeMixin,
        levelCountsMixin,
        {
            options: {
                logOffset: 0,
                fileName: '',
                liveUrl: '',
                reloadUrl: '',
                grepUrl: '',
                exceptionsUrl: '',
                uniqueEntriesUrl: '',
                throughputUrl: '',
                multiTailUrl: '',
                multiGrepUrl: '',
                viewBase: '',
                initGrep: '',
                initGrepFull: false,
                logFiles: []
            },

            initialize: function(options) {
                this.options = Object.assign({}, this.options, options);
                this.shell = document.getElementById('log-viewer-wrap');
                this.textarea = document.getElementById('log-content');

                if (!this.shell || !this.textarea || this.textarea._lvInited) {
                    return;
                }
                this.textarea._lvInited = true;

                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                this.csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
                this.logOffset = this.options.logOffset;
                this.grepActive = !!this.options.initGrep;
                this.originalLines = this.textarea.value.split('\n');

                this.textarea.scrollTop = this.textarea.scrollHeight;

                this.initDownloadBtn();
                this.initWindowControls();
                this.initThemeWrap();
                this.initJsFilter();
                this.initGrep();
                this.initLevelFilter();
                this.initExceptionAggregator();
                this.initUniqueEntries();
                this.initThroughput();
                this.initSplitView();
                this.initMultiView();
                this.initErrorSpikeAlert();
                this.initLevelCounts();

                this._initApplyBtn();

                if (!this.options.initGrep) {
                    this.initLiveTail();
                }
            },

            /**
             * Wires the Apply Lines button — full page navigation with current
             * lines count and optional grep query preserved in the URL.
             */
            _initApplyBtn: function() {
                const self = this;

                document.getElementById('btn-apply').addEventListener('click', function() {
                    const n = document.getElementById('lines-count').value;
                    const grep = document.getElementById('filter-grep').value.trim();
                    const fullScan = document.getElementById('grep-full').checked;
                    let url = self.options.viewBase + '?lines=' + encodeURIComponent(n);

                    if (grep) {
                        url += '&grep=' + encodeURIComponent(grep);
                    }
                    if (grep && fullScan) {
                        url += '&grepFull=1';
                    }

                    globalThis.location.href = url;
                });
            }
        }
    ));
});
