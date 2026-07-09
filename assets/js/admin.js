/**
 * SeederLinux Lite — Admin JS
 * Modal fix: modals usar style.display diretamente (nao classe CSS)
 * para evitar que abram automaticamente no load da pagina
 */

let currentUser = null, currentOrgId = null, organizations = [];
let allVariables = [], activeCategory = 'Todas';
let uploadedImages = { wallpapers: [], logos: [] };
let scriptTab = 'Core';

// ── API ──────────────────────────────────────────────────────────────────────

const API = {
    get: async (action, params = {}) => {
        const url = new URL('/api/', location.origin);
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
        return (await fetch(url, { credentials: 'same-origin' })).json();
    },
    post: async (action, data) => {
        const url = new URL('/api/', location.origin);
        url.searchParams.set('action', action);
        return (await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
            credentials: 'same-origin'
        })).json();
    },
    put: async (action, id, data) => {
        const url = new URL('/api/', location.origin);
        url.searchParams.set('action', action);
        if (id) url.searchParams.set('id', id);
        return (await fetch(url, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
            credentials: 'same-origin'
        })).json();
    },
    del: async (action, id) => {
        const url = new URL('/api/', location.origin);
        url.searchParams.set('action', action);
        if (id) url.searchParams.set('id', id);
        return (await fetch(url, { method: 'DELETE', credentials: 'same-origin' })).json();
    },
    upload: async (action, formData) => {
        const url = new URL('/api/', location.origin);
        url.searchParams.set('action', action);
        return (await fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' })).json();
    }
};

// ── Utils ─────────────────────────────────────────────────────────────────────

const esc = str => str?.replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m]) || '';
const fDate = d => d ? new Date(d).toLocaleString('pt-BR') : '-';
const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

const roleLabel = { admin_gap: 'Admin GAP', operador_om: 'Operador OM', auditor: 'Auditor' };
const catLabel = { dominio: 'Dominio', rede: 'Rede', proxy: 'Proxy', inventario: 'Inventario', navegador: 'Navegador', seguranca: 'Seguranca', branding: 'Identidade', generic: 'Geral', custom: 'Custom', arquivos: 'Arquivos', acesso_remoto: 'Acesso Remoto', impressoras: 'Impressoras', certificados: 'Certificados', repositorios: 'Repositorios' };
const catOrder = ['dominio', 'rede', 'proxy', 'repositorios', 'navegador', 'branding', 'arquivos', 'impressoras', 'inventario', 'acesso_remoto', 'certificados', 'seguranca', 'generic', 'custom'];

const varOpts = {
    PROXY_MODE: ['NONE', 'MANUAL', 'PAC'],
    REPOSITORY_MODE: ['PUBLIC', 'MIRROR', 'HYBRID', 'CUSTOM'],
    REMOTE_METHOD: ['ssh', 'xrdp', 'anydesk', 'rustdesk'],
    PROXY_PORTA: ['80', '8080', '3128', '8888'],
    OFFLINE_AUTH_ENABLED: 'boolean',
    INVENTORY_ENABLED: 'boolean',
    CERTIFICATE_AUTO_INSTALL: 'boolean',
    AUTO_UPDATE: 'boolean',
    DEBUG_MODE: 'boolean'
};

// ── Toast ─────────────────────────────────────────────────────────────────────

const Toast = {
    show(msg, type = 'success') {
        const d = document.createElement('div');
        d.className = `toast toast-${type}`;
        d.innerHTML = `<span class="toast-msg">${esc(msg)}</span><button class="toast-x" onclick="this.parentElement.remove()">&times;</button>`;
        document.getElementById('toast-container').appendChild(d);
        setTimeout(() => d.remove(), 5000);
    },
    success: m => Toast.show(m, 'success'),
    error:   m => Toast.show(m, 'error'),
    warning: m => Toast.show(m, 'warning'),
    info:    m => Toast.show(m, 'info')
};

// ── Modal — usa style.display diretamente (sem classes) ───────────────────────
// Isso evita que modals abram automaticamente quando a pagina carrega

function openModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
function closeAllModals() {
    document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
}
window.openModal = openModal;
window.closeModal = closeModal;

