/**
 * SeederLinux Lite - Admin Panel JavaScript
 * Handles dashboard, OM management, variables, scripts, bundles, users, audit
 */

let currentUser = null;
let currentOrgId = null;
let organizations = [];
let allVariables = [];
let variableCatalog = [];
let currentScriptTab = 'core';

// ============================================================================
// INIT
// ============================================================================
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const session = await API.get('session');
        console.log('[Admin] Session:', session);
        if (!session.success) {
            window.location.href = '/login.html';
            return;
        }
        currentUser = session.data;
        updateUserUI();
        applyRolePermissions();
    } catch (error) {
        console.error('[Admin] Session failed:', error);
        window.location.href = '/login.html';
        return;
    }

    await loadDashboard();
    await loadOrganizations();
    await loadVariableCatalog();
    setupEventListeners();
});

function updateUserUI() {
    document.getElementById('user-name').textContent = currentUser.full_name || currentUser.username;
    document.getElementById('user-initial').textContent = (currentUser.full_name || currentUser.username).charAt(0).toUpperCase();
    const roleLabels = {
        'admin': 'Administrador',
        'admin_gap': 'Admin GAP',
        'operador_om': 'Operador OM',
        'auditor': 'Auditor'
    };
    document.getElementById('user-role').textContent = roleLabels[currentUser.role] || currentUser.role;
}

function applyRolePermissions() {
    const role = currentUser.role;
    const isAdmin = role === 'admin' || role === 'admin_gap';
    const isAuditor = role === 'auditor';

    // Show/hide menu items
    document.getElementById('nav-users').classList.toggle('hidden', !isAdmin);
    document.getElementById('nav-scripts-core').classList.toggle('hidden', !isAdmin);
    document.getElementById('nav-audit').classList.toggle('hidden', !isAdmin && !isAuditor);
    document.getElementById('btn-new-org').classList.toggle('hidden', !isAdmin);
    document.getElementById('btn-upload-script').classList.toggle('hidden', false);
    document.getElementById('btn-new-core-script').classList.toggle('hidden', !isAdmin);
    document.getElementById('btn-new-user').classList.toggle('hidden', !isAdmin);

    // operador_om: hide orgs section, go directly to their org
    if (role === 'operador_om') {
        document.getElementById('orgs-section').style.display = 'none';
    }
}

// ============================================================================
// VIEW SWITCHING
// ============================================================================
function showView(viewName) {
    document.querySelectorAll('.nav-main').forEach(btn => {
        btn.classList.remove('active', 'bg-slate-700', 'text-white');
        btn.classList.add('text-slate-300');
    });
    const activeBtn = document.querySelector(`.nav-main[data-view="${viewName}"]`);
    if (activeBtn) {
        activeBtn.classList.add('active', 'bg-slate-700', 'text-white');
        activeBtn.classList.remove('text-slate-300');
    }

    document.getElementById('view-dashboard').classList.add('hidden');
    document.getElementById('view-om-detail').classList.add('hidden');
    const usersView = document.getElementById('view-users');
    const scriptsCoreView = document.getElementById('view-scripts-core');
    const auditView = document.getElementById('view-audit');
    if (usersView) usersView.classList.add('hidden');
    if (scriptsCoreView) scriptsCoreView.classList.add('hidden');
    if (auditView) auditView.classList.add('hidden');

    const titles = {
        'dashboard': ['Dashboard', 'Visão geral do sistema'],
        'users': ['Usuários', 'Gerenciamento de usuários'],
        'scripts-core': ['Scripts Core', 'Scripts centrais do sistema'],
        'audit': ['Auditoria', 'Eventos de auditoria do sistema']
    };

    if (viewName === 'dashboard') {
        document.getElementById('view-dashboard').classList.remove('hidden');
    } else if (viewName === 'users') {
        if (usersView) usersView.classList.remove('hidden');
        loadUsers();
    } else if (viewName === 'scripts-core') {
        if (scriptsCoreView) scriptsCoreView.classList.remove('hidden');
        loadCoreScripts();
    } else if (viewName === 'audit') {
        if (auditView) auditView.classList.remove('hidden');
        loadAuditEvents();
    }

    if (titles[viewName]) {
        document.getElementById('page-title').textContent = titles[viewName][0];
        document.getElementById('page-subtitle').textContent = titles[viewName][1];
    }
}
window.showView = showView;

// ============================================================================
// DASHBOARD
// ============================================================================
async function loadDashboard() {
    try {
        const [stats, orgs, scripts] = await Promise.all([
            API.get('dashboard'),
            API.get('organizations'),
            API.get('scripts')
        ]);

        if (stats.success) {
            document.getElementById('dash-orgs').textContent = stats.data.organizations;
            document.getElementById('dash-scripts').textContent = stats.data.scripts;
            document.getElementById('dash-vars').textContent = stats.data.variables;
            document.getElementById('dash-bundles').textContent = stats.data.bundles_this_month;
            document.getElementById('dash-stations-online').textContent = stats.data.stations_online ?? 0;
            document.getElementById('dash-stations-outdated').textContent = stats.data.stations_outdated ?? 0;
            renderRecentStations(stats.data.recent_stations ?? []);
        }

        if (orgs.success) {
            organizations = orgs.data;
            renderRecentOrgs();
        }

        if (scripts.success) {
            renderRecentScripts(scripts.data);
        }
    } catch (error) {
        console.error('Dashboard error:', error);
        Toast.error('Erro ao carregar dashboard');
    }
}

