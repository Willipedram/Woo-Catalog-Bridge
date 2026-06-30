<?php
if (!defined('ABSPATH')) {
    exit;
}

class WCB_Product_Importer {
    const SOURCE_URL_META = '_wcb_source_url';
    const BRAND_META = '_wcb_brand';

    public static function import($url) {
        if (!function_exists('wc_get_product_id_by_sku') || !class_exists('WC_Product_Simple')) {
            throw new RuntimeException(__('WooCommerce is required to import products.', 'woo-catalog-bridge'));
        }

        $url = esc_url_raw($url);
        if (!$url) {
            throw new InvalidArgumentException(__('A valid source product URL is required.', 'woo-catalog-bridge'));
        }

        $html = self::fetch_html($url);
        $data = self::extract_product_data($html, $url);
        $data = WCB_Content_Cleaner::clean_product_data($data);
        if (empty($data['title'])) {
            throw new RuntimeException(__('Unable to extract product title from source URL.', 'woo-catalog-bridge'));
        }
        $duplicate = self::find_duplicate($data);
        if ($duplicate) {
            WCB_Repository::record_product_import($data, 'duplicate', (int) $duplicate, __('Duplicate product detected; import stopped.', 'woo-catalog-bridge'));
            throw new RuntimeException(sprintf(__('Duplicate product detected. Existing product ID: %d', 'woo-catalog-bridge'), (int) $duplicate));
        }

        $product_id = self::create_woocommerce_product($data);
        WCB_Repository::record_product_import($data, 'imported', $product_id, '');
        WCB_Repository::log('info', __('Product imported to WooCommerce.', 'woo-catalog-bridge'), array('product_id' => $product_id, 'source_url' => $url));

        return array('product_id' => $product_id, 'data' => $data);
    }

    public static function find_duplicate($data) {
        if (!empty($data['sku'])) {
            $product_id = wc_get_product_id_by_sku($data['sku']);
            if ($product_id) {
                return (int) $product_id;
            }
        }

        $by_url = self::find_product_by_meta(self::SOURCE_URL_META, $data['source_url']);
        if ($by_url) {
            return $by_url;
        }

        if (!empty($data['title'])) {
            $page = get_page_by_title($data['title'], OBJECT, 'product');
            if ($page && !empty($page->ID)) {
                return (int) $page->ID;
            }
        }

        return 0;
    }

