(function (Drupal) {
  'use strict';

  Drupal.behaviors.photoswipeAll = {
    attach: function (context, settings) {
      context.querySelectorAll('.masonry-gallery').forEach((gallery) => {
        const lightbox = new PhotoSwipeLightbox({
          gallery,
          children: 'a.gallery-grid__item',
          pswpModule: PhotoSwipe,
          paddingFn: () => ({ top: 40, bottom: 120, left: 0, right: 0 }),
        });

        lightbox.addFilter('itemData', (itemData) => {
          const el = itemData.element;
          if (el) {
            const href = el.dataset.href;
            itemData.src = href.startsWith('http') ? href : window.location.origin + href;
            itemData.width = parseInt(el.dataset.pswpWidth) || 0;
            itemData.height = parseInt(el.dataset.pswpHeight) || 0;
            itemData.thumbCropped = true;
          }
          return itemData;
        });

        lightbox.on('uiRegister', () => {
          lightbox.pswp.ui.registerElement({
            name: 'thumbnails-strip',
            order: 9,
            isButton: false,
            appendTo: 'root',
            onInit: (el, pswp) => {
              const items = gallery.querySelectorAll('a.gallery-grid__item');
              el.classList.add('pswp-thumbs');

              const padL = document.createElement('div');
              padL.style.cssText = 'min-width: calc(50% - 44px); flex-shrink: 0;';
              el.appendChild(padL);

              items.forEach((a, i) => {
                const img = a.querySelector('img');
                if (!img) return;
                const thumb = document.createElement('button');
                thumb.classList.add('pswp-thumb');
                thumb.dataset.index = i;
                thumb.innerHTML = `<img src="${img.currentSrc || img.src}" alt="">`;
                thumb.addEventListener('click', () => pswp.goTo(i));
                el.appendChild(thumb);
              });

              const padR = document.createElement('div');
              padR.style.cssText = 'min-width: calc(50% - 44px); flex-shrink: 0;';
              el.appendChild(padR);

              const syncActive = () => {
                const thumbs = el.querySelectorAll('.pswp-thumb');
                thumbs.forEach((t, i) => {
                  t.classList.toggle('pswp-thumb--active', i === pswp.currIndex);
                });
                const active = thumbs[pswp.currIndex];
                if (active) {
                  const stripCenter = el.offsetWidth / 2;
                  const thumbCenter = active.offsetLeft + active.offsetWidth / 2;
                  el.scrollTo({ left: thumbCenter - stripCenter, behavior: 'smooth' });
                }
              };

              pswp.on('change', syncActive);
              pswp.on('firstUpdate', () => setTimeout(syncActive, 50));
            }
          });
        });

        lightbox.init();
      });
    }
  };

})(Drupal);