function renderRecentStations(stations) {
    const el = document.getElementById('dash-stations-table');
    if (!stations || !stations.length) {
        el.innerHTML = '<div class="p-6 text-slate-400 text-center text-sm">Nenhuma estação registrou check-in ainda.</div>';
        return;
    }
    const statusColors = { 'online': 'bg-emerald-500/20 text-emerald-400', 'offline': 'bg-slate-700 text-slate-400', 'delayed': 'bg-amber-500/20 text-amber-400', 'never_connected': 'bg-slate-700 text-slate-500' };
    el.innerHTML = `
        <table class="w-full">
            <thead class="bg-slate-900/50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-400 uppercase">Hostname</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-400 uppercase">IP</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-400 uppercase">Último Check-in</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-400 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-slate-400 uppercase">OM</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-700">
                ${stations.map(s => `
                    <tr>
                        <td class="px-6 py-4 text-sm text-white font-medium">${Utils.escapeHtml(s.hostname || '-')}</td>
                        <td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(s.ip_address || '-')}</td>
                        <td class="px-6 py-4 text-sm text-slate-300">${Utils.formatDate(s.last_checkin)}</td>
                        <td class="px-6 py-4"><span class="px-2 py-1 text-xs rounded ${statusColors[s.status] || statusColors['never_connected']}">${s.status || 'unknown'}</span></td>
                        <td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(s.org_acronym || '-')}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>`;
}

function renderRecentOrgs() {
    const el = document.getElementById('recent-orgs');
    if (!organizations.length) {
        el.innerHTML = '<p class="text-slate-400 text-center">Nenhuma organização cadastrada</p>';
        return;
    }
    el.innerHTML = organizations.map(org => `
        <div class="flex items-center justify-between py-2 border-b border-slate-700 last:border-0">
            <div>
                <span class="font-semibold text-blue-400">${Utils.escapeHtml(org.acronym)}</span>
                <span class="text-slate-400 text-sm ml-2">${Utils.escapeHtml(org.name)}</span>
            </div>
            <button onclick="selectOrganization(${org.id})" class="text-blue-400 hover:text-blue-300 text-sm">Ver →</button>
        </div>
    `).join('');
}

function renderRecentScripts(scripts) {
    const el = document.getElementById('recent-scripts');
    if (!scripts.length) {
        el.innerHTML = '<p class="text-slate-400 text-center">Nenhum script disponível</p>';
        return;
    }
    el.innerHTML = scripts.map(script => `
        <div class="flex items-center justify-between py-2 border-b border-slate-700 last:border-0">
            <div>
                <span class="font-medium text-white">${Utils.escapeHtml(script.name)}</span>
                <span class="text-slate-500 text-xs ml-2">${script.filename}</span>
            </div>
            <span class="px-2 py-1 text-xs rounded ${script.is_core ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-700 text-slate-400'}">
                ${script.is_core ? 'Core' : 'Custom'}
            </span>
        </div>
    `).join('');
}

// ============================================================================
// ORGANIZATIONS
// ============================================================================
async function loadOrganizations() {
    const orgList = document.getElementById('om-list');
    if (!organizations.length) {
        orgList.innerHTML = '<div class="text-center py-4 text-slate-500 text-sm">Nenhuma OM cadastrada</div>';
        return;
    }
    orgList.innerHTML = organizations.map(org => `
        <button class="nav-item w-full flex items-center gap-3 px-3 py-2 rounded-lg text-slate-300 hover:bg-slate-700 hover:text-white transition-colors text-left" data-org-id="${org.id}" onclick="selectOrganization(${org.id})">
            <span class="w-8 h-8 bg-slate-700 rounded-lg flex items-center justify-center text-sm font-semibold">${org.acronym.substring(0, 2)}</span>
            <div class="overflow-hidden">
                <span class="block font-medium truncate">${Utils.escapeHtml(org.acronym)}</span>
                <span class="block text-xs text-slate-500 truncate">${Utils.escapeHtml(org.name)}</span>
            </div>
        </button>
    `).join('');

    // If operador_om, auto-select their org
    if (currentUser.role === 'operador_om' && organizations.length === 1) {
        selectOrganization(organizations[0].id);
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

    document.getElementById('om-acronym-badge').textContent = org.acronym.substring(0, 3);
    document.getElementById('om-display-name').textContent = org.name;
    document.getElementById('om-display-domain').textContent = org.domain || 'Sem dominio configurado';

    // Populate edit modal fields
    document.getElementById('edit-org-name').value = org.name;
    document.getElementById('edit-org-acronym').value = org.acronym;
    document.getElementById('edit-org-domain').value = org.domain || '';
    document.getElementById('edit-org-description').value = org.description || '';

    // Switch to variables tab
    switchTab('variables');

    await loadVariables(orgId);
    await loadScriptsForBundle(orgId);
    await loadOrgScripts(orgId);
}
window.selectOrganization = selectOrganization;

// ============================================================================
// VARIABLES
// ============================================================================
async function loadVariables(orgId) {
    try {
        const response = await API.get('variables', { id: orgId });
        if (!response.success) { Toast.error('Erro ao carregar variáveis'); return; }

        allVariables = response.data.variables || [];
        renderVariables(allVariables);
        populateCategoryFilter(allVariables);
    } catch (error) {
        console.error('Variables error:', error);
        Toast.error('Erro ao carregar variáveis');
    }
}

function renderVariables(vars) {
    const varsList = document.getElementById('vars-list');
    if (!vars.length) {
        varsList.innerHTML = '<div class="col-span-2 text-center py-8 text-slate-400">Nenhuma variavel configurada. Clique em "+ Variavel" para adicionar.</div>';
        return;
    }

    const categories = {};
    vars.forEach(v => {
        const cat = v.category || 'general';
        if (!categories[cat]) categories[cat] = [];
        categories[cat].push(v);
    });

    const categoryLabels = {
        'dominio': 'Dominio e Autenticacao', 'rede': 'Configuracao de Rede',
        'proxy': 'Proxy e Internet', 'inventario': 'Inventario',
        'navegador': 'Navegador', 'seguranca': 'Seguranca',
        'branding': 'Identidade Visual', 'general': 'Geral', 'custom': 'Personalizadas',
        'arquivos': 'Arquivos e Diretorios', 'acesso_remoto': 'Acesso Remoto',
        'impressoras': 'Impressoras', 'certificados': 'Certificados', 'repositorios': 'Repositorios'
    };

    const renderInput = (v) => {
        const type = v.type || 'string';
        const value = v.current_value || '';
        const placeholder = v.default_value || '';
        const varId = v.id;

        if (type === 'boolean') {
            return `<select name="var_${varId}" data-var-id="${varId}" class="w-full px-4 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="true" ${value === 'true' ? 'selected' : ''}>Verdadeiro (true)</option>
                <option value="false" ${value === 'false' || value === '' ? 'selected' : ''}>Falso (false)</option>
            </select>`;
        } else if (type === 'password') {
            return `<input type="password" name="var_${varId}" value="${Utils.escapeHtml(value)}" data-var-id="${varId}" class="w-full px-4 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="******">`;
        } else if (type === 'array') {
            return `<textarea name="var_${varId}" data-var-id="${varId}" rows="2" class="w-full px-4 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="${Utils.escapeHtml(placeholder)}">${Utils.escapeHtml(value)}</textarea>
                <p class="text-slate-500 text-xs mt-1">Valores separados por virgula (ex: valor1, valor2, valor3)</p>`;
        } else if (type === 'json') {
            return `<textarea name="var_${varId}" data-var-id="${varId}" rows="3" class="w-full px-4 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder='{"key": "value"}'>${Utils.escapeHtml(value)}</textarea>`;
        } else {
            return `<input type="text" name="var_${varId}" value="${Utils.escapeHtml(value)}" data-var-id="${varId}" class="w-full px-4 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="${Utils.escapeHtml(placeholder)}">`;
        }
    };

    let html = '';
    for (const [category, categoryVars] of Object.entries(categories)) {
        html += `<div class="col-span-2"><h4 class="text-sm font-semibold text-slate-400 uppercase tracking-wider mb-3 mt-4 first:mt-0">${categoryLabels[category] || category}</h4></div>`;
        categoryVars.forEach(v => {
            html += `
                <div class="var-row" data-var-name="${Utils.escapeHtml(v.name).toLowerCase()}" data-var-category="${Utils.escapeHtml(v.category || '')}">
                    <label class="block text-sm font-medium text-slate-300 mb-2">
                        ${Utils.escapeHtml(v.name)} ${v.is_required ? '<span class="text-red-400">*</span>' : ''}
                        ${v.type && v.type !== 'string' ? `<span class="text-slate-600 text-xs ml-1"
        }
        )
    }
}>(${v.type})</span>` : ''}
                    </label>
                    ${renderInput(v)}
                    ${v.description && v.type !== 'array' ? `<p class="text-slate-500 text-xs mt-1">${Utils.escapeHtml(v.description)}</p>` : ''}
                </div>`;
        });
    }
    varsList.innerHTML = html;
}

