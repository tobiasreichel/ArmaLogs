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
    .tree-group{margin-bottom:12px;border:1px solid var(--border);border-radius:8px;overflow:hidden}
    .tree-header{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#111318;cursor:pointer;user-select:none}
    .tree-header:hover{background:#181b22}
    .tree-header .title{font-weight:600}
    .tree-header .meta{font-size:.8rem;color:var(--muted)}
    .tree-body{display:none;background:var(--panel);padding-left:18px}
    .tree-body.open{display:block}
    .tree-body table{margin:0;border:0;border-radius:0}
    .tree-body tbody tr:last-child td{border-bottom:0}
    .session-row{background:#151821}
    .session-row:hover{background:#1b202b}
    .section-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
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
      <div class="card"><h3>AI Reports</h3><p class="value" id="stat-reports">—</p></div>
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
      <div class="section-title" style="margin-bottom:12px">
        <h2>AI Reports</h2>
        <button class="btn" onclick="loadReports()">Refresh</button>
      </div>
      <div id="reports-list"></div>
    </section>

    <section style="margin-top:32px">
      <div class="section-title" style="margin-bottom:12px">
        <h2>Logs by session</h2>
      </div>
      <div class="toolbar" style="margin-bottom:12px">
        <button class="btn" onclick="expandAllTrees(true)">Expand all</button>
        <button class="btn" onclick="expandAllTrees(false)">Collapse all</button>
        <button class="btn" onclick="downloadSelectedLogs()">Download .zip</button>
        <button class="btn" onclick="analyzeSelectedLogs()">Analyze with AI</button>
      </div>
      <div class="filter-row" style="margin-bottom:12px">
        <input id="log-filter" placeholder="Search filename / session / friend" oninput="applyLogFilter()">
      </div>
      <div id="logs-tree"></div>
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
    function escapeHtml(s){
      if(s===null||s===undefined) return '';
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }
    async function api(path, options={}){
      let url='/api/?path='+encodeURIComponent(path);
      if(options.params){
        for(const [k,v] of Object.entries(options.params)){
          url+='&'+encodeURIComponent(k)+'='+encodeURIComponent(v);
        }
      }
      const {params, ...rest} = options;
      const r = await fetch(url, {headers:{'Accept':'application/json'}, ...rest});
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
      document.getElementById('stat-reports').textContent=s.reports ?? 0;
    }
    async function loadFriends(){
      const data=await api('friends');
      const tbody=document.querySelector('#friends-table tbody');
      if(!tbody){console.error('friends-table tbody not found');return;}
      tbody.innerHTML='';
      if(!data.friends||data.friends.length===0){tbody.innerHTML='<tr><td colspan="6" class="muted">No friends</td></tr>';return;}
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
    async function loadRequests(){
      const data=await api('friend-requests',{params:{status:'all'}});
      const tbody=document.querySelector('#requests-table tbody');
      tbody.innerHTML='';
      const rows=data.requests||[];
      if(rows.length===0){
        tbody.innerHTML='<tr><td colspan="4" class="muted">No pending requests</td></tr>';
        return;
      }
      for(const req of rows){
        const tr=document.createElement('tr');
        tr.innerHTML=`<td>${escapeHtml(req.name)}</td>
          <td class="muted">${escapeHtml(req.hostname||'—')}</td>
          <td>${new Date(req.created_at).toLocaleString()}</td>
          <td>
            ${req.status==='pending'?`<button class="btn" style="padding:4px 8px;font-size:.75rem" onclick="approveRequest(${req.id})">Approve</button>
            <button class="btn danger" style="padding:4px 8px;font-size:.75rem" onclick="rejectRequest(${req.id})">Reject</button>`:`<span class="muted">${escapeHtml(req.status)}</span>`}
          </td>`;
        tbody.appendChild(tr);
      }
    }
    async function approveRequest(id){
      await api('friend-requests-approve',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
      await loadRequests(); await loadFriends(); loadStats();
    }
    async function rejectRequest(id){
      await api('friend-requests-reject',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
      await loadRequests();
    }

    let allLogs=[];
    async function loadLogs(){
      const data=await api('logs');
      allLogs=data.logs;
      applyLogFilter();
    }
    function groupByFriendSession(logs){
      const friends={};
      for(const l of logs){
        const friend=l.friend_name||'unknown';
        const session=l.session_id||'unknown';
        if(!friends[friend]) friends[friend]={sessions:{}};
        if(!friends[friend].sessions[session]) friends[friend].sessions[session]=[];
        friends[friend].sessions[session].push(l);
      }
      const result=[];
      for(const friend of Object.keys(friends).sort()){
        const sessions=[];
        for(const session of Object.keys(friends[friend].sessions).sort()){
          const items=friends[friend].sessions[session].sort((a,b)=>a.filename.localeCompare(b.filename));
          sessions.push({session,items});
        }
        result.push({friend,sessions});
      }
      return result;
    }
    function applyLogFilter(){
      const filter=document.getElementById('log-filter').value.toLowerCase();
      const filtered=allLogs.filter(l=>
        (l.friend_name||'').toLowerCase().includes(filter)||
        (l.session_id||'').toLowerCase().includes(filter)||
        (l.filename||'').toLowerCase().includes(filter)
      );
      const tree=document.getElementById('logs-tree');
      tree.innerHTML='';
      const groups=groupByFriendSession(filtered);
      for(const g of groups){
        const friendTotal=g.sessions.reduce((s,sess)=>s+sess.items.reduce((ss,l)=>ss+(l.file_size||0),0),0);
        const friendFiles=g.sessions.reduce((s,sess)=>s+sess.items.length,0);
        const friendDiv=document.createElement('div');
        friendDiv.className='tree-group';
        friendDiv.innerHTML=`
          <div class="tree-header" onclick="toggleTree(this)">
            <div class="title">${escapeHtml(g.friend)}</div>
            <div class="meta">${g.sessions.length} session${g.sessions.length===1?'':'s'} · ${friendFiles} file${friendFiles===1?'':'s'} · ${fmtBytes(friendTotal)}</div>
          </div>
          <div class="tree-body">${g.sessions.map(sess=>{
            const sessTotal=sess.items.reduce((s,l)=>s+(l.file_size||0),0);
            return `
            <div class="tree-group" style="border:0;border-bottom:1px solid var(--border);margin-bottom:0">
              <div class="tree-header session-row" onclick="toggleTree(this)">
                <div class="title" style="font-weight:500">${escapeHtml(sess.session)}</div>
                <div class="meta">${sess.items.length} file${sess.items.length===1?'':'s'} · ${fmtBytes(sessTotal)}</div>
              </div>
              <div class="tree-body">
                <table>
                  <thead><tr><th width="40"><input type="checkbox" class="group-check" onclick="selectGroup(this)"></th><th>Time</th><th>Filename</th><th>Size</th></tr></thead>
                  <tbody>${sess.items.map(l=>`
                    <tr>
                      <td><input type="checkbox" class="log-check" data-id="${l.id}"></td>
                      <td>${new Date(l.uploaded_at).toLocaleString()}</td>
                      <td>${escapeHtml(l.filename)}</td>
                      <td>${fmtBytes(l.file_size)}</td>
                    </tr>`).join('')}</tbody>
                </table>
              </div>
            </div>`;
          }).join('')}</div>`;
        tree.appendChild(friendDiv);
      }
    }
    function toggleTree(header){
      header.nextElementSibling.classList.toggle('open');
    }
    function expandAllTrees(open){
      document.querySelectorAll('.tree-body').forEach(b=>open?b.classList.add('open'):b.classList.remove('open'));
    }
    function selectGroup(cb){
      cb.closest('.tree-group').querySelectorAll('.log-check').forEach(c=>c.checked=cb.checked);
      if(window.event) window.event.stopPropagation();
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
    async function analyzeSelectedLogs(){
      const ids=Array.from(document.querySelectorAll('.log-check:checked')).map(cb=>parseInt(cb.dataset.id));
      if(ids.length===0){showToast('No logs selected',true);return;}
      showToast('Analyzing with AI...');
      try{
        const data=await api('analyze',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({ids})});
        await loadReports();loadStats();showToast(`AI report created: ${data.report.title}`);
      }catch(e){showToast(e.message,true);}
    }
    const severityColor={critical:'var(--danger)',warning:'#ffaa00',info:'var(--success)'};
        function stripHtml(html){
    const tmp=document.createElement('div');
    tmp.innerHTML=html;
    return tmp.textContent||tmp.innerText||'';
    }
    function downloadReportMarkdown(ev,id){
    ev.stopPropagation();
    const div=document.querySelector(`#reports-list .tree-group:has(.tree-header .meta button[onclick*="downloadReportMarkdown(event,${id})"])`);
    if(!div) return;
    const r=JSON.parse(div.dataset.report||'{}');
    let md = r.markdown || '';
    if(!md){
    let findings=[];
    try{findings=Array.isArray(r.findings)?r.findings:JSON.parse(r.findings||'[]');}catch(e){}
    const date=new Date(r.created_at).toLocaleString();
    md=`# ${r.title||'Untitled report'}

    `;
    md+=`- **Friend:** ${r.friend_name||'unknown'}
    `;
    md+=`- **Session:** ${r.session_id||'unknown'}
    `;
    md+=`- **Created:** ${date}
    `;
    md+=`- **Model:** ${r.model||'unknown'}

    `;
    md+=`## Summary

    ${(r.summary||'').trim()}

    `;
    if(findings.length){
    md+=`## Findings

    `;
    for(const f of findings){
    md+=`### [${(f.severity||'info').toUpperCase()}] ${f.title||''} (${f.category||'other'})

    ${(f.details||'').trim()}

    `;
    }
    }
    }
    const blob=new Blob([md],{type:'text/markdown'});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');
    a.href=url;
    a.download=`report_${r.id||id}_${(r.title||'report').replace(/\s+/g,'_').toLowerCase()}.md`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    }

    function mdToHtml(s){
    if(!s) return '';
    return escapeHtml(s)
    .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
    .replace(/^\s*-\s+(.+)$/gm,'<li>$1</li>')
    .replace(/\n/g,'<br>');
    }

    async function loadReports(){
      const data=await api('reports');
      const list=document.getElementById('reports-list');
      if(!list){console.error('reports-list not found');return;}
      if(!data.reports||data.reports.length===0){list.innerHTML='<p class="muted">No reports yet. Select logs and click Analyze with AI.</p>';return;}
      list.innerHTML='';
      for(const r of data.reports){
        const div=document.createElement('div');
        div.className='tree-group';
        div.innerHTML=`<div class="tree-header" onclick="toggleTree(this)">
          <div class="title">${escapeHtml(r.title||'Untitled report')}</div>
          <div class="meta">${escapeHtml(r.friend_name||'unknown')} / ${escapeHtml(r.session_id||'unknown')} · ${new Date(r.created_at).toLocaleString()} · <button class="btn" style="padding:2px 8px;font-size:.75rem" onclick="downloadReportMarkdown(event,${r.id})">Download .md</button></div>
        </div>
        <div class="tree-body" style="padding:16px">
          <div class="markdown" style="line-height:1.55">${mdToHtml(r.markdown || r.summary || '')}</div>
        </div>`;
        div.dataset.report = JSON.stringify(r);
        list.appendChild(div);
      }
    }
    (async()=>{
      for(const fn of [loadStats,loadFriends,loadRequests,loadLogs,loadReports]){
        try{await fn();}catch(e){console.error(e);showToast(e.message,true);}
      }
    })();
  </script>
</body>
</html>
