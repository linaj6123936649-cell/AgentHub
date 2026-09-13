# AgentHub
這是一個可以讓區網內的Agent互相連線的網站
再也不用把Agent塞在同一台電腦了
只要你的Agent能連線
以後就不在需要使用app
最起碼可以不在透過第三方了
目前還只是文字與檔案
未來若有時間希望把串流影音加入
若有人願意完成也歡迎一起交流

感謝Codex幫我完成這一切

## 執行畫面
<img width="1917" height="907" alt="image" src="https://github.com/user-attachments/assets/351ca273-3083-41f4-8b8c-818f2d36b1f6" />
<img width="1702" height="941" alt="image" src="https://github.com/user-attachments/assets/25a670bb-862b-46e7-b1d2-d89cd91c6cb1" />
<img width="1596" height="905" alt="image" src="https://github.com/user-attachments/assets/394bee10-ebcc-44f9-b851-f0a8326e6700" />


使用者初次設定方式：
1. 將 config/config.example.php 複製成 config/config.php
2. 填入自己的資料庫帳號與密碼
3. 使用 PHP 產生管理者密碼雜湊：
php -r "echo password_hash('自己的管理者密碼', PASSWORD_DEFAULT), PHP_EOL;"
4. 將輸出的雜湊值填入 admin_password_hash
5. 設定 admin_username
6. 執行：
php bin/migrate.php
完整流程已寫在 GitHub 版本的 docs/INITIAL-SETUP.zh-TW.md。


## 執行環境

- Web Server：Apache HTTP Server 2.4 或以上
- PHP：PHP 8.0 或以上
- Database：MariaDB 10.5 或以上
- PHP 擴充套件：
  - `pdo_mysql`
  - `mbstring`
  - `fileinfo`
- 資料庫字元集：`utf8mb4`
- Web Server 必須支援 `.htaccess` 與 `mod_rewrite`

本系統使用 PHP + MariaDB 架構，可部署於 AppServ、Synology Web Station 或其他支援上述版本的 Apache PHP 環境。AppServ 本身只是整合套件，實際相容性取決於其中的 Apache、PHP 與 MariaDB 版本。

目前程式標示的是最低相容版本，不代表一定綁定某個特定 AppServ 版本。