function populateCategoryFilter(vars) {
    const select = document.getElementById('var-category-filter');
    const cats = [...new Set(vars.map(v => v.category || 'general'))];
    select.innerHTML = '<option value="">Todas categorias</option>' + cats.map(c => `<option value="${c}">${c}</option>`).join('');
}

function filterVariables() {
    const search = document.getElementById('var-search').value.toLowerCase();
    const category = document.getElementById('var-category-filter').value;
    document.querySelectorAll('.var-row').forEach(row => {
        const name = row.dataset.varName;
        const cat = row.dataset.varCategory;
        const nameMatch = !search || name.includes(search);
        const catMatch = !category || cat === category;
        row.style.display = (nameMatch && catMatch) ? '' : 'none';
    });
}
window.filterVariables = filterVariables;

async function saveVariables() {
    if (!currentOrgId) return;
    // Include both input and textarea for array types
    const inputs = document.querySelectorAll('#vars-form input[data-var-id], #vars-form textarea[data-var-id], #vars-form select[data-var-id]');
    const variables = {};
    inputs.forEach(input => { variables[input.dataset.varId] = input.value; });

    try {
        const response = await API.post('variables-update', { organization_id: currentOrgId, variables });
        if (response.success) Toast.success('Variaveis salvas com sucesso');
        else Toast.error(response.error || 'Erro ao salvar variaveis');
    } catch (error) {
        Toast.error('Erro ao salvar variaveis');
    }
}

