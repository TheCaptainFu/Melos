/**
 * @file
 * Returns the editor to the same scroll position on the roster after they
 * come back from a member (profile, edit, payments, delete).
 *
 * The page/filters are already preserved via ?back=; this adds the height.
 * Stored per exact roster URL in sessionStorage (this tab only), and only
 * restored when arriving from a member page, so opening the roster fresh
 * from the menu still starts at the top.
 */
(function (Drupal, once) {
  'use strict';

  var PREFIX = 'ggMembersScroll:';

  function key() {
    return PREFIX + window.location.pathname + window.location.search;
  }

  Drupal.behaviors.ggMembersRememberScroll = {
    attach: function () {
      once('gg-members-scroll', 'html').forEach(function () {
        var cameFromMember = false;
        try {
          cameFromMember = /\/admin\/members\/\d+/.test(new URL(document.referrer).pathname);
        }
        catch (e) {}

        try {
          var saved = sessionStorage.getItem(key());
          sessionStorage.removeItem(key());
          if (cameFromMember && saved !== null) {
            window.requestAnimationFrame(function () {
              window.scrollTo(0, parseInt(saved, 10) || 0);
            });
          }
        }
        catch (e) {}

        window.addEventListener('pagehide', function () {
          try {
            sessionStorage.setItem(key(), String(Math.round(window.scrollY)));
          }
          catch (e) {}
        });
      });
    },
  };
})(Drupal, once);
