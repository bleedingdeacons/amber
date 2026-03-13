/**
 * ACF Anonymous Name Uniqueness Validator
 *
 * Provides real-time AJAX validation on the `anonymous-name` sub-field
 * inside the `about-layout-group` ACF group on the intergroup-member
 * post type.  Debounces input and shows an inline error when the
 * value already belongs to another member.
 */
(function ($) {
    'use strict';

    if (typeof acf === 'undefined') {
        return;
    }

    var debounceTimer;
    var msgClass = 'amber-anon-name-msg';

    /**
     * Fires when the anonymous-name field is ready in the DOM.
     * ACF resolves sub-fields by their own name, not the parent
     * group prefix, so `ready_field/name=anonymous-name` works.
     */
    acf.addAction('ready_field/name=anonymous-name', function (field) {
        var $input = field.$input();

        $input.on('input', function () {
            clearTimeout(debounceTimer);

            // Remove any previous message.
            field.$el.find('.' + msgClass).remove();

            var value = $.trim($input.val());

            if (!value) {
                return;
            }

            debounceTimer = setTimeout(function () {
                $.ajax({
                    url: amberAnonName.ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'amber_validate_anonymous_name',
                        nonce: amberAnonName.nonce,
                        value: value,
                        post_id: amberAnonName.post_id,
                    },
                    success: function (response) {
                        // Clear stale messages (another keystroke may have fired).
                        field.$el.find('.' + msgClass).remove();

                        if (!response.success) {
                            return;
                        }

                        if (!response.data.valid) {
                            field.$el.append(
                                '<div class="' + msgClass + '" style="color:#d63638;margin-top:4px;font-style:italic;">' +
                                response.data.message +
                                '</div>'
                            );
                        }
                    },
                });
            }, 500);
        });
    });
})(jQuery);