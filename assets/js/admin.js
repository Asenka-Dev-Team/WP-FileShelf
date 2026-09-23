(function ($) {
    'use strict';

    function getWrap() {
        return $('.wfs-wrap').first();
    }

    function stateFromUrl(urlValue) {
        const url = new URL(urlValue || window.location.href, window.location.href);
        return {
            tab: url.searchParams.get('tab') || 'files',
            orderby: url.searchParams.get('orderby') || 'modified_at',
            order: url.searchParams.get('order') || 'desc',
            paged: url.searchParams.get('paged') || '1',
            s: url.searchParams.get('s') || ''
        };
    }

    function ajaxErrorMessage(xhr) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
            return xhr.responseJSON.data.message;
        }
        return WFSAdmin.strings.genericError;
    }

    function setBusy(isBusy) {
        const $wrap = getWrap();
        $wrap.toggleClass('is-wfs-ajax-busy', !!isBusy);
        $wrap.attr('aria-busy', isBusy ? 'true' : 'false');
    }

    function showNotice(message, type) {
        const $wrap = getWrap();
        if (!$wrap.length) return;

        $wrap.find('.wfs-ajax-notice').remove();
        const noticeClass = type === 'success' ? 'notice-success' : (type === 'warning' ? 'notice-warning' : 'notice-error');
        const $notice = $('<div class="notice is-dismissible wfs-ajax-notice"><p></p></div>')
            .addClass(noticeClass);
        $notice.find('p').text(message);

        const $tabs = $wrap.find('.wfs-tabs').first();
        if ($tabs.length) {
            $tabs.after($notice);
        } else {
            $wrap.prepend($notice);
        }
    }

    function replaceAdminView(html, url, historyMode) {
        if (!html) return false;

        const $holder = $('<div></div>').html(html);
        let $newWrap = $holder.children('.wfs-wrap').first();
        if (!$newWrap.length) $newWrap = $holder.find('.wfs-wrap').first();
        if (!$newWrap.length) return false;

        const $oldWrap = getWrap();
        if (!$oldWrap.length) return false;
        $oldWrap.replaceWith($newWrap);

        if (url) {
            if (historyMode === 'push') {
                window.history.pushState({ wfs: true }, '', url);
            } else if (historyMode === 'replace') {
                window.history.replaceState({ wfs: true }, '', url);
            }
        }
        return true;
    }

    function request(data, options) {
        options = options || {};
        setBusy(true);

        let ajaxData = data;
        let processData = true;
        let contentType = 'application/x-www-form-urlencoded; charset=UTF-8';

        if (data instanceof FormData) {
            data.append('nonce', WFSAdmin.nonce);
            processData = false;
            contentType = false;
        } else {
            ajaxData = $.extend({}, data, { nonce: WFSAdmin.nonce });
        }

        const $button = options.button || $();
        const originalHtml = $button.length ? $button.html() : '';
        const originalDisabled = $button.length ? $button.prop('disabled') : false;
        if ($button.length) {
            $button.prop('disabled', true);
            if (options.busyText) $button.text(options.busyText);
        }

        return $.ajax({
            url: WFSAdmin.ajaxUrl,
            method: 'POST',
            data: ajaxData,
            dataType: 'json',
            processData: processData,
            contentType: contentType
        }).done(function (response) {
            if (!response || !response.success) {
                const message = response && response.data && response.data.message
                    ? response.data.message
                    : WFSAdmin.strings.genericError;
                showNotice(message, 'error');
                return;
            }

            if (response.data && response.data.html) {
                if (!replaceAdminView(response.data.html, response.data.url || '', options.historyMode || 'replace')) {
                    showNotice(WFSAdmin.strings.genericError, 'error');
                }
            }

            if (typeof options.onSuccess === 'function') {
                options.onSuccess(response);
            }
        }).fail(function (xhr) {
            showNotice(ajaxErrorMessage(xhr), 'error');
        }).always(function () {
            if ($button.length && $.contains(document, $button.get(0))) {
                $button.html(originalHtml).prop('disabled', originalDisabled);
            }
            setBusy(false);
        });
    }

    function loadUrl(urlValue, historyMode) {
        const state = stateFromUrl(urlValue);
        request({
            action: 'wfs_render_admin_view',
            tab: state.tab,
            orderby: state.orderby,
            order: state.order,
            paged: state.paged,
            s: state.s
        }, { historyMode: historyMode || 'push' });
    }

    function appendCurrentState(formData) {
        const state = stateFromUrl();
        formData.append('orderby', state.orderby);
        formData.append('order', state.order);
        formData.append('paged', state.paged);
        formData.append('s', state.s);
    }

    function copyText(text, $button) {
        if (!text) return;

        const done = function () {
            const original = $button.html();
            $button.text(WFSAdmin.strings.copied);
            window.setTimeout(function () {
                if ($.contains(document, $button.get(0))) $button.html(original);
            }, 1200);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                fallbackCopy(text, done);
            });
        } else {
            fallbackCopy(text, done);
        }
    }

    function fallbackCopy(text, done) {
        const $temp = $('<textarea>')
            .css({ position: 'fixed', left: '-9999px', top: '-9999px' })
            .val(text)
            .appendTo('body');
        $temp.trigger('select');
        try {
            document.execCommand('copy');
            done();
        } finally {
            $temp.remove();
        }
    }

    $(document).on('click', '[data-wfs-nav]', function (event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || this.target === '_blank') return;
        event.preventDefault();
        loadUrl(this.href, 'push');
    });

    $(document).on('submit', '[data-wfs-search]', function (event) {
        event.preventDefault();
        const query = $(this).find('input[name="s"]').val() || '';
        const url = new URL(WFSAdmin.pageUrl, window.location.href);
        url.searchParams.set('tab', 'files');
        url.searchParams.set('s', query);
        url.searchParams.set('paged', '1');
        loadUrl(url.toString(), 'push');
    });

    $(document).on('submit', '.wfs-upload-form', function (event) {
        event.preventDefault();
        const formData = new FormData(this);
        request(formData, {
            button: $(this).find('button[type="submit"]').first(),
            busyText: WFSAdmin.strings.uploading,
            historyMode: 'push'
        });
    });

    $(document).on('click', '[data-wfs-edit]', function () {
        const id = String($(this).data('wfs-edit'));
        const $row = $('[data-wfs-edit-row="' + id.replace(/"/g, '') + '"]');
        $('.wfs-edit-row').not($row).attr('hidden', true);
        $row.prop('hidden', !$row.prop('hidden'));
    });

    $(document).on('click', '[data-wfs-edit-cancel]', function () {
        $(this).closest('.wfs-edit-row').prop('hidden', true);
    });

    $(document).on('submit', '.wfs-edit-form', function (event) {
        event.preventDefault();
        const formData = new FormData(this);
        request(formData, {
            button: $(this).find('button[type="submit"]').first(),
            busyText: WFSAdmin.strings.saving,
            historyMode: 'replace'
        });
    });

    $(document).on('click', '[data-wfs-delete]', function () {
        if (!window.confirm(WFSAdmin.strings.confirmDelete)) return;

        const state = stateFromUrl();
        request({
            action: 'wfs_delete_file',
            file_id: $(this).data('wfs-delete'),
            orderby: state.orderby,
            order: state.order,
            paged: state.paged,
            s: state.s
        }, {
            button: $(this),
            busyText: '…',
            historyMode: 'replace'
        });
    });

    $(document).on('click', '[data-wfs-copy]', function () {
        copyText(String($(this).data('wfs-copy') || ''), $(this));
    });

    $(document).on('click', '[data-wfs-password-toggle]', function () {
        const targetId = String($(this).data('wfs-password-toggle') || '');
        const input = targetId ? document.getElementById(targetId) : null;
        if (!input) return;

        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        $(this)
            .attr('aria-pressed', reveal ? 'true' : 'false')
            .text(reveal ? (WFSAdmin.strings.hide || 'Hide') : (WFSAdmin.strings.view || 'View'));
    });

    $(document).on('submit', '.wfs-settings-form, .wfs-advanced-form, .wfs-update-check-form', function (event) {
        event.preventDefault();
        const formData = new FormData(this);
        const $button = $(this).find('button[type="submit"]').first();
        const isUpdate = $(this).hasClass('wfs-update-check-form');
        request(formData, {
            button: $button,
            busyText: isUpdate ? WFSAdmin.strings.checking : WFSAdmin.strings.saving,
            historyMode: 'replace'
        });
    });

    $(document).on('click', '[data-wfs-select-all-mimes]', function () {
        $('.wfs-mime-groups input[type="checkbox"]').prop('checked', true);
    });

    $(document).on('click', '[data-wfs-clear-mimes]', function () {
        $('.wfs-mime-groups input[type="checkbox"]').prop('checked', false);
    });

    window.addEventListener('popstate', function () {
        loadUrl(window.location.href, 'none');
    });
})(jQuery);
