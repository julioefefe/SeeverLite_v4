/**
 * SeederLinux Lite - Admin Panel JavaScript
 */

let currentUser = null;
let currentOrgId = null;
let organizations = [];
let allVariables = [];
let activeCategory = 'Todas';

const API = {
    async get(action, params = {}) {
        const url = new URL('/api/', window.location.origin);
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
        const res = await fetch(url, { credentials: 'same-origin' });
        return res.json();
    },
    async post(action, data) {
        const url = new URL('/api/', window.location.origin);
        url.searchParams.set('action', action);
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
            credentials: 'same-origin'
        });
        return res.json();
    },
    async put(action, id, data) {
        const url = new URL('/api/', window.location.origin);
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
    async delete(action, id) {
        const url = new URL('/api/', window.location.origin);
        url.searchParams.set('action', action);
        url.searchParams.set('id', id);
        const res = await fetch(url, { method: 'DELETE', credentials: 'same-origin' });
        return res.json();
    }
};

const Utils = {
    escapeHtml: (str) => str?.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"})[m]) || '',
    formatDate: (d) => d ? new Date(d).toLocaleString('pt-BR') : '-'
};

const Toast = {
    show: (msg, type = 'success') => {
        const container = document.getElementById('toast-container');
        const div = document.createElement('div');
        div.className = `toast ${type}`;
        div.textContent = msg;
        container.appendChild(div);
        setTimeout(() => div.remove(), 4000);
    },
    success: (msg) => Toast.show(msg, 'success'),
    error: (msg) => Toast.show(msg, 'error'),
    warning: (msg) => Toast.show(msg, 'warning')
};

// Labels for categories
const categoryLabels = {
    'dominio': 'Dominio e Autenticacao',
    'rede': 'Configuracao de Rede',
    'proxy': 'Proxy e Internet',
    'inventario': 'Inventario',
    'navegador': 'Navegador',
    'seguranca': 'Seguranca',
    'branding': 'Identidade Visual',
    'general': 'Geral',
    'custom': 'Personalizadas',
    'arquivos': 'Arquivos e Diretorios',
    'acesso_remoto': 'Acesso Remoto',
    'impressoras': 'Impressoras',
    'certificados': 'Certificados',
    'repositorios': 'Repositorios'
};

const categoryOrder = ['dominio', 'rede', 'proxy', 'repositorios', 'navegador', 'branding', 'arquivos', 'impressoras', 'inventario', 'acesso_remoto', 'certificados', 'seguranca', 'general', 'custom'];

// Initialize
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const session = await API.get('session');
        if (!session.success) {
            window.location.href = '/login.html';
            return;
        }
        currentUser = session.data;
        updateUserUI();
        applyRolePermissions();
    } catch (e) {
        window.location.href = '/login.html';
        return;
    }

    await loadDashboard();
    await loadOrganizations();
    setupEventListeners();
});

function updateUserUI() {
    document.getElementById('user-name').textContent = currentUser.full_name || currentUser.username;
    document.getElementById('user-initial').textContent = (currentUser.full_name || currentUser.username).charAt(0).toUpperCase();
    const roles = { 'admin_gap': 'Admin GAP', 'operador_om': 'Operador OM', 'auditor': 'Auditor' };
    document.getElementById('user-role').textContent = roles[currentUser.role] || currentUser.role;
}

function applyRolePermissions() {
    const isAdmin = currentUser.role === 'admin_gap' || currentUser.role === 'admin';
    document.getElementById('nav-users')?.classList.toggle('hidden', !isAdmin);
    document.getElementById('nav-scripts-core')?.classList.toggle('hidden', !isAdmin);
    document.getElementById('nav-audit')?.classList.toggle('hidden', !isAdmin && currentUser.role !== 'auditor');
    document.getElementById('btn-new-org')?.classList.toggle('hidden', !isAdmin);
    document.getElementById('btn-new-user')?.classList.toggle('hidden', !isAdmin);

    if (currentUser.role === 'operador_om') {
        document.getElementById('orgs-section').style.display = 'none';
    }
}

