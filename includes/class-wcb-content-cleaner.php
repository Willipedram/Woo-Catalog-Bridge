<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Content_Cleaner {
    public static function clean_product_data($data) {
        foreach (array('title', 'short_description', 'description', 'brand') as $field) {
            if (isset($data[$field])) {
                $data[$field] = self::clean_text($data[$field], in_array($field, array('short_description', 'description'), true));
            }
        }
        if (!empty($data['attributes']) && is_array($data['attributes'])) {
            foreach ($data['attributes'] as $name => $value) {
                $clean_name = self::clean_text($name, false);
                if (is_array($value)) {
                    $data['attributes'][$clean_name] = array_map(function ($item) {
                        return WCB_Content_Cleaner::clean_text($item, false);
                    }, $value);
                } else {
                    $data['attributes'][$clean_name] = self::clean_text($value, false);
                }
                if ($clean_name !== $name) {
                    unset($data['attributes'][$name]);
                }
            }
        }
        if (!empty($data['variations']) && is_array($data['variations'])) {
            foreach ($data['variations'] as $index => $variation) {
                if (empty($variation['attributes']) || !is_array($variation['attributes'])) {
                    continue;
                }
                $clean_attributes = array();
                foreach ($variation['attributes'] as $name => $value) {
                    $clean_attributes[self::clean_text($name, false)] = self::clean_text($value, false);
                }
                $data['variations'][$index]['attributes'] = $clean_attributes;
            }
        }
        return $data;
    }

    public static function clean_text($content, $allow_html = true) {
        $content = self::links_to_plain_text((string) $content, $allow_html);
        $content = self::apply_replace_rules($content);
        return $allow_html ? wp_kses_post($content) : sanitize_text_field(wp_strip_all_tags($content));
    }

    public static function settings() {
        $settings = get_option('wcb_settings', array());
        $replacement = isset($settings['content_cleaner_replacement']) ? (string) $settings['content_cleaner_replacement'] : get_bloginfo('name');
        $rules = isset($settings['content_cleaner_rules']) && is_array($settings['content_cleaner_rules']) ? $settings['content_cleaner_rules'] : self::default_rules($replacement);
        return array('replacement' => $replacement, 'rules' => $rules);
    }

    public static function update_settings($replacement, $rules_text) {
        $settings = get_option('wcb_settings', array());
        $replacement = sanitize_text_field($replacement);
        $rules = self::parse_rules_text($rules_text, $replacement);
        $settings['content_cleaner_replacement'] = $replacement;
        $settings['content_cleaner_rules'] = $rules;
        update_option('wcb_settings', $settings);
        return array('replacement' => $replacement, 'rules' => $rules);
    }

    public static function rules_to_text($rules) {
        $lines = array();
        foreach ($rules as $rule) {
            if (!isset($rule['search'])) {
                continue;
            }
            $lines[] = $rule['search'] . ' => ' . (isset($rule['replace']) ? $rule['replace'] : '');
        }
        return implode("\n", $lines);
    }

    private static function links_to_plain_text($content, $allow_html) {
        if (false === stripos($content, '<a')) {
            return $content;
        }
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="wcb-cleaner-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $links = $dom->getElementsByTagName('a');
        for ($i = $links->length - 1; $i >= 0; $i--) {
            $link = $links->item($i);
            $text = $dom->createTextNode($link->textContent);
            $link->parentNode->replaceChild($text, $link);
        }
        $root = $dom->getElementById('wcb-cleaner-root');
        $clean = '';
        if ($root) {
            foreach ($root->childNodes as $child) {
                $clean .= $dom->saveHTML($child);
            }
        }
        libxml_clear_errors();
        return $allow_html ? $clean : wp_strip_all_tags($clean);
    }

    private static function apply_replace_rules($content) {
        $settings = self::settings();
        foreach ($settings['rules'] as $rule) {
            if (empty($rule['search'])) {
                continue;
            }
            $replace = isset($rule['replace']) ? $rule['replace'] : $settings['replacement'];
            $content = str_ireplace($rule['search'], $replace, $content);
        }
        return $content;
    }

    private static function parse_rules_text($rules_text, $replacement) {
        $rules = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $rules_text) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            $parts = preg_split('/\s*=>\s*/', $line, 2);
            $rules[] = array(
                'search' => sanitize_text_field($parts[0]),
                'replace' => isset($parts[1]) ? sanitize_text_field($parts[1]) : $replacement,
            );
        }
        return $rules ? $rules : self::default_rules($replacement);
    }

    private static function default_rules($replacement) {
        return array(
            array('search' => 'سازکالا', 'replace' => $replacement),
            array('search' => 'ساز کالا', 'replace' => $replacement),
            array('search' => 'sazkala', 'replace' => $replacement),
            array('search' => 'saz kala', 'replace' => $replacement),
        );
    }
}
