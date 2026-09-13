# AgentHub 初次設定

GitHub 發佈版不包含實際的 `config/config.php`、資料庫密碼或管理者密碼。第一次使用時，請在專案根目錄依序完成以下設定。

## 1. 建立設定檔

複製範例設定檔：

```text
config/config.example.php  →  config/config.php
```

`config/config.php` 只保留在自己的部署環境，不要提交到 GitHub。

## 2. 產生管理者密碼雜湊

使用 PHP 產生雜湊值，不要把明文密碼寫入設定檔：

```powershell
php -r "echo password_hash('請改成你自己的管理者密碼', PASSWORD_DEFAULT), PHP_EOL;"
```

把輸出的整串內容貼到 `config/config.php` 的 `admin_password_hash`。使用者登入時輸入原本設定的明文密碼，程式會用 `password_verify()` 驗證；程式本身不需要保存明文密碼。

## 3. 設定資料庫

修改 `config/config.php` 內的：

```php
'db' => [
    'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=agenthub;charset=utf8mb4',
    'username' => '你的資料庫帳號',
    'password' => '你的資料庫密碼',
],
```

資料庫建立 SQL 位於 `sql/001_schema.sql`，也可參考 `docs/database-setup.sql.txt`。

## 4. 建立資料表

在專案根目錄執行：

```powershell
php bin/migrate.php
```

第一次開啟網站後，使用 `config.php` 中的 `admin_username` 與你剛設定的管理者密碼登入。

## 5. GitHub 提交前檢查

確認下列內容沒有被提交：

- `config/config.php`
- `storage/` 內的 session、上傳檔案與執行資料
- 任何包含資料庫密碼或固定 Token 的私人設定檔

專案的 `.gitignore` 已預先排除 `config/config.php` 與 `storage` 的執行資料。
