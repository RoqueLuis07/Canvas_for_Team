/* ── Theme toggle ── */
function initThemeToggle() {
  const toggle = document.getElementById('themeToggle');
  const html   = document.documentElement;
  const saved  = localStorage.getItem('theme') || 'light';

  function applyTheme(theme) {
    if (theme === 'dark') {
      html.classList.add('dark-mode');
      html.classList.remove('light-mode');
      if (toggle) { toggle.innerHTML = '<i class="bi bi-sun-fill"></i>'; toggle.title = 'Modo claro'; }
    } else {
      html.classList.remove('dark-mode');
      html.classList.add('light-mode');
      if (toggle) { toggle.innerHTML = '<i class="bi bi-moon-fill"></i>'; toggle.title = 'Modo oscuro'; }
    }
  }

  applyTheme(saved);

  if (toggle) {
    toggle.addEventListener('click', () => {
      const newTheme = html.classList.contains('dark-mode') ? 'light' : 'dark';
      applyTheme(newTheme);
      localStorage.setItem('theme', newTheme);
    });
  }

  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
    if (!localStorage.getItem('theme')) applyTheme(e.matches ? 'dark' : 'light');
  });
}

/* ── Sidebar toggle ── */
function toggleSidebar() {
  const sb       = document.getElementById('sidebar');
  const mc       = document.getElementById('main-content');
  const backdrop = document.getElementById('sidebar-backdrop');
  const isMobile = window.innerWidth <= 768;

  if (isMobile) {
    sb.classList.toggle('mobile-open');
    backdrop.classList.toggle('active');
  } else {
    sb.classList.toggle('collapsed');
    mc.classList.toggle('expanded');
  }
}

/* ── Sidebar accordion groups (por zona/departamento) ──
   Cada sección (Directorio & Ingresos, Gestión Académica, etc.) se puede
   colapsar. El estado se recuerda en localStorage entre navegaciones (la
   app es multi-página, no SPA, así que Bootstrap Collapse por sí solo no
   persiste nada) — pero la sección que contiene la página actual siempre
   se fuerza abierta, para que nunca quede escondida la entrada de menú de
   donde estás parado. */
function initSidebarGroups() {
  document.querySelectorAll('.sidebar-group').forEach(group => {
    const id = group.id;
    const btn = document.querySelector(`[data-bs-target="#${id}"]`);
    if (!btn) return;

    const hasActive = group.querySelector('.sidebar-link.active, .sidebar-sublink.active');
    const saved = localStorage.getItem('sidebarGroup:' + id);
    const shouldExpand = !!hasActive || saved === null || saved === 'true';

    if (shouldExpand) {
      group.classList.add('show');
      btn.setAttribute('aria-expanded', 'true');
    } else {
      group.classList.remove('show');
      btn.setAttribute('aria-expanded', 'false');
    }

    group.addEventListener('shown.bs.collapse', () => localStorage.setItem('sidebarGroup:' + id, 'true'));
    group.addEventListener('hidden.bs.collapse', () => localStorage.setItem('sidebarGroup:' + id, 'false'));
  });
}

// Init on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initThemeToggle);
  document.addEventListener('DOMContentLoaded', initSidebarGroups);
} else {
  initThemeToggle();
  initSidebarGroups();
}

/* ── Copy to clipboard ── */
function copyToClipboard(text, label = 'ID') {
  navigator.clipboard.writeText(String(text)).then(() => {
    toast(`${label} copiado`, 'success');
  }).catch(() => {
    toast(`Error al copiar ${label.toLowerCase()}`, 'danger');
  });
}

/* ── Global search ── */
let _gsearchTimeout;
async function onGlobalSearch(q) {
  clearTimeout(_gsearchTimeout);
  const resultsEl = document.getElementById('gsearch-results');
  if (!q || q.length < 3) { resultsEl.style.display = 'none'; return; }

  _gsearchTimeout = setTimeout(async () => {
    try {
      const [users, courses] = await Promise.all([
        api.get(`/canvas/users?search=${encodeURIComponent(q)}&per_page=5`).catch(() => []),
        api.get(`/canvas/courses?search=${encodeURIComponent(q)}&per_page=5`).catch(() => []),
      ]);

      const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

      const html = [
        ...users.map(u => `
          <a href="/ui/unified-users?q=${encodeURIComponent(u.login_id || u.email || '')}" class="gs-item">
            <i class="bi bi-person text-muted"></i>
            <div>
              <div class="gs-label">${esc(u.name)}</div>
              <div class="gs-sub">${esc(u.login_id || u.email || '—')}</div>
            </div>
            <span class="gs-type bg-primary">Usuario</span>
          </a>`),
        ...courses.map(c => `
          <a href="/ui/canvas/courses" class="gs-item">
            <i class="bi bi-book text-muted"></i>
            <div>
              <div class="gs-label">${esc(c.name)}</div>
              <div class="gs-sub">${esc(c.course_code || '')}</div>
            </div>
            <span class="gs-type bg-success">Curso</span>
          </a>`),
      ].join('');

      resultsEl.innerHTML = html || '<div class="gs-item text-muted small">Sin resultados</div>';
      resultsEl.style.display = 'block';
    } catch (e) {
      console.error('Error en búsqueda global:', e);
      resultsEl.innerHTML = '<div class="gs-item text-danger small">Error en búsqueda</div>';
      resultsEl.style.display = 'block';
    }
  }, 500);
}

/* ── API helpers ── */
// Timeout del lado del cliente para toda llamada a la API: el backend ya
// tiene sus propios timeouts (10-12s en /stats, /health y la capa de DB),
// pero sin esto cualquier endpoint que no pase por ahí (o un corte de red)
// deja la pantalla en spinner/skeleton indefinido en vez de mostrar un
// error reintentable. 15s le da margen a que el backend resuelva solo
// primero con su propio mensaje de error.
const API_TIMEOUT_MS = 15000;

