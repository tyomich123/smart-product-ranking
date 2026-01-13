jQuery(document).ready(function($) {
    'use strict';
    
    let progressInterval = null;
    
    // Перерахунок релевантності
    $('#spr-recalculate-all').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $cancelButton = $('#spr-cancel-recalculation');
        var $spinner = $button.next('.spinner');
        var $progressContainer = $('#spr-progress-container');
        var $progressMessage = $('#spr-progress-message');
        
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $progressMessage.html('');
        
        $.ajax({
            url: sprAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'spr_start_recalculation',
                nonce: sprAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Показуємо контейнер прогресу
                    $progressContainer.slideDown();
                    $cancelButton.show();
                    
                    // Встановлюємо загальну кількість
                    $('#spr-total-count').text(response.data.total);
                    $('#spr-total-batches').text(response.data.batches);
                    
                    // Показуємо повідомлення
                    $progressMessage.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                    
                    // Запускаємо моніторинг прогресу
                    startProgressMonitoring();
                } else {
                    $progressMessage.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $progressMessage.html('<div class="notice notice-error inline"><p>Помилка при запуску перерахунку</p></div>');
            },
            complete: function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
    
    // Скасування перерахунку
    $('#spr-cancel-recalculation').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Ви впевнені що хочете скасувати перерахунок?')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true);
        
        $.ajax({
            url: sprAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'spr_cancel_recalculation',
                nonce: sprAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    stopProgressMonitoring();
                    $('#spr-progress-message').html('<div class="notice notice-warning inline"><p>' + response.data.message + '</p></div>');
                    $button.hide();
                    
                    // Ховаємо прогрес через 3 секунди
                    setTimeout(function() {
                        $('#spr-progress-container').slideUp();
                    }, 3000);
                }
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    // Функція моніторингу прогресу
    function startProgressMonitoring() {
        // Перевіряємо прогрес кожні 2 секунди
        progressInterval = setInterval(updateProgress, 2000);
        // Перший виклик одразу
        updateProgress();
    }
    
    function stopProgressMonitoring() {
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
    }
    
    function updateProgress() {
        $.ajax({
            url: sprAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'spr_get_progress',
                nonce: sprAdmin.nonce
            },
            success: function(response) {
                if (!response.success) {
                    return;
                }
                
                var data = response.data;
                
                // Оновлюємо прогрес бар
                $('#spr-progress-fill').css('width', data.progress + '%');
                $('#spr-progress-percent').text(data.progress + '%');
                
                // Оновлюємо статус
                $('#spr-progress-status').text(data.message);
                
                // Оновлюємо лічильники
                $('#spr-processed-count').text(data.processed_products);
                $('#spr-total-count').text(data.total_products);
                $('#spr-processed-batches').text(data.processed_batches);
                $('#spr-total-batches').text(data.total_batches);
                $('#spr-time-elapsed').text(data.time_elapsed);
                
                // Оновлюємо інформацію про чергу
                var queueInfo = '';
                if (data.pending_actions > 0) {
                    queueInfo += 'В черзі: ' + data.pending_actions + ' задач. ';
                }
                if (data.running_actions > 0) {
                    queueInfo += 'Виконується: ' + data.running_actions + ' задач.';
                }
                $('#spr-queue-info').html(queueInfo);
                
                // Перевіряємо статус
                if (data.status === 'completed') {
                    stopProgressMonitoring();
                    $('#spr-cancel-recalculation').hide();
                    $('#spr-progress-message').html('<div class="notice notice-success inline"><p><strong>✓ Завершено!</strong> ' + data.message + '</p></div>');
                    
                    // Ховаємо прогрес через 5 секунд
                    setTimeout(function() {
                        $('#spr-progress-container').slideUp();
                    }, 5000);
                } else if (data.status === 'failed') {
                    stopProgressMonitoring();
                    $('#spr-cancel-recalculation').hide();
                    $('#spr-progress-message').html('<div class="notice notice-error inline"><p><strong>✗ Помилка:</strong> ' + data.message + '</p></div>');
                } else if (data.status === 'cancelled') {
                    stopProgressMonitoring();
                    $('#spr-cancel-recalculation').hide();
                }
            },
            error: function() {
                // Продовжуємо спробувати
                console.log('Error fetching progress, will retry...');
            }
        });
    }
    
    // Перевіряємо чи є активний процес при завантаженні сторінки
    if ($('#spr-progress-container').length) {
        $.ajax({
            url: sprAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'spr_get_progress',
                nonce: sprAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data.status === 'running') {
                    // Є активний процес, показуємо прогрес
                    $('#spr-progress-container').show();
                    $('#spr-cancel-recalculation').show();
                    $('#spr-recalculate-all').prop('disabled', true);
                    
                    // Запускаємо моніторинг
                    startProgressMonitoring();
                }
            }
        });
    }
    
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
    
    // Очищення інтервалу при закритті сторінки
    $(window).on('beforeunload', function() {
        stopProgressMonitoring();
    });
});
