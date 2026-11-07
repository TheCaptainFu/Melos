/**
 * @file
 * The month columns on the statistics pages: they hold every month since
 * the first record, twelve in view. Opens on the latest months (or centred
 * on the picked one), and drags sideways with the mouse — a drag doesn't
 * count as clicking the month it ends on. Touch screens scroll natively.
 */
(function (Drupal, once) {
  Drupal.behaviors.ggMembersStatsChart = {
    attach(context) {
      once('gg-members-stats-chart', '.gg-members-monthly__scroller', context).forEach((scroller) => {
        const selected = scroller.querySelector('.is-selected');
        if (selected) {
          const column = selected.parentElement;
          scroller.scrollLeft = column.offsetLeft - (scroller.clientWidth - column.offsetWidth) / 2;
        }
        else {
          scroller.scrollLeft = scroller.scrollWidth;
        }

        const edges = () => {
          scroller.classList.toggle('has-more-left', scroller.scrollLeft > 4);
          scroller.classList.toggle('has-more-right', scroller.scrollLeft + scroller.clientWidth < scroller.scrollWidth - 4);
        };
        edges();
        scroller.addEventListener('scroll', edges, {passive: true});

        let start = null;
        let moved = false;
        scroller.addEventListener('pointerdown', (event) => {
          if (event.pointerType !== 'mouse' || event.button !== 0) {
            return;
          }
          start = {x: event.clientX, left: scroller.scrollLeft};
          moved = false;
        });
        window.addEventListener('pointermove', (event) => {
          if (!start) {
            return;
          }
          const distance = event.clientX - start.x;
          if (!moved && Math.abs(distance) > 5) {
            moved = true;
            scroller.classList.add('is-dragging');
          }
          if (moved) {
            scroller.scrollLeft = start.left - distance;
          }
        });
        window.addEventListener('pointerup', () => {
          start = null;
          scroller.classList.remove('is-dragging');
        });
        scroller.addEventListener('click', (event) => {
          if (moved) {
            event.preventDefault();
            moved = false;
          }
        }, true);

        // Already on the last 12 months: the button just scrolls back to them.
        const section = scroller.closest('.gg-members-monthly');
        const reset = section && section.querySelector('.gg-members-monthly__reset.is-active');
        if (reset) {
          reset.addEventListener('click', (event) => {
            event.preventDefault();
            scroller.scrollTo({left: scroller.scrollWidth, behavior: 'smooth'});
          });
        }
      });
    },
  };
})(Drupal, once);
