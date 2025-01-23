<?php
date_default_timezone_set('Europe/Moscow');

$config = json_decode(file_get_contents('config.json'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error parsing config.json: " . json_last_error_msg());
}

$sites = $config['sites'] ?? [];
$webhookUrl = $config['webhook_url'] ?? '';
$token = $config['token'] ?? '';
$chat_id = $config['chat_id'] ?? '';
$self_check_time = $config['self_check_time'] ?? '10:00';
$webhookUrl = str_replace('$TELEGRAM_BOT_TOKEN', $token, $webhookUrl);

if (empty($sites) || empty($webhookUrl)) {
    die("Invalid configuration. Ensure 'sites' and 'webhook_url' are set in config.json.");
}

function isSiteDown($url) {
    try {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true); // Only fetch headers
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Timeout in seconds
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode != 200;
    } catch (Exception $e) {
        echo "[ERROR] isSiteDown: " . $e->getMessage() . "\n";
    }
}

function sendAlert($message) {
    global $webhookUrl, $chat_id;
    try {
        $data = ['chat_id' => $chat_id, 'text' => $message];
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            echo "Webhook request failed: " . curl_error($ch) . "\n";
        } else {
            echo "Webhook sent successfully. Response: $response\n";
        }
        curl_close($ch);
    } catch (Exception $e) {
        echo "[ERROR] sendAlert: " . $e->getMessage() . "\n";
    }
}

function selfCheck() {
    global $webhookUrl, $self_check_time;
    if (date("H:i") === $self_check_time) {
        sendAlert('РАБОТАЕМ');
    }
}

foreach ($sites as $site) {
    if (isSiteDown($site)) {
        $message = "Site is down: $site";
        sendAlert($message);
    } else {
        echo "Site is up: $site\n";
    }
    selfCheck();
}