// View switching
function showView(viewName) {
    document.querySelectorAll('.nav-main').forEach(btn => btn.classList.remove('active', 'bg-slate-700', 'text-white'));
    document.querySelector(`.nav-main[data-view="${viewName}"]`)?.classList.add('active', 'bg-slate-700', 'text-white');

    ['view-dashboard', 'view-om-detail', 'view-users', 'view-scripts-core', 'view-audit'].forEach(id => {
        document.getElementById(id)?.classList.add('hidden');
    });

    const titles = {
        'dashboard': ['Dashboard', 'Visao geral do sistema'],
        'users': ['Usuarios', 'Gerenciamento de usuarios'],
        'scripts-core': ['Scripts Core', 'Scripts centrais do sistema'],
        'audit': ['Auditoria', 'Eventos de auditoria']
    };

    if (viewName === 'dashboard') {
        document.getElementById('view-dashboard')?.classList.remove('hidden');
    } else if (viewName === 'users') {
        document.getElementById('view-users')?.classList.remove('hidden');
        loadUsers();
    } else if (viewName === 'scripts-core') {
        document.getElementById('view-scripts-core')?.classList.remove('hidden');
        loadCoreScripts();
    } else if (viewName === 'audit') {
        document.getElementById('view-audit')?.classList.remove('hidden');
        loadAuditEvents();
    }

    if (titles[viewName]) {
        document.getElementById('page-title').textContent = titles[viewName][0];
        document.getElementById('page-subtitle').textContent = titles[viewName][1];
    }
}
window.showView = showView;

// Dashboard
async function loadDashboard() {
    try {
        const [stats, orgs] = await Promise.all([
            API.get('dashboard'),
            API.get('organizations')
        ]);

        if (stats.success) {
            document.getElementById('dash-orgs').textContent = stats.data.organizations;
            document.getElementById('dash-scripts').textContent = stats.data.scripts;
            document.getElementById('dash-vars').textContent = stats.data.variables;
            document.getElementById('dash-bundles').textContent = stats.data.bundles_this_month || 0;
            document.getElementById('dash-stations-online').textContent = stats.data.stations_online || 0;
            document.getElementById('dash-stations-outdated').textContent = stats.data.stations_outdated || 0;
        }

        if (orgs.success) {
            organizations = orgs.data;
            renderRecentOrgs();
        }
    } catch (e) {
        console.error('Dashboard error:', e);
    }
}

function renderRecentOrgs() {
    const el = document.getElementById('recent-orgs');
    if (!organizations.length) {
        el.innerHTML = '<p class="text-slate-400 text-center">Nenhuma organizacao cadastrada</p>';
        return;
    }
    el.innerHTML = organizations.map(org => `
        <div class="flex items-center justify-between py-2 border-b border-slate-700 last:border-0">
            <div class="flex items-center gap-2">
                <span class="font-semibold text-blue-400">${Utils.escapeHtml(org.acronym)}</span>
                <span class="text-slate-400 text-sm">${Utils.escapeHtml(org.name)}</span>
            </div>
            <button onclick="selectOrganization(${org.id})" class="text-blue-400 hover:text-blue-300 text-sm">Ver</button>
        </div>
    `).join('');
}