async function apiCall(method, url, data = null) {
  const opts = { method, headers: { 'Content-Type': 'application/json' } };
  if (data) opts.body = JSON.stringify(data);

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), API_TIMEOUT_MS);
  opts.signal = controller.signal;

  let r;
  try {
    r = await fetch(url, opts);
  } catch (e) {
    if (e.name === 'AbortError') {
      throw new Error('Tiempo de espera agotado. Reintentá en unos segundos.');
    }
    throw new Error('Error de conexión. Verificá que el servicio está activo.');
  } finally {
    clearTimeout(timer);
  }

  if (!r.ok) {
    let detail;
    try {
      const err = await r.json();
      detail = err.detail;
      if (Array.isArray(detail)) {
        detail = detail.map(e => {
          const field = (e.loc || []).slice(1).join('.');
          return field ? `${field}: ${e.msg}` : e.msg;
        }).join(' | ');
      } else if (detail && typeof detail === 'object') {
        detail = JSON.stringify(detail);
      }
    } catch { detail = null; }

    const httpMsg = {
      400: 'Solicitud inválida',
      401: 'No autenticado — iniciá sesión',
      403: 'Acceso denegado',
      404: 'Recurso no encontrado',
      409: 'Conflicto: el recurso ya existe',
      422: 'Datos de entrada inválidos',
      429: 'Demasiadas solicitudes — esperá un momento',
      500: 'Error interno del servidor',
      502: 'Servidor no disponible',
      503: 'Servicio temporalmente no disponible',
    };
    const errMsg = detail || httpMsg[r.status] || `Error ${r.status}: ${r.statusText}`;
    // Log API errors to the error panel
    if (typeof logError === 'function') {
      logError(errMsg, {
        type:     `HTTP ${r.status}`,
        severity: 'api',
        source:   `${method} ${url}`,
      });
    }
    throw new Error(errMsg);
  }

  return r.status === 204 ? {} : r.json();
}

const api = {
  get:      (u)    => apiCall('GET',    u),
  post:     (u, d) => apiCall('POST',   u, d),
  put:      (u, d) => apiCall('PUT',    u, d),
  patch:    (u, d) => apiCall('PATCH',  u, d),
  del:      (u)    => apiCall('DELETE', u),
  del_body: (u, d) => apiCall('DELETE', u, d),
};

/* ── Toast notifications ── */
function toast(msg, type = 'success') {
  const c   = document.getElementById('toast-container');
  const id  = 'toast-' + Date.now();
  const icons = {
    success: 'check-circle-fill',
    danger:  'x-circle-fill',
    warning: 'exclamation-triangle-fill',
    info:    'info-circle-fill'
  };
  c.insertAdjacentHTML('beforeend', `
    <div id="${id}" class="toast align-items-center text-bg-${type} border-0" role="alert" aria-live="assertive">
      <div class="d-flex">
        <div class="toast-body"><i class="bi bi-${icons[type] || 'info-circle-fill'} me-2"></i>${msg}</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
      </div>
    </div>`);
  const el = document.getElementById(id);
  new bootstrap.Toast(el, { delay: 4000 }).show();
  el.addEventListener('hidden.bs.toast', () => el.remove());
}

/* ── Button loading state ── */
function setLoading(btn, on) {
  if (on) {
    btn._orig    = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Procesando...';
    btn.disabled  = true;
  } else {
    btn.innerHTML = btn._orig;
    btn.disabled  = false;
  }
}

/* ── Global modal ── */
const gModal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('globalModal'));

function showModal(title, bodyHtml, footerHtml = '') {
  document.getElementById('globalModalTitle').textContent = title;
  document.getElementById('globalModalBody').innerHTML    = bodyHtml;
  document.getElementById('globalModalFooter').innerHTML  = footerHtml;
  gModal().show();
}

function closeModal() { gModal().hide(); }

/* ── Confirm dialog (dedicated modal for better UX) ── */
function confirmAction(msg, cb, title = '¿Confirmar acción?', danger = false) {
  const modal   = bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmModal'));
  const titleEl = document.getElementById('confirmTitle');
  const msgEl   = document.getElementById('confirmMsg');
  const okBtn   = document.getElementById('confirmOkBtn');
  const iconEl  = document.getElementById('confirmIcon');

  if (titleEl) titleEl.textContent = title;
  if (msgEl) {
    msgEl.textContent = msg;
    msgEl.style.textAlign = msg.includes('\n') ? 'left' : '';
  }
  if (iconEl)  iconEl.innerHTML    = danger
    ? '<i class="bi bi-exclamation-triangle-fill" style="color:#e74a3b"></i>'
    : '<i class="bi bi-question-circle-fill" style="color:#4e73df"></i>';
  if (okBtn) {
    okBtn.className = `btn px-4 ${danger ? 'btn-danger' : 'btn-primary'}`;
    const handler = () => {
      modal.hide();
      okBtn.removeEventListener('click', handler);
      cb();
    };
    okBtn.replaceWith(okBtn.cloneNode(true));  // remove old listeners
    document.getElementById('confirmOkBtn').addEventListener('click', handler);
  }

  modal.show();
}

/* ── Bulk delete with double confirmation ── */
const _bulkDeleteHandlers = {};

function registerBulkDelete(containerId, handler) {
  _bulkDeleteHandlers[containerId] = handler;
}

function bulkDelete(containerId) {
  const handler = _bulkDeleteHandlers[containerId];
  if (!handler) {
    toast('El borrado masivo no está configurado para esta tabla.', 'danger');
    return;
  }
  handler(getSelectedIds(containerId));
}

function bulkDeleteConfirm(containerId) {
  const ids   = getSelectedIds(containerId);
  const count = ids.length;
  if (count === 0) return;
  confirmAction(
    `Se eliminarán ${count} elemento${count !== 1 ? 's' : ''} permanentemente. Esta acción no se puede deshacer.`,
    () => bulkDelete(containerId),
    `Eliminar ${count} elemento${count !== 1 ? 's' : ''}`,
    true
  );
}

/* ── Export table to Excel ── */
function exportTableToExcel(containerId, filename) {
  const d = _tableData[containerId];
  if (!d || !d.rows || d.rows.length === 0) {
    toast('No hay datos para exportar', 'warning');
    return;
  }
  const rows = d.rows.map(row => {
    const obj = {};
    d.cols.forEach(c => { obj[c.label] = row[c.key] ?? ''; });
    return obj;
  });
  const ws = XLSX.utils.json_to_sheet(rows);
  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Datos');
  XLSX.writeFile(wb, filename || 'export.xlsx');
  toast(`${rows.length} registros exportados`, 'success');
}

/* ── Build table ── */
const _tableData = {};

function buildTable(containerId, rows, cols, actions = '', selectable = false, idKey = 'id') {
  _tableData[containerId] = { rows: [...rows], cols, actions, sortKey: null, sortAsc: true, selectable, idKey };
  _updateBulkBar(containerId, 0);
  _renderTable(containerId);
}

