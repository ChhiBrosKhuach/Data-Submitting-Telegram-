# 🤖 Telegram Bot Handler (bots.php)

A lightweight PHP-based **Telegram bot handler** that processes webhook updates, manages user interactions, and routes commands dynamically.

---

## 📌 Overview

`bots.php` is the **core entry point** for your Telegram bot. It receives updates from Telegram via webhook and handles:

* Incoming messages
* Commands (`/start`, etc.)
* Callback queries (buttons)
* User state logic
* Sending responses via Telegram API

---

## 📁 Project Structure

```
📦 project/
 ┣ 📜 bots.php                # Main webhook handler (core logic)
 ┣ 📜 config.php              # Define constants & load config
 ┣ 📜 .env                    # Secret keys (optional alternative to config.php)
 ┣ 📜 README.md               # Documentation

 ┣ 📂 data/                   # Main data storage (DATA_FOLDER)
 ┃ ┣ users.json               # User data & states
 ┃ ┣ sessions.json            # Session tracking
 ┃ ┗ debug.log                # Debug logs

 ┣ 📂 database/               # Database storage (DB_FILE)
 ┃ ┗ database.sqlite         # SQLite database file

 ┣ 📂 games/                  # Game files (FILE_GAME_FOLDER)
 ┃ ┣ quiz.json
 ┃ ┗ levels.json

 ┣ 📂 storage/                # Writable files (DATA_WRITE_FOLDER)
 ┃ ┣ uploads/
 ┃ ┗ temp/

 ┣ 📂 bakong/                 # Payment integration (BAKONG_TOKEN)
 ┃ ┣ qr/
 ┃ ┗ logs/

 ┗ 📂 admin/                  # Admin tools (ADMIN_ID related logic)
   ┣ dashboard.php
   ┗ logs.php
```

---

## ⚙️ Features

* 📩 **Webhook Processing**

  * Receives real-time updates from Telegram

* 🧠 **Command Handling**

  * Easily handle commands like `/start`, `/help`

* 🔄 **State Management**

  * Track user progress and actions

* 💬 **Dynamic Responses**

  * Reply based on user input or state

* 🔐 **Secure Setup**

  * Uses `.env` for sensitive data

* ⚡ **Lightweight**

  * No framework required, pure PHP

---

## 🚀 Setup Guide

### 1. Configure `.env`

Create a `.env` file:

```
TELEGRAM_BOT_TOKEN=your_bot_token
WEBHOOK_URL=https://yourdomain.com/bots.php
```

---

### 2. Set Webhook

Open in browser:

```
https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=https://yourdomain.com/bots.php
```

---

### 3. Server Requirements

* PHP 7.2+
* cURL enabled
* HTTPS required (Telegram webhook)

---

## 🔄 How It Works

1. User sends message to bot
2. Telegram sends update → `bots.php`
3. Script reads input (`php://input`)
4. Parses:

   * message
   * command
   * callback query
5. Processes logic
6. Sends response via Telegram API

---

## 🧪 Debugging

Logs are stored in:

```
/data/debug.log
```

You can log incoming data for debugging:

```php
file_put_contents('data/debug.log', file_get_contents('php://input'), FILE_APPEND);
```

---

## 🛡️ Security Tips

* ❌ Never upload `.env` to GitHub
* 🔒 Protect `/data/` with `.htaccess`
* ✅ Validate incoming webhook requests

---

## 📌 Notes

* Designed for **webhook mode** (not polling)
* Works on **cPanel / shared hosting**
* Easy to expand with more features

---

## 👨‍💻 Author

Developed by you 😎
