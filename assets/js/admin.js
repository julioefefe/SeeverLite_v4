/**
 * SeederLinux Lite - Admin JavaScript
 * Todos os handlers e funcoes da interface administrativa
 */

let currentUser = null;
let currentOrgId = null;
let organizations = [];
let allVariables = [];
let activeCategory = 'Todas';
let uploadedImages = { wallpapers: [], logos: [] };
let scriptTab = 'Core';

// API Helper
const API = {
    get: async (action, params = {}) => {
        const url = new URL('/api/', location.origin);
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
        const res = await fetch(url, { credentials: 'same-origin' });
        return res.json();
    },
    post: async (action, data) => {
        const url = new URL('/api/', location.origin);
        url.searchParams.set('action', action);
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
            credentials: 'same-origin'
        });
        return res.json();
    },
    put: async (action, id, data) => {
        const url = new URL('/api/', location.origin);
        url.searchParams.set('action', action);
        url.searchParams.set('id', id);
        const res = await fetch(url, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
            credentials: 'same-origin'
        });
        return res.json();
    },
    delete: async (action, id) => {
        const url = new URL('/api/', location.origin);
        url.searchParams.set('action', action);
        url.searchParams.set('id', id);
        const res = await fetch(url, { method: 'DELETE', credentials: 'same-origin' });
        return res.json();
    },
    postMultipart: async (action, formData) => {
        const url = new URL('/api/', location.origin);
        url.searchParams.set('action', action);
        const res = await fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' });
        return res.json();
    }
};

// Utils
const Utils = {
    escapeHtml: (str) => str?.replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m]) || '',
    formatDate: (d) => d ? new Date(d).toLocaleString('pt-BR') : '-',
    debounce: (fn, ms) => { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); }; }
};

// Toast notifications
const Toast = {
    show: (msg, type = 'success') => {
        const container = document.getElementById('toast-container');
        const div = document.createElement('div');
        div.className = `toast toast-${type}`;
        div.innerHTML = `<span>${Utils.escapeHtml(msg)}</span><button class="toast-close" onclick="this.parentElement.remove()">&times;</button>`;
        container.appendChild(div);
        setTimeout(() => div.remove(), 5000);
    },
    success: (msg) => Toast.show(msg, 'success'),
    error: (msg) => Toast.show(msg, 'error'),
    warning: (msg) => Toast.show(msg, 'warning'),
    info: (msg) => Toast.show(msg, 'info')
};

// Category labels
const categoryLabels = {
    'dominio': 'Dominio',
    'rede': 'Rede',
    'proxy': 'Proxy',
    'inventario': 'Inventario',
    'navegador': 'Navegador',
    'seguranca': 'Seguranca',
    'branding': 'Identidade',
    'generic': 'Geral',
    'custom': 'Custom',
    'arquivos': 'Arquivos',
    'acesso_remoto': 'Acesso Remoto',
    'impressoras': 'Impressoras',
    'certificados': 'Certificados',
    'repositorios': 'Repositorios'
};

const categoryOrder = ['dominio', 'rede', 'proxy', 'repositorios', 'navegador', 'branding', 'arquivos', 'impressoras', 'inventario', 'acesso_remoto', 'certificados', 'seguranca', 'generic', 'custom'];

// Variable options
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

// Role labels
const roleLabels = {
    'admin_gap': 'Admin GAP',
    'operador_om': 'Operador OM',
    'auditor': 'Auditor'
};

// ============ INITIALIZATION ============

document.addEventListener('DOMContentLoaded', async () => {
    try {
        const session = await API.get('session');
        if (!session.success) {
            location.href = '/login.html';
            return;
        }
        currentUser = session.data;
        applyRolePermissions();
        await loadDashboard();
        await loadOrganizations();
        setupEventListeners();
    } catch (e) {
        console.error('Init error:', e);
        location.href = '/login.html';
    }
});

function applyRolePermissions() {
    const role = currentUser?.role;
    document.getElementById('user-name').textContent = currentUser?.username || 'Usuario';
    document.getElementById('user-initial').textContent = (currentUser?.username || 'U').charAt(0).toUpperCase();
    document.getElementById('user-role').textContent = roleLabels[role] || role;

    // Admin menu items
    const adminOnly = ['nav-scripts-core', 'nav-users', 'btn-new-org', 'btn-new-user'];
    const adminOrAuditor = ['nav-audit'];

    adminOnly.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('hidden', role !== 'admin_gap');
    });

    adminOrAuditor.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('hidden', role !== 'admin_gap' && role !== 'auditor');
    });
}

// ============ VIEW MANAGEMENT ============