function _renderTable(containerId) {
  const { rows, cols, actions, sortKey, sortAsc, selectable, idKey } = _tableData[containerId];
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  const sorted = sortKey
    ? [...rows].sort((a, b) => {
        const av = String(a[sortKey] ?? '').toLowerCase();
        const bv = String(b[sortKey] ?? '').toLowerCase();
        return sortAsc ? av.localeCompare(bv, 'es') : bv.localeCompare(av, 'es');
      })
    : rows;

  const arrow = key => key !== sortKey
    ? '<i class="bi bi-arrow-down-up ms-1" style="font-size:.6rem;opacity:.4"></i>'
    : (sortAsc
        ? '<i class="bi bi-sort-alpha-down ms-1" style="font-size:.65rem"></i>'
        : '<i class="bi bi-sort-alpha-up ms-1" style="font-size:.65rem"></i>');

  const chkHead = selectable
    ? `<th style="width:36px"><input type="checkbox" id="selAll_${containerId}" title="Seleccionar todo"
         onchange="toggleSelectAll('${containerId}',this.checked)" aria-label="Seleccionar todo"></th>`
    : '';

  const thead = chkHead
    + cols.map(c =>
        `<th style="cursor:pointer;user-select:none;white-space:nowrap"
             onclick="sortTable('${containerId}','${c.key}')">${c.label}${arrow(c.key)}</th>`
      ).join('')
    + (actions ? '<th style="width:1%">Acciones</th>' : '');

  const colSpan = cols.length + (actions ? 1 : 0) + (selectable ? 1 : 0);

  const tbody = sorted.length === 0
    ? `<tr><td colspan="${colSpan}">
         <div class="empty-state">
           <i class="bi bi-inbox"></i>
           <p>Sin datos disponibles</p>
         </div>
       </td></tr>`
    : sorted.map(row => {
        const rowId   = esc(String(row[idKey] ?? ''));
        const chkCell = selectable
          ? `<td><input type="checkbox" class="row-sel" data-cid="${containerId}"
               data-rid="${rowId}" onchange="_onRowSelect('${containerId}')"
               aria-label="Seleccionar fila"></td>`
          : '';
        const cells = cols.map(c => `<td>${row[c.key] ?? '—'}</td>`).join('');
        return `<tr>${chkCell}${cells}${actions ? `<td>${actions(row)}</td>` : ''}</tr>`;
      }).join('');

  document.getElementById(containerId).innerHTML = `
    <table class="table table-hover mb-0">
      <thead><tr>${thead}</tr></thead>
      <tbody>${tbody}</tbody>
    </table>`;
}

function sortTable(containerId, key) {
  const s = _tableData[containerId];
  s.sortAsc = s.sortKey === key ? !s.sortAsc : true;
  s.sortKey = key;
  _renderTable(containerId);
}

/* ── Row selection ── */
function getSelectedIds(containerId) {
  return [...document.querySelectorAll(`.row-sel[data-cid="${containerId}"]:checked`)]
    .map(cb => String(cb.dataset.rid));
}

function toggleSelectAll(containerId, checked) {
  document.querySelectorAll(`.row-sel[data-cid="${containerId}"]`)
    .forEach(cb => { cb.checked = checked; });
  _onRowSelect(containerId);
}

function clearSelection(containerId) {
  if (!containerId) return;
  document.querySelectorAll(`.row-sel[data-cid="${containerId}"]`)
    .forEach(cb => { cb.checked = false; });
  const allCb = document.getElementById(`selAll_${containerId}`);
  if (allCb) allCb.checked = false;
  _updateBulkBar(containerId, 0);
}

function _onRowSelect(containerId) {
  const count = getSelectedIds(containerId).length;
  const badge = document.getElementById(`selBadge_${containerId}`);
  if (badge) {
    badge.textContent = count > 0 ? `${count} seleccionado${count !== 1 ? 's' : ''}` : '';
    badge.style.display = count > 0 ? '' : 'none';
  }
  _updateBulkBar(containerId, count);
}

/* ── Floating bulk-action bar ── */
function _updateBulkBar(containerId, count) {
  const bar = document.getElementById('bulk-bar');
  if (!bar) return;
  if (count > 0) {
    bar.style.display = 'flex';
    bar.dataset.container = containerId;
    document.getElementById('bulk-count').textContent =
      `${count} seleccionado${count !== 1 ? 's' : ''}`;
  } else {
    bar.style.display = 'none';
  }
}

/* ── Local search filter ── */
function filterTable(inputId, tableContainer) {
  const q = document.getElementById(inputId).value.toLowerCase();
  let shown = 0;
  document.querySelectorAll(`#${tableContainer} tbody tr:not(.no-data-row)`).forEach(tr => {
    const match = tr.textContent.toLowerCase().includes(q);
    tr.style.display = match ? '' : 'none';
    if (match) shown++;
  });
  const noData = document.querySelector(`#${tableContainer} .no-data-row`);
  if (shown === 0) {
    if (!noData) {
      const tbody = document.querySelector(`#${tableContainer} tbody`);
      const cols  = tbody.querySelector('tr')?.cells.length || 1;
      tbody.insertAdjacentHTML('beforeend',
        `<tr class="no-data-row"><td colspan="${cols}" class="text-center text-muted py-3">
           <i class="bi bi-search me-2"></i>Sin resultados
         </td></tr>`);
    }
  } else if (noData) {
    noData.remove();
  }
}

/* ── Excel drop zone ── */
function initDropZone(zoneId, fileInputId, previewCb) {
  const zone = document.getElementById(zoneId);
  const inp  = document.getElementById(fileInputId);
  if (!zone || !inp) return;

  zone.addEventListener('click', () => inp.click());
  zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
  zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
  zone.addEventListener('drop', e => {
    e.preventDefault(); zone.classList.remove('dragover');
    if (e.dataTransfer.files[0]) { inp.files = e.dataTransfer.files; previewCb(e.dataTransfer.files[0]); }
  });
  inp.addEventListener('change', () => { if (inp.files[0]) previewCb(inp.files[0]); });
}

/* ── Read Excel client-side ── */
function readExcel(file, cb) {
  const reader = new FileReader();
  reader.onload = e => {
    const wb = XLSX.read(e.target.result, { type: 'binary' });
    const ws = wb.Sheets[wb.SheetNames[0]];
    cb(XLSX.utils.sheet_to_json(ws, { defval: '' }));
  };
  reader.readAsBinaryString(file);
}

/* ── Upload Excel to server ── */
async function uploadExcel(url, fileInputId) {
  const inp = document.getElementById(fileInputId);
  if (!inp.files[0]) throw new Error('Seleccioná un archivo Excel primero');
  const fd = new FormData();
  fd.append('file', inp.files[0]);
  const r = await fetch(url, { method: 'POST', body: fd });
  if (!r.ok) {
    const e = await r.json().catch(() => ({}));
    throw new Error(e.detail || r.statusText);
  }
  return r.json();
}

