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

    function normalizeSearchText(value) {
        if (value === null || typeof value === 'undefined') {
            return '';
        }

        return String(value).trim().toLowerCase();
    }

    function initLocalSearch() {
        var $table = $('.azinsanaat-products-table');
        var $searchInput = $('.azinsanaat-products-search-input');
        var $searchButton = $('.azinsanaat-products-search-button');

        if (!$table.length || !$searchInput.length) {
            return;
        }

        var $productRows = $table.find('tbody > tr').not('.azinsanaat-product-variations-row');
        if (!$productRows.length) {
            return;
        }

        var $emptyState = $('.azinsanaat-products-search-empty');

        $productRows.each(function () {
            var $row = $(this);
            var curatedText = $row.data('searchText');

            if (typeof curatedText === 'undefined') {
                curatedText = $row.text();
            }

            $row.data('azinsanaatSearchText', normalizeSearchText(curatedText));
        });

        function applySearch(query) {
            var visibleCount = 0;

            $productRows.each(function () {
                var $row = $(this);
                var $variationsRow = $row.next('.azinsanaat-product-variations-row');
                var rowText = $row.data('azinsanaatSearchText') || '';
                var matches = !query || rowText.indexOf(query) !== -1;

                if (matches) {
                    $row.show();
                    if ($variationsRow.length) {
                        $variationsRow.show();
                    }
                    visibleCount++;
                } else {
                    $row.hide();
                    if ($variationsRow.length) {
                        $variationsRow.hide();
                    }
                }
            });

            if ($emptyState.length) {
                if (query && visibleCount === 0) {
                    $emptyState.show();
                } else {
                    $emptyState.hide();
                }
            }
        }

        $searchInput.on('input', function () {
            applySearch(normalizeSearchText($(this).val()));
        });

        if ($searchButton.length) {
            $searchButton.on('click', function (event) {
                event.preventDefault();
                applySearch(normalizeSearchText($searchInput.val()));
            });
        }

        $searchInput.on('keypress', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                applySearch(normalizeSearchText($searchInput.val()));
            }
        });
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
        }
        if (!productId) {
            return;
        }

        var $button = $form.find('.azinsanaat-import-button');
        var $spinner = $form.find('.spinner');
        var $feedback = $form.find('.azinsanaat-import-feedback');
        var nonce = $form.find('input[name="_wpnonce"]').val();
        var success = false;

        $feedback.removeClass('notice notice-success notice-error').empty();
        $button.prop('disabled', true).attr('aria-disabled', 'true');
        if ($spinner.length) {
            $spinner.addClass('is-active');
        }

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

        $.ajax({
            url: settings.ajaxUrl ? settings.ajaxUrl : window.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: requestData
        }).done(function (response) {
            if (response && response.success) {
                success = true;
                renderMessage($feedback, 'success', response.data.message, response.data.edit_url || '');
            } else {
                var fallbackMessages = settings.messages || {};
                var message = response && response.data && response.data.message ? response.data.message : fallbackMessages.genericError || '';
                renderMessage($feedback, 'error', message);
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

    $(function () {
        initLocalSearch();
    });
})(jQuery);
