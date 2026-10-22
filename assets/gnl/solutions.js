/* =====================================================================
   GNL Solution — page « Votre solution »
   Filtres, fenêtre de commande (options + domaine) et ajout au panier
   sans quitter la page. Le panier reste géré côté serveur (pages/cart.php) :
   on lui envoie exactement ce qu'envoie product-configuration.php.
   ===================================================================== */
(function () {
  'use strict';
  var dataEl = document.getElementById('gnl-sol-data');
  if (!dataEl) return;
  var DATA = {};
  try { DATA = JSON.parse(dataEl.textContent); } catch (e) { return; }
  var fmt = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
  var $ = function (id) { return document.getElementById(id); };

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
    fetch('/product-configuration?ajax=domain&domain=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (res) { cb(res && res.ok ? (res.results || []) : null, res); })
      .catch(function () { cb(null, null); });
  }
  function note(txt) { var d = document.createElement('div'); d.className = 'gnl-note'; d.textContent = txt; return d; }

  /* Formulaire autonome « Votre nom de domaine » */
  var domForm = $('gnl-dom-form'), domRes = $('gnl-dom-res');
  if (domForm && domRes) {
    domForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var q = (($('domaine') || {}).value || '').trim().toLowerCase();
      domRes.innerHTML = '';
      if (!DOMAIN_RE.test(q)) { domRes.appendChild(note('Entrez un domaine avec son extension, par exemple mon-association.fr.')); return; }
      domRes.innerHTML = '<div class="gnl-note"><span class="gnl-spin"></span> Vérification en cours…</div>';
      checkDomain(q, function (list) {
        domRes.innerHTML = '';
        if (!list || !list.length) { domRes.appendChild(note('Vérification automatique indisponible pour le moment. Vous pourrez demander ce domaine en option lors de la commande : nous confirmerons sa disponibilité.')); return; }
        list.slice(0, 6).forEach(function (it) {
          var row = document.createElement('div');
          row.style.cssText = 'display:flex;justify-content:space-between;gap:12px;padding:10px 14px;border-radius:3px;background:#fff;color:#141a33';
          row.innerHTML = '<strong></strong><span></span>';
          row.firstChild.textContent = it.domain;
          row.lastChild.textContent = it.orderable ? ('Disponible' + (it.price ? ' · ' + it.price : '')) : 'Indisponible';
          row.lastChild.style.color = it.orderable ? '#1f7a4a' : '#b42318';
          row.lastChild.style.fontWeight = '700';
          domRes.appendChild(row);
        });
        domRes.appendChild(note('Ajoutez le domaine en option au moment de commander votre hébergement.'));
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
    $('gnl-o-once').textContent = once > 0 ? '+ ' + fmt.format(once) + ' de frais uniques' : '';
    $('gnl-o-count').textContent = 'Total · ' + (n === 0 ? 'sans option' : n + ' option' + (n > 1 ? 's' : ''));
    var needDomain = state.options.domaine && !state.domain;
    $('gnl-o-add').disabled = !!needDomain;
    $('gnl-o-add').style.opacity = needDomain ? '.55' : '';
    $('gnl-o-add').title = needDomain ? 'Choisissez d’abord votre nom de domaine' : '';
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
    pr.textContent = o.prix > 0 ? ('+ ' + fmt.format(o.prix) + (o.unique ? ' (unique)' : ' / mois')) : (isDom ? 'selon le domaine' : 'inclus');
    lab.appendChild(cb); lab.appendChild(txt); lab.appendChild(pr); wrap.appendChild(lab);

    var domBox = null;
    if (isDom) {
      domBox = document.createElement('div'); domBox.hidden = true;
      domBox.style.cssText = 'padding:0 16px 16px 48px';
      domBox.innerHTML = '<div style="display:flex;gap:8px;flex-wrap:wrap"><label class="gnl-honey" for="gnl-o-dom">Nom de domaine souhaité</label>'
        + '<input id="gnl-o-dom" type="text" class="gnl-input" placeholder="mon-site.fr" autocomplete="off" spellcheck="false" style="flex:1 1 180px;min-height:44px">'
        + '<button type="button" class="gnl-b gnl-b-line" style="min-height:44px;font-size:14px">Vérifier</button></div><div class="gnl-domain-results" aria-live="polite"></div>';
      wrap.appendChild(domBox);
      var inp = domBox.querySelector('input'), btn = domBox.querySelector('button'), res = domBox.querySelector('.gnl-domain-results');
      var pick = function (name, price) {
        state.domain = { name: name, price: price || '', priceNum: 0 };
        res.querySelectorAll('.gnl-add').forEach(function (b) { b.classList.remove('is-added'); b.textContent = b.getAttribute('data-label'); });
        render();
      };
      var row = function (name, ok, price, label) {
        var r = document.createElement('div'); r.className = 'gnl-dom ' + (ok ? 'is-ok' : 'is-ko');
        r.innerHTML = '<span class="gnl-dom-name"></span><span class="gnl-dom-right"></span>';
        r.firstChild.textContent = name;
        var right = r.lastChild;
        if (ok) {
          if (price) { var p = document.createElement('span'); p.textContent = price; right.appendChild(p); }
          var a = document.createElement('button'); a.type = 'button'; a.className = 'gnl-add'; a.textContent = label; a.setAttribute('data-label', label);
          a.addEventListener('click', function () { pick(name, price); a.classList.add('is-added'); a.textContent = 'Choisi ✓'; });
          right.appendChild(a);
        } else { var k = document.createElement('span'); k.className = 'gnl-badge ko'; k.textContent = 'Indisponible'; right.appendChild(k); }
        return r;
      };
      var search = function () {
        var q = (inp.value || '').trim().toLowerCase();
        res.innerHTML = ''; state.domain = null; render();
        if (!DOMAIN_RE.test(q)) { res.appendChild(note('Entrez un domaine avec son extension, par exemple mon-site.fr.')); return; }
        btn.disabled = true;
        res.innerHTML = '<div class="gnl-note"><span class="gnl-spin"></span> Vérification…</div>';
        checkDomain(q, function (list) {
          btn.disabled = false; res.innerHTML = '';
          if (!list || !list.length) {
            res.appendChild(note('Vérification automatique indisponible : vous pouvez demander ce domaine, nous confirmerons sa disponibilité.'));
            res.appendChild(row(q, true, 'à confirmer', 'Demander'));
            return;
          }
          list.slice(0, 6).forEach(function (it) { res.appendChild(row(it.domain, !!it.orderable, it.price || '', 'Choisir')); });
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
    if (!cur || !modal) { location.href = '/product-configuration?slug=' + encodeURIComponent(slug); return; }
    state = { options: {}, domain: null };
    $('gnl-o-kicker').textContent = cur.kicker || '';
    $('titre-commande').textContent = cur.name;
    $('gnl-o-base').textContent = 'Offre ' + cur.name;
    $('gnl-o-baseprice').textContent = cur.price > 0 ? fmt.format(cur.price) : 'Sur devis';
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
    a.setAttribute('aria-label', 'Panier : ' + n + ' produit' + (n > 1 ? 's' : ''));
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
    fetch('/cart', { method: 'POST', body: fd, credentials: 'same-origin', redirect: 'follow' })
      .then(function (r) {
        if (!r.ok) throw new Error('http');
        var n = cookieCount(); if (n === null) n = before + 1;
        updateHeaderCart(n);
        $('gnl-o-done').innerHTML = '';
        var strong = document.createElement('strong'); strong.textContent = cur.name;
        $('gnl-o-done').appendChild(strong);
        $('gnl-o-done').appendChild(document.createTextNode(' a été ajouté à votre panier. Vous avez ' + n + ' produit' + (n > 1 ? 's' : '') + ' dans le panier.'));
        $('gnl-o-step1').hidden = true; $('gnl-o-step2').hidden = false;
        var f = $('gnl-o-step2').querySelector('a,button'); if (f) f.focus();
      })
      .catch(function () {
        $('gnl-o-err').textContent = 'L’ajout au panier n’a pas abouti. Réessayez, ou passez par la page de détail de l’offre.';
        $('gnl-o-err').hidden = false;
      })
      .then(function () { addBtn.disabled = false; render(); });
  });
})();
