<?php
declare(strict_types=1);

require_once __DIR__ . '/_init.php';

require_once INCLUDES_DIR . '/db.php';
require_once INCLUDES_DIR . '/auth.php';
require_once INCLUDES_DIR . '/helpers.php';

require_admin();
$admin = current_admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — ArmaLogs</title>
  <style>
    :root{--bg:#0b0d12;--panel:#151821;--text:#d8dce4;--muted:#8b92a8;--accent:#4f8cff;--danger:#ff4f4f;--success:#4fff8f;--border:#252a36;}
    *{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text)}
    header{display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid var(--border);background:var(--panel)}
    header h1{margin:0;font-size:1.2rem}header .meta{color:var(--muted);font-size:.85rem}
    main{max-width:1200px;margin:0 auto;padding:24px}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:18px}
    .card h3{margin:0 0 6px;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
    .card .value{margin:0;font-size:1.8rem;font-weight:600}
    table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden}
    th,td{padding:12px 14px;text-align:left;border-bottom:1px solid var(--border)}
    th{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:600}
    tr:hover td{background:#1c212c}
    .btn{padding:8px 14px;border:none;border-radius:6px;background:var(--accent);color:#fff;cursor:pointer;font-size:.85rem}
    .btn:hover{filter:brightness(1.1)}.btn.danger{background:var(--danger)}
    .muted{color:var(--muted)}.token{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;background:#0f1115;padding:2px 6px;border-radius:4px}
    dialog{border:1px solid var(--border);border-radius:12px;background:var(--panel);color:var(--text);max-width:520px;width:92vw;padding:0}
    dialog form{padding:22px}dialog h2{margin-top:0;font-size:1.1rem}
    dialog input, dialog textarea{width:100%;padding:10px 12px;background:#0f1115;border:1px solid #2c303a;border-radius:6px;color:var(--text);font:inherit;margin-top:6px}
    dialog textarea{min-height:80px;resize:vertical}
    .dialog-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
    .section-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .section-title h2{margin:0}
    .checkbox-col{width:40px}
    .toolbar{display:flex;gap:10px;margin-bottom:12px}
    .toolbar .btn{padding:6px 12px;font-size:.85rem}
    .filter-row{display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap}
    .filter-row input, .filter-row select{padding:8px 12px;background:#0f1115;border:1px solid #2c303a;border-radius:6px;color:var(--text);font:inherit}
    .toast{position:fixed;bottom:20px;right:20px;background:var(--panel);border:1px solid var(--border);padding:14px 18px;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.3);display:none;max-width:420px}
  </style>
</head>
<body>
  <header>
    <div>
      <h1>ArmaLogs</h1>
      <div class="meta">Admin: <?= html_safe($admin['username'] ?? 'unknown') ?></div>
    </div>
    <a class="btn" href="logout.php">Logout</a>
  </header>
  <main>
    <section class="grid">
      <div class="card"><h3>Friends</h3><p class="value" id="stat-friends">—</p></div>
      <div class="card"><h3>Sessions</h3><p class="value" id="stat-sessions">—</p></div>
      <div class="card"><h3>Logs</h3><p class="value" id="stat-logs">—</p></div>
      <div class="card"><h3>Storage</h3><p class="value" id="stat-bytes">—</p></div>
    </section>

    <section>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 style="margin:0">Friends</h2>
        <button class="btn" onclick="openFriendDialog()">+ Add friend</button>
      </div>
      <table id="friends-table">
        <thead>
          <tr><th>Name</th><th>Status</th><th>Sessions</th><th>Logs</th><th>Last seen</th><th>Actions</th></tr>
        </thead>
        <tbody></tbody>
      </table>
    </section>

    <section style="margin-top:32px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h2 style="margin:0">Friend requests</h2>
        <button class="btn" onclick="loadRequests()">Refresh</button>
      </div>
      <table id="requests-table">
        <thead>
          <tr><th>Name</th><th>Hostname</th><th>Requested</th><th>Actions</th></tr>
        </thead>
        <tbody></tbody>
      </table>
    </section>

    <section style="margin-top:32px">
      <div class="section-title">
        <h2>Recent logs</h2>
      </div>
      <div class="toolbar">
        <button class="btn" onclick="selectAllLogs(true)">Select all</button>
        <button class="btn" onclick="selectAllLogs(false)">Deselect</button>
        <button class="btn" onclick="downloadSelectedLogs()">Download .zip</button>
      </div>
      <div class="filter-row">
        <input id="log-filter" placeholder="Search filename / session / friend" oninput="applyLogFilter()">
      </div>
      <table id="logs-table">
        <thead>
          <tr><th class="checkbox-col"><input type="checkbox" id="logs-select-all" onclick="selectAllLogs(this.checked)"></th><th>Time</th><th>Friend</th><th>Session</th><th>Filename</th><th>Size</th></tr>
        </thead>
        <tbody></tbody>
      </table>
    </section>
  </main>

  <dialog id="friend-dialog">
    <form onsubmit="return false">
      <h2 id="dialog-title">Add friend</h2>
      <label>Name<input id="friend-name" required></label>
      <label>Note (optional)<textarea id="friend-note"></textarea></label>
      <div id="token-box" style="display:none">
        <label>Token (copy now — shown once)<input id="friend-token" readonly onclick="this.select()"></label>
      </div>
      <div class="dialog-actions">
        <button type="button" class="btn" style="background:var(--muted)" onclick="closeFriendDialog()">Close</button>
        <button type="submit" class="btn" id="friend-save" onclick="saveFriend()">Save</button>
      </div>
    </form>
  </dialog>

  <div class="toast" id="toast"></div>

  <script>
    const toast = document.getElementById('toast');
    function showToast(msg, isError=false){
      toast.textContent = msg;
      toast.style.borderColor = isError ? 'var(--danger)' : 'var(--success)';
      toast.style.display = 'block';
      setTimeout(()=>toast.style.display='none', 4000);
    }
    async function api(path, options={}){
      const r = await fetch('/api/?path='+path, {headers:{'Accept':'application/json'}, ...options});
      const data = await r.json().catch(()=>({ok:false,error:'Invalid response'}));
      if(!data.ok) throw new Error(data.error || 'Request failed');
      return data;
    }
    function fmtBytes(n){
      if(n===0)return '0 B';
      const k=1024, s=['B','KB','MB','GB','TB'];
      const i=Math.floor(Math.log(n)/Math.log(k));
      return (n/Math.pow(k,i)).toFixed(2)+' '+s[i];
    }
    async function loadStats(){
      const s=await api('stats');
      document.getElementById('stat-friends').textContent=s.friends;
      document.getElementById('stat-sessions').textContent=s.sessions;
      document.getElementById('stat-logs').textContent=s.logs;
      document.getElementById('stat-bytes').textContent=fmtBytes(s.bytes);
    }
    async function loadFriends(){
      const data=await api('friends');
      const tbody=document.querySelector('#friends-table tbody');
      tbody.innerHTML='';
      for(const f of data.friends){
        const tr=document.createElement('tr');
        tr.innerHTML=`
          <td>${escapeHtml(f.name)}${f.note?` <span class="muted">(${escapeHtml(f.note)})</span>`:''}</td>
          <td><span style="color:${f.is_active?'var(--success)':'var(--danger)'}">${f.is_active?'Active':'Inactive'}</span></td>
          <td>${f.session_count}</td>
          <td>${f.log_count}</td>
          <td>${f.last_seen_at?new Date(f.last_seen_at).toLocaleString():'—'}</td>
          <td>
            <button class="btn" style="padding:4px 8px;font-size:.75rem" onclick="toggleFriend(${f.id}, ${!f.is_active})">${f.is_active?'Disable':'Enable'}</button>
            <button class="btn danger" style="padding:4px 8px;font-size:.75rem" onclick="deleteFriend(${f.id}, '${escapeHtml(f.name)}')">Delete</button>
          </td>`;
        tbody.appendChild(tr);
      }
    }
    let allLogs=[];
    async function loadLogs(){
      const data=await api('logs');
      allLogs=data.logs;
      applyLogFilter();
    }
    function applyLogFilter(){
      const filter=document.getElementById('log-filter').value.toLowerCase();
      const filtered=allLogs.filter(l=>
        (l.friend_name||'').toLowerCase().includes(filter)||
        (l.session_id||'').toLowerCase().includes(filter)||
        (l.filename||'').toLowerCase().includes(filter)
      );
      const tbody=document.querySelector('#logs-table tbody');
      tbody.innerHTML='';
      for(const l of filtered.slice(0,50)){
        const tr=document.createElement('tr');
        tr.innerHTML=`
          <td><input type="checkbox" class="log-check" data-id="${l.id}"></td>
          <td>${new Date(l.uploaded_at).toLocaleString()}</td>
          <td>${escapeHtml(l.friend_name)}</td>
          <td><span class="token">${escapeHtml(l.session_id||'unknown')}</span></td>
          <td>${escapeHtml(l.filename)}</td>
          <td>${fmtBytes(l.file_size)}</td>`;
        tbody.appendChild(tr);
      }
    }
    function selectAllLogs(checked){
      document.querySelectorAll('.log-check').forEach(cb=>cb.checked=checked);
      document.getElementById('logs-select-all').checked=checked;
    }
    async function downloadSelectedLogs(){
      const ids=Array.from(document.querySelectorAll('.log-check:checked')).map(cb=>parseInt(cb.dataset.id));
      if(ids.length===0){showToast('No logs selected',true);return;}
      try{
        const r=await fetch('/api/?path=logs',{
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body:JSON.stringify({ids})
        });
        if(!r.ok){throw new Error((await r.json()).error||'Download failed');}
        const blob=await r.blob();
        const url=URL.createObjectURL(blob);
        const a=document.createElement('a');
        a.href=url;
        a.download=`armalogs_${new Date().toISOString().slice(0,19).replace(/[:T]/g,'-')}.zip`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        showToast(`Downloaded ${ids.length} log(s)`);
      }catch(e){showToast(e.message,true);}
    }
    function escapeHtml(s){return s.replace(/[<>&"']/g,c=>({'<':'\u0026lt;','>':'\u0026gt;','&':'\u0026amp;','"':'\u0026quot;',"'":'\u0026#39;'}[c]));}

    const dialog=document.getElementById('friend-dialog');
    function openFriendDialog(){document.getElementById('dialog-title').textContent='Add friend';document.getElementById('friend-name').value='';document.getElementById('friend-note').value='';document.getElementById('token-box').style.display='none';document.getElementById('friend-save').style.display='inline-block';dialog.showModal();}
    function closeFriendDialog(){dialog.close();}
    async function saveFriend(){
      const name=document.getElementById('friend-name').value.trim();
      const note=document.getElementById('friend-note').value.trim();
      if(!name){showToast('Name is required', true);return;}
      try{
        const data=await api('friends',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,note})});
        document.getElementById('friend-token').value=data.token;
        document.getElementById('token-box').style.display='block';
        document.getElementById('friend-save').style.display='none';
        await loadFriends();loadStats();showToast('Friend created');
      }catch(e){showToast(e.message,true);}
    }
    async function toggleFriend(id, active){
      try{
        await api('friends',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,is_active:active})});
        await loadFriends();showToast(active?'Friend enabled':'Friend disabled');
      }catch(e){showToast(e.message,true);}
    }
    async function deleteFriend(id,name){
      if(!confirm(`Delete friend ${name}? Their logs will also be removed.`)) return;
      try{
        await api('friends',{method:'DELETE',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
        await loadFriends();loadStats();showToast('Friend deleted');
      }catch(e){showToast(e.message,true);}
    }
    async function loadRequests(){
      const data=await api('friend-requests');
      const tbody=document.querySelector('#requests-table tbody');
      tbody.innerHTML='';
      if(data.requests.length===0){
        tbody.innerHTML='<tr><td colspan="4" class="muted">No pending requests</td></tr>';
        return;
      }
      for(const r of data.requests){
        const tr=document.createElement('tr');
        tr.innerHTML=`
          <td>${escapeHtml(r.name)}</td>
          <td>${escapeHtml(r.hostname||'—')}</td>
          <td>${new Date(r.created_at).toLocaleString()}</td>
          <td>
            <button class="btn" style="padding:4px 8px;font-size:.75rem" onclick="approveRequest(${r.id})">Approve</button>
            <button class="btn danger" style="padding:4px 8px;font-size:.75rem" onclick="rejectRequest(${r.id})">Reject</button>
          </td>`;
        tbody.appendChild(tr);
      }
    }
    async function approveRequest(id){
      try{
        const data=await api('friend-requests-approve',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
        await loadRequests();await loadFriends();loadStats();showToast('Request approved');
      }catch(e){showToast(e.message,true);}
    }
    async function rejectRequest(id){
      try{
        await api('friend-requests-reject',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
        await loadRequests();showToast('Request rejected');
      }catch(e){showToast(e.message,true);}
    }
    (async()=>{await loadStats();await loadFriends();await loadRequests();await loadLogs();})();
  </script>
</body>
</html>
