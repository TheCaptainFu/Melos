/**
 * @file
 * Settings page, reminder message: the "+ Όνομα μέλους" etc. buttons insert
 * their placeholder where the cursor is, the preview shows the message as a
 * parent would get it (with a made-up member) while it's being typed, and
 * "Επαναφορά" puts the built-in text back.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.ggMembersSettingsForm = {
    attach: function (context) {
      once('gg-members-settings', '.gg-members-settings', context).forEach(function (form) {
        var textarea = form.querySelector('.gg-members-template');
        var preview = form.querySelector('.gg-members-preview');
        var bubble = form.querySelector('.gg-members-preview__bubble');
        var club = form.querySelector('.gg-members-club-name');
        if (!textarea || !preview || !bubble) {
          return;
        }

        function render() {
          var values = {
            '[όνομα]': preview.getAttribute('data-name'),
            '[μήνες]': preview.getAttribute('data-months'),
            '[ποσό]': preview.getAttribute('data-amount'),
            '[σύλλογος]': (club && club.value.trim()) || preview.getAttribute('data-site-name'),
          };
          var text = textarea.value;
          Object.keys(values).forEach(function (token) {
            text = text.split(token).join(values[token]);
          });
          bubble.textContent = text;
        }

        form.querySelectorAll('.gg-members-token').forEach(function (button) {
          button.addEventListener('click', function () {
            var token = button.getAttribute('data-token');
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            textarea.value = textarea.value.slice(0, start) + token + textarea.value.slice(end);
            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = start + token.length;
            render();
          });
        });

        var reset = form.querySelector('.gg-members-template-reset');
        if (reset) {
          reset.addEventListener('click', function () {
            textarea.value = textarea.getAttribute('data-default');
            render();
          });
        }

        textarea.addEventListener('input', render);
        if (club) {
          club.addEventListener('input', render);
        }
        render();
      });
    },
  };
})(Drupal, once);
