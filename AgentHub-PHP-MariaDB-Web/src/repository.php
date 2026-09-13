<?php
declare(strict_types=1);

final class AgentHubRepository
{
    private $pdo;
    private $config;
    public function __construct(PDO $pdo, array $config) { $this->pdo = $pdo; $this->config = $config; }

    public function migrate(): void
    {
        $sql = file_get_contents(__DIR__ . '/../sql/001_schema.sql');
        if ($sql === false) throw new RuntimeException('Cannot read database schema');
        $this->pdo->exec($sql);
        $columns = [
            ['agents', 'transfer_url', 'ALTER TABLE agents ADD COLUMN transfer_url VARCHAR(500) NULL'],
            ['agents', 'transfer_token', 'ALTER TABLE agents ADD COLUMN transfer_token VARCHAR(200) NULL'],
            ['messages', 'transfer_manifest', 'ALTER TABLE messages ADD COLUMN transfer_manifest MEDIUMTEXT NULL'],
        ];
        foreach ($columns as [$table, $column, $alter]) {
            $check = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $check->execute([$table, $column]);
            if (!(int)$check->fetchColumn()) $this->pdo->exec($alter);
        }
        $this->pdo->prepare('INSERT IGNORE INTO migrations(version, applied_at) VALUES(?, ?)')
            ->execute(['001_schema', agenthub_sql_time(agenthub_now())]);
    }

    public function registerAgent(string $name, string $transferUrl = '', string $transferToken = ''): array
    {
        $name = clean_required($name, 80);
        if ($name === '' || $name === '*' || starts_with($name, '@') || strtolower($name) === 'all') {
            throw new InvalidArgumentException("agent name is required and cannot be '*', 'all', or start with '@'");
        }
        $transferUrl = trim($transferUrl);
        if ($transferUrl !== '') {
            $parsed = parse_url($transferUrl);
            if (!is_array($parsed) || !in_array(strtolower((string)($parsed['scheme'] ?? '')), ['http', 'https'], true) || trim((string)($parsed['host'] ?? '')) === '' || strlen($transferUrl) > 500) {
                throw new InvalidArgumentException('transfer_url must be a valid http or https URL');
            }
        }
        $transferToken = trim($transferToken);
        if ($transferUrl !== '' && $transferToken === '') {
            throw new InvalidArgumentException('transfer_token is required when transfer_url is set');
        }
        if ($transferUrl === '' && $transferToken !== '') {
            throw new InvalidArgumentException('transfer_url is required when transfer_token is set');
        }
        if (strlen($transferToken) > 200) throw new InvalidArgumentException('transfer_token is too long');
        $now = agenthub_now();
        $this->pdo->prepare('INSERT INTO agents(name, registered_at, last_seen_at, transfer_url, transfer_token) VALUES(?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE registered_at=VALUES(registered_at), last_seen_at=VALUES(last_seen_at), transfer_url=IF(VALUES(transfer_url)=\'\', agents.transfer_url, VALUES(transfer_url)), transfer_token=IF(VALUES(transfer_token)=\'\', agents.transfer_token, VALUES(transfer_token))')
            ->execute([$name, agenthub_sql_time($now), agenthub_sql_time($now), $transferUrl, $transferToken]);
        return ['name' => $name, 'registered_at' => agenthub_json_time(agenthub_sql_time($now)), 'last_seen_at' => agenthub_json_time(agenthub_sql_time($now)), 'transfer_enabled' => $transferUrl !== '' && $transferToken !== ''];
    }

    public function touchAgent(string $name): void
    {
        $name = clean_required($name, 80);
        if ($name === '') return;
        $now = agenthub_now();
        $this->pdo->prepare('INSERT INTO agents(name, registered_at, last_seen_at) VALUES(?, ?, ?) ON DUPLICATE KEY UPDATE last_seen_at=VALUES(last_seen_at)')
            ->execute([$name, agenthub_sql_time($now), agenthub_sql_time($now)]);
    }

