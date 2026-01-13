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
        
        // Автоматичне оновлення при змінах продукту
        add_action('woocommerce_update_product', array($this, 'on_product_update'), 10, 1);
        add_action('woocommerce_new_product', array($this, 'on_product_update'), 10, 1);
        
        // Автоматичне оновлення при додаванні коментаря/відгуку
        add_action('comment_post', array($this, 'on_comment_added'), 10, 3);
        
        // Автоматичне оновлення при зміні мета-даних (ціна, stock, тощо)
        add_action('updated_post_meta', array($this, 'on_product_meta_update'), 10, 4);
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
     * Отримання унікального ідентифікатора користувача (user_id або session_id)
     */
    public function get_user_identifier() {
        $user_id = get_current_user_id();
        if ($user_id) {
            return array('type' => 'user', 'id' => $user_id);
        }
        
        $session_id = $this->get_session_id();
        if ($session_id) {
            return array('type' => 'session', 'id' => $session_id);
        }
        
        return null;
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
    /**
     * Планування оновлення релевантності (миттєво через Action Scheduler)
     */
    private function schedule_relevance_update($product_id, $category_ids) {
        // Перевіряємо чи увімкнено автоматичне оновлення
        $settings = get_option('spr_settings', array());
        $auto_update_enabled = isset($settings['enable_auto_update']) ? $settings['enable_auto_update'] : true;
        
        if (!$auto_update_enabled) {
            return; // Автоматичне оновлення вимкнено
        }
        
        // Використовуємо Action Scheduler для миттєвого оновлення
        if (function_exists('as_enqueue_async_action')) {
            // Асинхронне виконання (миттєво, не блокує поточний запит)
            as_enqueue_async_action(
                'spr_update_single_product',
                array(
                    'product_id' => $product_id,
                    'category_ids' => $category_ids
                ),
                'spr_auto_update'
            );
        } else {
            // Fallback на WP Cron якщо Action Scheduler недоступний
            if (!wp_next_scheduled('spr_update_relevance', array($product_id, $category_ids))) {
                wp_schedule_single_event(time() + 60, 'spr_update_relevance', array($product_id, $category_ids));
            }
        }
    }
    
    /**
     * Обробка оновлення/створення продукту
     */
    public function on_product_update($product_id) {
        // Отримуємо категорії продукту
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
        
        if (!empty($categories) && !is_wp_error($categories)) {
            $this->schedule_relevance_update($product_id, $categories);
        }
    }
    
    /**
     * Обробка додавання коментаря/відгуку
     */
    public function on_comment_added($comment_id, $comment_approved, $comment_data) {
        // Перевіряємо чи це коментар до продукту
        $comment = get_comment($comment_id);
        
        if (!$comment) {
            return;
        }
        
        $post = get_post($comment->comment_post_ID);
        
        if (!$post || $post->post_type !== 'product') {
            return;
        }
        
        // Якщо коментар схвалений, оновлюємо релевантність
        if ($comment_approved == 1 || $comment_approved === 'approve') {
            $product_id = $post->ID;
            $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
            
            if (!empty($categories) && !is_wp_error($categories)) {
                $this->schedule_relevance_update($product_id, $categories);
            }
        }
    }
    
    /**
     * Обробка оновлення мета-даних продукту
     */
    public function on_product_meta_update($meta_id, $post_id, $meta_key, $meta_value) {
        // Перевіряємо чи це продукт
        $post = get_post($post_id);
        
        if (!$post || $post->post_type !== 'product') {
            return;
        }
        
        // Список мета-ключів які впливають на релевантність
        $relevant_meta_keys = array(
            '_price',
            '_regular_price',
            '_sale_price',
            '_stock_status',
            '_visibility',
            '_featured',
            'total_sales' // Це оновлюється при покупці
        );
        
        // Якщо оновлено релевантний мета-ключ
        if (in_array($meta_key, $relevant_meta_keys)) {
            $categories = wp_get_post_terms($post_id, 'product_cat', array('fields' => 'ids'));
            
            if (!empty($categories) && !is_wp_error($categories)) {
                $this->schedule_relevance_update($post_id, $categories);
            }
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
    
    /**
     * Отримання продуктів переглянутих користувачем в категорії
     * 
     * @param int $category_id ID категорії
     * @param int $days Період в днях (за замовчуванням 30)
     * @return array Масив ID продуктів з кількістю переглядів
     */
    public function get_user_viewed_products_in_category($category_id, $days = 30) {
        global $wpdb;
        
        $user_identifier = $this->get_user_identifier();
        if (!$user_identifier) {
            return array();
        }
        
        $table_name = $wpdb->prefix . 'spr_product_views';
        
        if ($user_identifier['type'] === 'user') {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT product_id, COUNT(*) as view_count 
                FROM $table_name 
                WHERE category_id = %d 
                AND user_id = %d 
                AND view_date > DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY product_id
                ORDER BY view_count DESC",
                $category_id,
                $user_identifier['id'],
                $days
            ));
        } else {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT product_id, COUNT(*) as view_count 
                FROM $table_name 
                WHERE category_id = %d 
                AND session_id = %s 
                AND view_date > DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY product_id
                ORDER BY view_count DESC",
                $category_id,
                $user_identifier['id'],
                $days
            ));
        }
        
        $viewed_products = array();
        foreach ($results as $row) {
            $viewed_products[$row->product_id] = (int) $row->view_count;
        }
        
        return $viewed_products;
    }
    
    /**
     * Отримання всіх продуктів переглянутих користувачем
     * 
     * @param int $days Період в днях (за замовчуванням 30)
     * @return array Масив ID продуктів з кількістю переглядів
     */
    public function get_user_all_viewed_products($days = 30) {
        global $wpdb;
        
        $user_identifier = $this->get_user_identifier();
        if (!$user_identifier) {
            return array();
        }
        
        $table_name = $wpdb->prefix . 'spr_product_views';
        
        if ($user_identifier['type'] === 'user') {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT product_id, COUNT(*) as view_count 
                FROM $table_name 
                WHERE user_id = %d 
                AND view_date > DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY product_id
                ORDER BY view_count DESC",
                $user_identifier['id'],
                $days
            ));
        } else {
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT product_id, COUNT(*) as view_count 
                FROM $table_name 
                WHERE session_id = %s 
                AND view_date > DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY product_id
                ORDER BY view_count DESC",
                $user_identifier['id'],
                $days
            ));
        }
        
        $viewed_products = array();
        foreach ($results as $row) {
            $viewed_products[$row->product_id] = (int) $row->view_count;
        }
        
        return $viewed_products;
    }
    
    /**
     * Перевірка чи користувач переглядав продукт
     * 
     * @param int $product_id ID продукту
     * @param int $days Період в днях
     * @return int Кількість переглядів (0 якщо не переглядав)
     */
    public function has_user_viewed_product($product_id, $days = 30) {
        global $wpdb;
        
        $user_identifier = $this->get_user_identifier();
        if (!$user_identifier) {
            return 0;
        }
        
        $table_name = $wpdb->prefix . 'spr_product_views';
        
        if ($user_identifier['type'] === 'user') {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) 
                FROM $table_name 
                WHERE product_id = %d 
                AND user_id = %d 
                AND view_date > DATE_SUB(NOW(), INTERVAL %d DAY)",
                $product_id,
                $user_identifier['id'],
                $days
            ));
        } else {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) 
                FROM $table_name 
                WHERE product_id = %d 
                AND session_id = %s 
                AND view_date > DATE_SUB(NOW(), INTERVAL %d DAY)",
                $product_id,
                $user_identifier['id'],
                $days
            ));
        }
        
        return (int) $count;
    }
}

// Додавання cron задачі для очищення
add_action('spr_cleanup_views', array('SPR_Tracker', 'cleanup_old_views'));

if (!wp_next_scheduled('spr_cleanup_views')) {
    wp_schedule_event(time(), 'daily', 'spr_cleanup_views');
}