    private static function fetch_html($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'redirection' => 5,
            'headers' => array(
                'User-Agent' => 'Woo Catalog Bridge/' . WCB_VERSION . '; ' . home_url('/'),
            ),
        ));
        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException(sprintf(__('Product request failed with HTTP %d.', 'woo-catalog-bridge'), $code));
        }
        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            throw new RuntimeException(__('Product page is empty.', 'woo-catalog-bridge'));
        }
        return $body;
    }

    private static function extract_product_data($html, $source_url) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new DOMXPath($dom);
        libxml_clear_errors();

        $json = self::extract_json_ld_product($xpath);
        $title = self::first_value(array(
            isset($json['name']) ? $json['name'] : '',
            self::meta($xpath, 'property', 'og:title'),
            self::text($xpath, '//h1'),
            self::text($xpath, '//title'),
        ));
        $short = self::first_value(array(
            isset($json['description']) ? $json['description'] : '',
            self::meta($xpath, 'name', 'description'),
            self::text($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " short-description ")]'),
        ));
        $sku = self::first_value(array(
            isset($json['sku']) ? $json['sku'] : '',
            self::text($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " sku ")]'),
        ));
        $price = self::normalize_price(self::first_value(array(
            isset($json['offers']['price']) ? $json['offers']['price'] : '',
            self::meta($xpath, 'property', 'product:price:amount'),
            self::attr($xpath, '//*[@itemprop="price"]', 'content'),
            self::text($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " price ")]'),
        )));

        $images = self::extract_images($xpath, $json, $source_url);
        $attributes = self::extract_attributes($xpath);
        $brand = self::first_value(array(
            isset($json['brand']['name']) ? $json['brand']['name'] : '',
            isset($json['brand']) && is_string($json['brand']) ? $json['brand'] : '',
            isset($attributes['brand']) ? $attributes['brand'] : '',
            isset($attributes['برند']) ? $attributes['برند'] : '',
        ));

        return array(
            'source_url' => $source_url,
            'title' => sanitize_text_field($title),
            'short_description' => wp_kses_post($short),
            'description' => wp_kses_post(self::first_value(array(self::html($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " woocommerce-product-details__short-description ")]'), $short))),
            'sku' => sanitize_text_field($sku),
            'price' => $price,
            'stock_status' => self::detect_stock_status($html, $json),
            'weight' => isset($attributes['weight']) ? $attributes['weight'] : (isset($attributes['وزن']) ? $attributes['وزن'] : ''),
            'dimensions' => self::extract_dimensions($attributes),
            'featured_image' => isset($images[0]) ? $images[0] : '',
            'gallery_images' => array_slice($images, 1),
            'attributes' => $attributes,
            'variations' => self::extract_variations($xpath, $source_url),
            'brand' => sanitize_text_field($brand),
        );
    }

    private static function create_woocommerce_product($data) {
        $product = !empty($data['variations']) && class_exists('WC_Product_Variable') ? new WC_Product_Variable() : new WC_Product_Simple();
        $product->set_name($data['title']);
        $product->set_description($data['description']);
        $product->set_short_description($data['short_description']);
        if ($data['sku']) {
            $product->set_sku($data['sku']);
        }
        if ('' !== $data['price']) {
            $product->set_regular_price((string) $data['price']);
        }
        $product->set_stock_status($data['stock_status']);
        if ($data['weight']) {
            $product->set_weight(self::numeric_value($data['weight']));
        }
        if (!empty($data['dimensions'])) {
            $product->set_length($data['dimensions']['length']);
            $product->set_width($data['dimensions']['width']);
            $product->set_height($data['dimensions']['height']);
        }
        $product->update_meta_data(self::SOURCE_URL_META, $data['source_url']);
        if ($data['brand']) {
            $product->update_meta_data(self::BRAND_META, $data['brand']);
        }
        $attribute_context = self::build_wc_attributes($data['attributes'], isset($data['variations']) ? $data['variations'] : array());
        $product->set_attributes($attribute_context['attributes']);
        $product_id = $product->save();

        $featured_id = $data['featured_image'] ? self::sideload_image($data['featured_image'], $product_id) : 0;
        if ($featured_id) {
            set_post_thumbnail($product_id, $featured_id);
        }
        $gallery_ids = array();
        foreach ($data['gallery_images'] as $image_url) {
            $attachment_id = self::sideload_image($image_url, $product_id);
            if ($attachment_id) {
                $gallery_ids[] = $attachment_id;
            }
        }
        if ($gallery_ids) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', array_unique($gallery_ids)));
        }

        if ($product instanceof WC_Product_Variable) {
            self::create_variations($product_id, $data['variations'], $attribute_context['map']);
            WC_Product_Variable::sync($product_id);
        }

        return (int) $product_id;
    }

    private static function sideload_image($image_url, $post_id) {
        if (!$image_url) {
            return 0;
        }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_sideload_image(esc_url_raw($image_url), $post_id, null, 'id');
        return is_wp_error($attachment_id) ? 0 : (int) $attachment_id;
    }

    private static function build_wc_attributes($attributes, $variations = array()) {
        $attributes = self::merge_variation_attributes($attributes, $variations);
        $wc_attributes = array();
        $map = array();
        foreach ($attributes as $name => $value) {
            $options = is_array($value) ? array_filter(array_map('wc_clean', $value)) : array(wc_clean($value));
            if (!$options) {
                continue;
            }
            $taxonomy = self::ensure_global_attribute($name);
            $term_ids = array();
            $term_slugs = array();
            foreach ($options as $option) {
                $term = self::ensure_attribute_term($taxonomy, $option);
                if ($term) {
                    $term_ids[] = (int) $term['term_id'];
                    $term_slugs[$option] = $term['slug'];
                }
            }
            $attribute = new WC_Product_Attribute();
            $attribute->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
            $attribute->set_name($taxonomy);
            $attribute->set_options($term_ids);
            $attribute->set_visible(true);
            $attribute->set_variation(self::is_variation_attribute($name, $variations));
            $wc_attributes[] = $attribute;
            $map[sanitize_title($name)] = array('taxonomy' => $taxonomy, 'terms' => $term_slugs);
        }
        return array('attributes' => $wc_attributes, 'map' => $map);
    }

    private static function extract_json_ld_product($xpath) {
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $decoded = json_decode($script->textContent, true);
            $items = isset($decoded['@graph']) ? $decoded['@graph'] : array($decoded);
            foreach ($items as $item) {
                if (is_array($item) && isset($item['@type']) && false !== stripos(is_array($item['@type']) ? implode(',', $item['@type']) : $item['@type'], 'Product')) {
                    return $item;
                }
            }
        }
        return array();
    }

    private static function extract_attributes($xpath) {
        $attributes = array();
        foreach ($xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " shop_attributes ")]//tr|//table//tr') as $row) {
            $name = self::node_text($xpath->query('.//th|.//td[1]', $row)->item(0));
            $value = self::node_text($xpath->query('.//td[last()]', $row)->item(0));
            if ($name && $value && $name !== $value) {
                $attributes[sanitize_text_field(trim($name, ': '))] = sanitize_text_field($value);
            }
        }
        return $attributes;
    }

    private static function extract_images($xpath, $json, $source_url) {
        $images = array();
        if (!empty($json['image'])) {
            $images = array_merge($images, is_array($json['image']) ? $json['image'] : array($json['image']));
        }
        $og = self::meta($xpath, 'property', 'og:image');
        if ($og) {
            $images[] = $og;
        }
        foreach ($xpath->query('//img[contains(@class,"wp-post-image") or contains(@class,"attachment-shop") or contains(@class,"product") or contains(@src,"/uploads/")]') as $img) {
            $src = $img->getAttribute('data-large_image') ?: ($img->getAttribute('data-src') ?: $img->getAttribute('src'));
            if ($src) {
                $images[] = $src;
            }
        }
        return array_values(array_unique(array_filter(array_map(function ($image) use ($source_url) {
            return esc_url_raw(wp_http_validate_url($image) ? $image : self::absolute_url($image, $source_url));
        }, $images))));
    }

    private static function extract_variations($xpath, $source_url) {
        $variations = array();
        foreach ($xpath->query('//*[@data-product_variations]') as $node) {
            $json = html_entity_decode($node->getAttribute('data-product_variations'), ENT_QUOTES, 'UTF-8');
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $variation) {
                    $normalized = self::normalize_variation($variation, $source_url);
                    if ($normalized) {
                        $variations[] = $normalized;
                    }
                }
            }
        }
        foreach ($xpath->query('//script[contains(text(), "variation_id") and contains(text(), "attributes")]') as $script) {
            if (preg_match_all('/\\[\\{.*?\\}\\]/s', $script->textContent, $matches)) {
                foreach ($matches[0] as $json) {
                    $decoded = json_decode($json, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    foreach ($decoded as $variation) {
                        $normalized = self::normalize_variation($variation, $source_url);
                        if ($normalized) {
                            $variations[] = $normalized;
                        }
                    }
                }
            }
        }
        return self::unique_variations($variations);
    }

    private static function normalize_variation($variation, $source_url) {
        if (!is_array($variation)) {
            return array();
        }
        $attributes = array();
        $raw_attributes = isset($variation['attributes']) && is_array($variation['attributes']) ? $variation['attributes'] : array();
        foreach ($raw_attributes as $name => $value) {
            $clean_name = preg_replace('/^attribute_/', '', (string) $name);
            $clean_name = preg_replace('/^pa_/', '', $clean_name);
            if ('' !== trim((string) $value)) {
                $attributes[sanitize_text_field(str_replace('-', ' ', $clean_name))] = sanitize_text_field($value);
            }
        }
        if (!$attributes) {
            return array();
        }
        $image = '';
        if (!empty($variation['image']['url'])) {
            $image = $variation['image']['url'];
        } elseif (!empty($variation['image']['src'])) {
            $image = $variation['image']['src'];
        }
        if ($image && !wp_http_validate_url($image)) {
            $image = self::absolute_url($image, $source_url);
        }
        return array(
            'attributes' => $attributes,
            'sku' => isset($variation['sku']) ? sanitize_text_field($variation['sku']) : '',
            'price' => self::normalize_price(isset($variation['display_price']) ? $variation['display_price'] : (isset($variation['price']) ? $variation['price'] : '')),
            'regular_price' => self::normalize_price(isset($variation['display_regular_price']) ? $variation['display_regular_price'] : (isset($variation['regular_price']) ? $variation['regular_price'] : '')),
            'stock_status' => !empty($variation['is_in_stock']) ? 'instock' : 'outofstock',
            'stock_quantity' => isset($variation['max_qty']) && '' !== $variation['max_qty'] ? (int) $variation['max_qty'] : null,
            'image' => esc_url_raw($image),
        );
    }

    private static function unique_variations($variations) {
        $seen = array();
        $unique = array();
        foreach ($variations as $variation) {
            $key = md5(wp_json_encode($variation['attributes']));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $variation;
        }
        return $unique;
    }

    private static function merge_variation_attributes($attributes, $variations) {
        foreach ($variations as $variation) {
            foreach ($variation['attributes'] as $name => $value) {
                if (!isset($attributes[$name])) {
                    $attributes[$name] = array();
                }
                if (!is_array($attributes[$name])) {
                    $attributes[$name] = array($attributes[$name]);
                }
                $attributes[$name][] = $value;
                $attributes[$name] = array_values(array_unique(array_filter($attributes[$name])));
            }
        }
        return $attributes;
    }

    private static function is_variation_attribute($name, $variations) {
        foreach ($variations as $variation) {
            if (array_key_exists($name, $variation['attributes'])) {
                return true;
            }
        }
        return false;
    }

    private static function create_variations($product_id, $variations, $attribute_map) {
        foreach ($variations as $variation_data) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($product_id);
            $variation->set_attributes(self::variation_attribute_values($variation_data['attributes'], $attribute_map));
            if (!empty($variation_data['sku']) && !wc_get_product_id_by_sku($variation_data['sku'])) {
                $variation->set_sku($variation_data['sku']);
            }
            $price = $variation_data['price'] ? $variation_data['price'] : $variation_data['regular_price'];
            if ('' !== $price) {
                $variation->set_regular_price((string) $price);
            }
            $variation->set_stock_status($variation_data['stock_status']);
            if (null !== $variation_data['stock_quantity']) {
                $variation->set_manage_stock(true);
                $variation->set_stock_quantity((int) $variation_data['stock_quantity']);
            }
            $variation_id = $variation->save();
            if (!empty($variation_data['image'])) {
                $image_id = self::sideload_image($variation_data['image'], $variation_id);
                if ($image_id) {
                    $variation->set_image_id($image_id);
                    $variation->save();
                }
            }
        }
    }

    private static function variation_attribute_values($attributes, $attribute_map) {
        $values = array();
        foreach ($attributes as $name => $value) {
            $key = sanitize_title($name);
            if (isset($attribute_map[$key])) {
                $taxonomy = $attribute_map[$key]['taxonomy'];
                $values[$taxonomy] = isset($attribute_map[$key]['terms'][$value]) ? $attribute_map[$key]['terms'][$value] : sanitize_title($value);
            }
        }
        return $values;
    }

    private static function ensure_global_attribute($name) {
        $taxonomy = wc_attribute_taxonomy_name($name);
        if (!wc_attribute_taxonomy_id_by_name($taxonomy) && function_exists('wc_create_attribute')) {
            wc_create_attribute(array(
                'name' => wc_clean($name),
                'slug' => str_replace('pa_', '', $taxonomy),
                'type' => 'select',
                'order_by' => 'menu_order',
                'has_archives' => false,
            ));
            delete_transient('wc_attribute_taxonomies');
        }
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, array('product'), array('hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false));
        }
        return $taxonomy;
    }

    private static function ensure_attribute_term($taxonomy, $value) {
        $term = get_term_by('name', $value, $taxonomy);
        if (!$term) {
            $inserted = wp_insert_term($value, $taxonomy);
            if (is_wp_error($inserted)) {
                $term = get_term_by('slug', sanitize_title($value), $taxonomy);
            } else {
                $term = get_term((int) $inserted['term_id'], $taxonomy);
            }
        }
        return $term ? array('term_id' => (int) $term->term_id, 'slug' => $term->slug) : null;
    }

    private static function extract_dimensions($attributes) {
        $raw = isset($attributes['dimensions']) ? $attributes['dimensions'] : (isset($attributes['ابعاد']) ? $attributes['ابعاد'] : '');
        preg_match_all('/[\d\.]+/', (string) $raw, $matches);
        if (count($matches[0]) < 3) {
            return array();
        }
        return array('length' => $matches[0][0], 'width' => $matches[0][1], 'height' => $matches[0][2]);
    }

    private static function find_product_by_meta($key, $value) {
        $query = new WP_Query(array('post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_key' => $key, 'meta_value' => $value));
        return !empty($query->posts[0]) ? (int) $query->posts[0] : 0;
    }

    private static function detect_stock_status($html, $json) {
        $availability = isset($json['offers']['availability']) ? strtolower($json['offers']['availability']) : '';
        if (false !== strpos($availability, 'outofstock') || false !== strpos($html, 'ناموجود')) {
            return 'outofstock';
        }
        return 'instock';
    }

    private static function normalize_price($value) {
        $value = str_replace(array(',', '٬', '،'), '', strip_tags((string) $value));
        $value = strtr($value, array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'));
        preg_match('/\d+(?:\.\d+)?/', $value, $matches);
        return isset($matches[0]) ? $matches[0] : '';
    }

    private static function numeric_value($value) {
        preg_match('/\d+(?:\.\d+)?/', self::normalize_price($value), $matches);
        return isset($matches[0]) ? $matches[0] : '';
    }

    private static function meta($xpath, $attr, $value) {
        return self::attr($xpath, '//meta[@' . $attr . '="' . $value . '"]', 'content');
    }

    private static function text($xpath, $query) {
        return self::node_text($xpath->query($query)->item(0));
    }

    private static function html($xpath, $query) {
        $node = $xpath->query($query)->item(0);
        return $node ? $node->ownerDocument->saveHTML($node) : '';
    }

    private static function attr($xpath, $query, $attr) {
        $node = $xpath->query($query)->item(0);
        return $node ? trim($node->getAttribute($attr)) : '';
    }

    private static function node_text($node) {
        return $node ? trim(preg_replace('/\s+/u', ' ', $node->textContent)) : '';
    }

    private static function first_value($values) {
        foreach ($values as $value) {
            if ('' !== trim((string) $value)) {
                return trim((string) $value);
            }
        }
        return '';
    }

    private static function absolute_url($url, $base) {
        if (0 === strpos($url, '//')) {
            return wp_parse_url($base, PHP_URL_SCHEME) . ':' . $url;
        }
        if (0 === strpos($url, '/')) {
            return wp_parse_url($base, PHP_URL_SCHEME) . '://' . wp_parse_url($base, PHP_URL_HOST) . $url;
        }
        return $url;
    }
}