// Organizations
async function loadOrganizations() {
    const el = document.getElementById('om-list');
    if (!el) return;

    try {
        const response = await API.get('organizations');
        if (!response.success) return;
        organizations = response.data;

        if (!organizations.length) {
            el.innerHTML = '<div class="text-center py-4 text-slate-500 text-sm">Nenhuma OM cadastrada</div>';
            return;
        }

        el.innerHTML = organizations.map(org => {
            const logoUrl = org.logo_url || '';
            const initials = org.acronym.substring(0, 3);
            return `
                <button class="nav-item w-full flex items-center gap-3 px-3 py-2 rounded-lg text-slate-300 hover:bg-slate-700 hover:text-white transition-colors text-left" data-org-id="${org.id}" onclick="selectOrganization(${org.id})">
                    <div class="org-logo">
                        ${logoUrl ? `<img src="${Utils.escapeHtml(logoUrl)}" alt="${Utils.escapeHtml(org.acronym)}" class="w-full h-full object-cover rounded" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"><span style="display:none;">${initials}</span>` : initials}
                    </div>
                    <div class="overflow-hidden">
                        <span class="block font-medium truncate">${Utils.escapeHtml(org.acronym)}</span>
                        <span class="block text-xs text-slate-500 truncate">${Utils.escapeHtml(org.name)}</span>
                    </div>
                </button>
            `;
        }).join('');

        if (currentUser.role === 'operador_om' && organizations.length === 1) {
            selectOrganization(organizations[0].id);
        }
    } catch (e) {
        console.error('Load organizations error:', e);
    }
}

async function selectOrganization(orgId) {
    currentOrgId = orgId;
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('active', 'bg-slate-700', 'text-white');
        if (parseInt(item.dataset.orgId) === orgId) item.classList.add('active', 'bg-slate-700', 'text-white');
    });

    const org = organizations.find(o => o.id === orgId);
    if (!org) return;

    document.getElementById('page-title').textContent = org.acronym;
    document.getElementById('page-subtitle').textContent = org.name;
    document.getElementById('view-dashboard').classList.add('hidden');
    document.getElementById('view-om-detail').classList.remove('hidden');

    // Header with logo
    const logoUrl = org.logo_url || '';
    const initials = org.acronym.substring(0, 3);
    document.getElementById('om-acronym-badge').innerHTML = logoUrl
        ? `<img src="${Utils.escapeHtml(logoUrl)}" alt="${Utils.escapeHtml(org.acronym)}" class="w-full h-full object-cover rounded-xl" onerror="this.style.display='none';this.parentElement.textContent='${initials}';">${initials}</img>`
        : initials;
    document.getElementById('om-display-name').textContent = org.name;
    document.getElementById('om-display-domain').textContent = org.domain || 'Sem dominio configurado';

    document.getElementById('edit-org-name').value = org.name;
    document.getElementById('edit-org-acronym').value = org.acronym;
    document.getElementById('edit-org-domain').value = org.domain || '';
    document.getElementById('edit-org-description').value = org.description || '';

    switchTab('variables');
    await loadVariables(orgId);
}
window.selectOrganization = selectOrganization;

// Variables
async function loadVariables(orgId) {
    try {
        const response = await API.get('variables', { id: orgId });
        if (!response.success) {
            Toast.error('Erro ao carregar variaveis');
            return;
        }
        allVariables = response.data.variables || [];
        activeCategory = 'Todas';
        renderVariables(allVariables);
    } catch (e) {
        console.error('Variables error:', e);
        Toast.error('Erro ao carregar variaveis');
    }
}

function renderVariables(vars) {
    const varsList = document.getElementById('vars-list');
    if (!vars.length) {
        varsList.innerHTML = '<div class="text-center py-8 text-slate-400">Nenhuma variavel configurada.</div>';
        return;
    }

    // Get unique categories
    const categories = [...new Set(vars.map(v => v.category || 'general'))];

    // Sort categories
    categories.sort((a, b) => {
        const aIdx = categoryOrder.indexOf(a);
        const bIdx = categoryOrder.indexOf(b);
        if (aIdx === -1 && bIdx === -1) return a.localeCompare(b);
        if (aIdx === -1) return 1;
        if (bIdx === -1) return -1;
        return aIdx - bIdx;
    });

    // Render horizontal tabs
    let tabsHtml = '<div class="category-tabs mb-4">';
    tabsHtml += `<button class="cat-tab ${activeCategory === 'Todas' ? 'active' : ''}" onclick="filterByCategory('Todas')">Todas</button>`;
    categories.forEach(cat => {
        const label = categoryLabels[cat] || cat;
        const count = vars.filter(v => (v.category || 'general') === cat).length;
        tabsHtml += `<button class="cat-tab ${activeCategory === cat ? 'active' : ''}" onclick="filterByCategory('${Utils.escapeHtml(cat)}')">${Utils.escapeHtml(label)} (${count})</button>`;
    });
    tabsHtml += '</div>';
    varsList.innerHTML = tabsHtml;

    // Filter variables based on category and search
    const search = (document.getElementById('var-search')?.value || '').toLowerCase();
    let filteredVars = vars;

    if (activeCategory !== 'Todas') {
        filteredVars = vars.filter(v => (v.category || 'general') === activeCategory);
    }

    if (search) {
        filteredVars = filteredVars.filter(v => v.name.toLowerCase().includes(search));
    }

    // Render variables
    const renderInput = (v) => {
        const type = v.type || 'string';
        const value = v.current_value || '';
        const placeholder = v.default_value || '';
        const varId = v.id;

        if (type === 'boolean') {
            return `<select name="var_${varId}" data-var-id="${varId}" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">
                <option value="true" ${value === 'true' ? 'selected' : ''}>Verdadeiro</option>
                <option value="false" ${value === 'false' || !value ? 'selected' : ''}>Falso</option>
            </select>`;
        }
        if (type === 'password') {
            return `<input type="password" name="var_${varId}" value="${Utils.escapeHtml(value)}" data-var-id="${varId}" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm" placeholder="******">`;
        }
        if (type === 'array') {
            return `<textarea name="var_${varId}" data-var-id="${varId}" rows="2" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">${Utils.escapeHtml(value)}</textarea>`;
        }
        if (type === 'json') {
            return `<textarea name="var_${varId}" data-var-id="${varId}" rows="3" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm font-mono">${Utils.escapeHtml(value)}</textarea>`;
        }
        return `<input type="text" name="var_${varId}" value="${Utils.escapeHtml(value)}" data-var-id="${varId}" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm" placeholder="${Utils.escapeHtml(placeholder)}">`;
    };

    // Group by category if showing all
    let varsHtml = '<div class="grid lg:grid-cols-2 gap-4 mt-4">';

    if (activeCategory === 'Todas') {
        // Group by category
        categories.forEach(cat => {
            const catVars = filteredVars.filter(v => (v.category || 'general') === cat);
            if (!catVars.length) return;

            const label = categoryLabels[cat] || cat;
            varsHtml += `
                <div class="col-span-2 mt-4 first:mt-0">
                    <h4 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-2">${Utils.escapeHtml(label)}</h4>
                </div>
            `;
            catVars.forEach(v => {
                varsHtml += renderVariableRow(v, renderInput);
            });
        });
    } else {
        filteredVars.forEach(v => {
            varsHtml += renderVariableRow(v, renderInput);
        });
    }

    varsHtml += '</div>';
    varsList.innerHTML += varsHtml;
}

function renderVariableRow(v, renderInput) {
    const isImageVar = v.name === 'WALLPAPER_URL' || v.name === 'LOGO_URL';
    const varId = v.id;
    const value = v.current_value || '';

    let extraHtml = '';
    if (isImageVar) {
        const imgType = v.name === 'WALLPAPER_URL' ? 'wallpaper' : 'logo';
        extraHtml = `
            <div class="mt-2">
                <label class="upload-btn cursor-pointer">
                    <input type="file" class="hidden" accept="image/jpeg,image/png,image/gif,image/webp" onchange="uploadImage('${imgType}', ${varId}, this)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Upload
                </label>
            </div>
        `;
    }

    return `
        <div class="var-row" data-var-name="${Utils.escapeHtml(v.name).toLowerCase()}" data-var-category="${Utils.escapeHtml(v.category || 'general')}">
            <label class="block text-sm font-medium text-slate-300 mb-1">
                ${Utils.escapeHtml(v.name)}
                ${v.is_required ? '<span class="text-red-400">*</span>' : ''}
                ${v.type && v.type !== 'string' ? `<span class="text-slate-600 text-xs ml-1">(${v.type})</span>` : ''}
            </label>
            ${renderInput(v)}
            ${v.description ? `<p class="text-slate-500 text-xs mt-1">${Utils.escapeHtml(v.description)}</p>` : ''}
            ${extraHtml}
        </div>
    `;
}

function filterByCategory(cat) {
    activeCategory = cat;
    renderVariables(allVariables);
}
window.filterByCategory = filterByCategory;

async function uploadImage(type, varId, input) {
    const file = input.files[0];
    if (!file || !currentOrgId) return;

    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        Toast.error('Tipo invalido. Use JPG, PNG, GIF ou WebP');
        return;
    }

    if (file.size > 5 * 1024 * 1024) {
        Toast.error('Arquivo muito grande. Maximo 5MB');
        return;
    }

    const formData = new FormData();
    formData.append(type === 'wallpaper' ? 'wallpaper' : 'logo', file);
    formData.append('organization_id', currentOrgId);

    try {
        const action = type === 'wallpaper' ? 'upload-wallpaper' : 'upload-logo';
        const res = await fetch(`/api/?action=${action}`, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        const data = await res.json();

        if (data.success) {
            Toast.success('Imagem enviada');
            // Update the input field
            const varInput = document.querySelector(`input[data-var-id="${varId}"], textarea[data-var-id="${varId}"]`);
            if (varInput) {
                varInput.value = data.data.url;
            }
        } else {
            Toast.error(data.error || 'Erro ao enviar');
        }
    } catch (e) {
        Toast.error('Erro ao enviar imagem');
    }

    input.value = '';
}
window.uploadImage = uploadImage;

async function saveVariables() {
    if (!currentOrgId) return;

    const updates = {};
    document.querySelectorAll('[data-var-id]').forEach(el => {
        const varId = el.dataset.varId;
        updates[varId] = el.value;
    });

    try {
        const response = await API.post('variables-update', { organization_id: currentOrgId, variables: updates });
        if (response.success) {
            Toast.success('Variaveis salvas');
            await loadVariables(currentOrgId);
        } else {
            Toast.error(response.error || 'Erro ao salvar');
        }
    } catch (e) {
        Toast.error('Erro ao salvar variaveis');
    }
}
window.saveVariables = saveVariables;

// Tab switching
function switchTab(tabName) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active', 'border-blue-500', 'text-blue-400');
        btn.classList.add('border-transparent', 'text-slate-400');
    });
    const activeBtn = document.querySelector(`.tab-btn[data-tab="${tabName}"]`);
    if (activeBtn) {
        activeBtn.classList.add('active', 'border-blue-500', 'text-blue-400');
        activeBtn.classList.remove('border-transparent', 'text-slate-400');
    }
    document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));
    const activeContent = document.getElementById(`tab-${tabName}`);
    if (activeContent) activeContent.classList.remove('hidden');
}
window.switchTab = switchTab;

