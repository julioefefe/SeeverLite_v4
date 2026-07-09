let currentUser = null, currentOrgId = null, organizations = [], allVariables = [], activeCategory = 'Todas';

const API = {
    get: async (action, params = {}) => { const url = new URL('/api/', location.origin); url.searchParams.set('action', action); Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v)); return (await fetch(url, {credentials: 'same-origin'})).json(); },
    post: async (action, data) => { const url = new URL('/api/', location.origin); url.searchParams.set('action', action); return (await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(data), credentials: 'same-origin'})).json(); }
};

const Utils = { escapeHtml: str => str?.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"})[m]) || '', formatDate: d => d ? new Date(d).toLocaleString('pt-BR') : '-' };
const Toast = { show: (msg, type = 'success') => { const div = document.createElement('div'); div.className = `toast ${type}`; div.textContent = msg; document.getElementById('toast-container').appendChild(div); setTimeout(() => div.remove(), 4000); }, success: msg => Toast.show(msg, 'success'), error: msg => Toast.show(msg, 'error') };

const categoryLabels = {'dominio':'Dominio','rede':'Rede','proxy':'Proxy','inventario':'Inventario','generic':'Geral','branding':'Identidade'};
const variableOptions = { 'PROXY_MODE': ['NONE', 'MANUAL', 'PAC'], 'OFFLINE_AUTH_ENABLED': 'boolean', 'INVENTORY_ENABLED': 'boolean' };

document.addEventListener('DOMContentLoaded', async () => {
    const session = await API.get('session');
    if (!session.success) { location.href = '/login.html'; return; }
    currentUser = session.data;
    document.getElementById('user-name').textContent = currentUser.username;
    document.getElementById('user-role').textContent = {admin_gap:'Admin GAP',operador_om:'Operador OM'}[currentUser.role] || currentUser.role;
    if (currentUser.role !== 'admin_gap') document.getElementById('btn-new-org')?.classList.add('hidden');
    await loadDashboard(); await loadOrganizations(); setupEvents();
});

async function loadDashboard() {
    const res = await API.get('dashboard');
    if (res.success) {
        document.getElementById('dash-orgs').textContent = res.data.organizations;
        document.getElementById('dash-scripts').textContent = res.data.scripts;
        document.getElementById('dash-vars').textContent = res.data.variables;
    }
}

async function loadOrganizations() {
    const res = await API.get('organizations');
    if (!res.success) return;
    organizations = res.data;
    const el = document.getElementById('om-list');
    el.innerHTML = organizations.map(o => `<button class="nav-item w-full flex items-center gap-3 px-3 py-2 rounded-lg text-slate-300 hover:bg-slate-700 text-left" data-org-id="${o.id}" onclick="selectOrg(${o.id})"><div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-emerald-500 flex items-center justify-center text-white font-bold text-sm">${o.logo_url?`<img src="${Utils.escapeHtml(o.logo_url)}" class="w-full h-full object-cover rounded" onerror="this.textContent='${o.acronym.substring(0,3)}'">`:o.acronym.substring(0,3)}</div><div><span class="font-medium">${Utils.escapeHtml(o.acronym)}</span><span class="block text-xs text-slate-500">${Utils.escapeHtml(o.name)}</span></div></button>`).join('') || '<p class="text-slate-500 text-sm">Nenhuma OM</p>';
}

async function selectOrg(orgId) {
    currentOrgId = orgId;
    const org = organizations.find(o => o.id === orgId);
    if (!org) return;
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active', 'bg-slate-700'));
    document.querySelector(`.nav-item[data-org-id="${orgId}"]`)?.classList.add('active', 'bg-slate-700');
    document.getElementById('page-title').textContent = org.acronym;
    document.getElementById('page-subtitle').textContent = org.name;
    document.getElementById('view-dashboard').classList.add('hidden');
    document.getElementById('view-om-detail').classList.remove('hidden');
    document.getElementById('om-display-name').textContent = org.name;
    switchTab('variables');
    await loadVariables(orgId);
}
window.selectOrg = selectOrg;

async function loadVariables(orgId) {
    const res = await API.get('variables', {id: orgId});
    if (!res.success) { Toast.error(res.error || 'Erro'); return; }
    allVariables = res.data.variables || [];
    activeCategory = 'Todas';
    renderVariables(allVariables);
}

function renderVariables(vars) {
    const el = document.getElementById('vars-list');
    if (!vars.length) { el.innerHTML = '<p class="text-slate-400 text-center py-8">Nenhuma variavel</p>'; return; }
    const cats = [...new Set(vars.map(v => v.category || 'generic'))];
    let html = '<div class="category-tabs"><button class="cat-tab ' + (activeCategory==='Todas'?'active':'') + '" onclick="filterCat(\'Todas\')">Todas</button>';
    cats.forEach(c => html += `<button class="cat-tab ${activeCategory===c?'active':''}" onclick="filterCat('${c}')">${categoryLabels[c]||c}</button>`);
    html += '</div>';

    let filtered = activeCategory === 'Todas' ? vars : vars.filter(v => v.category === activeCategory);
    html += '<div class="grid lg:grid-cols-2 gap-4 mt-4">';
    filtered.forEach(v => html += renderVarRow(v));
    html += '</div>';
    el.innerHTML = html;
}

function renderVarRow(v) {
    const input = renderTypedInput(v);
    return `<div class="var-row"><label class="block text-sm font-medium text-slate-300 mb-1">${Utils.escapeHtml(v.name)}${v.is_required?'<span class="text-red-400">*</span>':''}</label>${input}${v.description?`<p class="text-slate-500 text-xs mt-1">${Utils.escapeHtml(v.description)}</p>`:''}</div>`;
}

function renderTypedInput(v) {
    const val = v.current_value || '', varId = v.id;
    const opts = variableOptions[v.name];

    if (opts === 'boolean' || v.type === 'boolean') {
        const checked = val === 'true' || val === '1';
        return `<label class="inline-flex items-center cursor-pointer"><input type="checkbox" data-var-id="${varId}" ${checked?'checked':''} class="sr-only peer"><div class="w-11 h-6 bg-slate-700 rounded-full peer peer-checked:bg-emerald-600 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full relative"></div><span class="ml-2 text-sm">${checked?'Ativo':'Inativo'}</span></label>`;
    }
    if (Array.isArray(opts)) return `<select data-var-id="${varId}" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">${opts.map(o => `<option value="${o}" ${val===o?'selected':''}>${o}</option>`).join('')}</select>`;
    if (v.name.includes('URL')) return `<input type="url" data-var-id="${varId}" value="${Utils.escapeHtml(val)}" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">`;
    return `<input type="text" data-var-id="${varId}" value="${Utils.escapeHtml(val)}" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white text-sm">`;
}

function filterCat(c) { activeCategory = c; renderVariables(allVariables); }
window.filterCat = filterCat;

async function saveVariables() {
    if (!currentOrgId) return;
    const updates = {};
    document.querySelectorAll('[data-var-id]').forEach(el => updates[el.dataset.varId] = el.type === 'checkbox' ? (el.checked ? 'true' : 'false') : el.value);
    const res = await API.post('variables-update', {organization_id: currentOrgId, variables: updates});
    if (res.success) { Toast.success('Salvo'); loadVariables(currentOrgId); } else Toast.error(res.error);
}
window.saveVariables = saveVariables;

function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active', 'border-blue-500', 'text-blue-400'));
    document.querySelector(`.tab-btn[data-tab="${name}"]`)?.classList.add('active', 'border-blue-500', 'text-blue-400');
    document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
    document.getElementById('tab-' + name)?.classList.remove('hidden');
}
window.switchTab = switchTab;

async function generateBundle() {
    if (!currentOrgId) return;
    Toast.show('Gerando bundle...', 'success');
    const res = await API.post('bundle', {organization_id: currentOrgId});
    if (res.success && res.data.download_url) location.href = res.data.download_url;
    else Toast.error(res.error || 'Erro');
}
window.generateBundle = generateBundle;

function openModal(id) { document.getElementById(id)?.classList.remove('hidden'); }
window.openModal = openModal;
function closeModal(id) { document.getElementById(id)?.classList.add('hidden'); }
window.closeModal = closeModal;

function setupEvents() {
    document.getElementById('btn-new-org')?.addEventListener('click', () => openModal('modal-new-org'));
    document.getElementById('btn-save-vars')?.addEventListener('click', saveVariables);
    document.getElementById('btn-generate-bundle')?.addEventListener('click', generateBundle);
    document.getElementById('btn-logout')?.addEventListener('click', async () => { await API.post('logout'); location.href = '/login.html'; });
    document.querySelectorAll('.modal-close').forEach(b => b.addEventListener('click', () => b.closest('.fixed')?.classList.add('hidden')));
}
