(function () {
    'use strict';

    const config = window.WFSFront || {};
    const notice = document.getElementById('wfs-front-notice');
    const loginForm = document.getElementById('wfs-front-login');
    const uploadForm = document.getElementById('wfs-front-upload');
    const logoutButton = document.getElementById('wfs-front-logout');
    const modal = document.getElementById('wfs-replace-modal');
    const modalMessage = document.getElementById('wfs-replace-message');
    const confirmReplace = document.getElementById('wfs-confirm-replace');
    let pendingUploadForm = null;

    function showNotice(message, type) {
        if (!notice) return;
        notice.hidden = false;
        notice.className = 'wfs-front-notice is-' + (type || 'info');
        notice.textContent = message;
        notice.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function setButtonBusy(button, busy, busyText) {
        if (!button) return;
        if (busy) {
            button.dataset.originalText = button.textContent;
            button.disabled = true;
            button.textContent = busyText || config.strings.working || 'Working…';
        } else {
            button.disabled = false;
            button.textContent = button.dataset.originalText || button.textContent;
        }
    }

    async function postForm(formData) {
        const response = await fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });

        let json = null;
        try {
            json = await response.json();
        } catch (error) {
            throw new Error(config.strings.genericError || 'Something went wrong. Please try again.');
        }

        if (!json || !json.success) {
            const err = new Error(json && json.data && json.data.message
                ? json.data.message
                : (config.strings.genericError || 'Something went wrong. Please try again.'));
            err.payload = json && json.data ? json.data : {};
            err.status = response.status;
            throw err;
        }

        return json.data || {};
    }

    function openReplaceModal(payload) {
        if (!modal) return;
        const filename = payload && payload.filename ? payload.filename : 'This file';
        modalMessage.textContent = filename + ' already exists. Do you want to replace the existing file?';
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('wfs-modal-open');
        if (confirmReplace) confirmReplace.focus();
    }

    function closeReplaceModal() {
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('wfs-modal-open');
    }

    if (loginForm) {
        loginForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            const button = loginForm.querySelector('button[type="submit"]');
            const data = new FormData(loginForm);
            setButtonBusy(button, true, config.strings.working);
            try {
                await postForm(data);
                window.location.reload();
            } catch (error) {
                showNotice(error.message, 'error');
                setButtonBusy(button, false);
            }
        });
    }

    async function submitUpload(replaceExisting) {
        if (!pendingUploadForm) return;
        const button = pendingUploadForm.querySelector('button[type="submit"]');
        const data = new FormData(pendingUploadForm);
        if (replaceExisting) data.append('replace_existing', '1');

        setButtonBusy(button, true, config.strings.uploading);
        try {
            const result = await postForm(data);
            closeReplaceModal();
            pendingUploadForm.reset();
            pendingUploadForm = null;
            showNotice(result.message || (replaceExisting ? config.strings.replaced : config.strings.uploaded), 'success');
        } catch (error) {
            setButtonBusy(button, false);
            if (error.payload && error.payload.code === 'duplicate' && !replaceExisting) {
                openReplaceModal(error.payload);
                return;
            }
            closeReplaceModal();
            showNotice(error.message, 'error');
        } finally {
            if (button && document.body.contains(button)) setButtonBusy(button, false);
        }
    }

    if (uploadForm) {
        uploadForm.addEventListener('submit', function (event) {
            event.preventDefault();
            pendingUploadForm = uploadForm;
            submitUpload(false);
        });
    }

    if (confirmReplace) {
        confirmReplace.addEventListener('click', function () {
            submitUpload(true);
        });
    }

    document.querySelectorAll('[data-wfs-modal-cancel]').forEach(function (element) {
        element.addEventListener('click', function () {
            closeReplaceModal();
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && !modal.hidden) closeReplaceModal();
    });

    if (logoutButton) {
        logoutButton.addEventListener('click', async function () {
            const data = new FormData();
            data.append('action', 'wfs_frontend_logout');
            data.append('nonce', config.logoutNonce || '');
            setButtonBusy(logoutButton, true, config.strings.working);
            try {
                await postForm(data);
                window.location.reload();
            } catch (error) {
                showNotice(error.message, 'error');
                setButtonBusy(logoutButton, false);
            }
        });
    }
})();
