<?php
/**
 * Приклади використання плагіна Smart Product Ranking в темі
 * 
 * Скопіюйте потрібні приклади в файл functions.php вашої теми
 */

// ============================================
// ПРИКЛАД 1: Відображення скору релевантності на сторінці продукту
// ============================================

add_action('woocommerce_single_product_summary', 'display_product_relevance_score', 25);

function display_product_relevance_score() {
    global $product;
    
    if (!$product) {
        return;
    }
    
    // Отримуємо скор релевантності
    $relevance = spr_get_product_relevance($product->get_id());
    
    if ($relevance > 0) {
        echo '<div class="product-relevance-score">';
        echo '<strong>Релевантність для цієї категорії:</strong> ';
        echo '<span style="color: #0073aa; font-weight: bold;">' . $relevance . '%</span>';
        echo '</div>';
    }
}


// ============================================
// ПРИКЛАД 2: Відображення бейджу "Найрелевантніший" для топових продуктів
// ============================================

add_action('woocommerce_before_shop_loop_item_title', 'add_relevance_badge', 15);

function add_relevance_badge() {
    global $product;
    
    $relevance = spr_get_product_relevance($product->get_id());
    
    // Якщо релевантність вище 80%, показуємо бейдж
    if ($relevance >= 80) {
        echo '<div class="relevance-badge" style="position: absolute; top: 10px; left: 10px; background: #0073aa; color: white; padding: 5px 10px; font-size: 12px; border-radius: 3px; z-index: 10;">';
        echo '⭐ Найкраще співпадіння';
        echo '</div>';
    }
}


// ============================================
// ПРИКЛАД 3: Додавання індикатора популярності
// ============================================

add_action('woocommerce_after_shop_loop_item_title', 'add_popularity_indicator', 15);

function add_popularity_indicator() {
    global $product;
    
    // Отримуємо кількість переглядів
    $tracker = SPR_Tracker::get_instance();
    $category_id = get_queried_object_id();
    
    if ($category_id) {
        $views = $tracker->get_product_views($product->get_id(), $category_id, 30);
        $sales = $tracker->get_product_sales($product->get_id());
        
        if ($views > 50 || $sales > 10) {
            echo '<div class="popularity-indicator" style="color: #ff6b35; font-size: 12px; margin-top: 5px;">';
            echo '🔥 Популярний товар';
            echo '</div>';
        }
    }
}


// ============================================
// ПРИКЛАД 4: Створення віджету з топ продуктами за релевантністю
// ============================================

function get_top_relevant_products($category_id, $limit = 5) {
    global $wpdb;
    
    $relevance_table = $wpdb->prefix . 'spr_product_relevance';
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT product_id, relevance_score 
        FROM {$relevance_table} 
        WHERE category_id = %d 
        ORDER BY relevance_score DESC 
        LIMIT %d",
        $category_id,
        $limit
    ));
    
    return $results;
}

// Використання в шаблоні:
/*
$top_products = get_top_relevant_products(get_queried_object_id(), 5);

if ($top_products) {
    echo '<div class="top-relevant-products">';
    echo '<h3>Найрелевантніші товари</h3>';
    echo '<ul>';
    
    foreach ($top_products as $item) {
        $product = wc_get_product($item->product_id);
        if ($product) {
            echo '<li>';
            echo '<a href="' . get_permalink($product->get_id()) . '">' . $product->get_name() . '</a>';
            echo ' (' . round($item->relevance_score, 1) . '%)';
            echo '</li>';
        }
    }
    
    echo '</ul>';
    echo '</div>';
}
*/


// ============================================
// ПРИКЛАД 5: Кастомне сортування в WooCommerce шорткодах
// ============================================

add_filter('woocommerce_shortcode_products_query', 'custom_shortcode_relevance_sorting', 10, 3);

function custom_shortcode_relevance_sorting($query_args, $attributes, $type) {
    // Якщо встановлено атрибут orderby="relevance"
    if (isset($attributes['orderby']) && $attributes['orderby'] === 'relevance') {
        $query_args['orderby'] = 'relevance';
        $query_args['order'] = 'DESC';
    }
    
    return $query_args;
}

// Використання в шорткоді:
// [products limit="8" columns="4" orderby="relevance" category="electronics"]


