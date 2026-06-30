<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Queue {
    const HOOK = 'wcb_process_import_job';
    const GROUP = 'woo-catalog-bridge';

    const STATUS_PENDING = 'Pending';
    const STATUS_RUNNING = 'Running';
    const STATUS_COMPLETED = 'Completed';
    const STATUS_FAILED = 'Failed';
    const STATUS_CANCELED = 'Canceled';

    public static function enqueue($operation, $payload = array()) {
        $job_id = WCB_Repository::create_import_job($operation, $payload);
        self::schedule($job_id);
        return $job_id;
    }

    public static function resume($job_id) {
        $job = WCB_Repository::get_import_job($job_id);
        if (!$job || self::STATUS_CANCELED === $job['status'] || self::STATUS_COMPLETED === $job['status']) {
            return false;
        }
        WCB_Repository::update_import_job($job_id, array(
            'status' => self::STATUS_PENDING,
            'updated_at' => current_time('mysql'),
        ));
        self::schedule($job_id);
        return true;
    }

    public static function cancel($job_id) {
        $job = WCB_Repository::get_import_job($job_id);
        if (!$job || self::STATUS_COMPLETED === $job['status']) {
            return false;
        }
        WCB_Repository::update_import_job($job_id, array(
            'status' => self::STATUS_CANCELED,
            'finished_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ));
        return true;
    }

    public static function schedule($job_id, $delay = 0) {
        $args = array('job_id' => (int) $job_id);
        if (function_exists('as_enqueue_async_action') && 0 === (int) $delay) {
            $action_id = as_enqueue_async_action(self::HOOK, $args, self::GROUP);
            self::record_action_id($job_id, $action_id);
            return 'action_scheduler';
        }
        if (function_exists('as_schedule_single_action')) {
            $action_id = as_schedule_single_action(time() + (int) $delay, self::HOOK, $args, self::GROUP);
            self::record_action_id($job_id, $action_id);
            return 'action_scheduler';
        }
        wp_schedule_single_event(time() + (int) $delay, self::HOOK, array((int) $job_id));
        return 'wp_cron';
    }

    public static function process($job_id = 0) {
        $job_id = is_array($job_id) && isset($job_id['job_id']) ? (int) $job_id['job_id'] : (int) $job_id;
        $job = WCB_Repository::get_import_job($job_id);
        if (!$job || self::STATUS_CANCELED === $job['status'] || self::STATUS_COMPLETED === $job['status']) {
            return;
        }

        $payload = self::decode_payload($job['payload']);
        WCB_Repository::update_import_job($job_id, array(
            'status' => self::STATUS_RUNNING,
            'started_at' => $job['started_at'] ? $job['started_at'] : current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ));

        try {
            switch ($job['job_type']) {
                case 'scan_sitemap':
                    self::process_sitemap_scan($job_id, $job, $payload);
                    break;
                case 'scrape_product':
                case 'sync_products':
                    self::complete_placeholder_job($job_id, $job['job_type']);
                    break;
                default:
                    throw new RuntimeException(sprintf('Unknown queue operation: %s', $job['job_type']));
            }
        } catch (Exception $e) {
            self::handle_failure($job_id, $job, $payload, $e);
        }
    }

    private static function process_sitemap_scan($job_id, $job, $payload) {
        $scan_id = isset($payload['scan_id']) ? (int) $payload['scan_id'] : 0;
        if (!$scan_id) {
            $scan_id = WCB_Sitemap_Scanner::start(WCB_Sitemap_Scanner::DEFAULT_SITEMAP_URL);
            $payload['scan_id'] = $scan_id;
            WCB_Repository::update_import_job_payload($job_id, $payload);
        } else {
            WCB_Sitemap_Scanner::resume_scan($scan_id);
        }

        $result = WCB_Sitemap_Scanner::run_batch($scan_id, 2);
        $progress = isset($result['progress']) ? $result['progress'] : array();
        WCB_Repository::update_import_job($job_id, array(
            'total_items' => isset($progress['total_sitemaps']) ? (int) $progress['total_sitemaps'] : 0,
            'processed_items' => isset($progress['processed_sitemaps']) ? (int) $progress['processed_sitemaps'] : 0,
            'payload' => wp_json_encode($payload),
            'updated_at' => current_time('mysql'),
        ));

        if (isset($result['status']) && 'failed' === $result['status']) {
            throw new RuntimeException(isset($progress['last_error']) && $progress['last_error'] ? $progress['last_error'] : $result['message']);
        }

        if (!empty($result['complete'])) {
            WCB_Repository::complete_import_job($job_id);
            return;
        }

        WCB_Repository::update_import_job($job_id, array(
            'status' => self::STATUS_PENDING,
            'updated_at' => current_time('mysql'),
        ));
        self::schedule($job_id, 10);
    }

    private static function complete_placeholder_job($job_id, $operation) {
        WCB_Repository::log('info', sprintf('%s job completed.', $operation), array('job_id' => $job_id));
        WCB_Repository::complete_import_job($job_id);
    }

    private static function handle_failure($job_id, $job, $payload, Exception $e) {
        $attempts = isset($job['attempts']) ? (int) $job['attempts'] + 1 : 1;
        $max_attempts = isset($job['max_attempts']) ? (int) $job['max_attempts'] : 3;
        $data = array(
            'attempts' => $attempts,
            'last_error' => $e->getMessage(),
            'updated_at' => current_time('mysql'),
        );

        if ($attempts < $max_attempts) {
            $data['status'] = self::STATUS_PENDING;
            WCB_Repository::update_import_job($job_id, $data);
            WCB_Repository::log('warning', __('Queue job failed and will be retried.', 'woo-catalog-bridge'), array('job_id' => $job_id, 'attempts' => $attempts, 'error' => $e->getMessage()));
            self::schedule($job_id, min(300, 30 * $attempts));
            return;
        }

        $data['status'] = self::STATUS_FAILED;
        $data['finished_at'] = current_time('mysql');
        WCB_Repository::update_import_job($job_id, $data);
        WCB_Repository::log('error', __('Queue job failed permanently.', 'woo-catalog-bridge'), array('job_id' => $job_id, 'attempts' => $attempts, 'error' => $e->getMessage()));
    }

    private static function decode_payload($payload) {
        $decoded = json_decode((string) $payload, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function record_action_id($job_id, $action_id) {
        if (!$action_id) {
            return;
        }
        WCB_Repository::update_import_job($job_id, array(
            'action_id' => (int) $action_id,
            'updated_at' => current_time('mysql'),
        ));
    }
}