/* ── Render bulk result ── */
function renderBulkResult(result, containerId) {
  const ok  = result.succeeded || [];
  const err = result.failed    || [];
  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const html = `
    <div class="d-flex gap-2 mb-3">
      <span class="badge bg-success fs-6"><i class="bi bi-check-circle me-1"></i>${ok.length} exitosos</span>
      <span class="badge bg-danger  fs-6"><i class="bi bi-x-circle me-1"></i>${err.length} fallidos</span>
    </div>
    ${err.length ? `<div class="alert alert-warning p-2" style="max-height:200px;overflow:auto;font-size:.82rem">
      ${err.map(e => `<div class="py-1 border-bottom">${esc(JSON.stringify(e.input || e))} — <span class="text-danger">${esc(e.error)}</span></div>`).join('')}
    </div>` : ''}`;
  if (containerId) document.getElementById(containerId).innerHTML = html;
  return html;
}

/* ══════════════════════════════════════════════════════════════
   Web Spreadsheet  —  planilla de carga conjunta en el navegador
   Reemplaza la importación por Excel: el usuario escribe o pega
   datos directamente (Ctrl+V desde Excel/Sheets funciona).
   ══════════════════════════════════════════════════════════════ */

let _ss = null; // estado activo de la planilla

/**
 * Abre la planilla modal de carga conjunta.
 *
 * @param {object} cfg
 *   title    {string}   Título del modal
 *   columns  {Array}    Columnas: { key, label, required, width, type, options, placeholder }
 *   onImport {async fn} Recibe rows[] y devuelve { succeeded, failed }
 *   initRows {number}   Filas vacías iniciales (default 20)
 */
function showSpreadsheet(cfg) {
  _ss = { cfg, focusRow: 0, focusCol: 0 };

  const heads = cfg.columns.map(c =>
    `<th class="ss-th${c.required ? ' ss-th-req' : ''}" style="min-width:${c.width || 120}px">${c.label}</th>`
  ).join('');

  // Ampliar modal a XL
  const dlg = document.querySelector('#globalModal .modal-dialog');
  if (dlg) dlg.className = 'modal-dialog modal-xl modal-dialog-scrollable';

  showModal(cfg.title, `
    <div class="ss-wrap">
      <div class="ss-toolbar">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="_ssAddRows(10)">
          <i class="bi bi-plus me-1"></i>+10 filas
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="_ssClearAll()">
          <i class="bi bi-eraser me-1"></i>Limpiar
        </button>
        <span class="ss-paste-hint">
          <i class="bi bi-clipboard-check"></i>
          Pegá datos de Excel / Google Sheets con <kbd>Ctrl+V</kbd>
        </span>
        <span id="ss-row-count" class="ms-auto text-muted small fw-semibold"></span>
      </div>

      <div class="ss-grid-wrap" id="ss-grid-wrap">
        <table class="ss-table" id="ss-table">
          <thead>
            <tr>
              <th class="ss-th-num">#</th>
              ${heads}
              <th class="ss-th-status">Estado</th>
            </tr>
          </thead>
          <tbody id="ss-body"></tbody>
        </table>
      </div>

      <div id="ss-results"></div>
    </div>`,
    `<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
     <button type="button" class="btn btn-info px-3 text-white" id="ss-validate-btn" onclick="_ssDoValidate()">
       <i class="bi bi-magic me-1"></i>Validar datos
     </button>
     <button type="button" class="btn btn-primary px-4" id="ss-import-btn" onclick="_ssDoImport()">
       <i class="bi bi-upload me-1"></i>Importar datos
     </button>`
  );

  _ssRebuild(cfg.initRows || 20);

  // Capturar Ctrl+V en el grid
  document.getElementById('ss-grid-wrap')
    ?.addEventListener('paste', _ssPaste, true);
}

/* ── Construcción de filas ── */

function _ssRebuild(n) {
  const tbody = document.getElementById('ss-body');
  if (!tbody || !_ss) return;
  tbody.innerHTML = '';
  for (let r = 0; r < n; r++) tbody.appendChild(_ssMakeRow(r));
  _ssUpdateCount();
}

function _ssMakeRow(r, data = {}) {
  const tr = document.createElement('tr');
  tr.dataset.r = r;

  // Número de fila
  const numTd = document.createElement('td');
  numTd.className = 'ss-num';
  numTd.textContent = r + 1;
  tr.appendChild(numTd);

  // Celdas de datos
  _ss.cfg.columns.forEach((col, c) => {
    const td = document.createElement('td');
    let el;

      const currentVal = data[col.key] !== undefined ? data[col.key] : col.defaultValue;
      if (col.options) {
      el = document.createElement('select');
      el.className = 'ss-cell';
      const empty = document.createElement('option');
      empty.value = ''; empty.textContent = col.required ? '— seleccionar —' : '(opcional)';
      el.appendChild(empty);
      col.options.forEach(opt => {
        const o = document.createElement('option');
        o.value       = opt.value ?? opt;
        o.textContent = opt.label ?? opt;
        if (currentVal === o.value) o.selected = true;
        el.appendChild(o);
      });
      el.addEventListener('change', _ssUpdateCount);
    } else {
      el = document.createElement('input');
      el.type = col.type || 'text';
      el.className = 'ss-cell';
      el.value = currentVal ?? '';
      if (col.placeholder) el.placeholder = col.placeholder;
      el.addEventListener('input', _ssUpdateCount);
    }

    el.dataset.r = r; el.dataset.c = c;
    el.addEventListener('keydown', _ssKey);
    el.addEventListener('focus',   () => { _ss.focusRow = r; _ss.focusCol = c; });

    td.appendChild(el);
    tr.appendChild(td);
  });

  // Celda de estado
  const stTd = document.createElement('td');
  stTd.className = 'ss-status-cell'; stTd.id = `ss-s-${r}`;
  tr.appendChild(stTd);

  return tr;
}

/* ── Navegación con teclado ── */

function _ssKey(e) {
  const r    = +e.target.dataset.r;
  const c    = +e.target.dataset.c;
  const cols = _ss.cfg.columns.length;

  if (e.key === 'Tab') {
    e.preventDefault();
    if (!e.shiftKey) { c + 1 < cols ? _ssFocus(r, c + 1) : _ssFocus(r + 1, 0); }
    else             { c > 0        ? _ssFocus(r, c - 1) : _ssFocus(r - 1, cols - 1); }
  } else if (e.key === 'Enter') {
    e.preventDefault(); _ssFocus(r + 1, c);
  } else if (e.key === 'ArrowDown' && e.ctrlKey) {
    e.preventDefault(); _ssFocus(r + 1, c);
  } else if (e.key === 'ArrowUp' && e.ctrlKey) {
    e.preventDefault(); _ssFocus(r - 1, c);
  }
}

