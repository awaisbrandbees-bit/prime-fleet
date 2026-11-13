/* global primeFleetAdmin */
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
  var shellTitle = document.getElementById('prime-fleet-shell-title');
  var topbarMeta = document.getElementById('prime-fleet-topbar-meta');
  var topbarActions = document.getElementById('prime-fleet-topbar-actions');
  /*
   * Portal overlays to <body> so position:fixed uses the real viewport.
   * Inside .prime-fleet-app (overflow-x: clip) they were trapped and
   * max-height: 100vh overflowed the bottom of the console band.
   */
  function portalOverlaysToBody() {
    [modalEl, confirmEl, loaderEl].forEach(function (node) {
      if (node && node.parentElement !== document.body) {
        document.body.appendChild(node);
      }
    });
  }
  portalOverlaysToBody();

  /**
   * Pin overlay to visualViewport and give the dialog a FIXED pixel box.
   * (Flex min-height:auto was ignoring max-height → modal grew and stuck to the bottom.)
   */
  function layoutModalDialog() {
    if (!modalEl || modalEl.hidden) return;
    portalOverlaysToBody();
    var dialog = modalEl.querySelector('.prime-fleet-modal__dialog');
    if (!dialog) return;

    var vv = window.visualViewport;
    var vw = Math.round((vv && vv.width) || window.innerWidth || 0);
    var vh = Math.round((vv && vv.height) || window.innerHeight || 0);
    var ox = Math.round((vv && vv.offsetLeft) || 0);
    var oy = Math.round((vv && vv.offsetTop) || 0);

    /* 12px top inset (user-verified) — do NOT vertical-center (that made ~120px top gap). */
    var gap = 12;
    var w = Math.min(560, Math.max(280, vw - gap * 2));
    var h = Math.min(560, Math.max(280, vh - gap * 2));
    var left = Math.round((vw - w) / 2);
    var top = gap;

    /* Never set `inset` — shorthand wipes top/left and parks the overlay off-screen. */
    modalEl.style.cssText = [
      'position:fixed',
      'top:' + oy + 'px',
      'left:' + ox + 'px',
      'width:' + vw + 'px',
      'height:' + vh + 'px',
      'display:block',
      'z-index:100100',
      'padding:0',
      'margin:0',
      'overflow:hidden',
      'box-sizing:border-box'
    ].join(' !important;') + ' !important;';

    dialog.style.cssText = [
      'position:absolute',
      'top:' + top + 'px',
      'left:' + left + 'px',
      'width:' + w + 'px',
      'height:' + h + 'px',
      'max-width:' + w + 'px',
      'max-height:' + h + 'px',
      'min-width:0',
      'min-height:0',
      'margin:0',
      'display:flex',
      'flex-direction:column',
      'overflow:hidden',
      'box-sizing:border-box',
      'z-index:1'
    ].join(' !important;') + ' !important;';

    if (modalBody) {
      modalBody.style.cssText = 'flex:1 1 auto !important;min-height:0 !important;overflow-x:hidden !important;overflow-y:auto !important;';
      modalBody.scrollTop = 0;
    }
  }
  window.addEventListener('resize', layoutModalDialog);
  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', layoutModalDialog);
    window.visualViewport.addEventListener('scroll', layoutModalDialog);
  }
  var state = { panel: app.getAttribute('data-panel') || 'dashboard', query: {}, reqId: 0 };
  var confirmResolver = null;
  var i18n = primeFleetAdmin.i18n || {};
  var panelLabels = primeFleetAdmin.panelLabels || {};
  function t(key, fallback) {
    return (i18n[key] != null && i18n[key] !== '') ? i18n[key] : fallback;
  }
  function tReplace(key, fallback, map) {
    var s = String(t(key, fallback));
    Object.keys(map || {}).forEach(function (k) {
      s = s.split(k).join(String(map[k]));
    });
    return s;
  }

  /*
   * Strip any leftover inline chrome styles from older PF builds (cache),
   * then leave WordPress #wpcontent alone.
   */
  function pfClampAdminWidth() {
    try {
      ['html', 'body', '#wpwrap', '#wpcontent', '#wpbody', '#wpbody-content', '#wpfooter'].forEach(function (sel) {
        var el = sel === 'html' ? document.documentElement : (sel === 'body' ? document.body : document.querySelector(sel));
        if (!el || !el.style) return;
        ['width', 'max-width', 'min-width', 'margin-left', 'height', 'max-height', 'overflow', 'overflow-x', 'overflow-y'].forEach(function (p) {
          el.style.removeProperty(p);
        });
      });
      var bodyContent = document.getElementById('wpbody-content');
      if (bodyContent) bodyContent.style.float = 'none';
    } catch (eClamp) { String(eClamp); }
  }
  window.pfClampAdminWidth = pfClampAdminWidth;
  pfClampAdminWidth();

  /* Clear any leftover min-height from older PF builds that forced empty scroll. */
  function pfClearForcedWrapHeight() {
    try {
      var wrap = document.getElementById('wpwrap');
      if (wrap && wrap.style) wrap.style.removeProperty('min-height');
    } catch (eClear) { String(eClear); }
  }

  /*
   * Sticky content column only when the console is shorter than the viewport.
   * Tall pages must stay static — sticky was pinning #wpcontent bottom to the
   * viewport and cropping the last cards / eating bottom space.
   */
  function pfSyncContentSticky() {
    try {
      var appEl = document.querySelector('.prime-fleet-app');
      if (!appEl || !document.body.classList.contains('prime-fleet-admin')) return;
      var bar = parseInt(window.getComputedStyle(document.documentElement).getPropertyValue('--wp-admin--admin-bar--height'), 10);
      if (!bar || bar < 0) bar = 32;
      var room = Math.max(240, window.innerHeight - bar - 48);
      var short = appEl.scrollHeight <= room;
      document.body.classList.toggle('prime-fleet-admin--short', short);
    } catch (eSticky) { String(eSticky); }
  }
  window.pfSyncContentSticky = pfSyncContentSticky;
  pfSyncContentSticky();
  window.addEventListener('resize', pfSyncContentSticky);

  function toast(msg, type) {
    if (!toastStack || !msg) return;
    type = type || 'success';
    if (type !== 'success' && type !== 'error' && type !== 'info') type = 'info';
    var titles = {
      success: t('saved', 'Saved'),
      error: t('couldNotSave', 'Couldn’t save'),
      info: t('notice', 'Notice')
    };
    var hold = type === 'info' ? 7200 : (type === 'error' ? 5600 : 3400);
    var el = document.createElement('div');
    el.className = 'prime-fleet-toast prime-fleet-toast--' + type;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.style.setProperty('--pf-toast-ms', hold + 'ms');
    el.innerHTML =
      '<div class="prime-fleet-toast__body">' +
      '<p class="prime-fleet-toast__title"></p>' +
      '<p class="prime-fleet-toast__text"></p>' +
      '</div>' +
      '<button type="button" class="prime-fleet-toast__x" aria-label="' + t('close', 'Close') + '">&times;</button>' +
      '<span class="prime-fleet-toast__timer" aria-hidden="true"></span>';
    el.querySelector('.prime-fleet-toast__title').textContent = titles[type];
    el.querySelector('.prime-fleet-toast__text').textContent = msg;

    var dismissTimer = 0;
    var removeTimer = 0;
    var remaining = hold;
    var startedAt = 0;
    var paused = false;
    var timerEl = el.querySelector('.prime-fleet-toast__timer');

    function clearToastTimers() {
      if (dismissTimer) window.clearTimeout(dismissTimer);
      if (removeTimer) window.clearTimeout(removeTimer);
      dismissTimer = 0;
      removeTimer = 0;
    }

    function dismissToast() {
      if (!el.parentNode || el.classList.contains('is-out')) return;
      clearToastTimers();
      if (timerEl) timerEl.style.animationPlayState = 'paused';
      el.classList.remove('is-in');
      el.classList.add('is-out');
      removeTimer = window.setTimeout(function () {
        if (el.parentNode) el.remove();
      }, 220);
    }

    function armDismiss(ms) {
      clearToastTimers();
      startedAt = Date.now();
      remaining = ms;
      dismissTimer = window.setTimeout(dismissToast, ms);
    }

    el.querySelector('.prime-fleet-toast__x').addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      dismissToast();
    });

    el.addEventListener('mouseenter', function () {
      if (paused || el.classList.contains('is-out')) return;
      paused = true;
      remaining = Math.max(400, remaining - (Date.now() - startedAt));
      if (dismissTimer) window.clearTimeout(dismissTimer);
      dismissTimer = 0;
      if (timerEl) timerEl.style.animationPlayState = 'paused';
    });
    el.addEventListener('mouseleave', function () {
      if (!paused || el.classList.contains('is-out')) return;
      paused = false;
      if (timerEl) {
        timerEl.style.animationPlayState = 'running';
      }
      armDismiss(remaining);
    });

    toastStack.appendChild(el);
    requestAnimationFrame(function () {
      el.classList.add('is-in');
      armDismiss(hold);
    });
  }
  if (primeFleetAdmin) primeFleetAdmin.toast = toast;

  function showLoader(label) {
    if (!loaderEl) return;
    var text = loaderEl.querySelector('.prime-fleet-loader__label');
    if (text) text.textContent = label || t('loading', 'Loading…');
    loaderEl.hidden = false;
    if (panelEl) panelEl.classList.add('is-loading');
  }

  function hideLoader() {
    if (loaderEl) loaderEl.hidden = true;
    panelEl.classList.remove('is-loading');
  }

  function closeRibbonMenus(except) {
    app.querySelectorAll('.prime-fleet-ribbon__group').forEach(function (group) {
      if (except && group === except) return;
      var menu = group.querySelector('.prime-fleet-ribbon__menu');
      var toggle = group.querySelector('.prime-fleet-ribbon__toggle');
      if (menu) menu.hidden = true;
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    });
  }

  function closeMore() {
    closeRibbonMenus(null);
  }

  function askConfirm(message, title, okLabel, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (!confirmEl) {
        resolve(window.confirm(message || t('areYouSure', 'Are you sure?')));
        return;
      }
      confirmResolver = resolve;
      if (confirmTitle) confirmTitle.textContent = title || t('pleaseConfirm', 'Please confirm');
      if (confirmText) confirmText.textContent = message || t('confirmContinue', 'Are you sure you want to continue?');
      if (confirmOk) confirmOk.textContent = okLabel || t('delete', 'Delete');
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
    modalTitle.textContent = title || 'Edit';
    modalBody.innerHTML = '';
    /* Content-only templates (e.g. calendar day list) — no form required. */
    if (!form) {
      var content = src.querySelector('[data-pf-modal-content]') || src;
      var bodyClone = content.cloneNode(true);
      bodyClone.hidden = false;
      bodyClone.removeAttribute('id');
      bodyClone.removeAttribute('hidden');
      modalBody.appendChild(bodyClone);
      modalEl.hidden = false;
      document.body.classList.add('prime-fleet-modal-open');
      requestAnimationFrame(layoutModalDialog);
      return;
    }
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
    requestAnimationFrame(layoutModalDialog);
    bindModalForm(clone);
    enhanceSelects(clone);
    enhanceDateTimes(clone);
    hydrateMultiChecks(clone, record);
    hydrateConditionBuilders(clone, record);
    bindConditionBuilders(clone);
    var focusable = clone.querySelector(
      '.prime-fleet-select__trigger, .prime-fleet-dt__trigger, input:not([type="hidden"]):not(.prime-fleet-dt__native):not(.prime-fleet-select__native), textarea'
    );
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
    modalEl.removeAttribute('style');
    var dialog = modalEl.querySelector('.prime-fleet-modal__dialog');
    if (dialog) dialog.removeAttribute('style');
    if (modalBody) {
      modalBody.removeAttribute('style');
      modalBody.innerHTML = '';
    }
    if (!confirmEl || confirmEl.hidden) document.body.classList.remove('prime-fleet-modal-open');
  }

  function closeAllSelects(except) {
    document.querySelectorAll('.prime-fleet-select.is-open').forEach(function (wrap) {
      if (except && wrap === except) return;
      if (typeof wrap._sleClose === 'function') wrap._sleClose();
    });
    closeAllDateTimes(except && except.classList && except.classList.contains('prime-fleet-dt') ? except : null);
  }

  function closeAllDateTimes(except) {
    document.querySelectorAll('.prime-fleet-dt.is-open').forEach(function (wrap) {
      if (except && wrap === except) return;
      if (typeof wrap._pfDtClose === 'function') wrap._pfDtClose();
    });
  }

  function pad2(n) {
    return (n < 10 ? '0' : '') + n;
  }

  function parseDateTimeLocal(val) {
    if (!val || typeof val !== 'string') return null;
    var m = val.match(/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?/);
    if (!m) return null;
    return {
      y: Number(m[1]),
      mo: Number(m[2]),
      d: Number(m[3]),
      h: m[4] != null ? Number(m[4]) : 0,
      mi: m[5] != null ? Number(m[5]) : 0
    };
  }

  function formatDateTimeLocal(parts, dateOnly) {
    if (!parts) return '';
    var day = parts.y + '-' + pad2(parts.mo) + '-' + pad2(parts.d);
    if (dateOnly) return day;
    return day + 'T' + pad2(parts.h) + ':' + pad2(parts.mi);
  }

  function formatDateTimeLabel(parts, dateOnly) {
    if (!parts) return dateOnly ? t('selectDate', 'Select date') : t('selectDateTime', 'Select date & time');
    try {
      var dt = new Date(parts.y, parts.mo - 1, parts.d, dateOnly ? 12 : parts.h, dateOnly ? 0 : parts.mi);
      if (dateOnly) {
        return dt.toLocaleDateString(undefined, {
          weekday: 'short',
          month: 'short',
          day: 'numeric',
          year: 'numeric'
        });
      }
      return dt.toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
      });
    } catch (e) {
      return formatDateTimeLocal(parts, dateOnly);
    }
  }

  function enhanceDateTimes(root) {
    if (!root) return;
    root.querySelectorAll('input[type="datetime-local"], input[type="date"]').forEach(function (input) {
      if (input.hasAttribute('data-pf-native')) return;
      /* Keep hidden modal templates raw — cloneNode drops listeners. */
      if (input.closest('.prime-fleet-form-source')) return;

      var existing = input.closest('.prime-fleet-dt');
      if (existing) {
        if (typeof existing._pfDtClose === 'function') return;
        /* Dead wrapper from a cloned enhanced template — unwrap then rebuild. */
        var host = existing.parentNode;
        if (host) {
          host.insertBefore(input, existing);
          existing.remove();
        }
        input.classList.remove('prime-fleet-dt__native');
        input.removeAttribute('tabindex');
      }

      var dateOnly = (input.type === 'date');
      var wrap = document.createElement('div');
      wrap.className = 'prime-fleet-dt' + (dateOnly ? ' prime-fleet-dt--date' : '');
      if (input.disabled) wrap.classList.add('is-disabled');
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
      input.classList.add('prime-fleet-dt__native');
      input.setAttribute('tabindex', '-1');

      var trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.className = 'prime-fleet-dt__trigger';
      trigger.setAttribute('aria-haspopup', 'dialog');
      trigger.setAttribute('aria-expanded', 'false');
      if (input.id) trigger.setAttribute('aria-labelledby', input.id);
      if (input.disabled) trigger.disabled = true;

      var valueEl = document.createElement('span');
      valueEl.className = 'prime-fleet-dt__value';
      var icon = document.createElement('span');
      icon.className = 'prime-fleet-dt__icon';
      icon.setAttribute('aria-hidden', 'true');
      trigger.appendChild(valueEl);
      trigger.appendChild(icon);

      var pop = document.createElement('div');
      pop.className = 'prime-fleet-dt__pop';
      pop.setAttribute('role', 'dialog');
      pop.hidden = true;

      wrap.appendChild(trigger);
      wrap.appendChild(pop);

      var view = { y: 0, mo: 0 };
      var draft = null;

      function syncLabel() {
        var parts = parseDateTimeLocal(input.value);
        valueEl.textContent = formatDateTimeLabel(parts, dateOnly);
        wrap.classList.toggle('is-empty', !parts);
        wrap.classList.toggle('is-disabled', !!input.disabled);
        trigger.disabled = !!input.disabled;
      }

      function commitDraft(closeAfter) {
        if (!draft) return;
        input.value = formatDateTimeLocal(draft, dateOnly);
        try {
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (e) { String(e); }
        syncLabel();
        if (closeAfter) close();
      }

      function renderPop() {
        if (!draft) {
          var now = new Date();
          draft = {
            y: now.getFullYear(),
            mo: now.getMonth() + 1,
            d: now.getDate(),
            h: now.getHours(),
            mi: Math.floor(now.getMinutes() / 5) * 5
          };
        }
        if (!view.y) {
          view.y = draft.y;
          view.mo = draft.mo;
        }

        var monthStart = new Date(view.y, view.mo - 1, 1);
        var startDow = (monthStart.getDay() + 6) % 7; /* Mon-first */
        var daysInMonth = new Date(view.y, view.mo, 0).getDate();
        var today = new Date();
        var monthNames = (i18n.months && i18n.months.length === 12)
          ? i18n.months
          : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        var yMin = today.getFullYear() - 2;
        var yMax = today.getFullYear() + 5;
        var labelPrev = t('prevMonth', 'Previous month');
        var labelNext = t('nextMonth', 'Next month');
        var labelMonth = t('month', 'Month');
        var labelYear = t('year', 'Year');
        var labelTime = t('time', 'Time');
        var labelHour = t('hour', 'Hour');
        var labelMinute = t('minute', 'Minute');
        var labelMin = t('min', 'Min');

        var html = '';
        html += '<div class="prime-fleet-dt__body">';
        html += '<div class="prime-fleet-dt__cal">';
        html += '<div class="prime-fleet-dt__nav">';
        html += '<button type="button" class="prime-fleet-dt__nav-btn" data-pf-dt-nav="-1" aria-label="' + labelPrev + '"><svg class="prime-fleet-chev" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M10.5 3.5L5.5 8l5 4.5"/></svg></button>';
        html += '<div class="prime-fleet-dt__nav-mid">';
        html += '<select class="prime-fleet-dt__month-sel" data-pf-dt-month data-pf-native="1" aria-label="' + labelMonth + '">';
        var mi;
        for (mi = 1; mi <= 12; mi++) {
          html += '<option value="' + mi + '"' + (view.mo === mi ? ' selected' : '') + '>' + monthNames[mi - 1] + '</option>';
        }
        html += '</select>';
        html += '<select class="prime-fleet-dt__year-sel" data-pf-dt-year data-pf-native="1" aria-label="' + labelYear + '">';
        var yi;
        for (yi = yMin; yi <= yMax; yi++) {
          html += '<option value="' + yi + '"' + (view.y === yi ? ' selected' : '') + '>' + yi + '</option>';
        }
        html += '</select>';
        html += '</div>';
        html += '<button type="button" class="prime-fleet-dt__nav-btn" data-pf-dt-nav="1" aria-label="' + labelNext + '"><svg class="prime-fleet-chev" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M5.5 3.5l5 4.5-5 4.5"/></svg></button>';
        html += '</div>';
        html += '<div class="prime-fleet-dt__dow" aria-hidden="true"><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span></div>';
        html += '<div class="prime-fleet-dt__grid" role="grid">';
        var i;
        for (i = 0; i < startDow; i++) html += '<span class="prime-fleet-dt__cell is-blank"></span>';
        for (i = 1; i <= daysInMonth; i++) {
          var selected = draft.y === view.y && draft.mo === view.mo && draft.d === i;
          var isToday = today.getFullYear() === view.y && (today.getMonth() + 1) === view.mo && today.getDate() === i;
          html += '<button type="button" class="prime-fleet-dt__cell' + (selected ? ' is-selected' : '') + (isToday ? ' is-today' : '') + '" data-pf-dt-day="' + i + '">' + i + '</button>';
        }
        html += '</div></div>';
        if (!dateOnly) {
          html += '<div class="prime-fleet-dt__time" role="group" aria-label="' + labelTime + '">';
          html += '<p class="prime-fleet-dt__time-title">' + labelTime + '</p>';
          html += '<div class="prime-fleet-dt__wheels">';
          html += '<div class="prime-fleet-dt__wheel" data-pf-dt-wheel="hour">';
          html += '<span class="prime-fleet-dt__wheel-label">' + labelHour + '</span>';
          html += '<div class="prime-fleet-dt__wheel-track" data-pf-dt-hour-track tabindex="0" role="listbox" aria-label="' + labelHour + '">';
          for (i = 0; i < 24; i++) {
            html += '<button type="button" role="option" class="prime-fleet-dt__wheel-item' + (draft.h === i ? ' is-selected' : '') + '" data-pf-dt-hour="' + i + '" aria-selected="' + (draft.h === i ? 'true' : 'false') + '">' + pad2(i) + '</button>';
          }
          html += '</div></div>';
          html += '<span class="prime-fleet-dt__wheel-sep" aria-hidden="true">:</span>';
          html += '<div class="prime-fleet-dt__wheel" data-pf-dt-wheel="min">';
          html += '<span class="prime-fleet-dt__wheel-label">' + labelMin + '</span>';
          html += '<div class="prime-fleet-dt__wheel-track" data-pf-dt-min-track tabindex="0" role="listbox" aria-label="' + labelMinute + '">';
          for (i = 0; i < 60; i += 5) {
            html += '<button type="button" role="option" class="prime-fleet-dt__wheel-item' + (draft.mi === i ? ' is-selected' : '') + '" data-pf-dt-min="' + i + '" aria-selected="' + (draft.mi === i ? 'true' : 'false') + '">' + pad2(i) + '</button>';
          }
          html += '</div></div>';
          html += '</div></div>';
        }
        html += '</div>';
        html += '<div class="prime-fleet-dt__actions">';
        html += '<button type="button" class="prime-fleet-btn prime-fleet-btn--ghost prime-fleet-btn--compact" data-pf-dt-today>' + t('today', 'Today') + '</button>';
        html += '<button type="button" class="prime-fleet-btn prime-fleet-btn--compact" data-pf-dt-done>' + t('done', 'Done') + '</button>';
        html += '</div>';
        pop.innerHTML = html;
        if (!dateOnly) syncWheelScroll();
      }

      function syncWheelScroll() {
        var hourTrack = pop.querySelector('[data-pf-dt-hour-track]');
        var minTrack = pop.querySelector('[data-pf-dt-min-track]');
        var hourSel = pop.querySelector('[data-pf-dt-hour].is-selected');
        var minSel = pop.querySelector('[data-pf-dt-min].is-selected');
        if (hourTrack && hourSel) {
          hourTrack.scrollTop = Math.max(0, hourSel.offsetTop - (hourTrack.clientHeight / 2) + (hourSel.offsetHeight / 2));
        }
        if (minTrack && minSel) {
          minTrack.scrollTop = Math.max(0, minSel.offsetTop - (minTrack.clientHeight / 2) + (minSel.offsetHeight / 2));
        }
      }

      function positionPop() {
        /* Portal to <body> — modal overflow must never clip the ticker. */
        if (pop.parentNode !== document.body) document.body.appendChild(pop);

        var r = trigger.getBoundingClientRect();
        var pad = 10;
        var gap = 6;
        var vw = window.innerWidth || 0;
        var vh = window.innerHeight || 0;
        var boxEl = trigger.closest('.prime-fleet-modal__dialog');
        var box = boxEl ? boxEl.getBoundingClientRect() : null;

        /* Keep the pop inside the modal card when open from a popup; else viewport. */
        var minX = pad;
        var maxX = vw - pad;
        var minY = pad;
        var maxY = vh - pad;
        if (box) {
          minX = Math.max(pad, Math.round(box.left) + 8);
          maxX = Math.min(vw - pad, Math.round(box.right) - 8);
          minY = Math.max(pad, Math.round(box.top) + 8);
          maxY = Math.min(vh - pad, Math.round(box.bottom) - 8);
        }
        var boxW = Math.max(240, maxX - minX);
        var boxH = Math.max(200, maxY - minY);
        var width = Math.min(dateOnly ? 300 : 400, boxW);
        var maxH = Math.min(460, boxH);

        pop.style.cssText = [
          'position:fixed',
          'z-index:100120',
          'box-sizing:border-box',
          'width:' + width + 'px',
          'max-height:' + maxH + 'px',
          'overflow:auto',
          'left:-9999px',
          'top:0',
          'right:auto',
          'bottom:auto',
          'margin:0',
          'visibility:hidden'
        ].join(' !important;') + ' !important;';

        var naturalH = Math.ceil(pop.getBoundingClientRect().height) || (dateOnly ? 340 : 400);
        var popH = Math.min(maxH, naturalH);
        var popW = width;

        var spaceBelow = maxY - r.bottom - gap;
        var spaceAbove = r.top - minY - gap;
        var spaceRight = maxX - r.right - gap;
        var spaceLeft = r.left - minX - gap;

        var top;
        var left;
        var place = 'below';

        if (spaceBelow >= Math.min(popH, 220) || spaceBelow >= spaceAbove) {
          place = 'below';
          popH = Math.min(popH, Math.max(180, spaceBelow));
          top = r.bottom + gap;
        } else if (spaceAbove >= 180) {
          place = 'above';
          popH = Math.min(popH, Math.max(180, spaceAbove));
          top = r.top - popH - gap;
        } else if (spaceRight >= popW || spaceRight >= spaceLeft) {
          place = 'right';
          popH = Math.min(popH, boxH);
          top = Math.min(Math.max(minY, r.top), maxY - popH);
          left = r.right + gap;
        } else {
          place = 'left';
          popH = Math.min(popH, boxH);
          top = Math.min(Math.max(minY, r.top), maxY - popH);
          left = r.left - popW - gap;
        }

        if (place === 'below' || place === 'above') {
          left = r.left;
          if (left + popW > maxX) left = maxX - popW;
          if (left < minX) left = minX;
          if (place === 'below' && top + popH > maxY) top = Math.max(minY, maxY - popH);
          if (place === 'above' && top < minY) top = minY;
        } else {
          if (left + popW > maxX) left = maxX - popW;
          if (left < minX) left = minX;
        }

        top = Math.max(minY, Math.min(top, maxY - popH));
        left = Math.max(minX, Math.min(left, maxX - popW));

        pop.style.setProperty('visibility', 'visible', 'important');
        pop.style.setProperty('left', Math.round(left) + 'px', 'important');
        pop.style.setProperty('top', Math.round(top) + 'px', 'important');
        pop.style.setProperty('width', Math.round(popW) + 'px', 'important');
        pop.style.setProperty('max-height', Math.round(popH) + 'px', 'important');
        if (!dateOnly) {
          requestAnimationFrame(function () { syncWheelScroll(); });
        }
      }

      function open() {
        if (input.disabled) return;
        closeAllSelects(wrap);
        var parts = parseDateTimeLocal(input.value);
        draft = parts ? Object.assign({}, parts) : null;
        if (draft) {
          if (dateOnly) {
            draft.h = 0;
            draft.mi = 0;
          } else {
            draft.mi = Math.round(draft.mi / 5) * 5;
            if (draft.mi >= 60) { draft.mi = 55; }
          }
        }
        view = draft ? { y: draft.y, mo: draft.mo } : { y: 0, mo: 0 };
        renderPop();
        pop.hidden = false;
        wrap.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
        positionPop();
      }

      function close() {
        pop.hidden = true;
        wrap.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
        pop.removeAttribute('style');
        if (pop.parentNode !== wrap) wrap.appendChild(pop);
      }

      wrap._pfDtClose = close;
      wrap._pfDtPosition = positionPop;

      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        if (wrap.classList.contains('is-open')) close();
        else open();
      });

      pop.addEventListener('click', function (e) {
        e.stopPropagation();
        var t = e.target.closest('[data-pf-dt-nav], [data-pf-dt-day], [data-pf-dt-today], [data-pf-dt-done], [data-pf-dt-hour], [data-pf-dt-min]');
        if (!t) return;
        if (t.hasAttribute('data-pf-dt-nav')) {
          var delta = Number(t.getAttribute('data-pf-dt-nav')) || 0;
          var nm = new Date(view.y, view.mo - 1 + delta, 1);
          view = { y: nm.getFullYear(), mo: nm.getMonth() + 1 };
          renderPop();
          positionPop();
          return;
        }
        if (t.hasAttribute('data-pf-dt-day')) {
          if (!draft) draft = { y: view.y, mo: view.mo, d: 1, h: dateOnly ? 0 : 9, mi: 0 };
          draft.y = view.y;
          draft.mo = view.mo;
          draft.d = Number(t.getAttribute('data-pf-dt-day'));
          if (dateOnly) {
            commitDraft(true);
            return;
          }
          renderPop();
          positionPop();
          return;
        }
        if (t.hasAttribute('data-pf-dt-hour') || t.hasAttribute('data-pf-dt-min')) {
          if (!draft) draft = { y: view.y, mo: view.mo, d: 1, h: 9, mi: 0 };
          if (t.hasAttribute('data-pf-dt-hour')) draft.h = Number(t.getAttribute('data-pf-dt-hour'));
          if (t.hasAttribute('data-pf-dt-min')) draft.mi = Number(t.getAttribute('data-pf-dt-min'));
          pop.querySelectorAll('[data-pf-dt-hour], [data-pf-dt-min]').forEach(function (btn) {
            var isH = btn.hasAttribute('data-pf-dt-hour');
            var val = Number(isH ? btn.getAttribute('data-pf-dt-hour') : btn.getAttribute('data-pf-dt-min'));
            var on = isH ? draft.h === val : draft.mi === val;
            btn.classList.toggle('is-selected', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
          });
          syncWheelScroll();
          return;
        }
        if (t.hasAttribute('data-pf-dt-today')) {
          var now = new Date();
          draft = {
            y: now.getFullYear(),
            mo: now.getMonth() + 1,
            d: now.getDate(),
            h: dateOnly ? 0 : now.getHours(),
            mi: dateOnly ? 0 : Math.floor(now.getMinutes() / 5) * 5
          };
          view = { y: draft.y, mo: draft.mo };
          if (dateOnly) {
            commitDraft(true);
            return;
          }
          renderPop();
          positionPop();
          return;
        }
        if (t.hasAttribute('data-pf-dt-done')) {
          commitDraft(true);
        }
      });

      pop.addEventListener('change', function (e) {
        var t = e.target;
        if (!t) return;
        if (t.hasAttribute('data-pf-dt-month')) {
          view.mo = Number(t.value) || view.mo;
          renderPop();
          positionPop();
          return;
        }
        if (t.hasAttribute('data-pf-dt-year')) {
          view.y = Number(t.value) || view.y;
          renderPop();
          positionPop();
        }
      });

      input.addEventListener('change', syncLabel);
      syncLabel();
    });
  }

  function enhanceSelects(root) {
    if (!root) return;
    root.querySelectorAll('select').forEach(function (sel) {
      if (sel.multiple || sel.size > 1) return;
      if (sel.hasAttribute('data-pf-native')) return;
      if (sel.closest('.prime-fleet-form-source')) return;
      /* Filter / list toolbars stay WP-native — never wrap in custom dark menus. */
      if (
        sel.classList.contains('prime-fleet-cal__ctrl') ||
        sel.classList.contains('prime-fleet-table-tools__select') ||
        sel.closest(
          '.prime-fleet-table-tools, .prime-fleet-cal__filters, .prime-fleet-cal__jump, .prime-fleet-cal__nav, .prime-fleet-cal__toolbar, .prime-fleet-dispatch-bar, .prime-fleet-reports__filters'
        )
      ) {
        return;
      }

      var existingSel = sel.closest('.prime-fleet-select');
      if (existingSel) {
        if (typeof existingSel._sleClose === 'function') return;
        var selHost = existingSel.parentNode;
        if (selHost) {
          selHost.insertBefore(sel, existingSel);
          existingSel.remove();
        }
        sel.classList.remove('prime-fleet-select__native');
        sel.removeAttribute('tabindex');
      }

      var wrap = document.createElement('div');
      wrap.className = 'prime-fleet-select';
      if (sel.closest('.prime-fleet-field')) {
        wrap.classList.add('prime-fleet-select--field');
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

      var searchable = !sel.hasAttribute('data-pf-no-search') && (sel.hasAttribute('data-pf-search') || sel.options.length >= 8);
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
          search.setAttribute('placeholder', t('search', 'Search…'));
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
              try { again.setSelectionRange(len, len); } catch (err) { String(err); /* optional browser API / storage */ }
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
          empty.textContent = t('noMatches', 'No matches');
          list.appendChild(empty);
        }
      }

      function positionMenu() {
        if (menu.parentNode !== document.body) document.body.appendChild(menu);
        var r = btn.getBoundingClientRect();
        var pad = 10;
        var gap = 6;
        var vw = window.innerWidth || 0;
        var vh = window.innerHeight || 0;
        var width = Math.max(r.width, searchable ? 260 : 160);
        width = Math.min(width, Math.min(searchable ? 420 : 360, vw - pad * 2));
        menu.style.position = 'fixed';
        menu.style.zIndex = '100120';
        menu.style.minWidth = width + 'px';
        menu.style.maxWidth = width + 'px';
        menu.style.left = '-9999px';
        menu.style.top = '0';
        menu.style.visibility = 'hidden';
        requestAnimationFrame(function () {
          var mr = menu.getBoundingClientRect();
          var menuH = mr.height || 200;
          var menuW = Math.max(width, mr.width || width);
          var spaceBelow = vh - r.bottom - pad;
          var spaceAbove = r.top - pad;
          var openAbove = spaceBelow < menuH && spaceAbove > spaceBelow;
          var top = openAbove
            ? Math.max(pad, r.top - menuH - gap)
            : Math.min(r.bottom + gap, Math.max(pad, vh - menuH - pad));
          var left = r.left;
          if (left + menuW > vw - pad) left = Math.max(pad, r.right - menuW);
          if (left < pad) left = pad;
          if (left + menuW > vw - pad) left = Math.max(pad, vw - pad - menuW);
          menu.style.visibility = 'visible';
          menu.style.left = left + 'px';
          menu.style.top = top + 'px';
        });
      }

      function open() {
        if (sel.disabled) return;
        closeAllSelects(wrap);
        closeAllDateTimes();
        rebuildMenu('');
        wrap.classList.add('is-open');
        menu.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        if (menu.parentNode !== document.body) document.body.appendChild(menu);
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
      wrap._slePosition = positionMenu;

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

  function setNavGroupOpen(group, open) {
    if (!group) return;
    var toggle = group.querySelector('.prime-fleet-app-nav__toggle');
    var list = group.querySelector('.prime-fleet-app-nav__sublist');
    group.classList.toggle('is-open', !!open);
    if (toggle) {
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.classList.toggle('is-active', !!open);
    }
    if (list) list.hidden = !open;
  }

  function panelToPage(panel) {
    var map = (primeFleetAdmin && primeFleetAdmin.panelPages) || {};
    if (map[panel]) return map[panel];
    if (panel === 'dashboard') return 'prime-fleet';
    return 'prime-fleet-' + String(panel || '').replace(/_/g, '-');
  }

  function pageToPanel(page) {
    var map = (primeFleetAdmin && primeFleetAdmin.pageToPanel) || {};
    if (map[page]) return map[page];
    return 'dashboard';
  }

  function setWpMenuCurrent(panel) {
    var page = panelToPage(panel === 'service_edit' ? 'services' : panel);
    var menu = document.getElementById('toplevel_page_prime-fleet');
    if (!menu) return;
    menu.querySelectorAll('.wp-submenu li').forEach(function (li) {
      li.classList.remove('current');
      var a = li.querySelector('a');
      if (!a) return;
      try {
        var href = a.getAttribute('href') || '';
        var u = new URL(href, window.location.origin);
        var slug = u.searchParams.get('page') || '';
        if (slug === page) li.classList.add('current');
      } catch (eMenu) { String(eMenu); }
    });
  }

  function setActiveTab(panel) {
    /* Legacy in-app nav (if cached HTML still has it). */
    var activeKey = panel === 'service_edit' ? 'services' : panel;
    app.querySelectorAll('.prime-fleet-app-nav__link[data-pf-panel]').forEach(function (btn) {
      var on = btn.getAttribute('data-pf-panel') === activeKey;
      btn.classList.toggle('is-active', on);
      if (btn.hasAttribute('aria-current')) {
        btn.setAttribute('aria-current', on ? 'page' : 'false');
      }
    });
    app.querySelectorAll('.prime-fleet-app-nav__group').forEach(function (group) {
      var hasActive = !!group.querySelector('.prime-fleet-app-nav__link.is-active');
      if (hasActive) setNavGroupOpen(group, true);
      else {
        var toggle = group.querySelector('.prime-fleet-app-nav__toggle');
        if (toggle) toggle.classList.remove('is-active');
      }
    });
    app.querySelectorAll('.prime-fleet-ribbon__btn[data-pf-panel], .prime-fleet-ribbon__menu-item[data-pf-panel], .prime-fleet-ribbon__logo[data-pf-panel]').forEach(function (btn) {
      var on = btn.getAttribute('data-pf-panel') === panel;
      btn.classList.toggle('is-active', on);
      if (btn.hasAttribute('aria-current')) {
        btn.setAttribute('aria-current', on ? 'page' : 'false');
      }
    });
    if (shellTitle) {
      shellTitle.textContent = panelLabels[panel] || panel;
    }
    app.setAttribute('data-panel', panel);
    app.setAttribute('data-pf-page', panelToPage(panel));
    setWpMenuCurrent(panel);
  }

  function syncTopbarFromPanel() {
    if (!topbarActions) return;
    topbarActions.innerHTML = '';
    var head = panelEl.querySelector('.prime-fleet-panel-head');
    if (!head) return;
    var actions = head.querySelector('.prime-fleet-panel-head__actions');
    if (actions && actions.childNodes.length) {
      while (actions.firstChild) {
        topbarActions.appendChild(actions.firstChild);
      }
    }
    var titleEl = head.querySelector('.prime-fleet-panel-head__title');
    if (titleEl && shellTitle) {
      shellTitle.textContent = titleEl.textContent.replace(/\s+/g, ' ').trim();
    }
    head.classList.add('is-lifted');
  }

  function runPanelLoad(panel, query, opts) {
    /* Instant page load — no View Transition / GSAP wait on AJAX. */
    return loadPanel(panel, query, opts || {});
  }

  function parseAjax(r) {
    return r.text().then(function (text) {
      var json = null;
      try { json = text ? JSON.parse(text) : null; } catch (e) { json = null; }
      if (!r.ok || !json) {
        var msg = (json && json.data && json.data.message) || tReplace('serverError', 'Server error (%s). Check PHP logs.', { '%s': r.status });
        return { success: false, data: { message: msg } };
      }
      return json;
    });
  }

  function applyPanelResult(json, opts) {
    opts = opts || {};
    if (!json || !json.success) {
      toast((json && json.data && json.data.message) || t('requestFailed', 'Request failed'), 'error');
      return;
    }
    panelEl.innerHTML = json.data.html;
    if (shellTitle) {
      shellTitle.textContent = panelLabels[state.panel] || json.data.title || state.panel;
    }
    if (topbarMeta) {
      var when = new Date();
      var consoleLabel = t('operationsConsole', 'Operations console');
      try {
        topbarMeta.textContent = when.toLocaleDateString(undefined, {
          weekday: 'long', month: 'short', day: 'numeric', year: 'numeric'
        }) + ' · ' + consoleLabel;
      } catch (eMeta) {
        topbarMeta.textContent = consoleLabel;
      }
    }
    if (json.data.toast) toast(json.data.toast, json.data.toast_type || 'success');
    else if (opts.toast) toast(opts.toast, opts.toastType || 'success');

    var nav = panelEl.querySelector('[data-pf-navigate]');
    if (nav) {
      var next = nav.getAttribute('data-pf-navigate');
      var q = {};
      try { q = JSON.parse(nav.getAttribute('data-pf-query') || '{}'); } catch (e) { String(e); /* optional browser API / storage */ }
      closeModal();
      loadPanel(next, q);
      return;
    }
    syncTopbarFromPanel();
    bindPanel();
    bindDashPagers(panelEl);
    bindNotesCarousel(panelEl);
    enhanceSelects(panelEl);
    enhanceDateTimes(panelEl);
    bindConditionBuilders(panelEl);
    bindSettingsTabs(panelEl);
    bindMergeTagCopy(panelEl);
    pfClampAdminWidth();
    pfClearForcedWrapHeight();
    pfSyncContentSticky();
  }

  /** Enhance SSR boot HTML without a second AJAX fill / loader flash. */
  function mountSsrPanel() {
    if (!panelEl || !panelEl.children.length) return false;
    setActiveTab(state.panel);
    if (shellTitle) {
      shellTitle.textContent = panelLabels[state.panel] || state.panel;
    }
    app.setAttribute('data-panel', state.panel);
    app.setAttribute('data-pf-page', panelToPage(state.panel));
    syncTopbarFromPanel();
    bindPanel();
    bindDashPagers(panelEl);
    bindNotesCarousel(panelEl);
    enhanceSelects(panelEl);
    enhanceDateTimes(panelEl);
    bindConditionBuilders(panelEl);
    bindSettingsTabs(panelEl);
    bindMergeTagCopy(panelEl);
    pfClampAdminWidth();
    pfClearForcedWrapHeight();
    pfSyncContentSticky();
    panelEl.removeAttribute('data-pf-ssr');
    return true;
  }

  function bindNotesCarousel(root) {
    if (!root) return;
    root.querySelectorAll('[data-pf-notes-carousel]').forEach(function (wrap) {
      if (wrap.getAttribute('data-pf-notes-bound')) return;
      wrap.setAttribute('data-pf-notes-bound', '1');
      var viewport = wrap.querySelector('.prime-fleet-dash-notes-viewport');
      var track = wrap.querySelector('[data-pf-notes-track]');
      var prev = wrap.querySelector('[data-pf-notes-prev]');
      var next = wrap.querySelector('[data-pf-notes-next]');
      var nav = wrap.querySelector('.prime-fleet-dash-notes__nav');
      if (!viewport || !track) return;
      var cards = Array.prototype.slice.call(track.querySelectorAll('.prime-fleet-dash-note'));
      if (!cards.length) return;
      var index = 0;
      var gap = 8;
      var visible = 4;
      var step = 0;

      function layout() {
        var avail = Math.max(0, viewport.clientWidth || wrap.clientWidth || 0);
        /* Exactly N full cards fill the viewport — no partial peek. */
        visible = Math.min(4, Math.max(1, cards.length));
        if (avail > 0 && cards.length > 4) {
          visible = 4;
        } else if (avail > 0 && cards.length <= 4) {
          visible = cards.length;
        }
        var cardW = visible > 0 ? (avail - gap * (visible - 1)) / visible : avail;
        if (cardW < 1) cardW = 1;
        step = cardW + gap;
        cards.forEach(function (card) {
          card.style.flex = '0 0 ' + cardW + 'px';
          card.style.width = cardW + 'px';
          card.style.minWidth = cardW + 'px';
          card.style.maxWidth = cardW + 'px';
        });
      }

      function maxIndex() {
        return Math.max(0, cards.length - visible);
      }

      function paint() {
        layout();
        var max = maxIndex();
        if (index > max) index = max;
        if (index < 0) index = 0;
        track.style.transform = 'translateX(' + (-index * step) + 'px)';
        if (prev) prev.disabled = index <= 0;
        if (next) next.disabled = index >= max;
        if (nav) nav.hidden = max <= 0;
      }

      if (prev) {
        prev.addEventListener('click', function () {
          if (prev.disabled) return;
          index -= 1;
          paint();
        });
      }
      if (next) {
        next.addEventListener('click', function () {
          if (next.disabled) return;
          index += 1;
          paint();
        });
      }

      var resizeTimer = null;
      if (typeof ResizeObserver !== 'undefined') {
        var ro = new ResizeObserver(function () {
          if (resizeTimer) window.clearTimeout(resizeTimer);
          resizeTimer = window.setTimeout(paint, 80);
        });
        ro.observe(viewport);
      } else {
        window.addEventListener('resize', function () {
          if (resizeTimer) window.clearTimeout(resizeTimer);
          resizeTimer = window.setTimeout(paint, 80);
        });
      }
      paint();
    });
  }

  function bindDashPagers(root) {
    if (!root) return;
    /* Shared card/list pager — Overview (data-pf-dash-pager) + Reports/etc (data-pf-card-pager). */
    root.querySelectorAll('[data-pf-dash-pager], [data-pf-card-pager]').forEach(function (wrap) {
      if (wrap.getAttribute('data-pf-pager-bound')) return;
      wrap.setAttribute('data-pf-pager-bound', '1');
      var per = parseInt(wrap.getAttribute('data-per-page') || '4', 10) || 4;
      var rows = Array.prototype.slice.call(wrap.querySelectorAll('[data-pf-dash-row]'));
      var meta = wrap.querySelector('[data-pf-dash-page-meta]');
      var prev = wrap.querySelector('[data-pf-dash-page="prev"]');
      var next = wrap.querySelector('[data-pf-dash-page="next"]');
      var numBtns = Array.prototype.slice.call(wrap.querySelectorAll('[data-pf-dash-page-num]'));
      if (!rows.length) return;
      var page = 0;
      var pages = Math.max(1, Math.ceil(rows.length / per));

      function paint() {
        var start = page * per;
        var end = Math.min(start + per, rows.length);
        rows.forEach(function (row, i) {
          row.hidden = i < start || i >= end;
        });
        if (meta) {
          meta.textContent = rows.length
            ? tReplace('showingRange', 'Showing %1$s–%2$s of %3$s', {
                '%1$s': start + 1,
                '%2$s': end,
                '%3$s': rows.length
              })
            : t('showingEmpty', 'Showing 0 of 0');
        }
        if (prev) prev.disabled = page <= 0;
        if (next) next.disabled = page >= pages - 1;
        numBtns.forEach(function (btn) {
          var n = parseInt(btn.getAttribute('data-pf-dash-page-num') || '1', 10) || 1;
          btn.classList.toggle('is-active', n === page + 1);
          btn.hidden = n > pages;
          btn.disabled = false;
        });
      }

      if (prev) {
        prev.onclick = function () {
          if (page <= 0) return;
          page -= 1;
          paint();
        };
      }
      if (next) {
        next.onclick = function () {
          if (page >= pages - 1) return;
          page += 1;
          paint();
        };
      }
      numBtns.forEach(function (btn) {
        btn.onclick = function () {
          var n = parseInt(btn.getAttribute('data-pf-dash-page-num') || '1', 10) || 1;
          var nextPage = Math.max(0, Math.min(pages - 1, n - 1));
          if (nextPage === page) return;
          page = nextPage;
          paint();
        };
      });
      paint();
    });
  }

  function bindMergeTagCopy(root) {
    root.querySelectorAll('[data-pf-copy]').forEach(function (btn) {
      if (btn.getAttribute('data-pf-copy-bound')) return;
      btn.setAttribute('data-pf-copy-bound', '1');
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var text = btn.getAttribute('data-pf-copy') || '';
        if (!text) return;
        var done = function () { toast(tReplace('copied', 'Copied %s', { '%s': text }), 'success'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done).catch(function () {
            window.prompt(t('copyMergeTag', 'Copy merge tag:'), text);
          });
          return;
        }
        window.prompt(t('copyMergeTag', 'Copy merge tag:'), text);
      });
    });
  }

  function bindSettingsTabs(root) {
    /* Settings sections are server pages (data-pf-panel + stab). Keep query in sync. */
    var form = root.querySelector('.prime-fleet-settings');
    if (!form) return;
    var tabInput = form.querySelector('#prime-fleet-settings-tab');
    var stab = (tabInput && tabInput.value) || (state.query && state.query.stab) || 'general';
    state.query = Object.assign({}, state.query, { stab: stab });
    try { sessionStorage.setItem('prime_fleet_settings_tab', stab); } catch (e) { String(e); }
  }

  function loadPanel(panel, query, opts) {
    opts = opts || {};
    closeAllSelects();
    state.panel = panel;
    state.query = query || {};
    /* Bookings landing = calendar (list only when explicitly requested). */
    if (panel === 'bookings' && !state.query.id && !state.query.view) {
      state.query.view = 'calendar';
    }
    setActiveTab(panel);
    closeMore();
    if (!opts.silent) showLoader(opts.loaderLabel || t('loading', 'Loading…'));

    var body = new FormData();
    body.append('action', 'prime_fleet_panel');
    body.append('nonce', primeFleetAdmin.nonce);
    body.append('panel', panel);
    Object.keys(state.query).forEach(function (k) { body.append('query[' + k + ']', state.query[k]); });

    try {
      var url = new URL(window.location.href);
      url.searchParams.set('page', panelToPage(panel));
      url.searchParams.delete('panel');
      ['service_id', 'edit', 'tab', 'level', 'prime_fleet_delete', '_wpnonce', 's', 'status', 'type', 'payment', 'paged', 'id', 'day', 'date_from', 'date_to', 'vehicle_id', 'mtab', 'stab', 'view', 'ym'].forEach(function (k) {
        url.searchParams.delete(k);
      });
      Object.keys(state.query).forEach(function (k) {
        if (k === 'prime_fleet_delete' || k === '_wpnonce') return;
        url.searchParams.set(k, state.query[k]);
      });
      window.history.replaceState({}, '', url.toString());
    } catch (e) { String(e); /* optional browser API / storage */ }

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
        toast(t('networkError', 'Network error'), 'error');
      });
  }

  function postForm(form, fromModal, submitter) {
    var body = new FormData(form);
    // Include the clicked submit button (Attach / Detach / etc.) — FormData omits it by default.
    if (submitter && submitter.name) {
      body.set(submitter.name, submitter.value);
    }
    // Keep settings / messages sub-views after save.
    if (form.classList.contains('prime-fleet-settings') || form.querySelector('#prime-fleet-settings-tab')) {
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
    showLoader(t('saving', 'Saving…'));

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
        toast(t('networkError', 'Network error'), 'error');
      });
  }

  function bindModalForm(form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      postForm(form, true, e.submitter);
    });
  }

  function isCsvExportUrl(href) {
    try {
      var u = new URL(href, window.location.origin);
      return u.searchParams.has('prime_fleet_reports_csv') || u.searchParams.has('prime_fleet_csv');
    } catch (err) {
      return /prime_fleet_(?:reports_)?csv=/.test(String(href || ''));
    }
  }

  function csvFilenameFromUrl(href) {
    try {
      var u = new URL(href, window.location.origin);
      if (u.searchParams.has('prime_fleet_reports_csv')) {
        return 'prime-fleet-reports.csv';
      }
    } catch (err) { String(err); }
    return 'prime-fleet-bookings.csv';
  }

  /** Force a real .csv file download (SPA must not hijack these links). */
  function downloadCsv(url, filename) {
    showLoader(t('exporting', 'Exporting…'));
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) {
        var ct = (r.headers.get('content-type') || '').toLowerCase();
        if (!r.ok) {
          throw new Error(tReplace('exportFailed', 'Export failed (%s)', { '%s': r.status }));
        }
        return r.blob().then(function (blob) {
          return { blob: blob, ct: ct };
        });
      })
      .then(function (pack) {
        hideLoader();
        var blob = pack.blob;
        if (pack.ct.indexOf('text/html') !== -1 || pack.ct.indexOf('application/json') !== -1) {
          toast(t('csvExportFailedRetry', 'CSV export failed — try again'), 'error');
          return;
        }
        /* Ensure Excel/Sheets open it as CSV even if server MIME was generic. */
        if (blob.type && blob.type.indexOf('csv') === -1 && blob.type.indexOf('text/plain') === -1) {
          blob = new Blob([blob], { type: 'text/csv;charset=utf-8' });
        } else if (!blob.type || blob.type === 'application/octet-stream') {
          blob = new Blob([blob], { type: 'text/csv;charset=utf-8' });
        }
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename || 'prime-fleet-export.csv';
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () {
          URL.revokeObjectURL(a.href);
          if (a.parentNode) a.parentNode.removeChild(a);
        }, 1500);
        toast(t('csvDownloaded', 'CSV downloaded'), 'success');
      })
      .catch(function () {
        hideLoader();
        toast(t('csvExportFailed', 'CSV export failed'), 'error');
      });
  }

  if (!app.getAttribute('data-pf-csv-bound')) {
    app.setAttribute('data-pf-csv-bound', '1');
    app.addEventListener('click', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!a || !app.contains(a)) return;
      if (!isCsvExportUrl(a.getAttribute('href') || a.href)) return;
      e.preventDefault();
      e.stopPropagation();
      var name = a.getAttribute('download') || csvFilenameFromUrl(a.href);
      if (!/\.csv$/i.test(name)) name += '.csv';
      downloadCsv(a.href, name);
    });
  }

  function bindPanel() {
    panelEl.querySelectorAll('a[href*="page=prime-fleet"]').forEach(function (a) {
      try {
        if (isCsvExportUrl(a.href)) return;
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
      } catch (err) { String(err); /* optional browser API / storage */ }
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
            submitter.getAttribute('data-pf-confirm-title') || t('pleaseConfirm', 'Please confirm'),
            submitter.getAttribute('data-pf-confirm-ok') || 'Confirm'
          ).then(function (ok) { if (ok) run(); });
          return;
        }
        run();
      });
    });
  }

  app.querySelectorAll('.prime-fleet-app-nav__group').forEach(function (group) {
    var toggle = group.querySelector('.prime-fleet-app-nav__toggle');
    if (!toggle) return;
    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var willOpen = !group.classList.contains('is-open');
      /* Accordion: one open group at a time keeps the rail short. */
      app.querySelectorAll('.prime-fleet-app-nav__group').forEach(function (other) {
        if (other === group) return;
        if (!other.querySelector('.prime-fleet-app-nav__link.is-active')) {
          setNavGroupOpen(other, false);
        }
      });
      setNavGroupOpen(group, willOpen);
    });
  });

  app.querySelectorAll('.prime-fleet-ribbon__group').forEach(function (group) {
    var toggle = group.querySelector('.prime-fleet-ribbon__toggle');
    var menu = group.querySelector('.prime-fleet-ribbon__menu');
    if (!toggle || !menu) return;
    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var willOpen = menu.hidden;
      closeRibbonMenus(group);
      menu.hidden = !willOpen;
      toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });

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
    var onSelect = !!(e.target.closest && (e.target.closest('.prime-fleet-select') || e.target.closest('.prime-fleet-select__menu')));
    var onDt = !!(e.target.closest && (e.target.closest('.prime-fleet-dt') || e.target.closest('.prime-fleet-dt__pop')));
    if (!onSelect) {
      /* Close selects only — do not wipe open date pickers from this path. */
      document.querySelectorAll('.prime-fleet-select.is-open').forEach(function (wrap) {
        if (typeof wrap._sleClose === 'function') wrap._sleClose();
      });
    }
    if (!onDt && !onSelect) {
      closeAllDateTimes();
    }

    var soonBtn = e.target.closest('[data-pf-coming-soon]');
    if (soonBtn) {
      e.preventDefault();
      askConfirm(
        soonBtn.getAttribute('data-pf-coming-soon') || t('comingSoonBody', 'This payment method is coming soon.'),
        soonBtn.getAttribute('data-pf-coming-soon-title') || t('comingSoon', 'Coming soon'),
        soonBtn.getAttribute('data-pf-coming-soon-ok') || t('gotIt', 'Got it'),
        { info: true }
      );
      return;
    }

    if (e.target.closest('[data-pf-modal-close]') && !e.target.closest('#prime-fleet-confirm')) {
      e.preventDefault();
      var closeNav = e.target.closest('[data-pf-modal-close][data-pf-panel]');
      var closePanel = closeNav ? closeNav.getAttribute('data-pf-panel') : '';
      var closeQuery = {};
      var closeRaw = closeNav ? closeNav.getAttribute('data-pf-query') : '';
      if (closeRaw) {
        try { closeQuery = JSON.parse(closeRaw) || {}; } catch (err) { closeQuery = {}; }
      }
      closeModal();
      if (closePanel) {
        closeMore();
        runPanelLoad(closePanel, closeQuery, { silent: false, loaderLabel: t('loading', 'Loading…') });
      }
      return;
    }

    if (!e.target.closest('.prime-fleet-ribbon__group')) closeMore();

    var confirmBtn = e.target.closest('[data-pf-confirm]');
    if (confirmBtn && confirmBtn.hasAttribute('data-pf-panel')) {
      e.preventDefault();
      askConfirm(
        confirmBtn.getAttribute('data-pf-confirm') || t('areYouSure', 'Are you sure?'),
        confirmBtn.getAttribute('data-pf-confirm-title') || t('delete', 'Delete'),
        confirmBtn.getAttribute('data-pf-confirm-ok') || t('delete', 'Delete')
      ).then(function (ok) {
        if (!ok) return;
        var panel = confirmBtn.getAttribute('data-pf-panel');
        var query = {};
        try { query = JSON.parse(confirmBtn.getAttribute('data-pf-query') || '{}'); } catch (err) { String(err); /* optional browser API / storage */ }
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

    if (e.target.closest('[data-pf-notify-refresh]')) return;

    var tab = e.target.closest('[data-pf-panel]');
    if (!tab || !app.contains(tab) || tab.classList.contains('prime-fleet-ribbon__toggle') || tab.classList.contains('prime-fleet-app-nav__toggle')) return;
    if (tab.hasAttribute('data-pf-confirm')) return;
    if (tab.tagName === 'FORM' || tab.hasAttribute('data-pf-table-filter')) return;
    if (tab.tagName === 'A') e.preventDefault();
    var p = tab.getAttribute('data-pf-panel');
    var q = {};
    var raw = tab.getAttribute('data-pf-query');
    if (raw) { try { q = JSON.parse(raw) || {}; } catch (err) { q = {}; } }
    /*
     * Same-panel click with no query:
     * - Ignore only when already on the panel root (empty query).
     * - If detail/filters are open (id, view, etc.), reset to the panel root.
     */
    if (!raw && p === state.panel) {
      var hasActiveQuery = !!(state.query && Object.keys(state.query).some(function (k) {
        return state.query[k] != null && String(state.query[k]) !== '';
      }));
      if (!hasActiveQuery) return;
      q = {};
    }

    closeMore();
    runPanelLoad(p, q, { silent: false, loaderLabel: t('loading', 'Loading…') });
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
    labelEl.textContent = names.length ? names.join(', ') : t('selectServices', 'Select services…');
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
    if (!e.target.closest || (!e.target.closest('.prime-fleet-dt') && !e.target.closest('.prime-fleet-dt__pop'))) {
      closeAllDateTimes();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (document.querySelector('.prime-fleet-select.is-open') || document.querySelector('.prime-fleet-dt.is-open')) {
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

  function repositionOpenOverlays() {
    document.querySelectorAll('.prime-fleet-select.is-open').forEach(function (wrap) {
      if (typeof wrap._slePosition === 'function') wrap._slePosition();
    });
    document.querySelectorAll('.prime-fleet-dt.is-open').forEach(function (wrap) {
      if (typeof wrap._pfDtPosition === 'function') wrap._pfDtPosition();
    });
  }

  window.addEventListener('resize', function () {
    closeAllMultiselects();
    repositionOpenOverlays();
  });
  document.addEventListener('scroll', function (e) {
    var t = e.target;
    // Keep open while scrolling inside overlays / time wheels.
    if (t && t.nodeType === 1 && t.closest) {
      if (
        t.closest('.prime-fleet-select__menu') ||
        t.closest('.prime-fleet-ms__panel') ||
        t.closest('.prime-fleet-ms') ||
        t.closest('.prime-fleet-dt__pop') ||
        t.closest('.prime-fleet-dt__wheel-track')
      ) {
        return;
      }
    }
    /* Reposition fixed overlays on page scroll instead of closing them. */
    repositionOpenOverlays();
    if (t === document || t === document.documentElement || t === document.body || !t) {
      return;
    }
    document.querySelectorAll('.prime-fleet-select.is-open').forEach(function (wrap) {
      if (typeof wrap._sleClose === 'function') wrap._sleClose();
    });
  }, true);

  function closeNotify() {
    var root = document.getElementById('prime-fleet-notify');
    var bell = document.getElementById('prime-fleet-notify-bell');
    var panel = document.getElementById('prime-fleet-notify-panel');
    if (!root || !panel) return;
    root.classList.remove('is-open');
    panel.hidden = true;
    if (bell) bell.setAttribute('aria-expanded', 'false');
  }

  function renderNotifyList(items) {
    var list = document.getElementById('prime-fleet-notify-list');
    if (!list) return;
    list.innerHTML = '';
    if (!items || !items.length) {
      var empty = document.createElement('li');
      empty.className = 'prime-fleet-notify__empty';
      empty.textContent = t('notificationsEmpty', 'All clear — no pending bookings or payment alerts.');
      list.appendChild(empty);
      return;
    }
    items.forEach(function (item) {
      var li = document.createElement('li');
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'prime-fleet-notify__item prime-fleet-notify__item--' + (item.tone || 'warn');
      btn.setAttribute('data-pf-panel', 'bookings');
      btn.setAttribute('data-pf-query', JSON.stringify({ id: item.id }));

      var top = document.createElement('span');
      top.className = 'prime-fleet-notify__item-top';
      var ref = document.createElement('span');
      ref.className = 'prime-fleet-notify__ref';
      ref.textContent = item.reference || '';
      var reason = document.createElement('span');
      reason.className = 'prime-fleet-notify__reason';
      reason.textContent = item.reason || '';
      top.appendChild(ref);
      top.appendChild(reason);

      var meta = document.createElement('span');
      meta.className = 'prime-fleet-notify__item-meta';
      meta.textContent = (item.customer || '') + (item.when ? (' · ' + item.when) : '');

      btn.appendChild(top);
      btn.appendChild(meta);
      li.appendChild(btn);
      list.appendChild(li);
    });
  }

  function applyNotifyFeed(data) {
    var badge = document.getElementById('prime-fleet-notify-badge');
    var bell = document.getElementById('prime-fleet-notify-bell');
    if (!data) return;
    var count = Number(data.count) || 0;
    if (badge) {
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.hidden = count < 1;
    }
    if (bell) {
      bell.setAttribute(
        'aria-label',
        count > 0
          ? (count === 1
              ? tReplace('notificationCount', '%d notification', { '%d': count })
              : tReplace('notificationCountN', '%d notifications', { '%d': count }))
          : t('noNotifications', 'No notifications')
      );
    }
    renderNotifyList(data.items || []);
  }

  var notifyRefreshBusy = false;

  function setNotifyRefreshing(on) {
    var root = document.getElementById('prime-fleet-notify');
    var panel = document.getElementById('prime-fleet-notify-panel');
    var btn = root && root.querySelector('[data-pf-notify-refresh]');
    var list = document.getElementById('prime-fleet-notify-list');
    if (root) root.classList.toggle('is-refreshing', !!on);
    if (panel) panel.setAttribute('aria-busy', on ? 'true' : 'false');
    if (btn) {
      btn.disabled = !!on;
      btn.setAttribute('aria-busy', on ? 'true' : 'false');
    }
    if (list) list.classList.toggle('is-refreshing', !!on);
  }

  function refreshNotifications(opts) {
    opts = opts || {};
    if (notifyRefreshBusy) return Promise.resolve();
    notifyRefreshBusy = true;
    if (opts.spin !== false) setNotifyRefreshing(true);

    var body = new FormData();
    body.append('action', 'prime_fleet_notifications');
    body.append('nonce', primeFleetAdmin.nonce);
    return fetch(primeFleetAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (json && json.success && json.data) applyNotifyFeed(json.data);
      })
      .catch(function () { /* quiet */ })
      .then(function () {
        notifyRefreshBusy = false;
        setNotifyRefreshing(false);
      });
  }

  (function bindNotify() {
    var root = document.getElementById('prime-fleet-notify');
    var bell = document.getElementById('prime-fleet-notify-bell');
    var panel = document.getElementById('prime-fleet-notify-panel');
    if (!root || !bell || !panel) return;

    bell.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var open = root.classList.contains('is-open');
      if (open) {
        closeNotify();
        return;
      }
      closeAllSelects();
      closeAllDateTimes();
      root.classList.add('is-open');
      panel.hidden = false;
      bell.setAttribute('aria-expanded', 'true');
      /* Do not auto-refresh on open — only the Refresh button fetches. */
    });

    /* Capture phase so Refresh never bubbles into panel navigation / form submits. */
    root.addEventListener('click', function (e) {
      var refreshBtn = e.target && e.target.closest && e.target.closest('[data-pf-notify-refresh]');
      if (refreshBtn) {
        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
        refreshNotifications({ spin: true });
        return;
      }
      if (e.target && e.target.closest && e.target.closest('[data-pf-panel]')) {
        closeNotify();
      }
    }, true);

    document.addEventListener('pointerdown', function (e) {
      if (!root.classList.contains('is-open')) return;
      if (e.target && root.contains(e.target)) return;
      closeNotify();
    }, true);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeNotify();
    });
  })();

  var bootQuery = {};
  try {
    var bootUrl = new URL(window.location.href);
    var bootPage = bootUrl.searchParams.get('page') || 'prime-fleet';
    var bootPanel = bootUrl.searchParams.get('panel') || pageToPanel(bootPage);
    if (bootPanel) state.panel = bootPanel;
    ['service_id', 'edit', 'tab', 'level', 's', 'status', 'type', 'payment', 'paged', 'id', 'day', 'stab', 'mtab', 'view', 'ym', 'date_from', 'date_to', 'vehicle_id'].forEach(function (k) {
      if (bootUrl.searchParams.get(k)) bootQuery[k] = bootUrl.searchParams.get(k);
    });
  } catch (e) { String(e); /* optional browser API / storage */ }

  /*
   * Prefer SSR HTML (no blank-card / Overview flash). PHP already read the same
   * ?page=&day=&id= query, so skip AJAX when SSR panel matches the URL panel.
   */
  var needsAjaxBoot = false;
  try {
    var bootKeys = Object.keys(bootQuery);
    needsAjaxBoot = bootKeys.some(function (k) {
      return ['s', 'status', 'type', 'payment', 'paged', 'id', 'day', 'stab', 'mtab', 'view', 'ym', 'edit', 'service_id', 'date_from', 'date_to', 'vehicle_id', 'tab', 'level'].indexOf(k) !== -1;
    });
  } catch (eBoot) { needsAjaxBoot = true; }

  var ssrOk = mountSsrPanel();
  if (ssrOk && (!needsAjaxBoot || state.panel === bootPanel)) {
    /* First paint already has the right panel (incl. day/id filters from PHP). */
  } else {
    loadPanel(state.panel, bootQuery, { loaderLabel: t('loading', 'Loading…') });
  }
  pfClearForcedWrapHeight();
  pfSyncContentSticky();
})();
