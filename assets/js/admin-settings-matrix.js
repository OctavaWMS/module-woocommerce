/* global jQuery, octavawmsCarrierMatrix, octavawmsCodRules */
(function ($) {
  'use strict';

  var TYPE_OPTIONS = [
    { v: 'address', t: 'address' },
    { v: 'office', t: 'office' },
    { v: 'locker', t: 'locker' },
    { v: 'office_locker', t: 'office_locker' }
  ];

  function cfg() {
    return window.octavawmsCarrierMatrix || {};
  }

  function codCfg() {
    return window.octavawmsCodRules || {};
  }

  function escapeHtml(value) {
    return $('<div/>').text(value == null ? '' : String(value)).html();
  }

  function buildTypeSelect(selected) {
    var h = '<select class="octavawms-matrix-type widefat">';
    TYPE_OPTIONS.forEach(function (o) {
      h +=
        '<option value="' +
        o.v +
        '"' +
        (o.v === selected ? ' selected' : '') +
        '>' +
        o.t +
        '</option>';
    });
    h += '</select>';
    return h;
  }

  function lockerMarkersText(row) {
    if (!row || row.lockerMarkers == null) {
      return '';
    }
    if (Array.isArray(row.lockerMarkers)) {
      return row.lockerMarkers.join(', ');
    }
    return String(row.lockerMarkers);
  }

  function parseLockerMarkers(value) {
    return String(value || '')
      .split(',')
      .map(function (marker) {
        return $.trim(marker);
      })
      .filter(function (marker, index, markers) {
        return marker !== '' && markers.indexOf(marker) === index;
      });
  }

  function rowHtml(row) {
    row = row || {};
    var ck = row.courierMetaKey != null ? String(row.courierMetaKey) : '';
    var cv = row.courierMetaValue != null ? String(row.courierMetaValue) : '';
    var wd = row.wooDeliveryType != null ? String(row.wooDeliveryType) : '';
    var ty = row.type != null ? String(row.type) : 'address';
    var lm = lockerMarkersText(row);
    var ds = row.deliveryService != null ? String(row.deliveryService) : '';
    var rt = row.rate != null && row.rate !== '' ? String(row.rate) : '';
    return (
      '<tr class="octavawms-matrix-row">' +
      '<td><input type="text" class="octavawms-ck widefat" value="' +
      escapeHtml(ck) +
      '" placeholder="e.g. courierName" /></td>' +
      '<td><input type="text" class="octavawms-cv widefat" value="' +
      escapeHtml(cv) +
      '" /></td>' +
      '<td><input type="text" class="octavawms-wd widefat" placeholder="" value="' +
      escapeHtml(wd) +
      '" /></td>' +
      '<td>' +
      buildTypeSelect(ty) +
      '</td>' +
      '<td><input type="text" class="octavawms-locker-markers widefat" value="' +
      escapeHtml(lm) +
      '" placeholder="e.g. АВТОМАТ" /></td>' +
      '<td><input type="number" class="octavawms-carrier widefat" min="1" step="1" value="' +
      escapeHtml(ds) +
      '" placeholder="delivery service id" /></td>' +
      '<td><input type="number" class="octavawms-rate widefat" min="1" step="1" value="' +
      escapeHtml(rt) +
      '" placeholder="optional rate id" /></td>' +
      '<td><button type="button" class="button octavawms-matrix-del-row">&times;</button></td>' +
      '</tr>'
    );
  }

  function collectRowsFromVisual($tbody) {
    var rows = [];
    $tbody.find('tr.octavawms-matrix-row').each(function () {
      var $tr = $(this);
      var dsStr = $tr.find('.octavawms-carrier').val();
      var rateVal = $tr.find('.octavawms-rate').val();
      var lockerMarkers = parseLockerMarkers($tr.find('.octavawms-locker-markers').val());
      var row = {
        courierMetaKey: $.trim($tr.find('.octavawms-ck').val()),
        courierMetaValue: $.trim($tr.find('.octavawms-cv').val()),
        wooDeliveryType: $.trim($tr.find('.octavawms-wd').val()),
        type: $.trim($tr.find('.octavawms-matrix-type').val()) || 'address',
        deliveryService: dsStr ? parseInt(String(dsStr), 10) : 0,
        rate: rateVal === '' || rateVal === null ? null : parseInt(String(rateVal), 10)
      };
      if (lockerMarkers.length) {
        row.lockerMarkers = lockerMarkers;
      }
      rows.push(row);
    });
    return rows;
  }

  function renderRows($tbody, rows) {
    $tbody.empty();
    (rows || []).forEach(function (r) {
      var $tr = $(rowHtml(r));
      $tbody.append($tr);
    });
  }

  function setMessage(text, isError) {
    var $m = $('#octavawms-matrix-message');
    if (!$m.length) {
      return;
    }
    $m.text(text || '');
    $m.css('color', isError ? '#b32d2d' : '#1e4620');
  }

  $(function () {
    var $root = $('#octavawms-carrier-matrix-root');
    if (!$root.length) {
      return;
    }

    var jsonMode = false;
    var $tbody = $('#octavawms-matrix-tbody');
    var $visual = $('#octavawms-matrix-visual-wrap');
    var $jsonWrap = $('#octavawms-matrix-json-wrap');
    var $jsonTa = $('#octavawms-matrix-json');
    var $toggle = $('#octavawms-matrix-toggle-mode');

    function rowsToJsonPretty(rows) {
      return JSON.stringify(rows, null, 2);
    }

    function syncJsonFromVisual() {
      var rows = collectRowsFromVisual($tbody);
      $jsonTa.val(rowsToJsonPretty(rows));
    }

    function setHiddenRows(rows) {
      $('#octavawms-carrier-mapping-json').val(JSON.stringify(rows || []));
    }

    function currentRowsOrNull() {
      var payload;
      if (jsonMode) {
        try {
          payload = JSON.parse($jsonTa.val());
        } catch (e) {
          setMessage((cfg().strings && cfg().strings.invalidJson) || 'Invalid JSON', true);
          return null;
        }
        if (!Array.isArray(payload)) {
          setMessage((cfg().strings && cfg().strings.invalidJson) || 'Invalid JSON', true);
          return null;
        }
        return payload;
      }

      return collectRowsFromVisual($tbody);
    }

    function switchToJson() {
      syncJsonFromVisual();
      $visual.hide();
      $jsonWrap.show();
      jsonMode = true;
      $toggle.text((cfg().strings && cfg().strings.switchVisual) || 'Visual');
    }

    function switchToVisual() {
      var raw = $jsonTa.val();
      var parsed;
      try {
        parsed = JSON.parse(raw);
      } catch (e) {
        setMessage((cfg().strings && cfg().strings.invalidJson) || 'Invalid JSON', true);
        return;
      }
      if (!Array.isArray(parsed)) {
        setMessage((cfg().strings && cfg().strings.invalidJson) || 'Invalid JSON', true);
        return;
      }
      setMessage('', false);
      renderRows($tbody, parsed);
      $jsonWrap.hide();
      $visual.show();
      jsonMode = false;
      $toggle.text((cfg().strings && cfg().strings.switchJson) || 'JSON');
    }

    $toggle.on('click', function () {
      if (jsonMode) {
        switchToVisual();
      } else {
        switchToJson();
      }
    });

    $('#octavawms-matrix-add-row').on('click', function () {
      if (jsonMode) {
        setMessage('Switch to Visual to add rows.', true);
        return;
      }
      var $tr = $(rowHtml({}));
      $tbody.append($tr);
    });

    $tbody.on('click', '.octavawms-matrix-del-row', function () {
      var $tr = $(this).closest('tr');
      $tr.remove();
    });

    $root.closest('form').on('submit', function (e) {
      var payload = currentRowsOrNull();
      if (payload === null) {
        e.preventDefault();
        return false;
      }
      setHiddenRows(payload);
      return true;
    });

    var initialRows = Array.isArray(cfg().initialRows) ? cfg().initialRows : [];
    renderRows($tbody, initialRows);
    $jsonTa.val(rowsToJsonPretty(initialRows));
    setHiddenRows(initialRows);
  });

  var COD_TYPE_OPTIONS = [
    { v: 'any', t: 'Any' },
    { v: 'simple', t: 'Address' },
    { v: 'service_point', t: 'Office' },
    { v: 'self_service_point', t: 'Locker' },
    { v: 'office_and_locker', t: 'Office or locker' }
  ];

  function codString(key, fallback) {
    return (codCfg().strings && codCfg().strings[key]) || fallback;
  }

  function codTypeOptions() {
    return [
      { v: 'any', t: codString('anyType', 'Any') },
      { v: 'simple', t: codString('address', 'Address') },
      { v: 'service_point', t: codString('office', 'Office') },
      { v: 'self_service_point', t: codString('locker', 'Locker') },
      { v: 'office_and_locker', t: codString('officeLocker', 'Office or locker') }
    ];
  }

  function normalizeCodDeliveryType(value) {
    var type = String(value || 'any').trim().toLowerCase();
    var map = {
      '': 'any',
      address: 'simple',
      office: 'service_point',
      locker: 'self_service_point',
      office_locker: 'office_and_locker'
    };
    type = map[type] || type;
    return COD_TYPE_OPTIONS.some(function (o) { return o.v === type; }) ? type : 'any';
  }

  function buildCodTypeSelect(selected) {
    var h = '<select class="octavawms-cod-delivery-type widefat">';
    codTypeOptions().forEach(function (o) {
      h +=
        '<option value="' +
        o.v +
        '"' +
        (o.v === selected ? ' selected' : '') +
        '>' +
        escapeHtml(o.t) +
        '</option>';
    });
    h += '</select>';
    return h;
  }

  function codRuleValue(rule, flatKey, matchKey) {
    if (rule && rule[flatKey] != null) {
      return rule[flatKey];
    }
    if (rule && rule[matchKey] != null) {
      return rule[matchKey];
    }
    if (rule && rule.match && rule.match[matchKey] != null) {
      return rule.match[matchKey];
    }
    return '';
  }

  function codRowHtml(rule) {
    rule = rule || {};
    var enabled = rule.enabled !== false && String(rule.enabled || 'true') !== 'false';
    var mode = String(rule.mode || rule.action || 'exclude').toLowerCase();
    if (mode === 'hide') mode = 'exclude';
    if (mode === 'allow') mode = 'include';
    if (mode !== 'include') mode = 'exclude';
    var deliveryService = codRuleValue(rule, 'deliveryService', 'delivery_service_id');
    var rate = codRuleValue(rule, 'rate', 'rate_id');
    var type = normalizeCodDeliveryType(codRuleValue(rule, 'deliveryType', 'delivery_type') || rule.methodKind || 'any');
    return (
      '<tr class="octavawms-cod-rule-row">' +
      '<td><label><input type="checkbox" class="octavawms-cod-enabled"' +
      (enabled ? ' checked' : '') +
      '> Enabled</label></td>' +
      '<td><select class="octavawms-cod-mode widefat">' +
      '<option value="exclude"' +
      (mode === 'exclude' ? ' selected' : '') +
      '>' +
      escapeHtml(codString('hideCod', 'Hide COD')) +
      '</option>' +
      '<option value="include"' +
      (mode === 'include' ? ' selected' : '') +
      '>' +
      escapeHtml(codString('allowCod', 'Allow COD')) +
      '</option>' +
      '</select></td>' +
      '<td><input type="number" class="octavawms-cod-delivery-service widefat" min="1" step="1" value="' +
      escapeHtml(deliveryService) +
      '" placeholder="delivery service id" /></td>' +
      '<td>' +
      buildCodTypeSelect(type) +
      '</td>' +
      '<td><input type="number" class="octavawms-cod-rate widefat" min="1" step="1" value="' +
      escapeHtml(rate) +
      '" placeholder="optional rate id" /></td>' +
      '<td><button type="button" class="button octavawms-cod-del-row">&times;</button></td>' +
      '</tr>'
    );
  }

  function inferCodScope(deliveryType, rateId) {
    if (rateId) {
      return 'rate';
    }
    if (deliveryType && deliveryType !== 'any') {
      return 'delivery_type';
    }
    return 'carrier';
  }

  function collectCodRows($tbody) {
    var rows = [];
    $tbody.find('tr.octavawms-cod-rule-row').each(function (idx) {
      var $tr = $(this);
      var deliveryService = $.trim($tr.find('.octavawms-cod-delivery-service').val());
      var rate = $.trim($tr.find('.octavawms-cod-rate').val());
      var deliveryType = normalizeCodDeliveryType($tr.find('.octavawms-cod-delivery-type').val());
      if (!deliveryService && !rate && deliveryType === 'any') {
        return;
      }
      var match = {
        scope: inferCodScope(deliveryType, rate),
        delivery_type: deliveryType
      };
      if (deliveryService) {
        match.delivery_service_id = String(parseInt(deliveryService, 10));
      }
      if (rate) {
        match.rate_id = String(parseInt(rate, 10));
      }
      rows.push({
        id: 'woo-cod-rule-' + (idx + 1),
        enabled: $tr.find('.octavawms-cod-enabled').is(':checked'),
        carrier_key: deliveryService ? 'delivery_service_' + parseInt(deliveryService, 10) : 'all',
        carrier_label: '',
        payment_handle: 'cod',
        mode: $.trim($tr.find('.octavawms-cod-mode').val()) || 'exclude',
        match: match
      });
    });
    return rows;
  }

  function renderCodRows($tbody, rows) {
    $tbody.empty();
    (rows || []).forEach(function (row) {
      $tbody.append($(codRowHtml(row)));
    });
  }

  function setCodMessage(text, isError) {
    var $m = $('#octavawms-cod-rules-message');
    if (!$m.length) {
      return;
    }
    $m.text(text || '');
    $m.css('color', isError ? '#b32d2d' : '#1e4620');
  }

  $(function () {
    var $root = $('#octavawms-cod-rules-root');
    if (!$root.length) {
      return;
    }

    var $tbody = $('#octavawms-cod-rules-tbody');
    var $hidden = $('#octavawms-cod-rules-json');
    var initialRows = Array.isArray(codCfg().initialRows) ? codCfg().initialRows : [];
    renderCodRows($tbody, initialRows);

    $('#octavawms-cod-rules-add-row').on('click', function () {
      $tbody.append($(codRowHtml({})));
    });

    $tbody.on('click', '.octavawms-cod-del-row', function () {
      $(this).closest('tr').remove();
    });

    $root.closest('form').on('submit', function (e) {
      try {
        $hidden.val(JSON.stringify(collectCodRows($tbody)));
        setCodMessage('', false);
      } catch (err) {
        setCodMessage(codString('invalidRules', 'Invalid cash on delivery rules.'), true);
        e.preventDefault();
        return false;
      }
      return true;
    });
  });
})(jQuery);