async function loadVariableCatalog() {
    try {
        const response = await API.get('variable-catalog');
        if (response.success) {
            variableCatalog = response.data;
            const datalist = document.getElementById('variable-catalog-list');
            datalist.innerHTML = response.data.map(v => `<option value="${Utils.escapeHtml(v.name)}">${Utils.escapeHtml(v.description || '')}</option>`).join('');
        }
    } catch (error) { console.error('Catalog error:', error); }
}

async function addVariable(e) {
    e.preventDefault();
    if (!currentOrgId) { Toast.error('Selecione uma organizacao'); return; }

    const name = document.getElementById('var-name').value.trim().toUpperCase();
    const value = document.getElementById('var-value').value;
    const description = document.getElementById('var-description').value;
    const type = document.getElementById('var-type').value;
    const category = document.getElementById('var-category').value || 'general';
    const required = document.getElementById('var-required').checked;

    if (!name) { Toast.error('Nome da variavel e obrigatorio'); return; }

    try {
        const response = await API.post('variables', {
            organization_id: currentOrgId,
            name, value, description, type, category, required
        });
        if (response.success) {
            Toast.success('Variavel adicionada');
            closeModal('modal-add-variable');
            document.getElementById('add-variable-form').reset();
            await loadVariables(currentOrgId);
        } else {
            Toast.error(response.error || 'Erro ao adicionar variavel');
        }
    } catch (error) {
        Toast.error('Erro ao adicionar variavel');
    }
}

// Handle variable type change in the add variable modal
document.getElementById('var-type')?.addEventListener('change', function() {
    const arrayHint = document.getElementById('var-array-hint');
    const valueInput = document.getElementById('var-value');
    const wrapper = document.getElementById('var-value-input-wrapper');

    if (this.value === 'array') {
        arrayHint?.classList.remove('hidden');
        valueInput.placeholder = 'valor1, valor2, valor3';
    } else if (this.value === 'boolean') {
        wrapper.innerHTML = `
            <select id="var-value" class="w-full px-4 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white">
                <option value="true">Verdadeiro (true)</option>
                <option value="false">Falso (false)</option>
            </select>
        `;
        arrayHint?.classList.add('hidden');
    } else if (this.value === 'password') {
        wrapper.innerHTML = `<input type="password" id="var-value" class="w-full px-4 py-3 bg-slate-900 border border-slate-700 rounded-lg text-white" placeholder="Digite a senha">`;
        arrayHint?.classList.add('hidden');
    } else {
        arrayHint?.classList.add('hidden');
        valueInput.placeholder = 'Digite o valor';
    }
});

// ============================================================================
// SCRIPTS
// ============================================================================
async function loadOrgScripts(orgId) {
    try {
        const response = await API.get('scripts', { org: orgId });
        if (!response.success) return;
        renderScriptsList(response.data);
    } catch (error) { console.error('Scripts error:', error); }
}

function renderScriptsList(scripts) {
    const list = document.getElementById('scripts-list');
    const filtered = scripts.filter(s => currentScriptTab === 'core' ? s.is_core : !s.is_core);

    if (!filtered.length) {
        list.innerHTML = `<div class="text-center py-8 text-slate-400">Nenhum script ${currentScriptTab === 'core' ? 'core' : 'customizado'} disponível.</div>`;
        return;
    }

    list.innerHTML = filtered.map(script => `
        <div class="flex items-center justify-between p-4 bg-slate-900 rounded-lg mb-2">
            <div class="flex items-center gap-3">
                <div>
                    <span class="font-medium text-white">${Utils.escapeHtml(script.name)}</span>
                    <span class="text-slate-500 text-xs ml-2">${script.filename}</span>
                    ${script.version ? `<span class="text-slate-600 text-xs">v${script.version}</span>` : ''}
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="viewScript(${script.id})" class="text-blue-400 hover:text-blue-300 text-sm">Ver</button>
                ${!script.is_core ? `<button onclick="deleteScript(${script.id})" class="text-red-400 hover:text-red-300 text-sm">Excluir</button>` : ''}
            </div>
        </div>
    `).join('');
}

function switchScriptTab(tab) {
    currentScriptTab = tab;
    document.querySelectorAll('.script-tab').forEach(btn => {
        if (btn.dataset.scriptType === tab) {
            btn.classList.add('bg-blue-600', 'text-white');
            btn.classList.remove('bg-slate-700', 'text-slate-300');
        } else {
            btn.classList.remove('bg-blue-600', 'text-white');
            btn.classList.add('bg-slate-700', 'text-slate-300');
        }
    });
    loadOrgScripts(currentOrgId);
}
window.switchScriptTab = switchScriptTab;

