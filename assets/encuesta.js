/* Encuestas: condiciones (mostrar una pregunta según otra respuesta), encuesta por pasos con barra de progreso,
   deslizador y «ordenar por preferencia». Sin JavaScript el formulario funciona igual, todo en una página.
   Mismo archivo en Bezares Mailing (assets/encuesta.js) y en Lumen (public/assets/encuesta.js). */
(function () {
  'use strict';
  var form = document.querySelector('form.survey');
  if (!form) return;
  if (form.hasAttribute('data-sf-preview')) form.addEventListener('submit', function (e) { e.preventDefault(); }); // vista previa del editor
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---------- Valor actual de una pregunta (para las condiciones) ---------- */
  function valueOf(qid) {
    var fs = form.querySelector('fieldset[data-qid="' + qid + '"]');
    if (!fs || fs.hidden) return '';
    var t = fs.getAttribute('data-type'), out = [];
    $$('input, select, textarea', fs).forEach(function (el) {
      if (!el.name || el.disabled) return;
      if ((el.type === 'radio' || el.type === 'checkbox') && !el.checked) return;
      if (el.value !== '') out.push(el.value);
    });
    return t === 'rank' ? out.join('|').split('|').join(' > ') : out.join(' | ');
  }
  function test(c) {
    var v = valueOf(c.q);
    if (v === '') return false;
    var want = String(c.v || '').trim();
    if (c.op === 'le' || c.op === 'ge') {
      var a = parseFloat(v), b = parseFloat(want.replace(',', '.'));
      if (isNaN(a) || isNaN(b)) return false;
      return c.op === 'le' ? a <= b : a >= b;
    }
    var parts = v.split(/ \| | > /), hit = parts.indexOf(want) >= 0 || v.toLowerCase() === want.toLowerCase();
    return c.op === 'ne' ? !hit : hit;
  }
  var conds = $$('fieldset[data-cond]');
  function applyConds() {
    conds.forEach(function (fs) {
      var c;
      try { c = JSON.parse(fs.getAttribute('data-cond')); } catch (e) { return; }
      var show = test(c);
      if (fs.hidden === !show) return;
      fs.hidden = !show;
      $$('input, select, textarea', fs).forEach(function (el) { el.disabled = !show; }); // lo oculto no se envía
    });
    if (steps) paint();
  }

  /* ---------- Deslizador: el valor se envía solo después de moverlo ---------- */
  $$('.sf-slider input[type=range]').forEach(function (r) {
    var out = r.parentNode.querySelector('output'), unit = r.getAttribute('data-unit') || '';
    r.addEventListener('input', function () {
      r.name = r.getAttribute('data-sf-name');
      out.textContent = r.value + (unit ? ' ' + unit : '');
    });
  });

  /* ---------- Ordenar por preferencia ---------- */
  $$('[data-sf-rank]').forEach(function (ol) {
    var hidden = ol.parentNode.querySelector('input[type=hidden]');
    var sync = function () {
      var items = $$('li', ol);
      items.forEach(function (li, i) { li.querySelector('b').textContent = i + 1; });
      hidden.value = items.map(function (li) { return li.getAttribute('data-v'); }).join('|');
      applyConds();
    };
    ol.addEventListener('click', function (e) {
      var b = e.target.closest('button'), li = e.target.closest('li');
      if (!b || !li) return;
      if (b.hasAttribute('data-up') && li.previousElementSibling) ol.insertBefore(li, li.previousElementSibling);
      if (b.hasAttribute('data-down') && li.nextElementSibling) ol.insertBefore(li.nextElementSibling, li);
      b.focus();
      sync();
    });
    sync();
  });

  /* ---------- Encuesta por pasos ---------- */
  var mode = form.getAttribute('data-steps'), steps = null, cur = 0;
  var submit = form.querySelector('[data-sf-submit]');
  var bar, nav, back, next, count;
  if (mode) {
    var all = $$('fieldset', form).filter(function (f) { return f.parentNode === form; });
    steps = [];
    var page = [];
    all.forEach(function (f) {
      var isSection = f.classList.contains('sf-section');
      if (mode === 'question') {
        // una pregunta por pantalla; el correo va con la primera y cada título de sección con la pregunta que le sigue
        page.push(f);
        if (!isSection && !f.classList.contains('sf-email')) { steps.push(page); page = []; }
      } else {
        if (isSection && page.length && !(page.length === 1 && page[0].classList.contains('sf-email'))) { steps.push(page); page = []; }
        page.push(f);
      }
    });
    if (page.length) {
      if (steps.length && page.every(function (f) { return f.classList.contains('sf-section'); })) steps[steps.length - 1] = steps[steps.length - 1].concat(page);
      else steps.push(page);
    }
    if (steps.length < 2) {
      steps = null;
    } else {
      form.classList.add('sf-stepped');
      bar = document.createElement('div');
      bar.className = 'sf-progress';
      bar.innerHTML = '<i></i>';
      var card = form.closest('.pcard') || form.parentNode;
      card.insertBefore(bar, card.firstChild);
      nav = document.createElement('div');
      nav.className = 'sf-nav';
      nav.innerHTML = '<button type="button" class="btn" data-back></button><span></span><button type="button" class="btn primary big" data-next></button>';
      back = nav.querySelector('[data-back]'); next = nav.querySelector('[data-next]'); count = nav.querySelector('span');
      back.textContent = form.getAttribute('data-back') || 'Atrás';
      next.textContent = form.getAttribute('data-next') || 'Siguiente';
      submit.parentNode.insertBefore(nav, submit);
      nav.appendChild(submit);
      back.addEventListener('click', function () { go(cur - 1); });
      next.addEventListener('click', function () { if (validate(steps[cur])) go(cur + 1); });
      // si el servidor devolvió errores, abrir el primer paso con error
      var bad = form.querySelector('fieldset.bad');
      steps.forEach(function (p, i) { if (bad && p.indexOf(bad) >= 0 && cur === 0) cur = i; });
    }
  }
  function visibleSteps() { return steps.filter(function (p) { return p.some(function (f) { return !f.hidden; }); }); }
  function paint() {
    if (!steps) return;
    var vis = visibleSteps();
    if (cur >= steps.length) cur = steps.length - 1;
    steps.forEach(function (p, i) { p.forEach(function (f) { f.classList.toggle('sf-off', i !== cur); }); });
    var pos = vis.indexOf(steps[cur]);
    if (pos < 0) pos = 0;
    var last = pos >= vis.length - 1;
    back.hidden = pos === 0;
    next.hidden = last;
    submit.hidden = !last;
    count.textContent = (pos + 1) + ' ' + (form.getAttribute('data-of') || 'de') + ' ' + vis.length;
    bar.firstChild.style.width = Math.round((pos + 1) / vis.length * 100) + '%';
  }
  function go(i) {
    var dir = i > cur ? 1 : -1;
    // saltar los pasos que han quedado vacíos por las condiciones
    while (i >= 0 && i < steps.length && !steps[i].some(function (f) { return !f.hidden; })) i += dir;
    if (i < 0 || i >= steps.length) return;
    cur = i;
    paint();
    var first = steps[cur].filter(function (f) { return !f.hidden; })[0];
    if (first) { first.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); var el = first.querySelector('input:not([type=hidden]),select,textarea'); if (el && !el.readOnly) el.focus({ preventScroll: true }); }
  }
  function answered(f) {
    if (f.classList.contains('sf-section')) return true;
    return $$('input, select, textarea', f).some(function (el) {
      if (!el.name || el.disabled || el.type === 'hidden' && f.getAttribute('data-type') !== 'rank') return false;
      if (el.type === 'radio' || el.type === 'checkbox') return el.checked;
      return el.value.trim() !== '';
    }) && (f.getAttribute('data-type') !== 'matrix' || $$('tbody tr', f).every(function (tr) { return tr.querySelector('input:checked'); }));
  }
  function validate(page) {
    var ok = true;
    page.forEach(function (f) {
      var old = f.querySelector('.error.sf-js');
      if (old) old.remove();
      if (f.hidden || !f.hasAttribute('data-req') || answered(f)) { if (!f.querySelector('.error')) f.classList.remove('bad'); return; }
      ok = false;
      f.classList.add('bad');
      var p = document.createElement('p');
      p.className = 'error sf-js';
      p.textContent = form.getAttribute('data-required') || 'Obligatoria';
      f.appendChild(p);
    });
    if (!ok) { var b = page.filter(function (f) { return f.classList.contains('bad'); })[0]; if (b) b.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    return ok;
  }

  form.addEventListener('change', applyConds);
  form.addEventListener('input', function (e) { if (e.target.matches('input[type=text],input[type=number],textarea')) applyConds(); });
  form.addEventListener('keydown', function (e) { // Intro en un campo de texto: siguiente paso, no enviar
    if (steps && e.key === 'Enter' && e.target.matches('input') && !next.hidden) { e.preventDefault(); next.click(); }
  });
  applyConds();
  if (steps) paint();
})();
