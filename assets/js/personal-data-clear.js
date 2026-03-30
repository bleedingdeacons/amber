(function ($) {
    'use strict';

    if (typeof acf === 'undefined') {
        return;
    }

    /**
     * Whether the current user has the edit-personal-data capability.
     * When false the Clear buttons are rendered but disabled.
     */
    var canEdit = typeof amberPersonalData !== 'undefined' && !!amberPersonalData.canEdit;

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
            disabled: !canEdit,
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

        // Track whether the Clear button initiated the change so the
        // field-state monitor (below) can tell legitimate clears apart
        // from manual edits that empty the field.
        var clearButtonClicked = false;

        // Remember the last known good value so we can restore it when
        // the field is emptied without using the Clear button.
        var previousValue = $input.val();

        $(document).on('click', '#' + buttonId, function () {
            if (storedValue !== null) {
                // Undo — restore the stored value.
                clearButtonClicked = true;
                $input.val(storedValue).trigger('change');
                previousValue = storedValue;
                storedValue = null;
                $button.text('Clear');
            } else {
                // Clear — confirm, store and wipe.
                if (window.confirm(confirmMsg)) {
                    storedValue = $input.val();
                    clearButtonClicked = true;
                    $input.val('').trigger('change');
                    previousValue = '';
                    $button.text('Undo');
                }
            }

            return false;
        });

        // Monitor field state: if the value becomes empty through any
        // means other than the Clear button, restore the previous value.
        $input.on('change', function () {
            if (clearButtonClicked) {
                clearButtonClicked = false;
                return;
            }

            var currentValue = $input.val();

            if (currentValue === '' && previousValue !== '') {
                // Field was emptied without using the Clear button —
                // restore the previous value silently.
                $input.val(previousValue);
            } else {
                // Non-empty update (e.g. correcting a typo) — track it.
                previousValue = currentValue;
            }
        });
    }

    acf.addAction('ready', function () {
        attachClearButton('personal-email', 'personal-email-clear', 'This will clear the email permanently. Continue?');
        attachClearButton('mobile-number', 'mobile-number-clear', 'This will clear the mobile number permanently. Continue?');
    });
})(jQuery);