async function viewScript(scriptId) {
    try {
        const response = await API.get('script', { id: scriptId });
        if (response.success) {
            document.getElementById('script-modal-title').textContent = response.data.name;
            document.getElementById('script-edit-id').value = response.data.id;
            document.getElementById('script-name').value = response.data.name;
            document.getElementById('script-description').value = response.data.description || '';
            document.getElementById('script-content').value = response.data.content;
            document.getElementById('script-is-core').value = response.data.is_core ? 'true' : 'false';
            openModal('modal-upload-script');
        }
    } catch (error) { Toast.error('Erro ao carregar script'); }
}
window.viewScript = viewScript;

async function deleteScript(scriptId) {
    if (!confirm('Tem certeza que deseja excluir este script?')) return;
    try {
        const response = await API.delete('script', scriptId);
        if (response.success) {
            Toast.success('Script excluído');
            await loadOrgScripts(currentOrgId);
        } else { Toast.error(response.error || 'Erro ao excluir'); }
    } catch (error) { Toast.error('Erro ao excluir script'); }
}
window.deleteScript = deleteScript;

async function uploadScript(e) {
    e.preventDefault();
    const name = document.getElementById('script-name').value.trim();
    const description = document.getElementById('script-description').value.trim();
    const content = document.getElementById('script-content').value;
    const isCore = document.getElementById('script-is-core').value === 'true';
    const editId = document.getElementById('script-edit-id').value;

    if (!name || !content) { Toast.error('Nome e conteúdo são obrigatórios'); return; }

    try {
        let response;
        if (editId) {
            response = await API.put('script', editId, {
                name, description, content
            });
        } else {
            response = await API.post('script-upload', {
                name, description, content,
                is_core: isCore,
                organization_id: isCore ? null : currentOrgId
            });
        }
        if (response.success) {
            Toast.success(editId ? 'Script atualizado' : 'Script salvo com sucesso');
            closeModal('modal-upload-script');
            document.getElementById('script-form').reset();
            document.getElementById('script-edit-id').value = '';
            await loadOrgScripts(currentOrgId);
            if (isCore) await loadCoreScripts();
        } else { Toast.error(response.error || 'Erro ao salvar script'); }
    } catch (error) { Toast.error('Erro ao salvar script'); }
}

// ============================================================================
// CORE SCRIPTS VIEW
// ============================================================================
async function loadCoreScripts() {
    try {
        const response = await API.get('scripts');
        if (!response.success) return;
        const coreScripts = response.data.filter(s => s.is_core);
        const list = document.getElementById('core-scripts-list');

        if (!coreScripts.length) {
            list.innerHTML = '<div class="text-center py-8 text-slate-400">Nenhum script core cadastrado.</div>';
            return;
        }

        list.innerHTML = coreScripts.map(script => `
            <div class="flex items-center justify-between p-4 bg-slate-900 rounded-lg mb-2">
                <div>
                    <span class="font-medium text-white">${Utils.escapeHtml(script.name)}</span>
                    <span class="text-slate-500 text-xs ml-2">${script.filename}</span>
                </div>
                <button onclick="viewScript(${script.id})" class="text-blue-400 hover:text-blue-300 text-sm">Ver</button>
            </div>
        `).join('');
    } catch (error) { console.error('Core scripts error:', error); }
}

// ============================================================================
// BUNDLE
// ============================================================================
async function loadScriptsForBundle(orgId) {
    try {
        const response = await API.get('scripts', { org: orgId });
        if (!response.success) return;

        const list = document.getElementById('bundle-scripts-list');
        if (!response.data.length) {
            list.innerHTML = '<p class="text-slate-400 text-center py-4">Nenhum script disponível</p>';
            return;
        }

        list.innerHTML = response.data.map((script, index) => `
            <label class="flex items-center gap-3 p-3 bg-slate-900 rounded-lg cursor-pointer hover:bg-slate-800 transition-colors">
                <input type="checkbox" class="bundle-script-checkbox w-5 h-5 rounded bg-slate-700 border-slate-600 text-blue-600" value="${script.id}" ${script.is_core ? 'checked' : ''}>
                <span class="w-6 h-6 bg-slate-700 rounded flex items-center justify-center text-xs text-slate-400">${index + 1}</span>
                <div class="flex-1">
                    <span class="font-medium text-white">${Utils.escapeHtml(script.name)}</span>
                    <span class="text-slate-500 text-xs ml-2">${script.filename}</span>
                </div>
                <span class="px-2 py-1 text-xs rounded ${script.is_core ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-700 text-slate-400'}">${script.is_core ? 'Core' : 'Custom'}</span>
            </label>
        `).join('');
    } catch (error) { console.error('Bundle scripts error:', error); }
}

