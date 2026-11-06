/**
 * @file
 * The door scanner: reads cards from the camera one after another, sends
 * each to the site (which records the attendance), and shows the answer —
 * green, orange, blue or red, with a beep — for a few seconds.
 *
 * Reads with the browser's own BarcodeDetector where there is one (Chrome
 * on Android), else with jsQR (loaded only then). The same card held up
 * for a while is sent once, not every frame.
 */
(function () {
  'use strict';

  var config = window.ggScanner;
  var text = config.text;
  var video = document.querySelector('.camera');
  var startScreen = document.querySelector('[data-start]');
  var errorScreen = document.querySelector('[data-error]');
  var resultBox = document.querySelector('[data-result]');
  var countBox = document.querySelector('[data-count]');

  var csrfToken = null;
  var detector = null;
  var canvas = document.createElement('canvas');
  var context = canvas.getContext('2d', { willReadFrequently: true });
  var busy = false;
  var lastCode = null;
  var lastAt = 0;
  var audio = null;

  // How long an answer stays up, and how soon the same card counts again.
  var SHOW_MS = 2600;
  var SAME_CARD_MS = 6000;

  function showError(message) {
    errorScreen.querySelector('[data-error-text]').textContent = message;
    errorScreen.hidden = false;
    startScreen.hidden = true;
  }

  function loadJsQr() {
    return new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = config.jsqrUrl;
      script.onload = resolve;
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  function chooseReader() {
    if ('BarcodeDetector' in window) {
      return window.BarcodeDetector.getSupportedFormats().then(function (formats) {
        if (formats.indexOf('qr_code') !== -1) {
          detector = new window.BarcodeDetector({ formats: ['qr_code'] });
          return;
        }
        return loadJsQr();
      }).catch(loadJsQr);
    }
    return loadJsQr();
  }

  function read() {
    if (!video.videoWidth) {
      return Promise.resolve(null);
    }
    if (detector) {
      return detector.detect(video).then(function (codes) {
        return codes.length ? codes[0].rawValue : null;
      });
    }
    // jsQR: a downscaled frame is plenty for a card held up close.
    var scale = Math.min(1, 640 / video.videoWidth);
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    var frame = context.getImageData(0, 0, canvas.width, canvas.height);
    var found = window.jsQR(frame.data, frame.width, frame.height, { inversionAttempts: 'dontInvert' });
    return Promise.resolve(found ? found.data : null);
  }

  function beep(ok) {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      var oscillator = audio.createOscillator();
      var gain = audio.createGain();
      oscillator.frequency.value = ok ? 880 : 220;
      gain.gain.value = 0.15;
      oscillator.connect(gain);
      gain.connect(audio.destination);
      oscillator.start();
      oscillator.stop(audio.currentTime + (ok ? 0.15 : 0.45));
    }
    catch (e) {
      // No sound is fine.
    }
  }

  function element(tag, className, content) {
    var node = document.createElement(tag);
    node.className = className;
    if (content) {
      node.textContent = content;
    }
    return node;
  }

  // The same markup as templates/gg-members-attendance-result.html.twig.
  function show(result) {
    var box = element('div', 'result result--' + result.state);
    if (result.photo) {
      var photo = element('img', 'result__photo');
      photo.src = result.photo;
      photo.alt = '';
      box.appendChild(photo);
    }
    else if (result.name) {
      box.appendChild(element('span', 'result__photo result__photo--initial', result.name.charAt(0).toUpperCase()));
    }
    if (result.state === 'unknown') {
      box.appendChild(element('p', 'result__title', text.unknown));
      box.appendChild(element('p', 'result__note', text.seeCoach));
    }
    else {
      box.appendChild(element('p', 'result__name', result.name));
      if (result.class_name) {
        box.appendChild(element('p', 'result__class', result.class_name));
      }
      var titles = { ok: text.welcome, warn: text.welcome, again: text.again, inactive: text.inactive };
      var notes = { ok: result.time, warn: text.seeCoach, again: result.time, inactive: text.seeCoach };
      box.appendChild(element('p', 'result__title', titles[result.state]));
      box.appendChild(element('p', 'result__note', notes[result.state]));
    }
    resultBox.replaceChildren(box);
    resultBox.hidden = false;
    if (typeof result.today === 'number') {
      countBox.textContent = text.count.replace('@count', result.today);
    }
    beep(result.state === 'ok' || result.state === 'again');
    if (navigator.vibrate) {
      navigator.vibrate(result.state === 'ok' ? 80 : [80, 60, 80]);
    }
    setTimeout(function () {
      resultBox.hidden = true;
      busy = false;
    }, SHOW_MS);
  }

  function send(code) {
    busy = true;
    fetch(config.recordUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
      body: JSON.stringify({ code: code })
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error(response.status);
        }
        return response.json();
      })
      .then(show)
      .catch(function () {
        // Let the same card be tried again straight away.
        lastCode = null;
        show({ state: 'unknown', name: null });
        resultBox.querySelector('.result__title').textContent = text.offline;
        resultBox.querySelector('.result__note').textContent = '';
      });
  }

  function loop() {
    if (busy) {
      requestAnimationFrame(loop);
      return;
    }
    read().then(function (code) {
      var now = Date.now();
      if (code && !(code === lastCode && now - lastAt < SAME_CARD_MS)) {
        lastCode = code;
        lastAt = now;
        send(code);
      }
      else if (code) {
        lastAt = now;
      }
    }).catch(function () {}).then(function () {
      setTimeout(function () { requestAnimationFrame(loop); }, detector ? 120 : 200);
    });
  }

  // Keeps the door device's screen on while the scanner is open.
  function keepAwake() {
    if (navigator.wakeLock) {
      navigator.wakeLock.request('screen').catch(function () {});
    }
  }

  function start() {
    if (!window.isSecureContext || !navigator.mediaDevices) {
      showError(text.insecure);
      return;
    }
    startScreen.hidden = true;
    beep(true);
    Promise.all([
      fetch(config.tokenUrl, { credentials: 'same-origin' }).then(function (r) { return r.text(); }),
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 } }, audio: false }),
      chooseReader()
    ]).then(function (values) {
      csrfToken = values[0];
      video.srcObject = values[1];
      return video.play();
    }).then(function () {
      keepAwake();
      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
          keepAwake();
        }
      });
      loop();
    }).catch(function () {
      showError(text.noCamera);
    });
  }

  startScreen.querySelector('button').addEventListener('click', start);
  // For tests: what a scanned card would do, without a camera.
  window.ggScanner.handle = function (code) {
    if (!csrfToken) {
      return fetch(config.tokenUrl, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (t) { csrfToken = t; send(code); });
    }
    send(code);
  };
})();
