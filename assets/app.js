(function () {
  'use strict';
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  /* Diálogos y "seleccionar todo" */
  $$('[data-open]').forEach(b => b.addEventListener('click', () => $('#' + b.dataset.open).showModal()));
  $$('[data-close]').forEach(b => b.addEventListener('click', () => b.closest('dialog').close()));
  $$('[data-checkall]').forEach(c => c.addEventListener('change', () =>
    $$('tbody input[type=checkbox]', c.closest('table')).forEach(x => { x.checked = c.checked; })));

  /* Casillas "Mostrar contraseña" */
  $$('[data-showpass]').forEach(c => c.addEventListener('change', () =>
    $$(c.dataset.showpass).forEach(i => { i.type = c.checked ? 'text' : 'password'; })));

  $$('[data-copy]').forEach(b => b.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(b.dataset.copy); } catch (e) { prompt('Copie la dirección:', b.dataset.copy); return; }
    const t = b.textContent; b.textContent = 'Copiada ✓'; setTimeout(() => { b.textContent = t; }, 1500);
  }));

  /* Filas que llevan a otra página al pulsarlas (pestaña Etiquetas) */
  $$('tr[data-href]').forEach(tr => tr.addEventListener('click', e => { if (!e.target.closest('a,button,input,form')) location.href = tr.dataset.href; }));
  /* Importar a una etiqueta concreta (pestaña Etiquetas) */
  const ti = $('#dlg-tagimport');
  if (ti) $$('[data-import-tag]').forEach(b => b.addEventListener('click', () => {
    const tag = b.dataset.importTag, input = $('[name=preset_tag]', ti);
    input.value = tag;
    $('[data-import-title]', ti).textContent = tag ? 'Importar contactos a «' + tag + '»' : 'Importar contactos a una etiqueta nueva';
    ti.showModal();
    (tag ? $('[name=file]', ti) : input).focus();
  }));

  /* ---------- Acciones masivas en el listado de contactos ---------- */
  const bulk = $('#bulkform');
  if (bulk) {
    const bar = $('[data-bulkbar]', bulk), all = $('[data-allfiltered]', bulk);
    const boxes = () => $$('tbody input[name="ids[]"]', bulk);
    const update = () => {
      const n = all && all.checked ? +all.closest('label').textContent.match(/\d+/)[0] : boxes().filter(b => b.checked).length;
      $('[data-selcount]', bar).textContent = n;
      bar.classList.toggle('active', n > 0);
    };
    bulk.addEventListener('change', update);
    if (all) all.addEventListener('change', () => { boxes().forEach(b => { b.checked = all.checked; b.disabled = all.checked; }); update(); });
    $$('[data-bulk]', bar).forEach(btn => btn.addEventListener('click', e => {
      const n = +$('[data-selcount]', bar).textContent;
      const tags = $('[name=bulk_tags]', bar).value.trim();
      if (!n) { e.preventDefault(); alert('Marque primero los contactos en la lista.'); return; }
      if (btn.dataset.bulk !== 'delete' && !tags) { e.preventDefault(); $('[name=bulk_tags]', bar).focus(); return; }
      const msg = btn.dataset.bulk === 'delete' ? '¿Eliminar ' + n + ' contacto(s)? No se puede deshacer.'
        : (btn.dataset.bulk === 'add' ? '¿Añadir «' + tags + '» a ' : '¿Quitar «' + tags + '» de ') + n + ' contacto(s)?';
      if (!confirm(msg)) e.preventDefault();
    }));
    update();
  }

  /* ---------- Editor de correos ----------
     Se hace clic en un elemento (imagen, botón, enlace o texto) y sus opciones aparecen en el panel lateral. */
  const ed = $('.editor');
  if (ed) {
    const canvas = $('.canvas', ed), source = $('.source', ed), file = $('input[type=file]', ed);
    const wrap = $('.canvaswrap', ed), mark = $('.selmark', ed), panel = $('#props');
    const brand = ed.dataset.brand || '#00567d';
    const font = 'Ubuntu, Helvetica, Arial, sans-serif';
    canvas.innerHTML = source.value;
    let html = false, sel = null, kind = '', lastRange = null;

    /* Deshacer / rehacer por instantáneas */
    const hist = [canvas.innerHTML]; let hpos = 0, htimer = null;
    const snapshot = () => {
      if (hist[hpos] === canvas.innerHTML) return;
      hist.splice(hpos + 1); hist.push(canvas.innerHTML);
      if (hist.length > 60) hist.shift();
      hpos = hist.length - 1;
    };
    const sync = () => {
      if (!html) source.value = canvas.innerHTML;
      place(); clearTimeout(htimer); htimer = setTimeout(snapshot, 400);
    };
    const commit = () => { clearTimeout(htimer); snapshot(); };
    const travel = d => {
      clearTimeout(htimer); snapshot();
      const n = hpos + d;
      if (n < 0 || n >= hist.length) return;
      hpos = n; canvas.innerHTML = hist[hpos]; source.value = hist[hpos]; select(null);
    };

    /* Utilidades */
    const el = (tag, props, kids) => {
      const n = Object.assign(document.createElement(tag), props || {});
      (kids || []).forEach(k => n.append(k));
      return n;
    };
    const field = (label, input, hint) => el('label', { className: 'pf' }, [label, input].concat(hint ? [el('small', { textContent: hint })] : []));
    const choice = (options, current, onPick) => {
      const box = el('div', { className: 'choice' });
      options.forEach(([val, text]) => {
        const b = el('button', { type: 'button', textContent: text, className: val === current ? 'on' : '' });
        b.addEventListener('click', () => { $$('button', box).forEach(x => x.classList.remove('on')); b.classList.add('on'); onPick(val); sync(); });
        box.append(b);
      });
      return box;
    };
    const topOf = n => { while (n && n.parentNode !== canvas) n = n.parentNode; return n; };
    const isButton = a => a.parentElement && a.parentElement.tagName === 'TD' && a.parentElement.hasAttribute('bgcolor');
    const toHex = c => {
      const m = /rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(c || '');
      return m ? '#' + [1, 2, 3].map(i => (+m[i]).toString(16).padStart(2, '0')).join('') : (/^#[0-9a-f]{6}$/i.test(c || '') ? c : '#000000');
    };
    const place = () => {
      if (!sel || !canvas.contains(sel) || html) { mark.hidden = true; return; }
      const r = sel.getBoundingClientRect(), w = wrap.getBoundingClientRect();
      Object.assign(mark.style, { left: r.left - w.left - 3 + 'px', top: r.top - w.top - 3 + 'px', width: r.width + 6 + 'px', height: r.height + 6 + 'px' });
      mark.dataset.label = { image: 'Imagen', button: 'Botón', link: 'Enlace', text: 'Texto' }[kind] || '';
      mark.hidden = false;
    };
    window.addEventListener('resize', place);
    canvas.addEventListener('load', place, true);

    /* Biblioteca de imágenes */
    const picker = $('#picker'), grid = $('.pickgrid', picker);
    let onPick = null, pickKind = 'image';
    const tile = m => m.kind === 'image'
      ? '<img src="' + esc(m.url) + '" alt="" loading="lazy"><span>' + esc(m.name) + '</span>'
      : '<i class="docicon ' + esc(m.ext) + '">' + esc(m.ext.toUpperCase()) + '</i><span>' + esc(m.name) + '</span>';
    const loadGrid = async () => {
      grid.innerHTML = '<p class="muted">Cargando…</p>';
      const list = await (await fetch('index.php?p=media&a=json' + (pickKind === 'image' ? '&kind=image' : ''))).json();
      grid.innerHTML = list.length ? '' : '<p class="empty">La biblioteca está vacía. Suba el primer archivo.</p>';
      list.forEach(m => {
        const b = el('button', { type: 'button', className: 'pick', title: m.name });
        b.innerHTML = tile(m);
        b.addEventListener('click', () => { picker.close(); if (onPick) onPick(m.url, m); });
        grid.appendChild(b);
      });
    };
    const openPicker = (cb, kind) => {
      onPick = cb; pickKind = kind || 'image';
      $('[data-pick-title]', picker).textContent = pickKind === 'file' ? 'Elija el archivo que quiere adjuntar' : 'Biblioteca de imágenes';
      file.accept = pickKind === 'file' ? file.dataset.acceptFile : 'image/*';
      picker.showModal(); loadGrid();
    };
    $$('[data-pick]').forEach(b => b.addEventListener('click', () => openPicker(url => { $('#' + b.dataset.pick).value = url; })));
    $('[data-pick-upload]', picker).addEventListener('click', () => file.click());
    file.addEventListener('change', async () => {
      if (!file.files[0]) return;
      const fd = new FormData();
      fd.append('image', file.files[0]); fd.append('_csrf', window.CSRF); fd.append('kind', pickKind);
      try {
        const j = await (await fetch('index.php?p=emails&a=upload', { method: 'POST', body: fd })).json();
        if (j.error) throw new Error(j.error);
        await loadGrid();
      } catch (e) { alert(e.message || 'No se pudo subir el archivo'); }
      file.value = '';
    });

    /* Archivos adjuntos del correo */
    const att = $('#attach'), attList = $('.attlist', att);
    const fmtSize = n => n >= 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
    const attCheck = () => {
      const total = $$('[data-size]', attList).reduce((t, x) => t + (+x.dataset.size || 0), 0);
      $('.attwarn', att).hidden = total <= 5 * 1048576;
    };
    attList.addEventListener('click', e => { if (e.target.matches('[data-unattach]')) { e.target.closest('li').remove(); attCheck(); } });
    $('[data-attach]', att).addEventListener('click', () => openPicker((url, m) => {
      if ($$('input', attList).some(i => i.value === m.name)) return;
      const li = el('li');
      li.innerHTML = '<input type="hidden" name="attachments[]" value="' + esc(m.name) + '"><span class="docicon mini ' + esc(m.ext) + '">' + esc(m.ext.toUpperCase()) +
        '</span> <a href="' + esc(m.url) + '" target="_blank">' + esc(m.name) + '</a> <span class="muted" data-size="' + m.size + '">' + fmtSize(m.size) +
        '</span> <button type="button" class="btn small danger" data-unattach title="Quitar adjunto">×</button>';
      attList.append(li); attCheck();
    }, 'file'));
    attCheck();

    /* ---- Panel de opciones ---- */
    const blockTools = () => {
      const top = topOf(sel);
      const mk = (text, fn, cls) => { const b = el('button', { type: 'button', className: 'btn small ' + (cls || ''), textContent: text }); b.addEventListener('click', fn); return b; };
      return el('div', { className: 'ptools' }, [
        el('span', { className: 'muted', textContent: 'Bloque:' }),
        mk('↑ Subir', () => { if (top.previousElementSibling) { canvas.insertBefore(top, top.previousElementSibling); sync(); } }),
        mk('↓ Bajar', () => { if (top.nextElementSibling) { canvas.insertBefore(top.nextElementSibling, top); sync(); } }),
        mk('Duplicar', () => { top.after(top.cloneNode(true)); sync(); }),
        mk('Eliminar', () => { top.remove(); select(null); sync(); }, 'danger')
      ]);
    };
    const linkOf = n => (n.parentElement && n.parentElement.tagName === 'A' ? n.parentElement : null);
    const panels = {
      image: () => {
        const img = sel, out = [];
        const thumb = el('img', { src: img.src, className: 'pthumb', alt: '' });
        const change = el('button', { type: 'button', className: 'btn primary', textContent: 'Cambiar imagen…' });
        change.addEventListener('click', () => openPicker(url => { img.src = url; thumb.src = url; sync(); }));
        out.push(el('div', { className: 'pimg' }, [thumb, change]));
        const alt = el('input', { value: img.getAttribute('alt') || '', placeholder: 'Descripción breve de la imagen' });
        alt.addEventListener('input', () => { img.setAttribute('alt', alt.value); sync(); });
        out.push(field('Texto alternativo', alt, 'Se muestra si la imagen no carga y lo leen los lectores de pantalla.'));
        const a0 = linkOf(img);
        const href = el('input', { value: a0 ? a0.getAttribute('href') || '' : '', placeholder: 'https://… (vacío = sin enlace)' });
        href.addEventListener('input', () => {
          let a = linkOf(img);
          const v = href.value.trim();
          if (v) {
            if (!a) { a = el('a', { target: '_blank' }); a.style.textDecoration = 'none'; img.replaceWith(a); a.append(img); }
            a.setAttribute('href', v);
          } else if (a) a.replaceWith(img);
          sync();
        });
        out.push(field('Enlace al hacer clic en la imagen', href));
        const cur = img.style.width && img.style.width.endsWith('%') ? img.style.width : 'auto';
        out.push(field('Ancho', choice([['100%', 'Completo'], ['75%', '75%'], ['50%', '50%'], ['25%', '25%'], ['auto', 'Original']], cur, v => {
          img.removeAttribute('width'); img.removeAttribute('height');
          img.style.height = 'auto'; img.style.maxWidth = '100%';
          img.style.width = v === 'auto' ? '' : v;
        })));
        const m = img.style.marginLeft === 'auto' ? (img.style.marginRight === 'auto' ? 'center' : 'right') : 'left';
        out.push(field('Alineación', choice([['left', 'Izquierda'], ['center', 'Centro'], ['right', 'Derecha']], m, v => {
          img.style.display = 'block';
          img.style.marginLeft = v === 'left' ? '0' : 'auto';
          img.style.marginRight = v === 'right' ? '0' : 'auto';
        })));
        const del = el('button', { type: 'button', className: 'btn small danger', textContent: 'Quitar solo la imagen' });
        del.addEventListener('click', () => { (linkOf(img) || img).remove(); select(null); sync(); });
        out.push(del);
        return ['Imagen', out];
      },
      button: () => {
        const a = sel, td = a.parentElement, out = [];
        const text = el('input', { value: a.textContent.trim() });
        text.addEventListener('input', () => { a.textContent = text.value; sync(); });
        out.push(field('Texto del botón', text));
        const href = el('input', { value: a.getAttribute('href') || '', placeholder: 'https://…' });
        href.addEventListener('input', () => { a.setAttribute('href', href.value.trim()); sync(); });
        out.push(field('Enlace (dirección a la que lleva)', href));
        const color = el('input', { type: 'color', value: toHex(td.getAttribute('bgcolor') || td.style.backgroundColor) });
        color.addEventListener('input', () => { td.setAttribute('bgcolor', color.value); td.style.background = color.value; sync(); });
        out.push(field('Color del botón', color));
        const table = td.closest('table');
        const w = table && table.getAttribute('width') === '100%' ? 'full' : 'fit';
        out.push(field('Ancho', choice([['full', 'Completo'], ['fit', 'Ajustado al texto']], w, v => {
          if (!table) return;
          if (v === 'full') { table.setAttribute('width', '100%'); table.removeAttribute('align'); table.style.margin = '6px 0 22px'; }
          else { table.removeAttribute('width'); table.setAttribute('align', 'center'); table.style.margin = '6px auto 22px'; }
        })));
        return ['Botón', out];
      },
      link: () => {
        const a = sel, out = [];
        const text = el('input', { value: a.textContent });
        text.addEventListener('input', () => { a.textContent = text.value; sync(); });
        if (!a.querySelector('*')) out.push(field('Texto del enlace', text));
        const href = el('input', { value: a.getAttribute('href') || '', placeholder: 'https://…' });
        href.addEventListener('input', () => { a.setAttribute('href', href.value.trim()); sync(); });
        out.push(field('Enlace (dirección a la que lleva)', href, 'Puede usar {{encuesta_url}} o {{baja_url}}.'));
        const un = el('button', { type: 'button', className: 'btn small danger', textContent: 'Quitar el enlace (deja el texto)' });
        un.addEventListener('click', () => { a.replaceWith(...a.childNodes); select(null); sync(); });
        out.push(un);
        return ['Enlace', out];
      },
      text: () => {
        const out = [], tag = sel.tagName.toLowerCase();
        if (['p', 'h1', 'h2', 'h3', 'h4'].includes(tag)) {
          out.push(field('Tipo de texto', choice([['p', 'Párrafo'], ['h2', 'Título'], ['h3', 'Subtítulo']], tag === 'h1' ? 'h2' : tag, v => {
            const n = el(v); n.innerHTML = sel.innerHTML;
            if (sel.style.textAlign) n.style.textAlign = sel.style.textAlign;
            if (sel.style.color) n.style.color = sel.style.color;
            sel.replaceWith(n); sel = n; place();
          })));
        }
        out.push(field('Alineación', choice([['left', 'Izquierda'], ['center', 'Centro'], ['right', 'Derecha'], ['justify', 'Justificado']], sel.style.textAlign || 'left', v => { sel.style.textAlign = v; })));
        out.push(field('Tamaño', choice([['12px', 'Pequeño'], ['', 'Normal'], ['18px', 'Grande'], ['24px', 'Muy grande']], sel.style.fontSize || '', v => {
          sel.style.fontSize = v; sel.style.lineHeight = v ? '1.4' : '';
        })));
        const color = el('input', { type: 'color', value: toHex(sel.style.color || getComputedStyle(sel).color) });
        color.addEventListener('input', () => { sel.style.color = color.value; sync(); });
        out.push(field('Color del texto', color));
        const fmt = el('div', { className: 'choice' });
        [['bold', 'Negrita'], ['italic', 'Cursiva'], ['underline', 'Subrayado']].forEach(([cmd, label]) => {
          const b = el('button', { type: 'button', textContent: label });
          b.addEventListener('mousedown', e => { e.preventDefault(); document.execCommand(cmd); sync(); });
          fmt.append(b);
        });
        out.push(field('Formato de las palabras seleccionadas', fmt));
        const url = el('input', { placeholder: 'https://…' });
        const add = el('button', { type: 'button', className: 'btn small', textContent: 'Convertir la selección en enlace' });
        add.addEventListener('click', () => {
          if (!lastRange || lastRange.collapsed || !canvas.contains(lastRange.commonAncestorContainer)) { alert('Primero seleccione con el ratón las palabras que serán el enlace.'); return; }
          if (!url.value.trim()) { url.focus(); return; }
          const s = window.getSelection(); s.removeAllRanges(); s.addRange(lastRange);
          document.execCommand('createLink', false, url.value.trim());
          const a = s.anchorNode && s.anchorNode.parentElement && s.anchorNode.parentElement.closest('a');
          sync(); if (a) select(a, 'link');
        });
        out.push(field('Añadir un enlace', url, 'Seleccione palabras del texto, escriba la dirección y pulse el botón.'));
        out.push(add);
        return ['Texto', out];
      }
    };
    const select = (node, k) => {
      sel = node; kind = node ? k : '';
      panel.innerHTML = '';
      if (!node) {
        panel.append(el('h2', { textContent: 'Opciones del elemento' }),
          el('p', { className: 'muted', textContent: 'Haga clic en una imagen, un botón, un enlace o un texto del correo para ver y cambiar aquí sus opciones.' }));
        place(); return;
      }
      const [title, items] = panels[k]();
      const close = el('button', { type: 'button', className: 'btn small', textContent: 'Cerrar' });
      close.addEventListener('click', () => select(null));
      panel.append(el('div', { className: 'phead' }, [el('h2', { textContent: title }), close]), ...items, blockTools());
      place();
    };
    const pick = t => {
      if (!t || t === canvas || !canvas.contains(t)) return select(null);
      if (t.tagName === 'IMG') return select(t, 'image');
      const a = t.closest('a');
      if (a && canvas.contains(a)) return select(a, isButton(a) ? 'button' : 'link');
      const blk = t.closest('p,h1,h2,h3,h4,li,td,div');
      if (blk && blk !== canvas && canvas.contains(blk)) return select(blk, 'text');
      select(null);
    };
    canvas.addEventListener('click', e => { e.preventDefault(); commit(); pick(e.target); });
    const remember = () => {
      const s = window.getSelection();
      if (s.rangeCount && canvas.contains(s.anchorNode)) lastRange = s.getRangeAt(0).cloneRange();
    };
    canvas.addEventListener('mouseup', remember);
    canvas.addEventListener('keyup', () => { remember(); place(); });
    canvas.addEventListener('input', sync);
    // Pegar como texto limpio: evita arrastrar estilos de Word u Outlook
    canvas.addEventListener('paste', e => {
      const t = e.clipboardData.getData('text/plain');
      if (!t || e.clipboardData.types.includes('Files')) return;
      e.preventDefault();
      const parts = t.split(/\n{2,}/).map(p => esc(p).replace(/\n/g, '<br>'));
      document.execCommand('insertHTML', false, parts.length > 1 ? parts.map(p => '<p>' + p + '</p>').join('') : parts[0]);
      sync();
    });

    /* ---- Añadir bloques (debajo del elemento seleccionado, o al final) ---- */
    // Botón: el color va en la celda (bgcolor) para que Outlook lo pinte; el panel lo reconoce como botón
    const MF = "'Segoe UI',Helvetica,Arial,sans-serif", INK = '#1b2230', TXT = '#3d4756', MUTED = '#6b7686', LINE = '#e3e8ee', RED = '#f5333f', SKY = '#009fe3';
    const button = (text, url, bg, color) => { bg = bg || brand; color = color || '#ffffff';
      return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="margin:6px 0 24px;"><tr>' +
      '<td align="center" bgcolor="' + bg + '" style="background:' + bg + ';border-radius:8px;">' +
      '<a href="' + esc(url) + '" target="_blank" style="display:inline-block;padding:13px 28px;color:' + color + ';font-family:' + MF +
      ';font-size:15px;font-weight:700;line-height:20px;text-decoration:none;border-radius:8px;">' + esc(text) + '</a></td></tr></table>'; };
    const P = (t, size, color, mb) => '<p style="margin:0 0 ' + (mb == null ? 16 : mb) + 'px;font-family:' + MF + ';font-size:' + (size || 16) + 'px;line-height:1.65;color:' + (color || TXT) + ';">' + t + '</p>';
    const EYE = (t, c) => '<p style="margin:0 0 10px;font-family:' + MF + ';font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:' + (c || RED) + ';">' + t + '</p>';
    const H2 = (t, size, mb) => '<h2 style="margin:0 0 ' + (mb || 10) + 'px;font-family:' + MF + ';font-size:' + (size || 22) + 'px;line-height:1.3;font-weight:700;color:' + INK + ';"><a href="https://" target="_blank" style="color:' + INK + ';text-decoration:none;">' + t + '</a></h2>';
    const MORE = (t, size) => '<p style="margin:0 0 6px;font-family:' + MF + ';font-size:' + (size || 15) + 'px;"><a href="https://" target="_blank" style="font-weight:700;color:' + brand + ';text-decoration:none;">' + t + ' &rarr;</a></p>';
    const IMG = (w, h, max, r) => '<img src="' + ph(w, h) + '" width="' + max + '" alt="" style="display:block;width:100%;max-width:' + max + 'px;height:auto;border:0;border-radius:' + (r == null ? 10 : r) + 'px;">';
    const TWO = (a, b) => '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:0 0 26px;"><tr>' +
      '<td class="col2 mstack" width="50%" valign="top" style="width:50%;padding:0 12px 18px 0;">' + a + '</td>' +
      '<td class="col2 mstack" width="50%" valign="top" style="width:50%;padding:0 0 18px 12px;">' + b + '</td></tr></table>';
    const small = () => '<div class="full" style="margin:0 0 14px;">' + IMG(520, 340, 252) + '</div>' + EYE('Categoría', SKY) + H2('Titular de la noticia', 17, 8) + P('Resumen breve de la noticia…', 14, TXT, 10) + MORE('Leer más', 14);
    const product = () => '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="border:1px solid ' + LINE + ';border-radius:12px;">' +
      '<tr><td class="full" style="padding:0;">' + IMG(520, 380, 250, 0) + '</td></tr><tr><td style="padding:16px 18px 18px;">' +
      '<h2 style="margin:0 0 4px;font-family:' + MF + ';font-size:17px;line-height:1.3;font-weight:700;color:' + INK + ';">Nombre del producto</h2>' +
      '<p style="margin:0 0 12px;font-family:' + MF + ';font-size:13px;line-height:1.5;color:' + MUTED + ';">Características principales</p>' +
      '<p style="margin:0 0 14px;font-family:' + MF + ';font-size:14px;color:' + TXT + ';"><span style="text-decoration:line-through;color:' + MUTED + ';">100 €</span>&nbsp; <strong style="font-size:20px;color:' + RED + ';">85 €</strong></p>' +
      MORE('Ver producto', 14) + '</td></tr></table>';
    const ph = (w, h) => 'https://placehold.co/' + w + 'x' + h + '/e9eef2/667782?text=Haga+clic+para+elegir+imagen';
    const col = '<td class="col2" width="50%" valign="top" style="width:50%;padding:0 8px 16px;font-family:' + font + ';font-size:13px;line-height:1.5;">' +
      '<img src="' + ph(520, 520) + '" alt="" style="display:block;width:100%;height:auto;border:0;">' +
      '<p style="margin:10px 0;"><strong>Título</strong></p><p style="margin:0 0 10px;">Texto…</p>' + button('Más información >', 'https://') + '</td>';
    const blocks = {
      text: () => '<p>Escriba aquí el texto…</p>',
      title: () => '<h2>Título</h2>',
      image: () => '<img src="' + ph(1100, 400) + '" alt="" style="display:block;width:100%;height:auto;border:0;">',
      button: () => button('Más información', 'https://'),
      article: () => '<div><img src="' + ph(1100, 500) + '" alt="" style="display:block;width:100%;height:auto;border:0;">' +
        '<h2 style="margin:12px 0 10px;font-family:Helvetica, sans-serif;font-size:24px;line-height:32px;color:' + brand + ';">Título del artículo</h2>' +
        '<p style="margin:0 0 14px;">Texto del artículo…</p>' + button('Más información >', 'https://') + '</div>',
      columns: () => '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>' + col + col + '</tr></table>',
      divider: () => '<div style="border-top:1px solid ' + LINE + ';font-size:1px;line-height:1px;margin:10px 0 24px;">&nbsp;</div>',
      callout: () => '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:6px 0 22px;"><tr>' +
        '<td bgcolor="#f1f7fb" style="background-color:#f1f7fb;border-left:4px solid ' + SKY + ';border-radius:0 10px 10px 0;padding:16px 20px;font-family:' + MF + ';font-size:15px;line-height:1.6;color:' + INK + ';">' +
        '<strong>Título del recuadro</strong><br>Texto destacado…</td></tr></table>',
      news: () => '<div style="margin:0 0 30px;"><div class="full" style="margin:0 0 18px;">' + IMG(1100, 560, 528) + '</div>' + EYE('Categoría', SKY) + H2('Titular de la noticia') + P('Resumen de la noticia…', 15, TXT, 12) + MORE('Leer más') + '</div>',
      news2: () => TWO(small(), small()),
      stats: () => '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:6px 0 24px;"><tr><td bgcolor="' + brand + '" style="background-color:' + brand + ';border-radius:12px;padding:20px 14px;">' +
        '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0"><tr>' +
        [['1947', 'Desde'], ['10', 'Empresas'], ['+100', 'Países']].map(([n, l]) => '<td width="33%" align="center" style="padding:10px 6px;font-family:' + MF + ';color:#ffffff;"><div style="font-size:30px;font-weight:700;line-height:1.1;color:#ffffff;">' + n + '</div><div style="font-size:13px;color:#cfe2ec;padding-top:4px;">' + l + '</div></td>').join('') +
        '</tr></table></td></tr></table>',
      hero: () => '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:0 0 26px;"><tr>' +
        '<td bgcolor="' + brand + '" style="background-color:' + brand + ';background-image:linear-gradient(135deg,' + brand + ' 0%,#002f45 100%);border-radius:14px;padding:40px 34px 38px;">' +
        '<p style="margin:0 0 14px;"><span style="display:inline-block;background-color:' + RED + ';color:#ffffff;font-family:' + MF + ';font-size:12px;font-weight:700;letter-spacing:1.4px;padding:6px 12px;border-radius:20px;">OFERTA</span></p>' +
        '<h1 class="h1" style="margin:0 0 12px;font-family:' + MF + ';font-size:36px;line-height:1.15;font-weight:800;color:#ffffff;">Titular de la oferta</h1>' +
        '<p style="margin:0 0 24px;font-family:' + MF + ';font-size:17px;line-height:1.55;color:#d3e6f0;">Texto de la promoción…</p>' + button('Ver la oferta', 'https://', '#ffffff', brand) + '</td></tr></table>',
      products: () => TWO(product(), product()),
      coupon: () => '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:6px 0 24px;"><tr>' +
        '<td bgcolor="#fff4f4" style="background-color:#fff4f4;border:1px dashed ' + RED + ';border-radius:12px;padding:18px 20px;font-family:' + MF + ';font-size:14px;line-height:1.5;color:' + TXT + ';text-align:center;">' +
        'Use este código al hacer su pedido:<br><strong style="font-size:22px;letter-spacing:3px;color:' + RED + ';">CODIGO</strong><br><span style="font-size:13px;color:' + MUTED + ';">Válido hasta el 31/12/2026</span></td></tr></table>',
      event: () => '<table role="presentation" width="100%" border="0" cellpadding="0" cellspacing="0" style="margin:4px 0 26px;">' +
        [['&#128197;', 'Fecha', '13 al 19 de abril de 2026'], ['&#128205;', 'Lugar', 'Ciudad · Recinto'], ['&#128337;', 'Horario', 'De 9:30 a 18:30 h']].map(([i, t, d]) =>
          '<tr><td width="34" valign="top" style="padding:9px 0;font-size:18px;">' + i + '</td><td style="padding:9px 0;border-bottom:1px solid ' + LINE + ';font-family:' + MF + ';font-size:15px;line-height:1.5;color:' + TXT + ';"><strong style="color:' + INK + ';">' + t + '</strong><br>' + d + '</td></tr>').join('') + '</table>',
      survey: () => '<p>{{encuesta}}</p>'
    };
    $$('[data-add]', ed).forEach(b => b.addEventListener('click', () => {
      commit();
      const t = document.createElement('template');
      t.innerHTML = blocks[b.dataset.add]();
      const node = t.content.firstElementChild;
      const after = sel && canvas.contains(sel) ? topOf(sel) : null;
      if (after) after.after(node); else canvas.append(node);
      sync();
      const first = b.dataset.add === 'button' ? $('a', node) : (node.tagName === 'IMG' ? node : $('img', node) || node);
      pick(first); node.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      if (b.dataset.add === 'image') openPicker(url => { node.src = url; sync(); select(node, 'image'); });
    }));
    $('[data-act=var]', ed).addEventListener('change', e => {
      const v = e.target.value; e.target.value = '';
      if (!v) return;
      if (html) { source.setRangeText(v, source.selectionStart, source.selectionEnd, 'end'); return; }
      if (lastRange && canvas.contains(lastRange.commonAncestorContainer)) { const s = window.getSelection(); s.removeAllRanges(); s.addRange(lastRange); }
      else canvas.focus();
      document.execCommand('insertText', false, v); sync();
    });
    $('[data-act=undo]', ed).addEventListener('click', () => travel(-1));
    $('[data-act=redo]', ed).addEventListener('click', () => travel(1));
    const srcBtn = $('[data-act=source]', ed);
    srcBtn.addEventListener('click', () => {
      html = !html;
      if (html) { select(null); source.value = canvas.innerHTML; } else { canvas.innerHTML = source.value; snapshot(); }
      source.hidden = !html; wrap.hidden = html; srcBtn.classList.toggle('on', html);
    });
    ed.closest('form').addEventListener('submit', () => { if (!html) source.value = canvas.innerHTML; });
    select(null);
  }

  /* Aviso por correo de las respuestas: muestra solo los campos que aplican */
  const nm = $('[data-notifymode]');
  if (nm) {
    const upd = () => {
      $('[data-notifymax]').hidden = nm.value !== 'low';
      $$('[data-notifyto]').forEach(x => { x.hidden = nm.value === ''; });
    };
    nm.addEventListener('change', upd); upd();
  }

  /* ---------- Editor de preguntas ---------- */
  const qs = $('#questions');
  if (qs) {
    let next = 1000;
    const refresh = row => {
      const t = $('[data-qtype]', row).value;
      $('[data-qopts]', row).hidden = !(t === 'radio' || t === 'checkbox');
    };
    $$('[data-q]', qs).forEach(refresh);
    qs.addEventListener('change', e => { if (e.target.matches('[data-qtype]')) refresh(e.target.closest('[data-q]')); });
    qs.addEventListener('click', e => {
      const row = e.target.closest('[data-q]');
      if (!row) return;
      if (e.target.matches('[data-del]')) {
        if ($$('[data-q]', qs).length > 1 && confirm('¿Quitar esta pregunta? Sus respuestas se borrarán al guardar.')) row.remove();
      } else if (e.target.matches('[data-up]') && row.previousElementSibling) qs.insertBefore(row, row.previousElementSibling);
      else if (e.target.matches('[data-down]') && row.nextElementSibling) qs.insertBefore(row.nextElementSibling, row);
    });
    $('#addq').addEventListener('click', () => {
      const d = document.createElement('div');
      d.innerHTML = $('#qtpl').innerHTML.replace(/__I__/g, String(next++));
      const row = d.firstElementChild;
      qs.appendChild(row); refresh(row); $('input[name$="[label]"]', row).focus();
    });
  }

  /* ---------- Progreso del envío ---------- */
  const pg = $('#progress');
  if (pg) {
    const total = +pg.dataset.total, msg = $('#pmsg');
    const paint = s => {
      const sent = s.sent || 0, failed = s.failed || 0, skipped = s.skipped || 0;
      const pending = (s.pending || 0) + (s.sending || 0);
      $('[data-k=sent]', pg).textContent = sent;
      $('[data-k=failed]', pg).textContent = failed;
      $('[data-k=pending]', pg).textContent = pending;
      $('.fill', pg).style.width = (total ? (sent + failed + skipped) / total * 100 : 100) + '%';
      return pending;
    };
    let errors = 0;
    const step = async () => {
      try {
        const r = await fetch('index.php?p=emails&a=process&send=' + pg.dataset.send, { method: 'POST', headers: { 'X-CSRF': window.CSRF } });
        const j = await r.json();
        const pending = paint(j.stats || {});
        if (!pending) { msg.textContent = 'Envío terminado.'; if (j.stats.failed) setTimeout(() => location.reload(), 600); return; }
        if (j.limited) { msg.textContent = 'Se ha alcanzado el límite de envíos por hora. El resto continuará automáticamente (con esta página abierta o mediante la tarea programada).'; return setTimeout(step, 60000); }
        if (j.sent === 0 && j.failed > 0) {
          msg.textContent = 'El servidor de correo está rechazando los envíos: ' + (j.error || '') + ' Revise los Ajustes y recargue esta página para reintentar los pendientes.';
          return;
        }
        errors = 0; setTimeout(step, 400);
      } catch (e) {
        if (++errors > 5) { msg.textContent = 'Se ha perdido la conexión. Recargue la página para continuar.'; return; }
        setTimeout(step, 3000);
      }
    };
    step();
  }
})();
