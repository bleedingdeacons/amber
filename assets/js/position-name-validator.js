/**
 * ACF Position Name Uniqueness Validator
 *
 * Real-time AJAX validation on the `position-long-name` field
 * on the intergroup-position post type.
 */
(function ($) {
    'use strict';

    if (typeof acf === 'undefined' || typeof amberPositionName === 'undefined') {
        return;
    }

    var cfg = amberPositionName;
    var msgClass = 'amber-position-name-msg';

    function attachValidator(field) {
        var $input = field.$input();

        if ($input.data('amber-unique-bound')) {
            return;
        }
        $input.data('amber-unique-bound', true);

        var debounceTimer;

        $input.on('input', function () {
            clearTimeout(debounceTimer);
            field.$el.find('.' + msgClass).remove();

            var value = $.trim($input.val());

            if (!value) {
                return;
            }

            debounceTimer = setTimeout(function () {
                $.ajax({
                    url: cfg.ajaxurl,
                    method: 'POST',
                    data: {
                        action:  'amber_validate_position_name',
                        nonce:   cfg.nonce,
                        value:   value,
                        post_id: cfg.post_id,
                    },
                    success: function (response) {
                        field.$el.find('.' + msgClass).remove();

                        if (response.success && !response.data.valid) {
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
    }

    acf.addAction('ready_field/name=position-long-name', function (field) {
        attachValidator(field);
    });

    acf.addAction('ready_field/key=field_66720958da8b5', function (field) {
        attachValidator(field);
    });
})(jQuery);