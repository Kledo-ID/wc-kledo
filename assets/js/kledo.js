/**
 * Copyright (c) Kledo Software. All Rights Reserved
 */

jQuery(document).ready(function ($) {
    /**
     * Toggles availability of input in setting groups.
     *
     * @param {boolean} enable whether fields in this group should be enabled or not.
     */
    function toggleSettingOptions (enable) {
        $('.wc-kledo-field').each(function () {
            let $element = $(this);

            if (enable) {
                isUsingDisableProperty($element)
                    ? $element.prop('disabled', false)
                    : $element.css('pointer-events', 'all').css('opacity', '1.0');
            } else {
                isUsingDisableProperty($element)
                    ? $element.prop('disabled', true)
                    : $element.css('pointer-events', 'none').css('opacity', '0.4');
            }
        });
    }

    /**
     * Check if object using disable property.
     *
     * @param {object} object
     */
    function isUsingDisableProperty(object) {
        return object.hasClass('select2-hidden-accessible') || object.is(':checkbox');
    }

    // Toggle availability of payment account.
    if (!$('form.wc-kledo-settings').hasClass('disconnected')) {
        let $invoiceStatus = $('select.wc-kledo-invoice-status-field');

        if ($invoiceStatus.length) {
            $($invoiceStatus).on('change', function (e) {
                let $element = $('.wc-kledo-payment-account-field');

                if ($element.length) {
                    let status = $(this).val();

                    status === 'paid'
                        ? $element.prop('disabled', false)
                        : $element.prop('disabled', true);
                }
            }).trigger('change');
        }
    }

    /**
     * Keep "Close Sales Order When Invoiced" tied to "Link Invoice to Sales Order".
     *
     * Kledo closes a sales order by way of the invoice attached to it, so with linking off there
     * is no attachment and nothing to close. Leaving the dependent box tickable lets a shop turn
     * on a behaviour that cannot happen and then wait for a closure that never comes.
     *
     * The box is cleared as well as disabled so the form shows what will actually be saved: a
     * disabled checkbox is not submitted, and WooCommerce stores any absent checkbox as "no".
     * Showing it ticked-but-greyed would promise a value the save is about to discard.
     */
    let $linkOrder = $('input.wc-kledo-link-order-field');
    let $closeOrder = $('input.wc-kledo-close-order-field');

    if ($linkOrder.length && $closeOrder.length) {
        let $closeOrderRow = $closeOrder.closest('tr');

        $linkOrder.on('change', function () {
            let linked = $(this).is(':checked');

            if (linked) {
                $closeOrder.prop('disabled', false);
            } else {
                $closeOrder.prop('checked', false).prop('disabled', true);
            }

            // Dim the whole row, label included, so the reason the box cannot be ticked reads as
            // "this does not apply right now" rather than as a broken control.
            $closeOrderRow.css('opacity', linked ? '1.0' : '0.5');
        }).trigger('change');
    }

    /**
     * Select2 ajax call.
     *
     * @param {string} element The select field.
     * @param {string} action The ajax action name.
     * @param {string} placeholder The select2 placeholder.
     * @param {int} minimumResultsForSearch The select2 minimum result for search.
     */
    function wp_ajax(element, action, placeholder, minimumResultsForSearch = 1) {
        let $element = $(element);

        if ($element.length) {
            $element.selectWoo({
                placeholder: wc_kledo.i18n[placeholder],
                minimumResultsForSearch: minimumResultsForSearch,
                ajax: {
                    url: wc_kledo.ajax_url,
                    delay: 250,
                    type: 'POST',
                    dataType: 'json',
                    data: function (params) {
                        return {
                            action: action,
                            security: wc_kledo.security,
                            keyword: params.term,
                            page: params.page || 1,
                        };
                    },
                    processResults: function (data, params) {
                        params.page = params.page || 1;

                        return {
                            results: data.items,
                            pagination: {
                                more: (params.page * 10) < data.total,
                            },
                        };
                    },
                    cache: true,
                },
                language: {
                    errorLoading: function () {
                        return wc_kledo.i18n.error_loading;
                    },
                    loadingMore: function () {
                        return wc_kledo.i18n.loading_more;
                    },
                    noResults: function () {
                        return wc_kledo.i18n.no_result;
                    },
                    searching: function () {
                        return wc_kledo.i18n.searching;
                    },
                    search: function () {
                        return wc_kledo.i18n.search;
                    },
                },
            });
        }
    }

    // Payment Account.
    wp_ajax('.wc-kledo-payment-account-field', 'wc_kledo_payment_account', 'payment_account_placeholder');

    // Warehouse.
    wp_ajax('.wc-kledo-warehouse-field', 'wc_kledo_warehouse', 'warehouse_placeholder', -1);

    // Tags.
    let $wc_kledo_tags = $('.wc-kledo-tags-field');

    if ($wc_kledo_tags.length) {
        $wc_kledo_tags.selectWoo({
            tags: true,
            tokenSeparators: [',']
        });
    }

    // Disable field if connection status disconnected.
    if ($('form.wc-kledo-settings').hasClass('disconnected')) {
        toggleSettingOptions(false);
    }
});
