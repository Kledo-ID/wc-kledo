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
        $('.invoice-field').each(function () {
            let $element = $(this);

            if (enable) {
                $element.css('pointer-events', 'all').css('opacity', '1.0');
            } else {
                $element.css('pointer-events', 'none').css('opacity', '0.4');
            }
        });
    }

    // Disable field if connection status disconnected.
    if ($('form.wc-kledo-settings').hasClass('disconnected')) {
        toggleSettingOptions(false);
    }

    // Toggle availability of payment account.
    $('select#wc_kledo_invoice_status').on('change', function (e) {
        const $element = $('.payment-account-field');
        let status = $(this).val();

        if (status === 'paid') {
            $element.prop('disabled', false);

            return;
        }

        $element.prop('disabled', true);
    }).trigger('change');


    /**
     * Select2 ajax call.
     *
     * @param {string} element The select field.
     * @param {string} action The ajax action name.
     */
    function wp_ajax(element, action) {
        $(element).selectWoo({
            placeholder: wc_kledo_invoice.i18n.payment_account_placeholder,
            ajax: {
                url: wc_kledo_invoice.ajax_url,
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
                    return wc_kledo_invoice.i18n.error_loading;
                },
                loadingMore: function () {
                    return wc_kledo_invoice.i18n.loading_more;
                },
                noResults: function () {
                    return wc_kledo_invoice.i18n.no_result;
                },
                searching: function () {
                    return wc_kledo_invoice.i18n.searching;
                },
                search: function () {
                    return wc_kledo_invoice.i18n.search;
                },
            },
        });
    }

    // Payment Account.
    wp_ajax('#wc_kledo_invoice_payment_account', 'wc_kledo_payment_account');

    // Warehouse.
    wp_ajax('#wc_kledo_warehouse', 'wc_kledo_warehouse');
});
