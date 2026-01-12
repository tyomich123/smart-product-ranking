<?php
/**
 * Клас для відстеження поведінки користувачів
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPR_Tracker {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Відстеження переглядів продуктів
        add_action('woocommerce_before_single_product', array($this, 'track_product_view'));
        
        // Відстеження покупок
        add_action('woocommerce_order_status_completed', array($this, 'track_purchases'));
        add_action('woocommerce_order_status_processing', array($this, 'track_purchases'));
        
        // Управління сесіями
        add_action('init', array($this, 'start_session'));
    }
    
    /**
     * Ініціалізація сесії для анонімних користувачів
     */
    public function start_session() {
        if (!session_id() && get_option('spr_settings')['track_anonymous_users']) {
            session_start();
            if (!isset($_SESSION['spr_session_id'])) {
                $_SESSION['spr_session_id'] = $this->generate_session_id();
            }
        }
    }
    
    /**
     * Генерація унікального ID сесії
     */
    private function generate_session_id() {
        return md5(uniqid() . $_SERVER['REMOTE_ADDR'] . time());
    }
    
    /**
     * Отримання ID сесії
     */
    private function get_session_id() {
        return isset($_SESSION['spr_session_id']) ? $_SESSION['spr_session_id'] : null;
    }
    
    /**
     * Відстеження перегляду продукту
     */
    public function track_product_view() {
        global $product, $wpdb;
        
        if (!$product) {
            return;
        }
        
        $product_id = $product->get_id();
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        
        if (empty($categories)) {
            return;
        }
        
        $user_id = get_current_user_id();
        $session_id = $this->get_session_id();
        
        // Запис перегляду для кожної категорії продукту
        $table_name = $wpdb->prefix . 'spr_product_views';
        
        foreach ($categories as $category_id) {
            // Перевірка чи не записували перегляд за останні 30 хвилин
            $recent_view = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name 
                WHERE product_id = %d 
                AND category_id = %d 
                AND (user_id = %d OR session_id = %s)
                AND view_date > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                LIMIT 1",
                $product_id,
                $category_id,
                $user_id,
                $session_id
            ));
            
            if (!$recent_view) {
                $wpdb->insert(
                    $table_name,
                    array(
                        'product_id' => $product_id,
                        'category_id' => $category_id,
                        'user_id' => $user_id ?: null,
                        'session_id' => $session_id,
                        'view_date' => current_time('mysql')
                    ),
                    array('%d', '%d', '%d', '%s', '%s')
                );
            }
        }
        
        // Оновлення релевантності для цих категорій
        $this->schedule_relevance_update($product_id, $categories);
    }
    
    /**
     * Відстеження покупок
     */
    public function track_purchases($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $items = $order->get_items();
        $product_ids = array();
        
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            if ($product_id) {
                $product_ids[] = $product_id;
                
                // Оновлюємо мета-поле з кількістю продажів
                $sales_count = (int) get_post_meta($product_id, '_spr_sales_count', true);
                update_post_meta($product_id, '_spr_sales_count', $sales_count + $item->get_quantity());
            }
        }
        
        // Оновлення релевантності для куплених продуктів
        foreach ($product_ids as $product_id) {
            $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
            if (!empty($categories)) {
                $this->schedule_relevance_update($product_id, $categories);
            }
        }
    }
    
    /**
     * Отримання кількості переглядів продукту в категорії
     */
    public function get_product_views($product_id, $category_id, $days = 30) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'spr_product_views';
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name 
            WHERE product_id = %d 
            AND category_id = %d 
            AND view_date > DATE_SUB(NOW(), INTERVAL %d DAY)",
            $product_id,
            $category_id,
            $days
        ));
        
        return (int) $count;
    }
    
    /**
     * Отримання кількості продажів продукту
     */
    public function get_product_sales($product_id) {
        $sales = get_post_meta($product_id, '_spr_sales_count', true);
        
        // Якщо мета-поле не існує, спробуємо отримати з WooCommerce
        if ($sales === '') {
            $product = wc_get_product($product_id);
            if ($product) {
                $sales = $product->get_total_sales();
                update_post_meta($product_id, '_spr_sales_count', $sales);
            }
        }
        
        return (int) $sales;
    }
    
    /**
     * Отримання кількості відгуків
     */
    public function get_product_reviews_count($product_id) {
        $product = wc_get_product($product_id);
        return $product ? $product->get_review_count() : 0;
    }
    
    /**
     * Планування оновлення релевантності
     */
    private function schedule_relevance_update($product_id, $category_ids) {
        if (!wp_next_scheduled('spr_update_relevance', array($product_id, $category_ids))) {
            wp_schedule_single_event(time() + 300, 'spr_update_relevance', array($product_id, $category_ids));
        }
    }
    
    /**
     * Очищення старих записів переглядів
     */
    public static function cleanup_old_views() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'spr_product_views';
        
        // Видалення переглядів старіших за 90 днів
        $wpdb->query(
            "DELETE FROM $table_name 
            WHERE view_date < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
    }
}

// Додавання cron задачі для очищення
add_action('spr_cleanup_views', array('SPR_Tracker', 'cleanup_old_views'));

if (!wp_next_scheduled('spr_cleanup_views')) {
    wp_schedule_event(time(), 'daily', 'spr_cleanup_views');
}
