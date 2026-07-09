let currentUser = null, currentOrgId = null, organizations = [], allVariables = [], activeCategory = 'Todas', uploadedImages = { wallpapers: [], logos: [] };

const API = {
    get: async (action, params = {}) => { const url = new URL('/api/', location.origin); url.searchParams.set('action', action); Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v)); return (await fetch(url, {credentials: 'same-origin'})).json(); },
    post: async (action, data) => { const url = new URL('/api/', location.origin); url.searchParams.set('action', action); return (await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data), credentials: 'same-origin'})).json(); },
    put: async (action, id, data) => { const url = new URL('/api/', location.origin); url.searchParams.set('action', action); url.searchParams.set('id', id); return (await fetch(url, {method: 'PUT', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data), credentials: 'same-origin'})).json(); },
    delete: async (action, id) => { const url = new URL('/api/', location.origin); url.searchParams.set('action', action); url.searchParams.set('id', id); return (await fetch(url, {method: 'DELETE', credentials: 'same-origin'})).json(); }
};

const Utils = { escapeHtml: str => str?.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"})[m]) || '', formatDate: d => d ? new Date(d).toLocaleString('pt-BR') : '-' };
const Toast = { show: (msg, type = 'success') => { const div = document.createElement('div'); div.className = `toast ${type}`; div.textContent = msg; document.getElementById('toast-container').appendChild(div); setTimeout(() => div.remove(), 4000); }, success: msg => Toast.show(msg, 'success'), error: msg => Toast.show(msg, 'error'), warning: msg => Toast.show(msg, 'warning') };

const categoryLabels = {'dominio':'Dominio','rede':'Rede','proxy':'Proxy','inventario':'Inventario','navegador':'Navegador','seguranca':'Seguranca','branding':'Identidade','general':'Geral','custom':'Custom','arquivos':'Arquivos','acesso_remoto':'Acesso Remoto','impressoras':'Impressoras','certificados':'Certificados','repositorios':'Repositorios'};
const categoryOrder = ['dominio','rede','proxy','repositorios','navegador','branding','arquivos','impressoras','inventario','acesso_remoto','certificados','seguranca','general','custom'];

const variableOptions = {
    'PROXY_MODE': ['NONE', 'MANUAL', 'PAC'],
    'REPOSITORY_MODE': ['PUBLIC', 'MIRROR', 'HYBRID', 'CUSTOM'],
    'REMOTE_METHOD': ['ssh', 'xrdp', 'anydesk', 'rustdesk'],
    'PROXY_PORTA': ['80', '8080', '3128', '8888'],
    'OFFLINE_AUTH_ENABLED': 'boolean',
    'INVENTORY_ENABLED': 'boolean',
    'CERTIFICATE_AUTO_INSTALL': 'boolean',
    'AUTO_UPDATE': 'boolean',
    'DEBUG_MODE': 'boolean'
};

document.addEventListener('DOMContentLoaded', async () => {
    try {
        const session = await API.get('session');
        if (!session.success) { location.href = '/login.html'; return; }
        currentUser = session.data;
        document.getElementById('user-name').textContent = currentUser.full_name || currentUser.username;
        document.getElementById('user-initial').textContent = (currentUser.full_name || currentUser.username).charAt(0);
        document.getElementById('user-role').textContent = {admin_gap:'Admin GAP',operador_om:'Operador OM',auditor:'Auditor'}[currentUser.role] || currentUser.role;
        const isAdmin = currentUser.role === 'admin_gap';
        ['nav-users','nav-scripts-core','nav-audit','btn-new-org','btn-new-user'].forEach(id => { const el = document.getElementById(id); if (el) el.classList.toggle('hidden', !isAdmin); });
        if (currentUser.role === 'operador_om') { const s = document.getElementById('orgs-section'); if (s) s.style.display = 'none'; }
    } catch (e) { location.href = '/login.html'; return; }
    await loadDashboard(); await loadOrganizations(); setupEventListeners();
});

function showView(viewName) {
    document.querySelectorAll('.nav-main').forEach(b => b.classList.remove('active','bg-slate-700','text-white'));
    document.querySelector(`.nav-main[data-view="${viewName}"]`)?.classList.add('active','bg-slate-700','text-white');
    ['view-dashboard','view-om-detail','view-users','view-scripts-core','view-audit'].forEach(id => document.getElementById(id)?.classList.add('hidden'));
    const titles = {dashboard:['Dashboard','Visao geral'],users:['Usuarios','Gerenciamento'],scripts:['Scripts','Gerenciamento'],audit:['Auditoria','Eventos']};
    if (viewName === 'dashboard') document.getElementById('view-dashboard')?.classList.remove('hidden');
    else if (viewName === 'users') { document.getElementById('view-users')?.classList.remove('hidden'); loadUsers(); }
    else if (viewName === 'scripts-core') { document.getElementById('view-scripts-core')?.classList.remove('hidden'); loadAllScripts(); }
    else if (viewName === 'audit') { document.getElementById('view-audit')?.classList.remove('hidden'); loadAuditEvents(); }
    if (titles[viewName]) { document.getElementById('page-title').textContent = titles[viewName][0]; document.getElementById('page-subtitle').textContent = titles[viewName][1]; }
}
window.showView = showView;

async function loadDashboard() {
    const res = await API.get('dashboard');
    if (!res.success) return;
    const stats = res.data;
    document.getElementById('dash-orgs').textContent = stats.organizations;
    document.getElementById('dash-scripts').textContent = stats.scripts;
    document.getElementById('dash-vars').textContent = stats.variables;
    document.getElementById('dash-bundles').textContent = stats.bundles_this_month;
    document.getElementById('dash-stations-online').textContent = stats.stations_online;
    document.getElementById('dash-stations-outdated').textContent = stats.stations_outdated;

    const orgsRes = await API.get('organizations');
    if (orgsRes.success) {
        organizations = orgsRes.data;
        document.getElementById('recent-orgs').innerHTML = organizations.map(o => `<div class="flex items-center justify-between py-2 border-b border-slate-700"><div class="flex items-center gap-2"><span class="font-semibold text-blue-400">${Utils.escapeHtml(o.acronym)}</span><span class="text-slate-400 text-sm">${Utils.escapeHtml(o.name)}</span></div><button onclick="selectOrganization(${o.id})" class="text-blue-400 hover:text-blue-300 text-sm">Ver</button></div>`).join('');
    }

    const stationsEl = document.getElementById('recent-stations');
    if (stationsEl && stats.recent_stations?.length) {
        stationsEl.innerHTML = `<table class="w-full text-sm"><thead class="bg-slate-900"><tr><th class="px-4 py-2 text-left text-slate-400">Hostname</th><th class="px-4 py-2 text-left text-slate-400">IP</th><th class="px-4 py-2 text-left text-slate-400">Check-in</th><th class="px-4 py-2 text-left text-slate-400">OM</th><th class="px-4 py-2 text-left text-slate-400">Status</th></tr></thead><tbody>${stats.recent_stations.map(s => `<tr class="border-b border-slate-700"><td class="px-4 py-2 text-white">${Utils.escapeHtml(s.hostname || '-')}</td><td class="px-4 py-2 text-slate-300">${Utils.escapeHtml(s.ip_address || '-')}</td><td class="px-4 py-2 text-slate-300">${Utils.formatDate(s.last_checkin)}</td><td class="px-4 py-2 text-slate-300">${Utils.escapeHtml(s.org_acronym || '-')}</td><td class="px-4 py-2"><span class="px-2 py-0.5 text-xs rounded ${s.status === 'Atualizado' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-400'}">${s.status}</span></td></tr>`).join('')}</tbody></table>`;
    } else if (stationsEl) stationsEl.innerHTML = '<p class="text-slate-400 text-center py-4">Nenhuma estacao registrada</p>';
}

async function loadOrganizations() {
    const el = document.getElementById('om-list');
    const res = await API.get('organizations');
    if (!res.success) return;
    organizations = res.data;
    if (!organizations.length) { el.innerHTML = '<div class="text-center py-4 text-slate-500 text-sm">Nenhuma OM</div>'; return; }
    el.innerHTML = organizations.map(o => `<button class="nav-item w-full flex items-center gap-3 px-3 py-2 rounded-lg text-slate-300 hover:bg-slate-700 transition-colors text-left" data-org-id="${o.id}" onclick="selectOrganization(${o.id})"><div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-emerald-500 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">${o.logo_url?`<img src="${Utils.escapeHtml(o.logo_url)}" class="w-full h-full object-cover rounded" onerror="this.parentElement.textContent='${o.acronym.substring(0,3)}'">`:o.acronym.substring(0,3)}</div><div><span class="block font-medium truncate">${Utils.escapeHtml(o.acronym)}</span><span class="block text-xs text-slate-500 truncate">${Utils.escapeHtml(o.name)}</span></div></button>`).join('');
    const sel = document.getElementById('user-organization');
    if (sel) sel.innerHTML = '<option value="">Nenhuma</option>' + organizations.map(o => `<option value="${o.id}">${Utils.escapeHtml(o.acronym)}</option>`).join('');
    if (currentUser.role === 'operador_om' && organizations.length === 1) selectOrganization(organizations[0].id);
}

async function selectOrganization(orgId) {
    currentOrgId = orgId;
    document.querySelectorAll('.nav-item').forEach(i => { i.classList.remove('active','bg-slate-700','text-white'); if (parseInt(i.dataset.orgId) === orgId) i.classList.add('active','bg-slate-700','text-white'); });
    const org = organizations.find(o => o.id === orgId);
    if (!org) return;
    document.getElementById('page-title').textContent = org.acronym;
    document.getElementById('page-subtitle').textContent = org.name;
    document.getElementById('view-dashboard').classList.add('hidden');
    document.getElementById('view-om-detail').classList.remove('hidden');
    const badge = document.getElementById('om-acronym-badge');
    badge.innerHTML = org.logo_url ? `<img src="${Utils.escapeHtml(org.logo_url)}" class="w-full h-full object-cover rounded-xl" onerror="this.parentElement.textContent='${org.acronym.substring(0,3)}'">` : org.acronym.substring(0,3);
    document.getElementById('om-display-name').textContent = org.name;
    document.getElementById('om-display-domain').textContent = org.domain || 'Sem dominio';
    document.getElementById('edit-org-name').value = org.name;
    document.getElementById('edit-org-acronym').value = org.acronym;
    document.getElementById('edit-org-domain').value = org.domain || '';
    document.getElementById('edit-org-description').value = org.description || '';
    switchTab('variables');
    await loadVariables(orgId);
}
window.selectOrganization = selectOrganization;

async function loadVariables(orgId) {
    const res = await API.get('variables', {id: orgId});
    if (!res.success) { Toast.error('Erro ao carregar variaveis: ' + (res.error || 'Unknown')); return; }
    allVariables = res.data.variables || [];
    activeCategory = 'Todas';
    renderVariables(allVariables);
    try { const [w, l] = await Promise.all([API.get('wallpapers',{org_id:orgId}), API.get('logos',{org_id:orgId})]); uploadedImages.wallpapers = w.success ? w.data.images : []; uploadedImages.logos = l.success ? l.data.images : []; } catch(e) {}
}

function renderVariables(vars) {
    const el = document.getElementById('vars-list');
    if (!vars.length) { el.innerHTML = '<div class="text-center py-8 text-slate-400">Nenhuma variavel</div>'; return; }
    const cats = [...new Set(vars.map(v => v.category || 'general'))].sort((a,b) => { const ai = categoryOrder.indexOf(a), bi = categoryOrder.indexOf(b); return (ai===-1?1:ai)-(bi===-1?1:bi); });
    let html = '<div class="category-tabs mb-4"><button class="cat-tab ' + (activeCategory==='Todas'?'active':'') + '" onclick="filterByCategory(\'Todas\')">Todas</button>';
    cats.forEach(c => { const cnt = vars.filter(v => (v.category||'general')===c).length; html += `<button class="cat-tab ${activeCategory===c?'active':''}" onclick="filterByCategory('${Utils.escapeHtml(c)}')">${categoryLabels[c]||c} (${cnt})</button>`; });
    html += '</div>';
    el.innerHTML = html;

    let filtered = activeCategory === 'Todas' ? vars : vars.filter(v => (v.category||'general') === activeCategory);
    const search = (document.getElementById('var-search')?.value || '').toLowerCase();
    if (search) filtered = filtered.filter(v => v.name.toLowerCase().includes(search));

    html = '<div class="grid lg:grid-cols-2 gap-4 mt-4">';
    if (activeCategory === 'Todas') {
        cats.forEach(c => {
            const cv = filtered.filter(v => (v.category||'general')===c);
            if (!cv.length) return;
            html += `<div class="col-span-2 mt-4 first:mt-0"><h4 class="text-sm font-semibold text-slate-400 uppercase">${categoryLabels[c]||c}</h4></div>`;
            cv.forEach(v => html += renderVarRow(v));
        });
    } else filtered.forEach(v => html += renderVarRow(v));
    html += '</div>';
    el.innerHTML += html;
}

function renderVarRow(v) {
    const input = renderTypedInput(v);
    const isImg = v.name === 'WALLPAPER_URL' || v.name === 'LOGO_URL';
    const type = v.name === 'WALLPAPER_URL' ? 'wallpaper' : 'logo';
    const imgs = uploadedImages[type + 's'] || [];
    const gallery = isImg ? `<div class="mt-2"><label class="inline-flex items-center gap-1 px-2 py-1 bg-blue-600 text-white rounded text-xs cursor-pointer hover:bg-blue-700"><input type="file" class="hidden" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" onchange="uploadImage('${type}',${v.id},this)"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>Upload</label></div><div class="image-gallery mt-2" id="${type}-gallery">${imgs.map(i => `<div class="gallery-item ${i.url===(v.current_value||'')?'selected':''}" onclick="selectImage('${type}',${v.id},'${Utils.escapeHtml(i.url)}',this)"><img src="${i.thumbnail || i.url}" alt="${i.filename}"></div>`).join('') || '<span class="text-slate-500 text-xs">Nenhuma imagem</span>'}</div>` : '';
    return `<div class="var-row"><label class="block text-sm font-medium text-slate-300 mb-1">${Utils.escapeHtml(v.name)}${v.is_required?'<span class="text-red-400">*</span>':''}</label>${input}${v.description?`<p class="text-slate-500 text-xs mt-1">${Utils.escapeHtml(v.description)}</p>`:''}${gallery}</div>`;
}

function renderTypedInput(v) {
    const val = v.current_value || '';
    const ph = v.default_value || '';
    const varId = v.id;

    const predefinedOptions = variableOptions[v.name];
    if (predefinedOptions === 'boolean' || v.type === 'boolean') {
        const checked = val === 'true' || val === '1' || val === true;
        return `<label class="inline-flex items-center cursor-pointer"><input type="checkbox" data-var-id="${varId}" ${checked ? 'checked' : ''} class="sr-only peer" onchange="this.nextElementSibling.nextElementSibling.checked = this.checked"><div class="relative w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:bg-emerald-600 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all"></div><span class="ml-2 text-sm text-slate-300">${checked ? 'Ativo' : 'Inativo'}</span></label>`;
    }

    if (Array.isArray(predefinedOptions)) {
        let optionsHtml = predefinedOptions.map(opt => `<option value="${opt}" ${val === opt ? 'selected' : ''}>${opt}</option>`).join('');
        const hasCustomVal = val && !predefinedOptions.includes(val);
        return `<select data-var-id="${varId}" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">${optionsHtml}${hasCustomVal ? `<option value="${Utils.escapeHtml(val)}" selected>${Utils.escapeHtml(val)}</option>` : ''}<option value="">Outro...</option></select>`;
    }

    if (v.type === 'password') return `<input type="password" data-var-id="${varId}" value="${Utils.escapeHtml(val)}" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm" placeholder="******">`;
    if (v.type === 'json') return `<textarea data-var-id="${varId}" rows="3" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm font-mono">${Utils.escapeHtml(val)}</textarea>`;
    if (v.type === 'array') return `<textarea data-var-id="${varId}" rows="2" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">${Utils.escapeHtml(val)}</textarea>`;
    if (v.type === 'url' || v.name.includes('URL')) return `<input type="url" data-var-id="${varId}" value="${Utils.escapeHtml(val)}" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm" placeholder="${Utils.escapeHtml(ph)}">`;
    if (v.type === 'ip' || v.name.includes('IP') || v.name.includes('DNS')) return `<input type="text" data-var-id="${varId}" value="${Utils.escapeHtml(val)}" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm font-mono" placeholder="${Utils.escapeHtml(ph)}">`;

    return `<input type="text" data-var-id="${varId}" value="${Utils.escapeHtml(val)}" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm" placeholder="${Utils.escapeHtml(ph)}">`;
}

function selectImage(type, varId, url, el) {
    const input = document.querySelector(`input[data-var-id="${varId}"]`);
    if (input) input.value = url;
    const gallery = el.closest('.image-gallery');
    gallery.querySelectorAll('.gallery-item').forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
}
window.selectImage = selectImage;

async function uploadImage(type, varId, input) {
    const file = input.files[0];
    if (!file || !currentOrgId) return;
    if (!['image/jpeg','image/png','image/gif','image/webp','image/svg+xml'].includes(file.type)) { Toast.error('Tipo invalido'); return; }
    if (file.size > 10*1024*1024) { Toast.error('Max 10MB'); return; }
    const fd = new FormData();
    fd.append(type, file);
    fd.append('organization_id', currentOrgId);
    try {
        const res = await fetch(`/api/?action=upload-${type}`, {method:'POST', body:fd, credentials:'same-origin'});
        const data = await res.json();
        if (data.success) {
            Toast.success('Enviado');
            document.querySelector(`input[data-var-id="${varId}"]`).value = data.data.url;
            const gallery = document.getElementById(`${type}-gallery`);
            if (gallery) {
                gallery.querySelectorAll('.gallery-item').forEach(i => i.classList.remove('selected'));
                const item = document.createElement('div');
                item.className = 'gallery-item selected';
                item.innerHTML = `<img src="${data.data.thumbnail || data.data.url}" alt="${data.data.filename}">`;
                item.onclick = () => selectImage(type, varId, data.data.url, item);
                gallery.insertBefore(item, gallery.firstChild);
            }
        } else Toast.error(data.error);
    } catch(e) { Toast.error('Erro'); }
}
window.uploadImage = uploadImage;

function filterByCategory(c) { activeCategory = c; renderVariables(allVariables); }
window.filterByCategory = filterByCategory;

async function saveVariables() {
    if (!currentOrgId) return;
    const updates = {};
    document.querySelectorAll('[data-var-id]').forEach(el => {
        let value;
        if (el.type === 'checkbox') value = el.checked ? 'true' : 'false';
        else value = el.value;
        updates[el.dataset.varId] = value;
    });
    const res = await API.post('variables-update', {organization_id: currentOrgId, variables: updates});
    if (res.success) { Toast.success('Salvo'); loadVariables(currentOrgId); } else Toast.error(res.error);
}
window.saveVariables = saveVariables;

function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach(b => { b.classList.remove('active','border-blue-500','text-blue-400'); b.classList.add('border-transparent','text-slate-400'); });
    document.querySelector(`.tab-btn[data-tab="${name}"]`)?.classList.add('active','border-blue-500','text-blue-400');
    document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
    document.getElementById('tab-' + name)?.classList.remove('hidden');
}
window.switchTab = switchTab;

