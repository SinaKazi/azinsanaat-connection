(function ($) {
    'use strict';

    $(function () {
        var settings = window.AzinsanaatSettingsPage || {};
        var messages = settings.messages || {};
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

        ensurePlaceholder();
    });
})(jQuery);
