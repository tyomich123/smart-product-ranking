jQuery(document).ready(function($) {
    'use strict';
    
    // Перерахунок релевантності
    $('#spr-recalculate-all').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $spinner = $button.next('.spinner');
        var $message = $('.spr-message');
        
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $message.text('');
        
        $.ajax({
            url: sprAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'spr_recalculate_all',
                nonce: sprAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $message.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                } else {
                    $message.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                }
            },
            error: function() {
                $message.html('<span style="color: red;">✗ Помилка при виконанні запиту</span>');
            },
            complete: function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
    
    // Очищення даних
    $('#spr-clear-data').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Ви впевнені? Це видалить всі дані про перегляди та релевантність. Цю дію неможливо скасувати!')) {
            return;
        }
        
        var $button = $(this);
        var $message = $('.spr-clear-message');
        
        $button.prop('disabled', true);
        $message.text('');
        
        $.ajax({
            url: sprAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'spr_clear_data',
                nonce: sprAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $message.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                    
                    // Перезавантаження сторінки через 2 секунди
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $message.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                }
            },
            error: function() {
                $message.html('<span style="color: red;">✗ Помилка при виконанні запиту</span>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
});
