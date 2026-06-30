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

    function escapeHtml(value) {
        return $('<div>').text(value || '').html();
    }

    function renderMonitor(data) {
        var $monitor = $('[data-wcb-monitor]');
        if (!$monitor.length) {
            return;
        }
        var jobsHtml = '<table class="widefat striped"><thead><tr><th>ID</th><th>Job</th><th>Status</th><th>Progress</th><th>Start Time</th><th>Finish Time</th><th>Duration</th></tr></thead><tbody>';
        if (!data.jobs || !data.jobs.length) {
            jobsHtml += '<tr><td colspan="7">No jobs found.</td></tr>';
        } else {
            $.each(data.jobs, function (_, job) {
                jobsHtml += '<tr>' +
                    '<td>#' + escapeHtml(job.id) + '</td>' +
                    '<td>' + escapeHtml(job.job_type) + '</td>' +
                    '<td><span class="wcb-status wcb-status-' + escapeHtml(String(job.status).toLowerCase()) + '">' + escapeHtml(job.status) + '</span></td>' +
                    '<td><div class="wcb-progress wcb-monitor-progress"><div class="wcb-progress-bar"><span style="width:' + escapeHtml(job.progress_percent) + '%"></span></div><p>' + escapeHtml(job.processed_items) + '/' + escapeHtml(job.total_items) + ' — ' + escapeHtml(job.progress_percent) + '%</p></div></td>' +
                    '<td>' + escapeHtml(job.started_at) + '</td>' +
                    '<td>' + escapeHtml(job.finished_at) + '</td>' +
                    '<td>' + escapeHtml(job.duration) + '</td>' +
                    '</tr>';
            });
        }
        jobsHtml += '</tbody></table>';
        $monitor.find('[data-wcb-monitor-jobs]').html(jobsHtml);

        var logsHtml = '<table class="widefat striped"><thead><tr><th>Level</th><th>Message</th><th>Date</th></tr></thead><tbody>';
        if (!data.logs || !data.logs.length) {
            logsHtml += '<tr><td colspan="3">No logs found.</td></tr>';
        } else {
            $.each(data.logs, function (_, log) {
                logsHtml += '<tr><td><span class="wcb-log-level wcb-log-' + escapeHtml(log.level) + '">' + escapeHtml(log.level) + '</span></td><td>' + escapeHtml(log.message) + '</td><td>' + escapeHtml(log.created_at) + '</td></tr>';
            });
        }
        logsHtml += '</tbody></table>';
        $monitor.find('[data-wcb-monitor-logs]').html(logsHtml);
    }

    function refreshMonitor() {
        if (!$('[data-wcb-monitor]').length) {
            return;
        }
        $.post(WCBAdmin.ajaxUrl, {
            action: 'wcb_monitor',
            nonce: WCBAdmin.nonce
        }).done(function (response) {
            if (response.success) {
                renderMonitor(response.data);
                if (response.data.stats) {
                    updateStats(response.data.stats);
                }
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
        var categoryIds = $panel.find('[data-wcb-category-ids]').val() || [];
        var comparisonIds = $panel.find('[data-wcb-comparison-ids]').val() || [];
        var scheduler = {};
        $panel.find('[data-wcb-scheduler-row]').each(function () {
            var $row = $(this);
            var operation = $row.data('wcb-scheduler-row');
            scheduler[operation] = {
                enabled: $row.find('[data-wcb-scheduler-enabled]').is(':checked') ? 1 : 0,
                interval: $row.find('[data-wcb-scheduler-interval]').val(),
                custom_minutes: $row.find('[data-wcb-scheduler-custom]').val()
            };
        });
        $.post(WCBAdmin.ajaxUrl, {
            action: 'wcb_run_action',
            nonce: WCBAdmin.nonce,
            task: $button.data('task'),
            job_id: $button.data('job-id') || 0,
            product_url: $panel.find('[data-wcb-product-url]').val() || '',
            batch_mode: $panel.find('[data-wcb-batch-mode]:checked').val() || 'all',
            category_ids: categoryIds,
            price_sync_mode: $panel.find('[data-wcb-price-sync-mode]:checked').val() || 'all',
            stock_sync_mode: $panel.find('[data-wcb-stock-sync-mode]:checked').val() || 'all',
            comparison_ids: comparisonIds,
            scheduler: scheduler,
            content_cleaner_replacement: $panel.find('[data-wcb-setting="content_cleaner_replacement"]').val() || '',
            content_cleaner_rules: $panel.find('[data-wcb-setting="content_cleaner_rules"]').val() || ''
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
            if ($button.hasClass('wcb-sitemap-action') && !response.data.background && !response.data.complete && response.data.status && response.data.status !== 'failed') {
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
    refreshMonitor();
    if ($('[data-wcb-monitor]').length) {
        window.setInterval(refreshMonitor, 5000);
    }
})(jQuery);
