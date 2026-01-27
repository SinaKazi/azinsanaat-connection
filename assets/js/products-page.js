(function ($) {
    'use strict';

    var settings = (typeof AzinsanaatProductsPage !== 'undefined') ? AzinsanaatProductsPage : { messages: {} };

    function renderMessage($container, type, message, editUrl) {
        var messages = settings.messages || {};
        $container
            .removeClass('notice notice-success notice-error')
            .addClass('notice inline')
            .addClass(type === 'success' ? 'notice-success' : 'notice-error')
            .empty();

        var $paragraph = $('<p></p>').text(message || '');
        $container.append($paragraph);

        if (type === 'success' && editUrl) {
            var $link = $('<a></a>')
                .attr('href', editUrl)
                .attr('target', '_blank')
                .attr('rel', 'noopener')
                .text(messages.editLinkLabel || '');

            var $linkParagraph = $('<p></p>').append($link);
            $container.append($linkParagraph);
        }
    }

    function renderProgress($container, steps) {
        $container.empty();

        if (!steps || !steps.length) {
            return;
        }

        var $list = $('<ol></ol>').addClass('azinsanaat-import-progress__list');

        steps.forEach(function (step) {
            var message = step && step.message ? step.message : '';
            if (!message) {
                return;
            }

            var $item = $('<li></li>')
                .addClass('azinsanaat-import-progress__item')
                .text(message);

            $list.append($item);
        });

        $container.append($list);
    }

    function updateNotice($container, type, message) {
        if (!$container || !$container.length) {
            return;
        }

        $container
            .removeClass('notice-success notice-error notice-warning notice-info')
            .addClass(type)
            .find('p')
            .text(message || '');
    }

    function normalizeBoolean(value) {
        if (value === true || value === false) {
            return value;
        }

        if (typeof value === 'number') {
            return value === 1;
        }

        return value === '1';
    }

    function initCacheRefresh() {
        var cacheSettings = settings.cache || {};
        var messages = settings.messages || {};
        var $cacheStatus = $('.azinsanaat-cache-status');
        var $cacheProgress = $('.azinsanaat-cache-progress');

        if (!$cacheStatus.length) {
            return;
        }

        var cacheExists = normalizeBoolean($cacheStatus.data('cacheExists'));
        var connectionId = $cacheStatus.data('connectionId') || '';
        var nonce = $cacheStatus.data('refreshNonce') || '';
        var clientError = $cacheStatus.data('clientError') || '';
        var steps = [];
        var pollInterval = cacheSettings.pollInterval || 800;

        if (messages.cacheChecking) {
            steps.push({ message: messages.cacheChecking });
        }

        if (cacheExists) {
            if (messages.cacheExists) {
                steps.push({ message: messages.cacheExists });
            }

            renderProgress($cacheProgress, steps);
            return;
        }

        if (messages.cacheMissing) {
            steps.push({ message: messages.cacheMissing });
        }

        renderProgress($cacheProgress, steps);

        if (!connectionId || !nonce || clientError) {
            updateNotice(
                $cacheStatus,
                'notice-error',
                clientError || messages.cacheRefreshError || ''
            );
            return;
        }

        function requestChunk() {
            $.ajax({
                url: settings.ajaxUrl ? settings.ajaxUrl : window.ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'azinsanaat_refresh_cache',
                    nonce: nonce,
                    connection_id: connectionId
                }
            }).done(function (response) {
                if (response && response.success && response.data) {
                    if (response.data.message) {
                        steps.push({ message: response.data.message });
                        renderProgress($cacheProgress, steps);
                    }

                    if (response.data.status === 'done') {
                        updateNotice($cacheStatus, 'notice-success', response.data.message || messages.cacheRefreshDone || '');

                        if (messages.cacheReloading) {
                            steps.push({ message: messages.cacheReloading });
                            renderProgress($cacheProgress, steps);
                        }

                        setTimeout(function () {
                            window.location.reload();
                        }, 800);
                    } else {
                        updateNotice($cacheStatus, 'notice-info', response.data.message || messages.cacheChecking || '');
                        setTimeout(requestChunk, pollInterval);
                    }
                } else {
                    var fallback = messages.cacheRefreshError || '';
                    var message = response && response.data && response.data.message ? response.data.message : fallback;
                    updateNotice($cacheStatus, 'notice-error', message);
                }
            }).fail(function () {
                updateNotice($cacheStatus, 'notice-error', messages.cacheRefreshError || '');
            });
        }

        updateNotice($cacheStatus, 'notice-warning', messages.cacheMissing || '');
        requestChunk();
    }

    $(document).on('submit', '.azinsanaat-import-form', function (event) {
        var $form = $(this);

        if ($form.find('.azinsanaat-import-button').is(':disabled')) {
            return;
        }

        event.preventDefault();

        var productId = parseInt($form.data('productId'), 10) || 0;
        var connectionId = $form.data('connectionId') || $form.find('input[name="connection_id"]').val() || '';
        var siteCategoryId = '';
        var importSections = [];
        var hasImportOptions = false;
        var $row = $form.closest('tr');
        var variationAttributes = {};
        var hasVariationRows = false;
        var hasSelectedVariation = false;
        var missingRequiredAttributes = false;

        if ($row.length) {
            var $categorySelect = $row.find('.azinsanaat-site-category-select');
            if ($categorySelect.length) {
                siteCategoryId = $categorySelect.val() || '';
            }

            var $importOptions = $row.find('.azinsanaat-import-options input[type="checkbox"]');
            if ($importOptions.length) {
                hasImportOptions = true;
                $importOptions.each(function () {
                    var $checkbox = $(this);
                    if ($checkbox.is(':checked')) {
                        importSections.push($checkbox.val());
                    }
                });
            }

            var $variationsRow = $row.next('.azinsanaat-product-variations-row');
            if ($variationsRow.length) {
                hasVariationRows = true;
                $variationsRow.find('.azinsanaat-product-variations-table tbody tr').each(function () {
                    var $variationRow = $(this);
                    var remoteVariationId = parseInt($variationRow.data('remoteVariationId'), 10) || 0;
                    if (!remoteVariationId) {
                        return;
                    }

                    variationAttributes[remoteVariationId] = variationAttributes[remoteVariationId] || {};
                    var attributes = variationAttributes[remoteVariationId];
                    var hasValueInRow = false;
                    var rowMissingAttributes = false;

                    $variationRow.find('.azinsanaat-variation-attribute').each(function () {
                        var $select = $(this);
                        var attributeKey = $select.data('attributeKey') || '';
                        var value = $select.val() || '';

                        if (!attributeKey) {
                            return;
                        }

                        attributes[attributeKey] = value;

                        if (value) {
                            hasValueInRow = true;
                        }
                    });

                    if (hasValueInRow) {
                        Object.keys(attributes).forEach(function (key) {
                            if (!attributes[key]) {
                                rowMissingAttributes = true;
                            }
                        });

                        if (rowMissingAttributes) {
                            missingRequiredAttributes = true;
                        } else {
                            hasSelectedVariation = true;
                        }
                    } else {
                        delete variationAttributes[remoteVariationId];
                    }
                });
            }
        }
        if (!productId) {
            return;
        }

        var $button = $form.find('.azinsanaat-import-button');
        var $spinner = $form.find('.spinner');
        var $feedback = $form.find('.azinsanaat-import-feedback');
        var $progress = $form.find('.azinsanaat-import-progress');
        var nonce = $form.find('input[name="_wpnonce"]').val();
        var success = false;

        if (hasVariationRows && missingRequiredAttributes) {
            renderMessage($feedback, 'error', settings.messages && settings.messages.missingAttributes ? settings.messages.missingAttributes : '');
            return;
        }

        if (hasVariationRows && !hasSelectedVariation) {
            renderMessage($feedback, 'error', settings.messages && settings.messages.selectAtLeastOneVariation ? settings.messages.selectAtLeastOneVariation : '');
            return;
        }

        $feedback.removeClass('notice notice-success notice-error').empty();
        $button.prop('disabled', true).attr('aria-disabled', 'true');
        if ($spinner.length) {
            $spinner.addClass('is-active');
        }

        renderProgress($progress, settings.messages && settings.messages.startingImport ? [
            { message: settings.messages.startingImport }
        ] : []);

        var requestData = {
            action: 'azinsanaat_import_product',
            product_id: productId,
            nonce: nonce,
            connection_id: connectionId,
            site_category_id: siteCategoryId,
            import_sections: importSections
        };

        if (hasImportOptions) {
            requestData.import_sections_submitted = 1;
        }

        if (hasVariationRows) {
            requestData.variation_attributes = variationAttributes;
        }

        $.ajax({
            url: settings.ajaxUrl ? settings.ajaxUrl : window.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: requestData
        }).done(function (response) {
            var steps = (response && response.data && response.data.steps) ? response.data.steps : [];
            if (response && response.success) {
                success = true;
                renderMessage($feedback, 'success', response.data.message, response.data.edit_url || '');
                renderProgress($progress, steps);
            } else {
                var fallbackMessages = settings.messages || {};
                var message = response && response.data && response.data.message ? response.data.message : fallbackMessages.genericError || '';
                renderMessage($feedback, 'error', message);
                renderProgress($progress, steps);
            }
        }).fail(function () {
            var fallback = (settings.messages && settings.messages.networkError) ? settings.messages.networkError : '';
            renderMessage($feedback, 'error', fallback || '');
        }).always(function () {
            if ($spinner.length) {
                $spinner.removeClass('is-active');
            }

            if (!success) {
                $button.prop('disabled', false).removeAttr('aria-disabled');
            }
        });
    });

    $(document).ready(function () {
        initCacheRefresh();
    });

})(jQuery);
