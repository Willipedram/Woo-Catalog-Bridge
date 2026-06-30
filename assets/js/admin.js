(function ($) {
    'use strict';

    function showNotice(message, type) {
        var $notice = $('#wcb-admin-notice');
        $notice.removeClass('notice-success notice-error notice-info').addClass('notice-' + type);
        $notice.find('p').text(message);
        $notice.slideDown(150);
    }

    function updateStats(stats) {
        $.each(stats, function (key, value) {
            $('[data-stat="' + key + '"]').text(value);
        });
    }

    function refreshStats() {
        if (!$('[data-stat]').length) {
            return;
        }
        $.post(WCBAdmin.ajaxUrl, {
            action: 'wcb_dashboard_stats',
            nonce: WCBAdmin.nonce
        }).done(function (response) {
            if (response.success) {
                updateStats(response.data);
            }
        });
    }

    $(document).on('click', '.wcb-ajax-action', function (event) {
        event.preventDefault();
        var $button = $(this);
        var originalText = $button.text();
        $button.prop('disabled', true).text(WCBAdmin.i18n.working);
        $button.closest('.wcb-panel').find('.spinner').addClass('is-active');

        $.post(WCBAdmin.ajaxUrl, {
            action: 'wcb_run_action',
            nonce: WCBAdmin.nonce,
            task: $button.data('task')
        }).done(function (response) {
            if (response.success) {
                showNotice(response.data.message || WCBAdmin.i18n.done, 'success');
                if (response.data.stats) {
                    updateStats(response.data.stats);
                }
            } else {
                showNotice((response.data && response.data.message) || WCBAdmin.i18n.failed, 'error');
            }
        }).fail(function () {
            showNotice(WCBAdmin.i18n.failed, 'error');
        }).always(function () {
            $button.prop('disabled', false).text(originalText);
            $button.closest('.wcb-panel').find('.spinner').removeClass('is-active');
        });
    });

    refreshStats();
})(jQuery);