function showView(viewName) {
    // Hide all views
    ['view-dashboard', 'view-om-detail', 'view-organizations', 'view-scripts-core', 'view-users', 'view-stations', 'view-audit'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.add('hidden');
    });

    // Update nav
    document.querySelectorAll('.nav-item').forEach(btn => btn.classList.remove('active', 'bg-slate-700', 'text-white'));

    // Show selected view
    const view = document.getElementById(`view-${viewName}`);
    if (view) view.classList.remove('hidden');

    // Update title
    const titles = {
        dashboard: ['Dashboard', 'Visao geral do sistema'],
        organizations: ['Organizacoes', 'Gerenciamento de OMs'],
        'scripts-core': ['Scripts Core', 'Scripts do sistema'],
        users: ['Usuarios', 'Gerenciamento de usuarios'],
        stations: ['Estacoes', 'Maquinas registradas'],
        audit: ['Auditoria', 'Log de eventos']
    };

    if (titles[viewName]) {
        document.getElementById('page-title').textContent = titles[viewName][0];
        document.getElementById('page-subtitle').textContent = titles[viewName][1];
    }

    // Load data for view
    switch (viewName) {
        case 'dashboard': loadDashboard(); break;
        case 'users': loadUsers(); break;
        case 'scripts-core': loadAllScripts(); break;
        case 'stations': loadStations(); break;
        case 'audit': loadAuditEvents(); break;
    }
}
window.showView = showView;

// ============ DASHBOARD ============