function openModal(id) { document.getElementById(id)?.classList.remove('hidden'); }
window.openModal = openModal;
function closeModal(id) { document.getElementById(id)?.classList.add('hidden'); }
window.closeModal = closeModal;

async function loadAllScripts() {
    const res = await API.get('scripts');
    if (!res.success) return;
    const core = res.data.filter(s => s.is_core), custom = res.data.filter(s => !s.is_core);
    document.getElementById('scripts-list').innerHTML = `<div class="mb-6"><h4 class="text-sm font-semibold text-slate-400 uppercase mb-3">Scripts Core</h4><div class="space-y-2">${core.map(s => `<div class="p-4 bg-slate-900 rounded-lg border border-slate-700 flex justify-between items-center"><div><span class="font-medium text-white">${Utils.escapeHtml(s.name)}</span><span class="text-slate-500 text-sm ml-2">${Utils.escapeHtml(s.filename)}</span><span class="ml-2 px-2 py-0.5 text-xs bg-blue-500/20 text-blue-400 rounded">Core</span></div><button onclick="viewScript(${s.id})" class="text-blue-400 hover:text-blue-300 text-sm">Ver</button></div>`).join('')}</div></div><div><div class="flex justify-between mb-3"><h4 class="text-sm font-semibold text-slate-400 uppercase">Scripts Custom</h4><button onclick="openModal('modal-new-script')" class="text-sm text-blue-400 hover:text-blue-300">+ Novo</button></div><div class="space-y-2">${custom.length ? custom.map(s => `<div class="p-4 bg-slate-900 rounded-lg border border-slate-700 flex justify-between items-center"><div><span class="font-medium text-white">${Utils.escapeHtml(s.name)}</span><span class="text-slate-500 text-sm ml-2">${Utils.escapeHtml(s.filename)}</span></div><div class="flex gap-2"><button onclick="viewScript(${s.id})" class="text-blue-400 hover:text-blue-300 text-sm">Ver</button><button onclick="editScript(${s.id})" class="text-amber-400 hover:text-amber-300 text-sm">Editar</button><button onclick="deleteScript(${s.id})" class="text-red-400 hover:text-red-300 text-sm">Excluir</button></div></div>`).join('') : '<p class="text-slate-500 text-sm">Nenhum</p>'}</div></div>`;
}