// ── Init ──────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', async () => {
    // Garantia extra: esconder todos os modals ao iniciar
    closeAllModals();

    try {
        const res = await API.get('session');
        if (!res.success) { location.href = '/login.html'; return; }
        currentUser = res.data;
    } catch (e) { location.href = '/login.html'; return; }

    applyRolePermissions();
    await loadDashboard();
    await loadOrganizations();
    setupListeners();
});

// ── Roles ─────────────────────────────────────────────────────────────────────

function applyRolePermissions() {
    const r = currentUser?.role;
    const u = currentUser;
    document.getElementById('user-name').textContent = u?.full_name || u?.username || 'Usuario';
    document.getElementById('user-initial').textContent = (u?.full_name || u?.username || 'U').charAt(0).toUpperCase();
    document.getElementById('user-role-badge').textContent = roleLabel[r] || r;

    const adminOnly  = ['nav-scripts-core', 'nav-users', 'btn-new-org'];
    const auditVisible = ['nav-audit', 'btn-new-user'];

    adminOnly.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('hidden', r !== 'admin_gap');
    });

    auditVisible.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('hidden', r !== 'admin_gap' && r !== 'auditor');
    });
}

// ── Views ─────────────────────────────────────────────────────────────────────

function showView(name) {
    closeAllModals();
    ['dashboard', 'om-detail', 'users', 'scripts-core', 'stations', 'audit'].forEach(v => {
        document.getElementById(`view-${v}`)?.classList.toggle('hidden', v !== name);
    });
    const titles = {
        dashboard: ['Dashboard', 'Visao geral do sistema'],
        'om-detail': ['', ''],
        users: ['Usuarios', 'Gerenciamento de usuarios'],
        'scripts-core': ['Scripts Core', 'Scripts do sistema'],
        stations: ['Estacoes', 'Maquinas registradas'],
        audit: ['Auditoria', 'Log de eventos']
    };
    if (titles[name]) {
        document.getElementById('page-title').textContent = titles[name][0];
        document.getElementById('page-subtitle').textContent = titles[name][1];
    }
    // Data loaders
    if (name === 'users') loadUsers();
    if (name === 'scripts-core') loadAllScripts();
    if (name === 'stations') loadStations();
    if (name === 'audit') loadAuditEvents();
}
window.showView = showView;

// ── Dashboard ─────────────────────────────────────────────────────────────────

async function loadDashboard() {
    const res = await API.get('dashboard');
    if (!res.success) return;
    const s = res.data;
    document.getElementById('dash-orgs').textContent = s.organizations;
    document.getElementById('dash-scripts').textContent = s.scripts;
    document.getElementById('dash-vars').textContent = s.variables;
    document.getElementById('dash-bundles').textContent = s.bundles_this_month;
    document.getElementById('dash-online').textContent = s.stations_online;
    document.getElementById('dash-outdated').textContent = s.stations_outdated;

    const tbl = document.getElementById('recent-stations');
    tbl.innerHTML = s.recent_stations?.length
        ? `<table><thead><tr><th>Hostname</th><th>IP</th><th>Check-in</th><th>OM</th><th>Status</th></tr></thead><tbody>
            ${s.recent_stations.map(r => `<tr>
                <td>${esc(r.hostname)}</td><td class="font-mono">${esc(r.ip_address || '-')}</td>
                <td>${fDate(r.last_checkin)}</td><td>${esc(r.org_acronym || '-')}</td>
                <td><span class="badge ${r.status === 'Atualizado' ? 'badge-success' : 'badge-warning'}">${r.status}</span></td>
            </tr>`).join('')}</tbody></table>`
        : '<p class="text-sm text-muted text-center p-3">Nenhuma estacao</p>';

    const ol = document.getElementById('recent-orgs');
    ol.innerHTML = s.recent_orgs?.length
        ? s.recent_orgs.map(o => `<div class="flex items-center justify-between p-3 border-b" style="border-color:var(--border)">
            <div class="flex items-center gap-2"><span style="color:var(--primary);font-weight:600">${esc(o.acronym)}</span><span class="text-sm text-muted">${esc(o.name)}</span></div>
            <button class="btn btn-sm btn-secondary" onclick="selectOrganization(${o.id})">Ver</button>
          </div>`).join('')
        : '<p class="text-sm text-muted text-center p-3">Nenhuma OM</p>';
}

// ── Organizations ─────────────────────────────────────────────────────────────

