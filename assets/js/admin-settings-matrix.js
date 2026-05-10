/* global jQuery, octavawmsCarrierMatrix */
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

  function post(subaction, extra) {
    var data = $.extend(
      {
        action: cfg().action,
        security: cfg().nonce,
        subaction: subaction
      },
      extra || {}
    );
    return $.post(cfg().ajaxUrl, data, null, 'json');
  }

  function destroySelectWoo($el) {
    if ($.fn.selectWoo && $el && $el.length) {
      try {
        if ($el.hasClass('select2-hidden-accessible')) {
          $el.selectWoo('destroy');
        }
      } catch (e) {
        /* ignore */
      }
    }
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

  function rowHtml(row) {
    row = row || {};
    var ck = row.courierMetaKey != null ? String(row.courierMetaKey) : '';
    var cv = row.courierMetaValue != null ? String(row.courierMetaValue) : '';
    var wd = row.wooDeliveryType != null ? String(row.wooDeliveryType) : '';
    var ty = row.type != null ? String(row.type) : 'address';
    var ds = row.deliveryService != null ? String(row.deliveryService) : '';
    var rt = row.rate != null && row.rate !== '' ? String(row.rate) : '';
    return (
      '<tr class="octavawms-matrix-row">' +
      '<td><input type="text" class="octavawms-ck widefat" list="octavawms-wc-meta-keys" value="' +
      $('<div/>').text(ck).html() +
      '" placeholder="e.g. courierName" /></td>' +
      '<td><input type="text" class="octavawms-cv widefat" value="' +
      $('<div/>').text(cv).html() +
      '" /></td>' +
      '<td><input type="text" class="octavawms-wd widefat" placeholder="" value="' +
      $('<div/>').text(wd).html() +
      '" /></td>' +
      '<td>' +
      buildTypeSelect(ty) +
      '</td>' +
      '<td><select class="octavawms-carrier widefat" style="min-width:220px" data-initial-ds="' +
      $('<div/>').text(ds).html() +
      '" data-initial-label=""></select></td>' +
      '<td><select class="octavawms-rate widefat"><option value="">' +
      (cfg().strings && cfg().strings.anyRate ? cfg().strings.anyRate : '—') +
      '</option></select></td>' +
      '<td><button type="button" class="button octavawms-matrix-del-row">&times;</button></td>' +
      '</tr>'
    );
  }

  function collectRowsFromVisual($tbody) {
    var rows = [];
    $tbody.find('tr.octavawms-matrix-row').each(function () {
      var $tr = $(this);
      var $c = $tr.find('.octavawms-carrier');
      var dsStr = $c.length ? $c.val() : $tr.find('.octavawms-carrier-fallback').val();
      var rateVal = $tr.find('.octavawms-rate').val();
      rows.push({
        courierMetaKey: $.trim($tr.find('.octavawms-ck').val()),
        courierMetaValue: $.trim($tr.find('.octavawms-cv').val()),
        wooDeliveryType: $.trim($tr.find('.octavawms-wd').val()),
        type: $.trim($tr.find('.octavawms-matrix-type').val()) || 'address',
        deliveryService: dsStr ? parseInt(String(dsStr), 10) : 0,
        rate: rateVal === '' || rateVal === null ? null : parseInt(String(rateVal), 10)
      });
    });
    return rows;
  }

  function loadRatesForRow($tr, deliveryServiceId, selectedRateId) {
    var $rate = $tr.find('.octavawms-rate');
    $rate.empty();
    $rate.append(
      $('<option/>', { value: '', text: (cfg().strings && cfg().strings.anyRate) || '—' })
    );
    if (!deliveryServiceId || deliveryServiceId <= 0) {
      return;
    }
    post('rates', { delivery_service_id: String(deliveryServiceId) }).done(function (res) {
      if (!res || !res.success || !res.data || !res.data.items) {
        return;
      }
      res.data.items.forEach(function (it) {
        var o = $('<option/>', { value: String(it.id), text: it.text || String(it.id) });
        if (selectedRateId && String(it.id) === String(selectedRateId)) {
          o.prop('selected', true);
        }
        $rate.append(o);
      });
    });
  }

  function initCarrierSelect($tr, row) {
    var $sel = $tr.find('.octavawms-carrier');
    var initialDs = parseInt($sel.attr('data-initial-ds'), 10) || 0;
    var initialRate = row && row.rate != null ? row.rate : null;
    destroySelectWoo($sel);
    if ($.fn.selectWoo) {
      $sel.selectWoo({
        width: '100%',
        allowClear: true,
        placeholder: (cfg().strings && cfg().strings.pickCarrier) || '…',
        ajax: {
          url: cfg().ajaxUrl,
          type: 'POST',
          dataType: 'json',
          delay: 250,
          data: function (params) {
            params = params || {};
            return {
              action: cfg().action,
              security: cfg().nonce,
              subaction: 'integrations',
              search: params.term || '',
              page: params.page || 1
            };
          },
          processResults: function (data, params) {
            params.page = params.page || 1;
            if (!data || !data.success || !data.data || !data.data.items) {
              return { results: [], pagination: { more: false } };
            }
            var items = data.data.items.map(function (it) {
              return {
                id: String(it.deliveryServiceId),
                text: it.text || String(it.deliveryServiceId)
              };
            });
            var total = data.data.total_pages || 1;
            return {
              results: items,
              pagination: { more: params.page < total }
            };
          }
        }
      });
      if (initialDs > 0) {
        var label =
          'Delivery service #' +
          initialDs +
          (row && row.courierMetaValue ? ' (' + row.courierMetaValue + ')' : '');
        var opt = new Option(label, String(initialDs), true, true);
        $sel.append(opt).trigger('change');
      }
      $sel.on('change', function () {
        var v = parseInt(String($sel.val() || ''), 10) || 0;
        loadRatesForRow($tr, v, null);
      });
      if (initialDs > 0) {
        loadRatesForRow($tr, initialDs, initialRate);
      }
    } else {
      $sel.replaceWith(
        '<input type="number" class="octavawms-carrier-fallback widefat" min="1" step="1" value="' +
          (initialDs > 0 ? initialDs : '') +
          '" placeholder="delivery service id" />'
      );
      $tr.find('.octavawms-carrier-fallback').on('change input', function () {
        var v = parseInt(String($(this).val() || ''), 10) || 0;
        loadRatesForRow($tr, v, null);
      });
      if (initialDs > 0) {
        loadRatesForRow($tr, initialDs, initialRate);
      }
    }
  }

  function renderRows($tbody, rows) {
    $tbody.empty();
    (rows || []).forEach(function (r) {
      var $tr = $(rowHtml(r));
      $tbody.append($tr);
      initCarrierSelect($tr, r);
    });
  }

  function setSpinner(on) {
    var $sp = $('#octavawms-matrix-spinner');
    if (!$sp.length) {
      return;
    }
    $sp.css('visibility', on ? 'visible' : 'hidden');
    if (on) {
      $sp.addClass('is-active');
    } else {
      $sp.removeClass('is-active');
    }
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
      $tbody.find('tr').each(function () {
        destroySelectWoo($(this).find('.octavawms-carrier'));
      });
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
      initCarrierSelect($tr, {});
    });

    $tbody.on('click', '.octavawms-matrix-del-row', function () {
      var $tr = $(this).closest('tr');
      destroySelectWoo($tr.find('.octavawms-carrier'));
      $tr.remove();
    });

    $('#octavawms-matrix-save').on('click', function () {
      var payload;
      if (jsonMode) {
        try {
          payload = JSON.parse($jsonTa.val());
        } catch (e) {
          setMessage((cfg().strings && cfg().strings.invalidJson) || 'Invalid JSON', true);
          return;
        }
        if (!Array.isArray(payload)) {
          setMessage((cfg().strings && cfg().strings.invalidJson) || 'Invalid JSON', true);
          return;
        }
      } else {
        payload = collectRowsFromVisual($tbody);
      }
      setSpinner(true);
      setMessage('', false);
      post('save', { carrier_mapping_json: JSON.stringify(payload) })
        .done(function (res) {
          setSpinner(false);
          if (res && res.success) {
            setMessage((cfg().strings && cfg().strings.saved) || 'Saved', false);
            if (!jsonMode && res.data && res.data.carrierMapping) {
              $tbody.find('tr').each(function () {
                destroySelectWoo($(this).find('.octavawms-carrier'));
              });
              renderRows($tbody, res.data.carrierMapping);
            }
          } else {
            setMessage(
              (res && res.data && res.data.message) ||
                (cfg().strings && cfg().strings.saveFailed) ||
                'Error',
              true
            );
          }
        })
        .fail(function () {
          setSpinner(false);
          setMessage((cfg().strings && cfg().strings.saveFailed) || 'Error', true);
        });
    });

    // Populate WC meta key datalist from order meta in the database.
    post('meta_keys', { search: '' }).done(function (res) {
      if (!res || !res.success || !res.data || !res.data.items) {
        return;
      }
      var $dl = $('#octavawms-wc-meta-keys');
      res.data.items.forEach(function (k) {
        $dl.append($('<option/>', { value: k }));
      });
    });

    setSpinner(true);
    post('get')
      .done(function (res) {
        setSpinner(false);
        if (!res || !res.success) {
          setMessage(
            (res && res.data && res.data.message) ||
              (cfg().strings && cfg().strings.loadFailed) ||
              'Load failed',
            true
          );
          return;
        }
        var rows = (res.data && res.data.carrierMapping) || [];
        renderRows($tbody, rows);
        $jsonTa.val(rowsToJsonPretty(rows));
      })
      .fail(function () {
        setSpinner(false);
        setMessage((cfg().strings && cfg().strings.loadFailed) || 'Load failed', true);
      });
  });
})(jQuery);