async function viewScript(id) {
    const res = await API.get('script', {id});
    if (!res.success) { Toast.error(res.error); return; }
    document.getElementById('script-view-name').textContent = res.data.name;
    document.getElementById('script-view-filename').textContent = res.data.filename;
    document.getElementById('script-view-content').value = res.data.content || '';
    document.getElementById('script-view-core').textContent = res.data.is_core ? 'Sim' : 'Nao';
    document.getElementById('script-edit-btn').classList.toggle('hidden', res.data.is_core);
    document.getElementById('script-delete-btn').classList.toggle('hidden', res.data.is_core);
    if (!res.data.is_core) { document.getElementById('script-edit-btn').onclick = () => editScript(id); document.getElementById('script-delete-btn').onclick = () => deleteScript(id); }
    openModal('modal-view-script');
}
window.viewScript = viewScript;

async function editScript(id) {
    const res = await API.get('script', {id});
    if (!res.success) { Toast.error(res.error); return; }
    document.getElementById('edit-script-id').value = res.data.id;
    document.getElementById('edit-script-name').value = res.data.name;
    document.getElementById('edit-script-filename').value = res.data.filename;
    document.getElementById('edit-script-description').value = res.data.description || '';
    document.getElementById('edit-script-content').value = res.data.content || '';
    closeModal('modal-view-script'); openModal('modal-edit-script');
}
window.editScript = editScript;

