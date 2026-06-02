define([], function() {
    'use strict';

    return {
        /**
         * Intercepts the download button and performs an AJAX fetch+blob download
         * so the page does not navigate away.
         */
        initDownloadBtn: function() {
            const btn = document.getElementById('btn-download');
            const icon = document.getElementById('btn-download-icon');
            const label = document.getElementById('btn-download-label');
            if (!btn || !icon || !label) {
                return;
            }

            const downloadUrl = btn.getAttribute('href');
            const fileName = btn.getAttribute('download') || 'download.log';
            const originalLabel = label.textContent;

            btn.addEventListener('click', function(e) {
                e.preventDefault();
                btn.classList.add('downloading');
                icon.className = 'lv-spinning';
                icon.textContent = '\u29d6';
                label.textContent = 'Preparing\u2026';

                fetch(downloadUrl, {credentials: 'same-origin'})
                    .then(function(response) {
                        if (!response.ok) {
                            throw new Error('Download failed: ' + response.status);
                        }
                        return response.blob();
                    })
                    .then(function(blob) {
                        const objectUrl = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = objectUrl;
                        a.download = fileName;
                        document.body.appendChild(a);
                        a.click();
                        setTimeout(function() {
                            URL.revokeObjectURL(objectUrl);
                            a.remove();
                        }, 1000);
                        btn.classList.remove('downloading');
                        icon.className = '';
                        icon.textContent = '\u2713';
                        label.textContent = originalLabel;
                        setTimeout(function() {
                            icon.textContent = '\u2B07';
                        }, 2000);
                    })
                    .catch(function(err) {
                        btn.classList.remove('downloading');
                        icon.className = '';
                        icon.textContent = '\u26a0';
                        label.textContent = 'Error';
                        setTimeout(function() {
                            icon.textContent = '\u2B07';
                            label.textContent = originalLabel;
                        }, 3000);
                        // eslint-disable-next-line no-console
                        console.error('Log download failed:', err);
                    });
            });
        }
    };
});
