(function ($) {
    'use strict';

    if (typeof acf === 'undefined') {
        return;
    }

    /**
     * Attach a "Clear" button to an ACF field. When cleared the button
     * becomes "Undo" and restores the previous value if clicked again.
     *
     * @param {string} fieldName   ACF data-name attribute (e.g. "personal-email").
     * @param {string} buttonId    HTML id for the button.
     * @param {string} confirmMsg  Text shown in the confirmation dialog.
     */
    function attachClearButton(fieldName, buttonId, confirmMsg) {
        var $wrapper = $('.acf-field[data-name="' + fieldName + '"]');
        if (!$wrapper.length) {
            return;
        }

        // Bail if the button has already been injected (ACF may fire ready more than once).
        if ($('#' + buttonId).length) {
            return;
        }

        // The input may be type="email", "tel" or "text" depending on
        // the field type and whether Scrutiny has wrapped the field.
        var $input = $wrapper.find('input').not('[type="hidden"]').first();
        if (!$input.length) {
            return;
        }

        // Build the clear button matching the input height.
        var $button = $('<button/>', {
            type: 'button',
            id: buttonId,
            'class': 'button',
            text: 'Clear',
            css: {
                'flex': '0 0 auto',
                'height': $input.outerHeight() + 'px',
                'line-height': '1',
                'box-sizing': 'border-box'
            }
        });

        // Wrap the input's immediate parent and the button in a flex container
        // so they sit side-by-side regardless of ACF's internal markup.
        var $inputParent = $input.parent();
        $inputParent.css('flex', '1 1 auto');
        $inputParent.wrap('<div style="display:flex;align-items:center;gap:8px;"></div>');
        $inputParent.after($button);

        var storedValue = null;

        $(document).on('click', '#' + buttonId, function () {
            if (storedValue !== null) {
                // Undo — restore the stored value.
                $input.val(storedValue).trigger('change');
                storedValue = null;
                $button.text('Clear');
            } else {
                // Clear — confirm, store and wipe.
                if (window.confirm(confirmMsg)) {
                    storedValue = $input.val();
                    $input.val('').trigger('change');
                    $button.text('Undo');
                }
            }

            return false;
        });
    }

    acf.addAction('ready', function () {
        attachClearButton('personal-email', 'personal-email-clear', 'This will clear the email permanently. Continue?');
        attachClearButton('mobile-number', 'mobile-number-clear', 'This will clear the mobile number permanently. Continue?');
    });
})(jQuery);