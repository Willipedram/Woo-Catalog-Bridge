<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Scheduler {
    const GROUP = 'woo-catalog-bridge-scheduler';
    const OPTION = 'wcb_scheduler_settings';
    const HOOK_PREFIX = 'wcb_scheduled_operation_';

    /**
     * @return array<string,string>
     */
    public static function operations() {
        return array(
            'scan_sitemap' => __('Scan Sitemap', 'woo-catalog-bridge'),
            'import_products' => __('Import Products', 'woo-catalog-bridge'),
            'price_sync' => __('Price Sync', 'woo-catalog-bridge'),
            'stock_sync' => __('Stock Sync', 'woo-catalog-bridge'),
        );
    }

    public static function intervals() {
        return array(
            'wcb_15_minutes' => __('15 minutes', 'woo-catalog-bridge'),
            'wcb_30_minutes' => __('30 minutes', 'woo-catalog-bridge'),
            'hourly' => __('1 hour', 'woo-catalog-bridge'),
            'wcb_6_hours' => __('6 hours', 'woo-catalog-bridge'),
            'daily' => __('Daily', 'woo-catalog-bridge'),
            'custom' => __('Custom', 'woo-catalog-bridge'),
        );
    }

    public static function cron_schedules($schedules) {
        $schedules['wcb_15_minutes'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => __('Every 15 minutes', 'woo-catalog-bridge'));
        $schedules['wcb_30_minutes'] = array('interval' => 30 * MINUTE_IN_SECONDS, 'display' => __('Every 30 minutes', 'woo-catalog-bridge'));
        $schedules['wcb_6_hours'] = array('interval' => 6 * HOUR_IN_SECONDS, 'display' => __('Every 6 hours', 'woo-catalog-bridge'));
        return $schedules;
    }

    public static function settings() {
        $settings = get_option(self::OPTION, array());
        foreach (self::operations() as $operation => $label) {
            if (empty($settings[$operation])) {
                $settings[$operation] = array('enabled' => 0, 'interval' => 'hourly', 'custom_minutes' => 60, 'next_run' => '');
            }
        }
        return $settings;
    }

    /**
     * Validate, persist, and reschedule operation schedules.
     *
     * @param array<string,mixed> $posted
     * @return array<string,mixed>
     */
    public static function save_settings($posted) {
        $settings = array();
        foreach (self::operations() as $operation => $label) {
            $enabled = !empty($posted[$operation]['enabled']) ? 1 : 0;
            $interval = isset($posted[$operation]['interval']) ? sanitize_key($posted[$operation]['interval']) : 'hourly';
            if (!array_key_exists($interval, self::intervals())) {
                $interval = 'hourly';
            }
            $settings[$operation] = array(
                'enabled' => $enabled,
                'interval' => $interval,
                'custom_minutes' => max(1, isset($posted[$operation]['custom_minutes']) ? absint($posted[$operation]['custom_minutes']) : 60),
                'next_run' => '',
            );
        }
        update_option(self::OPTION, $settings);
        self::reschedule_all();
        return self::settings();
    }

    public static function reschedule_all() {
        foreach (array_keys(self::operations()) as $operation) {
            self::clear($operation);
        }
        $settings = self::settings();
        foreach ($settings as $operation => $config) {
            if (!empty($config['enabled'])) {
                self::schedule($operation, $config);
            }
        }
    }

    public static function schedule($operation, $config) {
        $timestamp = time() + self::seconds($config);
        $hook = self::hook($operation);
        if ('custom' === $config['interval']) {
            wp_schedule_single_event($timestamp, $hook, array($operation));
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action($timestamp, $hook, array('operation' => $operation), self::GROUP);
            }
        } else {
            if (!wp_next_scheduled($hook, array($operation))) {
                wp_schedule_event($timestamp, $config['interval'], $hook, array($operation));
            }
            if (function_exists('as_schedule_recurring_action')) {
                as_schedule_recurring_action($timestamp, self::seconds($config), $hook, array('operation' => $operation), self::GROUP, true);
            }
        }
        self::set_next_run($operation, $timestamp);
    }

    public static function clear($operation) {
        $hook = self::hook($operation);
        while ($timestamp = wp_next_scheduled($hook, array($operation))) {
            wp_unschedule_event($timestamp, $hook, array($operation));
        }
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook, null, self::GROUP);
        }
    }

    /**
     * Queue the catalog operation requested by WP Cron or Action Scheduler.
     *
     * @param string|array<string,string> $operation
     */
    public static function run_operation($operation = '') {
        if (is_array($operation) && isset($operation['operation'])) {
            $operation = $operation['operation'];
        }
        $operation = sanitize_key($operation);
        switch ($operation) {
            case 'scan_sitemap':
                $scan_id = WCB_Sitemap_Scanner::start(WCB_Sitemap_Scanner::DEFAULT_SITEMAP_URL);
                WCB_Queue::enqueue('scan_sitemap', array('scan_id' => $scan_id));
                break;
            case 'import_products':
                WCB_Batch_Importer::start('all', array());
                break;
            case 'price_sync':
                WCB_Price_Sync_Engine::enqueue('all', array());
                break;
            case 'stock_sync':
                WCB_Stock_Sync_Engine::enqueue('all', array());
                break;
            default:
                return;
        }
        WCB_Repository::log('info', sprintf(__('Scheduled operation queued: %s', 'woo-catalog-bridge'), $operation));
        $settings = self::settings();
        if (!empty($settings[$operation]) && !empty($settings[$operation]['enabled']) && 'custom' === $settings[$operation]['interval']) {
            self::schedule($operation, $settings[$operation]);
        }
    }

    public static function hook($operation) {
        return self::HOOK_PREFIX . sanitize_key($operation);
    }

    private static function seconds($config) {
        switch ($config['interval']) {
            case 'wcb_15_minutes': return 15 * MINUTE_IN_SECONDS;
            case 'wcb_30_minutes': return 30 * MINUTE_IN_SECONDS;
            case 'wcb_6_hours': return 6 * HOUR_IN_SECONDS;
            case 'daily': return DAY_IN_SECONDS;
            case 'custom': return max(1, (int) $config['custom_minutes']) * MINUTE_IN_SECONDS;
            case 'hourly':
            default: return HOUR_IN_SECONDS;
        }
    }

    private static function set_next_run($operation, $timestamp) {
        $settings = self::settings();
        if (isset($settings[$operation])) {
            $settings[$operation]['next_run'] = gmdate('Y-m-d H:i:s', $timestamp);
            update_option(self::OPTION, $settings);
        }
    }
}