async function loadOrganizations() {
    const res = await API.get('organizations');
    if (!res.success) return;
    organizations = res.data;

    const list = document.getElementById('om-list');
    list.innerHTML = organizations.map(o => `
        <button class="om-nav-item" data-org-id="${o.id}" onclick="selectOrganization(${o.id})">
            <div class="org-logo">${o.logo_url ? `<img src="${esc(o.logo_url)}" alt="" class="org-logo-img" onerror="this.parentElement.textContent='${esc(o.acronym.substring(0,3))}'">` : esc(o.acronym.substring(0, 3))}</div>
            <div style="min-width:0"><div style="font-size:.8125rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(o.acronym)}</div><div class="text-xs text-muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(o.name)}</div></div>
        </button>
    `).join('') || '<p class="text-xs text-muted p-3 text-center">Nenhuma OM</p>';

    // User org select
    const sel = document.getElementById('user-org-select');
    if (sel) sel.innerHTML = '<option value="">Nenhuma</option>' + organizations.map(o => `<option value="${o.id}">${esc(o.acronym)}</option>`).join('');
}

async function selectOrganization(orgId) {
    currentOrgId = orgId;
    const org = organizations.find(o => o.id === orgId);
    if (!org) return;

    document.querySelectorAll('.om-nav-item').forEach(b => b.classList.toggle('active', parseInt(b.dataset.orgId) === orgId));

    document.getElementById('page-title').textContent = org.acronym;
    document.getElementById('page-subtitle').textContent = org.name;

    showView('om-detail');

    // Header - using org-logo classes for consistent sizing
    const badge = document.getElementById('om-badge');
    badge.innerHTML = org.logo_url ? `<img src="${esc(org.logo_url)}" alt="" class="org-logo-img" onerror="this.parentElement.textContent='${esc(org.acronym.substring(0,3))}'">` : esc(org.acronym.substring(0, 3));
    document.getElementById('om-name').textContent = org.name;
    document.getElementById('om-acronym').textContent = org.acronym;
    document.getElementById('om-domain').textContent = org.domain || 'Sem dominio';

    // Edit form
    document.getElementById('edit-org-name').value = org.name;
    document.getElementById('edit-org-acronym').value = org.acronym;
    document.getElementById('edit-org-domain').value = org.domain || '';
    document.getElementById('edit-org-desc').value = org.description || '';

    switchTab('variables');
    await loadVariables(orgId);
}
window.selectOrganization = selectOrganization;

// ── Variables ─────────────────────────────────────────────────────────────────

async function loadVariables(orgId) {
    if (!orgId) orgId = currentOrgId;
    const res = await API.get('variables', { id: orgId });
    if (!res.success) { Toast.error(res.error || 'Erro ao carregar variaveis'); return; }
    allVariables = res.data.variables || [];

    // Load image galleries
    try {
        const [wr, lr] = await Promise.all([API.get('wallpapers', { org_id: orgId }), API.get('logos', { org_id: orgId })]);
        uploadedImages.wallpapers = wr.success ? wr.data.images : [];
        uploadedImages.logos = lr.success ? lr.data.images : [];
    } catch (_) {}

    activeCategory = 'Todas';
    renderVariables();
}

function renderVariables() {
    const container = document.getElementById('vars-container');
    if (!container) return;

    const search = (document.getElementById('var-search')?.value || '').toLowerCase();
    let vars = allVariables;
    if (search) vars = vars.filter(v => v.name.toLowerCase().includes(search));

    const cats = [...new Set(vars.map(v => v.category || 'generic'))].sort((a, b) => {
        const ai = catOrder.indexOf(a), bi = catOrder.indexOf(b);
        return (ai === -1 ? 999 : ai) - (bi === -1 ? 999 : bi);
    });

    // Category tab bar
    let tabHtml = `<div class="cat-tabs"><button class="cat-tab ${activeCategory === 'Todas' ? 'active' : ''}" onclick="filterByCategory('Todas')">Todas</button>`;
    cats.forEach(c => { tabHtml += `<button class="cat-tab ${activeCategory === c ? 'active' : ''}" onclick="filterByCategory('${c}')">${catLabel[c] || c}</button>`; });
    tabHtml += '</div>';

    const filtered = activeCategory === 'Todas' ? vars : vars.filter(v => (v.category || 'generic') === activeCategory);

    let bodyHtml = '<div class="var-grid">';
    if (activeCategory === 'Todas') {
        cats.forEach(c => {
            const cv = filtered.filter(v => (v.category || 'generic') === c);
            if (!cv.length) return;
            bodyHtml += `<div class="var-cat-heading">${catLabel[c] || c}</div>`;
            cv.forEach(v => bodyHtml += varRowHtml(v));
        });
    } else {
        filtered.forEach(v => bodyHtml += varRowHtml(v));
    }
    bodyHtml += '</div>';

    container.innerHTML = tabHtml + bodyHtml;
}

