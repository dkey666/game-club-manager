<?php
/**
 * webhook_setup.php - запустите один раз для настройки вебхука
 * Теперь использует конфигурацию из .env файла
 */

// Подключаем конфигурацию
require_once __DIR__ . '/config.php';

try {
    $token = BOT_TOKEN; // Используем токен из .env файла
    $webhook_url = Config::get('WEBHOOK_URL', APP_URL . '/index.php?webhook=1');
    
    $url = "https://api.telegram.org/bot$token/setWebhook";
    $data = [
        'url' => $webhook_url
    ];
    
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($data)
        ]
    ];
    
    $result = file_get_contents($url, false, stream_context_create($options));
    
    if ($result) {
        $response = json_decode($result, true);
        if ($response && $response['ok']) {
            echo "✅ Webhook успешно установлен!\n";
            echo "URL: $webhook_url\n";
            echo "Ответ сервера: " . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            echo "❌ Ошибка при установке webhook:\n";
            echo $result;
        }
    } else {
        echo "❌ Не удалось получить ответ от Telegram API";
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "Убедитесь, что файл .env создан на основе .env.example и содержит BOT_TOKEN\n";
}
?>