async function saveScript(e) {
    e.preventDefault();
    const id = document.getElementById('edit-script-id').value;
    const res = await API.put('script', id, { name: document.getElementById('edit-script-name').value, description: document.getElementById('edit-script-description').value, content: document.getElementById('edit-script-content').value });
    if (res.success) { Toast.success('Salvo'); closeModal('modal-edit-script'); loadAllScripts(); } else Toast.error(res.error);
}
window.saveScript = saveScript;

async function deleteScript(id) {
    if (!confirm('Excluir este script?')) return;
    const res = await API.delete('script', id);
    if (res.success) { Toast.success('Excluido'); closeModal('modal-view-script'); loadAllScripts(); } else Toast.error(res.error);
}
window.deleteScript = deleteScript;

async function createScript(e) {
    e.preventDefault();
    const res = await API.post('script', { name: document.getElementById('new-script-name').value, filename: document.getElementById('new-script-filename').value, description: document.getElementById('new-script-description').value, content: document.getElementById('new-script-content').value });
    if (res.success) { Toast.success('Criado'); closeModal('modal-new-script'); loadAllScripts(); } else Toast.error(res.error);
}
window.createScript = createScript;

async function generateBundle() {
    if (!currentOrgId) return;
    const res = await API.post('bundle', {organization_id: currentOrgId});
    if (res.success && res.data.download_url) { Toast.success('Gerado'); location.href = res.data.download_url; }
    else Toast.error(res.error || 'Erro');
}
window.generateBundle = generateBundle;