async function generateBundle() {
    if (!currentOrgId) { Toast.error('Selecione uma organização'); return; }

    const checked = document.querySelectorAll('.bundle-script-checkbox:checked');
    const scriptIds = Array.from(checked).map(cb => parseInt(cb.value));

    if (!scriptIds.length) { Toast.error('Selecione pelo menos um script'); return; }

    try {
        const response = await API.post('generate-bundle', {
            organization_id: currentOrgId,
            script_ids: scriptIds
        });

        if (response.success) {
            Toast.success('Bundle gerado com sucesso!');
            const downloadLink = document.getElementById('download-link');
            downloadLink.href = response.data.download_url;
            downloadLink.classList.remove('hidden');

            if (response.data.warnings && response.data.warnings.length) {
                document.getElementById('bundle-warnings').classList.remove('hidden');
                document.getElementById('bundle-warnings-list').innerHTML = response.data.warnings.map(w => `<li>${Utils.escapeHtml(w)}</li>`).join('');
            } else {
                document.getElementById('bundle-warnings').classList.add('hidden');
            }
        } else {
            Toast.error(response.error || 'Erro ao gerar bundle');
            if (response.data) {
                document.getElementById('bundle-warnings').classList.remove('hidden');
                document.getElementById('bundle-warnings-list').innerHTML = `<li>${Utils.escapeHtml(response.error)}</li>`;
            }
        }
    } catch (error) {
        Toast.error('Erro ao gerar bundle');
    }
}

// ============================================================================
// USERS
// ============================================================================
async function loadUsers() {
    try {
        const response = await API.get('users');
        if (!response.success) return;

        const tbody = document.getElementById('users-tbody');
        const roleLabels = { 'admin': 'Admin', 'admin_gap': 'Admin GAP', 'operador_om': 'Operador', 'auditor': 'Auditor' };

        tbody.innerHTML = response.data.map(user => `
            <tr>
                <td class="px-6 py-4 text-sm text-white font-medium">${Utils.escapeHtml(user.username)}</td>
                <td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(user.full_name || '-')}</td>
                <td class="px-6 py-4"><span class="px-2 py-1 text-xs rounded bg-slate-700 text-slate-300">${roleLabels[user.role] || user.role}</span></td>
                <td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(user.org_acronym || '-')}</td>
                <td class="px-6 py-4"><span class="px-2 py-1 text-xs rounded ${user.is_active ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400'}">${user.is_active ? 'Ativo' : 'Bloqueado'}</span></td>
                <td class="px-6 py-4 text-right">
                    <button onclick="editUser(${user.id})" class="text-blue-400 hover:text-blue-300 text-sm mr-3">Editar</button>
                    <button onclick="toggleUserStatus(${user.id}, ${user.is_active ? 'false' : 'true'})" class="text-amber-400 hover:text-amber-300 text-sm mr-3">${user.is_active ? 'Bloquear' : 'Ativar'}</button>
                    <button onclick="deleteUser(${user.id})" class="text-red-400 hover:text-red-300 text-sm">Excluir</button>
                </td>
            </tr>
        `).join('');

        // Populate org select in user modal
        const orgSelect = document.getElementById('user-organization');
        orgSelect.innerHTML = '<option value="">Nenhuma (admin)</option>' + organizations.map(o => `<option value="${o.id}">${Utils.escapeHtml(o.acronym)} - ${Utils.escapeHtml(o.name)}</option>`).join('');
    } catch (error) { console.error('Users error:', error); }
}

async function editUser(userId) {
    try {
        const response = await API.get('users');
        if (!response.success) return;
        const user = response.data.find(u => u.id === userId);
        if (!user) return;

        document.getElementById('user-edit-id').value = user.id;
        document.getElementById('user-username').value = user.username;
        document.getElementById('user-username').readOnly = true;
        document.getElementById('user-fullname').value = user.full_name || '';
        document.getElementById('user-email').value = user.email || '';
        document.getElementById('user-password').value = '';
        document.getElementById('user-role').value = user.role;
        document.getElementById('user-organization').value = user.organization_id || '';
        document.getElementById('user-modal-title').textContent = 'Editar Usuário';
        document.getElementById('user-password-optional').textContent = '(deixe vazio para manter)';
        openModal('modal-new-user');
    } catch (error) { Toast.error('Erro ao carregar usuário'); }
}
window.editUser = editUser;

async function toggleUserStatus(userId, activate) {
    try {
        const response = await API.put('user', userId, { is_active: activate === 'true' || activate === true });
        if (response.success) {
            Toast.success(activate ? 'Usuário ativado' : 'Usuário bloqueado');
            await loadUsers();
        } else { Toast.error(response.error || 'Erro'); }
    } catch (error) { Toast.error('Erro ao atualizar status'); }
}
window.toggleUserStatus = toggleUserStatus;

async function deleteUser(userId) {
    if (!confirm('Tem certeza que deseja excluir este usuário? Esta ação não pode ser desfeita.')) return;
    try {
        const response = await API.delete('user', userId);
        if (response.success) {
            Toast.success('Usuário excluído');
            await loadUsers();
        } else { Toast.error(response.error || 'Erro ao excluir usuário'); }
    } catch (error) { Toast.error('Erro ao excluir usuário'); }
}
window.deleteUser = deleteUser;

