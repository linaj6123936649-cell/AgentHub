(() => {
  const base = window.AGENTHUB_BASE_PATH || '/';
  const apiUrl = path => base.replace(/\/$/, '') + path;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const localDate = () => {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  };
  async function api(path, options = {}) {
    const headers = {'Accept': 'application/json', ...(options.headers || {})};
    if (options.method && options.method.toUpperCase() !== 'GET' && window.AGENTHUB_CSRF && !headers['X-CSRF-Token']) headers['X-CSRF-Token'] = window.AGENTHUB_CSRF;
    const response = await fetch(apiUrl(path), {...options, headers, credentials: 'same-origin'});
    const body = await response.text();
    if (!response.ok) {
      let message = response.statusText;
      try { message = JSON.parse(body).error || message; } catch {}
      throw new Error(message);
    }
    return body ? JSON.parse(body) : null;
  }

  let lastRendered = '';
  let lastAgentSignature = '';
  let viewMode = window.AGENTHUB_VIEW_MODE || 'today';
  function setRecipient(mention) {
    document.querySelector('#target').value = mention;
    document.querySelector('#recipient-display').textContent = mention === '@all' ? '收件者：@all（廣播）' : '收件者：' + mention;
    document.querySelectorAll('#agent-list [data-target]').forEach(b => b.classList.toggle('is-selected', b.dataset.target === mention));
  }
  function historyPath() {
    const dateField = document.querySelector('#history-date');
    const limitField = document.querySelector('#history-limit');
    const filterField = document.querySelector('#filter');
    const date = viewMode === 'today' ? localDate() : (dateField ? dateField.value : localDate());
    const limit = limitField ? limitField.value : '100';
    const agent = filterField ? filterField.value : '';
    const q = new URLSearchParams({date, limit});
    if (agent) q.set('agent', agent);
    return {path: '/api/history?' + q, date, limit};
  }
  function render(rows, details) {
    const history = viewMode === 'history';
    document.querySelector('#message-context').textContent =
      (history ? details.date + ' 的歷史訊息' : '今天的訊息') +
      ' ・ 顯示最新 ' + details.limit + ' 則' + (history ? ' ・ 可逐則刪除' : '');
    const box = document.querySelector('#messages');
    box.innerHTML = rows.length ? rows.map(m =>
      '<article class="message"><div class="message-top"><div class="meta">#' + m.id + ' ・ ' + esc(m.sender) + ' → ' +
      esc(m.target === '*' ? '@all' : '@' + m.target) + ' ・ ' + new Date(m.created_at).toLocaleString() + '</div>' +
      (history ? '<button class="delete-message" type="button" data-delete-id="' + m.id + '">刪除</button>' : '') +
      '</div><div class="content">' + esc(m.content) + '</div>' +
      (m.attachments && m.attachments.length ? '<div class="attachments">' + m.attachments.map(f =>
        f.id ? '<a href="' + apiUrl('/api/files/' + encodeURIComponent(f.id)) + '">📎 ' + esc(f.name) + '</a>' : '<span>📎 ' + esc(f.name) + '（已傳送至 Agent）</span>'
      ).join('') + '</div>' : '') + '</article>'
    ).join('') : '<div class="empty">沒有符合條件的訊息</div>';
    box.scrollTop = box.scrollHeight;
  }
  async function loadAgents() {
    const agents = (await api('/api/agents')).filter(a => a.name !== window.AGENTHUB_SENDER);
    const signature = JSON.stringify(agents);
    if (signature === lastAgentSignature) return;
    lastAgentSignature = signature;
    const filter = document.querySelector('#filter');
    const current = filter.value;
    filter.innerHTML = '<option value="">所有訊息</option>' + agents.map(a => '<option value="' + esc(a.name) + '">' + esc(a.name) + '</option>').join('');
    filter.value = agents.some(a => a.name === current) ? current : '';
    const targetField = document.querySelector('#target');
    const chosen = targetField ? targetField.value : '@all';
    const valid = agents.some(a => '@' + a.name === chosen) ? chosen : '@all';
    const countField = document.querySelector('#agent-count');
    const listField = document.querySelector('#agent-list');
    if (countField) countField.textContent = agents.length + ' 名在線';
    if (listField) {
      listField.innerHTML =
        '<button type="button" class="agent-chip is-selected" data-target="@all">🌐 所有 Agent ・ @all</button>' +
        agents.map(a => '<button type="button" class="agent-chip is-online" data-target="@' + esc(a.name) +
          '" title="最後活動：' + esc(new Date(a.last_seen_at).toLocaleString()) + '"><span class="presence online"></span>@' +
          esc(a.name) + '<span class="agent-presence">在線</span></button>').join('');
      setRecipient(valid);
    }
  }
  async function refresh() {
    try {
      await loadAgents();
      const details = historyPath();
      const rows = await api(viewMode === 'today' ? '/api/messages?after=0' : details.path);
      const signature = viewMode + JSON.stringify(rows);
      if (signature !== lastRendered) {
        lastRendered = signature;
        render(rows, details);
      }
      document.querySelector('#status').textContent = '● 已連線';
    } catch (error) {
      document.querySelector('#status').textContent = '● 連線問題：' + error.message;
    }
  }
  function setView(mode) {
    viewMode = mode;
    lastRendered = '';
    const history = mode === 'history';
    const historyControls = document.querySelector('#history-controls');
    const todayButton = document.querySelector('#view-today');
    const historyButton = document.querySelector('#view-history');
    if (historyControls) historyControls.hidden = !history;
    if (todayButton) todayButton.classList.toggle('is-active', !history);
    if (historyButton) historyButton.classList.toggle('is-active', history);
    refresh();
  }
  const todayButton = document.querySelector('#view-today');
  const historyButton = document.querySelector('#view-history');
  const refreshButton = document.querySelector('#refresh');
  const filterField = document.querySelector('#filter');
  const historyDate = document.querySelector('#history-date');
  const historyLimit = document.querySelector('#history-limit');
  const agentList = document.querySelector('#agent-list');
  const messagesBox = document.querySelector('#messages');
  if (todayButton) todayButton.onclick = () => setView('today');
  if (historyButton) historyButton.onclick = () => setView('history');
  if (refreshButton) refreshButton.onclick = refresh;
  if (filterField) filterField.onchange = () => { lastRendered = ''; refresh(); };
  if (historyDate) historyDate.onchange = () => { lastRendered = ''; if (viewMode === 'history') refresh(); };
  if (historyLimit) historyLimit.onchange = () => { lastRendered = ''; if (viewMode === 'history') refresh(); };
  if (agentList) agentList.onclick = e => {
    const b = e.target.closest('[data-target]');
    if (b) setRecipient(b.dataset.target);
  };
  if (messagesBox) messagesBox.onclick = async e => {
    const button = e.target.closest('[data-delete-id]');
    if (!button) return;
    const id = button.dataset.deleteId;
    if (!confirm('確定要刪除歷史訊息 #' + id + '？此操作無法復原。')) return;
    button.disabled = true;
    try {
      await api('/api/messages/' + encodeURIComponent(id), {method: 'DELETE', headers: {'X-CSRF-Token': window.AGENTHUB_CSRF}});
      lastRendered = '';
      await refresh();
    } catch (error) {
      alert(error.message);
      button.disabled = false;
    }
  };

  const contentField = document.querySelector('#content');
  const fileInput = document.querySelector('#files');
  let pendingFiles = [];
  let selectedFiles = document.querySelector('#selected-files');
  const fileSelectionStatus = document.querySelector('#file-selection-status');
  if (!selectedFiles) {
    const picker = document.createElement('div');
    picker.className = 'file-picker';
    fileInput.parentNode.insertBefore(picker, fileInput);
    picker.appendChild(fileInput);
    selectedFiles = document.createElement('div');
    selectedFiles.id = 'selected-files';
    selectedFiles.className = 'selected-files';
    selectedFiles.setAttribute('aria-live', 'polite');
    picker.appendChild(selectedFiles);
  }
  const formatFileSize = bytes => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  };
  const syncFileInput = () => {
    const transfer = new DataTransfer();
    pendingFiles.forEach(file => transfer.items.add(file));
    fileInput.files = transfer.files;
  };
  const renderSelectedFiles = () => {
    if (fileSelectionStatus) {
      fileSelectionStatus.textContent = pendingFiles.length
        ? pendingFiles.length + ' 個檔案已選擇'
        : '未選擇任何檔案';
    }
    selectedFiles.innerHTML = pendingFiles.map((file, index) =>
      '<div class="selected-file"><span title="' + esc(file.name) + '">' + esc(file.name) + ' <small>' +
      formatFileSize(file.size) + '</small></span><button type="button" class="remove-file" data-file-index="' +
      index + '" aria-label="移除 ' + esc(file.name) + '">移除</button></div>'
    ).join('');
  };
  fileInput.addEventListener('change', () => {
    pendingFiles = Array.from(fileInput.files || []);
    renderSelectedFiles();
  });
  selectedFiles.addEventListener('click', e => {
    const button = e.target.closest('[data-file-index]');
    if (!button) return;
    pendingFiles.splice(Number(button.dataset.fileIndex), 1);
    syncFileInput();
    renderSelectedFiles();
  });

  let altHeld = false;
  let pendingEnter = false;
  let enterHasModifier = false;
  const isAlt = e => e.key === 'Alt' || e.key === 'AltGraph' || e.code === 'AltLeft' || e.code === 'AltRight' || e.code === 'AltGraph';
  const hasModifier = e => e.altKey || e.ctrlKey || e.shiftKey || e.metaKey || altHeld || (e.getModifierState && (e.getModifierState('Alt') || e.getModifierState('AltGraph')));
  window.addEventListener('keydown', e => { if (isAlt(e)) altHeld = true; if (pendingEnter && hasModifier(e)) enterHasModifier = true; }, true);
  window.addEventListener('keyup', e => { if (isAlt(e)) altHeld = false; }, true);
  window.addEventListener('blur', () => { altHeld = false; pendingEnter = false; enterHasModifier = false; });
  const insertLineBreak = f => f.setRangeText('\n', f.selectionStart, f.selectionEnd, 'end');
  contentField.addEventListener('keydown', e => {
    if (isAlt(e)) { altHeld = true; return; }
    if (e.key !== 'Enter' || e.isComposing || e.repeat) return;
    e.preventDefault();
    pendingEnter = true;
    enterHasModifier = Boolean(hasModifier(e));
  });
  contentField.addEventListener('keyup', e => {
    if (isAlt(e)) { altHeld = false; return; }
    if (e.key !== 'Enter' || !pendingEnter) return;
    e.preventDefault();
    const newline = enterHasModifier || Boolean(hasModifier(e));
    pendingEnter = false;
    enterHasModifier = false;
    if (newline) insertLineBreak(e.currentTarget);
    else document.querySelector('#composer').requestSubmit();
  });

  document.querySelector('#composer').addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.currentTarget;
    const button = form.querySelector('button[type=submit]');
    const mention = document.querySelector('#target').value.trim();
    const target = mention.toLowerCase() === '@all' ? '*' : mention.startsWith('@') ? mention.slice(1) : '';
    const text = contentField.value.trim();
    if (!text) { contentField.focus(); return; }
    if (pendingFiles.length && target === '*') { alert('即時檔案傳送需要選擇單一 Agent，請先點選左側 Agent。'); return; }
    button.disabled = true;
    button.textContent = '傳送中…';
    try {
      const transferAttachments = [];
      for (const file of pendingFiles) {
        const transferred = await api('/api/transfers?agent=' + encodeURIComponent(target) + '&name=' + encodeURIComponent(file.name), {
          method: 'POST',
          headers: {'Content-Type': file.type || 'application/octet-stream'},
          body: file
        });
        transferAttachments.push(transferred);
      }
      await api('/api/messages', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({sender: window.AGENTHUB_SENDER, target, content: mention + ' ' + text, attachments: transferAttachments})
      });
      contentField.value = '';
      pendingFiles = [];
      syncFileInput();
      renderSelectedFiles();
      lastRendered = '';
      await refresh();
    } catch (error) {
      alert(error.message);
    } finally {
      button.disabled = false;
      button.textContent = '傳送';
    }
  });

  if (historyDate) historyDate.value = localDate();
  loadAgents().then(refresh);
  setInterval(() => {
    if (viewMode === 'today') refresh();
    else loadAgents().catch(() => {});
  }, 2000);
})();
