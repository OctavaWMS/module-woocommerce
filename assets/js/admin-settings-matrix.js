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
      '<td><input type="text" class="octavawms-ck widefat" value="' +
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
      '<td><input type="number" class="octavawms-carrier widefat" min="1" step="1" value="' +
      $('<div/>').text(ds).html() +
      '" placeholder="delivery service id" /></td>' +
      '<td><input type="number" class="octavawms-rate widefat" min="1" step="1" value="' +
      $('<div/>').text(rt).html() +
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

  function renderRows($tbody, rows) {
    $tbody.empty();
    (rows || []).forEach(function (r) {
      var $tr = $(rowHtml(r));
      $tbody.append($tr);
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

    function submitSettingsForm() {
      var payload = currentRowsOrNull();
      if (payload === null) {
        return;
      }
      setHiddenRows(payload);
      setMessage((cfg().strings && cfg().strings.saved) || 'Saving settings…', false);

      var $form = $root.closest('form');
      if (!$form.length) {
        setMessage((cfg().strings && cfg().strings.saveFailed) || 'Save failed.', true);
        return;
      }

      var $save = $form.find('button[name="save"], input[name="save"]').first();
      if ($save.length) {
        $save.trigger('click');
        return;
      }

      if (!$form.find('input[name="save"][data-octavawms-matrix]').length) {
        $form.append('<input type="hidden" name="save" value="Save changes" data-octavawms-matrix="1" />');
      }
      $form.trigger('submit');
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

    $('#octavawms-matrix-save').on('click', function () {
      submitSettingsForm();
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
})(jQuery);
