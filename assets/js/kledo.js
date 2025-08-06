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

    // Disable field if connection status disconnected.
    if ($('form.wc-kledo-settings').hasClass('disconnected')) {
        toggleSettingOptions(false);
    }
});
