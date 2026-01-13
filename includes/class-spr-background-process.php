<?php
/**
 * Клас для фонового перерахунку релевантності через Action Scheduler
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPR_Background_Process {
    
    private static $instance = null;
    private $batch_size = 50; // Кількість продуктів за один раз
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Перевірка наявності Action Scheduler
        if (!function_exists('as_schedule_single_action')) {
            add_action('admin_notices', array($this, 'action_scheduler_missing_notice'));
            return;
        }
        
        // Реєстрація хуків для обробки
        add_action('spr_process_batch', array($this, 'process_batch'), 10, 3);
        add_action('spr_complete_recalculation', array($this, 'complete_recalculation'));
        
        // AJAX для запуску процесу
        add_action('wp_ajax_spr_start_recalculation', array($this, 'ajax_start_recalculation'));
        add_action('wp_ajax_spr_get_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_spr_cancel_recalculation', array($this, 'ajax_cancel_recalculation'));
    }
    
    /**
     * Повідомлення про відсутність Action Scheduler
     */
    public function action_scheduler_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Smart Product Ranking потребує Action Scheduler для фонової обробки. Будь ласка, встановіть плагін Action Scheduler.', 'smart-product-ranking'); ?></p>
        </div>
        <?php
    }
    
    /**
     * AJAX: Запуск перерахунку
     */
    public function ajax_start_recalculation() {
        check_ajax_referer('spr-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Недостатньо прав', 'smart-product-ranking')));
        }
        
        $result = $this->start_recalculation();
        
        if ($result) {
            wp_send_json_success(array(
                'message' => __('Перерахунок розпочато! Процес працює у фоновому режимі.', 'smart-product-ranking'),
                'total' => $result['total'],
                'batches' => $result['batches']
            ));
        } else {
            wp_send_json_error(array('message' => __('Не вдалося запустити перерахунок', 'smart-product-ranking')));
        }
    }
    
    /**
     * AJAX: Отримання прогресу
     */
    public function ajax_get_progress() {
        check_ajax_referer('spr-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Недостатньо прав', 'smart-product-ranking')));
        }
        
        $progress = $this->get_progress();
        wp_send_json_success($progress);
    }
    
    /**
     * AJAX: Скасування перерахунку
     */
    public function ajax_cancel_recalculation() {
        check_ajax_referer('spr-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Недостатньо прав', 'smart-product-ranking')));
        }
        
        $this->cancel_recalculation();
        
        wp_send_json_success(array('message' => __('Перерахунок скасовано', 'smart-product-ranking')));
    }
    
    /**
     * Запуск процесу перерахунку
     */
    public function start_recalculation() {
        // Перевірка чи вже йде процес
        $current_process = get_option('spr_recalculation_process');
        if ($current_process && $current_process['status'] === 'running') {
            return false;
        }
        
        // Отримання всіх ID продуктів
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'publish'
        );
        
        $product_ids = get_posts($args);
        $total_products = count($product_ids);
        
        if ($total_products === 0) {
            return false;
        }
        
        // Розбиття на батчі
        $batches = array_chunk($product_ids, $this->batch_size);
        $total_batches = count($batches);
        
        // Збереження інформації про процес
        $process_data = array(
            'status' => 'running',
            'total_products' => $total_products,
            'total_batches' => $total_batches,
            'processed_batches' => 0,
            'processed_products' => 0,
            'failed_products' => array(),
            'started_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        update_option('spr_recalculation_process', $process_data);
        
        // Скасування попередніх незавершених задач
        $this->cancel_pending_actions();
        
        // Планування батчів через Action Scheduler
        $current_time = time();
        foreach ($batches as $batch_index => $batch_product_ids) {
            // Розподіляємо задачі з невеликою затримкою (кожні 10 секунд)
            $scheduled_time = $current_time + ($batch_index * 10);
            
            as_schedule_single_action(
                $scheduled_time,
                'spr_process_batch',
                array(
                    'batch_index' => $batch_index,
                    'product_ids' => $batch_product_ids,
                    'total_batches' => $total_batches
                ),
                'spr_recalculation'
            );
        }
        
        // Планування фінальної задачі
        $final_time = $current_time + (($total_batches + 1) * 10);
        as_schedule_single_action(
            $final_time,
            'spr_complete_recalculation',
            array(),
            'spr_recalculation'
        );
        
        return array(
            'total' => $total_products,
            'batches' => $total_batches
        );
    }
    
    /**
     * Обробка одного батча
     */
    public function process_batch($batch_index, $product_ids, $total_batches) {
        $process_data = get_option('spr_recalculation_process');
        
        if (!$process_data || $process_data['status'] !== 'running') {
            return;
        }
        
        $ranking_engine = SPR_Ranking_Engine::get_instance();
        $failed = array();
        
        foreach ($product_ids as $product_id) {
            try {
                $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
                
                if (!empty($categories) && !is_wp_error($categories)) {
                    $ranking_engine->update_product_relevance($product_id, $categories);
                }
            } catch (Exception $e) {
                $failed[] = $product_id;
                error_log('SPR: Failed to process product ' . $product_id . ': ' . $e->getMessage());
            }
        }
        
        // Оновлення прогресу
        $process_data['processed_batches'] = $batch_index + 1;
        $process_data['processed_products'] += count($product_ids);
        $process_data['failed_products'] = array_merge($process_data['failed_products'], $failed);
        $process_data['updated_at'] = current_time('mysql');
        
        update_option('spr_recalculation_process', $process_data);
        
        // Очищення кешу
        wp_cache_flush();
    }
    
    /**
     * Завершення перерахунку
     */
    public function complete_recalculation() {
        $process_data = get_option('spr_recalculation_process');
        
        if (!$process_data) {
            return;
        }
        
        $process_data['status'] = 'completed';
        $process_data['completed_at'] = current_time('mysql');
        $process_data['updated_at'] = current_time('mysql');
        
        update_option('spr_recalculation_process', $process_data);
        
        // Очищення всього кешу
        wp_cache_flush();
    }
    
    /**
     * Отримання прогресу
     */
    public function get_progress() {
        $process_data = get_option('spr_recalculation_process');
        
        if (!$process_data) {
            return array(
                'status' => 'idle',
                'progress' => 0,
                'message' => __('Перерахунок не запущено', 'smart-product-ranking')
            );
        }
        
        $total = $process_data['total_products'];
        $processed = $process_data['processed_products'];
        $progress = $total > 0 ? round(($processed / $total) * 100, 2) : 0;
        
        // Перевірка зависших задач
        if ($process_data['status'] === 'running') {
            $pending_count = $this->get_pending_actions_count();
            $running_count = $this->get_running_actions_count();
            
            // Якщо немає задач в черзі і прогрес не 100%
            if ($pending_count === 0 && $running_count === 0 && $progress < 100) {
                // Можливо процес завис
                $last_update = strtotime($process_data['updated_at']);
                $now = time();
                
                // Якщо оновлення було більше 5 хвилин тому - позначаємо як failed
                if (($now - $last_update) > 300) {
                    $process_data['status'] = 'failed';
                    $process_data['updated_at'] = current_time('mysql');
                    update_option('spr_recalculation_process', $process_data);
                }
            }
        }
        
        $time_elapsed = '';
        if (isset($process_data['started_at'])) {
            $start_time = strtotime($process_data['started_at']);
            $current_time = isset($process_data['completed_at']) ? 
                strtotime($process_data['completed_at']) : time();
            
            $elapsed_seconds = $current_time - $start_time;
            $time_elapsed = $this->format_time_elapsed($elapsed_seconds);
        }
        
        return array(
            'status' => $process_data['status'],
            'progress' => $progress,
            'total_products' => $total,
            'processed_products' => $processed,
            'processed_batches' => $process_data['processed_batches'],
            'total_batches' => $process_data['total_batches'],
            'failed_products_count' => count($process_data['failed_products']),
            'pending_actions' => $this->get_pending_actions_count(),
            'running_actions' => $this->get_running_actions_count(),
            'time_elapsed' => $time_elapsed,
            'message' => $this->get_status_message($process_data)
        );
    }
    
    /**
     * Скасування перерахунку
     */
    public function cancel_recalculation() {
        $this->cancel_pending_actions();
        
        $process_data = get_option('spr_recalculation_process');
        if ($process_data) {
            $process_data['status'] = 'cancelled';
            $process_data['updated_at'] = current_time('mysql');
            update_option('spr_recalculation_process', $process_data);
        }
    }
    
    /**
     * Скасування всіх pending action scheduler задач
     */
    private function cancel_pending_actions() {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('spr_process_batch', array(), 'spr_recalculation');
            as_unschedule_all_actions('spr_complete_recalculation', array(), 'spr_recalculation');
        }
    }
    
    /**
     * Отримання кількості pending задач
     */
    private function get_pending_actions_count() {
        if (!function_exists('as_get_scheduled_actions')) {
            return 0;
        }
        
        $actions = as_get_scheduled_actions(array(
            'group' => 'spr_recalculation',
            'status' => 'pending',
            'per_page' => -1
        ), 'ids');
        
        return count($actions);
    }
    
    /**
     * Отримання кількості running задач
     */
    private function get_running_actions_count() {
        if (!function_exists('as_get_scheduled_actions')) {
            return 0;
        }
        
        $actions = as_get_scheduled_actions(array(
            'group' => 'spr_recalculation',
            'status' => 'in-progress',
            'per_page' => -1
        ), 'ids');
        
        return count($actions);
    }
    
    /**
     * Форматування часу
     */
    private function format_time_elapsed($seconds) {
        if ($seconds < 60) {
            return sprintf(__('%d секунд', 'smart-product-ranking'), $seconds);
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return sprintf(__('%d хв %d сек', 'smart-product-ranking'), $minutes, $secs);
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return sprintf(__('%d год %d хв', 'smart-product-ranking'), $hours, $minutes);
        }
    }
    
    /**
     * Отримання повідомлення про статус
     */
    private function get_status_message($process_data) {
        switch ($process_data['status']) {
            case 'running':
                return sprintf(
                    __('Обробка... %d з %d продуктів', 'smart-product-ranking'),
                    $process_data['processed_products'],
                    $process_data['total_products']
                );
            case 'completed':
                return sprintf(
                    __('Завершено! Оброблено %d продуктів', 'smart-product-ranking'),
                    $process_data['processed_products']
                );
            case 'failed':
                return __('Процес завис. Спробуйте запустити знову.', 'smart-product-ranking');
            case 'cancelled':
                return __('Перерахунок скасовано', 'smart-product-ranking');
            default:
                return __('Невідомий статус', 'smart-product-ranking');
        }
    }
    
    /**
     * Отримання розміру батча
     */
    public function get_batch_size() {
        return apply_filters('spr_batch_size', $this->batch_size);
    }
    
    /**
     * Встановлення розміру батча
     */
    public function set_batch_size($size) {
        $this->batch_size = max(1, min(500, intval($size)));
    }
}