async function loadUsers() {
    const res = await API.get('users');
    if (!res.success) return;
    document.getElementById('users-tbody').innerHTML = res.data.length ? res.data.map(u => `<tr><td class="px-6 py-4 text-sm text-white">${Utils.escapeHtml(u.username)}</td><td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(u.full_name||'-')}</td><td class="px-6 py-4"><span class="px-2 py-1 text-xs rounded bg-blue-500/20 text-blue-400">${{admin_gap:'Admin GAP',operador_om:'Operador OM',auditor:'Auditor'}[u.role]||u.role}</span></td><td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(u.org_acronym||'-')}</td><td class="px-6 py-4"><span class="px-2 py-1 text-xs rounded ${u.is_active?'bg-emerald-500/20 text-emerald-400':'bg-slate-700 text-slate-400'}">${u.is_active?'Ativo':'Inativo'}</span></td><td class="px-6 py-4 text-right"><button onclick="editUser(${u.id})" class="text-blue-400 hover:text-blue-300 mr-2 text-sm">Editar</button><button onclick="deleteUser(${u.id})" class="text-red-400 hover:text-red-300 text-sm">Excluir</button></td></tr>`).join('') : '<tr><td colspan="6" class="px-6 py-8 text-center text-slate-400">Nenhum usuario</td></tr>';
}