// Modal handlers
function openModal(id) {
    document.getElementById(id)?.classList.remove('hidden');
}
window.openModal = openModal;

function closeModal(id) {
    document.getElementById(id)?.classList.add('hidden');
}
window.closeModal = closeModal;

// Organization CRUD
async function createOrganization() {
    const name = document.getElementById('new-org-name').value.trim();
    const acronym = document.getElementById('new-org-acronym').value.trim().toUpperCase();
    const domain = document.getElementById('new-org-domain').value.trim();
    const description = document.getElementById('new-org-description').value.trim();
    const dc_ip = document.getElementById('new-org-dc-ip')?.value.trim() || '';
    const dns_primario = document.getElementById('new-org-dns-primario')?.value.trim() || '';
    const dns_secundario = document.getElementById('new-org-dns-secundario')?.value.trim() || '';
    const homepage = document.getElementById('new-org-homepage')?.value.trim() || '';
    const proxy_http = document.getElementById('new-org-proxy-http')?.value.trim() || '';
    const proxy_porta = document.getElementById('new-org-proxy-porta')?.value.trim() || '';

    if (!name || !acronym) {
        Toast.error('Nome e sigla sao obrigatorios');
        return;
    }

    if (domain && (!dc_ip || !dns_primario)) {
        Toast.error('DC Principal e DNS Primario sao obrigatorios quando dominio e informado');
        return;
    }

    try {
        const response = await API.post('organizations', {
            name, acronym, domain, description,
            dc_ip, dns_primario, dns_secundario, homepage, proxy_http, proxy_porta
        });
        if (response.success) {
            Toast.success('Organizacao criada');
            closeModal('modal-new-org');
            document.getElementById('new-org-form').reset();
            document.getElementById('new-org-network-config')?.classList.add('hidden');
            await loadDashboard();
            await loadOrganizations();
            if (response.data?.id) selectOrganization(response.data.id);
        } else {
            Toast.error(response.error || 'Erro ao criar');
        }
    } catch (e) {
        Toast.error('Erro ao criar organizacao');
    }
}

