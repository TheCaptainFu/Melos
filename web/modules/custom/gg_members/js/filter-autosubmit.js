/**
 * @file
 * Applies the roster filters as soon as they change: a toggle chip
 * ("Μόνο όσοι χρωστάνε" etc.) when clicked, a class/status dropdown when a
 * new option is picked. The search box still waits for Enter.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.ggMembersFilterAutosubmit = {
    attach: function (context) {
      once('gg-members-filter-autosubmit', '.gg-members-filters .gg-members-chip input, .gg-members-filters select', context).forEach(function (input) {
        input.addEventListener('change', function () {
          input.form.submit();
        });
      });
    },
  };
})(Drupal, once);
