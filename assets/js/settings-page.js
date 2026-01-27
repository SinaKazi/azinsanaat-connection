(function ($) {
    'use strict';

    $(function () {
        var settings = window.AzinsanaatSettingsPage || {};
        var messages = settings.messages || {};
        var cacheRefresh = settings.cacheRefresh || {};
        var cacheClear = settings.cacheClear || {};
        var $container = $('#azinsanaat-connections-container');
        var template = $('#azinsanaat-connection-template').html();
        var $addButton = $('#azinsanaat-add-connection');

        function generateKey() {
            var random = Math.floor(Math.random() * 1000000).toString(36);
            return 'conn_' + Date.now().toString(36) + '_' + random;
        }

        function ensurePlaceholder() {
            if (!$container.length) {
                return;
            }

            if ($container.find('.azinsanaat-connection-item').length) {
                $container.find('.azinsanaat-no-connections').remove();
                return;
            }

            var message = messages.noConnections || '';
            if (!message) {
                return;
            }

            if (!$container.find('.azinsanaat-no-connections').length) {
                $('<p/>', {
                    'class': 'description azinsanaat-no-connections',
                    text: message
                }).appendTo($container);
            }
        }

        if ($addButton.length) {
            $addButton.on('click', function (event) {
                event.preventDefault();

                if (!template || !$container.length) {
                    return;
                }

                var key = generateKey();
                var html = template.replace(/__key__/g, key);
                var $item = $(html);

                $container.find('.azinsanaat-no-connections').remove();
                $container.append($item);
            });
        }

        if ($container.length) {
            $container.on('click', '.azinsanaat-remove-connection', function (event) {
                event.preventDefault();
                $(this).closest('.azinsanaat-connection-item').remove();
                ensurePlaceholder();
            });
        }

        function ensureCacheNotice($box) {
            var $notice = $box.find('.azinsanaat-cache-refresh-notice');
            if ($notice.length) {
                return $notice;
            }

            $notice = $('<div/>', {
                'class': 'notice inline azinsanaat-cache-refresh-notice',
            }).append($('<p/>'));

            $box.append($notice);

            return $notice;
        }

        function setCacheNotice($box, type, message) {
            var $notice = ensureCacheNotice($box);
            var $message = $notice.find('p');

            $notice.removeClass('notice-success notice-error notice-info notice-warning')
                .addClass('notice-' + type);

            $message.text(message);
        }

        function ensureCacheSpinner($box) {
            var $spinner = $box.find('.azinsanaat-cache-refresh-spinner');
            if ($spinner.length) {
                return $spinner;
            }

            $spinner = $('<span/>', {
                'class': 'spinner azinsanaat-cache-refresh-spinner',
            });

            $box.find('.azinsanaat-cache-refresh-actions').append($spinner);

            return $spinner;
        }

        function runCacheRefresh($button, connectionId, action, config) {
            var $box = $button.closest('.azinsanaat-connection-cache');
            var $spinner = ensureCacheSpinner($box);
            var pollInterval = config.pollInterval || 800;

            $button.prop('disabled', true).attr('aria-disabled', 'true');
            $spinner.addClass('is-active');

            function finalize() {
                $spinner.removeClass('is-active');
                $button.prop('disabled', false).attr('aria-disabled', 'false');
            }

            function makeRequest() {
                $.post(config.ajaxUrl, {
                    action: action,
                    connection_id: connectionId,
                    nonce: config.nonce
                })
                    .done(function (response) {
                        if (!response || !response.success) {
                            var errorMessage = config.messages && config.messages.error
                                ? config.messages.error
                                : 'خطا در به‌روزرسانی کش.';

                            if (response && response.data && response.data.message) {
                                errorMessage = response.data.message;
                            }

                            setCacheNotice($box, 'error', errorMessage);
                            finalize();
                            return;
                        }

                        var data = response.data || {};
                        if (data.status === 'in_progress') {
                            var progressMessage = data.message || (config.messages && config.messages.inProgress) || '';
                            if (progressMessage) {
                                setCacheNotice($box, 'info', progressMessage);
                            }
                            window.setTimeout(makeRequest, pollInterval);
                            return;
                        }

                        var doneMessage = data.message || (config.messages && config.messages.done) || '';
                        if (doneMessage) {
                            setCacheNotice($box, data.type || 'success', doneMessage);
                        }
                        finalize();
                    })
                    .fail(function () {
                        var errorMessage = config.messages && config.messages.error
                            ? config.messages.error
                            : 'خطا در به‌روزرسانی کش.';
                        setCacheNotice($box, 'error', errorMessage);
                        finalize();
                    });
            }

            makeRequest();
        }

        if (cacheRefresh.ajaxUrl && cacheRefresh.nonce) {
            $(document).on('click', '.azinsanaat-cache-refresh-actions button', function (event) {
                var $button = $(this);
                var connectionId = $button.data('connectionId');

                if (!connectionId || $button.hasClass('azinsanaat-cache-clear')) {
                    return;
                }

                event.preventDefault();
                runCacheRefresh($button, connectionId, 'azinsanaat_refresh_cache', cacheRefresh);
            });
        }

        if (cacheClear.ajaxUrl && cacheClear.nonce) {
            $(document).on('click', '.azinsanaat-cache-refresh-actions .azinsanaat-cache-clear', function (event) {
                var $button = $(this);
                var connectionId = $button.data('connectionId');

                if (!connectionId) {
                    return;
                }

                event.preventDefault();
                runCacheRefresh($button, connectionId, 'azinsanaat_clear_cache', cacheClear);
            });
        }

        ensurePlaceholder();
    });
})(jQuery);
