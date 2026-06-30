<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Monitor {
    /**
     * Build a sanitized monitoring snapshot from persisted jobs and logs.
     *
     * @return array<string,mixed>
     */
    public static function snapshot() {
        return array(
            'jobs' => self::jobs(),
            'logs' => WCB_Repository::list_logs(10, 0),
            'stats' => WCB_Repository::stats(),
            'generated_at' => current_time('mysql'),
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function jobs() {
        $jobs = WCB_Repository::list_import_jobs(20, 0);
        foreach ($jobs as &$job) {
            $job['progress_percent'] = self::progress_percent($job);
            $job['duration'] = self::duration($job);
            $job['started_at'] = $job['started_at'] ? $job['started_at'] : '';
            $job['finished_at'] = $job['finished_at'] ? $job['finished_at'] : '';
        }
        return $jobs;
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function progress_percent($job) {
        $total = isset($job['total_items']) ? (int) $job['total_items'] : 0;
        $processed = isset($job['processed_items']) ? (int) $job['processed_items'] : 0;
        if ($total <= 0) {
            return in_array($job['status'], array(WCB_Queue::STATUS_COMPLETED, WCB_Queue::STATUS_FAILED, WCB_Queue::STATUS_CANCELED), true) ? 100 : 0;
        }
        return min(100, round(($processed / $total) * 100, 2));
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function duration($job) {
        $start = !empty($job['started_at']) ? strtotime($job['started_at']) : strtotime($job['created_at']);
        $end = !empty($job['finished_at']) ? strtotime($job['finished_at']) : current_time('timestamp');
        if (!$start || !$end || $end < $start) {
            return '00:00:00';
        }
        $seconds = $end - $start;
        return sprintf('%02d:%02d:%02d', floor($seconds / HOUR_IN_SECONDS), floor(($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS), $seconds % MINUTE_IN_SECONDS);
    }
}
