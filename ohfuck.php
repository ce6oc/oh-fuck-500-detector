<?php
/**
 * Website Availability Monitor
 * Run via cron, sends Telegram alerts on HTTP errors
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Moscow');

// ─────────────────────────────────────────────────────────────
// Configuration Loading
// ─────────────────────────────────────────────────────────────
$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    die("ERROR: config.json not found\n");
}

$configRaw = file_get_contents($configFile);
$config = json_decode($configRaw, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("ERROR parsing config.json: " . json_last_error_msg() . "\n");
}

$sites = $config['sites'] ?? [];
// ✅ Trim and validate site URLs
$sites = array_map('trim', $sites);
$sites = array_filter($sites, function($url) {
    return !empty($url) && filter_var($url, FILTER_VALIDATE_URL);
});

if (empty($sites)) {
    die("ERROR: No valid URLs found in config.json sites array\n");
}
$telegramConfig = $config['telegram'] ?? [];
$settings = $config['settings'] ?? [];

// Load token from env var (preferred) or fallback to config
$token = getenv($telegramConfig['token_env'] ?? 'TELEGRAM_BOT_TOKEN') 
         ?: ($telegramConfig['token'] ?? '');
$chatId = $telegramConfig['chat_id'] ?? '';
$webhookTemplate = $telegramConfig['webhook_template'] ?? 'https://api.telegram.org/bot$TOKEN/sendMessage';
$webhookUrl = str_replace('$TOKEN', $token, $webhookTemplate);

$selfCheckTime = $settings['self_check_time'] ?? '10:00';
$timeout = (int)($settings['timeout'] ?? 10);
$alertCooldown = (int)($settings['alert_cooldown_minutes'] ?? 60) * 60;
$maxRetries = (int)($settings['max_retries'] ?? 2);

$stateFile = __DIR__ . '/state.json';
$logFile = __DIR__ . '/monitor.log';

// Validate config
if (empty($sites) || empty($webhookUrl) || empty($chatId)) {
    die("ERROR: Invalid configuration. Ensure 'sites', 'telegram', and required fields are set.\n");
}

function getRandomUserAgent(): string {
    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64; rv:123.0) Gecko/20100101 Firefox/123.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3 Safari/605.1.15',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
    ];
    return $userAgents[array_rand($userAgents)];
}

// ─────────────────────────────────────────────────────────────
// Logging
// ─────────────────────────────────────────────────────────────
function logMessage(string $level, string $message): void {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $entry = sprintf("[%s] [%s] %s\n", $timestamp, $level, $message);
    
    // Append to log file with lock
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    
    // Also output to stdout for cron email notifications
    echo $entry;
}

// ─────────────────────────────────────────────────────────────
// HTTP Check with Retry Logic
// ─────────────────────────────────────────────────────────────
function isSiteDown(string $url, int $timeout = 10): array {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => max(3, (int)($timeout * 0.5)),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => getRandomUserAgent(),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
    ]);
    
    $result = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['up' => false, 'code' => 0, 'error' => $error];
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $isUp = ($httpCode >= 200 && $httpCode < 400);
    
    return [
        'up' => $isUp,
        'code' => $httpCode,
        'error' => null,
    ];
}

// Update isSiteDownWithRetry to use the array return:
function isSiteDownWithRetry(string $url, int $maxRetries = 2, int $timeout = 10): array {
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $result = isSiteDown($url, $timeout);
        if ($result['up']) {
            if ($attempt > 1) {
                logMessage('RETRY_SUCCESS', "$url recovered on attempt $attempt");
            }
            return $result;
        }
        if ($attempt < $maxRetries) {
            $delay = 500000;
            logMessage('RETRY', "$url failed, retrying in " . ($delay/1000000) . "s (attempt $attempt/$maxRetries)");
            usleep($delay);
        }
    }
    return $result; // Still down after retries
}

// ─────────────────────────────────────────────────────────────
// Telegram Alert
// ─────────────────────────────────────────────────────────────
function sendAlert(string $message): bool {
    global $webhookUrl, $chatId;
    
    try {
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ];
        
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        
        if ($errno) {
            logMessage('TELEGRAM_ERROR', "Request failed: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        $responseData = json_decode($response, true);
        if ($httpCode === 200 && ($responseData['ok'] ?? false)) {
            logMessage('TELEGRAM_SENT', "Alert sent successfully");
            return true;
        } else {
            logMessage('TELEGRAM_ERROR', "Unexpected response: HTTP $httpCode, body: $response");
            return false;
        }
    } catch (Throwable $e) {
        logMessage('EXCEPTION', "sendAlert: " . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// State Management (prevent alert spam)
// ─────────────────────────────────────────────────────────────
function loadState(string $file): array {
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    $state = json_decode($content, true);
    return is_array($state) ? $state : [];
}

function saveState(string $file, array $state): void {
    $content = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($file, $content, LOCK_EX);
}

// ─────────────────────────────────────────────────────────────
// Self-Check (monitor heartbeat)
// ─────────────────────────────────────────────────────────────
function selfCheck(string $scheduledTime): void {
    $now = new DateTime();
    $scheduled = DateTime::createFromFormat('H:i', $scheduledTime);
    
    if (!$scheduled) {
        return;
    }
    
    // Set scheduled time to today
    $scheduled->setDate(
        (int)$now->format('Y'),
        (int)$now->format('m'),
        (int)$now->format('d')
    );
    
    // Allow 5-minute window for cron drift
    $windowStart = clone $scheduled;
    $windowStart->modify('-5 minutes');
    $windowEnd = clone $scheduled;
    $windowEnd->modify('+5 minutes');
    
    if ($now >= $windowStart && $now <= $windowEnd) {
        $uptime = trim(shell_exec('uptime -p 2>/dev/null') ?: 'unknown');
        $load = trim(shell_exec('uptime | awk -F"load average:" \'{print $2}\' 2>/dev/null') ?: 'unknown');
        $message = sprintf(
            "🤠 <b>Monitor Heartbeat</b>\n📅 %s\n⏱ Uptime: %s\n📊 Load:%s",
            date('Y-m-d H:i'),
            $uptime,
            $load
        );
        sendAlert($message);
        logMessage('SELF_CHECK', "Heartbeat sent");
    }
}

// ─────────────────────────────────────────────────────────────
// Parallel Site Checking - PHP 8+ Compatible
// ─────────────────────────────────────────────────────────────
function checkSitesParallel(array $sites, int $timeout = 10, int $maxRetries = 2): array {
    $multi = curl_multi_init();
    $handles = [];
    $results = [];
    
    foreach ($sites as $site) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $site,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => max(3, (int)($timeout * 0.5)),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => getRandomUserAgent(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$site] = $ch;
    }
    
    $running = null;
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 1);
    } while ($running > 0);
    
    foreach ($handles as $site => $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        
        $isUp = empty($error) && ($httpCode >= 200 && $httpCode < 400);
        
        $results[$site] = [
            'up' => $isUp,
            'code' => $httpCode,
            'error' => $error ?: null,
            'effective_url' => $effectiveUrl,
        ];
        
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    
    curl_multi_close($multi);
    
    foreach ($results as $site => &$result) {
        if (!$result['up']) {
            $result['up'] = !isSiteDownWithRetry($site, $maxRetries, $timeout);
            if ($result['up']) {
                $result['recovered_after_retry'] = true;
            }
        }
    }
    
    return $results;
}

// ─────────────────────────────────────────────────────────────
// Main Execution
// ─────────────────────────────────────────────────────────────
function run(array $sites, int $timeout, int $maxRetries, int $alertCooldown): int {
    global $stateFile;
    
    $state = loadState($stateFile);
    $now = time();
    $exitCode = 0;
    $checkedCount = 0;
    $downCount = 0;
    
    logMessage('INFO', "Starting monitor check for " . count($sites) . " site(s)");
    
    // Check all sites in parallel
    $results = checkSitesParallel($sites, $timeout, $maxRetries);
    
    foreach ($results as $site => $result) {
        $checkedCount++;
        $key = md5($site);
        
        if (!$result['up']) {
            $downCount++;
            $exitCode = max($exitCode, 2);
            
            // ✅ Enhanced error info display
            if (!empty($result['error'])) {
                // Show specific curl error for connection issues
                $errorLower = strtolower($result['error']);
                if (str_contains($errorLower, 'could not resolve host')) {
                    $httpInfo = '🌐 DNS resolution failed';
                } elseif (str_contains($errorLower, 'connection timed out')) {
                    $httpInfo = '⏱ Connection timed out';
                } elseif (str_contains($errorLower, 'ssl')) {
                    $httpInfo = '🔐 SSL/TLS error';
                } else {
                    $httpInfo = '🔌 ' . $result['error'];
                }
            } elseif ($result['code']) {
                $httpInfo = "HTTP {$result['code']}";
            } else {
                $httpInfo = "Unknown error";
            }
            
            $lastAlert = $state[$key]['last_alert'] ?? 0;
            $wasAlreadyDown = ($state[$key]['status'] ?? null) === 'down';
            
            if (!$wasAlreadyDown || ($now - $lastAlert) >= $alertCooldown) {
                // ✅ Context-aware emojis
                if (!empty($result['error'])) {
                    $emoji = str_contains(strtolower($result['error']), 'ssl') ? '🔐' : '🔌';
                } elseif ($result['code'] >= 500) {
                    $emoji = '🔥';
                } elseif ($result['code'] >= 400) {
                    $emoji = '⚠️';
                } else {
                    $emoji = '🔴';
                }
                
                $message = sprintf(
                    "%s <b>DOWN</b>: %s\n(%s)%s",
                    $emoji,
                    htmlspecialchars($site),
                    $httpInfo,
                    $wasAlreadyDown ? "\n⏰ Alert suppressed (cooldown active)" : ""
                );
                
                if (sendAlert($message)) {
                    $state[$key] = [
                        'status' => 'down',
                        'last_alert' => $now,
                        'http_code' => $result['code'],
                        'error' => $result['error'],
                    ];
                    logMessage('ALERT_SENT', "$site - alert sent");
                }
            } else {
                logMessage('ALERT_SUPPRESSED', "$site - still down, cooldown active");
            }
        } else {
            // Recovery logic
            if (isset($state[$key]) && $state[$key]['status'] === 'down') {
                $message = sprintf("🟢 <b>RECOVERED</b>: %s", htmlspecialchars($site));
                sendAlert($message);
                logMessage('RECOVERY', "$site is back up");
                unset($state[$key]);
            }
            logMessage('OK', "✓ $site is up" . ($result['recovered_after_retry'] ?? false ? " (recovered after retry)" : ""));
        }
    }
    
    // Save state
    saveState($stateFile, $state);
    
    // Self-check heartbeat
    global $selfCheckTime;
    selfCheck($selfCheckTime);
    
    // Summary
    $summary = sprintf(
        "Check complete: %d/%d sites up, %d down",
        $checkedCount - $downCount,
        $checkedCount,
        $downCount
    );
    logMessage('INFO', $summary);
    
    return $exitCode;
}

// ─────────────────────────────────────────────────────────────
// Entry Point
// ─────────────────────────────────────────────────────────────
try {
    $exitCode = run($sites, $timeout, $maxRetries, $alertCooldown);
    exit($exitCode);
} catch (Throwable $e) {
    logMessage('FATAL', "Unhandled exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    // Try to send critical alert
    sendAlert("💥 <b>Monitor Crashed</b>\n" . htmlspecialchars($e->getMessage()));
    exit(3); // Unexpected error
}