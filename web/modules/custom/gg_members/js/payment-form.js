/**
 * @file
 * Keeps the payment form's amount in step with the ticked months: it fills
 * in what's left on all of them, which the editor can lower for a partial
 * payment.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.ggMembersPaymentForm = {
    attach: function (context) {
      once('gg-members-payment-form', 'form.gg-members-payment-form', context).forEach(function (form) {
        var amount = form.querySelector('input[name="amount"]');
        var boxes = form.querySelectorAll('input[data-remaining]');
        if (!amount) {
          return;
        }

        function update() {
          var cents = 0;
          boxes.forEach(function (box) {
            if (box.checked) {
              cents += Math.round(parseFloat(box.getAttribute('data-remaining')) * 100);
            }
          });
          if (cents > 0) {
            amount.value = (cents / 100).toFixed(2);
          }
        }

        boxes.forEach(function (box) {
          box.addEventListener('change', update);
        });
      });
    },
  };
})(Drupal, once);