function _ssFocus(r, c) {
  const tbody = document.getElementById('ss-body');
  if (!tbody) return;
  if (r >= tbody.rows.length) { _ssAddRows(5); setTimeout(() => _ssFocus(r, c), 60); return; }
  if (r < 0) return;
  tbody.rows[r]?.querySelector(`.ss-cell[data-c="${c}"]`)?.focus();
}

/* ── Pegar desde Excel / Google Sheets ── */

function _ssPaste(e) {
  const text = (e.clipboardData || window.clipboardData).getData('text/plain');
  if (!text || !_ss) return;

  const lines    = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
  const rowData  = lines.filter(l => l.trim()).map(l => l.split('\t'));
  if (!rowData.length) return;

  // Detectar y omitir fila de cabeceras
  const labels   = _ss.cfg.columns.map(c => c.label.toLowerCase());
  const keys     = _ss.cfg.columns.map(c => c.key.toLowerCase());
  const firstRow = rowData[0].map(v => v.trim().toLowerCase());
  const isHeader = firstRow.length > 0 && firstRow.some(v =>
    labels.some(l => l.includes(v) || v.includes(l.split(' ')[0])) ||
    keys.some(k => k.startsWith(v) || v === k)
  );
  const data = isHeader ? rowData.slice(1) : rowData;
  if (!data.length) return;

  e.preventDefault();

  const startR = document.activeElement?.dataset?.r != null ? +document.activeElement.dataset.r : 0;
  const startC = document.activeElement?.dataset?.c != null ? +document.activeElement.dataset.c : 0;

  // Agregar filas si hacen falta
  const tbody   = document.getElementById('ss-body');
  const needed  = startR + data.length + 3;
  while (tbody.rows.length < needed) tbody.appendChild(_ssMakeRow(tbody.rows.length));

  // Llenar celdas
  data.forEach((cols, ri) => {
    cols.forEach((val, ci) => {
      const colIdx = startC + ci;
      if (colIdx >= _ss.cfg.columns.length) return;
      const cell = tbody.rows[startR + ri]?.querySelector(`.ss-cell[data-c="${colIdx}"]`);
      if (cell) {
        cell.value = val.trim();
        cell.classList.remove('ss-cell-error');
      }
    });
    // Actualizar número de fila si es nueva
    const numEl = tbody.rows[startR + ri]?.querySelector('.ss-num');
    if (numEl) numEl.textContent = startR + ri + 1;
  });

  _ssUpdateCount();
  toast(`${data.length} fila${data.length !== 1 ? 's' : ''} pegadas`, 'success');
}

/* ── Utilidades ── */

function _ssAddRows(n = 10) {
  const tbody = document.getElementById('ss-body');
  if (!tbody || !_ss) return;
  const cur = tbody.rows.length;
  for (let i = 0; i < n; i++) tbody.appendChild(_ssMakeRow(cur + i));
}

function _ssClearAll() {
  if (!confirm('¿Limpiar todos los datos de la planilla?')) return;
  _ssRebuild(20);
}

function _ssUpdateCount() {
  const tbody = document.getElementById('ss-body');
  const el    = document.getElementById('ss-row-count');
  if (!tbody || !el) return;
  const n = [...tbody.rows].filter(tr =>
    [...tr.querySelectorAll('.ss-cell')].some(i => i.value.trim())
  ).length;
  el.textContent = n ? `${n} fila${n !== 1 ? 's' : ''} con datos` : '';
}

function _ssGetRows() {
  const tbody = document.getElementById('ss-body');
  if (!tbody || !_ss) return [];
  const rows = [];
  for (const tr of tbody.rows) {
    const obj = {}; let hasData = false;
    for (const cell of tr.querySelectorAll('.ss-cell')) {
      const col = _ss.cfg.columns[+cell.dataset.c];
      if (!col) continue;
      const v = cell.value.trim();
      if (v) hasData = true;
      obj[col.key] = v || null;
    }
    if (hasData) rows.push({ _tr: tr, ...obj });
  }
  return rows;
}

/* ── Importar / Validar ── */

function _ssRunValidation(rawRows) {
  // Limpiar marcas de error previas
  document.querySelectorAll('#ss-body .ss-cell-error').forEach(el => el.classList.remove('ss-cell-error'));
  document.querySelectorAll('.ss-status-cell').forEach(el => el.innerHTML = '');

  let hasErrors = false;
  let errorMsg = 'Completá los campos obligatorios (marcados en rojo)';

  const uniqueVals = {};
  _ss.cfg.columns.forEach((col, ci) => {
    if (col.unique) uniqueVals[ci] = new Set();
  });

  rawRows.forEach(row => {
    _ss.cfg.columns.forEach((col, ci) => {
      const val = row[col.key];
      let cellErr = false;
      
      // Obligatorios
      if (col.required && !val) {
        cellErr = true;
      }
      
      // Duplicados
      if (col.unique && val) {
        if (uniqueVals[ci].has(val)) {
          cellErr = true;
          errorMsg = 'Existen valores duplicados en columnas únicas (marcados en rojo)';
        } else {
          uniqueVals[ci].add(val);
        }
      }
      
      if (cellErr) {
        hasErrors = true;
        row._tr?.querySelector(`.ss-cell[data-c="${ci}"]`)?.classList.add('ss-cell-error');
      }
    });
  });
  
  return { hasErrors, errorMsg };
}

function _ssDoValidate() {
  if (!_ss) return;
  const rawRows = _ssGetRows();
  if (!rawRows.length) { toast('No hay datos para validar', 'warning'); return; }

  const { hasErrors, errorMsg } = _ssRunValidation(rawRows);

  if (hasErrors) {
    document.getElementById('ss-results').innerHTML = `<div class="alert alert-warning mt-2 py-2 small"><i class="bi bi-exclamation-triangle-fill me-1"></i>${errorMsg}</div>`;
    toast(errorMsg, 'warning');
  } else {
    document.getElementById('ss-results').innerHTML = `<div class="alert alert-success mt-2 py-2 small"><i class="bi bi-check-circle-fill me-1"></i>Datos válidos. Todo listo para importar.</div>`;
    toast('Los datos pasaron la validación previa', 'success');
  }
}