async function saveUser(e) {
    e.preventDefault();
    const editId = document.getElementById('user-edit-id').value;
    const username = document.getElementById('user-username').value;
    const fullName = document.getElementById('user-fullname').value;
    const email = document.getElementById('user-email').value;
    const password = document.getElementById('user-password').value;
    const confirmPassword = document.getElementById('user-confirm-password').value;
    const role = document.getElementById('user-role').value;
    const orgId = document.getElementById('user-organization').value || null;

    // Validate password match for new users
    if (!editId && password !== confirmPassword) {
        Toast.error('As senhas não conferem');
        return;
    }

    // Validate password match when changing password
    if (editId && password && password !== confirmPassword) {
        Toast.error('As senhas não conferem');
        return;
    }

    try {
        let response;
        if (editId) {
            response = await API.put('user', editId, {
                full_name: fullName, email, password: password || undefined,
                role, organization_id: orgId
            });
        } else {
            response = await API.post('users', {
                username, password, full_name: fullName, email, role, organization_id: orgId
            });
        }

        if (response.success) {
            Toast.success(editId ? 'Usuário atualizado' : 'Usuário criado');
            closeModal('modal-new-user');
            document.getElementById('user-form').reset();
            document.getElementById('user-edit-id').value = '';
            document.getElementById('user-username').readOnly = false;
            document.getElementById('user-modal-title').textContent = 'Novo Usuário';
            document.getElementById('user-password-optional').textContent = '(deixe vazio ao editar)';
            await loadUsers();
        } else {
            Toast.error(response.error || 'Erro ao salvar usuário');
        }
    } catch (error) { Toast.error('Erro ao salvar usuário'); }
}

// ============================================================================
// AUDIT
// ============================================================================
async function loadAuditEvents() {
    try {
        const response = await API.get('audit', { limit: 100 });
        if (!response.success) return;

        const tbody = document.getElementById('audit-tbody');
        if (!response.data.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="px-6 py-8 text-center text-slate-400">Nenhum evento de auditoria registrado.</td></tr>';
            return;
        }

        tbody.innerHTML = response.data.map(event => `
            <tr>
                <td class="px-6 py-4 text-sm text-slate-300">${Utils.formatDate(event.created_at)}</td>
                <td class="px-6 py-4 text-sm text-white">${Utils.escapeHtml(event.full_name || event.username || '-')}</td>
                <td class="px-6 py-4 text-sm"><span class="px-2 py-1 text-xs rounded bg-blue-500/20 text-blue-400">${Utils.escapeHtml(event.action)}</span></td>
                <td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(event.entity)}</td>
                <td class="px-6 py-4 text-sm text-slate-300">${Utils.escapeHtml(event.org_acronym || '-')}</td>
                <td class="px-6 py-4 text-sm text-slate-400">${event.details ? Utils.escapeHtml(typeof event.details === 'string' ? event.details : JSON.stringify(event.details)) : '-'}</td>
            </tr>
        `).join('');
    } catch (error) { console.error('Audit error:', error); }
}

// ============================================================================
// ORGANIZATION CRUD
// ============================================================================
async function createOrganization() {
    const name = document.getElementById('new-org-name').value.trim();
    const acronym = document.getElementById('new-org-acronym').value.trim().toUpperCase();
    const domain = document.getElementById('new-org-domain').value.trim();
    const description = document.getElementById('new-org-description').value.trim();

    if (!name || !acronym) { Toast.error('Nome e sigla são obrigatórios'); return; }

    try {
        const response = await API.post('organizations', { name, acronym, domain, description });
        if (response.success) {
            Toast.success('Organização criada com sucesso');
            closeModal('modal-new-org');
            document.getElementById('new-org-form').reset();
            await loadDashboard();
            await loadOrganizations();
            if (response.data && response.data.id) selectOrganization(response.data.id);
        } else { Toast.error(response.error || 'Erro ao criar organização'); }
    } catch (error) { Toast.error('Erro ao criar organização'); }
}

async function updateOrganization() {
    if (!currentOrgId) return;
    const name = document.getElementById('edit-org-name').value.trim();
    const domain = document.getElementById('edit-org-domain').value.trim();
    const description = document.getElementById('edit-org-description').value.trim();

    if (!name) { Toast.error('Nome e obrigatorio'); return; }

    try {
        const data = await API.put('organization', currentOrgId, { name, domain, description });
        if (!data.success) { Toast.error(data.error || 'Erro ao atualizar'); return; }

        Toast.success('Organizacao atualizada com sucesso');
        closeModal('modal-edit-org');
        await loadDashboard();
        await loadOrganizations();
        // Update display
        document.getElementById('om-display-name').textContent = name;
        document.getElementById('om-display-domain').textContent = domain || 'Sem dominio configurado';
    } catch (error) { Toast.error('Erro ao atualizar organizacao'); }
}

// Handle edit org button
document.getElementById('btn-edit-org')?.addEventListener('click', () => {
    if (!currentOrgId) return;
    openModal('modal-edit-org');
});

// Form submit for edit org
document.getElementById('edit-org-form')?.addEventListener('submit', (e) => {
    e.preventDefault();
    updateOrganization();
});