// ============================================
// ПРИКЛАД 6: Відображення статистики продукту в адмінці
// ============================================

add_action('woocommerce_product_options_general_product_data', 'add_relevance_info_to_product_page');

function add_relevance_info_to_product_page() {
    global $post, $wpdb;
    
    $product_id = $post->ID;
    $tracker = SPR_Tracker::get_instance();
    $relevance_table = $wpdb->prefix . 'spr_product_relevance';
    
    // Отримуємо середню релевантність
    $avg_relevance = $wpdb->get_var($wpdb->prepare(
        "SELECT AVG(relevance_score) FROM {$relevance_table} WHERE product_id = %d",
        $product_id
    ));
    
    // Отримуємо загальну кількість переглядів
    $total_views = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}spr_product_views 
        WHERE product_id = %d AND view_date > DATE_SUB(NOW(), INTERVAL 30 DAY)",
        $product_id
    ));
    
    echo '<div class="options_group">';
    echo '<h3 style="padding-left: 12px;">📊 Статистика ранжування</h3>';
    
    if ($avg_relevance !== null) {
        echo '<p class="form-field" style="padding-left: 12px;">';
        echo '<strong>Середня релевантність:</strong> ' . round($avg_relevance, 2) . '%';
        echo '</p>';
    }
    
    echo '<p class="form-field" style="padding-left: 12px;">';
    echo '<strong>Переглядів за 30 днів:</strong> ' . number_format($total_views);
    echo '</p>';
    
    $sales = $tracker->get_product_sales($product_id);
    echo '<p class="form-field" style="padding-left: 12px;">';
    echo '<strong>Всього продажів:</strong> ' . number_format($sales);
    echo '</p>';
    
    echo '</div>';
}


// ============================================
// ПРИКЛАД 7: Хук для модифікації скору релевантності
// ============================================

add_filter('spr_relevance_score', 'custom_relevance_modifier', 10, 3);

function custom_relevance_modifier($score, $product_id, $category_id) {
    // Приклад: підвищуємо релевантність для продуктів зі знижкою
    $product = wc_get_product($product_id);
    
    if ($product && $product->is_on_sale()) {
        // Додаємо 10% до скору для товарів зі знижкою
        $score = min($score * 1.1, 100);
    }
    
    return $score;
}


// ============================================
// ПРИКЛАД 8: Показ релевантності в результатах пошуку
// ============================================

add_action('woocommerce_after_shop_loop_item', 'show_search_relevance', 5);

function show_search_relevance() {
    if (is_search()) {
        global $product;
        
        $relevance = spr_get_product_relevance($product->get_id());
        
        if ($relevance > 0) {
            echo '<div style="font-size: 12px; color: #666; margin-top: 5px;">';
            echo 'Співпадіння: ' . $relevance . '%';
            echo '</div>';
        }
    }
}


// ============================================
// ПРИКЛАД 9: REST API endpoint для отримання релевантності
// ============================================

add_action('rest_api_init', function () {
    register_rest_route('spr/v1', '/relevance/(?P<product_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'get_product_relevance_api',
        'permission_callback' => '__return_true'
    ));
});

function get_product_relevance_api($request) {
    $product_id = $request['product_id'];
    $relevance = spr_get_product_relevance($product_id);
    
    return array(
        'product_id' => $product_id,
        'relevance_score' => $relevance,
        'timestamp' => current_time('mysql')
    );
}

// Використання: GET /wp-json/spr/v1/relevance/123


// ============================================
// ПРИКЛАД 10: CSS стилі для покращення відображення
// ============================================

add_action('wp_head', 'spr_custom_styles');

function spr_custom_styles() {
    ?>
    <style>
        .product-relevance-score {
            background: #f0f0f0;
            padding: 10px;
            margin: 15px 0;
            border-left: 3px solid #0073aa;
        }
        
        .relevance-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 5px 12px;
            font-size: 11px;
            font-weight: bold;
            border-radius: 20px;
            z-index: 10;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .popularity-indicator {
            display: inline-block;
            background: #fff3cd;
            color: #856404;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            margin-top: 5px;
        }
        
        .spr-relevance-score {
            display: inline-block;
            background: #0073aa;
            color: white;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
    </style>
    <?php
}