async function _ssDoImport() {
  if (!_ss) return;
  const rawRows = _ssGetRows();
  if (!rawRows.length) { toast('No hay datos para importar', 'warning'); return; }

  const { hasErrors, errorMsg } = _ssRunValidation(rawRows);
  if (hasErrors) { toast(errorMsg, 'warning'); return; }

  // Limpiar _tr antes de enviar a la API
  const cleanRows = rawRows.map(({ _tr, ...rest }) => rest);

  const btn = document.getElementById('ss-import-btn');
  setLoading(btn, true);
  document.getElementById('ss-results').innerHTML = '';

  try {
    const result = await _ss.cfg.onImport(cleanRows);
    const ok  = result.succeeded?.length || 0;
    const err = result.failed?.length    || 0;

    const errHtml = err ? `
      <details class="mt-2">
        <summary class="text-warning small fw-semibold" style="cursor:pointer">
          <i class="bi bi-exclamation-triangle me-1"></i>Ver ${err} error${err !== 1 ? 'es' : ''}
        </summary>
        <div class="mt-1 p-2 border rounded" style="max-height:150px;overflow-y:auto;font-size:.76rem;font-family:monospace">
          ${(result.failed || []).map(f =>
            `<div class="py-1 border-bottom text-danger">${JSON.stringify(f.input || f).substring(0, 90)} — ${f.error || ''}</div>`
          ).join('')}
        </div>
      </details>` : '';

    document.getElementById('ss-results').innerHTML = `
      <div class="ss-result-banner ${err ? 'ss-result-err' : 'ss-result-ok'} mt-2">
        <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>${ok} importados</span>
        ${err ? `<span class="badge bg-warning text-dark"><i class="bi bi-x-circle me-1"></i>${err} errores</span>` : ''}
        <span class="text-muted small">${ok + err} filas procesadas</span>
      </div>${errHtml}`;

    if (ok)  toast(`${ok} registros importados correctamente`, 'success');
    if (err) toast(`${err} registros con error — revisá los detalles`, 'warning');
  } catch(ex) {
    document.getElementById('ss-results').innerHTML =
      `<div class="alert alert-danger mt-2 py-2 small"><i class="bi bi-x-circle-fill me-1"></i>${ex.message}</div>`;
    toast('Error al importar: ' + ex.message, 'danger');
  }

  setLoading(btn, false);
}

/* ── Pagination ── */
const _pg = {};

/**
 * Initialize (or reset to page 1) a paginated list.
 * @param {string}   wrapId   - id of the #tableWrap element
 * @param {Array}    items    - full filtered dataset
 * @param {Function} renderFn - function(pageSlice) that writes to #wrapId
 * @param {number}   perPage  - rows per page (default 50)
 */
function pgInit(wrapId, items, renderFn, perPage = 50) {
  _pg[wrapId] = { items, renderFn, page: 1, perPage };
  _pgDraw(wrapId);
}

/* ── Error log panel ── */
const _errHistory = [];
let   _errPanelOpen = false;

/**
 * Register and display an error in the log panel.
 * severity: 'error' | 'warning' | 'info' | 'api'
 */
function logError(message, { type = 'Error', severity = 'error', source = '', stack = '' } = {}) {
  const ts    = new Date().toLocaleTimeString('es', { hour12: false });
  const entry = { ts, type, severity, message: String(message), source, stack };
  _errHistory.push(entry);

  const panel = document.getElementById('err-panel');
  const list  = document.getElementById('err-list');
  const count = document.getElementById('err-count');
  const badge = document.getElementById('err-badge-btn');
  const bCount = document.getElementById('err-badge-count');

  if (!panel || !list) return;

  // Update counters
  if (count)  count.textContent  = _errHistory.length;
  if (bCount) bCount.textContent = _errHistory.length;

  // Build entry HTML
  const id     = 'err-' + Date.now();
  const sevCls = { error: 'err-sev-error', warning: 'err-sev-warning', info: 'err-sev-info', api: 'err-sev-api' }[severity] || 'err-sev-error';
  const sevLbl = { error: 'Error', warning: 'Advertencia', info: 'Info', api: 'API' }[severity] || 'Error';
  const esc    = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

  const stackHtml = stack
    ? `<button class="err-stack-toggle" onclick="_errToggleStack('${id}')" type="button">
         <i class="bi bi-chevron-right" id="${id}-chevron"></i> Ver stack trace
       </button>
       <pre class="err-stack" id="${id}-stack">${esc(stack)}</pre>`
    : '';

  const copyText = `[${ts}] ${type}: ${message}${source ? '\nFuente: ' + source : ''}${stack ? '\n' + stack : ''}`;

  const el = document.createElement('div');
  el.className = 'err-entry';
  el.innerHTML = `
    <div class="err-entry-header">
      <span class="err-severity ${sevCls}">${sevLbl}</span>
      <span class="err-type">${esc(type)}</span>
      <span class="err-ts">${ts}</span>
    </div>
    <div class="err-message">${esc(message)}</div>
    ${source ? `<div class="err-source"><i class="bi bi-geo-alt-fill me-1" style="font-size:.68rem"></i>${esc(source)}</div>` : ''}
    ${stackHtml}
    <button class="err-copy-btn" type="button"
            onclick="navigator.clipboard.writeText(${esc(JSON.stringify(copyText))}).then(()=>toast('Copiado','success'))">
      <i class="bi bi-clipboard me-1"></i>Copiar
    </button>`;

  // Most recent error at top
  list.insertBefore(el, list.firstChild);

  // Remove "empty" placeholder if present
  list.querySelector('.err-empty')?.remove();

  // Open panel automatically and hide badge
  openErrPanel();
  if (badge) badge.classList.add('err-badge-hidden');
}

function _errToggleStack(id) {
  const stack   = document.getElementById(id + '-stack');
  const chevron = document.getElementById(id + '-chevron');
  if (!stack) return;
  stack.classList.toggle('err-stack-open');
  if (chevron) chevron.className = stack.classList.contains('err-stack-open')
    ? 'bi bi-chevron-down'
    : 'bi bi-chevron-right';
}

function openErrPanel() {
  const panel = document.getElementById('err-panel');
  const badge = document.getElementById('err-badge-btn');
  if (panel) panel.classList.add('err-open');
  if (badge) badge.classList.add('err-badge-hidden');
  _errPanelOpen = true;
}

function closeErrPanel() {
  const panel  = document.getElementById('err-panel');
  const badge  = document.getElementById('err-badge-btn');
  if (panel) panel.classList.remove('err-open');
  if (_errHistory.length > 0 && badge) badge.classList.remove('err-badge-hidden');
  _errPanelOpen = false;
}

function clearErrLog() {
  _errHistory.length = 0;
  const list  = document.getElementById('err-list');
  const count = document.getElementById('err-count');
  const badge = document.getElementById('err-badge-btn');
  if (list)  list.innerHTML = '<div class="err-empty"><i class="bi bi-check-circle"></i>Sin errores registrados</div>';
  if (count) count.textContent = '0';
  if (badge) badge.classList.add('err-badge-hidden');
}

/* ── Global error interceptors ── */

// Uncaught JS errors
window.addEventListener('error', e => {
  logError(e.message, {
    type:     e.error?.name || 'Error',
    severity: 'error',
    source:   e.filename ? `${e.filename.split('/').pop()}:${e.lineno}` : '',
    stack:    e.error?.stack || '',
  });
});