async function updateOrganization() {
    if (!currentOrgId) return;

    const name = document.getElementById('edit-org-name').value.trim();
    const domain = document.getElementById('edit-org-domain').value.trim();
    const description = document.getElementById('edit-org-description').value.trim();

    if (!name) {
        Toast.error('Nome e obrigatorio');
        return;
    }

    try {
        const data = await API.put('organization', currentOrgId, { name, domain, description });
        if (!data.success) {
            Toast.error(data.error || 'Erro ao atualizar');
            return;
        }
        Toast.success('Organizacao atualizada');
        closeModal('modal-edit-org');
        await loadDashboard();
        await loadOrganizations();
        document.getElementById('om-display-name').textContent = name;
        document.getElementById('om-display-domain').textContent = domain || 'Sem dominio';
    } catch (e) {
        Toast.error('Erro ao atualizar');
    }
}

function deleteOrganization() {
    if (!currentOrgId) return;
    if (!confirm('Tem certeza que deseja excluir esta organizacao?')) return;

    (async () => {
        try {
            const res = await API.delete('organization', currentOrgId);
            if (res.success) {
                Toast.success('Organizacao excluida');
                showView('dashboard');
                await loadDashboard();
                await loadOrganizations();
            } else {
                Toast.error(res.error || 'Erro ao excluir');
            }
        } catch (e) {
            Toast.error('Erro ao excluir');
        }
    })();
}

