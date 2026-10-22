(function (Drupal, once, $) {
  'use strict';

  // Each uploaded file in a #multiple managed_file widget renders as a
  // checkbox next to a shared "Remove selected" button. Rather than
  // reimplement file removal, this hides the checkbox and adds a delete
  // button right next to the file that checks just that one box and clicks
  // the (hidden) shared button — so Drupal's own AJAX removal logic still
  // does the actual work.
  Drupal.behaviors.ggMembersFileDelete = {
    attach: function (context) {
      once('gg-members-file-delete', '.form-managed-file .js-form-type-checkbox', context).forEach(function (row) {
        var checkbox = row.querySelector('input[type="checkbox"]');
        var widget = row.closest('.js-form-managed-file');
        if (!checkbox || !widget) {
          return;
        }
        var removeButton = widget.querySelector('input[type="submit"][name$="_remove_button"]');
        if (!removeButton) {
          return;
        }

        checkbox.classList.add('gg-members-file-checkbox-hidden');

        var deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.className = 'gg-members-file-delete';
        deleteBtn.textContent = Drupal.t('Διαγραφή');

        deleteBtn.addEventListener('click', function (event) {
          event.preventDefault();
          widget.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
            cb.checked = (cb === checkbox);
          });
          // Drupal's AJAX binds to "mousedown" on this button (see
          // core/misc/ajax.js), not "click" — a plain removeButton.click()
          // fires a synthetic click event only and never triggers the AJAX
          // removal at all. Needs a real jQuery-triggered mousedown.
          $(removeButton).trigger('mousedown');
        });

        row.appendChild(deleteBtn);
      });
    }
  };
})(Drupal, once, jQuery);
