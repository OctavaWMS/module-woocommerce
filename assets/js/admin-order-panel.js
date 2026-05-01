(function () {
  const root = document.getElementById('octavawms-panel');
  if (!root || typeof octavawmsOrderPanel === 'undefined') {
    return;
  }
  const cfg = octavawmsOrderPanel;
  const box = root.closest('.octavawms-label-box');
  let spDetail = null;
  let lastSpShipmentId = 0;
  /** @type {{ id?: unknown, state?: string, error_message?: string, tracking_number?: string }|null} */
  let panelShipment = null;
  /** @type {{ has_order?: boolean, shipment?: Record<string, unknown>, has_label_locally?: boolean, download_url?: string, cod?: Record<string, unknown> }|null} */
  let lastOrderPanelPayload = null;
  let pendingLabelSuccessToast = false;
  /** @type {unknown} */
  let pdfJsDoc = null;
  /** @type {unknown} */
  let pdfJsPage = null;
  let pdfUserZoomScale = 1;
  const PDF_ZOOM_FACTOR = 1.25;

  var carrierFirstPageGen = 0;
  /** @type {unknown} */
  var carrierFirstPagePayload = null;
  /** @type {any} */
  var carrierFirstPagePromise;

  function beginCarrierFirstPagePrefetch() {
    if (typeof jQuery === 'undefined') {
      carrierFirstPagePromise = undefined;
      carrierFirstPagePayload = null;
      return;
    }
    carrierFirstPageGen += 1;
    var gen = carrierFirstPageGen;
    carrierFirstPagePayload = null;
    carrierFirstPagePromise = jQuery.post(cfg.ajaxUrl, {
      action: 'octavawms_delivery_services',
      nonce: cfg.connectorNonce,
      order_id: String(cfg.orderId),
      search: '',
      page: 1,
    }).done(function (resp) {
      if (gen === carrierFirstPageGen) {
        carrierFirstPagePayload = resp;
      }
    });
  }

  /**
   * @param {string|Record<string, string>|object|undefined} raw
   * @returns {{ search: string, page: number }}
   */
  function parseCarrierAjaxFormData(raw) {
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
      return {
        search: raw.search != null ? String(raw.search) : '',
        page: parseInt(String(raw.page != null ? raw.page : '1'), 10) || 1,
      };
    }
    if (typeof raw === 'string' && raw !== '') {
      var out = { search: '', page: 1 };
      raw.split('&').forEach(function (pair) {
        var i = pair.indexOf('=');
        if (i === -1) {
          return;
        }
        var k = decodeURIComponent(pair.slice(0, i));
        var v = decodeURIComponent(pair.slice(i + 1));
        if (k === 'search') {
          out.search = v;
        }
        if (k === 'page') {
          var p = parseInt(v, 10);
          if (!isNaN(p)) {
            out.page = p;
          }
        }
      });
      return out;
    }
    return { search: '', page: 1 };
  }

  /**
   * @param {JQuery.AjaxSettings} params
   * @param {(data: unknown) => void} success
   * @param {(xhr?: unknown, status?: string, err?: string) => void} failure
   */
  function carrierAjaxTransport(params, success, failure) {
    var q = parseCarrierAjaxFormData(params.data);
    var emptySearch = !String(q.search || '').trim();
    if (emptySearch && q.page === 1 && carrierFirstPagePromise) {
      if (carrierFirstPagePromise.state() === 'resolved') {
        success(carrierFirstPagePayload);
        return carrierFirstPagePromise;
      }
      return carrierFirstPagePromise.then(
        function () {
          success(carrierFirstPagePayload);
        },
        function (xhr, status, err) {
          failure(xhr, status, err);
        }
      );
    }
    return jQuery.ajax(params).done(success).fail(failure);
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function spTypeFilterFromStrategy(strategyVal) {
    if (!strategyVal || strategyVal === '__manual__') {
      return '';
    }
    try {
      var p = JSON.parse(strategyVal);
      var dr =
        p &&
        p.updateData &&
        p.updateData.eav &&
        p.updateData.eav['delivery-request-service-point-select'];
      var t = dr && dr.criteria ? dr.criteria.type : null;
      if (t === 'service_point') {
        return 'service_point';
      }
      if (t === 'self_service_point') {
        return 'self_service_point';
      }
      if (Array.isArray(t) && t.length > 1) {
        return '';
      }
    } catch (e) {}
    return '';
  }

  function dimAbbrev(raw) {
    return esc(String(raw).replace(/\([^)]*\)\s*/g, '').trim());
  }

  var PATCH_DEBOUNCE_MS = 450;

  /** @type {Record<string, number>} */
  var pendingPlacePatchTimers = Object.create(null);

  function cancelAllDebouncedPatches() {
    Object.keys(pendingPlacePatchTimers).forEach(function (pid) {
      window.clearTimeout(pendingPlacePatchTimers[pid]);
      delete pendingPlacePatchTimers[pid];
    });
  }

  function placesTotalGramsFromPlacesJson(places) {
    var s = 0;
    for (var i = 0; i < places.length; i++) {
      var p = places[i];
      var w =
        typeof (p && p.weight) === 'number'
          ? p.weight
          : parseFloat(String(p && p.weight !== undefined ? p.weight : '')) || 0;
      s += w;
    }
    return s;
  }

  function placesSummaryLineHtmlFromPlacesJson(places) {
    var n = places.length;
    var g = Math.round(placesTotalGramsFromPlacesJson(places));
    var boxPart =
      n === 1 ? cfg.strings.placesTotalOneBox : cfg.strings.placesTotalBoxes.replace('%d', String(n));
    var tpl = String(cfg.strings.placesSummaryGramsLine || '%1$s · %2$d g');
    return esc(tpl.replace('%1$s', boxPart).replace('%2$d', String(g)));
  }

  /** @param {number} shipmentId */
  function connectorUpdatePlace(shipmentId, placeId, weight, dx, dy, dz) {
    return connectorPost('octavawms_update_place', {
      shipment_id: String(shipmentId),
      place_id: String(placeId),
      weight: String(weight),
      dim_x: String(dx),
      dim_y: String(dy),
      dim_z: String(dz),
    });
  }

  function flushPendingPatchesForShipment(shipmentId, skipReload) {
    var snap = pendingPlacePatchTimers;
    pendingPlacePatchTimers = Object.create(null);
    Object.keys(snap).forEach(function (k) {
      window.clearTimeout(snap[k]);
    });
    /** @type {number[]} */
    var ids = [];
    Object.keys(snap).forEach(function (k) {
      var pid = parseInt(k, 10);
      if (pid > 0) {
        ids.push(pid);
      }
    });
    if (ids.length === 0) {
      return Promise.resolve();
    }
    var tb = tbodyFromPlacesPanel();
    var chain = Promise.resolve();
    ids.forEach(function (pid) {
      chain = chain.then(function () {
        var trEl =
          tb && typeof tb.querySelector === 'function'
            ? tb.querySelector('tr[data-place-id="' + String(pid) + '"]')
            : null;
        if (!(trEl instanceof HTMLTableRowElement)) {
          return null;
        }
        var inp = inputsFromPlacesRow(trEl);
        var wgt = inp.w ? String(inp.w.value) : '0';
        var x = inp.dx ? String(inp.dx.value) : '0';
        var y = inp.dy ? String(inp.dy.value) : '0';
        var z = inp.dz ? String(inp.dz.value) : '0';
        return connectorUpdatePlace(shipmentId, pid, wgt, x, y, z).then(function (j) {
          if (!j || !j.success) {
            window.alert((j && j.data && j.data.message) || cfg.strings.error);
          }
          return j;
        });
      });
    });
    return chain.then(function () {
      if (!skipReload) {
        loadPlacesSection(shipmentId);
      }
    });
  }

  /** @returns {HTMLElement|null} */
  function tbodyFromPlacesPanel() {
    var body = document.getElementById('octavawms-places-body');
    return body && body.querySelector('tbody.places-tbody');
  }

  function currentShipmentIdFromDom() {
    var el = document.getElementById('octavawms-places-body');
    return el ? parseInt(String(el.getAttribute('data-shipment-id') || '0'), 10) || 0 : 0;
  }

  function scheduleDebouncedPatchForPlaceRow(shipmentId, placeId, trEl) {
    var key = String(placeId);
    if (pendingPlacePatchTimers[key]) {
      window.clearTimeout(pendingPlacePatchTimers[key]);
    }
    pendingPlacePatchTimers[key] = window.setTimeout(function () {
      delete pendingPlacePatchTimers[key];
      var m = inputsFromPlacesRow(trEl);
      connectorUpdatePlace(
        shipmentId,
        placeId,
        m.w ? String(m.w.value) : '0',
        m.dx ? String(m.dx.value) : '0',
        m.dy ? String(m.dy.value) : '0',
        m.dz ? String(m.dz.value) : '0',
      ).then(function (j) {
        if (!j || !j.success) {
          window.alert((j && j.data && j.data.message) || cfg.strings.error);
          loadPlacesSection(shipmentId);
          return;
        }
        loadPlacesSectionSilent(shipmentId);
      });
    }, PATCH_DEBOUNCE_MS);
  }

  /** @param {HTMLElement} tbody */
  function bindPlacesInputDelegation(tbody, shipmentId) {
    tbody.addEventListener('input', function (e) {
      if (currentPanelLabelState() === 'locked') {
        return;
      }
      var t = e.target;
      if (!(t instanceof HTMLInputElement) || !t.classList.contains('octavawms-place-input')) {
        return;
      }
      var trEl = t.closest('tr[data-place-id]');
      if (!(trEl instanceof HTMLTableRowElement)) {
        return;
      }
      var pid = parseInt(trEl.getAttribute('data-place-id') || '0', 10) || 0;
      if (pid <= 0) {
        return;
      }
      scheduleDebouncedPatchForPlaceRow(shipmentId, pid, trEl);
    });
    tbody.addEventListener('blur', function (e) {
      if (currentPanelLabelState() === 'locked') {
        return;
      }
      var t = e.target;
      if (!(t instanceof HTMLInputElement) || !t.classList.contains('octavawms-place-input')) {
        return;
      }
      var trEl = t.closest('tr[data-place-id]');
      if (!(trEl instanceof HTMLTableRowElement)) {
        return;
      }
      var pid = parseInt(trEl.getAttribute('data-place-id') || '0', 10) || 0;
      if (pid <= 0) {
        return;
      }
      var key = String(pid);
      if (pendingPlacePatchTimers[key]) {
        window.clearTimeout(pendingPlacePatchTimers[key]);
        delete pendingPlacePatchTimers[key];
      }
      var m = inputsFromPlacesRow(trEl);
      connectorUpdatePlace(
        shipmentId,
        pid,
        m.w ? String(m.w.value) : '0',
        m.dx ? String(m.dx.value) : '0',
        m.dy ? String(m.dy.value) : '0',
        m.dz ? String(m.dz.value) : '0',
      ).then(function (j) {
        if (!j || !j.success) {
          window.alert((j && j.data && j.data.message) || cfg.strings.error);
          loadPlacesSection(shipmentId);
          return;
        }
        loadPlacesSectionSilent(shipmentId);
      });
    }, true);
  }

  /** @param {string} stateRaw shipment state from backend */
  function shipmentStateTone(stateRaw) {
    const raw = String(stateRaw || '').trim();
    if (!raw) {
      return 'neutral';
    }
    const s = raw.toLowerCase();
    if (/(error|fail|reject|invalid|exception)/.test(s)) {
      return 'error';
    }
    if (/(delivered|labeled|parcelation_success|parcelate_success|picked_up|handed_over|collected)/.test(s)) {
      return 'success';
    }
    if (/\b(success|completed|done)\b/.test(s) && !/pending/.test(s)) {
      return 'success';
    }
    if (/(shipped|in_transit|\btransit\b|out_for_delivery)/.test(s)) {
      return 'info';
    }
    if (/(pending|parcelating|queued|waiting|processing|draft|scheduled|parcelation_)/.test(s)) {
      return 'warn';
    }
    if (/\bready\b/.test(s)) {
      return 'info';
    }
    return 'neutral';
  }

  function statusPillHtml(stateRaw) {
    const tone = shipmentStateTone(stateRaw);
    const label = esc(String(stateRaw || '').trim() || '—');
    return (
      '<span class="octavawms-status-pill octavawms-status-pill--' + tone + '" title="' + esc(cfg.strings.shipmentStatus) + '">' + label + '</span>'
    );
  }

  /** @param {{ state?: string, error_message?: string }|null|undefined} shipmentStub */
  /** @param {Record<string, unknown>|null|undefined} detail */
  function pendingErrorBannerContentHtml(shipmentStub, detail) {
    const st = detail && detail.shipment_state
      ? String(detail.shipment_state)
      : shipmentStub && shipmentStub.state
        ? String(shipmentStub.state)
        : '';
    if (st !== 'pending_error') {
      return '';
    }
    let statusRow = '<p class="octavawms-shipment-state-banner__status">' + statusPillHtml(st);
    const extra = detail && detail.shipment_status_text ? String(detail.shipment_status_text).trim() : '';
    if (extra) {
      statusRow += ' <span class="octavawms-shipment-state-banner__extra">' + esc(extra) + '</span>';
    }
    statusRow += '</p>';
    let msg = '';
    if (detail && detail.shipment_error_message) {
      msg = String(detail.shipment_error_message).trim();
    }
    if (!msg && shipmentStub && shipmentStub.error_message) {
      msg = String(shipmentStub.error_message).trim();
    }
    if (!msg) {
      msg = String(cfg.strings.shipmentPendingErrorGeneric || '').trim();
    }
    const sid =
      shipmentStub && shipmentStub.id != null ? parseInt(String(shipmentStub.id), 10) || 0 : 0;
    let actions = '';
    if (sid > 0) {
      actions +=
        '<p class="octavawms-shipment-state-banner__actions">' +
        '<button type="button" class="button button-primary" data-octavawms-action="retry-pending-error" data-shipment-id="' +
        esc(String(sid)) +
        '">' +
        esc(cfg.strings.retryPendingError || 'Retry') +
        '</button></p>';
    }
    return (
      statusRow +
      '<p class="octavawms-shipment-state-banner__message" id="octavawms-shipment-state-banner-msg">' +
      esc(msg) +
      '</p>' +
      actions
    );
  }

  /** @param {{ state?: string, error_message?: string }|null|undefined} shipmentStub */
  /** @param {Record<string, unknown>|null|undefined} detail */
  function pendingErrorBannerWrapperHtml(shipmentStub, detail) {
    const inner = pendingErrorBannerContentHtml(shipmentStub, detail);
    if (!inner) {
      return '';
    }
    return (
      '<div class="octavawms-shipment-state-banner octavawms-shipment-state-banner--error" id="octavawms-shipment-state-banner" role="alert">' +
      inner +
      '</div>'
    );
  }

  function syncPendingErrorBanner() {
    const wrap = root.querySelector('.octavawms-connect-page');
    const grid = wrap && wrap.querySelector('.octavawms-connect-grid');
    if (!wrap || !grid) {
      return;
    }
    const inner = pendingErrorBannerContentHtml(panelShipment, spDetail);
    let el = document.getElementById('octavawms-shipment-state-banner');
    if (!inner) {
      if (el) {
        el.remove();
      }
      return;
    }
    if (!el) {
      el = document.createElement('div');
      el.id = 'octavawms-shipment-state-banner';
      el.className = 'octavawms-shipment-state-banner octavawms-shipment-state-banner--error';
      el.setAttribute('role', 'alert');
      grid.parentNode.insertBefore(el, grid);
    }
    el.innerHTML = inner;
  }

  /** @param {{ is_cod?: boolean, formatted_total?: string, label?: string, payment_method_title?: string }|null|undefined} codInfo */
  function codPillHtml(codInfo) {
    const ci = codInfo && typeof codInfo === 'object' ? codInfo : {};
    const isCod =
      ci.is_cod === true ||
      (Boolean(ci.formatted_total) && ci.is_cod !== false && !Object.prototype.hasOwnProperty.call(ci, 'is_cod'));
    if (!isCod) {
      return '<span class="octavawms-cod-pill octavawms-cod-pill--no">' + esc(cfg.strings.codNo) + '</span>';
    }
    const baseLabel = String(ci.label || cfg.strings.codYes).trim();
    let inner = esc(baseLabel);
    const amt = ci.formatted_total ? String(ci.formatted_total).trim() : '';
    if (amt !== '') {
      inner += ' · ' + esc(amt);
    }
    let h =
      '<span class="octavawms-cod-pill octavawms-cod-pill--yes" title="' + esc(cfg.strings.codYes) + '">' + inner + '</span>';
    const pmRaw = ci.payment_method_title ? String(ci.payment_method_title).trim() : '';
    if (pmRaw && pmRaw.toLowerCase() !== baseLabel.toLowerCase()) {
      h +=
        '<span class="octavawms-muted octavawms-muted--tight octavawms-label-shipment__pm">' +
        esc(pmRaw) +
        '</span>';
    }
    return h;
  }

  /** @param {{ cod?: Record<string, unknown> }|null|undefined} data @param {{ id?: unknown, state?: string }|null|undefined} shipment */
  function shipmentMetaRowHtml(data, shipment) {
    const sidStr = shipment && shipment.id ? String(shipment.id) : '';
    const codInfo = data && data.cod && typeof data.cod === 'object' ? data.cod : {};
    const state = (shipment && shipment.state) || '';
    const statusHuman = shipmentStateDisplayName(state);
    return (
      '<div class="octavawms-create-label-shipment-meta">' +
      '<p class="octavawms-create-label-shipment-ref">' +
      '<strong>' +
      esc(cfg.strings.shipmentLabel) +
      '</strong> ' +
      esc(sidStr ? '#' + sidStr : '—') +
      '</p>' +
      '<div class="ow-meta-rows">' +
      '<div class="ow-meta-row"><span class="ow-meta-key">' +
      esc(cfg.strings.statusLabel || '') +
      ':</span> ' +
      esc(statusHuman) +
      '</div>' +
      shipmentPaymentLineHtml(codInfo) +
      '</div></div>'
    );
  }

  function hrefAttr(u) {
    return String(u || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;');
  }
  function shipmentIdFrom(data) {
    if (data.shipment && data.shipment.id) {
      return parseInt(String(data.shipment.id), 10) || 0;
    }
    return parseInt(String(data.shipment_id || 0), 10) || 0;
  }

  /**
   * @param {{ has_label_locally?: boolean, download_url?: string }|null|undefined} data
   * @param {{ tracking_number?: string }|null|undefined} shipment
   * @returns {'draft'|'generated'|'locked'}
   */
  function panelLabelState(data, shipment) {
    const tn =
      shipment && shipment.tracking_number != null ? String(shipment.tracking_number).trim() : '';
    if (tn !== '') {
      return 'locked';
    }
    const hasLocal = !!(data && data.has_label_locally);
    const dl = data && data.download_url ? String(data.download_url).trim() : '';
    if (hasLocal && dl) {
      return 'generated';
    }
    return 'draft';
  }

  /**
   * @returns {'draft'|'generated'|'locked'}
   */
  function currentPanelLabelState() {
    if (!lastOrderPanelPayload || !lastOrderPanelPayload.shipment) {
      return 'draft';
    }
    return panelLabelState(lastOrderPanelPayload, lastOrderPanelPayload.shipment);
  }

  function carrierSlugFromName(name) {
    const n = String(name || '').toLowerCase();
    if (/speedy|спиди/.test(n)) {
      return 'speedy';
    }
    if (/econt|еконт/.test(n)) {
      return 'econt';
    }
    if (/post|пощи|bg.?post|български/.test(n)) {
      return 'bgpost';
    }
    return '';
  }

  function buildCarrierTrackUrl(slug, trackingNumber) {
    const raw = String(trackingNumber || '').trim();
    const n = encodeURIComponent(raw);
    if (!slug || !n) {
      return '';
    }
    const map = {
      speedy: 'https://www.speedy.bg/en/track-shipment?shipmentNumber=' + n,
      econt: 'https://www.econt.com/services/track-shipment/' + n,
      bgpost: 'https://www.bgpost.bg/bg/tracking?code=' + n,
    };
    return map[slug] || '';
  }

  function hydrateTrackingCarrierButtons(shipmentId, trackingNumber) {
    const btn = document.querySelector('[data-octavawms-action="track-shipment"]');
    if (!(btn instanceof HTMLButtonElement)) {
      return;
    }
    if (btn.getAttribute('data-track-url')) {
      btn.style.display = '';
      return;
    }
    connectorPost('octavawms_shipment_detail', { shipment_id: String(shipmentId) })
      .then(function (j) {
        if (!j || !j.success || !j.data || !j.data.detail) {
          return;
        }
        const d = j.data.detail;
        const name = d.delivery_service_name ? String(d.delivery_service_name) : '';
        const slug = carrierSlugFromName(name);
        const url = buildCarrierTrackUrl(slug, trackingNumber);
        if (url) {
          btn.setAttribute('data-track-url', url);
          btn.style.display = '';
        }
      })
      .catch(function () {});
  }

  function showLabelToast(message) {
    const msg = String(message || '').trim();
    if (!msg) {
      return;
    }
    let el = document.getElementById('octavawms-panel-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'octavawms-panel-toast';
      el.className = 'ow-toast';
      el.setAttribute('role', 'status');
      root.appendChild(el);
    }
    el.innerHTML =
      '<svg class="ow-toast__icon" viewBox="0 0 16 16" aria-hidden="true" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="8" cy="8" r="8" fill="#4ab866"/><path d="M4.5 8.2l2.3 2.3 5-5" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
      '<span>' +
      esc(msg) +
      '</span>';
    el.classList.remove('ow-toast--visible');
    void el.offsetWidth;
    el.classList.add('ow-toast--visible');
    window.setTimeout(function () {
      el.classList.remove('ow-toast--visible');
    }, 2600);
  }

  function shipmentStateDisplayName(raw) {
    const key = String(raw || '').trim();
    const map = cfg.strings.shipmentStateNames || {};
    if (map[key]) {
      return String(map[key]);
    }
    return key || '—';
  }

  function shipmentPaymentLineHtml(codInfo) {
    const ci = codInfo && typeof codInfo === 'object' ? codInfo : {};
    const isCod =
      ci.is_cod === true ||
      (Boolean(ci.formatted_total) && ci.is_cod !== false && !Object.prototype.hasOwnProperty.call(ci, 'is_cod'));
    let paymentText = '';
    if (isCod) {
      const baseLabel = String(ci.label || cfg.strings.codYes).trim();
      paymentText = baseLabel;
      const amt = ci.formatted_total ? String(ci.formatted_total).trim() : '';
      if (amt !== '') {
        paymentText += ' · ' + amt;
      }
    } else {
      paymentText = String(cfg.strings.codNo || '');
    }
    return (
      '<div class="ow-meta-row"><span class="ow-meta-key">' +
      esc(cfg.strings.paymentLabel || '') +
      ':</span> ' +
      esc(paymentText) +
      '</div>'
    );
  }

  /**
   * @param {string} msg
   * @param {number} [optRequeueSid] Preferred shipment id for re-queue; falls back to {@link panelShipment}.
   */
  function renderError(msg, optRequeueSid) {
    revokeLabelViewerPdfObjectUrl();
    var sid =
      typeof optRequeueSid === 'number' && optRequeueSid > 0
        ? optRequeueSid
        : panelShipment && panelShipment.id
          ? parseInt(String(panelShipment.id), 10) || 0
          : 0;
    var requeueBtn =
      sid > 0
        ? '<button type="button" class="button" data-octavawms-action="requeue-ending-queued" data-shipment-id="' +
          esc(String(sid)) +
          '">' +
          esc(cfg.strings.requeueEndingQueued || 'Re-queue shipment') +
          '</button>'
        : '';
    root.innerHTML =
      '<div class="octavawms-connect-page">' +
      '<div class="octavawms-connect-section">' +
      '<div class="octavawms-connect-section-body">' +
      '<p class="octavawms-notice octavawms-notice--error">' +
      esc(msg) +
      '</p>' +
      '<div class="octavawms-actions-row">' +
      '<button type="button" class="button button-primary" data-octavawms-action="retry">' +
      esc(cfg.strings.tryAgain) +
      '</button>' +
      requeueBtn +
      '</div></div></div></div>';
  }

  function connectorPost(action, fields) {
    const body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', cfg.connectorNonce);
    body.set('order_id', String(cfg.orderId));
    Object.keys(fields || {}).forEach(function (k) {
      body.set(k, fields[k]);
    });
    return fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
      credentials: 'same-origin',
    }).then(function (r) {
      return r.json();
    });
  }

  function patchShipmentContext(shipmentId, patchKind, extraFields) {
    extraFields = extraFields || {};
    const f = { shipment_id: String(shipmentId), patch_kind: patchKind };
    Object.keys(extraFields).forEach(function (k) {
      f[k] = extraFields[k];
    });
    return connectorPost('octavawms_patch_shipment', f);
  }

  function toolbarHtml(leftInnerHtml) {
    if (leftInnerHtml == null) {
      leftInnerHtml = '';
    }
    return (
      '<div class="octavawms-connect-toolbar">' +
      '<div class="octavawms-connect-toolbar__left">' +
      String(leftInnerHtml) +
      '</div>' +
      '<div class="octavawms-connect-toolbar__actions">' +
      '<button type="button" class="button button-small" data-octavawms-action="panel-login">' +
      esc(cfg.strings.loginToPanel || 'Login to the panel') +
      '</button>' +
      '<button type="button" class="button button-small" data-octavawms-action="refresh-status">' +
      esc(cfg.strings.refreshStatus) +
      '</button></div></div>'
    );
  }

  function postPanelLoginUrl() {
    const body = new URLSearchParams();
    body.set('action', 'octavawms_panel_login_url');
    body.set('security', String(cfg.panelLoginNonce || ''));
    return fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
      credentials: 'same-origin',
    }).then(function (r) {
      return r.json();
    });
  }

  /** @returns {boolean} */
  function placesTableHasRenderableRows() {
    const tbody = document.querySelector('#octavawms-places-body tbody.places-tbody');
    return !!(tbody && tbody.querySelector('tr[data-place-id]'));
  }

  function syncLabelPrimaryActions() {
    const sec = document.getElementById('octavawms-panel-label');
    const loading = sec ? sec.classList.contains('is-loading') : false;
    const bodies = !!placesTableHasRenderableRows();
    const st = currentPanelLabelState();
    const locked = st === 'locked';
    root.querySelectorAll('[data-octavawms-action="generate-label"]').forEach(function (el) {
      if (!(el instanceof HTMLButtonElement)) {
        return;
      }
      if (el.classList.contains('button-link-delete')) {
        el.disabled = locked || loading;
      } else {
        el.disabled = st !== 'draft' || loading || !bodies;
      }
    });
    const addBt = root.querySelector('[data-octavawms-action="place-add"]');
    if (addBt instanceof HTMLButtonElement) {
      var el = document.getElementById('octavawms-places-body');
      var sid = el ? parseInt(String(el.getAttribute('data-shipment-id') || '0'), 10) || 0 : 0;
      addBt.disabled = locked || loading || !(sid > 0);
    }
  }

  /** @param {boolean} shipmentLocked */
  function labelTopActionsRowHtml(shipmentId, shipmentLocked) {
    if (shipmentLocked) {
      return '';
    }
    const sidStr = esc(String(shipmentId));
    const dis = shipmentId <= 0 ? ' disabled' : '';
    return (
      '<button type="button" class="button button-secondary"' +
      dis +
      ' data-octavawms-action="place-add" data-shipment-id="' +
      sidStr +
      '">' +
      esc(cfg.strings.addPlace) +
      '</button>'
    );
  }

  function createLabelAndBoxesSection(data, shipment, hasLocal, dl, shipmentId, placesInitialInnerHtml) {
    const labelState = panelLabelState(data, shipment);
    const tn =
      shipment && shipment.tracking_number != null && String(shipment.tracking_number).trim() !== ''
        ? String(shipment.tracking_number).trim()
        : '';
    const shipmentLocked = labelState === 'locked';

    let banner = '';
    if (shipmentLocked) {
      banner +=
        '<div class="octavawms-tracking-lock-banner ow-tracking-card">' +
        '<span class="ow-tracking-number-label">' +
        esc(cfg.strings.trackingNumberLabel || cfg.strings.trackingNumber || '') +
        '</span>' +
        '<div class="ow-tracking-number-row">' +
        '<span class="ow-tracking-number-lg">' +
        esc(tn) +
        '</span>' +
        '<button type="button" class="button button-small" data-octavawms-action="copy-tracking" data-tracking-number="' +
        hrefAttr(tn) +
        '">' +
        esc(cfg.strings.copyTracking || '') +
        '</button>' +
        '<button type="button" class="button button-primary" style="display:none" data-octavawms-action="track-shipment" data-tracking-number="' +
        hrefAttr(tn) +
        '">' +
        esc(cfg.strings.trackShipment || '') +
        '</button>' +
        '</div>' +
        '<div class="ow-tracking-cancel-row">' +
        '<button type="button" class="button button-small" data-octavawms-action="cancel-shipment" data-shipment-id="' +
        esc(String(shipmentId)) +
        '">' +
        esc(cfg.strings.cancelShipment || '') +
        '</button>' +
        '</div>' +
        '<p class="octavawms-muted octavawms-shipment-locked-msg">' +
        esc(cfg.strings.shipmentLocked || '') +
        '</p></div>';
    }

    let boxesNotice = '';
    if (labelState === 'generated') {
      boxesNotice +=
        '<p class="octavawms-notice octavawms-notice--warning">' +
        esc(cfg.strings.boxesEditNeedsRegenerate || '') +
        '</p>';
    }

    let editOrderFooter = '';
    if (cfg.orderEditUrl) {
      editOrderFooter =
        '<p class="octavawms-muted octavawms-muted--tight">' +
        '<a href="' +
        hrefAttr(cfg.orderEditUrl) +
        '">' +
        esc(cfg.strings.editOrder) +
        '</a></p>';
    }

    const placesBody =
      '<div id="octavawms-places-body" data-shipment-id="' +
      esc(String(shipmentId)) +
      '">' +
      placesInitialInnerHtml +
      '</div>';

    const placesWrapped =
      shipmentLocked
        ? '<details class="octavawms-boxes-details"><summary>' +
          esc(cfg.strings.labelPanelSrHeading) +
          '</summary>' +
          boxesNotice +
          placesBody +
          '</details>'
        : boxesNotice + placesBody;

    const boxesWrap =
      '<div class="octavawms-label-boxes-mount">' +
      banner +
      '<div class="octavawms-label-top-actions" data-shipment-id="' +
      esc(String(shipmentId)) +
      '">' +
      labelTopActionsRowHtml(shipmentId, shipmentLocked) +
      '</div>' +
      placesWrapped +
      editOrderFooter +
      '</div>';

    return (
      '<section class="octavawms-connect-section octavawms-connect-section--label-boxes octavawms-panel-label' +
      (shipmentLocked ? ' octavawms-panel-label--locked' : '') +
      '" id="octavawms-panel-label" aria-label="' +
      esc(cfg.strings.labelPanelSrHeading) +
      '">' +
      '<div class="octavawms-connect-section-body">' +
      boxesWrap +
      '</div></section>'
    );
  }

  /**
   * Local labels are served with Content-Disposition: attachment unless inline=1 is set;
   * attachment in an iframe triggers a download instead of a preview.
   *
   * @param {string} url
   * @returns {string}
   */
  function labelUrlForIframeOrPrint(url) {
    if (!url || typeof url !== 'string') {
      return url;
    }
    if (url.indexOf('blob:') === 0 || url.indexOf('data:') === 0) {
      return url;
    }
    try {
      var base = window.location.origin || '';
      var u = new URL(url, base || undefined);
      var path = (u.pathname || '').toLowerCase();
      if (path.indexOf('admin-post.php') !== -1 && u.searchParams.get('action') === 'octavawms_download_label') {
        u.searchParams.set('inline', '1');
        return u.toString();
      }
    } catch (e) {
      return url;
    }
    return url;
  }

  /** @type {string|null} */
  let labelViewerPdfObjectUrl = null;

  function destroyPdfRender() {
    if (pdfJsDoc) {
      try {
        pdfJsDoc.destroy();
      } catch (e) {}
      pdfJsDoc = null;
      pdfJsPage = null;
    }
    pdfUserZoomScale = 1;
  }

  function revokeLabelViewerPdfObjectUrl() {
    destroyPdfRender();
    if (labelViewerPdfObjectUrl) {
      try {
        URL.revokeObjectURL(labelViewerPdfObjectUrl);
      } catch (e) {}
      labelViewerPdfObjectUrl = null;
    }
  }

  /**
   * @param {string} data
   * @returns {string}
   */
  function stripDataUrlBase64(data) {
    if (!data || typeof data !== 'string') {
      return '';
    }
    if (data.startsWith('data:')) {
      const comma = data.indexOf(',');
      return comma >= 0 ? data.slice(comma + 1) : data;
    }
    return data;
  }

  /**
   * @param {Uint8Array} bytes
   * @returns {boolean}
   */
  function byteArrayStartsWithPdfMagic(bytes) {
    return (
      bytes &&
      bytes.length >= 4 &&
      bytes[0] === 0x25 &&
      bytes[1] === 0x50 &&
      bytes[2] === 0x44 &&
      bytes[3] === 0x46
    );
  }

  /**
   * @param {Uint8Array} bytes
   * @returns {Uint8Array}
   */
  function uint8StripUtf8Bom(bytes) {
    if (bytes.length >= 3 && bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf) {
      return bytes.subarray(3);
    }
    return bytes;
  }

  /**
   * @param {string} downloadUrl
   * @returns {{ ok: true, url: string, objectUrl: false }}
   */
  function resolvePdfPreviewFallbackDirect(downloadUrl) {
    const trimmed = String(downloadUrl || '').trim();
    return { ok: true, url: labelUrlForIframeOrPrint(trimmed), objectUrl: false };
  }

  /**
   * @param {string} input
   * @returns {string}
   */
  function sanitizeBase64Candidate(input) {
    let s = String(input || '');
    s = s.replace(/^\uFEFF/, '');
    s = s.replace(/[^A-Za-z0-9+/=_-]/g, '');
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    s = s.replace(/=+$/g, '');
    const mod = s.length % 4;
    if (mod === 2) {
      s += '==';
    } else if (mod === 3) {
      s += '=';
    } else if (mod === 1) {
      s = s.slice(0, -1);
    }
    return s;
  }

  /**
   * @param {string} base64
   * @returns {Blob}
   */
  function base64ToPdfBlob(base64) {
    const sanitized = sanitizeBase64Candidate(base64);
    if (sanitized.length < 16) {
      throw new Error('base64_too_short');
    }
    let binStr;
    try {
      binStr = atob(sanitized);
    } catch (e) {
      throw new Error('atob_failed');
    }
    const len = binStr.length;
    const bytes = new Uint8Array(len);
    for (let i = 0; i < len; i++) {
      bytes[i] = binStr.charCodeAt(i);
    }
    if (!byteArrayStartsWithPdfMagic(bytes)) {
      throw new Error('not_pdf');
    }
    return new Blob([bytes], { type: 'application/pdf' });
  }

  /**
   * Recursively walks decoded JSON looking for a string value that decodes to a real PDF.
   *
   * @param {unknown} node
   * @param {number} depth
   * @returns {Blob|null}
   */
  function walkJsonForPdfBlob(node, depth) {
    if (depth > 10 || node == null) {
      return null;
    }
    if (typeof node === 'string') {
      if (node.length < 100) {
        return null;
      }
      try {
        return base64ToPdfBlob(stripDataUrlBase64(node));
      } catch (e) {
        return null;
      }
    }
    if (Array.isArray(node)) {
      for (let i = 0; i < node.length; i++) {
        const blob = walkJsonForPdfBlob(node[i], depth + 1);
        if (blob) {
          return blob;
        }
      }
      return null;
    }
    if (typeof node === 'object') {
      const priority = [
        'pdfData',
        'pdf',
        'pdf_base64',
        'pdfBase64',
        'labelPdf',
        'label_pdf',
        'content',
        'data',
        'file',
        'body',
      ];
      for (let pi = 0; pi < priority.length; pi++) {
        if (Object.prototype.hasOwnProperty.call(node, priority[pi])) {
          const blob = walkJsonForPdfBlob(node[priority[pi]], depth + 1);
          if (blob) {
            return blob;
          }
        }
      }
      const keys = Object.keys(node);
      for (let ki = 0; ki < keys.length; ki++) {
        const blob = walkJsonForPdfBlob(node[keys[ki]], depth + 1);
        if (blob) {
          return blob;
        }
      }
    }
    return null;
  }

  /**
   * @param {string} downloadUrl
   * @returns {Promise<{ ok: boolean, url?: string, objectUrl?: boolean }>}
   */
  async function resolvePdfPreviewForViewer(downloadUrl) {
    const trimmed = String(downloadUrl || '').trim();
    if (!trimmed) {
      return { ok: false };
    }
    if (trimmed.startsWith('data:')) {
      try {
        const b64 = stripDataUrlBase64(trimmed);
        const blob = base64ToPdfBlob(b64);
        return { ok: true, url: URL.createObjectURL(blob), objectUrl: true };
      } catch (e) {
        try {
          console.warn('[octavawms label preview] data: URL decode failed', e);
        } catch (ee) {}
        return { ok: false };
      }
    }
    const looksLikeHttpUrl =
      /^https?:\/\//i.test(trimmed) ||
      trimmed.startsWith('/') ||
      trimmed.indexOf('admin-post.php') !== -1 ||
      trimmed.indexOf('admin-ajax.php') !== -1 ||
      trimmed.indexOf('action=') !== -1 ||
      trimmed.indexOf('octavawms_') !== -1;
    if (!looksLikeHttpUrl && trimmed.length > 40) {
      try {
        const blob = base64ToPdfBlob(trimmed);
        return { ok: true, url: URL.createObjectURL(blob), objectUrl: true };
      } catch (e) {
        try {
          console.warn('[octavawms label preview] standalone base64 decode failed', e);
        } catch (ee) {}
        return { ok: false };
      }
    }
    try {
      const res = await fetch(trimmed, { credentials: 'same-origin', redirect: 'follow' });
      if (!res.ok) {
        try {
          console.warn(
            '[octavawms label preview] fetch !ok, falling back to direct URL',
            res.status,
            res.statusText
          );
        } catch (ee) {}
        return resolvePdfPreviewFallbackDirect(trimmed);
      }
      const buf = await res.arrayBuffer();
      const bytesRaw = new Uint8Array(buf);
      const bytes = uint8StripUtf8Bom(bytesRaw);
      if (byteArrayStartsWithPdfMagic(bytes)) {
        const blob = new Blob([bytes], { type: 'application/pdf' });
        return { ok: true, url: URL.createObjectURL(blob), objectUrl: true };
      }
      let text = '';
      try {
        text = new TextDecoder('utf-8', { fatal: false }).decode(bytes);
      } catch (e) {
        try {
          console.warn('[octavawms label preview] TextDecoder failed', e);
        } catch (ee) {}
        return resolvePdfPreviewFallbackDirect(trimmed);
      }
      const cleaned = text.replace(/^\uFEFF/, '').trim();
      try {
        const j = JSON.parse(cleaned);
        const blob = walkJsonForPdfBlob(j, 0);
        if (blob) {
          return { ok: true, url: URL.createObjectURL(blob), objectUrl: true };
        }
      } catch (parseErr) {}
      try {
        const blob = base64ToPdfBlob(cleaned);
        return { ok: true, url: URL.createObjectURL(blob), objectUrl: true };
      } catch (firstErr) {
        try {
          console.warn(
            '[octavawms label preview] body is not PDF/JSON/base64, snippet:',
            cleaned.slice(0, 80),
            firstErr
          );
        } catch (ee) {}
      }
      const m = cleaned.match(/[A-Za-z0-9+/=_-]{200,}/);
      if (m && m[0]) {
        try {
          const blob = base64ToPdfBlob(m[0]);
          return { ok: true, url: URL.createObjectURL(blob), objectUrl: true };
        } catch (eRegion) {
          try {
            console.warn(
              '[octavawms label preview] inline base64 region decode failed',
              eRegion
            );
          } catch (ee) {}
        }
      }
      return resolvePdfPreviewFallbackDirect(trimmed);
    } catch (e) {
      try {
        console.warn('[octavawms label preview] fetch threw, falling back', e);
      } catch (ee) {}
      return resolvePdfPreviewFallbackDirect(trimmed);
    }
  }

  /**
   * @param {string} resolvedUrl
   * @param {boolean} objectUrlFlag
   */
  function syncLabelViewerActionUrls(resolvedUrl, objectUrlFlag) {
    const card = document.getElementById('octavawms-label-viewer-card');
    if (!card) {
      return;
    }
    const printBt = card.querySelector('[data-octavawms-action="print-label"]');
    if (printBt) {
      printBt.setAttribute('data-label-url', resolvedUrl);
    }
    const dl = card.querySelector('a.octavawms-label-viewer-download');
    if (dl instanceof HTMLAnchorElement) {
      dl.href = resolvedUrl;
      if (objectUrlFlag) {
        dl.setAttribute('download', 'label.pdf');
      } else {
        dl.removeAttribute('download');
      }
    }
  }

  /**
   * @param {'fit'|'zoom-in'|'zoom-out'} mode
   */
  function renderPdfPageToCanvas(mode) {
    const canvas = document.getElementById('octavawms-label-pdf-canvas');
    const wrap = canvas ? canvas.closest('.ow-pdf-canvas-wrap') : null;
    if (!canvas || !wrap || !pdfJsPage) {
      return;
    }
    const page = pdfJsPage;
    const baseVp = page.getViewport({ scale: 1 });
    const cw = Math.max(wrap.clientWidth || 400, 200);
    const fitScale = cw / baseVp.width;
    if (mode === 'fit') {
      pdfUserZoomScale = 1;
    } else if (mode === 'zoom-in') {
      pdfUserZoomScale *= PDF_ZOOM_FACTOR;
    } else if (mode === 'zoom-out') {
      pdfUserZoomScale /= PDF_ZOOM_FACTOR;
      if (pdfUserZoomScale < 0.25) {
        pdfUserZoomScale = 0.25;
      }
    }
    const scale = fitScale * pdfUserZoomScale;
    const viewport = page.getViewport({ scale: scale });
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      return;
    }
    ctx.save();
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    page.render({ canvasContext: ctx, viewport: viewport }).promise.then(function () {
      ctx.restore();
    });
  }

  /**
   * @param {string} downloadUrl
   * @returns {Promise<void>}
   */
  function attachLabelPdfViewer(downloadUrl) {
    revokeLabelViewerPdfObjectUrl();
    const loading = document.getElementById('octavawms-label-viewer-loading');
    const canvas = document.getElementById('octavawms-label-pdf-canvas');
    const errBox = document.getElementById('octavawms-label-viewer-error');
    const fallbackDl = document.getElementById('octavawms-label-viewer-fallback-dl');
    if (!canvas || !downloadUrl) {
      return Promise.resolve();
    }
    if (loading) {
      loading.style.display = '';
    }
    canvas.style.display = 'none';
    if (errBox) {
      errBox.style.display = 'none';
      errBox.innerHTML = '';
    }
    if (fallbackDl instanceof HTMLAnchorElement) {
      fallbackDl.style.display = 'none';
    }

    if (typeof pdfjsLib === 'undefined') {
      if (loading) {
        loading.style.display = 'none';
      }
      if (errBox) {
        errBox.style.display = '';
        errBox.innerHTML =
          '<p class="octavawms-notice octavawms-notice--error">' +
          esc(cfg.strings.labelPreviewError || '') +
          '</p>';
      }
      return Promise.resolve();
    }
    if (cfg.pdfjs && cfg.pdfjs.workerSrc) {
      pdfjsLib.GlobalWorkerOptions.workerSrc = cfg.pdfjs.workerSrc;
    }

    return resolvePdfPreviewForViewer(downloadUrl)
      .then(function (resolved) {
        if (!resolved.ok || !resolved.url) {
          if (loading) {
            loading.style.display = 'none';
          }
          if (errBox) {
            errBox.style.display = '';
            errBox.innerHTML =
              '<p class="octavawms-notice octavawms-notice--error">' +
              esc(cfg.strings.labelPreviewError || '') +
              '</p>';
          }
          if (fallbackDl instanceof HTMLAnchorElement) {
            fallbackDl.href = String(downloadUrl);
            fallbackDl.style.display = '';
          }
          return;
        }
        if (resolved.objectUrl) {
          labelViewerPdfObjectUrl = resolved.url;
        }
        return fetch(resolved.url)
          .then(function (r) {
            return r.arrayBuffer();
          })
          .then(function (buf) {
            return pdfjsLib.getDocument({ data: buf }).promise;
          })
          .then(function (pdf) {
            pdfJsDoc = pdf;
            return pdf.getPage(1);
          })
          .then(function (page) {
            pdfJsPage = page;
            pdfUserZoomScale = 1;
            if (loading) {
              loading.style.display = 'none';
            }
            canvas.style.display = '';
            renderPdfPageToCanvas('fit');
            syncLabelViewerActionUrls(resolved.url, !!resolved.objectUrl);
          })
          .catch(function () {
            if (loading) {
              loading.style.display = 'none';
            }
            if (errBox) {
              errBox.style.display = '';
              errBox.innerHTML =
                '<p class="octavawms-notice octavawms-notice--error">' +
                esc(cfg.strings.labelPreviewError || '') +
                '</p>';
            }
          });
      })
      .catch(function () {
        if (loading) {
          loading.style.display = 'none';
        }
      });
  }

  function openLabelPrint(url) {
    if (!url) {
      return;
    }
    const printSrc = labelUrlForIframeOrPrint(url);
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = printSrc;
    document.body.appendChild(iframe);
    iframe.onload = function () {
      setTimeout(function () {
        try {
          if (iframe.contentWindow) {
            iframe.contentWindow.print();
          }
        } catch (e) {
          window.open(printSrc, '_blank', 'noopener,noreferrer');
        }
        setTimeout(function () {
          if (iframe.parentNode) {
            iframe.parentNode.removeChild(iframe);
          }
        }, 2000);
      }, 100);
    };
  }

  function labelViewerHeadRowHtml(actionsInnerHtml) {
    const title = esc(cfg.strings.labelViewerTitle || '');
    const actions = actionsInnerHtml ? '<div class="octavawms-label-viewer-actions">' + actionsInnerHtml + '</div>' : '';
    return (
      '<div class="octavawms-label-viewer-head">' +
      '<h3 class="octavawms-label-viewer-title">' +
      title +
      '</h3>' +
      actions +
      '</div>'
    );
  }

  function labelViewerToolbarZoomHtml() {
    return (
      '<div class="ow-pdf-toolbar">' +
      '<button type="button" class="button button-small" data-octavawms-action="pdf-zoom-out" title="' +
      esc(cfg.strings.zoomOut || '') +
      '">−</button>' +
      '<button type="button" class="button button-small" data-octavawms-action="pdf-zoom-in" title="' +
      esc(cfg.strings.zoomIn || '') +
      '">+</button>' +
      '<button type="button" class="button button-small" data-octavawms-action="pdf-fit" title="' +
      esc(cfg.strings.fitPage || '') +
      '">' +
      esc(cfg.strings.fitPage || 'Fit') +
      '</button>' +
      '</div>'
    );
  }

  /**
   * @param {string} downloadUrl
   * @param {number} shipmentId
   * @param {'draft'|'generated'|'locked'} labelState
   */
  function labelViewerBodyInner(downloadUrl, shipmentId, labelState) {
    const sidStr = esc(String(shipmentId));
    if (labelState === 'draft') {
      const genBtn =
        '<button type="button" class="button button-primary" disabled data-octavawms-action="generate-label" data-shipment-id="' +
        sidStr +
        '">' +
        esc(cfg.strings.generateLabel) +
        '</button>';
      return (
        labelViewerHeadRowHtml(genBtn) +
        '<p class="octavawms-muted">' +
        esc(cfg.strings.labelDraftHint || '') +
        '</p>'
      );
    }

    const dlNorm = downloadUrl ? labelUrlForIframeOrPrint(String(downloadUrl)) : '';
    const locked = labelState === 'locked';
    const regDis = locked ? ' disabled' : '';
    const regTitle = locked ? ' title="' + esc(cfg.strings.shipmentLocked || '') + '"' : '';

    const zoomBar = labelViewerToolbarZoomHtml();
    const actionsRight =
      '<button type="button" class="button button-primary" data-octavawms-action="print-label" data-label-url="' +
      hrefAttr(dlNorm) +
      '">' +
      esc(cfg.strings.printLabel || '') +
      '</button>' +
      '<a class="button button-secondary octavawms-label-viewer-download" href="' +
      hrefAttr(dlNorm) +
      '" target="_blank" rel="noopener noreferrer">' +
      esc(cfg.strings.downloadLabel) +
      '</a>' +
      '<button type="button" class="button button-link-delete"' +
      regDis +
      regTitle +
      ' data-octavawms-action="generate-label" data-shipment-id="' +
      sidStr +
      '">' +
      esc(cfg.strings.regenerateLabel || '') +
      '</button>';

    const toolbarMerge =
      '<div class="ow-label-toolbar-merge">' +
      zoomBar +
      '<div class="ow-label-toolbar-actions">' +
      actionsRight +
      '</div></div>';

    if (!downloadUrl) {
      return (
        labelViewerHeadRowHtml(
          '<button type="button" class="button button-primary" data-octavawms-action="fetch-label-pdf" data-shipment-id="' +
            sidStr +
            '">' +
            esc(cfg.strings.fetchLabel || '') +
            '</button>'
        ) +
        '<p class="octavawms-muted"><span class="octavawms-spinner"></span> ' +
        esc(cfg.strings.fetchingLabel || '') +
        '</p>'
      );
    }

    return (
      labelViewerHeadRowHtml('') +
      toolbarMerge +
      '<div class="octavawms-label-viewer-frame-wrap">' +
      '<div id="octavawms-label-viewer-loading" class="octavawms-muted octavawms-label-viewer-loading">' +
      '<span class="octavawms-spinner"></span> ' +
      esc(cfg.strings.loading || '') +
      '</div>' +
      '<div class="ow-pdf-canvas-wrap">' +
      '<canvas id="octavawms-label-pdf-canvas" class="ow-pdf-canvas" aria-label="' +
      esc(cfg.strings.labelViewerTitle || '') +
      '"></canvas>' +
      '</div>' +
      '<div id="octavawms-label-viewer-error" class="octavawms-label-viewer-error" style="display:none"></div>' +
      '<a id="octavawms-label-viewer-fallback-dl" class="button button-primary octavawms-label-viewer-fallback-dl" href="' +
      hrefAttr(dlNorm) +
      '" style="display:none" target="_blank" rel="noopener noreferrer">' +
      esc(cfg.strings.downloadLabel) +
      '</a>' +
      '</div>'
    );
  }

  /**
   * @param {'draft'|'generated'|'locked'} labelState
   */
  function labelViewerSectionHtml(downloadUrl, shipmentId, labelState) {
    return (
      '<div class="octavawms-sp-card octavawms-label-viewer-card" id="octavawms-label-viewer-card" data-shipment-id="' +
      esc(String(shipmentId)) +
      '">' +
      '<div id="octavawms-label-viewer-body" data-shipment-id="' +
      esc(String(shipmentId)) +
      '">' +
      labelViewerBodyInner(downloadUrl, shipmentId, labelState) +
      '</div></div>'
    );
  }

  function scheduleFetchLabelPdf(shipmentId) {
    const viewerBody = document.getElementById('octavawms-label-viewer-body');
    if (viewerBody) {
      viewerBody.innerHTML =
        labelViewerHeadRowHtml('') +
        '<p class="octavawms-muted"><span class="octavawms-spinner"></span> ' +
        esc(cfg.strings.fetchingLabel || '') +
        '</p>';
    }
    fetchLabelPdf(shipmentId, true);
  }

  function fetchLabelPdf(shipmentId, silent) {
    const viewerBody = document.getElementById('octavawms-label-viewer-body');
    if (!silent && viewerBody) {
      viewerBody.innerHTML =
        labelViewerHeadRowHtml('') +
        '<p class="octavawms-muted"><span class="octavawms-spinner"></span> ' +
        esc(cfg.strings.fetchingLabel || '') +
        '</p>';
    }
    const form = new URLSearchParams();
    form.set('action', 'octavawms_fetch_label');
    form.set('nonce', String(cfg.fetchLabelNonce || ''));
    form.set('order_id', String(cfg.orderId));
    form.set('shipment_id', String(shipmentId));
    return fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form,
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        const viewerEl = document.getElementById('octavawms-label-viewer-body');
        if (!j || !j.success || !j.data || !j.data.download_url) {
          const msg = (j && j.data && j.data.message) || cfg.strings.labelFetchError || cfg.strings.error;
          if (viewerEl) {
            viewerEl.innerHTML =
              labelViewerHeadRowHtml(
                '<button type="button" class="button button-primary" data-octavawms-action="fetch-label-pdf" data-shipment-id="' +
                  esc(String(shipmentId)) +
                  '">' +
                  esc(cfg.strings.tryAgain || cfg.strings.fetchLabel || 'Retry') +
                  '</button>'
              ) +
              '<p class="octavawms-notice octavawms-notice--error">' +
              esc(msg) +
              '</p>';
          }
          if (!silent) {
            window.alert(msg);
          }
          return;
        }
        const url = String(j.data.download_url);
        if (viewerEl) {
          viewerEl.innerHTML = labelViewerBodyInner(url, shipmentId, 'generated');
          attachLabelPdfViewer(url);
        }
      })
      .catch(function () {
        const viewerErr = document.getElementById('octavawms-label-viewer-body');
        if (viewerErr) {
          viewerErr.innerHTML =
            labelViewerHeadRowHtml(
              '<button type="button" class="button button-primary" data-octavawms-action="fetch-label-pdf" data-shipment-id="' +
                esc(String(shipmentId)) +
                '">' +
                esc(cfg.strings.tryAgain || cfg.strings.fetchLabel || 'Retry') +
                '</button>'
            ) +
            '<p class="octavawms-notice octavawms-notice--error">' +
            esc(cfg.strings.error) +
            '</p>';
        }
        if (!silent) {
          window.alert(cfg.strings.error);
        }
      });
  }

  function cancelShipmentLabel(shipmentId) {
    const form = new URLSearchParams();
    form.set('action', 'octavawms_cancel_label');
    form.set('nonce', String(cfg.cancelLabelNonce || ''));
    form.set('order_id', String(cfg.orderId));
    form.set('shipment_id', String(shipmentId));
    return fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form,
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        if (!j || !j.success) {
          window.alert((j && j.data && j.data.message) || cfg.strings.error);
          return;
        }
        fetchStatus();
      })
      .catch(function () {
        window.alert(cfg.strings.error);
      });
  }

  function servicePointSlotLoading(shipmentId) {
    return (
      '<div class="octavawms-sp-card">' +
      '<h3 class="octavawms-sp-card__title">' +
      esc(cfg.strings.servicePointSection) +
      '</h3>' +
      '<div id="octavawms-sp-body" data-shipment-id="' +
      esc(String(shipmentId)) +
      '">' +
      (shipmentId > 0
        ? '<p class="octavawms-muted">' + esc(cfg.strings.loading) + '</p>'
        : '<p class="octavawms-muted">' + esc(cfg.strings.noShipmentForSection) + '</p>') +
      '</div></div>'
    );
  }

  function renderPanel(data) {
    const hasOrder = data.has_order;
    const shipment = data.shipment;
    const hasLocal = data.has_label_locally;
    const dl = data.download_url || '';
    const sid = shipmentIdFrom(data);
    let html = '';

    if (!hasOrder) {
      revokeLabelViewerPdfObjectUrl();
      lastOrderPanelPayload = null;
      panelShipment = null;
      spDetail = null;
      html =
        '<div class="octavawms-connect-page">' +
        toolbarHtml() +
        '<div class="octavawms-connect-section">' +
        '<div class="octavawms-connect-section-body">' +
        '<p class="octavawms-notice octavawms-notice--info">' +
        esc(cfg.strings.noOrder) +
        '</p>' +
        '<div class="octavawms-actions-row">' +
        '<button type="button" class="button button-primary" data-octavawms-action="upload-order">' +
        esc(cfg.strings.uploadOrder) +
        '</button></div></div></div></div>';
      root.innerHTML = html;
      return;
    }

    if (!shipment || !shipment.id) {
      revokeLabelViewerPdfObjectUrl();
      lastOrderPanelPayload = null;
      panelShipment = null;
      spDetail = null;
      html = '<div class="octavawms-connect-page">' + toolbarHtml() + '<div class="octavawms-connect-grid">';
      html +=
        '<div class="octavawms-slot octavawms-slot--label">' +
        '<section class="octavawms-connect-section">' +
        '<h3 class="octavawms-connect-section-title">' +
        esc(cfg.strings.orderSynced) +
        '</h3>' +
        '<div class="octavawms-connect-section-body">' +
        '<p class="octavawms-muted">' +
        esc(cfg.strings.awaitingShipment) +
        '</p>' +
        '<p class="octavawms-muted octavawms-muted--tight">' +
        esc(cfg.strings.noShipmentForSection) +
        '</p>' +
        '</div></section></div>';
      html +=
        '<div class="octavawms-slot octavawms-slot--sp">' + servicePointSlotLoading(0) + '</div>';
      html += '</div></div>';
      root.innerHTML = html;
      return;
    }

    lastOrderPanelPayload = data;
    panelShipment = shipment;
    spDetail = null;
    lastSpShipmentId = 0;

    const lblState = panelLabelState(data, shipment);
    const hasTracking =
      shipment &&
      shipment.tracking_number != null &&
      String(shipment.tracking_number).trim() !== '';

    const gridModifier = lblState !== 'draft' ? ' octavawms-connect-grid--label-first' : '';

    html =
      '<div class="octavawms-connect-page">' +
      toolbarHtml(shipmentMetaRowHtml(data, shipment)) +
      pendingErrorBannerWrapperHtml(panelShipment, null) +
      '<div class="octavawms-connect-grid' +
      gridModifier +
      '">';

    html +=
      '<div class="octavawms-slot octavawms-slot--label">' +
      createLabelAndBoxesSection(
        data,
        shipment,
        hasLocal,
        dl,
        sid,
        '<span class="octavawms-spinner"></span> <span>' + esc(cfg.strings.loading) + '</span>'
      ) +
      '</div>';

    html +=
      '<div class="octavawms-slot octavawms-slot--sp octavawms-slot--stack">' +
      labelViewerSectionHtml(dl, sid, lblState) +
      (!hasTracking ? '<div class="octavawms-sp-below-label">' + servicePointSlotLoading(sid) + '</div>' : '') +
      '</div>';

    html += '</div></div>';
    revokeLabelViewerPdfObjectUrl();
    root.innerHTML = html;

    if (lblState === 'generated' || lblState === 'locked') {
      if (dl) {
        attachLabelPdfViewer(String(dl));
      } else if (hasTracking) {
        scheduleFetchLabelPdf(sid);
      }
    }

    if (!hasTracking) {
      loadServicePointSection(sid);
    }
    loadPlacesSection(sid);

    if (pendingLabelSuccessToast) {
      pendingLabelSuccessToast = false;
      window.setTimeout(function () {
        showLabelToast(cfg.strings.labelGeneratedToast || '');
      }, 80);
    }

    if (hasTracking && lblState === 'locked') {
      const tn =
        shipment && shipment.tracking_number != null ? String(shipment.tracking_number).trim() : '';
      if (tn) {
        hydrateTrackingCarrierButtons(sid, tn);
      }
    }

    syncLabelPrimaryActions();
  }

  function loadServicePointSection(shipmentId) {
    const body = document.getElementById('octavawms-sp-body');
    if (!body || shipmentId <= 0) {
      return;
    }
    body.innerHTML =
      '<p class="octavawms-muted">' + '<span class="octavawms-spinner"></span> ' + esc(cfg.strings.loading) + '</p>';
    connectorPost('octavawms_shipment_detail', { shipment_id: String(shipmentId) })
      .then(function (j) {
        if (!j || !j.success) {
          body.innerHTML =
            '<p class="octavawms-notice octavawms-notice--error">' +
            esc((j && j.data && j.data.message) || cfg.strings.error) +
            '</p>';
          return;
        }
        spDetail = j.data.detail || null;
        lastSpShipmentId = shipmentId;
        renderSpShell(body, shipmentId);
        syncPendingErrorBanner();
      })
      .catch(function () {
        body.innerHTML =
          '<p class="octavawms-notice octavawms-notice--error">' + esc(cfg.strings.error) + '</p>';
      });
  }

  function selectedStrategyIndex() {
    const opts = cfg.deliveryStrategyOptions || [];
    const cur = spDetail && spDetail.delivery_strategy !== undefined ? spDetail.delivery_strategy : '';
    for (let i = 0; i < opts.length; i++) {
      if (opts[i].value === cur) {
        return String(i);
      }
    }
    return '0';
  }

  function destroyOctavaSelectWooIn(container) {
    if (typeof jQuery === 'undefined') {
      return;
    }
    const $c = container ? jQuery(container) : jQuery(document);
    $c.find('#octavawms-ds-select, #octavawms-loc-select, #octavawms-sp-select').each(function () {
      const $t = jQuery(this);
      if ($t.data('select2')) {
        $t.selectWoo('destroy');
      }
    });
  }

  function spSelectGated() {
    if (!spDetail) {
      return true;
    }
    return !spDetail.delivery_service_id || !spDetail.locality_id;
  }

  function getNoLockersPayload() {
    const cb = document.getElementById('octavawms-sp-nolockers-cb');
    return cb && cb.checked ? '1' : '';
  }

  function syncSpApplyButton() {
    const btn = document.getElementById('octavawms-sp-apply-btn');
    if (!btn) {
      return;
    }
    if (typeof jQuery === 'undefined') {
      btn.disabled = true;
      return;
    }
    const raw = jQuery('#octavawms-sp-select').val();
    const id = raw ? parseInt(String(raw), 10) || 0 : 0;
    btn.disabled = !id || spSelectGated();
  }

  function updateSpGateHint() {
    const hint = document.getElementById('octavawms-sp-gate-hint');
    if (!hint) {
      return;
    }
    hint.textContent = spSelectGated() ? cfg.strings.selectCarrierLocalityFirst || '' : '';
  }

  function selectWooAvailable() {
    return typeof jQuery !== 'undefined' && typeof jQuery.fn.selectWoo === 'function';
  }

  function formatLocalityDisplay(d) {
    if (!d) {
      return '';
    }
    const raw = String(d.locality_label || '').trim();
    return raw;
  }

  /** @param {Record<string, unknown>|null|undefined} sp */
  function servicePointMapsUrl(sp) {
    if (!sp || typeof sp !== 'object') {
      return '';
    }
    const geo = String(sp.geo || '').trim();
    const m = geo.match(/POINT\s*\(\s*([+-]?\d+(?:\.\d+)?)\s+([+-]?\d+(?:\.\d+)?)\s*\)/i);
    if (m) {
      const lng = parseFloat(m[1]);
      const lat = parseFloat(m[2]);
      if (!isNaN(lat) && !isNaN(lng)) {
        return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(lat + ',' + lng);
      }
    }
    const q = String(sp.raw_address || sp.address || sp.name || '').trim();
    return q ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q) : '';
  }

  /** @param {Record<string, unknown>|null|undefined} sp */
  function servicePointPreviewContextFor(sp) {
    if (!sp || !spDetail || !spDetail.current_service_point) {
      return {};
    }
    if (String(spDetail.current_service_point.id || '') !== String(sp.id || '')) {
      return {};
    }
    const c = spDetail.service_point_context;
    return c && typeof c === 'object' ? c : {};
  }

  /** @param {string} label @param {string} value */
  function spPreviewLine(label, value) {
    const v = String(value || '').trim();
    if (!v) {
      return '';
    }
    return (
      '<p class="octavawms-sp-preview__line"><span class="octavawms-sp-preview__k">' +
      esc(label) +
      '</span> ' +
      esc(v) +
      '</p>'
    );
  }

  /**
   * @param {Record<string, unknown>|null} sp
   * @param {Record<string, unknown>} ctx
   */
  function renderSpPreviewUnderSelect(sp, ctx) {
    const wrap = document.getElementById('octavawms-sp-preview');
    if (!wrap) {
      return;
    }
    ctx = ctx && typeof ctx === 'object' ? ctx : {};
    if (!sp || !sp.id) {
      wrap.className = 'octavawms-sp-preview is-empty';
      wrap.innerHTML =
        '<p class="octavawms-sp-preview__head">' +
        esc(cfg.strings.servicePointDetails) +
        '</p><p class="octavawms-sp-preview__line octavawms-sp-preview__placeholder">' +
        esc(cfg.strings.noDetailsYet) +
        '</p>';
      return;
    }
    const parts = [];
    parts.push('<p class="octavawms-sp-preview__head">' + esc(cfg.strings.servicePointDetails) + '</p>');
    const nm = String(sp.name || '').trim();
    if (nm) {
      parts.push('<p class="octavawms-sp-preview__title">' + esc(nm) + '</p>');
    }
    const ext = String(sp.ext_id || '').trim();
    if (ext) {
      parts.push(spPreviewLine(cfg.strings.spPreviewId || 'ID', ext));
    }
    const typ = String(sp.type || '').trim();
    if (typ) {
      parts.push(spPreviewLine(cfg.strings.spPreviewType || 'Type', typ));
    }
    const st = String(sp.state || '').trim();
    if (st) {
      parts.push(spPreviewLine(cfg.strings.spPreviewState || 'State', st));
    }
    const addr = String(sp.raw_address || sp.address || '').trim();
    if (addr) {
      parts.push(
        '<p class="octavawms-sp-preview__line"><span class="octavawms-sp-preview__k">' +
          esc(cfg.strings.spPreviewAddress || 'Address') +
          '</span></p><p class="octavawms-sp-preview__line">' +
          esc(addr) +
          '</p>'
      );
    }
    const ph = String(sp.raw_phone || '').trim();
    if (ph) {
      parts.push(spPreviewLine(cfg.strings.spPreviewPhone || 'Phone', ph));
    }
    const hrs = String(sp.working_hours_summary || '').trim();
    if (hrs) {
      parts.push(spPreviewLine(cfg.strings.spPreviewHours || 'Working hours', hrs));
    }
    const tt = String(sp.raw_timetable || '').trim();
    if (tt) {
      parts.push(spPreviewLine(cfg.strings.spPreviewTimetable || 'Schedule', tt));
    }
    const rd = String(sp.raw_description || '').trim();
    if (rd && !hrs && rd.length < 400 && rd.charAt(0) !== '[') {
      parts.push(spPreviewLine(cfg.strings.spPreviewAiNote || 'Note', rd));
    }
    const ai = ctx && ctx.ai_message ? String(ctx.ai_message).trim() : '';
    if (ai) {
      parts.push(
        '<div class="octavawms-notice octavawms-notice--info" style="margin:8px 0 0">' + esc(ai) + '</div>'
      );
    }
    const dm = ctx && ctx.distance_m != null && ctx.distance_m !== '' ? Number(ctx.distance_m) : NaN;
    if (!isNaN(dm)) {
      const tpl = String(cfg.strings.spDistanceMeters || 'Distance: %s m');
      parts.push(
        '<p class="octavawms-sp-preview__line octavawms-muted">' + esc(tpl.replace('%s', String(dm))) + '</p>'
      );
    }
    const mapU = servicePointMapsUrl(sp);
    if (mapU) {
      parts.push(
        '<p class="octavawms-sp-preview__actions"><a class="button button-secondary" href="' +
          hrefAttr(mapU) +
          '" target="_blank" rel="noopener noreferrer">' +
          esc(cfg.strings.spOpenInMaps || 'Open in Maps') +
          '</a></p>'
      );
    }
    wrap.className = 'octavawms-sp-preview';
    wrap.innerHTML = parts.join('');
  }

  function refreshSpPreviewFromSelect() {
    if (typeof jQuery === 'undefined') {
      return;
    }
    const $sp = jQuery('#octavawms-sp-select');
    if (!$sp.length) {
      return;
    }
    let row = $sp.select2('data');
    row = Array.isArray(row) ? row[0] : row;
    let sp = row && row.sp ? row.sp : null;
    const vid = row && row.id != null ? parseInt(String(row.id), 10) || 0 : 0;
    if (!sp && spDetail && spDetail.current_service_point && vid === parseInt(String(spDetail.current_service_point.id || 0), 10)) {
      sp = spDetail.current_service_point;
    }
    renderSpPreviewUnderSelect(sp && typeof sp === 'object' ? sp : null, servicePointPreviewContextFor(sp));
  }

  function initEditShipmentSelects(shipmentId) {
    const bodyEl = document.getElementById('octavawms-sp-body');
    if (!bodyEl || !selectWooAvailable()) {
      if (bodyEl && !selectWooAvailable()) {
        const err = document.createElement('p');
        err.className = 'octavawms-notice octavawms-notice--error';
        err.textContent = cfg.strings.needSelectWoo || '';
        bodyEl.insertBefore(err, bodyEl.firstChild);
      }
      return;
    }
    beginCarrierFirstPagePrefetch();
    const $ = jQuery;
    const stratOpts = cfg.deliveryStrategyOptions || [];
    const stSel = document.getElementById('octavawms-sp-strategy');
    if (stSel) {
      stSel.value = selectedStrategyIndex();
      stSel.addEventListener('change', function () {
        const idx = parseInt(stSel.value, 10);
        if (isNaN(idx) || !stratOpts[idx]) {
          return;
        }
        const strat = stratOpts[idx].value;
        const st = document.getElementById('octavawms-sp-inline-status');
        if (st) {
          st.innerHTML = '<span class="octavawms-spinner"></span> ' + esc(cfg.strings.saving);
        }
        patchShipmentContext(shipmentId, 'delivery_strategy', { strategy: strat })
          .then(function (j) {
            if (st) {
              st.textContent = '';
            }
            if (!j || !j.success) {
              if (st) {
                st.innerHTML =
                  '<span class="octavawms-text-danger">' +
                  esc((j && j.data && j.data.message) || cfg.strings.error) +
                  '</span>';
              }
              stSel.value = selectedStrategyIndex();
              return;
            }
            spDetail = j.data.detail || spDetail;
            stSel.value = selectedStrategyIndex();
            $('#octavawms-sp-select').val(null).trigger('change');
            updateSpGateHint();
            const $sp = $('#octavawms-sp-select');
            $sp.prop('disabled', spSelectGated());
            syncSpApplyButton();
            syncPendingErrorBanner();
          })
          .catch(function () {
            if (st) {
              st.innerHTML = '<span class="octavawms-text-danger">' + esc(cfg.strings.error) + '</span>';
            }
            stSel.value = selectedStrategyIndex();
          });
      });
    }

    const swBase = { width: '100%', dropdownCssClass: 'octavawms-select2-wrap' };

    $('#octavawms-ds-select').selectWoo(
      $.extend({}, swBase, {
        placeholder: cfg.strings.carrierPlaceholder || '',
        allowClear: false,
        minimumInputLength: 0,
        ajax: {
          delay: 250,
          url: cfg.ajaxUrl,
          type: 'POST',
          dataType: 'json',
          transport: carrierAjaxTransport,
          data: function (params) {
            return {
              action: 'octavawms_delivery_services',
              nonce: cfg.connectorNonce,
              order_id: String(cfg.orderId),
              search: params.term || '',
              page: params.page || 1,
            };
          },
          processResults: function (resp, params) {
            const page = params.page || 1;
            if (!resp || !resp.success || !resp.data) {
              return { results: [], pagination: { more: false } };
            }
            const items = resp.data.items || [];
            const totalPages = parseInt(String(resp.data.total_pages || 1), 10) || 1;
            return {
              results: items
                .map(function (it) {
                  const id = parseInt(String(it.id || 0), 10) || 0;
                  return { id: String(id), text: String(it.name || '').trim() };
                })
                .filter(function (r) {
                  return r.id !== '0';
                }),
              pagination: { more: page < totalPages },
            };
          },
        },
      })
    );

    if (spDetail && spDetail.delivery_service_id) {
      const txt = String(spDetail.delivery_service_name || '').trim();
      const o = new Option(txt, String(spDetail.delivery_service_id), true, true);
      $('#octavawms-ds-select').append(o).trigger('change');
    }

    $('#octavawms-ds-select').on('select2:select', function (e) {
      const id = parseInt(String(e.params.data.id || '0'), 10) || 0;
      if (!id) {
        return;
      }
      const st = document.getElementById('octavawms-sp-inline-status');
      if (st) {
        st.innerHTML = '<span class="octavawms-spinner"></span> ' + esc(cfg.strings.saving);
      }
      patchShipmentContext(shipmentId, 'delivery_service', { delivery_service_id: String(id) })
        .then(function (j) {
          if (st) {
            st.textContent = '';
          }
          if (!j || !j.success) {
            if (st) {
              st.innerHTML =
                '<span class="octavawms-text-danger">' +
                esc((j && j.data && j.data.message) || cfg.strings.error) +
                '</span>';
            }
            return;
          }
          spDetail = j.data.detail || spDetail;
          $('#octavawms-sp-select').val(null).trigger('change');
          updateSpGateHint();
          $('#octavawms-sp-select').prop('disabled', spSelectGated());
          syncSpApplyButton();
          syncPendingErrorBanner();
        })
        .catch(function () {
          if (st) {
            st.innerHTML = '<span class="octavawms-text-danger">' + esc(cfg.strings.error) + '</span>';
          }
        });
    });

    $('#octavawms-loc-select').selectWoo(
      $.extend({}, swBase, {
        placeholder: cfg.strings.localityPlaceholder || '',
        allowClear: true,
        minimumInputLength: 2,
        language: {
          inputTooShort: function () {
            return cfg.strings.localitySearchMin || '';
          },
        },
        ajax: {
          delay: 280,
          url: cfg.ajaxUrl,
          type: 'POST',
          dataType: 'json',
          data: function (params) {
            return {
              action: 'octavawms_localities',
              nonce: cfg.connectorNonce,
              order_id: String(cfg.orderId),
              search: params.term || '',
              page: params.page || 1,
            };
          },
          processResults: function (resp, params) {
            const page = params.page || 1;
            if (!resp || !resp.success || !resp.data) {
              return { results: [], pagination: { more: false } };
            }
            const items = resp.data.items || [];
            const totalPages = parseInt(String(resp.data.total_pages || 1), 10) || 1;
            return {
              results: items
                .map(function (it) {
                  const id = parseInt(String(it.id || 0), 10) || 0;
                  return { id: String(id), text: String(it.label || it.name || '').trim() };
                })
                .filter(function (r) {
                  return r.id !== '0';
                }),
              pagination: { more: page < totalPages },
            };
          },
        },
      })
    );

    if (spDetail && spDetail.locality_id) {
      const lab = formatLocalityDisplay(spDetail);
      const o = new Option(lab || String(spDetail.locality_id), String(spDetail.locality_id), true, true);
      $('#octavawms-loc-select').append(o).trigger('change');
    }

    $('#octavawms-loc-select').on('select2:select', function (e) {
      const id = parseInt(String(e.params.data.id || '0'), 10) || 0;
      const st = document.getElementById('octavawms-sp-inline-status');
      if (st) {
        st.innerHTML = '<span class="octavawms-spinner"></span> ' + esc(cfg.strings.saving);
      }
      patchShipmentContext(shipmentId, 'recipient_locality', { recipient_locality_id: String(id) })
        .then(function (j) {
          if (st) {
            st.textContent = '';
          }
          if (!j || !j.success) {
            if (st) {
              st.innerHTML =
                '<span class="octavawms-text-danger">' +
                esc((j && j.data && j.data.message) || cfg.strings.error) +
                '</span>';
            }
            return;
          }
          spDetail = j.data.detail || spDetail;
          $('#octavawms-sp-select').val(null).trigger('change');
          updateSpGateHint();
          $('#octavawms-sp-select').prop('disabled', spSelectGated());
          syncSpApplyButton();
          syncPendingErrorBanner();
        })
        .catch(function () {
          if (st) {
            st.innerHTML = '<span class="octavawms-text-danger">' + esc(cfg.strings.error) + '</span>';
          }
        });
    });

    $('#octavawms-loc-select').on('select2:clear', function () {
      const st = document.getElementById('octavawms-sp-inline-status');
      if (st) {
        st.innerHTML = '<span class="octavawms-spinner"></span> ' + esc(cfg.strings.saving);
      }
      patchShipmentContext(shipmentId, 'recipient_locality', { recipient_locality_id: '0' })
        .then(function (j) {
          if (st) {
            st.textContent = '';
          }
          if (j && j.success) {
            spDetail = j.data.detail || spDetail;
            $('#octavawms-sp-select').val(null).trigger('change');
          }
          updateSpGateHint();
          $('#octavawms-sp-select').prop('disabled', spSelectGated());
          syncSpApplyButton();
          syncPendingErrorBanner();
        })
        .catch(function () {
          if (st) {
            st.innerHTML = '<span class="octavawms-text-danger">' + esc(cfg.strings.error) + '</span>';
          }
        });
    });

    $('#octavawms-sp-select').selectWoo(
      $.extend({}, swBase, {
        placeholder: cfg.strings.pickupPointPlaceholder || '',
        allowClear: true,
        minimumInputLength: 0,
        ajax: {
          delay: 280,
          url: cfg.ajaxUrl,
          type: 'POST',
          dataType: 'json',
          data: function (params) {
            const spTf = spDetail ? spTypeFilterFromStrategy(String(spDetail.delivery_strategy || '')) : '';
            const payload = {
              action: 'octavawms_service_points',
              nonce: cfg.connectorNonce,
              order_id: String(cfg.orderId),
              shipment_id: String(shipmentId),
              search: params.term || '',
              no_lockers: getNoLockersPayload(),
              locality_id: spDetail && spDetail.locality_id ? String(spDetail.locality_id) : '',
              delivery_service_id: spDetail && spDetail.delivery_service_id ? String(spDetail.delivery_service_id) : '',
            };
            if (spTf) {
              payload.sp_type_filter = spTf;
            }
            return payload;
          },
          processResults: function (resp) {
            if (!resp || !resp.success || !resp.data) {
              return { results: [], pagination: { more: false } };
            }
            const items = resp.data.items || [];
            return {
              results: items
                .map(function (it) {
                  const id = parseInt(String(it.id || 0), 10) || 0;
                  const parts = [it.name, it.ext_id].filter(Boolean);
                  const text = parts.join(' · ') || String(id);
                  return { id: String(id), text: text, sp: it };
                })
                .filter(function (r) {
                  return r.id !== '0';
                }),
              pagination: { more: false },
            };
          },
        },
      })
    );

    $('#octavawms-sp-select').on('select2:opening', function (e) {
      if (spSelectGated()) {
        e.preventDefault();
      }
    });

    const cb = document.getElementById('octavawms-sp-nolockers-cb');
    if (cb) {
      cb.addEventListener('change', function () {
        $('#octavawms-sp-select').val(null).trigger('change');
      });
    }

    if (spDetail && spDetail.current_service_point && spDetail.current_service_point.id) {
      const cp = spDetail.current_service_point;
      const parts = [cp.name, cp.ext_id].filter(Boolean);
      const text = parts.join(' · ') || String(cp.id);
      const o = new Option(text, String(cp.id), true, true);
      $('#octavawms-sp-select').append(o).trigger('change');
    }

    $('#octavawms-sp-select').on('select2:select', function (e) {
      const d = e.params && e.params.data ? e.params.data : null;
      const spSel = d && d.sp ? d.sp : null;
      renderSpPreviewUnderSelect(spSel && typeof spSel === 'object' ? spSel : null, servicePointPreviewContextFor(spSel));
    });
    $('#octavawms-sp-select').on('select2:clear', function () {
      renderSpPreviewUnderSelect(null, {});
    });
    $('#octavawms-sp-select').on('change', function () {
      syncSpApplyButton();
      window.setTimeout(refreshSpPreviewFromSelect, 0);
    });
    updateSpGateHint();
    $('#octavawms-sp-select').prop('disabled', spSelectGated());
    syncSpApplyButton();
    refreshSpPreviewFromSelect();

    const applyBtn = document.getElementById('octavawms-sp-apply-btn');
    if (applyBtn) {
      applyBtn.addEventListener('click', function (e) {
        e.preventDefault();
        const spid = parseInt(String($('#octavawms-sp-select').val() || '0'), 10) || 0;
        if (!spid || spSelectGated()) {
          return;
        }
        saveServicePoint(shipmentId, spid);
      });
    }
  }

  function renderSpShell(body, shipmentId) {
    destroyOctavaSelectWooIn(body);

    let h = '';
    if (spDetail && spDetail.current_service_point) {
      const c = spDetail.current_service_point;
      const label = [c.name, c.ext_id].filter(Boolean).join(' — ') || String(c.id);
      h +=
        '<p class="octavawms-muted octavawms-muted--tight octavawms-sp-current-line"><strong>' +
        esc(cfg.strings.currentPoint) +
        ':</strong> ' +
        esc(label) +
        (c.address ? ' · ' + esc(c.address) : '') +
        '</p>';
    }

    if (spDetail && spDetail.shipment_state === 'pending_queued') {
      h +=
        '<p class="octavawms-notice octavawms-notice--info">' +
        esc(cfg.strings.shipmentQueuedInfo) +
        '</p>';
    }

    h += '<div class="octavawms-sp-field">';
    h +=
      '<label class="octavawms-sp-context__label" for="octavawms-sp-strategy">' +
      esc(cfg.strings.strategyForAi) +
      '</label>';
    h += '<select id="octavawms-sp-strategy" class="widefat">';
    const stratOpts = cfg.deliveryStrategyOptions || [];
    const dStrat = spDetail && spDetail.delivery_strategy !== undefined ? spDetail.delivery_strategy : '';
    for (let si = 0; si < stratOpts.length; si++) {
      const sSel = stratOpts[si].value === dStrat ? ' selected' : '';
      h +=
        '<option value="' + esc(String(si)) + '"' + sSel + '>' + esc(stratOpts[si].label) + '</option>';
    }
    h += '</select></div>';

    h += '<div class="octavawms-sp-field">';
    h +=
      '<label class="octavawms-sp-context__label" for="octavawms-ds-select">' +
      esc(cfg.strings.deliveryCarrier) +
      '</label>';
    h += '<select id="octavawms-ds-select" class="widefat octavawms-selectwoo"></select></div>';

    h += '<div class="octavawms-sp-field">';
    h +=
      '<label class="octavawms-sp-context__label" for="octavawms-loc-select">' +
      esc(cfg.strings.recipientLocality) +
      '</label>';
    h += '<select id="octavawms-loc-select" class="widefat octavawms-selectwoo"></select></div>';

    h += '<div class="octavawms-sp-field octavawms-sp-field--sp">';
    h += '<div class="octavawms-sp-label-row">';
    h +=
      '<label class="octavawms-sp-context__label" for="octavawms-sp-select">' +
      esc(cfg.strings.servicePointFieldLabel) +
      '</label>';
    h +=
      '<span class="octavawms-sp-toggle"><label><input type="checkbox" id="octavawms-sp-nolockers-cb" /> ' +
      esc(cfg.strings.noLockers) +
      '</label></span>';
    h += '</div>';
    h += '<p class="octavawms-sp-gate-hint octavawms-muted" id="octavawms-sp-gate-hint"></p>';
    h +=
      '<select id="octavawms-sp-select" class="widefat octavawms-selectwoo" aria-label="' +
      esc(cfg.strings.servicePointFieldLabel) +
      '"></select>';
    h +=
      '<div id="octavawms-sp-preview" class="octavawms-sp-preview is-empty"><p class="octavawms-sp-preview__head">' +
      esc(cfg.strings.servicePointDetails) +
      '</p><p class="octavawms-sp-preview__line octavawms-sp-preview__placeholder">' +
      esc(cfg.strings.noDetailsYet) +
      '</p></div></div>';

    h += '<div class="octavawms-sp-card__footer">';
    h +=
      '<button type="button" class="button button-primary octavawms-sp-apply-full" id="octavawms-sp-apply-btn" disabled data-octavawms-action="sp-save">';
    h += esc(cfg.strings.applyServicePoint);
    h += '</button></div>';

    h += '<p id="octavawms-sp-inline-status" class="octavawms-sp-inline-status"></p>';

    body.innerHTML = h;
    initEditShipmentSelects(shipmentId);
  }

  function saveServicePoint(shipmentId, spId) {
    const statusEl = document.getElementById('octavawms-sp-inline-status');
    const $sp = typeof jQuery !== 'undefined' ? jQuery('#octavawms-sp-select') : null;
    if ($sp && $sp.length) {
      $sp.prop('disabled', true);
    } else {
      const selElBefore = document.getElementById('octavawms-sp-select');
      if (selElBefore) {
        selElBefore.disabled = true;
      }
    }
    if (statusEl) {
      statusEl.innerHTML =
        '<span class="octavawms-spinner"></span> ' + esc(cfg.strings.saving);
    }
    connectorPost('octavawms_save_service_point', {
      shipment_id: String(shipmentId),
      service_point_id: String(spId),
    })
      .then(function (j) {
        if ($sp && $sp.length) {
          $sp.prop('disabled', false);
        } else {
          const selErr = document.getElementById('octavawms-sp-select');
          if (selErr) {
            selErr.disabled = false;
          }
        }
        if (!j || !j.success) {
          if (statusEl) {
            statusEl.innerHTML =
              '<span class="octavawms-text-danger">' +
              esc((j && j.data && j.data.message) || cfg.strings.error) +
              '</span>';
          }
          if ($sp && $sp.length) {
            $sp.prop('disabled', spSelectGated());
          }
          return;
        }
        spDetail = j.data.detail || spDetail;
        const bodySp = document.getElementById('octavawms-sp-body');
        if (bodySp && lastSpShipmentId === shipmentId) {
          renderSpShell(bodySp, shipmentId);
          syncPendingErrorBanner();
        }
        if (statusEl) {
          statusEl.textContent = '';
        }
      })
      .catch(function () {
        const $sx = typeof jQuery !== 'undefined' ? jQuery('#octavawms-sp-select') : null;
        if ($sx && $sx.length) {
          $sx.prop('disabled', spSelectGated());
        } else {
          const selCx = document.getElementById('octavawms-sp-select');
          if (selCx) {
            selCx.disabled = false;
          }
        }
        if (statusEl) {
          statusEl.innerHTML =
            '<span class="octavawms-text-danger">' + esc(cfg.strings.error) + '</span>';
        }
      });
  }

  function loadPlacesSection(shipmentId, opts) {
    opts = opts || {};
    const body = document.getElementById('octavawms-places-body');
    if (!body || shipmentId <= 0) {
      return;
    }
    cancelAllDebouncedPatches();
    if (!opts.silentReload) {
      body.innerHTML =
        '<p class="octavawms-muted">' +
        '<span class="octavawms-spinner"></span> ' +
        esc(cfg.strings.loading) +
        '</p>';
    }
    syncLabelPrimaryActions();
    connectorPost('octavawms_places', { shipment_id: String(shipmentId) })
      .then(function (j) {
        if (!j || !j.success) {
          body.innerHTML =
            '<p class="octavawms-notice octavawms-notice--error">' +
            esc((j && j.data && j.data.message) || cfg.strings.error) +
            '</p>';
          syncLabelPrimaryActions();
          return;
        }
        renderPlacesTable(body, shipmentId, (j.data && j.data.places) || []);
      })
      .catch(function () {
        body.innerHTML =
          '<p class="octavawms-notice octavawms-notice--error">' + esc(cfg.strings.error) + '</p>';
        syncLabelPrimaryActions();
      });
  }

  function loadPlacesSectionSilent(shipmentId) {
    loadPlacesSection(shipmentId, { silentReload: true });
  }

  function renderPlacesTable(body, shipmentId, places) {
    const locked = currentPanelLabelState() === 'locked';
    if (places.length === 0) {
      body.innerHTML = '<p class="octavawms-muted">' + esc(cfg.strings.noPlaces) + '</p>';
      syncLabelPrimaryActions();
      return;
    }

    const nPlaces = places.length;
    let h = '<p class="octavawms-muted octavawms-places-summary">' + placesSummaryLineHtmlFromPlacesJson(places) + '</p>';
    h +=
      '<p class="octavawms-muted octavawms-muted--tight">' +
      esc(cfg.strings.weightRecalcNote || '') +
      '</p>';
    h += '<div class="octavawms-place-table-wrap"><table class="widefat striped octavawms-place-table octavawms-place-table--compact"><thead><tr>';
    h += '<th class="octavawms-place-th-box">' + esc(cfg.strings.boxColumn) + '</th>';
    h +=
      '<th title="' +
      esc(cfg.strings.weightG) +
      '">' +
      esc(cfg.strings.placeTableWeightHeader || '(g)') +
      '</th>';
    h +=
      '<th colspan="3" class="octavawms-place-th-dims" title="' +
      esc(cfg.strings.placeTableDimsHeaderTitle || '') +
      '">' +
      esc(cfg.strings.placeTableDimsHeader || '(W, H, L) mm') +
      '</th>';
    h += '<th class="octavawms-place-th-actions">' + esc(cfg.strings.placeActionsColumn) + '</th>';
    h += '</tr></thead><tbody class="places-tbody">';

    places.forEach(function (p, idx) {
      const pid =
        typeof p.id === 'number' ? p.id : parseInt(String(p.id), 10) || 0;
      const ic =
        typeof p.items_count === 'number'
          ? p.items_count
          : parseInt(String(p.items_count), 10) || 0;
      const pidStr = esc(String(pid));
      let actionsCell = locked
        ? '<span class="octavawms-place-actions-empty"></span>'
        : nPlaces < 2
          ? '<span class="octavawms-place-actions-empty"></span>'
          : ic > 0
            ? '<button type="button" class="button-link octavawms-place-remove" disabled title="' +
              esc(cfg.strings.placeRemoveBlockedTitle) +
              '" aria-disabled="true">&times;</button>'
            : '<button type="button" class="button-link-delete octavawms-place-remove" data-octavawms-action="place-remove"' +
              ' title="' +
              esc(cfg.strings.removePlace) +
              '" data-place-id="' +
              pidStr +
              '" aria-label="' +
              esc(cfg.strings.removePlace) +
              '">&times;</button>';

      const disAttr = locked ? ' disabled' : '';

      h += '<tr class="octavawms-place-row" data-place-id="' + pidStr + '" data-items-count="' + esc(String(ic)) + '">';
      h += '<td class="octavawms-place-td-num">' + esc(String(idx + 1)) + '</td>';
      if (locked) {
        h += '<td>' + esc(String(p.weight)) + '</td>';
        h += '<td>' + esc(String(p.dim_x)) + '</td>';
        h += '<td>' + esc(String(p.dim_y)) + '</td>';
        h += '<td>' + esc(String(p.dim_z)) + '</td>';
      } else {
        h +=
          '<td><input type="number" class="octavawms-place-input" aria-label="' +
          esc(cfg.strings.weightG) +
          '" step="any" value="' +
          esc(String(p.weight)) +
          '"' +
          disAttr +
          '/></td>';
        const dxLab = dimAbbrev(cfg.strings.widthMm);
        h +=
          '<td><input type="number" class="octavawms-place-input" aria-label="' +
          dxLab +
          '" step="any" value="' +
          esc(String(p.dim_x)) +
          '"' +
          disAttr +
          '/></td>';
        h +=
          '<td><input type="number" class="octavawms-place-input" aria-label="' +
          dimAbbrev(cfg.strings.heightMm) +
          '" step="any" value="' +
          esc(String(p.dim_y)) +
          '"' +
          disAttr +
          '/></td>';
        h +=
          '<td><input type="number" class="octavawms-place-input" aria-label="' +
          dimAbbrev(cfg.strings.lengthMm) +
          '" step="any" value="' +
          esc(String(p.dim_z)) +
          '"' +
          disAttr +
          '/></td>';
      }
      h += '<td class="octavawms-place-td-actions">' + actionsCell + '</td>';
      h += '</tr>';
    });

    h += '</tbody></table></div>';
    body.innerHTML = h;

    const tbody = body.querySelector('tbody.places-tbody');
    if (tbody instanceof HTMLElement) {
      bindPlacesInputDelegation(tbody, shipmentId);
    }
    syncLabelPrimaryActions();
  }

  function reloadPlaces(shipmentId) {
    loadPlacesSection(shipmentId);
  }

  function inputsFromPlacesRow(tr) {
    const inputs = tr.querySelectorAll('.octavawms-place-input');
    return {
      w: inputs[0],
      dx: inputs[1],
      dy: inputs[2],
      dz: inputs[3],
    };
  }

  function fetchStatus() {
    revokeLabelViewerPdfObjectUrl();
    root.innerHTML =
      '<div class="octavawms-connect-page">' +
      toolbarHtml('') +
      '<div class="octavawms-connect-grid">' +
      '<div class="octavawms-connect-section-body">' +
      '<span class="octavawms-spinner"></span> ' +
      esc(cfg.strings.loading) +
      '</div></div></div>';
    const body = new URLSearchParams();
    body.set('action', 'octavawms_order_status');
    body.set('nonce', cfg.statusNonce);
    body.set('order_id', String(cfg.orderId));
    fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        if (!j || !j.success) {
          renderError((j && j.data && j.data.message) || cfg.strings.error);
          return;
        }
        renderPanel(j.data);
      })
      .catch(function () {
        renderError(cfg.strings.error);
      });
  }

  function setLabelCardLoading(on) {
    const sec = document.getElementById('octavawms-panel-label');
    if (sec) {
      sec.classList.toggle('is-loading', !!on);
    }
    syncLabelPrimaryActions();
  }

  function generateLabel() {
    if (currentPanelLabelState() === 'locked') {
      return;
    }
    var sid = currentShipmentIdFromDom();
    if (!(sid > 0) || !placesTableHasRenderableRows()) {
      window.alert(cfg.strings.generateLabelNeedBoxes || cfg.strings.error);
      return;
    }
    const genBt = root.querySelector('[data-octavawms-action="generate-label"]');
    if (genBt instanceof HTMLButtonElement && genBt.disabled) {
      return;
    }

    setLabelCardLoading(true);
    flushPendingPatchesForShipment(sid, true)
      .then(function () {
        const bodyForm = new URLSearchParams();
        bodyForm.set('action', 'octavawms_generate_label');
        bodyForm.set('nonce', cfg.generateLabelNonce);
        bodyForm.set('order_id', String(cfg.orderId));
        bodyForm.set('shipment_id', String(sid));
        bodyForm.set('from_places', '1');
        return fetch(cfg.ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: bodyForm,
          credentials: 'same-origin',
        });
      })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        setLabelCardLoading(false);
        if (!j || !j.success) {
          renderError((j && j.data && j.data.message) || cfg.strings.error, sid);
          return;
        }
        pendingLabelSuccessToast = true;
        fetchStatus();
      })
      .catch(function () {
        setLabelCardLoading(false);
        renderError(cfg.strings.error, sid);
      });
  }

  function uploadOrder() {
    revokeLabelViewerPdfObjectUrl();
    root.innerHTML =
      '<div class="octavawms-connect-page">' +
      '<div class="octavawms-connect-section-body">' +
      '<span class="octavawms-spinner"></span> ' +
      esc(cfg.strings.uploading) +
      '</div></div>';
    const body = new URLSearchParams();
    body.set('action', 'octavawms_upload_order');
    body.set('nonce', cfg.uploadNonce);
    body.set('order_id', String(cfg.orderId));
    fetch(cfg.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
      credentials: 'same-origin',
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (j) {
        if (!j || !j.success) {
          renderError((j && j.data && j.data.message) || cfg.strings.error);
          return;
        }
        fetchStatus();
      })
      .catch(function () {
        renderError(cfg.strings.error);
      });
  }

  if (box) {
    box.addEventListener('click', function (e) {
      const t = e.target;
      if (!(t instanceof HTMLElement)) {
        return;
      }
      const btn = t.closest('[data-octavawms-action]');
      if (!(btn instanceof HTMLElement) || btn.tagName !== 'BUTTON') {
        return;
      }
      const act = btn.getAttribute('data-octavawms-action');
      if (!act) {
        return;
      }
      if (act === 'retry') {
        e.preventDefault();
        fetchStatus();
        return;
      }
      if (act === 'panel-login') {
        e.preventDefault();
        if (btn.disabled) {
          return;
        }
        btn.disabled = true;
        postPanelLoginUrl()
          .then(function (j) {
            if (j && j.success && j.data && j.data.loginUrl) {
              window.open(String(j.data.loginUrl), '_blank', 'noopener,noreferrer');
              return;
            }
            window.alert((j && j.data && j.data.message) || cfg.strings.panelLoginError || cfg.strings.error);
          })
          .catch(function () {
            window.alert(cfg.strings.panelLoginError || cfg.strings.error);
          })
          .then(function () {
            btn.disabled = false;
          });
        return;
      }
      if (act === 'refresh-status') {
        e.preventDefault();
        fetchStatus();
        return;
      }
      if (act === 'print-label') {
        e.preventDefault();
        var pu = btn.getAttribute('data-label-url');
        if (pu) {
          openLabelPrint(pu);
        }
        return;
      }
      if (act === 'pdf-zoom-out') {
        e.preventDefault();
        renderPdfPageToCanvas('zoom-out');
        return;
      }
      if (act === 'pdf-zoom-in') {
        e.preventDefault();
        renderPdfPageToCanvas('zoom-in');
        return;
      }
      if (act === 'pdf-fit') {
        e.preventDefault();
        renderPdfPageToCanvas('fit');
        return;
      }
      if (act === 'copy-tracking') {
        e.preventDefault();
        var trackNum = btn.getAttribute('data-tracking-number') || '';
        if (trackNum && navigator.clipboard && navigator.clipboard.writeText) {
          var prevCopy = btn.textContent;
          navigator.clipboard
            .writeText(trackNum)
            .then(function () {
              btn.textContent = String(cfg.strings.copiedTracking || 'Copied');
              window.setTimeout(function () {
                btn.textContent = prevCopy;
              }, 1600);
            })
            .catch(function () {});
        }
        return;
      }
      if (act === 'track-shipment') {
        e.preventDefault();
        var trk = btn.getAttribute('data-track-url') || '';
        if (trk) {
          window.open(trk, '_blank', 'noopener,noreferrer');
        }
        return;
      }
      if (act === 'fetch-label-pdf') {
        e.preventDefault();
        var sidFetch = parseInt(btn.getAttribute('data-shipment-id') || '0', 10) || 0;
        if (!(sidFetch > 0)) {
          return;
        }
        btn.disabled = true;
        fetchLabelPdf(sidFetch, false).finally(function () {
          btn.disabled = false;
        });
        return;
      }
      if (act === 'cancel-shipment') {
        e.preventDefault();
        var sidCancel = parseInt(btn.getAttribute('data-shipment-id') || '0', 10) || 0;
        if (!(sidCancel > 0)) {
          return;
        }
        if (!window.confirm(String(cfg.strings.cancelShipment || 'Cancel') + '?')) {
          return;
        }
        btn.disabled = true;
        cancelShipmentLabel(sidCancel).finally(function () {
          btn.disabled = false;
        });
        return;
      }
      if (act === 'retry-pending-error') {
        e.preventDefault();
        const sidRetry = parseInt(btn.getAttribute('data-shipment-id') || '0', 10) || 0;
        if (!(sidRetry > 0) || btn.disabled) {
          return;
        }
        const prevLabel = btn.textContent;
        btn.disabled = true;
        btn.textContent = String(cfg.strings.retryingPendingError || '…');
        const pkRetry = cfg.patchKindRetryPendingError || 'retry_pending_error';
        patchShipmentContext(sidRetry, pkRetry, {})
          .then(function (j) {
            if (!j || !j.success) {
              window.alert((j && j.data && j.data.message) || cfg.strings.error);
              btn.textContent = prevLabel;
              btn.disabled = false;
              return;
            }
            fetchStatus();
          })
          .catch(function () {
            window.alert(cfg.strings.error);
            btn.textContent = prevLabel;
            btn.disabled = false;
          });
        return;
      }
      if (act === 'requeue-ending-queued') {
        e.preventDefault();
        var sidEq = parseInt(btn.getAttribute('data-shipment-id') || '0', 10) || 0;
        if (!(sidEq > 0) || btn.disabled) {
          return;
        }
        var prevEq = btn.textContent;
        btn.disabled = true;
        btn.textContent = String(cfg.strings.requeueingEndingQueued || '…');
        var pkEq = cfg.patchKindRequeueEndingQueued || 'requeue_ending_queued';
        patchShipmentContext(sidEq, pkEq, {})
          .then(function (j) {
            if (!j || !j.success) {
              window.alert((j && j.data && j.data.message) || cfg.strings.error);
              btn.textContent = prevEq;
              btn.disabled = false;
              return;
            }
            fetchStatus();
          })
          .catch(function () {
            window.alert(cfg.strings.error);
            btn.textContent = prevEq;
            btn.disabled = false;
          });
        return;
      }
      if (act === 'upload-order') {
        e.preventDefault();
        uploadOrder();
        return;
      }
      if (act === 'generate-label') {
        e.preventDefault();
        generateLabel();
        return;
      }
      if (act === 'place-add') {
        e.preventDefault();
        if (currentPanelLabelState() === 'locked') {
          return;
        }
        var sid = parseInt(btn.getAttribute('data-shipment-id') || '0', 10) || 0;
        if (!(sid > 0) || btn.disabled) {
          return;
        }
        connectorPost('octavawms_add_place', { shipment_id: String(sid) })
          .then(function (j) {
            if (!j || !j.success) {
              window.alert((j && j.data && j.data.message) || cfg.strings.error);
              return;
            }
            reloadPlaces(sid);
          })
          .catch(function () {
            window.alert(cfg.strings.error);
          });
        return;
      }
      if (act === 'place-remove') {
        e.preventDefault();
        if (btn.disabled) {
          return;
        }
        var sidRm = currentShipmentIdFromDom();
        var victim = parseInt(btn.getAttribute('data-place-id') || '0', 10) || 0;
        if (!(sidRm > 0 && victim > 0)) {
          return;
        }
        connectorPost('octavawms_delete_place', {
          shipment_id: String(sidRm),
          place_id: String(victim),
        })
          .then(function (j) {
            if (!j || !j.success) {
              window.alert((j && j.data && j.data.message) || cfg.strings.error);
              return;
            }
            reloadPlaces(sidRm);
          })
          .catch(function () {
            window.alert(cfg.strings.error);
          });
        return;
      }
    });
  }

  fetchStatus();
})();