    public function agents(): array
    {
        $cutoff = agenthub_now()->modify('-' . (int)$this->config['agent_lease_seconds'] . ' seconds');
        $stmt = $this->pdo->prepare('SELECT name, registered_at, last_seen_at, transfer_url, transfer_token FROM agents WHERE last_seen_at >= ? ORDER BY name');
        $stmt->execute([agenthub_sql_time($cutoff)]);
        return array_map(function(array $row): array {
            return [
            'name' => $row['name'],
            'registered_at' => agenthub_json_time($row['registered_at']),
            'last_seen_at' => agenthub_json_time($row['last_seen_at']),
            'transfer_enabled' => trim((string)($row['transfer_url'] ?? '')) !== '' && trim((string)($row['transfer_token'] ?? '')) !== '',
            ];
        }, $stmt->fetchAll());
    }

    public function addMessage(array $request): array
    {
        $sender = clean_required((string)($request['sender'] ?? ''), 80);
        $content = trim((string)($request['content'] ?? ''));
        if ($sender === '') throw new InvalidArgumentException('sender is required');
        if (strlen($content) > (int)$this->config['max_message_bytes']) throw new InvalidArgumentException('message exceeds 1 MiB');
        $this->touchAgent($sender);
        $target = $this->resolveMention($content, (string)($request['target'] ?? ''));
        $ids = [];
        $transferAttachments = [];
        foreach ((array)($request['attachments'] ?? []) as $attachment) {
            if (is_array($attachment)) {
                $transferId = clean_required((string)($attachment['transfer_id'] ?? ''), 64);
                $fileName = safe_filename((string)($attachment['name'] ?? ''));
                $size = filter_var($attachment['size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($transferId === '' || !preg_match('/^[a-f0-9]{32}$/', $transferId) || $fileName === '' || $size === false) throw new InvalidArgumentException('invalid transfer attachment');
                $transferAttachments[] = ['transfer_id' => $transferId, 'name' => $fileName, 'size' => (int)$size, 'content_type' => clean_required((string)($attachment['content_type'] ?? 'application/octet-stream'), 255) ?: 'application/octet-stream'];
            } else {
                $id = trim((string)$attachment);
                if ($id !== '') $ids[] = $id;
            }
        }
        $attachments = [];
        foreach ($ids as $id) {
            try { $attachment = $this->file($id); }
            catch (RuntimeException $error) { throw new InvalidArgumentException('attachment ' . json_encode($id, JSON_UNESCAPED_UNICODE) . ': ' . $error->getMessage()); }
            unset($attachment['_stored_name']);
            $attachments[] = $attachment;
        }
        $created = agenthub_now();
        $this->pdo->beginTransaction();
        try {
            $manifest = $transferAttachments ? json_encode($transferAttachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null;
            $stmt = $this->pdo->prepare('INSERT INTO messages(sender, target, content, created_at, transfer_manifest) VALUES(?, ?, ?, ?, ?)');
            $stmt->execute([$sender, $target, $content, agenthub_sql_time($created), $manifest]);
            $id = (int)$this->pdo->lastInsertId();
            $link = $this->pdo->prepare('INSERT INTO message_attachments(message_id, file_id, position) VALUES(?, ?, ?)');
            foreach ($attachments as $position => $attachment) $link->execute([$id, $attachment['id'], $position]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        return ['id' => $id, 'sender' => $sender, 'target' => $target, 'content' => $content, 'attachments' => array_merge($attachments, $transferAttachments), 'created_at' => agenthub_json_time(agenthub_sql_time($created))];
    }

    private function resolveMention(string $content, string $requested): string
    {
        if ($content === '' || !starts_with($content, '@')) throw new InvalidArgumentException('message must start with @AgentName or @all');
        $target = clean_required($requested, 80);
        if ($target === '' ) throw new InvalidArgumentException('target is required and must match the @ mention');
        if ($target === '*' || strtolower($target) === 'all' || strtolower($target) === '@all') {
            if (!has_mention_prefix($content, '@all')) throw new InvalidArgumentException('broadcast target requires an @all mention');
            return '*';
        }
        $target = ltrim($target, '@');
        $stmt = $this->pdo->prepare('SELECT 1 FROM agents WHERE name=?');
        $stmt->execute([$target]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('mentioned agent is not registered');
        if (!has_mention_prefix($content, '@' . $target)) throw new InvalidArgumentException('target does not match mentioned agent');
        return $target;
    }

    public function messages(string $agent, int $after): array
    {
        $agent = trim($agent);
        if ($agent !== '') $this->touchAgent($agent);
        $sql = 'SELECT id, sender, target, content, created_at, transfer_manifest FROM messages WHERE id > ?';
        $args = [$after];
        if ($agent !== '') { $sql .= " AND (target = ? OR (target = '*' AND sender <> ?))"; $args[] = $agent; $args[] = $agent; }
        $sql .= ' ORDER BY id';
        $stmt = $this->pdo->prepare($sql); $stmt->execute($args);
        return $this->hydrateMessages($stmt->fetchAll());
    }

    public function history(string $date, string $agent, int $limit): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new InvalidArgumentException('date must use YYYY-MM-DD');
        if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('limit must be between 1 and 500');
        $zone = new DateTimeZone((string)$this->config['timezone']);
        $start = new DateTimeImmutable($date . ' 00:00:00', $zone);
        $end = $start->modify('+1 day');
        $sql = 'SELECT id, sender, target, content, created_at, transfer_manifest FROM messages WHERE created_at >= ? AND created_at < ?';
        $args = [agenthub_sql_time($start->setTimezone(new DateTimeZone('UTC'))), agenthub_sql_time($end->setTimezone(new DateTimeZone('UTC')))];
        if (trim($agent) !== '') { $sql .= " AND (sender = ? OR target = ? OR target = '*')"; $args[] = trim($agent); $args[] = trim($agent); }
        $sql .= ' ORDER BY id DESC LIMIT ' . (int)$limit;
        $stmt = $this->pdo->prepare($sql); $stmt->execute($args);
        $rows = array_reverse($stmt->fetchAll());
        return $this->hydrateMessages($rows);
    }

    public function deleteMessage(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM messages WHERE id=?'); $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) throw new RuntimeException('message not found');
    }

    public function relayTransfer(string $agent, string $name, string $contentType): array
    {
        $agent = ltrim(trim($agent), '@');
        if ($agent === '' || strtolower($agent) === 'all' || $agent === '*') throw new InvalidArgumentException('direct file transfer requires one Agent');
        $name = safe_filename($name);
        if ($name === '') throw new InvalidArgumentException('filename is required');
        $cutoff = agenthub_now()->modify('-' . (int)$this->config['agent_lease_seconds'] . ' seconds');
        $stmt = $this->pdo->prepare('SELECT name, transfer_url, transfer_token FROM agents WHERE name=? AND last_seen_at >= ?');
        $stmt->execute([$agent, agenthub_sql_time($cutoff)]);
        $row = $stmt->fetch();
        if (!$row || trim((string)$row['transfer_url']) === '' || trim((string)$row['transfer_token']) === '') throw new RuntimeException('target Agent is offline or has no data channel');
        $length = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($length === false || $length > (int)$this->config['max_file_bytes']) throw new InvalidArgumentException('file exceeds 100 MiB or has no content length');
        $input = @fopen('php://input', 'rb');
        if (!$input) throw new RuntimeException('cannot open upload input stream');
        $transferId = bin2hex(random_bytes(16));
        $parsed = parse_url((string)$row['transfer_url']);
        if (!is_array($parsed) || !in_array(strtolower((string)($parsed['scheme'] ?? '')), ['http', 'https'], true) || trim((string)($parsed['host'] ?? '')) === '') { fclose($input); throw new RuntimeException('target Agent data channel URL is invalid'); }
        $host = (string)$parsed['host']; $port = (int)($parsed['port'] ?? (strtolower((string)$parsed['scheme']) === 'https' ? 443 : 80));
        $socketHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host;
        $transport = strtolower((string)$parsed['scheme']) === 'https' ? 'tls' : 'tcp';
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $socket = @stream_socket_client($transport . '://' . $socketHost . ':' . $port, $errno, $error, 30, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) { fclose($input); throw new RuntimeException('cannot connect to target Agent data channel'); }
        stream_set_timeout($socket, 120);
        $path = (string)($parsed['path'] ?? '/'); if ($path === '') $path = '/'; if (isset($parsed['query'])) $path .= '?' . $parsed['query'];
        $type = preg_replace('/[^\x20-\x7E]/', '', $contentType) ?: 'application/octet-stream';
        $headers = "POST " . $path . " HTTP/1.1\r\nHost: " . $host . "\r\nConnection: close\r\nContent-Length: " . (int)$length . "\r\nContent-Type: " . $type . "\r\nX-AgentHub-Transfer-Id: " . $transferId . "\r\nX-AgentHub-Filename: " . rawurlencode($name) . "\r\nX-AgentHub-Sender: " . rawurlencode((string)($this->config['admin_sender'] ?? '管理者')) . "\r\nX-AgentHub-Token: " . rawurlencode((string)$row['transfer_token']) . "\r\n\r\n";
        $this->writeSocket($socket, $headers);
        $remaining = (int)$length; $size = 0;
        while ($remaining > 0) { $chunk = fread($input, min(1024 * 1024, $remaining)); if ($chunk === false || $chunk === '') { fclose($input); fclose($socket); throw new RuntimeException('cannot read upload input stream'); } $this->writeSocket($socket, $chunk); $written = strlen($chunk); $size += $written; $remaining -= $written; }
        fclose($input); $statusLine = fgets($socket); $responseHeaders = ''; while (($line = fgets($socket)) !== false) { if (rtrim($line, "\r\n") === '') break; $responseHeaders .= $line; } $body = stream_get_contents($socket); fclose($socket);
        if (!preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', (string)$statusLine, $match) || (int)$match[1] < 200 || (int)$match[1] >= 300) throw new RuntimeException('target Agent rejected data transfer');
        return ['transfer_id' => $transferId, 'name' => $name, 'size' => $size, 'content_type' => $type, 'target' => $agent];
    }

    private function writeSocket($socket, string $data): void
    {
        $offset = 0; $length = strlen($data);
        while ($offset < $length) { $written = fwrite($socket, substr($data, $offset)); if ($written === false || $written === 0) throw new RuntimeException('cannot send data to target Agent'); $offset += $written; }
    }

    public function saveFile(string $sender, string $name, string $contentType): array
    {
        $sender = clean_required($sender, 80); $name = safe_filename($name);
        if ($sender === '' || $name === '') throw new InvalidArgumentException('sender and filename are required');
        $id = bin2hex(random_bytes(16));
        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';
        $extension = substr($extension, 0, 20);
        $stored = $id . ($extension === '' ? '.bin' : '.' . $extension);
        $storage = rtrim((string)$this->config['storage_dir'], DIRECTORY_SEPARATOR);
        $files = $storage . DIRECTORY_SEPARATOR . 'files'; $tmpDir = $storage . DIRECTORY_SEPARATOR . 'uploads-temp';
        if (!is_dir($files) && !mkdir($files, 0750, true) && !is_dir($files)) throw new RuntimeException('cannot create file storage');
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0750, true) && !is_dir($tmpDir)) throw new RuntimeException('cannot create upload storage');
        $tmp = $tmpDir . DIRECTORY_SEPARATOR . $id . '.upload'; $final = $files . DIRECTORY_SEPARATOR . $stored;
        $in = @fopen('php://input', 'rb');
        if (!$in) throw new RuntimeException('cannot open upload input stream');
        $out = @fopen($tmp, 'wb');
        if (!$out) { fclose($in); throw new RuntimeException('cannot create upload temp file'); }
        $size = 0; $tooLarge = false;
        while (!feof($in)) { $chunk = fread($in, 8192); if ($chunk === false) { fclose($in); fclose($out); @unlink($tmp); throw new RuntimeException('cannot read upload stream'); } $size += strlen($chunk); if ($size > (int)$this->config['max_file_bytes']) { $tooLarge = true; break; } $written = fwrite($out, $chunk); if ($written !== strlen($chunk)) { fclose($in); fclose($out); @unlink($tmp); throw new RuntimeException('cannot write upload'); } }
        fclose($in); fflush($out); fclose($out);
        if ($tooLarge) { @unlink($tmp); throw new InvalidArgumentException('file exceeds 100 MiB'); }
        if (!rename($tmp, $final)) { @unlink($tmp); throw new RuntimeException('cannot finalize uploaded file'); }
        if ($contentType === '' || $contentType === 'application/octet-stream') $contentType = function_exists('mime_content_type') ? (mime_content_type($final) ?: 'application/octet-stream') : 'application/octet-stream';
        $created = agenthub_now();
        try { $this->pdo->prepare('INSERT INTO files(id, name, sender, size_bytes, content_type, created_at, stored_name) VALUES(?, ?, ?, ?, ?, ?, ?)')->execute([$id, $name, $sender, $size, $contentType, agenthub_sql_time($created), $stored]); }
        catch (Throwable $error) { @unlink($final); throw $error; }
        return ['id'=>$id, 'name'=>$name, 'sender'=>$sender, 'size'=>$size, 'content_type'=>$contentType, 'created_at'=>agenthub_json_time(agenthub_sql_time($created))];
    }

    public function file(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, sender, size_bytes, content_type, created_at, stored_name FROM files WHERE id=?'); $stmt->execute([trim($id)]); $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('file not found');
        return ['id'=>$row['id'], 'name'=>$row['name'], 'sender'=>$row['sender'], 'size'=>(int)$row['size_bytes'], 'content_type'=>$row['content_type'], 'created_at'=>agenthub_json_time($row['created_at']), '_stored_name'=>$row['stored_name']];
    }

    public function filePath(string $id): array { $file = $this->file($id); $file['_path'] = rtrim((string)$this->config['storage_dir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . $file['_stored_name']; return $file; }

    private function hydrateMessages(array $rows): array
    {
        $link = $this->pdo->prepare('SELECT f.id, f.name, f.sender, f.size_bytes, f.content_type, f.created_at FROM files f JOIN message_attachments ma ON ma.file_id=f.id WHERE ma.message_id=? ORDER BY ma.position');
        return array_map(function(array $row) use ($link): array { $link->execute([$row['id']]); $attachments=[]; foreach($link->fetchAll() as $file) $attachments[]=['id'=>$file['id'],'name'=>$file['name'],'sender'=>$file['sender'],'size'=>(int)$file['size_bytes'],'content_type'=>$file['content_type'],'created_at'=>agenthub_json_time($file['created_at'])]; $manifest=json_decode((string)($row['transfer_manifest']??''),true); if(is_array($manifest)) foreach($manifest as $file) if(is_array($file)) $attachments[]=$file; return ['id'=>(int)$row['id'],'sender'=>$row['sender'],'target'=>$row['target'],'content'=>$row['content'],'attachments'=>$attachments,'created_at'=>agenthub_json_time($row['created_at'])]; }, $rows);
    }
}

function clean_required(string $value, int $max): string { $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? ''); return mb_strlen($value, 'UTF-8') <= $max ? $value : ''; }
function has_mention_prefix(string $content, string $mention): bool { if (!starts_with($content, $mention)) return false; if (strlen($content) === strlen($mention)) return true; return preg_match('/^[ \t\r\n]/', substr($content, strlen($mention))) === 1; }
function safe_filename(string $name): string { $name = basename(str_replace('\\', '/', trim($name))); $name = preg_replace('/[\x00-\x1F\x7F<>:"\/\\|?*]/u', '_', $name) ?? ''; if ($name === '' || $name === '.' || $name === '..') return ''; return mb_substr($name, 0, 180, 'UTF-8'); }
