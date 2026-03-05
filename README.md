# 🌐 Website Availability Monitor

A production-ready PHP script that monitors website availability and sends instant Telegram alerts when sites go down or come back online. Designed to run via cron with intelligent alert deduplication, retry logic, and parallel checking.

---

## ✨ Features

| Feature | Description |
|---------|-------------|
| 🚫 **Alert Deduplication** | Prevents Telegram spam with state persistence and configurable cooldown |
| 🔄 **Recovery Notifications** | Alerts when a down site comes back online |
| ⚡ **Parallel Checks** | Monitors multiple sites simultaneously via `curl_multi` |
| 🔁 **Retry Logic** | Handles temporary network glitches with configurable retries |
| 📝 **Structured Logging** | Detailed logs with levels for easy debugging |
| 🔐 **Secure Config** | Supports environment variables for sensitive tokens |
| 📊 **Exit Codes** | Proper codes (0/2/3) for cron and external monitoring |
| 💓 **Heartbeat** | Daily self-check to confirm monitor is running |
| 🔒 **SSL Verification** | Full HTTPS certificate validation enabled |
| 🎯 **Smart HTTP Handling** | Accepts 2xx/3xx codes, follows redirects |

---

## 📋 Requirements

- **PHP** 7.4+ (8.0+ recommended)
- **cURL** extension enabled
- **JSON** extension enabled
- **Telegram Bot** (free via [@BotFather](https://t.me/botfather))
- **Cron** access (Linux/Unix) or task scheduler

---

## 🚀 Quick Start

### 1. Clone or Download

```bash
git clone https://github.com/ce6oc/oh-fuck-500-detector.git
cd oh-fuck-500-detector
```

### 2. Install Dependencies

```bash
# Verify PHP and cURL are available
php -v
php -m | grep curl
```

### 3. Configure

```bash
# Copy example config
cp config.json.example config.json

# Edit with your settings
nano config.json
```

### 4. Set Environment Variable (Recommended)

```bash
# Add to your shell profile
echo 'export TELEGRAM_BOT_TOKEN="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11"' >> ~/.bashrc
source ~/.bashrc

# Or set inline in crontab (see below)
```

### 5. Test Run

```bash
php ohfuck.php
```

### 6. Set Up Cron

```bash
crontab -e
```

Add this line to run every 5 minutes:

```cron
*/5 * * * * TELEGRAM_BOT_TOKEN="your_token" /usr/bin/php /path/to/ohfuck.php >> /var/log/monitor-cron.log 2>&1
```

---

## 📁 Project Structure

```
oh-fuck-500-detector/
├── ohfuck.php          # Main monitoring script
├── config.json          # Configuration file (edit this)
├── config.json.example  # Example configuration
├── state.json           # Alert state tracking (auto-generated)
├── monitor.log          # Log file (auto-generated)
└── README.md            # This file
```

---

## ⚙️ Configuration

### `config.json` Structure

```json
{
  "sites": [
    "https://example.com",
    "https://api.example.com/health",
    "https://shop.example.com"
  ],
  "telegram": {
    "token_env": "TELEGRAM_BOT_TOKEN",
    "chat_id": 123456789,
    "webhook_template": "https://api.telegram.org/bot$TOKEN/sendMessage",
    "token": "your_telegram_bot_token_here"
  },
  "settings": {
    "timeout": 15,
    "alert_cooldown_minutes": 60,
    "max_retries": 2,
    "self_check_time": "10:00"
  }
}
```

### Configuration Options

| Section | Key | Type | Default | Description |
|---------|-----|------|---------|-------------|
| `sites` | - | array | *required* | List of URLs to monitor |
| `telegram` | `token_env` | string | `TELEGRAM_BOT_TOKEN` | Environment variable name for bot token |
| `telegram` | `token` | string | - | Fallback token (less secure, not recommended) |
| `telegram` | `chat_id` | integer | *required* | Telegram chat/user ID for alerts |
| `telegram` | `webhook_template` | string | Telegram API URL | Custom webhook URL template |
| `settings` | `timeout` | integer | `10` | Request timeout in seconds |
| `settings` | `alert_cooldown_minutes` | integer | `60` | Minutes between repeated alerts for same site |
| `settings` | `max_retries` | integer | `2` | Number of retry attempts before marking as down |
| `settings` | `self_check_time` | string | `10:00` | Daily heartbeat time (HH:MM format) |

---

## 🤖 Getting Telegram Bot Token & Chat ID

### 1. Create a Bot

1. Open Telegram and search for **@BotFather**
2. Send `/newbot` command
3. Follow prompts to name your bot
4. Save the **API token** (looks like: `123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11`)

### 2. Get Your Chat ID

**Option A: Use a bot**
1. Search for **@userinfobot** or **@getmyid_bot**
2. Start the bot and it will show your ID

**Option B: Manual method**
1. Send a message to your new bot
2. Visit: `https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates`
3. Look for `"chat":{"id":123456789}` in the JSON response

### 3. Add Bot to Group (Optional)

1. Create a Telegram group
2. Add your bot as a member
3. Get the **group ID** (negative number, e.g., `-987654321`)
4. Use this as `chat_id` in config

---

## 📊 Alert Messages

### Site Down Alert
```
🔴 DOWN: https://example.com
(HTTP 500)
```

### Site Recovery Alert
```
🟢 RECOVERED: https://example.com
```

### Daily Heartbeat
```
🤠 Monitor Heartbeat
📅 2024-01-15 10:00
⏱ Uptime: up 45 days, 12:34
📊 Load: 0.15, 0.10, 0.05
```

---

## 📝 Logging

All activity is logged to `monitor.log` with timestamps and levels:

```log
[2024-01-15 10:05:01] [INFO] Starting monitor check for 3 site(s)
[2024-01-15 10:05:02] [OK] ✓ https://example.com is up
[2024-01-15 10:05:03] [HTTP_CHECK] https://api.example.com returned HTTP 500
[2024-01-15 10:05:03] [RETRY] https://api.example.com failed, retrying in 0.5s (attempt 1/2)
[2024-01-15 10:05:04] [ALERT_SENT] https://api.example.com - alert sent
[2024-01-15 10:05:05] [INFO] Check complete: 2/3 sites up, 1 down
```

### Log Levels

| Level | Description |
|-------|-------------|
| `INFO` | General operational messages |
| `OK` | Site is up |
| `HTTP_CHECK` | HTTP status code received |
| `RETRY` | Retry attempt in progress |
| `RETRY_SUCCESS` | Site recovered after retry |
| `ALERT_SENT` | Telegram alert dispatched |
| `ALERT_SUPPRESSED` | Alert skipped due to cooldown |
| `RECOVERY` | Site came back online |
| `CURL_ERROR` | Connection/network error |
| `TELEGRAM_ERROR` | Failed to send Telegram message |
| `SELF_CHECK` | Heartbeat executed |
| `FATAL` | Unhandled exception/crash |

---

## 🔐 Security Best Practices

### ✅ Recommended

```bash
# Store token in environment variable
export TELEGRAM_BOT_TOKEN="your_token"

# Set restrictive file permissions
chmod 600 config.json
chmod 600 state.json
chown www-data:www-data monitor.log  # If running as web user

# Run ohfuck.php from a non-web-accessible directory
# Or add .htaccess to block direct access:
# Deny from all
```

### ❌ Avoid

- Never commit `config.json` with real tokens to Git
- Don't store tokens in plain text in config files
- Don't disable SSL verification (`CURLOPT_SSL_VERIFYPEER`)
- Don't run as root user

---

## 🧪 Testing & Debugging

### Test Configuration

```bash
# Check PHP syntax
php -l ohfuck.php

# Test config parsing
php -r "json_decode(file_get_contents('config.json')); echo json_last_error_msg();"

# Dry run (watch output)
php ohfuck.php
```

### Test Specific Scenarios

```bash
# Test with a known down URL
php -r "
require 'ohfuck.php';
var_dump(isSiteDown('https://httpbin.org/status/500')); // Should be true
"

# Test with a known up URL
php -r "
require 'ohfuck.php';
var_dump(isSiteDown('https://httpbin.org/status/200')); // Should be false
"

# Force self-check (temporarily edit self_check_time to current time)
```

### View Logs in Real-Time

```bash
tail -f monitor.log
```

### Check Exit Codes

```bash
php ohfuck.php; echo "Exit code: $?"
# 0 = All sites up
# 2 = One or more sites down
# 3 = Script crashed
```

---

## 🛠️ Troubleshooting

| Issue | Solution |
|-------|----------|
| **No alerts received** | Check bot token, chat_id, and bot has permission to message you |
| **All sites marked down** | Verify network connectivity, check SSL certificates, increase timeout |
| **Too many alerts** | Increase `alert_cooldown_minutes` in config |
| **Script crashes** | Check `monitor.log` for FATAL errors, verify PHP version |
| **Permission denied** | Ensure script has write access to `state.json` and `monitor.log` |
| **cURL errors** | Install/enable cURL: `apt install php-curl` or `yum install php-curl` |
| **Self-check not working** | Verify cron is running, check system time/timezone |
| **SSL verification fails** | Update CA certificates: `apt install ca-certificates` |

### Common Error Messages

```
CURL_ERROR: SSL certificate problem
→ Update CA certificates or check site's SSL validity

CURL_ERROR: Connection timed out
→ Increase timeout in config or check network/firewall

TELEGRAM_ERROR: Unauthorized
→ Bot token is invalid or expired

TELEGRAM_ERROR: Chat not found
→ Chat ID is incorrect or bot hasn't been messaged first
```

---

## 📄 License

MIT License - feel free to use, modify, and distribute.

---

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## 📞 Support

- **Issues:** [GitHub Issues](https://github.com/ce6oc/oh-fuck-500-detector/issues)
- **Telegram Bot API:** [Official Documentation](https://core.telegram.org/bots/api)
- **PHP cURL:** [PHP Manual](https://www.php.net/manual/en/book.curl.php)

---

## 🙏 Credits

Built with ❤️ using PHP and the Telegram Bot API.

Inspired by the need for simple, reliable uptime monitoring without external dependencies or subscription fees.

---

**Made for sysadmins, developers, and anyone who needs to know when their site goes down — before their users do.** 🚀