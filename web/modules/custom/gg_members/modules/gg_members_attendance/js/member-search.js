/**
 * @file
 * "Ήρθε χωρίς κάρτα;": the member list as a search box. Type part of a
 * name (with or without accents, in any case) and pick from the matches;
 * the real <select> behind it keeps the chosen member for the form.
 */
(function () {
  'use strict';

  // "Άγγελος" and "αγγελος" match alike; ς counts as σ.
  function fold(text) {
    return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/ς/g, 'σ');
  }

  function enhance(select) {
    if (select.dataset.ggSearch) {
      return;
    }
    select.dataset.ggSearch = '1';
    var options = Array.prototype.filter.call(select.options, function (o) { return o.value; }).map(function (o) {
      var parts = o.text.split(' — ');
      return { value: o.value, name: parts[0], extra: parts[1] || '', key: fold(o.text) };
    });

    var box = document.createElement('div');
    box.className = 'gg-member-search';
    var input = document.createElement('input');
    input.type = 'search';
    input.className = 'form-element gg-member-search__input';
    input.placeholder = select.getAttribute('data-placeholder') || 'Γράψτε όνομα…';
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-autocomplete', 'list');
    if (select.id) {
      input.id = select.id + '-search';
      var label = document.querySelector('label[for="' + select.id + '"]');
      if (label) {
        label.setAttribute('for', input.id);
      }
    }
    var list = document.createElement('ul');
    list.className = 'gg-member-search__list';
    list.setAttribute('role', 'listbox');
    list.id = (select.id || 'gg-member') + '-results';
    input.setAttribute('aria-controls', list.id);
    list.hidden = true;
    box.appendChild(input);
    box.appendChild(list);
    select.parentNode.insertBefore(box, select);
    select.hidden = true;
    select.tabIndex = -1;

    var matches = [];
    var active = -1;

    function choose(option) {
      select.value = option.value;
      input.value = option.name + (option.extra ? ' — ' + option.extra : '');
      close();
      var submit = select.form && select.form.querySelector('.gg-attendance-day__add [type=submit]');
      if (submit) {
        submit.focus();
      }
    }

    function close() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      active = -1;
    }

    function highlight(index) {
      active = index;
      Array.prototype.forEach.call(list.children, function (li, i) {
        li.classList.toggle('is-active', i === index);
        li.setAttribute('aria-selected', i === index ? 'true' : 'false');
        if (i === index) {
          li.scrollIntoView({ block: 'nearest' });
        }
      });
    }

    function render() {
      var words = fold(input.value.trim()).split(/\s+/).filter(Boolean);
      select.value = '';
      matches = words.length ? options.filter(function (o) {
        return words.every(function (w) { return o.key.indexOf(w) !== -1; });
      }).slice(0, 12) : [];
      list.innerHTML = '';
      if (!words.length) {
        close();
        return;
      }
      if (!matches.length) {
        var none = document.createElement('li');
        none.className = 'gg-member-search__none';
        none.textContent = 'Κανένα μέλος με αυτό το όνομα';
        list.appendChild(none);
      }
      matches.forEach(function (o, i) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.className = 'gg-member-search__item';
        var name = document.createElement('span');
        name.className = 'gg-member-search__name';
        name.textContent = o.name;
        li.appendChild(name);
        if (o.extra) {
          var extra = document.createElement('span');
          extra.className = 'gg-member-search__extra';
          extra.textContent = o.extra;
          li.appendChild(extra);
        }
        li.addEventListener('mousedown', function (e) {
          e.preventDefault();
          choose(o);
        });
        li.addEventListener('mousemove', function () {
          if (active !== i) {
            highlight(i);
          }
        });
        list.appendChild(li);
      });
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      highlight(matches.length ? 0 : -1);
    }

    input.addEventListener('input', render);
    input.addEventListener('focus', function () {
      if (input.value && !select.value) {
        render();
      }
    });
    input.addEventListener('blur', function () {
      setTimeout(close, 120);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' && matches.length) {
        e.preventDefault();
        highlight((active + 1) % matches.length);
      }
      else if (e.key === 'ArrowUp' && matches.length) {
        e.preventDefault();
        highlight((active - 1 + matches.length) % matches.length);
      }
      else if (e.key === 'Enter') {
        // Enter picks the highlighted member; a second Enter adds them.
        if (!list.hidden && matches[active]) {
          e.preventDefault();
          choose(matches[active]);
        }
        else if (!select.value) {
          e.preventDefault();
        }
      }
      else if (e.key === 'Escape') {
        close();
      }
    });
  }

  function init() {
    document.querySelectorAll('select[data-gg-member-search]').forEach(enhance);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  }
  else {
    init();
  }
})();
