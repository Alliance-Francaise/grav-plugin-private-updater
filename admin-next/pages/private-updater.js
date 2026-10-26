// Admin2 page for Private Updater: one row per package of user/.dependencies,
// with the installed, pinned and latest tag, and an Update button.
// Self-contained on purpose: Admin2 evaluates this file on its own.
(() => {
  const TAG = window.__GRAV_PAGE_TAG || 'grav-private-updater--page';

  const STATES = {
    ok: ['Up to date', 'ok'],
    outdated: ['Update available', 'warn'],
    drift: ['Installed ≠ pinned', 'warn'],
    dirty: ['Modified on server', 'bad'],
    missing: ['Not installed', 'warn'],
    linked: ['Dev symlink', 'muted'],
    unknown: ['Unknown', 'bad'],
  };

  function apiUrl(path) {
    return `${window.__GRAV_API_SERVER_URL || ''}${window.__GRAV_API_PREFIX || '/api/v1'}${path}`;
  }

  // Prefer Admin2's own client (token refresh, re-login); fall back to fetch.
  async function api(method, path, body) {
    if (window.__GRAV_API) {
      return method === 'GET' ? window.__GRAV_API.get(path) : window.__GRAV_API.post(path, body);
    }
    const headers = { 'Content-Type': 'application/json' };
    if (window.__GRAV_API_TOKEN) headers['X-API-Token'] = window.__GRAV_API_TOKEN;
    const resp = await fetch(apiUrl(path), { method, headers, body: body ? JSON.stringify(body) : undefined });
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) throw Object.assign(new Error(json.detail || json.message || resp.statusText), { status: resp.status });
    return json.data ?? json;
  }

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  class PrivateUpdaterPage extends HTMLElement {
    connectedCallback() {
      this.rows = [];
      this.busy = null;
      this.addEventListener('page-action', (e) => {
        if (e.detail?.id === 'refresh') this.load();
      });
      this.load();
    }

    async load() {
      this.loading = true;
      this.error = null;
      this.render();
      try {
        this.rows = await api('GET', '/private-updater/packages');
      } catch (err) {
        this.error = err.message;
      }
      this.loading = false;
      this.render();
    }

    async update(name) {
      const select = this.querySelector(`select[data-name="${CSS.escape(name)}"]`);
      const tag = select?.value;
      const row = this.rows.find((r) => r.name === name);
      if (!tag || !row) return;
      const ok = await window.__GRAV_DIALOGS?.confirm({
        title: `Update ${name}?`,
        message: `${row.installed || 'nothing'} → ${tag}. The site runs the new code right away. You can come back to ${row.installed || 'the old version'} the same way.`,
        confirmLabel: 'Update',
      });
      if (!ok) return;

      this.busy = name;
      this.render();
      try {
        const updated = await api('POST', `/private-updater/packages/${encodeURIComponent(name)}/update`, { tag });
        window.__GRAV_TOAST?.success(`${name} is now at ${updated.installed}`);
        await this.load();
      } catch (err) {
        window.__GRAV_TOAST?.error(err.message, { duration: 10000 });
      }
      this.busy = null;
      this.render();
    }

    render() {
      const body = this.loading
        ? '<p class="pu-muted">Asking the repositories for their releases…</p>'
        : this.error
          ? `<p class="pu-bad">${esc(this.error)}</p>`
          : this.rows.length === 0
            ? '<p class="pu-muted">No git package in <code>user/.dependencies</code>.</p>'
            : this.table();

      this.innerHTML = `
        <style>
          .pu-wrap { padding: 1rem 0; }
          .pu-table { width: 100%; border-collapse: collapse; }
          .pu-table th, .pu-table td { text-align: left; padding: .6rem .75rem; border-bottom: 1px solid var(--border, rgba(127,127,127,.25)); vertical-align: middle; }
          .pu-table th { font-weight: 600; font-size: .85em; opacity: .7; }
          .pu-name { font-weight: 600; }
          .pu-path { display: block; font-size: .8em; opacity: .6; }
          .pu-pill { display: inline-block; padding: .1rem .55rem; border-radius: 999px; font-size: .8em; white-space: nowrap; }
          .pu-pill.ok { background: rgba(34,197,94,.15); color: rgb(21,128,61); }
          .pu-pill.warn { background: rgba(234,179,8,.18); color: rgb(161,98,7); }
          .pu-pill.bad { background: rgba(239,68,68,.15); color: rgb(185,28,28); }
          .pu-pill.muted { background: rgba(127,127,127,.15); }
          .pu-note { display: block; font-size: .8em; margin-top: .25rem; opacity: .75; }
          .pu-actions { display: flex; gap: .5rem; align-items: center; min-height: auto; }
          .pu-actions select, .pu-actions button { padding: .3rem .6rem; border-radius: 6px; border: 1px solid var(--border, rgba(127,127,127,.4)); background: transparent; color: inherit; font: inherit; }
          .pu-actions button { cursor: pointer; }
          .pu-actions button:disabled { opacity: .5; cursor: default; }
          .pu-muted { opacity: .7; }
          .pu-bad { color: rgb(185,28,28); }
        </style>
        <div class="pu-wrap">${body}</div>`;

      this.querySelectorAll('button[data-name]').forEach((b) => b.addEventListener('click', () => this.update(b.dataset.name)));
    }

    table() {
      const rows = this.rows.map((r) => {
        const [label, cls] = STATES[r.state] || STATES.unknown;
        const canUpdate = !['dirty', 'linked', 'unknown'].includes(r.state) && r.tags.length > 0;
        const options = r.tags.map((t) => `<option ${t === (r.latest || r.pinned) ? 'selected' : ''}>${esc(t)}</option>`).join('');
        const note = r.error || (r.state === 'dirty' ? `Files were changed on the server. Check: git -C ${r.path} status` : '');
        return `
          <tr>
            <td><span class="pu-name">${esc(r.name)}</span><span class="pu-path">${esc(r.path)}</span></td>
            <td>${esc(r.installed || '-')}</td>
            <td>${esc(r.pinned)}</td>
            <td>${esc(r.latest || '-')}</td>
            <td><span class="pu-pill ${cls}">${label}</span>${note ? `<span class="pu-note">${esc(note)}</span>` : ''}</td>
            <td>${canUpdate ? `
              <div class="pu-actions">
                <select data-name="${esc(r.name)}" aria-label="Version of ${esc(r.name)}">${options}</select>
                <button data-name="${esc(r.name)}" ${this.busy ? 'disabled' : ''}>${this.busy === r.name ? 'Updating…' : 'Update'}</button>
              </div>` : ''}</td>
          </tr>`;
      }).join('');
      return `
        <table class="pu-table">
          <thead><tr><th>Package</th><th>Installed</th><th>Pinned</th><th>Latest</th><th>State</th><th></th></tr></thead>
          <tbody>${rows}</tbody>
        </table>`;
    }
  }

  if (!customElements.get(TAG)) customElements.define(TAG, PrivateUpdaterPage);
})();
