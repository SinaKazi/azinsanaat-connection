(function ($) {
    'use strict';

    var settings = (typeof AzinsanaatManualSync !== 'undefined') ? AzinsanaatManualSync : { messages: {} };
    var state = {
        offset: 0,
        synced: 0,
        failed: 0,
        total: 0,
        running: false
    };

    function setStatus($status, type, message) {
        if (!$status.length) {
            return;
        }

        $status
            .removeClass('notice-success notice-error notice-info notice-warning')
            .addClass(type)
            .find('p')
            .text(message || '');

        if ($status.is(':hidden')) {
            $status.show();
        }
    }

    function updateStats($synced, $remaining) {
        $('.azinsanaat-manual-sync-synced').text($synced);
        $('.azinsanaat-manual-sync-remaining').text($remaining);
    }

    function renderErrors(errors) {
        var $errors = $('.azinsanaat-manual-sync-errors');
        if (!$errors.length) {
            return;
        }

        $errors.empty();
        if (!errors || !errors.length) {
            return;
        }

        var $list = $('<ul></ul>').addClass('azinsanaat-manual-sync-error-list');
        errors.forEach(function (message) {
            if (!message) {
                return;
            }
            $('<li></li>').text(message).appendTo($list);
        });

        $errors.append($list);
    }

    function resetState() {
        state.offset = 0;
        state.synced = 0;
        state.failed = 0;
        state.total = 0;
        state.running = true;
        updateStats(0, 0);
        renderErrors([]);
    }

    function requestNextBatch(connectionId, $status, $button, $spinner) {
        $.ajax({
            url: settings.ajaxUrl ? settings.ajaxUrl : window.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'azinsanaat_manual_sync_bulk',
                nonce: settings.nonce || '',
                connection_id: connectionId,
                offset: state.offset
            }
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                var fallback = (settings.messages && settings.messages.syncError) ? settings.messages.syncError : '';
                setStatus($status, 'notice-error', response && response.data && response.data.message ? response.data.message : fallback);
                state.running = false;
                return;
            }

            var data = response.data;
            if (typeof data.total === 'number') {
                state.total = data.total;
            }

            if (typeof data.synced === 'number') {
                state.synced += data.synced;
            }

            if (typeof data.failed === 'number') {
                state.failed += data.failed;
            }

            if (Array.isArray(data.errors) && data.errors.length) {
                renderErrors(data.errors);
            }

            if (typeof data.next_offset === 'number') {
                state.offset = data.next_offset;
            }

            var remaining = typeof data.remaining === 'number'
                ? data.remaining
                : Math.max(state.total - state.offset, 0);

            updateStats(state.synced, remaining);

            if (data.message) {
                setStatus($status, 'notice-info', data.message);
            } else if (settings.messages && settings.messages.syncing) {
                setStatus($status, 'notice-info', settings.messages.syncing);
            }

            if (data.done) {
                state.running = false;
                if (settings.messages && settings.messages.syncDone) {
                    setStatus($status, 'notice-success', settings.messages.syncDone);
                }
                return;
            }

            setTimeout(function () {
                requestNextBatch(connectionId, $status, $button, $spinner);
            }, 400);
        }).fail(function () {
            var fallback = (settings.messages && settings.messages.syncError) ? settings.messages.syncError : '';
            setStatus($status, 'notice-error', fallback);
            state.running = false;
        }).always(function () {
            if (!state.running) {
                $button.prop('disabled', false).removeAttr('aria-disabled');
                $spinner.removeClass('is-active');
            }
        });
    }

    $(document).ready(function () {
        var $button = $('#azinsanaat-manual-sync-start');
        var $select = $('#azinsanaat-manual-sync-connection');
        var $status = $('.azinsanaat-manual-sync-status');
        var $spinner = $('.azinsanaat-manual-sync-form .spinner');

        $button.on('click', function () {
            if (state.running) {
                return;
            }

            var connectionId = $select.val() || '';
            if (!connectionId) {
                var message = settings.messages && settings.messages.missingConnection ? settings.messages.missingConnection : '';
                setStatus($status, 'notice-warning', message);
                return;
            }

            resetState();
            $button.prop('disabled', true).attr('aria-disabled', 'true');
            $spinner.addClass('is-active');

            if (settings.messages && settings.messages.syncing) {
                setStatus($status, 'notice-info', settings.messages.syncing);
            }

            requestNextBatch(connectionId, $status, $button, $spinner);
        });
    });
})(jQuery);
