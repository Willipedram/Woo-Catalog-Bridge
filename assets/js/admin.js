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

    function updateProgress($panel, progress) {
        if (!progress) {
            return;
        }
        var percent = progress.percent || 0;
        $panel.find('[data-progress-wrap]').show();
        $panel.find('[data-progress-bar]').css('width', percent + '%');
        $panel.find('[data-progress-text]').text(
            progress.processed_sitemaps + '/' + progress.total_sitemaps + ' sitemaps — ' +
            progress.total_items + ' items — ' + percent + '%'
        );
    }

    function runAction($button, originalText) {
        var $panel = $button.closest('.wcb-panel');
        $.post(WCBAdmin.ajaxUrl, {
            action: 'wcb_run_action',
            nonce: WCBAdmin.nonce,
            task: $button.data('task')
        }).done(function (response) {
            if (!response.success) {
                showNotice((response.data && response.data.message) || WCBAdmin.i18n.failed, 'error');
                return;
            }
            showNotice(response.data.message || WCBAdmin.i18n.done, 'success');
            if (response.data.stats) {
                updateStats(response.data.stats);
            }
            if (response.data.progress) {
                updateProgress($panel, response.data.progress);
            }
            if ($button.hasClass('wcb-sitemap-action') && !response.data.complete && response.data.status !== 'failed') {
                window.setTimeout(function () {
                    runAction($button, originalText);
                }, 250);
                return;
            }
            $button.prop('disabled', false).text(originalText);
            $panel.find('.spinner').removeClass('is-active');
        }).fail(function () {
            showNotice(WCBAdmin.i18n.failed, 'error');
            $button.prop('disabled', false).text(originalText);
            $panel.find('.spinner').removeClass('is-active');
        });
    }

    $(document).on('click', '.wcb-ajax-action', function (event) {
        event.preventDefault();
        var $button = $(this);
        var originalText = $button.text();
        $button.prop('disabled', true).text(WCBAdmin.i18n.working);
        $button.closest('.wcb-panel').find('.spinner').addClass('is-active');
        runAction($button, originalText);
    });

    refreshStats();
})(jQuery);
