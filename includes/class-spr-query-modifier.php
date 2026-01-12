<?php
/**
 * Клас для модифікації WooCommerce запитів
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPR_Query_Modifier {
    
    private static $instance = null;
    private $ranking_engine;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->ranking_engine = SPR_Ranking_Engine::get_instance();
        
        // Модифікація запитів WooCommerce
        add_filter('woocommerce_product_query', array($this, 'modify_product_query'), 10, 2);
        
        // Модифікація сортування
        add_filter('posts_clauses', array($this, 'modify_query_clauses'), 10, 2);
        
        // Додавання нового варіанту сортування
        add_filter('woocommerce_catalog_orderby', array($this, 'add_relevance_sorting'));
        add_filter('woocommerce_get_catalog_ordering_args', array($this, 'get_relevance_sorting_args'));
        
        // Додавання мета-даних для сортування
        add_action('pre_get_posts', array($this, 'pre_get_posts_sorting'));
    }
    
    /**
     * Модифікація запиту продуктів
     */
    public function modify_product_query($q, $query) {
        // Перевіряємо чи це запит категорії
        if (!is_admin() && $q->is_main_query() && (is_product_category() || is_shop())) {
            // Встановлюємо мета-запит для включення скору релевантності
            $q->set('update_post_meta_cache', true);
        }
        
        return $q;
    }
    
    /**
     * Модифікація SQL запиту для сортування за релевантністю
     */
    public function modify_query_clauses($clauses, $query) {
        global $wpdb;
        
        // Перевіряємо чи це наш запит
        if (!is_admin() && 
            $query->is_main_query() && 
            (is_product_category() || is_shop()) &&
            isset($query->query_vars['orderby']) && 
            $query->query_vars['orderby'] === 'relevance') {
            
            $category_id = 0;
            
            // Отримуємо ID категорії
            if (is_product_category()) {
                $queried_object = get_queried_object();
                if ($queried_object) {
                    $category_id = $queried_object->term_id;
                }
            }
            
            if ($category_id > 0) {
                $relevance_table = $wpdb->prefix . 'spr_product_relevance';
                
                // Додаємо JOIN до таблиці релевантності
                $clauses['join'] .= " LEFT JOIN {$relevance_table} AS spr ON {$wpdb->posts}.ID = spr.product_id AND spr.category_id = {$category_id}";
                
                // Модифікуємо ORDER BY
                $order = isset($query->query_vars['order']) ? $query->query_vars['order'] : 'DESC';
                $clauses['orderby'] = "COALESCE(spr.relevance_score, 0) {$order}, {$wpdb->posts}.post_date DESC";
                
                // Додаємо DISTINCT щоб уникнути дублікатів
                $clauses['distinct'] = 'DISTINCT';
            }
        }
        
        return $clauses;
    }
    
    /**
     * Додавання сортування за релевантністю в WooCommerce
     */
    public function add_relevance_sorting($sortby) {
        $sortby['relevance'] = __('За релевантністю', 'smart-product-ranking');
        return $sortby;
    }
    
    /**
     * Аргументи для сортування за релевантністю
     */
    public function get_relevance_sorting_args($args) {
        if (isset($_GET['orderby']) && $_GET['orderby'] === 'relevance') {
            $args['orderby'] = 'relevance';
            $args['order'] = 'DESC';
        }
        
        return $args;
    }
    
    /**
     * Налаштування сортування в pre_get_posts
     */
    public function pre_get_posts_sorting($query) {
        if (!is_admin() && 
            $query->is_main_query() && 
            (is_product_category() || is_shop())) {
            
            // Якщо не вказано сортування, використовуємо релевантність
            if (!isset($_GET['orderby']) || empty($_GET['orderby'])) {
                $query->set('orderby', 'relevance');
                $query->set('order', 'DESC');
            }
        }
    }
    
    /**
     * Додавання скору релевантності до продукту (для використання в шаблонах)
     */
    public function get_product_relevance_display($product_id) {
        $category_id = 0;
        
        if (is_product_category()) {
            $queried_object = get_queried_object();
            if ($queried_object) {
                $category_id = $queried_object->term_id;
            }
        }
        
        if ($category_id > 0) {
            $score = $this->ranking_engine->get_cached_relevance_score($product_id, $category_id);
            return round($score, 2);
        }
        
        return 0;
    }
    
    /**
     * Шорткод для відображення скору релевантності
     */
    public function relevance_score_shortcode($atts) {
        global $product;
        
        if (!$product) {
            return '';
        }
        
        $atts = shortcode_atts(array(
            'show_label' => 'yes'
        ), $atts);
        
        $score = $this->get_product_relevance_display($product->get_id());
        
        if ($score > 0) {
            $output = '<div class="spr-relevance-score">';
            
            if ($atts['show_label'] === 'yes') {
                $output .= '<span class="spr-label">' . __('Релевантність:', 'smart-product-ranking') . ' </span>';
            }
            
            $output .= '<span class="spr-score">' . $score . '%</span>';
            $output .= '</div>';
            
            return $output;
        }
        
        return '';
    }
}

// Реєстрація шорткоду
add_shortcode('product_relevance', array(SPR_Query_Modifier::get_instance(), 'relevance_score_shortcode'));

/**
 * Хелпер-функція для отримання скору релевантності
 */
function spr_get_product_relevance($product_id = null) {
    if (!$product_id) {
        global $product;
        if ($product) {
            $product_id = $product->get_id();
        }
    }
    
    if (!$product_id) {
        return 0;
    }
    
    $modifier = SPR_Query_Modifier::get_instance();
    return $modifier->get_product_relevance_display($product_id);
}
