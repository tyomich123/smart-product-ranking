<?php
/**
 * Модифікатор запитів для ранжування продуктів
 * ПРАВИЛЬНИЙ підхід через WooCommerce hooks
 *
 * @package SmartProductRanking
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPR_Query_Modifier {
    private static $instance = null;
    private $tracker;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->tracker = SPR_Tracker::get_instance();
        
        // Додавання опції сортування в dropdown
        add_filter('woocommerce_catalog_orderby', array($this, 'add_relevance_sorting'));
        
        // Встановлення за замовчуванням
        add_filter('woocommerce_default_catalog_orderby', array($this, 'set_default_sorting'));
        
        // ГОЛОВНИЙ хук - правильний WooCommerce метод для custom sorting
        add_filter('woocommerce_get_catalog_ordering_args', array($this, 'custom_catalog_ordering'), 20, 2);
        
        // Модифікація основного query
        add_action('pre_get_posts', array($this, 'modify_main_query'), 50);
    }
    
    /**
     * Додавання опції "За релевантністю" в dropdown
     */
    public function add_relevance_sorting($sortby) {
        $sortby['relevance'] = __('За релевантністю', 'smart-product-ranking');
        return $sortby;
    }
    
    /**
     * Встановлення за замовчуванням
     */
    public function set_default_sorting($default) {
        $settings = get_option('spr_settings', array());
        $use_as_default = isset($settings['use_as_default_sorting']) ? $settings['use_as_default_sorting'] : true;
        
        if ($use_as_default && (is_shop() || is_product_category() || is_product_tag())) {
            return 'relevance';
        }
        
        return $default;
    }
    
    /**
     * Модифікація основного query
     */
    public function modify_main_query($query) {
        // ТІЛЬКИ для головного запиту категорій товарів
        if (!is_admin() && 
            $query->is_main_query() && 
            is_product_category()) {
            
            // Перевіряємо налаштування
            $settings = get_option('spr_settings', array());
            $use_as_default = isset($settings['use_as_default_sorting']) ? $settings['use_as_default_sorting'] : true;
            
            if ($use_as_default) {
                // Якщо не вказано orderby або вказано menu_order - використовуємо relevance
                $orderby = $query->get('orderby');
                if (empty($orderby) || $orderby == 'menu_order' || $orderby == 'menu_order title') {
                    $query->set('orderby', 'relevance');
                }
            }
        }
    }
    
    /**
     * ГОЛОВНИЙ метод - правильний WooCommerce спосіб для custom sorting
     */
    public function custom_catalog_ordering($args, $orderby) {
        // Тільки для релевантності
        if ($orderby !== 'relevance') {
            return $args;
        }
        
        // Тільки для категорій товарів
        if (!is_product_category()) {
            return $args;
        }
        
        // Отримуємо ID категорії
        $queried_object = get_queried_object();
        if (!$queried_object || !isset($queried_object->term_id)) {
            return $args;
        }
        
        $category_id = $queried_object->term_id;
        
        // Отримуємо налаштування
        $settings = get_option('spr_settings', array());
        $show_unranked = isset($settings['show_unranked_products']) ? $settings['show_unranked_products'] : true;
        
        global $wpdb;
        $relevance_table = $wpdb->prefix . 'spr_product_relevance';
        
        // Використовуємо postmeta для сортування
        // Ключ: _spr_relevance_{category_id}
        $meta_key = '_spr_relevance_' . $category_id;
        
        if ($show_unranked) {
            // Показуємо всі товари
            // Використовуємо meta_query щоб створити clause для сортування
            $args['meta_query'] = array(
                'relation' => 'OR',
                'relevance_exists' => array(
                    'key' => $meta_key,
                    'compare' => 'EXISTS',
                    'type' => 'NUMERIC'
                ),
                'relevance_not_exists' => array(
                    'key' => $meta_key,
                    'compare' => 'NOT EXISTS'
                )
            );
            
            // Сортування: спочатку з метою, потім за датою
            $args['orderby'] = array(
                'relevance_exists' => 'DESC',
                'date' => 'DESC'
            );
            $args['meta_key'] = $meta_key;
        } else {
            // Тільки товари зі скором
            $args['meta_key'] = $meta_key;
            $args['orderby'] = 'meta_value_num';
            $args['order'] = 'DESC';
            
            // Додаємо meta_query щоб фільтрувати тільки товари з метою
            $args['meta_query'] = array(
                array(
                    'key' => $meta_key,
                    'compare' => 'EXISTS',
                    'type' => 'NUMERIC'
                )
            );
        }
        
        return $args;
    }
    
    /**
     * Отримання скору релевантності для продукту
     */
    public function get_product_relevance($product_id, $category_id) {
        // Спочатку перевіряємо postmeta
        $meta_key = '_spr_relevance_' . $category_id;
        $score = get_post_meta($product_id, $meta_key, true);
        
        if ($score !== '') {
            return floatval($score);
        }
        
        // Якщо в postmeta немає - шукаємо в таблиці
        global $wpdb;
        $table_name = $wpdb->prefix . 'spr_product_relevance';
        
        $score = $wpdb->get_var($wpdb->prepare(
            "SELECT relevance_score FROM $table_name 
            WHERE product_id = %d AND category_id = %d",
            $product_id,
            $category_id
        ));
        
        // Зберігаємо в postmeta для швидшого доступу
        if ($score !== null) {
            update_post_meta($product_id, $meta_key, floatval($score));
            return floatval($score);
        }
        
        return null;
    }
}
