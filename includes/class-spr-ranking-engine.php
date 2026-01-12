<?php
/**
 * Клас для обчислення релевантності продуктів
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPR_Ranking_Engine {
    
    private static $instance = null;
    private $semantic_matcher;
    private $tracker;
    private $settings;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->semantic_matcher = SPR_Semantic_Matcher::get_instance();
        $this->tracker = SPR_Tracker::get_instance();
        $this->settings = get_option('spr_settings', array());
        
        // Додаємо хук для оновлення релевантності
        add_action('spr_update_relevance', array($this, 'update_product_relevance'), 10, 2);
    }
    
    /**
     * Обчислення загального скору релевантності продукту для категорії
     */
    public function calculate_relevance_score($product_id, $category_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return 0;
        }
        
        $category = get_term($category_id, 'product_cat');
        
        if (!$category || is_wp_error($category)) {
            return 0;
        }
        
        // Отримання налаштувань
        $title_weight = isset($this->settings['title_weight']) ? (float) $this->settings['title_weight'] : 10;
        $description_weight = isset($this->settings['description_weight']) ? (float) $this->settings['description_weight'] : 5;
        $sales_weight = isset($this->settings['sales_weight']) ? (float) $this->settings['sales_weight'] : 3;
        $reviews_weight = isset($this->settings['reviews_weight']) ? (float) $this->settings['reviews_weight'] : 2;
        $views_weight = isset($this->settings['views_weight']) ? (float) $this->settings['views_weight'] : 1;
        $enable_semantic = isset($this->settings['enable_semantic_matching']) ? $this->settings['enable_semantic_matching'] : true;
        
        // 1. Скор за назвою продукту
        $title_score = $this->calculate_title_score($product, $category, $enable_semantic);
        
        // 2. Скор за описом
        $description_score = $this->calculate_description_score($product, $category, $enable_semantic);
        
        // 3. Скор за продажами
        $sales_score = $this->calculate_sales_score($product_id);
        
        // 4. Скор за відгуками
        $reviews_score = $this->calculate_reviews_score($product_id);
        
        // 5. Скор за переглядами
        $views_score = $this->calculate_views_score($product_id, $category_id);
        
        // Обчислення зваженого скору
        $total_score = 
            ($title_score * $title_weight) +
            ($description_score * $description_weight) +
            ($sales_score * $sales_weight) +
            ($reviews_score * $reviews_weight) +
            ($views_score * $views_weight);
        
        // Нормалізація скору
        $max_possible_score = $title_weight + $description_weight + $sales_weight + $reviews_weight + $views_weight;
        $normalized_score = $max_possible_score > 0 ? ($total_score / $max_possible_score) * 100 : 0;
        
        return round($normalized_score, 4);
    }
    
    /**
     * Скор за назвою продукту
     */
    private function calculate_title_score($product, $category, $enable_semantic = true) {
        $title = $product->get_name();
        $category_name = $category->name;
        
        if (empty($title) || empty($category_name)) {
            return 0;
        }
        
        // Точне співпадіння дає максимальний скор
        $title_lower = mb_strtolower($title, 'UTF-8');
        $category_lower = mb_strtolower($category_name, 'UTF-8');
        
        if (mb_strpos($title_lower, $category_lower, 0, 'UTF-8') !== false) {
            return 1.0;
        }
        
        // Часткове співпадіння
        $title_words = explode(' ', $title_lower);
        $category_words = explode(' ', $category_lower);
        
        $matches = 0;
        foreach ($category_words as $cat_word) {
            foreach ($title_words as $title_word) {
                if (mb_strlen($cat_word, 'UTF-8') > 2 && 
                    mb_strpos($title_word, $cat_word, 0, 'UTF-8') !== false) {
                    $matches++;
                    break;
                }
            }
        }
        
        $partial_score = count($category_words) > 0 ? $matches / count($category_words) : 0;
        
        // Семантичне порівняння
        if ($enable_semantic && $partial_score < 0.8) {
            $semantic_score = $this->semantic_matcher->calculate_similarity($title, $category_name);
            return max($partial_score, $semantic_score * 0.8);
        }
        
        return $partial_score;
    }
    
    /**
     * Скор за описом продукту
     */
    private function calculate_description_score($product, $category, $enable_semantic = true) {
        $description = $product->get_description();
        $short_description = $product->get_short_description();
        $full_description = $description . ' ' . $short_description;
        
        $category_name = $category->name;
        
        if (empty($full_description) || empty($category_name)) {
            return 0;
        }
        
        // Точне співпадіння
        $desc_lower = mb_strtolower($full_description, 'UTF-8');
        $category_lower = mb_strtolower($category_name, 'UTF-8');
        
        if (mb_strpos($desc_lower, $category_lower, 0, 'UTF-8') !== false) {
            return 1.0;
        }
        
        // Часткове співпадіння за словами
        $desc_words = explode(' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $desc_lower));
        $category_words = explode(' ', $category_lower);
        
        $matches = 0;
        foreach ($category_words as $cat_word) {
            if (mb_strlen($cat_word, 'UTF-8') > 2 && in_array($cat_word, $desc_words)) {
                $matches++;
            }
        }
        
        $partial_score = count($category_words) > 0 ? $matches / count($category_words) : 0;
        
        // Семантичне порівняння
        if ($enable_semantic && $partial_score < 0.6) {
            // Беремо перші 500 символів опису для швидкості
            $short_desc = mb_substr($full_description, 0, 500, 'UTF-8');
            $semantic_score = $this->semantic_matcher->calculate_similarity($short_desc, $category_name);
            return max($partial_score, $semantic_score * 0.6);
        }
        
        return $partial_score;
    }
    
    /**
     * Скор за продажами
     */
    private function calculate_sales_score($product_id) {
        $sales = $this->tracker->get_product_sales($product_id);
        
        if ($sales === 0) {
            return 0;
        }
        
        // Логарифмічна шкала для нормалізації
        // Продукт з 100 продажами отримує скор ~0.67
        // Продукт з 1000 продажами отримає скор ~1.0
        $normalized = log10($sales + 1) / 3;
        
        return min($normalized, 1.0);
    }
    
    /**
     * Скор за відгуками
     */
    private function calculate_reviews_score($product_id) {
        $reviews_count = $this->tracker->get_product_reviews_count($product_id);
        
        if ($reviews_count === 0) {
            return 0;
        }
        
        // Логарифмічна шкала
        // 10 відгуків = ~0.5, 50 відгуків = ~0.85, 100 відгуків = 1.0
        $normalized = log10($reviews_count + 1) / 2;
        
        return min($normalized, 1.0);
    }
    
    /**
     * Скор за переглядами
     */
    private function calculate_views_score($product_id, $category_id) {
        $views = $this->tracker->get_product_views($product_id, $category_id, 30);
        
        if ($views === 0) {
            return 0;
        }
        
        // Логарифмічна шкала
        // 10 переглядів = ~0.33, 100 переглядів = ~0.67, 1000 переглядів = 1.0
        $normalized = log10($views + 1) / 3;
        
        return min($normalized, 1.0);
    }
    
    /**
     * Оновлення релевантності продукту в БД
     */
    public function update_product_relevance($product_id, $category_ids) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'spr_product_relevance';
        
        foreach ($category_ids as $category_id) {
            $score = $this->calculate_relevance_score($product_id, $category_id);
            
            // Оновлення або вставка
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE product_id = %d AND category_id = %d",
                $product_id,
                $category_id
            ));
            
            if ($existing) {
                $wpdb->update(
                    $table_name,
                    array(
                        'relevance_score' => $score,
                        'last_updated' => current_time('mysql')
                    ),
                    array(
                        'product_id' => $product_id,
                        'category_id' => $category_id
                    ),
                    array('%f', '%s'),
                    array('%d', '%d')
                );
            } else {
                $wpdb->insert(
                    $table_name,
                    array(
                        'product_id' => $product_id,
                        'category_id' => $category_id,
                        'relevance_score' => $score,
                        'last_updated' => current_time('mysql')
                    ),
                    array('%d', '%d', '%f', '%s')
                );
            }
        }
        
        // Очищення кешу
        wp_cache_delete('spr_relevance_' . $product_id);
    }
    
    /**
     * Отримання скору релевантності з кешу або БД
     */
    public function get_cached_relevance_score($product_id, $category_id) {
        global $wpdb;
        
        $cache_key = 'spr_relevance_' . $product_id . '_' . $category_id;
        $cached = wp_cache_get($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $table_name = $wpdb->prefix . 'spr_product_relevance';
        
        $score = $wpdb->get_var($wpdb->prepare(
            "SELECT relevance_score FROM $table_name 
            WHERE product_id = %d AND category_id = %d",
            $product_id,
            $category_id
        ));
        
        // Якщо скор не знайдено, обчислюємо його
        if ($score === null) {
            $score = $this->calculate_relevance_score($product_id, $category_id);
            $this->update_product_relevance($product_id, array($category_id));
        }
        
        wp_cache_set($cache_key, $score, '', 3600);
        
        return (float) $score;
    }
    
    /**
     * Масове оновлення релевантності для всіх продуктів
     */
    public function bulk_update_relevance() {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        
        $product_ids = get_posts($args);
        
        foreach ($product_ids as $product_id) {
            $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
            
            if (!empty($categories)) {
                $this->update_product_relevance($product_id, $categories);
            }
        }
    }
}
