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
});
