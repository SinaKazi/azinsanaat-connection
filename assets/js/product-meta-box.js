(function ($) {
    'use strict';

    function createNotice(type, message) {
        return $('<div/>', {
            'class': 'notice notice-' + type,
            html: $('<p/>', { text: message })
        });
    }

    function formatVariationRow(variation, localVariations) {
        var $row = $('<tr/>');
        $row.append($('<td/>', { text: variation.id || '—' }));
        $row.append($('<td/>', { text: variation.attributes || '—' }));
        $row.append($('<td/>', { text: variation.price || variation.regular_price || '—' }));
        $row.append($('<td/>', { text: variation.stock_status || '—' }));
        $row.append($('<td/>', { text: variation.stock_quantity !== null && variation.stock_quantity !== undefined ? variation.stock_quantity : '—' }));

        var $select = $('<select/>', {
            'class': 'azinsanaat-variation-select',
            'data-remote-id': variation.id
        });

        $select.append($('<option/>', {
            value: '',
            text: AzinsanaatProductMeta.strings.selectVariationPlaceholder
        }));

        localVariations.forEach(function (localVariation) {
            var label = localVariation.name;
            if (localVariation.remote_id) {
                label += ' — #' + localVariation.remote_id;
            }

            $select.append($('<option/>', {
                value: localVariation.id,
                text: label,
                selected: parseInt(localVariation.id, 10) === parseInt(variation.connected_variation_id || 0, 10)
            }));
        });

        $row.append($('<td/>').append($select));
        return $row;
    }

    $(function () {
        if (typeof AzinsanaatProductMeta === 'undefined') {
            return;
        }

        var $metaBox = $('#azinsanaat-product-meta-box');
        if (!$metaBox.length) {
            return;
        }

        var productId = parseInt(AzinsanaatProductMeta.productId || 0, 10);
        var $input = $metaBox.find('#azinsanaat-remote-product-id');
        var $messages = $metaBox.find('.azinsanaat-meta-messages');
        var $results = $metaBox.find('.azinsanaat-meta-results');
        var $staticInfo = $metaBox.find('.azinsanaat-meta-static');
        var $dynamic = $metaBox.find('.azinsanaat-meta-dynamic');

        function clearMessages() {
            $messages.empty();
        }

        function addMessage(type, text) {
            clearMessages();
            $messages.append(createNotice(type, text));
        }

        function renderSimpleResult(data) {
            var html = '';
            html += '<p><strong>' + (data.product.name || '') + '</strong></p>';
            html += '<p>' + 'شناسه وب‌سرویس: ' + (data.remote_id || '—') + '</p>';
            if (data.product.price || data.product.regular_price) {
                html += '<p>' + (data.product.price || data.product.regular_price) + '</p>';
            }
            if (data.product.stock_status) {
                html += '<p>' + data.product.stock_status + '</p>';
            }

            var $container = $('<div/>', { html: html });
            var $button = $('<button/>', {
                'class': 'button button-primary azinsanaat-connect-simple',
                text: AzinsanaatProductMeta.strings.connectSimple,
                'data-remote-id': data.remote_id
            });

            $dynamic.empty().append($container).append($button);
        }

        function renderVariationsResult(data) {
            var localVariations = data.local_variations || [];
            var remoteVariations = data.remote_variations || [];

            if (!localVariations.length) {
                $dynamic.empty().append($('<p/>', {
                    'class': 'description',
                    text: AzinsanaatProductMeta.strings.noVariationsFound
                }));
                return;
            }

            var $table = $('<table/>');
            var $thead = $('<thead/>').append('<tr>' +
                '<th>ID</th>' +
                '<th>ویژگی‌ها</th>' +
                '<th>قیمت</th>' +
                '<th>موجودی</th>' +
                '<th>تعداد</th>' +
                '<th>متغیر ووکامرس</th>' +
            '</tr>');
            $table.append($thead);
            var $tbody = $('<tbody/>');

            remoteVariations.forEach(function (variation) {
                $tbody.append(formatVariationRow(variation, localVariations));
            });

            $table.append($tbody);

            var $button = $('<button/>', {
                'class': 'button button-primary azinsanaat-save-variations',
                text: AzinsanaatProductMeta.strings.saveVariations,
                'data-remote-id': data.remote_id
            });

            $dynamic.empty()
                .append($('<p/>', { html: '<strong>' + (data.product.name || '') + '</strong>' }))
                .append($('<p/>', { text: 'شناسه وب‌سرویس: ' + (data.remote_id || '—') }))
                .append($table)
                .append($button);
        }

        function renderResult(data) {
            if (data.remote_id) {
                $input.val(data.remote_id);
            }

            var currentId = data.current_remote_id || 0;
            var $currentRemote = $staticInfo.find('.azinsanaat-current-remote-id');
            if ($currentRemote.length) {
                if (currentId) {
                    $currentRemote.text(currentId);
                } else {
                    $currentRemote.text('—');
                }
            }

            if (data.last_sync) {
                var $lastSync = $staticInfo.find('.azinsanaat-last-sync span');
                if ($lastSync.length) {
                    $lastSync.text(data.last_sync);
                }
            }

            if (!data.last_sync) {
                var $syncSpan = $staticInfo.find('.azinsanaat-last-sync span');
                if ($syncSpan.length) {
                    $syncSpan.text('—');
                }
            }

            if (data.is_variable) {
                renderVariationsResult(data);
            } else {
                renderSimpleResult(data);
            }
        }

        function disableButton($button, disabled) {
            $button.prop('disabled', !!disabled);
        }

        $metaBox.on('click', '.azinsanaat-fetch-button', function () {
            if (!productId) {
                addMessage('warning', AzinsanaatProductMeta.strings.missingProduct);
                return;
            }

            var remoteId = parseInt($input.val(), 10);
            if (!remoteId) {
                addMessage('error', AzinsanaatProductMeta.strings.invalidRemote);
                return;
            }

            var $button = $(this);
            disableButton($button, true);
            addMessage('info', AzinsanaatProductMeta.strings.fetching);

            $.ajax({
                url: AzinsanaatProductMeta.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'azinsanaat_fetch_remote_product',
                    nonce: AzinsanaatProductMeta.nonce,
                    product_id: productId,
                    remote_id: remoteId
                }
            }).done(function (response) {
                if (response.success) {
                    renderResult(response.data);
                    clearMessages();
                } else {
                    addMessage('error', (response.data && response.data.message) || AzinsanaatProductMeta.strings.error);
                }
            }).fail(function () {
                addMessage('error', AzinsanaatProductMeta.strings.error);
            }).always(function () {
                disableButton($button, false);
            });
        });

        $metaBox.on('click', '.azinsanaat-connect-simple', function () {
            var remoteId = parseInt($(this).data('remote-id'), 10);
            var $button = $(this);

            if (!remoteId) {
                addMessage('error', AzinsanaatProductMeta.strings.invalidRemote);
                return;
            }

            disableButton($button, true);

            $.ajax({
                url: AzinsanaatProductMeta.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'azinsanaat_connect_simple_product',
                    nonce: AzinsanaatProductMeta.nonce,
                    product_id: productId,
                    remote_id: remoteId
                }
            }).done(function (response) {
                if (response.success) {
                    $input.val(remoteId);
                    addMessage('success', AzinsanaatProductMeta.strings.success);
                    if (response.data && response.data.last_sync) {
                        var $lastSync = $staticInfo.find('.azinsanaat-last-sync span');
                        if ($lastSync.length) {
                            $lastSync.text(response.data.last_sync);
                        }
                    }
                    var $currentRemote = $staticInfo.find('.azinsanaat-current-remote-id');
                    if ($currentRemote.length) {
                        $currentRemote.text(remoteId);
                    }
                } else {
                    addMessage('error', (response.data && response.data.message) || AzinsanaatProductMeta.strings.error);
                }
            }).fail(function () {
                addMessage('error', AzinsanaatProductMeta.strings.error);
            }).always(function () {
                disableButton($button, false);
            });
        });

        $metaBox.on('click', '.azinsanaat-save-variations', function () {
            var $button = $(this);
            var remoteId = parseInt($button.data('remote-id'), 10);
            if (!remoteId) {
                addMessage('error', AzinsanaatProductMeta.strings.invalidRemote);
                return;
            }

            var mappings = {};
            var duplicate = false;
            var selectedLocalIds = [];

            $results.find('.azinsanaat-variation-select').each(function () {
                var $select = $(this);
                var remoteVariationId = parseInt($select.data('remote-id'), 10);
                var localId = parseInt($select.val(), 10);

                if (!remoteVariationId || !localId) {
                    return;
                }

                if (selectedLocalIds.indexOf(localId) !== -1) {
                    duplicate = true;
                    return false;
                }

                selectedLocalIds.push(localId);
                mappings[remoteVariationId] = localId;
            });

            if (duplicate) {
                addMessage('error', AzinsanaatProductMeta.strings.duplicateVariation);
                return;
            }

            if (!Object.keys(mappings).length) {
                addMessage('error', AzinsanaatProductMeta.strings.noMappings);
                return;
            }

            disableButton($button, true);

            $.ajax({
                url: AzinsanaatProductMeta.ajaxUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'azinsanaat_connect_product_variations',
                    nonce: AzinsanaatProductMeta.nonce,
                    product_id: productId,
                    remote_id: remoteId,
                    mappings: mappings
                }
            }).done(function (response) {
                if (response.success) {
                    $input.val(remoteId);
                    addMessage('success', AzinsanaatProductMeta.strings.success);
                    if (response.data && response.data.last_sync) {
                        var $lastSync = $staticInfo.find('.azinsanaat-last-sync span');
                        if ($lastSync.length) {
                            $lastSync.text(response.data.last_sync);
                        }
                    }
                    var $currentRemote = $staticInfo.find('.azinsanaat-current-remote-id');
                    if ($currentRemote.length) {
                        $currentRemote.text(remoteId);
                    }
                } else {
                    addMessage('error', (response.data && response.data.message) || AzinsanaatProductMeta.strings.error);
                }
            }).fail(function () {
                addMessage('error', AzinsanaatProductMeta.strings.error);
            }).always(function () {
                disableButton($button, false);
            });
        });
    });
})(jQuery);