function deleteOrganization() {
    if (!currentOrgId) return;
    if (!confirm('Tem certeza que deseja excluir esta organizacao? Esta acao nao pode ser desfeita.')) return;

    (async () => {
        try {
            const response = await API.delete('organization', currentOrgId);
            if (response.success) {
                Toast.success('Organizacao excluida');
                currentOrgId = null;
                document.getElementById('view-om-detail').classList.add('hidden');
                document.getElementById('view-dashboard').classList.remove('hidden');
                await loadDashboard();
                await loadOrganizations();
            } else {
                Toast.error(response.error || 'Erro ao excluir');
            }
        } catch (error) { Toast.error('Erro ao excluir organizacao'); }
    })();
}

    // Validate file type
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        Toast.error('Tipo de arquivo invalido. Use JPG, PNG, GIF ou WebP');
        e.target.value = '';
        return;
    }

    // Validate size (5MB max)
    if (file.size > 5 * 1024 * 1024) {
        Toast.error('Arquivo muito grande. Maximo 5MB');
        e.target.value = '';
        return;
    }

    // Show preview immediately
    const preview = document.getElementById('wallpaper-preview');
    const reader = new FileReader();
    reader.onload = function(ev) {
        preview.innerHTML = `<img src="${ev.target.result}" alt="Preview" class="w-full h-full object-cover">`;
    };
    reader.readAsDataURL(file);

    // Upload via API
    const formData = new FormData();
    formData.append('wallpaper', file);
    formData.append('organization_id', currentOrgId);

    try {
        const response = await fetch('/api/?action=upload-wallpaper', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });
        const data = await response.json();

        if (data.success) {
            Toast.success('Wallpaper enviado com sucesso');
            document.getElementById('cfg-wallpaper-url').value = data.data.url;
            await loadVariables(currentOrgId);
        } else {
            Toast.error(data.error || 'Erro ao enviar wallpaper');
        }
    } catch (error) {
        Toast.error('Erro ao enviar wallpaper');
    }

    e.target.value = '';
}
window.handleWallpaperUpload = handleWallpaperUpload;

async function deleteOrganization() {
    if (!currentOrgId) return;
    if (!confirm('Tem certeza que deseja excluir esta organização? Esta ação não pode ser desfeita.')) return;

    try {
        const data = await API.delete('organization', currentOrgId);
        if (data.success) {
            Toast.success('Organização excluída');
            showView('dashboard');
            await loadDashboard();
            await loadOrganizations();
        } else { Toast.error(data.error || 'Erro ao excluir'); }
    } catch (error) { Toast.error('Erro ao excluir organização'); }
}

// ============================================================================
// TABS
// ============================================================================
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

// ============================================================================
// MODALS
// ============================================================================
function openModal(modalId) {
    document.getElementById(modalId).classList.remove('hidden');
}
function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
}
window.closeModal = closeModal;

// ============================================================================
// EVENT LISTENERS
// ============================================================================
function setupEventListeners() {
    // New OM
    document.getElementById('btn-new-org').addEventListener('click', () => openModal('modal-new-org'));
    document.getElementById('new-org-form').addEventListener('submit', (e) => { e.preventDefault(); createOrganization(); });

    // Save variables
    document.getElementById('btn-save-vars').addEventListener('click', saveVariables);

    // Add variable
    document.getElementById('btn-add-variable').addEventListener('click', () => openModal('modal-add-variable'));
    document.getElementById('add-variable-form').addEventListener('submit', addVariable);

    // Logout
    document.getElementById('btn-logout').addEventListener('click', async () => {
        try { await API.post('logout'); } catch (e) {}
        window.location.href = '/login.html';
    });

    // Generate bundle
    document.getElementById('btn-generate-bundle').addEventListener('click', generateBundle);

    // Upload script
    document.getElementById('btn-upload-script').addEventListener('click', () => {
        document.getElementById('script-form').reset();
        document.getElementById('script-is-core').value = 'false';
        document.getElementById('script-modal-title').textContent = 'Upload Script Customizado';
        openModal('modal-upload-script');
    });
    document.getElementById('script-form').addEventListener('submit', uploadScript);

    // New core script
    const btnNewCore = document.getElementById('btn-new-core-script');
    if (btnNewCore) {
        btnNewCore.addEventListener('click', () => {
            document.getElementById('script-form').reset();
            document.getElementById('script-is-core').value = 'true';
            document.getElementById('script-modal-title').textContent = 'Novo Script Core';
            openModal('modal-upload-script');
        });
    }

    // Users
    document.getElementById('btn-new-user').addEventListener('click', () => {
        document.getElementById('user-form').reset();
        document.getElementById('user-edit-id').value = '';
        document.getElementById('user-username').readOnly = false;
        document.getElementById('user-modal-title').textContent = 'Novo Usuário';
        document.getElementById('user-password-optional').textContent = '';
        openModal('modal-new-user');
    });
    document.getElementById('user-form').addEventListener('submit', saveUser);

    // Modal close (all modals)
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.fixed.inset-0').classList.add('hidden'));
    });
    document.querySelectorAll('.modal-backdrop').forEach(btn => {
        btn.addEventListener('click', (e) => {
            if (e.target === btn) btn.closest('.fixed.inset-0').classList.add('hidden');
        });
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') document.querySelectorAll('.fixed.inset-0:not(.hidden)').forEach(m => m.classList.add('hidden'));
    });
}