function varRowHtml(v) {
    const val = v.current_value || '';
    const input = varInputHtml(v, val);
    const isWall = v.name === 'WALLPAPER_URL';
    const isLogo = v.name === 'LOGO_URL';
    const isImg = isWall || isLogo;
    const imgType = isWall ? 'wallpaper' : 'logo';
    const imgs = uploadedImages[imgType + 's'] || [];

    let gallery = '';
    if (isImg) {
        gallery = `
        <div class="mt-2">
            <label class="btn btn-sm btn-secondary" style="cursor:pointer">
                <svg style="width:14px;height:14px" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Upload
                <input type="file" class="hidden" accept="image/jpeg,image/png,image/gif,image/webp" onchange="handleImageUpload('${imgType}',${v.id},this)">
            </label>
        </div>
        <div class="gallery mt-2" id="gallery-${imgType}">
            ${imgs.map(i => `<div class="gallery-item ${i.url === val ? 'selected' : ''}" onclick="selectGalleryImage('${esc(i.url)}',${v.id},this)"><img src="${esc(i.thumbnail || i.url)}" alt=""></div>`).join('') || '<span class="gallery-empty">Nenhuma imagem</span>'}
        </div>`;
    }

    return `<div class="var-row">
        <div class="var-name">${esc(v.name)}${v.is_required ? '<span style="color:var(--danger)">*</span>' : ''}</div>
        ${input}
        ${v.description ? `<div class="var-desc">${esc(v.description)}</div>` : ''}
        ${gallery}
    </div>`;
}

function varInputHtml(v, val) {
    const opts = varOpts[v.name];
    const vid = v.id;

    if (opts === 'boolean' || v.type === 'boolean') {
        const checked = val === 'true' || val === '1';
        return `<label class="toggle-wrap"><label class="toggle"><input type="checkbox" data-var-id="${vid}" ${checked ? 'checked' : ''}><span class="toggle-slider"></span></label><span class="toggle-label">${checked ? 'Ativo' : 'Inativo'}</span></label>`;
    }
    if (Array.isArray(opts)) {
        return `<select data-var-id="${vid}" class="var-select">${opts.map(o => `<option value="${esc(o)}" ${val === o ? 'selected' : ''}>${o}</option>`).join('')}</select>`;
    }
    if (v.type === 'array') return `<textarea data-var-id="${vid}" class="var-textarea" placeholder="Separe multiplos valores por virgula" rows="2">${esc(val)}</textarea>`;
    if (v.type === 'url' || v.name.includes('URL')) return `<input type="url" data-var-id="${vid}" class="var-input" value="${esc(val)}">`;
    if (v.type === 'ip' || v.name.includes('IP') || v.name.includes('DNS')) return `<input type="text" data-var-id="${vid}" class="var-input mono" value="${esc(val)}">`;
    if (v.type === 'password') return `<input type="password" data-var-id="${vid}" class="var-input" value="${esc(val)}">`;
    return `<input type="text" data-var-id="${vid}" class="var-input" value="${esc(val)}">`;
}

function filterByCategory(c) { activeCategory = c; renderVariables(); }
window.filterByCategory = filterByCategory;

async function saveVariables() {
    if (!currentOrgId) return;
    const updates = {};
    document.querySelectorAll('[data-var-id]').forEach(el => {
        updates[el.dataset.varId] = el.type === 'checkbox' ? (el.checked ? 'true' : 'false') : el.value;
    });
    const res = await API.post('variables-update', { organization_id: currentOrgId, variables: updates });
    res.success ? Toast.success('Variaveis salvas') : Toast.error(res.error);
}
window.saveVariables = saveVariables;

