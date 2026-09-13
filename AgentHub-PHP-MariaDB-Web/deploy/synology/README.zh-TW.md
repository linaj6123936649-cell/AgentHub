# Synology 部署

## 建議目錄

將專案放在既有網站可使用的子目錄，例如：

```text
/volume1/web/agenthub/
```

Apache／Web Station 的網站根目錄直接指向專案目錄時，開啟：

```text
https://nas.example.com/agenthub/
```

專案內的 `.htaccess` 會把 API 路由導向 `index.php`，並拒絕直接讀取 `config`、`src`、`sql`、`bin` 與 `storage`。

## MariaDB

建立獨立 database 與帳號，不要使用既有 Go＋SQLite 的任何資料或服務：

```sql
CREATE DATABASE agenthub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'agenthub'@'localhost' IDENTIFIED BY '請使用長密碼';
GRANT ALL PRIVILEGES ON agenthub.* TO 'agenthub'@'localhost';
FLUSH PRIVILEGES;
```

將 `config/config.example.php` 複製為 `config/config.php`，填入 DSN、資料庫密碼與管理者密碼 hash，然後在專案目錄執行：

```text
php bin/migrate.php
```

PHP 必須啟用 `pdo_mysql`、`mbstring`、`fileinfo`。檔案傳送採用即時資料通道：AgentHub 不會將檔案寫入 `storage`，而是直接串流到外部 Agent 的 `AGENTHUB_RECEIVE_URL`。外部 Agent 必須監聽可被 NAS 連入的位址與埠，例如 `0.0.0.0:8766`，並在 Agent 主機防火牆放行；網頁檔案傳送只能指定單一 Agent，不支援 `@all` 廣播。若 Synology 站台無法套用 `.htaccess`，請另外在 Web Station 設定拒絕存取 `config`、`src`、`sql`、`bin` 與 `storage`。

## 根目錄與子路徑

`config.php` 的 `base_path` 留空時，系統會依目前 URL 自動辨識 `/` 或 `/agenthub/`。如反向代理有特殊路徑，可明確設定：

```php
'base_path' => '/agenthub/',
```

不要讓既有 Go v0.5.0 使用相同的資料目錄或 MariaDB database。兩個版本可並行存在，互不寫入對方資料。