async function saveUser(e) {
    e.preventDefault();
    const id = document.getElementById('user-edit-id').value;
    const data = { username: document.getElementById('user-username').value, role: document.getElementById('user-role').value, organization_id: document.getElementById('user-organization').value || null };
    const pw = document.getElementById('user-password').value;
    if (pw) data.password = pw;
    const res = id ? await API.put('user', id, data) : await API.post('users', data);
    if (res.success) { Toast.success(id?'Atualizado':'Criado'); closeModal('modal-new-user'); loadUsers(); } else Toast.error(res.error);
}
window.saveUser = saveUser;

function editUser(id) { document.getElementById('user-form').reset(); document.getElementById('user-edit-id').value = id; openModal('modal-new-user'); }
window.editUser = editUser;

async function deleteUser(id) {
    if (!confirm('Excluir usuario?')) return;
    const res = await API.delete('user', id);
    if (res.success) { Toast.success('Excluido'); loadUsers(); } else Toast.error(res.error);
}
window.deleteUser = deleteUser;

async function loadAuditEvents() {
    const res = await API.get('audit', {limit: 100});
    if (!res.success) return;
    document.getElementById('audit-tbody').innerHTML = res.data.length ? res.data.map(e => `<tr><td class="px-6 py-4 text-sm text-slate-300">${Utils.formatDate(e.created_at)}</td><td class="px-6 py-4 text-sm text-white">${Utils.escapeHtml(e.full_name||e.username||'-')}</td><td class="px-6 py-4"><span class="px-2 py-1 text-xs rounded bg-blue-500/20 text-blue-400">${Utils.escapeHtml(e.action)}</span></td><td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(e.entity)}</td><td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(e.org_acronym||'-')}</td><td class="px-6 py-4 text-sm text-slate-400">${e.details?Utils.escapeHtml(typeof e.details==='string'?e.details:JSON.stringify(e.details)):'-'}</td></tr>`).join('') : '<tr><td colspan="6" class="px-6 py-8 text-center text-slate-400">Nenhum evento</td></tr>';
}