// Unhandled promise rejections
window.addEventListener('unhandledrejection', e => {
  const err = e.reason;
  const msg = err instanceof Error ? err.message : String(err ?? 'Promesa rechazada sin mensaje');
  logError(msg, {
    type:     err instanceof Error ? err.name : 'UnhandledRejection',
    severity: 'error',
    stack:    err instanceof Error ? (err.stack || '') : '',
  });
});

/** Navigate to a specific page. */
function pgGo(wrapId, page) {
  if (!_pg[wrapId]) return;
  _pg[wrapId].page = page;
  _pgDraw(wrapId);
  // Scroll the card header into view
  const wrap = document.getElementById(wrapId);
  wrap?.closest('.table-card')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function _pgDraw(wrapId) {
  const state = _pg[wrapId];
  if (!state) return;
  const { items, renderFn, perPage } = state;
  const total  = items.length;
  const pages  = Math.max(1, Math.ceil(total / perPage));
  state.page   = Math.min(Math.max(1, state.page), pages);
  const p      = state.page;
  const start  = (p - 1) * perPage;

  // Render the current page slice
  renderFn(items.slice(start, start + perPage));

  // Remove the old pagination bar (if any) from the table-card
  const wrap = document.getElementById(wrapId);
  if (!wrap) return;
  const card = wrap.closest('.table-card') || wrap.parentElement;
  card.querySelector('.pg-bar')?.remove();
  if (pages <= 1) return;

  // Build page-number buttons (window of ±2 around current page)
  const lo = Math.max(1, p - 2);
  const hi = Math.min(pages, p + 2);
  let nums = '';
  if (lo > 1) nums += `<button class="pg-btn" onclick="pgGo('${wrapId}',1)">1</button>`;
  if (lo > 2) nums += `<button class="pg-btn" disabled>…</button>`;
  for (let i = lo; i <= hi; i++)
    nums += `<button class="pg-btn${i === p ? ' active' : ''}" onclick="pgGo('${wrapId}',${i})">${i}</button>`;
  if (hi < pages - 1) nums += `<button class="pg-btn" disabled>…</button>`;
  if (hi < pages)     nums += `<button class="pg-btn" onclick="pgGo('${wrapId}',${pages})">${pages}</button>`;

  const from = start + 1;
  const to   = Math.min(start + perPage, total);

  card.insertAdjacentHTML('beforeend', `
    <div class="pg-bar">
      <span class="pg-info">${from}—${to} de ${total}</span>
      <div class="pg-btns">
        <button class="pg-btn" ${p <= 1 ? 'disabled' : ''} onclick="pgGo('${wrapId}',${p - 1})">&laquo;</button>
        ${nums}
        <button class="pg-btn" ${p >= pages ? 'disabled' : ''} onclick="pgGo('${wrapId}',${p + 1})">&raquo;</button>
      </div>
    </div>`);
}

/* ── Command Palette (Ctrl+K) ── */
document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    const modal = new bootstrap.Modal(document.getElementById('commandPaletteModal'));
    modal.show();
  }
});

document.getElementById('commandPaletteModal')?.addEventListener('shown.bs.modal', function () {
  document.getElementById('cmdPaletteInput').focus();
});

let _cmdPaletteTimeout;
document.getElementById('cmdPaletteInput')?.addEventListener('input', function(e) {
  clearTimeout(_cmdPaletteTimeout);
  const q = e.target.value.trim();
  const resultsEl = document.getElementById('cmdPaletteResults');
  if (!q || q.length < 3) {
    resultsEl.innerHTML = '';
    return;
  }
  
  resultsEl.innerHTML = '<div class="p-3 text-center text-muted"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Buscando...</div>';
  
  _cmdPaletteTimeout = setTimeout(async () => {
    try {
      const [users, courses] = await Promise.all([
        api.get(`/canvas/users?search=${encodeURIComponent(q)}&per_page=5`).catch(() => []),
        api.get(`/canvas/courses?search=${encodeURIComponent(q)}&per_page=5`).catch(() => [])
      ]);
      const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      
      const html = [
        ...users.map(u => `
          <a href="/ui/unified-users?q=${encodeURIComponent(u.login_id || u.email)}" class="list-group-item list-group-item-action border-0 py-3 d-flex align-items-center gap-3">
            <div class="bg-primary bg-opacity-10 text-primary rounded p-2"><i class="bi bi-person-fill fs-5"></i></div>
            <div><div class="fw-bold">${esc(u.name)}</div><div class="small text-muted">${esc(u.login_id || u.email || '')}</div></div>
            <span class="badge bg-primary bg-opacity-10 text-primary ms-auto rounded-pill px-3 py-2">Usuario</span>
          </a>`),
        ...courses.map(c => `
          <a href="/ui/canvas/courses?q=${encodeURIComponent(c.course_code)}" class="list-group-item list-group-item-action border-0 py-3 d-flex align-items-center gap-3">
            <div class="bg-success bg-opacity-10 text-success rounded p-2"><i class="bi bi-book-fill fs-5"></i></div>
            <div><div class="fw-bold">${esc(c.name)}</div><div class="small text-muted">${esc(c.course_code || '')}</div></div>
            <span class="badge bg-success bg-opacity-10 text-success ms-auto rounded-pill px-3 py-2">Curso</span>
          </a>`)
      ].join('');
      
      resultsEl.innerHTML = html || '<div class="p-4 text-center text-muted"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>Sin resultados</div>';
    } catch (err) {
      resultsEl.innerHTML = '<div class="p-4 text-center text-danger"><i class="bi bi-exclamation-triangle fs-1 d-block mb-2"></i>Error al buscar</div>';
    }
  }, 400);
});