async function loadDashboard() {
    const res = await API.get('dashboard');
    if (!res.success) return;

    const stats = res.data;
    document.getElementById('dash-orgs').textContent = stats.organizations || 0;
    document.getElementById('dash-scripts').textContent = stats.scripts || 0;
    document.getElementById('dash-vars').textContent = stats.variables || 0;
    document.getElementById('dash-bundles').textContent = stats.bundles_this_month || 0;
    document.getElementById('dash-stations-online').textContent = stats.stations_online || 0;
    document.getElementById('dash-stations-outdated').textContent = stats.stations_outdated || 0;

    // Recent stations table
    const stationsEl = document.getElementById('recent-stations');
    if (stationsEl) {
        if (stats.recent_stations?.length) {
            stationsEl.innerHTML = `
                <table class="w-full text-sm">
                    <thead><tr class="bg-slate-900">
                        <th class="px-3 py-2 text-left text-slate-400">Hostname</th>
                        <th class="px-3 py-2 text-left text-slate-400">IP</th>
                        <th class="px-3 py-2 text-left text-slate-400">Check-in</th>
                        <th class="px-3 py-2 text-left text-slate-400">OM</th>
                        <th class="px-3 py-2 text-left text-slate-400">Status</th>
                    </tr></thead>
                    <tbody>
                        ${stats.recent_stations.map(s => `
                            <tr class="border-b border-slate-700">
                                <td class="px-3 py-2">${Utils.escapeHtml(s.hostname)}</td>
                                <td class="px-3 py-2">${Utils.escapeHtml(s.ip_address || '-')}</td>
                                <td class="px-3 py-2">${Utils.formatDate(s.last_checkin)}</td>
                                <td class="px-3 py-2">${Utils.escapeHtml(s.org_acronym || '-')}</td>
                                <td class="px-3 py-2"><span class="badge ${s.status === 'Atualizado' ? 'badge-success' : 'badge-warning'}">${s.status}</span></td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>`;
        } else {
            stationsEl.innerHTML = '<p class="text-slate-400 text-center py-4">Nenhuma estacao registrada</p>';
        }
    }

    // Recent orgs
    const orgsEl = document.getElementById('recent-orgs');
    if (orgsEl && stats.recent_orgs?.length) {
        orgsEl.innerHTML = stats.recent_orgs.map(o => `
            <div class="p-3 bg-slate-800 rounded-lg border border-slate-700 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-blue-400">${Utils.escapeHtml(o.acronym)}</span>
                    <span class="text-slate-300">${Utils.escapeHtml(o.name)}</span>
                </div>
                <button onclick="selectOrganization(${o.id})" class="text-sm text-blue-400 hover:text-blue-300">Ver</button>
            </div>
        `).join('');
    }
}

// ============ ORGANIZATIONS ============

async function loadOrganizations() {
    const res = await API.get('organizations');
    if (!res.success) return;

    organizations = res.data;
    const el = document.getElementById('om-list');
    if (!el) return;

    if (!organizations.length) {
        el.innerHTML = '<p class="text-slate-500 text-sm text-center py-4">Nenhuma organizacao</p>';
        return;
    }

    el.innerHTML = organizations.map(o => `
        <button class="nav-item w-full flex items-center gap-3 px-3 py-2 rounded-lg text-slate-300 hover:bg-slate-700 text-left"
                data-org-id="${o.id}" onclick="selectOrganization(${o.id})">
            <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-emerald-500 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                ${o.logo_url
                    ? `<img src="${Utils.escapeHtml(o.logo_url)}" class="w-full h-full object-cover rounded" onerror="this.parentElement.textContent='${o.acronym.substring(0, 3)}'">`
                    : o.acronym.substring(0, 3)}
            </div>
            <div class="min-w-0">
                <span class="block font-medium truncate">${Utils.escapeHtml(o.acronym)}</span>
                <span class="block text-xs text-slate-500 truncate">${Utils.escapeHtml(o.name)}</span>
            </div>
        </button>
    `).join('');

    // Update user organization select
    const select = document.getElementById('user-organization');
    if (select) {
        select.innerHTML = '<option value="">Nenhuma</option>' + organizations.map(o =>
            `<option value="${o.id}">${Utils.escapeHtml(o.acronym)}</option>`
        ).join('');
    }
}

async function selectOrganization(orgId) {
    currentOrgId = orgId;
    const org = organizations.find(o => o.id === orgId);
    if (!org) return;

    // Update nav
    document.querySelectorAll('.nav-item[data-org-id]').forEach(btn => {
        btn.classList.toggle('active', parseInt(btn.dataset.orgId) === orgId);
        btn.classList.toggle('bg-slate-700', parseInt(btn.dataset.orgId) === orgId);
    });

    // Update header
    document.getElementById('page-title').textContent = org.acronym;
    document.getElementById('page-subtitle').textContent = org.name;

    // Show detail view
    document.getElementById('view-dashboard')?.classList.add('hidden');
    document.getElementById('view-om-detail')?.classList.remove('hidden');

    // Update org display
    document.getElementById('om-display-name').textContent = org.name;
    document.getElementById('om-display-acronym').textContent = org.acronym;
    document.getElementById('om-display-domain').textContent = org.domain || 'Sem dominio';

    // Set edit modal values
    document.getElementById('edit-org-name').value = org.name;
    document.getElementById('edit-org-acronym').value = org.acronym;
    document.getElementById('edit-org-domain').value = org.domain || '';
    document.getElementById('edit-org-description').value = org.description || '';

    // Badge
    const badge = document.getElementById('om-badge');
    badge.innerHTML = org.logo_url
        ? `<img src="${Utils.escapeHtml(org.logo_url)}" class="w-full h-full object-cover rounded-xl" onerror="this.parentElement.textContent='${org.acronym.substring(0, 3)}'">`
        : org.acronym.substring(0, 3);

    // Load tabs
    switchTab('variables');
    await loadVariables(orgId);
}
window.selectOrganization = selectOrganization;

// ============ VARIABLES ============

async function loadVariables(orgId) {
    if (!orgId) orgId = currentOrgId;

    const res = await API.get('variables', { id: orgId });
    if (!res.success) {
        Toast.error(res.error || 'Erro ao carregar variaveis');
        return;
    }

    allVariables = res.data.variables || [];
    activeCategory = 'Todas';
    renderVariables(allVariables);

    // Load image galleries
    try {
        const [wRes, lRes] = await Promise.all([
            API.get('wallpapers', { org_id: orgId }),
            API.get('logos', { org_id: orgId })
        ]);
        uploadedImages.wallpapers = wRes.success ? wRes.data.images : [];
        uploadedImages.logos = lRes.success ? lRes.data.images : [];
    } catch (e) { }
}

function renderVariables(vars) {
    const el = document.getElementById('vars-list');
    if (!el) return;

    if (!vars.length) {
        el.innerHTML = '<p class="text-slate-400 text-center py-8">Nenhuma variavel</p>';
        return;
    }

    const cats = [...new Set(vars.map(v => v.category || 'generic'))].sort((a, b) => {
        const ai = categoryOrder.indexOf(a);
        const bi = categoryOrder.indexOf(b);
        return (ai === -1 ? 999 : ai) - (bi === -1 ? 999 : bi);
    });

    // Category tabs
    let html = '<div class="category-tabs">';
    html += `<button class="cat-tab ${activeCategory === 'Todas' ? 'active' : ''}" onclick="filterByCategory('Todas')">Todas</button>`;
    cats.forEach(c => {
        html += `<button class="cat-tab ${activeCategory === c ? 'active' : ''}" onclick="filterByCategory('${Utils.escapeHtml(c)}')">${categoryLabels[c] || c}</button>`;
    });
    html += '</div>';

    // Variables grid
    let filtered = activeCategory === 'Todas' ? vars : vars.filter(v => (v.category || 'generic') === activeCategory);
    const search = document.getElementById('var-search')?.value?.toLowerCase() || '';
    if (search) filtered = filtered.filter(v => v.name.toLowerCase().includes(search));

    html += '<div class="var-grid">';
    if (activeCategory === 'Todas') {
        cats.forEach(c => {
            const catVars = filtered.filter(v => (v.category || 'generic') === c);
            if (!catVars.length) return;
            html += `<h4 class="col-span-2 mt-4 first:mt-0 text-sm font-semibold text-slate-400 uppercase">${categoryLabels[c] || c}</h4>`;
            catVars.forEach(v => html += renderVarRow(v));
        });
    } else {
        filtered.forEach(v => html += renderVarRow(v));
    }
    html += '</div>';

    el.innerHTML = html;
}

function renderVarRow(v) {
    const input = renderTypedInput(v);
    const isImg = v.name === 'WALLPAPER_URL' || v.name === 'LOGO_URL';
    const type = v.name === 'WALLPAPER_URL' ? 'wallpaper' : 'logo';

    let gallery = '';
    if (isImg) {
        const imgs = uploadedImages[type + 's'] || [];
        gallery = `
            <div class="mt-2">
                <label class="inline-flex items-center gap-2 px-3 py-2 bg-blue-600 text-white rounded-lg text-sm cursor-pointer hover:bg-blue-700">
                    <input type="file" class="hidden" accept="image/jpeg,image/png,image/gif,image/webp" onchange="handleImageUpload('${type}', ${v.id}, this)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Upload
                </label>
            </div>
            <div class="image-gallery mt-2" id="${type}-gallery">
                ${imgs.length ? imgs.map(i => `
                    <div class="gallery-thumb ${i.url === (v.current_value || '') ? 'selected' : ''}"
                         onclick="selectGalleryImage('${Utils.escapeHtml(i.url)}', ${v.id}, this)">
                        <img src="${i.thumbnail || i.url}" alt="${i.filename}">
                    </div>
                `).join('') : '<span class="text-slate-500 text-xs">Nenhuma imagem</span>'}
            </div>`;
    }

    return `
        <div class="var-row">
            <label class="block text-sm font-medium text-slate-300 mb-1">
                ${Utils.escapeHtml(v.name)}${v.is_required ? '<span class="text-red-400">*</span>' : ''}
            </label>
            ${input}
            ${v.description ? `<p class="text-slate-500 text-xs mt-1">${Utils.escapeHtml(v.description)}</p>` : ''}
            ${gallery}
        </div>`;
}

function renderTypedInput(v) {
    const val = v.current_value || '';
    const varId = v.id;
    const opts = variableOptions[v.name];

    // Boolean
    if (opts === 'boolean' || v.type === 'boolean') {
        const checked = val === 'true' || val === '1' || val === true;
        return `
            <label class="toggle-switch">
                <input type="checkbox" data-var-id="${varId}" ${checked ? 'checked' : ''}>
                <span class="toggle-slider"></span>
            </label>
            <span class="ml-2 text-sm text-slate-300">${checked ? 'Ativo' : 'Inativo'}</span>`;
    }

    // Select
    if (Array.isArray(opts)) {
        return `<select data-var-id="${varId}" class="var-select">
            ${opts.map(o => `<option value="${o}" ${val === o ? 'selected' : ''}>${o}</option>`).join('')}
        </select>`;
    }

    // Array
    if (v.type === 'array') {
        return `<textarea data-var-id="${varId}" rows="2" class="var-textarea" placeholder="Separe multiplos valores por virgula">${Utils.escapeHtml(val)}</textarea>`;
    }

    // URL
    if (v.type === 'url' || v.name.includes('URL')) {
        return `<input type="url" data-var-id="${varId}" value="${Utils.escapeHtml(val)}" class="var-input">`;
    }

    // IP
    if (v.type === 'ip' || v.name.includes('IP') || v.name.includes('DNS')) {
        return `<input type="text" data-var-id="${varId}" value="${Utils.escapeHtml(val)}" class="var-input font-mono">`;
    }

    // Password
    if (v.type === 'password') {
        return `<input type="password" data-var-id="${varId}" value="${Utils.escapeHtml(val)}" class="var-input">`;
    }

    // Default text
    return `<input type="text" data-var-id="${varId}" value="${Utils.escapeHtml(val)}" class="var-input">`;
}

function filterByCategory(c) {
    activeCategory = c;
    renderVariables(allVariables);
}
window.filterByCategory = filterByCategory;

async function saveVariables() {
    if (!currentOrgId) return;

    const updates = {};
    document.querySelectorAll('[data-var-id]').forEach(el => {
        const value = el.type === 'checkbox' ? (el.checked ? 'true' : 'false') : el.value;
        updates[el.dataset.varId] = value;
    });

    const res = await API.post('variables-update', { organization_id: currentOrgId, variables: updates });
    if (res.success) {
        Toast.success('Variaveis salvas com sucesso');
        loadVariables(currentOrgId);
    } else {
        Toast.error(res.error || 'Erro ao salvar');
    }
}
window.saveVariables = saveVariables;

function selectGalleryImage(url, varId, el) {
    const input = document.querySelector(`input[data-var-id="${varId}"], textarea[data-var-id="${varId}"]`);
    if (input) input.value = url;

    const gallery = el.closest('.image-gallery');
    gallery.querySelectorAll('.gallery-thumb').forEach(t => t.classList.remove('selected'));
    el.classList.add('selected');
}
window.selectGalleryImage = selectGalleryImage;

async function handleImageUpload(type, varId, inputEl) {
    const file = inputEl.files[0];
    if (!file || !currentOrgId) return;

    const fd = new FormData();
    fd.append(type, file);
    fd.append('organization_id', currentOrgId);

    Toast.info('Enviando arquivo...');

    const res = await API.postMultipart(`upload-${type}`, fd);
    if (res.success) {
        Toast.success('Arquivo enviado com sucesso');

        const inp = document.querySelector(`input[data-var-id="${varId}"]`);
        if (inp) inp.value = res.data.url;

        const gallery = document.getElementById(`${type}-gallery`);
        if (gallery) {
            gallery.querySelectorAll('.gallery-thumb').forEach(t => t.classList.remove('selected'));
            const item = document.createElement('div');
            item.className = 'gallery-thumb selected';
            item.innerHTML = `<img src="${res.data.thumbnail || res.data.url}" alt="${res.data.filename}">`;
            item.onclick = () => selectGalleryImage(res.data.url, varId, item);
            gallery.insertBefore(item, gallery.firstChild);
        }
    } else {
        Toast.error(res.error || 'Erro no upload');
    }
}
window.handleImageUpload = handleImageUpload;

// ============ TABS ============

function switchTab(tabName) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active', 'border-blue-500', 'text-blue-400');
        btn.classList.add('border-transparent', 'text-slate-400');
    });
    document.querySelector(`.tab-btn[data-tab="${tabName}"]`)?.classList.add('active', 'border-blue-500', 'text-blue-400');

    document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
    document.getElementById(`tab-${tabName}`)?.classList.remove('hidden');

    if (tabName === 'scripts') loadOrgScripts(currentOrgId);
}
window.switchTab = switchTab;

// ============ SCRIPTS ============

async function loadAllScripts() {
    const res = await API.get('scripts');
    if (!res.success) return;

    const core = res.data.filter(s => s.is_core);
    const custom = res.data.filter(s => !s.is_core);

    const el = document.getElementById('scripts-list');
    if (!el) return;

    el.innerHTML = `
        <div class="mb-6">
            <h4 class="text-sm font-semibold text-slate-400 uppercase mb-3">Scripts Core (${core.length})</h4>
            <div class="space-y-2">
                ${core.map(s => `
                    <div class="p-4 bg-slate-900 rounded-lg border border-slate-700 flex justify-between items-center">
                        <div>
                            <span class="font-medium text-white">${Utils.escapeHtml(s.name)}</span>
                            <span class="text-slate-500 text-sm ml-2">${Utils.escapeHtml(s.filename)}</span>
                            <span class="ml-2 px-2 py-0.5 text-xs bg-blue-500/20 text-blue-400 rounded">Core</span>
                        </div>
                        <button onclick="viewScript(${s.id})" class="text-blue-400 hover:text-blue-300 text-sm">Visualizar</button>
                    </div>
                `).join('') || '<p class="text-slate-500 text-sm">Nenhum</p>'}
            </div>
        </div>
        <div>
            <div class="flex justify-between mb-3">
                <h4 class="text-sm font-semibold text-slate-400 uppercase">Scripts Custom (${custom.length})</h4>
                <button onclick="openModal('modal-new-script')" class="text-sm text-blue-400 hover:text-blue-300">+ Novo</button>
            </div>
            <div class="space-y-2">
                ${custom.map(s => `
                    <div class="p-4 bg-slate-900 rounded-lg border border-slate-700 flex justify-between items-center">
                        <div>
                            <span class="font-medium text-white">${Utils.escapeHtml(s.name)}</span>
                            <span class="text-slate-500 text-sm ml-2">${Utils.escapeHtml(s.filename)}</span>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="viewScript(${s.id})" class="text-blue-400 hover:text-blue-300 text-sm">Visualizar</button>
                            <button onclick="editScript(${s.id})" class="text-amber-400 hover:text-amber-300 text-sm">Editar</button>
                            <button onclick="deleteScript(${s.id})" class="text-red-400 hover:text-red-300 text-sm">Excluir</button>
                        </div>
                    </div>
                `).join('') || '<p class="text-slate-500 text-sm">Nenhum</p>'}
            </div>
        </div>`;
}

async function loadOrgScripts(orgId) {
    if (!orgId) orgId = currentOrgId;
    const res = await API.get('scripts', { org_id: orgId });
    if (!res.success) return;

    const scripts = res.data || [];
    const core = scripts.filter(s => s.is_core);
    const custom = scripts.filter(s => !s.is_core);

    const el = document.getElementById('org-scripts-list');
    if (!el) return;

    const currentList = scriptTab === 'Core' ? core : custom;

    el.innerHTML = currentList.map(s => `
        <div class="flex items-center justify-between p-3 bg-slate-900 rounded border border-slate-700">
            <div class="flex items-center gap-3">
                <input type="checkbox" class="script-checkbox" value="${s.id}" checked>
                <div>
                    <span class="text-white">${Utils.escapeHtml(s.name)}</span>
                    <span class="text-slate-500 text-sm ml-2">v${s.version || 1}</span>
                    ${s.is_core ? '<span class="ml-2 px-2 py-0.5 text-xs bg-blue-500/20 text-blue-400 rounded">Core</span>' : ''}
                </div>
            </div>
            <button onclick="viewScript(${s.id})" class="text-blue-400 hover:text-blue-300 text-sm">Ver</button>
        </div>
    `).join('') || '<p class="text-slate-500 text-sm">Nenhum script</p>';
}

function switchScriptTab(type) {
    scriptTab = type;
    document.querySelectorAll('.script-tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.scriptTab === type);
    });
    loadOrgScripts(currentOrgId);
}
window.switchScriptTab = switchScriptTab;

async function viewScript(id) {
    const res = await API.get('script', { id: id });
    if (!res.success) {
        Toast.error(res.error || 'Erro ao carregar script');
        return;
    }

    document.getElementById('script-view-name').textContent = res.data.name;
    document.getElementById('script-view-filename').textContent = res.data.filename;
    document.getElementById('script-view-content').value = res.data.content || '';
    document.getElementById('script-view-core').textContent = res.data.is_core ? 'Sim' : 'Nao';

    // Show/hide edit/delete buttons
    document.getElementById('script-edit-btn').classList.toggle('hidden', res.data.is_core);
    document.getElementById('script-delete-btn').classList.toggle('hidden', res.data.is_core);

    if (!res.data.is_core) {
        document.getElementById('script-edit-btn').onclick = () => editScript(id);
        document.getElementById('script-delete-btn').onclick = () => deleteScript(id);
    }

    openModal('modal-view-script');
}
window.viewScript = viewScript;

async function editScript(id) {
    const res = await API.get('script', { id });
    if (!res.success) {
        Toast.error(res.error);
        return;
    }

    document.getElementById('edit-script-id').value = res.data.id;
    document.getElementById('edit-script-name').value = res.data.name;
    document.getElementById('edit-script-filename').value = res.data.filename;
    document.getElementById('edit-script-description').value = res.data.description || '';
    document.getElementById('edit-script-content').value = res.data.content || '';

    closeModal('modal-view-script');
    openModal('modal-edit-script');
}
window.editScript = editScript;

async function deleteScript(id) {
    if (!confirm('Tem certeza que deseja excluir este script?')) return;

    const res = await API.delete('script', id);
    if (res.success) {
        Toast.success('Script excluido');
        closeModal('modal-view-script');
        loadAllScripts();
    } else {
        Toast.error(res.error || 'Erro ao excluir');
    }
}
window.deleteScript = deleteScript;

// ============ BUNDLE ============

async function generateBundle() {
    if (!currentOrgId) {
        Toast.error('Selecione uma organizacao');
        return;
    }

    const selected = [...document.querySelectorAll('.script-checkbox:checked')].map(el => parseInt(el.value));

    Toast.info('Gerando bundle...');

    const res = await API.post('generate-bundle', {
        organization_id: currentOrgId,
        scripts: selected
    });

    if (res.success && res.data.download_url) {
        Toast.success('Bundle gerado com sucesso');
        window.location.href = res.data.download_url;
    } else {
        Toast.error(res.error || 'Erro ao gerar bundle');
    }
}
window.generateBundle = generateBundle;

// ============ USERS ============

async function loadUsers() {
    const res = await API.get('users');
    if (!res.success) return;

    const el = document.getElementById('users-tbody');
    if (!el) return;

    el.innerHTML = res.data.length ? res.data.map(u => `
        <tr>
            <td class="px-4 py-3">${Utils.escapeHtml(u.username)}</td>
            <td class="px-4 py-3">${Utils.escapeHtml(u.full_name || '-')}</td>
            <td class="px-4 py-3">${Utils.escapeHtml(u.email || '-')}</td>
            <td class="px-4 py-3"><span class="badge badge-info">${roleLabels[u.role] || u.role}</span></td>
            <td class="px-4 py-3">${Utils.escapeHtml(u.org_acronym || '-')}</td>
            <td class="px-4 py-3">
                <span class="badge ${u.is_active ? 'badge-success' : 'badge-secondary'}">${u.is_active ? 'Ativo' : 'Inativo'}</span>
            </td>
            <td class="px-4 py-3 text-right">
                <button onclick="editUser(${u.id})" class="text-blue-400 hover:text-blue-300 text-sm mr-2">Editar</button>
                <button onclick="toggleUserStatus(${u.id})" class="text-amber-400 hover:text-amber-300 text-sm mr-2">${u.is_active ? 'Desativar' : 'Ativar'}</button>
                <button onclick="deleteUser(${u.id})" class="text-red-400 hover:text-red-300 text-sm">Excluir</button>
            </td>
        </tr>
    `).join('') : '<tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">Nenhum usuario</td></tr>';
}

async function saveUser(e) {
    e.preventDefault();

    const password = document.getElementById('user-password').value;
    const confirmPassword = document.getElementById('user-confirm-password').value;

    if (password && password !== confirmPassword) {
        Toast.error('Senhas nao conferem');
        return;
    }

    const id = document.getElementById('user-edit-id').value;
    const data = {
        username: document.getElementById('user-username').value,
        full_name: document.getElementById('user-full-name').value,
        email: document.getElementById('user-email').value,
        role: document.getElementById('user-role').value,
        organization_id: document.getElementById('user-organization').value || null,
        password: password,
        confirm_password: confirmPassword
    };

    const res = id
        ? await API.put('user', id, data)
        : await API.post('users', data);

    if (res.success) {
        Toast.success(id ? 'Usuario atualizado' : 'Usuario criado');
        closeModal('modal-user');
        loadUsers();
    } else {
        Toast.error(res.error || 'Erro ao salvar');
    }
}
window.saveUser = saveUser;

function editUser(id) {
    // Load user data and open modal
    API.get('users').then(res => {
        if (!res.success) return;
        const user = res.data.find(u => u.id === id);
        if (!user) return;

        document.getElementById('user-edit-id').value = user.id;
        document.getElementById('user-username').value = user.username;
        document.getElementById('user-full-name').value = user.full_name || '';
        document.getElementById('user-email').value = user.email || '';
        document.getElementById('user-role').value = user.role;
        document.getElementById('user-organization').value = user.organization_id || '';
        document.getElementById('user-password').value = '';
        document.getElementById('user-confirm-password').value = '';

        document.getElementById('modal-user-title').textContent = 'Editar Usuario';
        openModal('modal-user');
    });
}
window.editUser = editUser;

async function deleteUser(id) {
    if (!confirm('Tem certeza que deseja excluir este usuario?')) return;

    const res = await API.delete('user', id);
    if (res.success) {
        Toast.success('Usuario excluido');
        loadUsers();
    } else {
        Toast.error(res.error || 'Erro ao excluir');
    }
}
window.deleteUser = deleteUser;

async function toggleUserStatus(id) {
    const res = await API.post('user', { id: id });
    if (res.success) {
        Toast.success(res.message || 'Status alterado');
        loadUsers();
    } else {
        Toast.error(res.error || 'Erro');
    }
}
window.toggleUserStatus = toggleUserStatus;

// ============ STATIONS ============

async function loadStations() {
    const res = await API.get('stations', { org_id: currentOrgId || 0 });
    if (!res.success) return;

    const el = document.getElementById('stations-tbody');
    if (!el) return;

    el.innerHTML = res.data.length ? res.data.map(s => {
        const connStatus = {
            online: 'badge-success',
            delayed: 'badge-warning',
            never: 'badge-secondary',
            unknown: 'badge-secondary'
        }[s.connection_status] || 'badge-secondary';

        const connLabel = {
            online: 'Online',
            delayed: 'Atrasada',
            never: 'Nunca',
            unknown: '-'
        }[s.connection_status] || '-';

        return `
            <tr>
                <td class="px-4 py-3">${Utils.escapeHtml(s.hostname)}</td>
                <td class="px-4 py-3 font-mono text-sm">${Utils.escapeHtml(s.ip_address || '-')}</td>
                <td class="px-4 py-3 font-mono text-sm">${Utils.escapeHtml(s.mac_address || '-')}</td>
                <td class="px-4 py-3">${Utils.escapeHtml(s.os_name || '-')} ${Utils.escapeHtml(s.os_version || '')}</td>
                <td class="px-4 py-3">${Utils.formatDate(s.last_checkin)}</td>
                <td class="px-4 py-3"><span class="badge ${connStatus}">${connLabel}</span></td>
                <td class="px-4 py-3">${Utils.escapeHtml(s.org_acronym || '-')}</td>
            </tr>
        `;
    }).join('') : '<tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">Nenhuma estacao</td></tr>';
}

// ============ AUDIT ============

async function loadAuditEvents() {
    const params = {};
    const orgId = document.getElementById('audit-org-filter')?.value;
    const startDate = document.getElementById('audit-start-date')?.value;
    const endDate = document.getElementById('audit-end-date')?.value;

    if (orgId) params.org_id = orgId;
    if (startDate) params.start_date = startDate;
    if (endDate) params.end_date = endDate;

    const res = await API.get('audit', params);
    if (!res.success) return;

    const el = document.getElementById('audit-tbody');
    if (!el) return;

    el.innerHTML = res.data.length ? res.data.map(e => `
        <tr>
            <td class="px-4 py-3">${Utils.formatDate(e.created_at)}</td>
            <td class="px-4 py-3">${Utils.escapeHtml(e.full_name || e.username || '-')}</td>
            <td class="px-4 py-3"><span class="badge badge-info">${Utils.escapeHtml(e.action)}</span></td>
            <td class="px-4 py-3">${Utils.escapeHtml(e.entity)}</td>
            <td class="px-4 py-3">${Utils.escapeHtml(e.org_acronym || '-')}</td>
            <td class="px-4 py-3 text-slate-400 text-sm">${Utils.escapeHtml(e.details || '-')}</td>
        </tr>
    `).join('') : '<tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">Nenhum evento</td></tr>';
}

// ============ ORGANIZATION CRUD ============

async function createOrganization(e) {
    e.preventDefault();

    const name = document.getElementById('new-org-name').value;
    const acronym = document.getElementById('new-org-acronym').value.toUpperCase();
    const domain = document.getElementById('new-org-domain').value;
    const description = document.getElementById('new-org-description').value;
    const dcIp = document.getElementById('new-org-dc-ip')?.value;
    const dnsPrimario = document.getElementById('new-org-dns-primario')?.value;
    const dnsSecundario = document.getElementById('new-org-dns-secundario')?.value;

    if (!name || !acronym) {
        Toast.error('Nome e sigla obrigatorios');
        return;
    }

    const res = await API.post('organizations', {
        name, acronym, domain, description,
        dc_ip: dcIp,
        dns_primario: dnsPrimario,
        dns_secundario: dnsSecundario
    });

    if (res.success) {
        Toast.success('Organizacao criada');
        closeModal('modal-new-org');
        loadDashboard();
        loadOrganizations();
        if (res.data?.id) selectOrganization(res.data.id);
    } else {
        Toast.error(res.error || 'Erro ao criar');
    }
}
window.createOrganization = createOrganization;

async function updateOrganization(e) {
    e.preventDefault();

    if (!currentOrgId) return;

    const res = await API.put('organization', currentOrgId, {
        name: document.getElementById('edit-org-name').value,
        domain: document.getElementById('edit-org-domain').value,
        description: document.getElementById('edit-org-description').value
    });

    if (res.success) {
        Toast.success('Organizacao atualizada');
        closeModal('modal-edit-org');
        loadDashboard();
        loadOrganizations();
    } else {
        Toast.error(res.error || 'Erro ao atualizar');
    }
}
window.updateOrganization = updateOrganization;

async function deleteOrganization(id) {
    if (!confirm('Tem certeza que deseja excluir esta organizacao?')) return;

    const res = await API.delete('organization', id);
    if (res.success) {
        Toast.success('Organizacao excluida');
        showView('dashboard');
        loadDashboard();
        loadOrganizations();
    } else {
        Toast.error(res.error || 'Erro ao excluir');
    }
}
window.deleteOrganization = deleteOrganization;

// ============ MODALS ============

function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('hidden');
}
window.openModal = openModal;

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('hidden');
}
window.closeModal = closeModal;

// ============ EVENT LISTENERS ============

function setupEventListeners() {
    // Navigation
    document.querySelectorAll('.nav-item[data-view]').forEach(btn => {
        btn.addEventListener('click', () => showView(btn.dataset.view));
    });

    // Logout
    document.getElementById('btn-logout')?.addEventListener('click', async () => {
        await API.post('logout');
        location.href = '/login.html';
    });

    // New org
    document.getElementById('btn-new-org')?.addEventListener('click', () => {
        document.getElementById('new-org-form')?.reset();
        openModal('modal-new-org');
    });

    // Save variables
    document.getElementById('btn-save-vars')?.addEventListener('click', saveVariables);

    // Generate bundle
    document.getElementById('btn-generate-bundle')?.addEventListener('click', generateBundle);

    // Edit org
    document.getElementById('btn-edit-org')?.addEventListener('click', () => openModal('modal-edit-org'));

    // New user
    document.getElementById('btn-new-user')?.addEventListener('click', () => {
        document.getElementById('user-form')?.reset();
        document.getElementById('user-edit-id').value = '';
        document.getElementById('modal-user-title').textContent = 'Novo Usuario';
        openModal('modal-user');
    });

    // Variable search
    document.getElementById('var-search')?.addEventListener('input', Utils.debounce(() => {
        renderVariables(allVariables);
    }, 300));

    // Modal close buttons
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.modal');
            if (modal) modal.classList.add('hidden');
        });
    });

    // Modal backdrop click
    document.querySelectorAll('.modal-backdrop').forEach(el => {
        el.addEventListener('click', (e) => {
            if (e.target === el) {
                el.closest('.modal')?.classList.add('hidden');
            }
        });
    });

    // Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal:not(.hidden)').forEach(m => m.classList.add('hidden'));
        }
    });

    // Forms
    document.getElementById('new-org-form')?.addEventListener('submit', createOrganization);
    document.getElementById('edit-org-form')?.addEventListener('submit', updateOrganization);
    document.getElementById('user-form')?.addEventListener('submit', saveUser);
}
