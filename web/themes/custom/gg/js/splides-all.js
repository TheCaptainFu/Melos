(function () {
  window.addEventListener('load', function () {
    document.querySelectorAll('.splide.service').forEach(function (e) {
      var splide = new Splide(e, {
        pauseOnHover: false,
        rewind: true,
        autoplay: false,
        perMove: 1,
        perPage: 4,
        arrows: true,
        pagination: true,
        easing: 'ease',
        drag: true,
        speed: 1000,
        gap: '10px',
        type: 'carousel',
        breakpoints: {
          1420: { perPage: 3 },
          1024: { perPage: 2 },
          767: { perPage: 1 },
        },
      });
      splide.mount();
    });

  });
})();
