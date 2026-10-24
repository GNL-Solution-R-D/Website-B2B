/* =====================================================================
   GNL Solution — page « Votre solution »
   Filtres, fenêtre de commande (options + domaine) et ajout au panier
   sans quitter la page. Le panier reste géré côté serveur (pages/cart.php) :
   on lui envoie exactement ce qu'envoie product-configuration.php.
   Textes : T('…') -> traduction fournie par window.gnlT (include/i18n.php).
   ===================================================================== */
(function () {
  'use strict';
  var T = function (s, vars) {
    var t = window.gnlT ? window.gnlT(s) : s;
    if (vars) Object.keys(vars).forEach(function (k) { t = t.split('{' + k + '}').join(vars[k]); });
    return t;
  };
  var dataEl = document.getElementById('gnl-sol-data');
  if (!dataEl) return;
  var DATA = {};
  try { DATA = JSON.parse(dataEl.textContent); } catch (e) { return; }
  var fmt = new Intl.NumberFormat(window.GNL_LOCALE || 'fr-FR', { style: 'currency', currency: 'EUR' });
  var $ = function (id) { return document.getElementById(id); };
  var LANG = window.GNL_LANG ? '/' + window.GNL_LANG : '';

  /* ---------------- Filtres ---------------- */
  var pills = document.querySelectorAll('#gnl-filters [data-filter]');
  pills.forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.getAttribute('data-filter');
      pills.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      document.querySelectorAll('.gnl-offer').forEach(function (o) {
        o.hidden = !(f === 'all' || o.getAttribute('data-cat') === f);
      });
    });
  });

  /* ---------------- Recherche de domaine (proxy OVH existant) ---------------- */
  var DOMAIN_RE = /^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9-]+)+$/;
  function checkDomain(q, cb) {
    fetch(LANG + '/product-configuration?ajax=domain&domain=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (res) { cb(res && res.ok ? (res.results || []) : null, res); })
      .catch(function () { cb(null, null); });
  }
  function note(txt) { var d = document.createElement('div'); d.className = 'gnl-note'; d.textContent = txt; return d; }
  function spin(txt) { var d = note(txt); var s = document.createElement('span'); s.className = 'gnl-spin'; d.insertBefore(document.createTextNode(' '), d.firstChild); d.insertBefore(s, d.firstChild); return d; }

  /* Formulaire autonome « Votre nom de domaine » */
  var domForm = $('gnl-dom-form'), domRes = $('gnl-dom-res');
  if (domForm && domRes) {
    domForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var q = (($('domaine') || {}).value || '').trim().toLowerCase();
      domRes.innerHTML = '';
      if (!DOMAIN_RE.test(q)) { domRes.appendChild(note(T('Entrez un domaine avec son extension, par exemple mon-association.fr.'))); return; }
      domRes.appendChild(spin(T('Vérification en cours…')));
      checkDomain(q, function (list) {
        domRes.innerHTML = '';
        if (!list || !list.length) { domRes.appendChild(note(T('Vérification automatique indisponible pour le moment. Vous pourrez demander ce domaine en option lors de la commande : nous confirmerons sa disponibilité.'))); return; }
        list.slice(0, 6).forEach(function (it) {
          var row = document.createElement('div');
          row.style.cssText = 'display:flex;justify-content:space-between;gap:12px;padding:10px 14px;border-radius:3px;background:#fff;color:#141a33';
          var a = document.createElement('strong'); a.textContent = it.domain;
          var b = document.createElement('span');
          b.textContent = it.orderable ? (T('Disponible') + (it.price ? ' · ' + it.price : '')) : T('Indisponible');
          b.style.color = it.orderable ? '#1f7a4a' : '#b42318';
          b.style.fontWeight = '700';
          row.appendChild(a); row.appendChild(b);
          domRes.appendChild(row);
        });
        domRes.appendChild(note(T('Ajoutez le domaine en option au moment de commander votre hébergement.')));
      });
    });
  }

  /* ---------------- Fenêtre de commande ---------------- */
  var modal = $('gnl-order');
  var cur = null, state = { options: {}, domain: null };

  function render() {
    if (!cur) return;
    var total = cur.price, once = 0, n = 0;
    Object.keys(state.options).forEach(function (s) {
      var o = state.options[s]; n++;
      if (o.unique) once += o.prix; else total += o.prix;
    });
    $('gnl-o-total').textContent = fmt.format(total);
    $('gnl-o-once').textContent = once > 0 ? T('+ {montant} de frais uniques', { montant: fmt.format(once) }) : '';
    $('gnl-o-count').textContent = n === 0 ? T('Total · sans option') : (n === 1 ? T('Total · 1 option') : T('Total · {n} options', { n: n }));
    var needDomain = state.options.domaine && !state.domain;
    $('gnl-o-add').disabled = !!needDomain;
    $('gnl-o-add').style.opacity = needDomain ? '.55' : '';
    $('gnl-o-add').title = needDomain ? T('Choisissez d’abord votre nom de domaine') : '';
  }

  function optionRow(o) {
    var isDom = o.slug === 'domaine';
    var wrap = document.createElement('div'); wrap.className = 'gnl-optrow';
    var lab = document.createElement('label');
    var cb = document.createElement('input'); cb.type = 'checkbox';
    var txt = document.createElement('span'); txt.style.flex = '1';
    var t1 = document.createElement('span'); t1.style.cssText = 'display:block;font-size:15px;font-weight:700'; t1.textContent = o.name;
    var t2 = document.createElement('span'); t2.style.cssText = 'display:block;font-size:13px;line-height:19px;color:#5e6684'; t2.textContent = o.desc || '';
    txt.appendChild(t1); if (o.desc) txt.appendChild(t2);
    var pr = document.createElement('span'); pr.style.cssText = 'font-size:14px;font-weight:700;white-space:nowrap';
    if (o.prix > 0) pr.textContent = o.unique ? T('+ {montant} (unique)', { montant: fmt.format(o.prix) }) : T('+ {montant} / mois', { montant: fmt.format(o.prix) });
    else pr.textContent = isDom ? T('selon le domaine') : T('inclus');
    lab.appendChild(cb); lab.appendChild(txt); lab.appendChild(pr); wrap.appendChild(lab);

    var domBox = null;
    if (isDom) {
      domBox = document.createElement('div'); domBox.hidden = true;
      domBox.style.cssText = 'padding:0 16px 16px 48px';
      var line = document.createElement('div'); line.style.cssText = 'display:flex;gap:8px;flex-wrap:wrap';
      var hl = document.createElement('label'); hl.className = 'gnl-honey'; hl.htmlFor = 'gnl-o-dom'; hl.textContent = T('Nom de domaine souhaité');
      var inp = document.createElement('input'); inp.id = 'gnl-o-dom'; inp.type = 'text'; inp.className = 'gnl-input'; inp.placeholder = 'mon-site.fr';
      inp.autocomplete = 'off'; inp.spellcheck = false; inp.style.cssText = 'flex:1 1 180px;min-height:44px';
      var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'gnl-b gnl-b-line'; btn.style.cssText = 'min-height:44px;font-size:14px'; btn.textContent = T('Vérifier');
      var res = document.createElement('div'); res.className = 'gnl-domain-results'; res.setAttribute('aria-live', 'polite');
      line.appendChild(hl); line.appendChild(inp); line.appendChild(btn);
      domBox.appendChild(line); domBox.appendChild(res);
      wrap.appendChild(domBox);
      var pick = function (name, price) {
        state.domain = { name: name, price: price || '', priceNum: 0 };
        res.querySelectorAll('.gnl-add').forEach(function (b) { b.classList.remove('is-added'); b.textContent = b.getAttribute('data-label'); });
        render();
      };
      var row = function (name, ok, price, label) {
        var r = document.createElement('div'); r.className = 'gnl-dom ' + (ok ? 'is-ok' : 'is-ko');
        var left = document.createElement('span'); left.className = 'gnl-dom-name'; left.textContent = name;
        var right = document.createElement('span'); right.className = 'gnl-dom-right';
        if (ok) {
          if (price) { var p = document.createElement('span'); p.textContent = price; right.appendChild(p); }
          var a = document.createElement('button'); a.type = 'button'; a.className = 'gnl-add'; a.textContent = label; a.setAttribute('data-label', label);
          a.addEventListener('click', function () { pick(name, price); a.classList.add('is-added'); a.textContent = T('Choisi ✓'); });
          right.appendChild(a);
        } else { var k = document.createElement('span'); k.className = 'gnl-badge ko'; k.textContent = T('Indisponible'); right.appendChild(k); }
        r.appendChild(left); r.appendChild(right);
        return r;
      };
      var search = function () {
        var q = (inp.value || '').trim().toLowerCase();
        res.innerHTML = ''; state.domain = null; render();
        if (!DOMAIN_RE.test(q)) { res.appendChild(note(T('Entrez un domaine avec son extension, par exemple mon-site.fr.'))); return; }
        btn.disabled = true;
        res.appendChild(spin(T('Vérification…')));
        checkDomain(q, function (list) {
          btn.disabled = false; res.innerHTML = '';
          if (!list || !list.length) {
            res.appendChild(note(T('Vérification automatique indisponible : vous pouvez demander ce domaine, nous confirmerons sa disponibilité.')));
            res.appendChild(row(q, true, T('à confirmer'), T('Demander')));
            return;
          }
          list.slice(0, 6).forEach(function (it) { res.appendChild(row(it.domain, !!it.orderable, it.price || '', T('Choisir'))); });
        });
      };
      btn.addEventListener('click', search);
      inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); search(); } });
    }

    cb.addEventListener('change', function () {
      wrap.classList.toggle('is-on', cb.checked);
      if (cb.checked) state.options[o.slug] = { name: o.name, prix: o.prix, unique: !!o.unique };
      else { delete state.options[o.slug]; if (isDom) state.domain = null; }
      if (domBox) domBox.hidden = !cb.checked;
      render();
    });
    return wrap;
  }

  function open(slug) {
    cur = DATA.products[slug];
    if (!cur || !modal) { location.href = LANG + '/product-configuration?slug=' + encodeURIComponent(slug); return; }
    state = { options: {}, domain: null };
    $('gnl-o-kicker').textContent = cur.kicker || '';
    $('titre-commande').textContent = cur.name;
    $('gnl-o-base').textContent = T('Offre {nom}', { nom: cur.name });
    $('gnl-o-baseprice').textContent = cur.price > 0 ? fmt.format(cur.price) : T('Sur devis');
    var list = $('gnl-o-list'); list.innerHTML = '';
    var opts = cur.options ? (DATA.options || []) : [];
    opts.forEach(function (o) { list.appendChild(optionRow(o)); });
    $('gnl-o-opts').hidden = !opts.length;
    $('gnl-o-none').hidden = !!opts.length;
    $('gnl-o-err').hidden = true;
    $('gnl-o-step1').hidden = false; $('gnl-o-step2').hidden = true;
    render();
    if (window.gnlOpenModal) window.gnlOpenModal('gnl-order');
  }
  document.querySelectorAll('[data-order]').forEach(function (b) {
    b.addEventListener('click', function () { open(b.getAttribute('data-order')); });
  });

  function cookieCount() {
    var m = document.cookie.match(/(?:^|; )gnl_cart_count=(\d+)/);
    return m ? parseInt(m[1], 10) : null;
  }
  function updateHeaderCart(n) {
    var a = document.querySelector('.gnl-hd-cart'); if (!a) return;
    var s = a.querySelector('.gnl-hd-cart-n');
    if (s) s.textContent = n > 99 ? '99+' : String(n);
    a.hidden = !(n > 0);
    a.setAttribute('aria-label', n > 1 ? T('Panier : {n} produits', { n: n }) : T('Panier : {n} produit', { n: n }));
  }

  var addBtn = $('gnl-o-add');
  if (addBtn) addBtn.addEventListener('click', function () {
    if (!cur || addBtn.disabled) return;
    var total = cur.price;
    Object.keys(state.options).forEach(function (s) { if (!state.options[s].unique) total += state.options[s].prix; });
    var fd = new FormData();
    fd.append('action', 'add');
    fd.append('produit', cur.slug);
    fd.append('config', JSON.stringify({ produit: cur.slug, base: cur.price, options: state.options, domaine: state.domain, total_mensuel: total }));
    addBtn.disabled = true;
    var before = cookieCount() || 0;
    fetch(LANG + '/cart', { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'follow' })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        var n = cookieCount(); if (n === null) n = before + 1;
        updateHeaderCart(n);
        var done = $('gnl-o-done'); done.innerHTML = '';
        var strong = document.createElement('strong'); strong.textContent = cur.name;
        done.appendChild(strong);
        done.appendChild(document.createTextNode(' ' + T('a été ajouté à votre panier.') + ' '
          + (n > 1 ? T('Vous avez {n} produits dans le panier.', { n: n }) : T('Vous avez {n} produit dans le panier.', { n: n }))));
        $('gnl-o-step1').hidden = true; $('gnl-o-step2').hidden = false;
        var f = $('gnl-o-step2').querySelector('a,button'); if (f) f.focus();
      })
      .catch(function () {
        $('gnl-o-err').textContent = T('L’ajout au panier n’a pas abouti. Réessayez, ou passez par la page de détail de l’offre.');
        $('gnl-o-err').hidden = false;
      })
      .then(function () { addBtn.disabled = false; render(); });
  });
})();