function selectGalleryImage(url, varId, el) {
    document.querySelector(`[data-var-id="${varId}"]`).value = url;
    el.closest('.gallery').querySelectorAll('.gallery-item').forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
}
window.selectGalleryImage = selectGalleryImage;

async function handleImageUpload(type, varId, inputEl) {
    const file = inputEl.files[0];
    if (!file || !currentOrgId) return;
    const fd = new FormData();
    fd.append(type, file);
    fd.append('organization_id', currentOrgId);
    Toast.info('Enviando...');
    const res = await API.upload(`upload-${type}`, fd);
    if (!res.success) { Toast.error(res.error); return; }
    Toast.success('Enviado');
    document.querySelector(`[data-var-id="${varId}"]`).value = res.data.url;
    const gallery = document.getElementById(`gallery-${type}`);
    if (gallery) {
        gallery.querySelectorAll('.gallery-item').forEach(i => i.classList.remove('selected'));
        const item = document.createElement('div');
        item.className = 'gallery-item selected';
        item.innerHTML = `<img src="${esc(res.data.thumbnail || res.data.url)}" alt="">`;
        item.onclick = () => selectGalleryImage(res.data.url, varId, item);
        gallery.prepend(item);
    }
}
window.handleImageUpload = handleImageUpload;

// ── Tabs ──────────────────────────────────────────────────────────────────────

function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.dataset.pane === name));
    if (name === 'scripts') loadOrgScripts(currentOrgId);
}
window.switchTab = switchTab;

// ── Scripts ───────────────────────────────────────────────────────────────────

async function loadAllScripts() {
    const res = await API.get('scripts');
    if (!res.success) return;
    const core = res.data.filter(s => s.is_core);
    const custom = res.data.filter(s => !s.is_core);
    const el = document.getElementById('all-scripts-list');
    if (!el) return;
    el.innerHTML = `
        <div class="section-header"><h3>Scripts Core</h3><button class="btn btn-sm btn-secondary" onclick="openModal('modal-upload-script')">Upload</button></div>
        <div class="mb-4">${core.map(s => scriptRow(s, 'core')).join('') || '<p class="text-sm text-muted">Nenhum</p>'}</div>
        <div class="section-header"><h3>Scripts Custom</h3><button class="btn btn-sm btn-primary" onclick="openNewScriptModal()">+ Novo</button></div>
        ${custom.map(s => scriptRow(s, 'custom')).join('') || '<p class="text-sm text-muted">Nenhum</p>'}`;
}

async function loadOrgScripts(orgId) {
    if (!orgId) return;
    const res = await API.get('scripts', { org_id: orgId });
    if (!res.success) return;
    const scripts = res.data;
    const tab = scriptTab;
    const list = scripts.filter(s => tab === 'Core' ? s.is_core : !s.is_core);
    const el = document.getElementById('org-scripts-list');
    if (!el) return;
    el.innerHTML = list.map(s => `<div class="flex items-center justify-between p-3 rounded-lg border mb-2" style="background:rgba(0,0,0,.2)">
        <div class="flex items-center gap-2"><input type="checkbox" class="script-sel" value="${s.id}" checked><span class="text-sm">${esc(s.name)}</span><span class="badge badge-secondary">${esc(s.filename)}</span></div>
        <button class="btn btn-sm btn-secondary" onclick="viewScript(${s.id})">Ver</button>
    </div>`).join('') || '<p class="text-sm text-muted">Nenhum script</p>';
}

function scriptRow(s, type) {
    const actions = type === 'core'
        ? `<button class="btn btn-sm btn-secondary" onclick="viewScript(${s.id})">Ver</button>`
        : `<button class="btn btn-sm btn-secondary" onclick="viewScript(${s.id})">Ver</button>
           <button class="btn btn-sm btn-primary" onclick="editScript(${s.id})">Editar</button>
           <button class="btn btn-sm btn-danger" onclick="deleteScript(${s.id})">Excluir</button>`;
    return `<div class="flex items-center justify-between p-3 rounded-lg border mb-2" style="background:rgba(0,0,0,.2)">
        <div class="flex items-center gap-2"><span>${esc(s.name)}</span><span class="badge badge-secondary text-xs">${esc(s.filename)}</span>${type === 'core' ? '<span class="badge badge-core">Core</span>' : ''}</div>
        <div class="flex gap-2">${actions}</div>
    </div>`;
}