// Users
async function loadUsers() {
    const tbody = document.getElementById('users-tbody');
    if (!tbody) return;

    try {
        const res = await API.get('users');
        if (!res.success) return;

        if (!res.data.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="px-6 py-8 text-center text-slate-400">Nenhum usuario cadastrado.</td></tr>';
            return;
        }

        const roles = { 'admin_gap': 'Admin GAP', 'operador_om': 'Operador OM', 'auditor': 'Auditor' };
        tbody.innerHTML = res.data.map(u => `
            <tr>
                <td class="px-6 py-4 text-sm text-white font-medium">${Utils.escapeHtml(u.username)}</td>
                <td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(u.full_name || '-')}</td>
                <td class="px-6 py-4 text-sm"><span class="px-2 py-1 text-xs rounded bg-blue-500/20 text-blue-400">${roles[u.role] || u.role}</span></td>
                <td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(u.org_acronym || '-')}</td>
                <td class="px-6 py-4 text-sm"><span class="px-2 py-1 text-xs rounded ${u.is_active ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-700 text-slate-400'}">${u.is_active ? 'Ativo' : 'Inativo'}</span></td>
                <td class="px-6 py-4 text-sm text-right">
                    <button onclick="editUser(${u.id})" class="text-blue-400 hover:text-blue-300 mr-2">Editar</button>
                    <button onclick="deleteUser(${u.id})" class="text-red-400 hover:text-red-300">Excluir</button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Load users error:', e);
    }
}

async function saveUser(e) {
    e.preventDefault();
    const id = document.getElementById('user-edit-id').value;
    const username = document.getElementById('user-username').value.trim();
    const password = document.getElementById('user-password').value;
    const role = document.getElementById('user-role').value;
    const organization_id = document.getElementById('user-organization').value || null;

    if (!username) {
        Toast.error('Username obrigatorio');
        return;
    }

    try {
        const data = { username, role, organization_id };
        if (password) data.password = password;

        const response = id
            ? await API.put('user', id, data)
            : await API.post('users', data);

        if (response.success) {
            Toast.success(id ? 'Usuario atualizado' : 'Usuario criado');
            closeModal('modal-new-user');
            loadUsers();
        } else {
            Toast.error(response.error || 'Erro ao salvar');
        }
    } catch (e) {
        Toast.error('Erro ao salvar usuario');
    }
}

// Audit
async function loadAuditEvents() {
    const tbody = document.getElementById('audit-tbody');
    if (!tbody) return;

    try {
        const res = await API.get('audit', { limit: 100 });
        if (!res.success) return;

        if (!res.data.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="px-6 py-8 text-center text-slate-400">Nenhum evento registrado.</td></tr>';
            return;
        }

        tbody.innerHTML = res.data.map(e => `
            <tr>
                <td class="px-6 py-4 text-sm text-slate-300">${Utils.formatDate(e.created_at)}</td>
                <td class="px-6 py-4 text-sm text-white">${Utils.escapeHtml(e.full_name || e.username || '-')}</td>
                <td class="px-6 py-4 text-sm"><span class="px-2 py-1 text-xs rounded bg-blue-500/20 text-blue-400">${Utils.escapeHtml(e.action)}</span></td>
                <td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(e.entity)}</td>
                <td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(e.org_acronym || '-')}</td>
                <td class="px-6 py-4 text-sm text-slate-400">${e.details ? Utils.escapeHtml(typeof e.details === 'string' ? e.details : JSON.stringify(e.details)) : '-'}</td>
            </tr>
        `).join('');
    } catch (e) {
        console.error('Audit error:', e);
    }
}

// Scripts
async function loadCoreScripts() {
    const el = document.getElementById('core-scripts-list');
    if (!el) return;

    try {
        const res = await API.get('scripts');
        if (!res.success) return;

        const scripts = res.data.filter(s => s.is_core);
        if (!scripts.length) {
            el.innerHTML = '<div class="text-slate-400 text-center py-8">Nenhum script core.</div>';
            return;
        }

        el.innerHTML = scripts.map(s => `
            <div class="p-4 bg-slate-900 rounded-lg border border-slate-700 mb-2">
                <div class="flex justify-between items-center">
                    <div>
                        <span class="font-medium text-white">${Utils.escapeHtml(s.name)}</span>
                        <span class="text-slate-500 text-sm ml-2">${s.filename}</span>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="viewScript(${s.id})" class="text-blue-400 hover:text-blue-300 text-sm">Ver</button>
                        <button onclick="deleteScript(${s.id})" class="text-red-400 hover:text-red-300 text-sm">Excluir</button>
                    </div>
                </div>
                ${s.description ? `<p class="text-slate-400 text-sm mt-2">${Utils.escapeHtml(s.description)}</p>` : ''}
            </div>
        `).join('');
    } catch (e) {
        console.error('Load scripts error:', e);
    }
}

// Event listeners
function setupEventListeners() {
    // New organization
    document.getElementById('btn-new-org')?.addEventListener('click', () => openModal('modal-new-org'));
    document.getElementById('new-org-form')?.addEventListener('submit', (e) => { e.preventDefault(); createOrganization(); });

    // Show network config when domain is filled
    document.getElementById('new-org-domain')?.addEventListener('input', function() {
        const config = document.getElementById('new-org-network-config');
        if (this.value.trim()) {
            config?.classList.remove('hidden');
        } else {
            config?.classList.add('hidden');
        }
    });

    // Edit organization
    document.getElementById('btn-edit-org')?.addEventListener('click', () => openModal('modal-edit-org'));
    document.getElementById('edit-org-form')?.addEventListener('submit', (e) => { e.preventDefault(); updateOrganization(); });

    // Save variables
    document.getElementById('btn-save-vars')?.addEventListener('click', saveVariables);
    document.getElementById('var-search')?.addEventListener('input', () => renderVariables(allVariables));

    // User form
    document.getElementById('user-form')?.addEventListener('submit', saveUser);
    document.getElementById('btn-new-user')?.addEventListener('click', () => {
        document.getElementById('user-form')?.reset();
        document.getElementById('user-edit-id').value = '';
        openModal('modal-new-user');
    });

    // Logout
    document.getElementById('btn-logout')?.addEventListener('click', async () => {
        try { await API.post('logout'); } catch (e) {}
        window.location.href = '/login.html';
    });

    // Generate bundle
    document.getElementById('btn-generate-bundle')?.addEventListener('click', async () => {
        if (!currentOrgId) return;
        try {
            const res = await API.post('bundle', { organization_id: currentOrgId });
            if (res.success && res.data?.url) {
                const link = document.getElementById('download-link');
                link.href = res.data.url;
                link.classList.remove('hidden');
                Toast.success('Bundle gerado');
            } else {
                Toast.error(res.error || 'Erro ao gerar bundle');
            }
        } catch (e) {
            Toast.error('Erro ao gerar bundle');
        }
    });

    // Modal close buttons
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.fixed')?.classList.add('hidden'));
    });

    // Backdrop click to close
    document.querySelectorAll('.modal-backdrop').forEach(btn => {
        btn.addEventListener('click', (e) => {
            if (e.target === btn) btn.closest('.fixed')?.classList.add('hidden');
        });
    });

    // Escape to close
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.fixed:not(.hidden)').forEach(m => m.classList.add('hidden'));
        }
    });
}