// Generic Autocomplete Setup
function setupAutocomplete(inputId, hiddenId, resultsId, searchUrl, itemFormatter, onSelect) {
  const input = document.getElementById(inputId);
  const hidden = document.getElementById(hiddenId);
  const results = document.getElementById(resultsId);
  if(!input || !hidden || !results) return;
  
  let timeout = null;

  input.addEventListener('input', () => {
    clearTimeout(timeout);
    hidden.value = ''; // Reset ID on change
    const query = input.value.trim();
    if (query.length < 3) {
      results.classList.remove('show');
      return;
    }
    
    results.innerHTML = '<div class="autocomplete-loading"><i class="bi bi-hourglass-split"></i> Buscando...</div>';
    results.classList.add('show');
    
    timeout = setTimeout(async () => {
      try {
        const paramName = searchUrl.includes('/teams/users') ? 'search' : 'search_term';
        const url = `${searchUrl}?${paramName}=${encodeURIComponent(query)}&top=15&per_page=15`;
        const res = await api.get(url);
        let items = Array.isArray(res) ? res : (res.value || res.items || []);
        
        if (items.length === 0) {
          results.innerHTML = '<div class="autocomplete-loading">No se encontraron resultados</div>';
          return;
        }
        
        results.innerHTML = '';
        items.forEach(item => {
          const div = document.createElement('div');
          div.className = 'autocomplete-item';
          div.innerHTML = itemFormatter(item);
          div.addEventListener('click', () => {
            onSelect(item, input, hidden);
            results.classList.remove('show');
          });
          results.appendChild(div);
        });
      } catch (err) {
        results.innerHTML = '<div class="autocomplete-loading text-danger">Error al buscar</div>';
      }
    }, 450); // Debounce de 450ms
  });

  // Hide on blur
  document.addEventListener('click', (e) => {
    if (!input.contains(e.target) && !results.contains(e.target)) {
      results.classList.remove('show');
    }
  });
}

/* 🟢 Excel Upload that returns a blob (download modified excel) */
async function uploadExcelDownloadBlob(url, fileInputId) {
  const inp = document.getElementById(fileInputId);
  if (!inp.files[0]) throw new Error('Selecciona un archivo Excel primero');
  const fd = new FormData();
  fd.append('file', inp.files[0]);
  
  const originalName = inp.files[0].name;
  const newName = originalName.replace(".xlsx", "_procesado.xlsx");

  const r = await fetch(url, { method: 'POST', body: fd });
  if (!r.ok) {
    const e = await r.json().catch(() => ({}));
    throw new Error(e.detail || r.statusText);
  }
  
  const blob = await r.blob();
  const downloadUrl = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = downloadUrl;
  a.download = newName;
  document.body.appendChild(a);
  a.click();
  a.remove();
  window.URL.revokeObjectURL(downloadUrl);
}

/* 🟢 Modal function for Diplomados */
function openExcelDiplomados() {
  document.getElementById('globalModalTitle').innerHTML = '<i class="bi bi-cloud-arrow-up me-2"></i>Sincronización Directa de Diplomados';
  document.getElementById('globalModalBody').innerHTML = `
    <div class="alert alert-info">
      Pega el <b>enlace compartido de OneDrive</b> de la planilla de Diplomados. Haz clic en "Cargar Pestañas" para ver las hojas disponibles.
    </div>
    <div class="row">
      <div class="col-md-10 offset-md-1">
        <label class="form-label fw-bold">URL Compartida de OneDrive / SharePoint</label>
        <div class="input-group mb-3">
          <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
          <input type="url" class="form-control" id="diplomadoUrl" value="https://usilparaguay-my.sharepoint.com/:x:/g/personal/resteche_usil_edu_py/IQBjeh0nYFG7QbZx21y-3U-8AfhP2B9akxz7fo_LK_sKyGo?e=y8pJYX" required>
          <button class="btn btn-primary" type="button" id="btnLoadSheets" onclick="fetchSheets()">Cargar Pestañas</button>
        </div>
        <label class="form-label fw-bold">Nombre de la Pestaña (Requerido)</label>
        <div class="input-group mb-3">
          <span class="input-group-text"><i class="bi bi-file-spreadsheet"></i></span>
          <select class="form-select" id="diplomadoSheet" required>
              <option value="">Primero haz clic en Cargar Pestañas...</option>
          </select>
        </div>
        
      </div>
    </div>
  `;
  document.getElementById('globalModalFooter').innerHTML = `
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
    <button type="button" class="btn btn-success" id="btnUploadDiplomado" onclick="doUploadDiplomados()"><i class="bi bi-lightning-charge me-1"></i>Sincronizar Ahora</button>
  `;
  
  new bootstrap.Modal(document.getElementById('globalModal')).show();
}



// Restore OneDrive URL across pages with defaults
document.addEventListener('DOMContentLoaded', () => {
    const defaultUrls = {
        'coursesOdUrl': 'https://usilparaguay-my.sharepoint.com/:x:/g/personal/resteche_usil_edu_py/IQARA_HJhg00QKcvL8bD1WvnATEbShmJ6jbq6qzgbWLzqIc?e=BayIS2',
        'diplomadoUrl': 'https://usilparaguay-my.sharepoint.com/:x:/g/personal/resteche_usil_edu_py/IQBjeh0nYFG7QbZx21y-3U-8AfhP2B9akxz7fo_LK_sKyGo?e=tXi91Q',
        'mat_url': 'https://usilparaguay-my.sharepoint.com/:x:/g/personal/resteche_usil_edu_py/IQCHMuoLYGs9T4NDeid5n9A7AZvphg9oml_g9dt-GYD5tY0?e=d4RKCr',
        'masivoUrl': 'https://usilparaguay-my.sharepoint.com/:x:/g/personal/resteche_usil_edu_py/IQA4gwZnz09sSIwlVtQ5bZlmAblW8XRtsRBXTTPnz6UTXjU?e=AlIYI6',
        'doc_url': 'https://usilparaguay-my.sharepoint.com/:x:/g/personal/resteche_usil_edu_py/IQAXcMN-cm4oQL3gRm2urNcTAeH-gSDKwUwleXVrjyAFcZY?e=GhWnCj',
        'urlEgresoOneDrive': ''
    };
    
    Object.keys(defaultUrls).forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            const savedUrl = localStorage.getItem(`onedrive_excel_url_${id}`);
            if (savedUrl) {
                el.value = savedUrl;
            } else if (defaultUrls[id]) {
                el.value = defaultUrls[id];
            }
            
            el.addEventListener('change', (e) => {
                if (e.target.value && e.target.value.includes('http')) {
                    localStorage.setItem(`onedrive_excel_url_${id}`, e.target.value);
                }
            });
        }
    });
});

// Resalta el sub-item del sidebar que corresponde al ?tab= actual y
// despliega su submenú (ej. Cursos Canvas > Liberar SIS ID).
document.addEventListener('DOMContentLoaded', () => {
    const currentTab = new URLSearchParams(window.location.search).get('tab');
    if (!currentTab) return;

    document.querySelectorAll('.sidebar-sublink').forEach(link => {
        const url = new URL(link.getAttribute('href'), window.location.origin);
        if (url.pathname !== window.location.pathname || url.searchParams.get('tab') !== currentTab) return;

        link.classList.add('active');
        const submenu = link.closest('.collapse');
        if (!submenu) return;
        submenu.classList.add('show');
        const group = submenu.previousElementSibling;
        const caret = group && group.querySelector('.sidebar-caret-btn');
        if (caret) caret.setAttribute('aria-expanded', 'true');
    });
});