function setupEventListeners() {
    document.getElementById('btn-new-org')?.addEventListener('click', () => openModal('modal-new-org'));
    document.getElementById('new-org-form')?.addEventListener('submit', e => { e.preventDefault(); (async () => {
        const n = document.getElementById('new-org-name').value, a = document.getElementById('new-org-acronym').value.toUpperCase(), d = document.getElementById('new-org-domain').value;
        if (!n || !a) { Toast.error('Nome e sigla obrigatorios'); return; }
        const res = await API.post('organizations', { name: n, acronym: a, domain: d, description: document.getElementById('new-org-description').value, dc_ip: document.getElementById('new-org-dc-ip')?.value, dns_primario: document.getElementById('new-org-dns-primario')?.value, dns_secundario: document.getElementById('new-org-dns-secundario')?.value });
        if (res.success) { Toast.success('Criada'); closeModal('modal-new-org'); loadDashboard(); loadOrganizations(); if (res.data?.id) selectOrganization(res.data.id); } else Toast.error(res.error);
    })(); });
    document.getElementById('new-org-domain')?.addEventListener('input', function() { document.getElementById('new-org-network-config')?.classList.toggle('hidden', !this.value.trim()); });
    document.getElementById('btn-edit-org')?.addEventListener('click', () => openModal('modal-edit-org'));
    document.getElementById('edit-org-form')?.addEventListener('submit', async e => { e.preventDefault(); if (!currentOrgId) return; const res = await API.put('organization', currentOrgId, { name: document.getElementById('edit-org-name').value, domain: document.getElementById('edit-org-domain').value, description: document.getElementById('edit-org-description').value }); if (res.success) { Toast.success('Salvo'); closeModal('modal-edit-org'); loadDashboard(); loadOrganizations(); } else Toast.error(res.error); });
    document.getElementById('btn-save-vars')?.addEventListener('click', saveVariables);
    document.getElementById('var-search')?.addEventListener('input', () => renderVariables(allVariables));
    document.getElementById('user-form')?.addEventListener('submit', saveUser);
    document.getElementById('btn-new-user')?.addEventListener('click', () => { document.getElementById('user-form').reset(); document.getElementById('user-edit-id').value = ''; openModal('modal-new-user'); });
    document.getElementById('btn-logout')?.addEventListener('click', async () => { try { await API.post('logout'); } catch(e) {} location.href = '/login.html'; });
    document.getElementById('btn-generate-bundle')?.addEventListener('click', generateBundle);
    document.getElementById('edit-script-form')?.addEventListener('submit', saveScript);
    document.getElementById('new-script-form')?.addEventListener('submit', createScript);
    document.querySelectorAll('.modal-close').forEach(b => b.addEventListener('click', () => b.closest('.fixed')?.classList.add('hidden')));
    document.querySelectorAll('.modal-backdrop').forEach(b => b.addEventListener('click', e => { if (e.target === b) b.closest('.fixed')?.classList.add('hidden'); }));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.fixed:not(.hidden)').forEach(m => m.classList.add('hidden')); });
}
