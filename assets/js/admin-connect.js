(function ($) {
  'use strict';

  $(function () {
    const btn = $('#octavawms-connect-btn');
    if (!btn.length) {
      return;
    }
    const sp = $('#octavawms-connect-spinner');
    const msg = $('#octavawms-connect-message');
    const badge = $('#octavawms-status-badge');
    const cfg = window.octavawmsConnect;
    if (!cfg) {
      return;
    }

    const data = {
      action: 'octavawms_connect',
      nonce: cfg.nonce,
      security: cfg.nonce,
    };

    btn.on('click', function () {
      sp.css('visibility', 'visible');
      msg.text('');
      btn.prop('disabled', true);

      $.post(cfg.ajaxUrl, data, function (r) {
        if (r && r.success) {
          msg.text((r.data && r.data.message) || '');
          if (r.data && r.data.connected) {
            badge.text(cfg.strings.connected);
            badge.css({ background: '#e7f4e4', color: '#1e4620' });
            const kw = (r.data.api_key) || '';
            if (kw) {
              $('input[name*="api_key"]').val(kw).trigger('change');
            }
          }
        } else {
          msg.text(
            r && r.data && r.data.message
              ? r.data.message
              : cfg.strings.error || 'Error'
          );
          badge.text(cfg.strings.notConnected);
          badge.css({ background: '#f0f0f0', color: '#333' });
        }
      }, 'json')
        .fail(function () {
          msg.text(cfg.strings.error);
        })
        .always(function () {
          sp.css('visibility', 'hidden');
          btn.prop('disabled', false);
        });
    });
  });
})(jQuery);
