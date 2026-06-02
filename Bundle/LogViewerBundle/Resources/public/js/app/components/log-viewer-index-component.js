define([
    'oroui/js/app/components/base/component',
    'oroui/js/delete-confirmation',
    'oroui/js/messenger'
], function(BaseComponent, DeleteConfirmation, messenger) {
    /* global document */
    'use strict';

    const ICON_SPINNER = '\u29d6';
    const ICON_SUCCESS = '\u2713';
    const ICON_WARNING = '\u26a0';

    const REVOKE_DELAY_MS = 1000;
    const SUCCESS_ICON_MS = 2000;
    const ERROR_RESET_MS = 3000;

    /**
     * Handles AJAX download and delete actions on the log viewer index page.
     */
    const LogViewerIndexComponent = BaseComponent.extend({
        constructor: function LogViewerIndexComponent(options) {
            LogViewerIndexComponent.__super__.constructor.call(this, options);
        },

        /**
         * @param {Object} options
         */
        initialize: function(options) {
            LogViewerIndexComponent.__super__.initialize.call(this, options);
            this._initDownloadLinks();
            this._initDeleteLinks();
        },

        /**
         * Attaches fetch+blob download handlers to all .lv-ajax-download links.
         */
        _initDownloadLinks: function() {
            document.querySelectorAll('.lv-ajax-download').forEach(link => {
                link.addEventListener('click', e => {
                    e.preventDefault();
                    this._startDownload(link);
                });
            });
        },

        /**
         * @param {HTMLElement} link
         */
        _startDownload: function(link) {
            const downloadUrl = link.dataset.downloadUrl;
            const fileName = link.dataset.fileName;
            const originalText = link.textContent;

            link.textContent = ICON_SPINNER + ' Downloading\u2026';
            link.style.opacity = '0.6';
            link.style.pointerEvents = 'none';
            link.setAttribute('aria-busy', 'true');

            fetch(downloadUrl, {credentials: 'same-origin'})
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Download failed: ' + response.status);
                    }
                    return response.blob();
                })
                .then(blob => {
                    this._triggerBlobDownload(blob, fileName);
                    link.textContent = ICON_SUCCESS + ' Done';
                    link.style.opacity = '';
                    link.style.pointerEvents = '';
                    link.setAttribute('aria-busy', 'false');
                    setTimeout(function() {
                        link.textContent = originalText;
                    }, SUCCESS_ICON_MS);
                })
                .catch(function(downloadError) {
                    link.textContent = ICON_WARNING + ' Error';
                    link.style.opacity = '';
                    link.style.pointerEvents = '';
                    link.setAttribute('aria-busy', 'false');
                    setTimeout(function() {
                        link.textContent = originalText;
                    }, ERROR_RESET_MS);
                    messenger.notificationFlashMessage('error', 'Download failed: ' + downloadError.message);
                });
        },

        /**
         * Creates a temporary anchor to trigger a browser file download from a Blob.
         *
         * @param {Blob} blob
         * @param {string} fileName
         */
        _triggerBlobDownload: function(blob, fileName) {
            const objectUrl = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download = fileName;
            document.body.appendChild(anchor);
            anchor.click();
            setTimeout(function() {
                URL.revokeObjectURL(objectUrl);
                anchor.remove();
            }, REVOKE_DELAY_MS);
        },

        /**
         * Attaches AJAX delete handlers to all .lv-ajax-delete links,
         * showing a confirmation modal before proceeding.
         */
        _initDeleteLinks: function() {
            document.querySelectorAll('.lv-ajax-delete').forEach(link => {
                link.addEventListener('click', e => {
                    e.preventDefault();
                    this._confirmAndDelete(link);
                });
            });
        },

        /**
         * @param {HTMLElement} link
         */
        _confirmAndDelete: function(link) {
            const fileName = link.dataset.fileName;
            const confirmView = new DeleteConfirmation({
                content: 'Are you sure you want to delete <strong>' + fileName + '</strong>?'
            });
            confirmView.on('ok', () => {
                this._performDelete(link);
            });
            confirmView.open();
        },

        /**
         * @param {HTMLElement} link
         */
        _performDelete: function(link) {
            const deleteUrl = link.dataset.deleteUrl;
            const csrfToken = link.dataset.csrf;
            const originalText = link.textContent;

            link.textContent = 'Deleting\u2026';
            link.style.opacity = '0.6';
            link.style.pointerEvents = 'none';
            link.setAttribute('aria-busy', 'true');

            const formData = new FormData();
            formData.append('_token', csrfToken);

            fetch(deleteUrl, {method: 'POST', credentials: 'same-origin', body: formData})
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Delete failed: ' + response.status);
                    }
                    const row = link.closest('tr');
                    if (row) {
                        row.remove();
                    }
                })
                .catch(function(deleteError) {
                    link.textContent = originalText;
                    link.style.opacity = '';
                    link.style.pointerEvents = '';
                    link.setAttribute('aria-busy', 'false');
                    messenger.notificationFlashMessage('error', 'Delete failed: ' + deleteError.message);
                });
        }
    });

    return LogViewerIndexComponent;
});