function switchScriptTab(t) {
    scriptTab = t;
    document.querySelectorAll('.script-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.st === t));
    loadOrgScripts(currentOrgId);
}
window.switchScriptTab = switchScriptTab;

async function viewScript(id) {
    const res = await API.get('script', { id });
    if (!res.success) { Toast.error(res.error); return; }
    const s = res.data;
    document.getElementById('sv-name').textContent = s.name;
    document.getElementById('sv-filename').textContent = s.filename;
    document.getElementById('sv-content').value = s.content || '';
    document.getElementById('sv-is-core').textContent = s.is_core ? 'Sim' : 'Nao';
    const editBtn = document.getElementById('sv-edit-btn');
    const delBtn  = document.getElementById('sv-del-btn');
    editBtn.classList.toggle('hidden', !!s.is_core);
    delBtn.classList.toggle('hidden', !!s.is_core);
    if (!s.is_core) {
        editBtn.onclick = () => { closeModal('modal-view-script'); editScript(id); };
        delBtn.onclick  = () => deleteScript(id);
    }
    openModal('modal-view-script');
}
window.viewScript = viewScript;

async function editScript(id) {
    const res = await API.get('script', { id });
    if (!res.success) { Toast.error(res.error); return; }
    document.getElementById('edit-script-id').value = res.data.id;
    document.getElementById('edit-script-name').value = res.data.name;
    document.getElementById('edit-script-filename').value = res.data.filename;
    document.getElementById('edit-script-desc').value = res.data.description || '';
    document.getElementById('edit-script-content').value = res.data.content || '';
    openModal('modal-edit-script');
}
window.editScript = editScript;

async function deleteScript(id) {
    if (!confirm('Tem certeza que deseja excluir este script?')) return;
    const res = await API.del('script', id);
    if (res.success) { Toast.success('Script excluido'); closeAllModals(); loadAllScripts(); }
    else Toast.error(res.error);
}
window.deleteScript = deleteScript;

function openNewScriptModal() {
    document.getElementById('new-script-form').reset();
    openModal('modal-new-script');
}
window.openNewScriptModal = openNewScriptModal;

// ── Bundle ────────────────────────────────────────────────────────────────────

async function generateBundle() {
    if (!currentOrgId) { Toast.error('Selecione uma organizacao'); return; }
    const selected = [...document.querySelectorAll('.script-sel:checked')].map(el => parseInt(el.value));
    Toast.info('Gerando bundle...');
    const res = await API.post('generate-bundle', { organization_id: currentOrgId, scripts: selected });
    if (res.success && res.data.download_url) { Toast.success('Bundle gerado'); location.href = res.data.download_url; }
    else Toast.error(res.error || 'Erro ao gerar');
}
window.generateBundle = generateBundle;

// ── Users ─────────────────────────────────────────────────────────────────────

async function loadUsers() {
    const res = await API.get('users');
    if (!res.success) return;
    const tb = document.getElementById('users-tbody');
    if (!tb) return;
    tb.innerHTML = res.data.length ? res.data.map(u => `<tr>
        <td>${esc(u.username)}</td>
        <td>${esc(u.full_name || '-')}</td>
        <td>${esc(u.email || '-')}</td>
        <td><span class="badge badge-info">${roleLabel[u.role] || u.role}</span></td>
        <td>${esc(u.org_acronym || '-')}</td>
        <td><span class="badge ${u.is_active ? 'badge-success' : 'badge-secondary'}">${u.is_active ? 'Ativo' : 'Inativo'}</span></td>
        <td class="text-right">
            <button class="btn btn-sm btn-secondary" onclick="editUser(${u.id})">Editar</button>
            <button class="btn btn-sm btn-secondary" onclick="toggleUserStatus(${u.id})">${u.is_active ? 'Desativar' : 'Ativar'}</button>
            <button class="btn btn-sm btn-danger" onclick="deleteUser(${u.id})">Excluir</button>
        </td>
    </tr>`).join('') : '<tr><td colspan="7" class="text-center text-muted p-3">Nenhum usuario</td></tr>';
}

async function saveUser(e) {
    e.preventDefault();
    const pw = document.getElementById('u-password').value;
    const cpw = document.getElementById('u-confirm-pw').value;
    if (pw && pw !== cpw) { Toast.error('Senhas nao conferem'); return; }
    const id = document.getElementById('u-edit-id').value;
    const data = { username: document.getElementById('u-username').value, full_name: document.getElementById('u-fullname').value, email: document.getElementById('u-email').value, role: document.getElementById('u-role').value, organization_id: document.getElementById('u-org-select').value || null, password: pw, confirm_password: cpw };
    const res = id ? await API.put('user', id, data) : await API.post('users', data);
    if (res.success) { Toast.success(id ? 'Usuario atualizado' : 'Usuario criado'); closeModal('modal-user'); loadUsers(); }
    else Toast.error(res.error);
}
window.saveUser = saveUser;

function editUser(id) {
    API.get('users').then(res => {
        if (!res.success) return;
        const u = res.data.find(u => u.id === id);
        if (!u) return;
        document.getElementById('u-edit-id').value = u.id;
        document.getElementById('u-username').value = u.username;
        document.getElementById('u-fullname').value = u.full_name || '';
        document.getElementById('u-email').value = u.email || '';
        document.getElementById('u-role').value = u.role;
        document.getElementById('u-org-select').value = u.organization_id || '';
        document.getElementById('u-password').value = '';
        document.getElementById('u-confirm-pw').value = '';
        document.getElementById('modal-user-title').textContent = 'Editar Usuario';
        openModal('modal-user');
    });
}
window.editUser = editUser;

async function deleteUser(id) {
    if (!confirm('Tem certeza que deseja excluir este usuario?')) return;
    const res = await API.del('user', id);
    res.success ? (Toast.success('Excluido'), loadUsers()) : Toast.error(res.error);
}
window.deleteUser = deleteUser;

async function toggleUserStatus(id) {
    const res = await API.post('user', { id });
    res.success ? (Toast.success(res.message || 'Status alterado'), loadUsers()) : Toast.error(res.error);
}
window.toggleUserStatus = toggleUserStatus;

// ── Organizations CRUD ────────────────────────────────────────────────────────

async function createOrganization(e) {
    e.preventDefault();
    const res = await API.post('organizations', {
        name: document.getElementById('new-org-name').value,
        acronym: document.getElementById('new-org-acronym').value.toUpperCase(),
        domain: document.getElementById('new-org-domain').value,
        description: document.getElementById('new-org-desc').value,
        dc_ip: document.getElementById('new-org-dc').value,
        dns_primario: document.getElementById('new-org-dns1').value,
        dns_secundario: document.getElementById('new-org-dns2').value
    });
    if (res.success) {
        Toast.success('Organizacao criada');
        closeModal('modal-new-org');
        document.getElementById('new-org-form').reset();
        await loadDashboard();
        await loadOrganizations();
        if (res.data?.id) selectOrganization(res.data.id);
    } else Toast.error(res.error);
}
window.createOrganization = createOrganization;

async function updateOrganization(e) {
    e.preventDefault();
    if (!currentOrgId) return;
    const res = await API.put('organization', currentOrgId, { name: document.getElementById('edit-org-name').value, domain: document.getElementById('edit-org-domain').value, description: document.getElementById('edit-org-desc').value });
    if (res.success) { Toast.success('Atualizado'); closeModal('modal-edit-org'); loadDashboard(); loadOrganizations(); }
    else Toast.error(res.error);
}
window.updateOrganization = updateOrganization;

async function deleteOrganization(id) {
    if (!confirm('Tem certeza que deseja excluir esta organizacao?')) return;
    const res = await API.del('organization', id);
    if (res.success) { Toast.success('Excluido'); showView('dashboard'); loadDashboard(); loadOrganizations(); }
    else Toast.error(res.error);
}
window.deleteOrganization = deleteOrganization;

// ── Stations ──────────────────────────────────────────────────────────────────

async function loadStations() {
    const res = await API.get('stations', currentOrgId ? { org_id: currentOrgId } : {});
    if (!res.success) return;
    const el = document.getElementById('stations-tbody');
    if (!el) return;
    const connLabel = { online: 'Online', delayed: 'Atrasada', never: 'Nunca', unknown: '-' };
    const connBadge = { online: 'badge-success', delayed: 'badge-warning', never: 'badge-secondary', unknown: 'badge-secondary' };
    el.innerHTML = res.data.length ? res.data.map(s => `<tr>
        <td>${esc(s.hostname)}</td><td class="font-mono text-xs">${esc(s.ip_address || '-')}</td>
        <td class="font-mono text-xs">${esc(s.mac_address || '-')}</td><td>${esc(s.os_name || '-')}</td>
        <td>${fDate(s.last_checkin)}</td>
        <td><span class="badge ${connBadge[s.conn_status] || 'badge-secondary'}">${connLabel[s.conn_status] || '-'}</span>
            <span class="badge ${s.config_status === 'updated' ? 'badge-success' : 'badge-warning'} ml-1">${s.config_status === 'updated' ? 'OK' : 'Desatualizada'}</span></td>
        <td>${esc(s.org_acronym || '-')}</td>
    </tr>`).join('') : '<tr><td colspan="7" class="text-center text-muted p-3">Nenhuma estacao</td></tr>';
}

// ── Audit ─────────────────────────────────────────────────────────────────────

async function loadAuditEvents() {
    const params = {};
    const sd = document.getElementById('audit-start')?.value;
    const ed = document.getElementById('audit-end')?.value;
    if (sd) params.start_date = sd;
    if (ed) params.end_date = ed;
    const res = await API.get('audit', params);
    if (!res.success) return;
    const el = document.getElementById('audit-tbody');
    if (!el) return;
    el.innerHTML = res.data.length ? res.data.map(e => `<tr>
        <td>${fDate(e.created_at)}</td><td>${esc(e.full_name || e.username || '-')}</td>
        <td><span class="badge badge-info">${esc(e.action)}</span></td>
        <td>${esc(e.entity)}</td><td>${esc(e.org_acronym || '-')}</td>
        <td class="text-xs text-muted">${esc(e.details || '-')}</td>
    </tr>`).join('') : '<tr><td colspan="6" class="text-center text-muted p-3">Nenhum evento</td></tr>';
}
window.loadAuditEvents = loadAuditEvents;

// ── Event Listeners ───────────────────────────────────────────────────────────

function setupListeners() {
    // Logout
    document.getElementById('btn-logout')?.addEventListener('click', async () => { await API.post('logout'); location.href = '/login.html'; });

    // Nav buttons
    document.getElementById('btn-new-org')?.addEventListener('click', () => {
        document.getElementById('new-org-form')?.reset();
        openModal('modal-new-org');
    });
    document.getElementById('btn-new-user')?.addEventListener('click', () => {
        document.getElementById('user-form')?.reset();
        document.getElementById('u-edit-id').value = '';
        document.getElementById('modal-user-title').textContent = 'Novo Usuario';
        openModal('modal-user');
    });
    document.getElementById('btn-edit-org')?.addEventListener('click', () => openModal('modal-edit-org'));
    document.getElementById('btn-save-vars')?.addEventListener('click', saveVariables);
    document.getElementById('btn-generate-bundle')?.addEventListener('click', generateBundle);

    // Variable search
    document.getElementById('var-search')?.addEventListener('input', debounce(renderVariables, 250));

    // Forms
    document.getElementById('new-org-form')?.addEventListener('submit', createOrganization);
    document.getElementById('edit-org-form')?.addEventListener('submit', updateOrganization);
    document.getElementById('user-form')?.addEventListener('submit', saveUser);

    document.getElementById('edit-script-form')?.addEventListener('submit', async e => {
        e.preventDefault();
        const id = document.getElementById('edit-script-id').value;
        const res = await API.put('script', id, { name: document.getElementById('edit-script-name').value, description: document.getElementById('edit-script-desc').value, content: document.getElementById('edit-script-content').value });
        if (res.success) { Toast.success('Script atualizado'); closeModal('modal-edit-script'); loadAllScripts(); }
        else Toast.error(res.error);
    });

    document.getElementById('new-script-form')?.addEventListener('submit', async e => {
        e.preventDefault();
        const res = await API.post('script', { name: document.getElementById('new-script-name').value, filename: document.getElementById('new-script-filename').value, description: document.getElementById('new-script-desc').value, content: document.getElementById('new-script-content').value });
        if (res.success) { Toast.success('Script criado'); closeModal('modal-new-script'); document.getElementById('new-script-form').reset(); loadAllScripts(); }
        else Toast.error(res.error);
    });

    // Escape key closes modals
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAllModals(); });
}
