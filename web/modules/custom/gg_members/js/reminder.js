/**
 * @file
 * The reminder dialog's buttons: each logs the reminder (so the roster can
 * show when it was sent), then opens Viber/WhatsApp/SMS with the message
 * as it is in the text box right now, or copies it.
 */
(function (Drupal, once) {
  'use strict';

  function links(phone, text) {
    var message = encodeURIComponent(text);
    return {
      viber: 'viber://chat?number=' + encodeURIComponent(phone) + '&draft=' + message,
      whatsapp: 'https://wa.me/' + phone.replace(/^\+/, '') + '?text=' + message,
      // "?&body=" is understood by both iOS and Android.
      sms: 'sms:' + phone + '?&body=' + message,
    };
  }

  function copy(text, textarea) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }
    // Plain-http fallback (e.g. a local site): select and copy.
    textarea.focus();
    textarea.select();
    document.execCommand('copy');
    return Promise.resolve();
  }

  Drupal.behaviors.ggMembersReminder = {
    attach: function (context) {
      once('gg-members-reminder', '.gg-members-reminder', context).forEach(function (root) {
        var phone = root.getAttribute('data-phone') || '';
        var textarea = root.querySelector('.gg-members-reminder__message');
        var status = root.querySelector('.gg-members-reminder__status');

        root.querySelectorAll('[data-channel]').forEach(function (button) {
          button.addEventListener('click', function () {
            var channel = button.getAttribute('data-channel');

            // keepalive: the log request must survive the page handing over
            // to the Viber/SMS app straight after.
            fetch(button.getAttribute('data-log'), {method: 'POST', credentials: 'same-origin', keepalive: true})
              .then(function (response) { return response.ok ? response.json() : null; })
              .then(function (result) {
                if (result && status) {
                  status.textContent = Drupal.t('Καταγράφηκε: @channel, σήμερα @date.', {
                    '@channel': button.textContent.trim(),
                    '@date': result.date,
                  });
                }
              })
              .catch(function () {});

            if (channel === 'copy') {
              copy(textarea.value, textarea).then(function () {
                var label = button.querySelector('[data-copy-label]');
                label.textContent = Drupal.t('Αντιγράφηκε ✓');
                setTimeout(function () { label.textContent = Drupal.t('Αντιγραφή'); }, 2000);
              });
              return;
            }

            var href = links(phone, textarea.value)[channel];
            if (channel === 'whatsapp') {
              window.open(href, '_blank', 'noopener');
            }
            else {
              window.location.href = href;
            }
          });
        });
      });
    },
  };
})(Drupal, once);
