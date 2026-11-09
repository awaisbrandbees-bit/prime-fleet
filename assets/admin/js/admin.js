(function () {
  var app = document.getElementById('prime-fleet-app');
  if (!app || typeof primeFleetAdmin === 'undefined') return;

  var panelEl = document.getElementById('prime-fleet-panel');
  var toastStack = document.getElementById('prime-fleet-toast-stack');
  var loaderEl = document.getElementById('prime-fleet-loader');
  var modalEl = document.getElementById('prime-fleet-modal');
  var modalTitle = document.getElementById('prime-fleet-modal-title');
  var modalBody = document.getElementById('prime-fleet-modal-body');
  var confirmEl = document.getElementById('prime-fleet-confirm');
  var confirmTitle = document.getElementById('prime-fleet-confirm-title');
  var confirmText = document.getElementById('prime-fleet-confirm-text');
  var confirmOk = document.getElementById('prime-fleet-confirm-ok');
  var confirmCancel = document.getElementById('prime-fleet-confirm-cancel');
  var moreRoot = app.querySelector('.prime-fleet-more');
  var moreToggle = document.getElementById('prime-fleet-more-toggle');
  var moreMenu = document.getElementById('prime-fleet-more-menu');
  var state = { panel: app.getAttribute('data-panel') || 'dashboard', query: {}, reqId: 0 };
  var confirmResolver = null;

  function toast(msg, type) {
    if (!toastStack || !msg) return;
    type = type || 'success';
    var titles = { success: 'Success', error: 'Something went wrong', info: 'Notice' };
    var el = document.createElement('div');
    el.className = 'prime-fleet-toast prime-fleet-toast--' + type;
    el.setAttribute('role', 'status');
    el.innerHTML =
      '<span class="prime-fleet-toast__icon" aria-hidden="true"></span>' +
      '<div class="prime-fleet-toast__content">' +
      '<p class="prime-fleet-toast__title"></p>' +
      '<p class="prime-fleet-toast__text"></p>' +
      '</div>' +
      '<button type="button" class="prime-fleet-toast__x" aria-label="Close">&times;</button>';
    el.querySelector('.prime-fleet-toast__title').textContent = titles[type] || titles.success;
    el.querySelector('.prime-fleet-toast__text').textContent = msg;
    el.querySelector('.prime-fleet-toast__x').addEventListener('click', function () { el.remove(); });
    toastStack.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('is-in'); });
    setTimeout(function () {
      el.classList.remove('is-in');
      el.classList.add('is-out');
      setTimeout(function () { el.remove(); }, 280);
    }, 3600);
  }

  function showLoader(label) {
    if (!loaderEl) return;
    var text = loaderEl.querySelector('.prime-fleet-loader__label');
    if (text) text.textContent = label || 'Loading…';
    loaderEl.hidden = false;
    panelEl.classList.add('is-loading');
  }

  function hideLoader() {
    if (loaderEl) loaderEl.hidden = true;
    panelEl.classList.remove('is-loading');
  }

  function closeMore() {
    if (!moreMenu || !moreToggle) return;
    moreMenu.hidden = true;
    moreToggle.setAttribute('aria-expanded', 'false');
  }

  function askConfirm(message, title, okLabel, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (!confirmEl) {
        resolve(window.confirm(message || 'Are you sure?'));
        return;
      }
      confirmResolver = resolve;
      if (confirmTitle) confirmTitle.textContent = title || 'Please confirm';
      if (confirmText) confirmText.textContent = message || 'Are you sure you want to continue?';
      if (confirmOk) confirmOk.textContent = okLabel || 'Delete';
      confirmEl.classList.toggle('is-info', !!opts.info);
      if (confirmCancel) confirmCancel.hidden = !!opts.info;
      confirmEl.hidden = false;
      document.body.classList.add('prime-fleet-modal-open');
      if (confirmOk) confirmOk.focus();
    });
  }

  function closeConfirm(result) {
    if (!confirmEl) return;
    confirmEl.hidden = true;
    confirmEl.classList.remove('is-info');
    if (confirmCancel) confirmCancel.hidden = false;
    if (modalEl && modalEl.hidden) document.body.classList.remove('prime-fleet-modal-open');
    var resolver = confirmResolver;
    confirmResolver = null;
    if (resolver) resolver(!!result);
  }

  function openModal(templateId, title, record) {
    var src = document.getElementById(templateId);
    if (!src || !modalEl) return;
    var form = src.querySelector('form');
    if (!form) return;
    modalTitle.textContent = title || 'Edit';
    modalBody.innerHTML = '';
    var clone = form.cloneNode(true);
    clone.id = '';
    clone.querySelectorAll('[data-pf-field]').forEach(function (field) {
      var key = field.getAttribute('data-pf-field');
      if (field.type === 'checkbox') {
        if (record) field.checked = Number(record[key]) === 1;
        return;
      }
      if (record && record[key] != null && record[key] !== '') {
        field.value = String(record[key]);
        return;
      }
      if (key === 'id') {
        field.value = '0';
        return;
      }
      if (!record && field.tagName !== 'SELECT') {
        if (field.type === 'number') field.value = field.getAttribute('value') || field.value || '';
        else if (field.type !== 'hidden') field.value = field.value || '';
      }
    });
    if (record && Number(record.is_system) === 1) {
      var sysKey = clone.querySelector('[data-pf-field="field_key"]');
      if (sysKey) sysKey.readOnly = true;
    }
    modalBody.appendChild(clone);
    modalEl.hidden = false;
    document.body.classList.add('prime-fleet-modal-open');
    bindModalForm(clone);
    enhanceSelects(clone);
    hydrateMultiChecks(clone, record);
    hydrateConditionBuilders(clone, record);
    bindConditionBuilders(clone);
    var focusable = clone.querySelector('input:not([type="hidden"]), .prime-fleet-select__trigger, textarea');
    if (focusable) focusable.focus();
  }

  function hydrateMultiChecks(root, record) {
    if (!root || !record) return;
    root.querySelectorAll('[data-pf-multi]').forEach(function (wrap) {
      var key = wrap.getAttribute('data-pf-multi');
      var ids = record[key];
      if (!Array.isArray(ids)) {
        try { ids = JSON.parse(record[key] || '[]'); } catch (e) { ids = []; }
      }
      ids = (ids || []).map(function (n) { return String(n); });
      wrap.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
        cb.checked = ids.indexOf(String(cb.value)) !== -1;
      });
    });
  }

  function parseConditionPayload(raw) {
    if (!raw) return { match: 'all', conditions: [] };
    var data = raw;
    if (typeof raw === 'string') {
      try { data = JSON.parse(raw); } catch (e) { return { match: 'all', conditions: [] }; }
    }
    if (!data || typeof data !== 'object') return { match: 'all', conditions: [] };
    var match = data.match === 'any' ? 'any' : 'all';
    var rules = [];
    if (Array.isArray(data.conditions)) rules = data.conditions;
    else if (Array.isArray(data.rules)) rules = data.rules;
    else if (Array.isArray(data)) rules = data;
    return {
      match: match,
      conditions: rules.map(function (r) {
        if (!r || typeof r !== 'object') return null;
        var op = r.operator || r.op || (r.equals != null ? 'is' : '');
        var val = r.value != null ? r.value : (r.equals != null ? r.equals : '');
        if (Array.isArray(val)) val = val.join(', ');
        return { field: r.field || '', operator: op, value: String(val == null ? '' : val) };
      }).filter(Boolean)
    };
  }

  function addConditionRow(builder, rule) {
    var tpl = builder.querySelector('[data-pf-cond-tpl]');
    var rows = builder.querySelector('[data-pf-cond-rows]');
    if (!tpl || !rows) return;
    var i = rows.children.length;
    var html = tpl.innerHTML.replace(/__i__/g, String(i));
    var wrap = document.createElement('div');
    wrap.innerHTML = html.trim();
    var row = wrap.firstElementChild;
    rows.appendChild(row);
    if (rule) {
      var f = row.querySelector('[data-pf-cond-field]');
      var o = row.querySelector('[data-pf-cond-op]');
      var v = row.querySelector('[data-pf-cond-value]');
      if (f && rule.field) f.value = rule.field;
      if (o && rule.operator) o.value = rule.operator;
      if (v) v.value = rule.value || '';
    }
    enhanceSelects(row);
  }

  function hydrateConditionBuilders(root, record) {
    if (!root) return;
    root.querySelectorAll('[data-pf-condition-builder]').forEach(function (builder) {
      var key = builder.getAttribute('data-pf-condition-builder');
      var mode = builder.getAttribute('data-pf-condition-mode') || 'group';
      var raw = record && record[key] != null ? record[key] : '';
      var parsed = parseConditionPayload(raw);
      var matchEl = builder.querySelector('[data-pf-cond-match]');
      if (matchEl && mode === 'group') matchEl.value = parsed.match;
      var rows = builder.querySelector('[data-pf-cond-rows]');
      if (rows) rows.innerHTML = '';
      if (parsed.conditions.length) {
        parsed.conditions.forEach(function (rule) { addConditionRow(builder, rule); });
      }
    });
  }

  function bindConditionBuilders(root) {
    if (!root) return;
    root.querySelectorAll('[data-pf-condition-builder]').forEach(function (builder) {
      if (builder.getAttribute('data-pf-cond-bound')) return;
      builder.setAttribute('data-pf-cond-bound', '1');
      builder.addEventListener('click', function (e) {
        if (e.target.closest('[data-pf-cond-add]')) {
          e.preventDefault();
          addConditionRow(builder, null);
          return;
        }
        var rm = e.target.closest('[data-pf-cond-remove]');
        if (rm) {
          e.preventDefault();
          var row = rm.closest('.prime-fleet-conditions__row');
          if (row) row.remove();
        }
      });
    });
  }

  function closeModal() {
    if (!modalEl) return;
    closeAllSelects();
    modalEl.hidden = true;
    modalBody.innerHTML = '';
    if (!confirmEl || confirmEl.hidden) document.body.classList.remove('prime-fleet-modal-open');
  }

  function closeAllSelects(except) {
    document.querySelectorAll('.prime-fleet-select.is-open').forEach(function (wrap) {
      if (except && wrap === except) return;
      if (typeof wrap._sleClose === 'function') wrap._sleClose();
    });
  }

  function enhanceSelects(root) {
    if (!root) return;
    root.querySelectorAll('select').forEach(function (sel) {
      if (sel.multiple || sel.size > 1) return;
      if (sel.hasAttribute('data-pf-native')) return;
      if (sel.closest('.prime-fleet-form-source')) return;
      if (sel.closest('.prime-fleet-select')) return;

      var wrap = document.createElement('div');
      wrap.className = 'prime-fleet-select';
      if (sel.classList.contains('prime-fleet-table-tools__select')) {
        wrap.classList.add('prime-fleet-select--toolbar');
      }
      if (sel.disabled) wrap.classList.add('is-disabled');

      sel.parentNode.insertBefore(wrap, sel);
      wrap.appendChild(sel);
      sel.classList.add('prime-fleet-select__native');
      sel.setAttribute('tabindex', '-1');

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'prime-fleet-select__trigger';
      btn.setAttribute('aria-haspopup', 'listbox');
      btn.setAttribute('aria-expanded', 'false');
      if (sel.id) btn.setAttribute('aria-labelledby', sel.id);
      if (sel.disabled) btn.disabled = true;

      var label = document.createElement('span');
      label.className = 'prime-fleet-select__value';
      var chevron = document.createElement('span');
      chevron.className = 'prime-fleet-select__chevron';
      chevron.setAttribute('aria-hidden', 'true');
      btn.appendChild(label);
      btn.appendChild(chevron);

      var menu = document.createElement('div');
      menu.className = 'prime-fleet-select__menu';
      menu.setAttribute('role', 'listbox');
      menu.hidden = true;

      wrap.appendChild(btn);
      wrap.appendChild(menu);

      var searchable = sel.hasAttribute('data-pf-search') || sel.options.length >= 8;
      if (searchable) wrap.classList.add('prime-fleet-select--search');

      function optionLabel(opt) {
        return (opt && (opt.textContent || opt.label || opt.value)) || '';
      }

      function syncFromSelect() {
        var opt = sel.options[sel.selectedIndex];
        label.textContent = optionLabel(opt) || '—';
        wrap.classList.toggle('is-disabled', !!sel.disabled);
        btn.disabled = !!sel.disabled;
      }

      function rebuildMenu(filterText) {
        var q = (filterText || '').toLowerCase().trim();
        menu.innerHTML = '';

        if (searchable) {
          var searchWrap = document.createElement('div');
          searchWrap.className = 'prime-fleet-select__search';
          var icon = document.createElement('span');
          icon.className = 'prime-fleet-select__search-icon';
          icon.setAttribute('aria-hidden', 'true');
          var search = document.createElement('input');
          search.type = 'search';
          search.className = 'prime-fleet-select__search-input';
          search.setAttribute('placeholder', 'Search…');
          search.setAttribute('autocomplete', 'off');
          search.value = filterText || '';
          search.addEventListener('click', function (e) { e.stopPropagation(); });
          search.addEventListener('mousedown', function (e) { e.stopPropagation(); });
          search.addEventListener('keydown', function (e) {
            e.stopPropagation();
            if (e.key === 'Escape') {
              e.preventDefault();
              close();
              btn.focus();
            }
            if (e.key === 'ArrowDown') {
              e.preventDefault();
              var first = menu.querySelector('.prime-fleet-select__option:not(:disabled)');
              if (first) first.focus();
            }
            if (e.key === 'Enter') {
              e.preventDefault();
              var hit = menu.querySelector('.prime-fleet-select__option:not(:disabled)');
              if (hit) hit.click();
            }
          });
          search.addEventListener('input', function () {
            rebuildMenu(search.value);
            var again = menu.querySelector('.prime-fleet-select__search-input');
            if (again) {
              again.focus();
              var len = again.value.length;
              try { again.setSelectionRange(len, len); } catch (err) { /* aislop-ignore-line ai-slop/swallowed-exception -- optional browser API / storage */ void err; }
            }
          });
          searchWrap.appendChild(icon);
          searchWrap.appendChild(search);
          menu.appendChild(searchWrap);
        }

        var list = document.createElement('div');
        list.className = 'prime-fleet-select__list';
        menu.appendChild(list);

        var shown = 0;
        Array.prototype.forEach.call(sel.options, function (opt, i) {
          if (opt.hidden) return;
          var text = optionLabel(opt);
          if (q && text.toLowerCase().indexOf(q) === -1) return;
          // Skip blank separator rows from WP timezone lists.
          if (!text.trim() && !opt.value) return;

          var item = document.createElement('button');
          item.type = 'button';
          item.className = 'prime-fleet-select__option';
          item.setAttribute('role', 'option');
          item.dataset.index = String(i);
          item.textContent = text || '—';
          if (opt.disabled) {
            item.disabled = true;
            item.classList.add('is-disabled');
          }
          if (i === sel.selectedIndex) {
            item.classList.add('is-selected');
            item.setAttribute('aria-selected', 'true');
          }
          item.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (opt.disabled) return;
            sel.selectedIndex = i;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
            syncFromSelect();
            close();
            btn.focus();
          });
          list.appendChild(item);
          shown++;
        });

        if (!shown) {
          var empty = document.createElement('p');
          empty.className = 'prime-fleet-select__empty';
          empty.textContent = 'No matches';
          list.appendChild(empty);
        }
      }

      function positionMenu() {
        var r = btn.getBoundingClientRect();
        var width = Math.max(r.width, searchable ? 260 : 160);
        menu.style.minWidth = width + 'px';
        menu.style.maxWidth = Math.min(searchable ? 420 : 360, window.innerWidth - 16) + 'px';
        menu.style.left = r.left + 'px';
        menu.style.top = (r.bottom + 6) + 'px';
        requestAnimationFrame(function () {
          var mr = menu.getBoundingClientRect();
          var left = r.left;
          var top = r.bottom + 6;
          if (mr.bottom > window.innerHeight - 10) {
            top = Math.max(10, r.top - mr.height - 6);
          }
          if (left + mr.width > window.innerWidth - 10) {
            left = Math.max(10, window.innerWidth - mr.width - 10);
          }
          menu.style.left = left + 'px';
          menu.style.top = top + 'px';
        });
      }

      function open() {
        if (sel.disabled) return;
        closeAllSelects(wrap);
        rebuildMenu('');
        wrap.classList.add('is-open');
        menu.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        if (menu.parentNode !== app) app.appendChild(menu);
        positionMenu();
        var selected = menu.querySelector('.prime-fleet-select__option.is-selected');
        var listEl = menu.querySelector('.prime-fleet-select__list');
        if (selected && listEl) {
          // Show the current value in view (not stuck at top of list).
          var top = selected.offsetTop - Math.max(0, (listEl.clientHeight / 2) - (selected.offsetHeight / 2));
          listEl.scrollTop = Math.max(0, top);
        }
        var search = menu.querySelector('.prime-fleet-select__search-input');
        if (search) {
          search.focus();
        } else if (selected) {
          selected.focus();
        }
      }

      function close() {
        wrap.classList.remove('is-open');
        menu.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        if (menu.parentNode !== wrap) wrap.appendChild(menu);
      }

      wrap._sleClose = close;
      wrap._sleSync = syncFromSelect;

      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (wrap.classList.contains('is-open')) close();
        else open();
      });

      btn.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          if (!wrap.classList.contains('is-open')) open();
        }
      });

      menu.addEventListener('keydown', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('prime-fleet-select__search-input')) {
          return;
        }
        var items = Array.prototype.slice.call(menu.querySelectorAll('.prime-fleet-select__option:not(:disabled)'));
        var idx = items.indexOf(document.activeElement);
        if (e.key === 'Escape') {
          e.preventDefault();
          close();
          btn.focus();
          return;
        }
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          if (items[idx + 1]) items[idx + 1].focus();
          else if (items[0]) items[0].focus();
        }
        if (e.key === 'ArrowUp') {
          e.preventDefault();
          if (items[idx - 1]) items[idx - 1].focus();
          else if (items[items.length - 1]) items[items.length - 1].focus();
        }
      });

      sel.addEventListener('change', syncFromSelect);
      syncFromSelect();
    });
  }

  function setActiveTab(panel) {
    document.querySelectorAll('.prime-fleet-tab[data-pf-panel], .prime-fleet-more__item').forEach(function (btn) {
      var on = btn.getAttribute('data-pf-panel') === panel;
      btn.classList.toggle('is-active', on);
      if (btn.getAttribute('role') === 'tab') btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    if (moreToggle) moreToggle.classList.toggle('is-active', !!app.querySelector('.prime-fleet-more__item.is-active'));
  }

  function parseAjax(r) {
    return r.text().then(function (text) {
      var json = null;
      try { json = text ? JSON.parse(text) : null; } catch (e) { json = null; }
      if (!r.ok || !json) {
        var msg = (json && json.data && json.data.message) || ('Server error (' + r.status + '). Check PHP logs.');
        return { success: false, data: { message: msg } };
      }
      return json;
    });
  }

  function applyPanelResult(json, opts) {
    opts = opts || {};
    if (!json || !json.success) {
      toast((json && json.data && json.data.message) || 'Request failed', 'error');
      return;
    }
    panelEl.innerHTML = json.data.html;
    if (json.data.toast) toast(json.data.toast, json.data.toast_type || 'success');
    else if (opts.toast) toast(opts.toast, opts.toastType || 'success');

    var nav = panelEl.querySelector('[data-pf-navigate]');
    if (nav) {
      var next = nav.getAttribute('data-pf-navigate');
      var q = {};
      try { q = JSON.parse(nav.getAttribute('data-pf-query') || '{}'); } catch (e) { /* aislop-ignore-line ai-slop/swallowed-exception -- optional browser API / storage */ void e; }
      closeModal();
      loadPanel(next, q);
      return;
    }
    bindPanel();
    enhanceSelects(panelEl);
    bindConditionBuilders(panelEl);
    bindSettingsTabs(panelEl);
    bindMergeTagCopy(panelEl);
  }

  function bindMergeTagCopy(root) {
    root.querySelectorAll('[data-pf-copy]').forEach(function (btn) {
      if (btn.getAttribute('data-pf-copy-bound')) return;
      btn.setAttribute('data-pf-copy-bound', '1');
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var text = btn.getAttribute('data-pf-copy') || '';
        if (!text) return;
        var done = function () { toast('Copied ' + text, 'success'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done).catch(function () {
            window.prompt('Copy merge tag:', text);
          });
          return;
        }
        window.prompt('Copy merge tag:', text);
      });
    });
  }

  function bindSettingsTabs(root) {
    var form = root.querySelector('[data-pf-settings-tabs]');
    if (!form) return;

    var tabInput = form.querySelector('#prime-fleet-settings-tab');
    var buttons = form.querySelectorAll('[data-pf-settings-tab]');
    var panels = form.querySelectorAll('[data-pf-settings-panel]');

    function activate(key) {
      if (!key) key = 'general';
      buttons.forEach(function (btn) {
        var on = btn.getAttribute('data-pf-settings-tab') === key;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      panels.forEach(function (panel) {
        var on = panel.getAttribute('data-pf-settings-panel') === key;
        if (on) panel.removeAttribute('hidden');
        else panel.setAttribute('hidden', 'hidden');
      });
      if (tabInput) tabInput.value = key;
      try { sessionStorage.setItem('prime_fleet_settings_tab', key); } catch (e) { /* aislop-ignore-line ai-slop/swallowed-exception -- optional browser API / storage */ void e; }
      state.query = Object.assign({}, state.query, { stab: key });
      try {
        var url = new URL(window.location.href);
        url.searchParams.set('panel', 'settings');
        url.searchParams.set('stab', key);
        window.history.replaceState({}, '', url.toString());
      } catch (e2) { /* aislop-ignore-line ai-slop/swallowed-exception -- optional browser API / storage */ void e2; }
    }

    buttons.forEach(function (btn) {
      if (btn.getAttribute('data-pf-settings-bound')) return;
      btn.setAttribute('data-pf-settings-bound', '1');
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        activate(btn.getAttribute('data-pf-settings-tab'));
      });
    });

    var initial = (tabInput && tabInput.value) || (state.query && state.query.stab) || '';
    if (!initial) {
      try { initial = sessionStorage.getItem('prime_fleet_settings_tab') || ''; } catch (e3) { initial = ''; }
    }
    if (!initial || !form.querySelector('[data-pf-settings-panel="' + initial + '"]')) {
      initial = 'general';
    }
    activate(initial);
  }

  function loadPanel(panel, query, opts) {
    opts = opts || {};
    closeAllSelects();
    state.panel = panel;
    state.query = query || {};
    setActiveTab(panel);
    closeMore();
    if (!opts.silent) showLoader(opts.loaderLabel || 'Loading…');

    var body = new FormData();
    body.append('action', 'prime_fleet_panel');
    body.append('nonce', primeFleetAdmin.nonce);
    body.append('panel', panel);
    Object.keys(state.query).forEach(function (k) { body.append('query[' + k + ']', state.query[k]); });

    try {
      var url = new URL(window.location.href);
      url.searchParams.set('page', 'prime-fleet');
      url.searchParams.set('panel', panel);
      ['service_id', 'edit', 'tab', 'level', 'prime_fleet_delete', '_wpnonce', 's', 'status', 'type', 'payment', 'paged', 'id', 'day'].forEach(function (k) {
        url.searchParams.delete(k);
      });
      Object.keys(state.query).forEach(function (k) {
        if (k === 'prime_fleet_delete' || k === '_wpnonce') return;
        url.searchParams.set(k, state.query[k]);
      });
      window.history.replaceState({}, '', url.toString());
    } catch (e) { /* aislop-ignore-line ai-slop/swallowed-exception -- optional browser API / storage */ void e; }

    if (state.query.prime_fleet_delete) {
      var kept = {};
      ['s', 'status', 'type', 'payment', 'paged', 'tab', 'service_id'].forEach(function (k) {
        if (state.query[k] != null && state.query[k] !== '') kept[k] = state.query[k];
      });
      state.query = kept;
    }

    var reqId = Date.now();
    state.reqId = reqId;

    return fetch(primeFleetAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(parseAjax)
      .then(function (json) {
        if (state.reqId !== reqId) return;
        hideLoader();
        applyPanelResult(json, opts);
      })
      .catch(function () {
        if (state.reqId !== reqId) return;
        hideLoader();
        toast('Network error', 'error');
      });
  }

  function postForm(form, fromModal, submitter) {
    var body = new FormData(form);
    // Include the clicked submit button (Attach / Detach / etc.) — FormData omits it by default.
    if (submitter && submitter.name) {
      body.set(submitter.name, submitter.value);
    }
    // Keep settings / messages sub-views after save.
    if (form.hasAttribute('data-pf-settings-tabs')) {
      var stab = body.get('settings_tab') || (state.query && state.query.stab) || 'general';
      state.query = Object.assign({}, state.query, { stab: String(stab) });
    }
    var tplId = body.get('template_id');
    if (tplId && body.get('prime_fleet_messages_action') === 'save_template') {
      state.query = Object.assign({}, state.query, { edit: String(tplId), mtab: 'templates' });
    }
    body.append('action', 'prime_fleet_panel');
    body.append('nonce', primeFleetAdmin.nonce);
    body.append('panel', state.panel);
    Object.keys(state.query).forEach(function (k) { body.append('query[' + k + ']', state.query[k]); });

    if (fromModal) closeModal();
    showLoader('Saving…');

    var reqId = Date.now();
    state.reqId = reqId;

    return fetch(primeFleetAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(parseAjax)
      .then(function (json) {
        if (state.reqId !== reqId) return;
        hideLoader();
        applyPanelResult(json);
      })
      .catch(function () {
        if (state.reqId !== reqId) return;
        hideLoader();
        toast('Network error', 'error');
      });
  }

  function bindModalForm(form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm(form, true, e.submitter);
    });
  }

  function bindPanel() {
    panelEl.querySelectorAll('a[href*="page=prime-fleet"]').forEach(function (a) {
      try {
        var u = new URL(a.href, window.location.origin);
        var page = u.searchParams.get('page');
        var map = {
          'prime-fleet': 'dashboard', 'prime-fleet-services': 'services', 'prime-fleet-zones': 'zones',
          'prime-fleet-pricing': 'pricing', 'prime-fleet-vehicles': 'vehicles', 'prime-fleet-chauffeurs': 'chauffeurs',
          'prime-fleet-addons': 'addons', 'prime-fleet-surcharges': 'surcharges', 'prime-fleet-coupons': 'coupons',
          'prime-fleet-availability': 'availability', 'prime-fleet-payments': 'payments', 'prime-fleet-settings': 'settings',
          'prime-fleet-logs': 'logs', 'prime-fleet-service-edit': 'service_edit', 'prime-fleets': 'bookings',
          'prime-fleet-dispatch': 'dispatch', 'prime-fleet-templates': 'templates', 'prime-fleet-reports': 'reports'
        };
        if (!map[page]) return;
        a.addEventListener('click', function (e) {
          e.preventDefault();
          var q = {};
          u.searchParams.forEach(function (v, k) { if (k !== 'page') q[k] = v; });
          loadPanel(map[page], q);
        });
      } catch (err) { /* aislop-ignore-line ai-slop/swallowed-exception -- optional browser API / storage */ void err; }
    });

    panelEl.querySelectorAll('form:not([data-pf-modal-form])').forEach(function (form) {
      if (form.closest('.prime-fleet-form-source')) return;

      if (form.hasAttribute('data-pf-table-filter')) {
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          var q = {};
          new FormData(form).forEach(function (v, k) {
            v = String(v).trim();
            if (v !== '') q[k] = v;
          });
          loadPanel(form.getAttribute('data-pf-panel') || state.panel, q);
        });
        var resetBtn = form.querySelector('[data-pf-table-reset]');
        if (resetBtn) {
          resetBtn.addEventListener('click', function () {
            loadPanel(form.getAttribute('data-pf-panel') || state.panel, {});
          });
        }
        return;
      }

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var submitter = e.submitter;
        var run = function () { postForm(form, false, submitter); };
        if (submitter && submitter.hasAttribute('data-pf-confirm')) {
          askConfirm(
            submitter.getAttribute('data-pf-confirm'),
            submitter.getAttribute('data-pf-confirm-title') || 'Please confirm',
            submitter.getAttribute('data-pf-confirm-ok') || 'Confirm'
          ).then(function (ok) { if (ok) run(); });
          return;
        }
        run();
      });
    });
  }

  if (moreToggle && moreMenu) {
    moreToggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var willOpen = moreMenu.hidden;
      moreMenu.hidden = !willOpen;
      moreToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  }

  if (confirmOk) confirmOk.addEventListener('click', function () { closeConfirm(true); });
  if (confirmCancel) confirmCancel.addEventListener('click', function () { closeConfirm(false); });
  if (confirmEl) {
    confirmEl.addEventListener('click', function (e) {
      if (e.target.closest('[data-pf-confirm-close]')) {
        e.preventDefault();
        closeConfirm(false);
      }
    });
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest('.prime-fleet-select') && !e.target.closest('.prime-fleet-select__menu')) {
      closeAllSelects();
    }

    var soonBtn = e.target.closest('[data-pf-coming-soon]');
    if (soonBtn) {
      e.preventDefault();
      askConfirm(
        soonBtn.getAttribute('data-pf-coming-soon') || 'This payment method is coming soon.',
        soonBtn.getAttribute('data-pf-coming-soon-title') || 'Coming soon',
        soonBtn.getAttribute('data-pf-coming-soon-ok') || 'Got it',
        { info: true }
      );
      return;
    }

    if (e.target.closest('[data-pf-modal-close]') && !e.target.closest('#prime-fleet-confirm')) {
      e.preventDefault();
      closeModal();
      return;
    }

    if (moreRoot && !moreRoot.contains(e.target)) closeMore();

    var confirmBtn = e.target.closest('[data-pf-confirm]');
    if (confirmBtn && confirmBtn.hasAttribute('data-pf-panel')) {
      e.preventDefault();
      askConfirm(
        confirmBtn.getAttribute('data-pf-confirm') || 'Are you sure?',
        confirmBtn.getAttribute('data-pf-confirm-title') || 'Delete',
        confirmBtn.getAttribute('data-pf-confirm-ok') || 'Delete'
      ).then(function (ok) {
        if (!ok) return;
        var panel = confirmBtn.getAttribute('data-pf-panel');
        var query = {};
        try { query = JSON.parse(confirmBtn.getAttribute('data-pf-query') || '{}'); } catch (err) { /* aislop-ignore-line ai-slop/swallowed-exception -- optional browser API / storage */ void err; }
        loadPanel(panel, query);
      });
      return;
    }

    // Form submit confirms are handled in the submit listener.
    if (confirmBtn && confirmBtn.type === 'submit') return;

    var openModalBtn = e.target.closest('[data-pf-open-modal]');
    if (openModalBtn) {
      e.preventDefault();
      var record = null;
      try { record = JSON.parse(openModalBtn.getAttribute('data-pf-record') || 'null'); } catch (err) { record = null; }
      openModal(
        openModalBtn.getAttribute('data-pf-open-modal'),
        openModalBtn.getAttribute('data-pf-modal-title') || 'Edit',
        record
      );
      return;
    }

    var tab = e.target.closest('[data-pf-panel]');
    if (!tab || !app.contains(tab) || tab.classList.contains('prime-fleet-more__toggle')) return;
    if (tab.hasAttribute('data-pf-confirm')) return;
    if (tab.tagName === 'FORM' || tab.hasAttribute('data-pf-table-filter')) return;
    if (tab.tagName === 'A') e.preventDefault();
    var p = tab.getAttribute('data-pf-panel');
    var q = {};
    var raw = tab.getAttribute('data-pf-query');
    if (raw) { try { q = JSON.parse(raw) || {}; } catch (err) { q = {}; } }
    loadPanel(p, q);
  });

  function closeAllMultiselects() {
    document.querySelectorAll('.prime-fleet-ms.is-open').forEach(function (ms) {
      ms.classList.remove('is-open');
      var panel = ms.querySelector('.prime-fleet-ms__panel');
      var btn = ms.querySelector('.prime-fleet-ms__trigger');
      if (panel) panel.hidden = true;
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
  }

  function syncMultiselectLabel(ms) {
    if (!ms) return;
    var labelEl = ms.querySelector('.prime-fleet-ms__label');
    if (!labelEl) return;
    var names = [];
    ms.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) {
      var n = cb.getAttribute('data-pf-ms-label') || (cb.nextElementSibling ? cb.nextElementSibling.textContent : '');
      if (n) names.push(n.trim());
    });
    labelEl.textContent = names.length ? names.join(', ') : 'Select services…';
  }

  document.addEventListener('click', function (e) {
    var trigger = e.target.closest && e.target.closest('.prime-fleet-ms__trigger');
    if (trigger) {
      e.preventDefault();
      var wrap = trigger.closest('.prime-fleet-ms');
      if (!wrap) return;
      var wasOpen = wrap.classList.contains('is-open');
      closeAllMultiselects();
      if (!wasOpen) {
        wrap.classList.add('is-open');
        var panel = wrap.querySelector('.prime-fleet-ms__panel');
        if (panel) panel.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
      }
      return;
    }
    if (e.target.closest && e.target.closest('.prime-fleet-ms__panel')) {
      var ms = e.target.closest('.prime-fleet-ms');
      if (e.target.matches && e.target.matches('input[type="checkbox"]')) {
        syncMultiselectLabel(ms);
      }
      return;
    }
    if (!e.target.closest || !e.target.closest('.prime-fleet-ms')) {
      closeAllMultiselects();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (document.querySelector('.prime-fleet-select.is-open')) {
      closeAllSelects();
      return;
    }
    if (document.querySelector('.prime-fleet-ms.is-open')) {
      closeAllMultiselects();
      return;
    }
    if (confirmEl && !confirmEl.hidden) {
      closeConfirm(false);
      return;
    }
    if (modalEl && !modalEl.hidden) closeModal();
  });

  window.addEventListener('resize', function () {
    closeAllSelects();
    closeAllMultiselects();
  });
  document.addEventListener('scroll', function (e) {
    var t = e.target;
    // Keep open while scrolling inside the dropdown panel (or its options).
    if (t && t.nodeType === 1 && t.closest) {
      if (t.closest('.prime-fleet-select__menu') || t.closest('.prime-fleet-ms__panel') || t.closest('.prime-fleet-ms')) {
        return;
      }
    }
    if (t === document || t === document.documentElement || t === document.body) {
      closeAllSelects();
      // Do not close multiselect on page scroll — only on outside click / Escape.
      return;
    }
    closeAllSelects();
  }, true);

  var bootQuery = {};
  try {
    var bootUrl = new URL(window.location.href);
    ['service_id', 'edit', 'tab', 'level', 's', 'status', 'type', 'payment', 'paged', 'id', 'day'].forEach(function (k) {
      if (bootUrl.searchParams.get(k)) bootQuery[k] = bootUrl.searchParams.get(k);
    });
  } catch (e) { /* aislop-ignore-line ai-slop/swallowed-exception -- optional browser API / storage */ void e; }

  loadPanel(state.panel, bootQuery, { loaderLabel: 'Loading…' });
})();
