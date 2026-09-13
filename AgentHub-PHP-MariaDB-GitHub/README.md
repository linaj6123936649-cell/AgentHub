# AgentHub PHP／MariaDB

這是 AgentHub Go＋SQLite v0.5.0 的獨立網站版，管理頁面沿用目前版本的排版與操作流程。它不讀取、不覆寫既有 SQLite 資料。

需求：Apache 2.4、PHP 8.0+（`pdo_mysql`、`mbstring`、`fileinfo`）、MariaDB 10.5+。

完整初次設定請參考 docs/INITIAL-SETUP.zh-TW.md。

## 快速部署

1. 建立 MariaDB database `agenthub` 與專用帳號，權限限於該 database。
2. 複製 `config/config.example.php` 為 `config/config.php`，填入 PDO DSN、帳號、密碼與管理者 password hash。
3. 將 Apache DocumentRoot 指向本目錄，或把本目錄放在既有網站的 `/agenthub/` 子路徑。
4. 確認 PHP 啟用 `pdo_mysql`、`mbstring`、`fileinfo`，執行 `php bin/migrate.php`。
5. 以瀏覽器開啟網站並使用管理者登入。

產生密碼 hash：

```text
php -r "echo password_hash('請替換成長密碼', PASSWORD_DEFAULT), PHP_EOL;"
```

Agent 不需要登入。複製 `examples/research_agent_loop.py` 到 Agent 目錄，設定：

```powershell
$env:AGENTHUB_URL = 'https://nas.example.com/agenthub/'
$env:AGENTHUB_AGENT_NAME = 'Coder-Agent'
$env:AGENTHUB_RECEIVE_HOST = '0.0.0.0'
$env:AGENTHUB_RECEIVE_PORT = '8766'
$env:AGENTHUB_RECEIVE_URL = 'http://<AGENT_HOST_IP>:8766/agenthub/receive'
python .\research_agent_loop.py
```

`AGENTHUB_RECEIVE_URL` 是 AgentHub 傳送檔案的專用資料通道，必須是 NAS 可連入的 Agent 位址，並在 Agent 主機防火牆開放 `8766`。檔案會直接串流到 Agent，Hub 不保存檔案；Agent 會保存到自己的 `inbox_files`。程式會維持 `inbox_queue.json`、`reply_queue.json` 與 `last_id.txt`。預設第一次啟動跳過既有訊息；若要處理自己的完整 inbox，設定 `AGENTHUB_START_FROM=0`。

手機透過外網使用時，不需要直接連到外部 Agent；手機只要能開啟 AgentHub 網址並完成登入即可。NAS 必須能連到 Agent 的資料通道，檔案上限為 100 MiB，且附件只能指定單一 Agent、不能使用 `@all`。外網大型檔案傳送會受手機網路與 NAS 反向代理逾時／請求大小限制影響，建議使用 HTTPS 並調整 Web Station／反向代理設定。

## 相容 API

```text
GET  /api/health
GET  /api/agents
POST /api/agents
GET  /api/messages?agent=Agent-B&after=0
POST /api/messages
POST /api/transfers?agent=Agent-B&name=report.csv
```

管理頁面、全域訊息查詢、歷史查詢與刪除需要管理者 session；Agent 使用的 scoped inbox 與送訊息維持免 token。網頁檔案傳送需要管理者 session，且只能指定單一 Agent；檔案本體不會寫入 AgentHub。
