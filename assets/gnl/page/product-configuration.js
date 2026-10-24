(function(){
  var dataEl = document.getElementById('gnl-config-data');
  if (!dataEl) return;
  var DATA = {};
  try { DATA = JSON.parse(dataEl.getAttribute('data-json') || dataEl.textContent); } catch(e){ return; }

  var fmt = new Intl.NumberFormat('fr-FR', { style:'currency', currency: DATA.currency || 'EUR' });
  var base = (DATA.product && typeof DATA.product.price === 'number') ? DATA.product.price : 0;

  var state = { options:{}, domain:null };

  var linesEl = document.getElementById('gnl-sum-lines');
  var totalEl = document.getElementById('gnl-total');
  var cfgField = document.getElementById('gnl-config-field');

  function li(label, value, cls){
    var l = document.createElement('li');
    if (cls) l.className = cls;
    var a = document.createElement('span'); a.textContent = label;
    var b = document.createElement('span'); b.textContent = value;
    l.appendChild(a); l.appendChild(b);
    return l;
  }

  function render(){
    var total = base;
    linesEl.innerHTML = '';
    linesEl.appendChild(li(DATA.product.name, fmt.format(base), 'gnl-sum-base'));

    Object.keys(state.options).forEach(function(slug){
      var o = state.options[slug];
      var label = o.name + (o.unique ? ' (unique)' : '');
      var val = o.prix > 0 ? '+ ' + fmt.format(o.prix) : 'incluse';
      linesEl.appendChild(li(label, val));
      if (!o.unique) total += o.prix; // les frais uniques ne comptent pas dans le /mois
    });

    if (state.domain){
      linesEl.appendChild(li('Domaine : ' + state.domain.name, state.domain.price || '—'));
      if (state.domain.priceNum) total += 0; // tarif domaine généralement annuel : affiché mais non ajouté au /mois
    }

    if (!Object.keys(state.options).length && !state.domain){
      linesEl.appendChild(li('Aucune option', '', 'gnl-muted'));
    }

    totalEl.textContent = fmt.format(total);

    if (cfgField){
      cfgField.value = JSON.stringify({
        produit: DATA.product.slug,
        base: base,
        options: state.options,
        domaine: state.domain,
        total_mensuel: total
      });
    }
  }

  // -------- Choix Oui / Non par option --------
  document.querySelectorAll('.gnl-opt').forEach(function(card){
    var slug   = card.getAttribute('data-slug');
    var name   = card.getAttribute('data-name');
    var prix   = parseFloat(card.getAttribute('data-prix')) || 0;
    var unique = card.getAttribute('data-unique') === '1';
    var isDom  = card.getAttribute('data-domain') === '1';
    var domBox = card.querySelector('.gnl-domain');

    card.querySelectorAll('.gnl-choice').forEach(function(btn){
      btn.addEventListener('click', function(){
        card.querySelectorAll('.gnl-choice').forEach(function(b){ b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        var yes = btn.getAttribute('data-val') === 'oui';
        card.classList.toggle('is-on', yes);

        if (yes){ state.options[slug] = { name:name, prix:prix, unique:unique }; }
        else    { delete state.options[slug]; if (isDom) state.domain = null; }

        if (isDom && domBox){
          domBox.hidden = !yes;
          if (!yes){ domBox.querySelector('.gnl-domain-results').innerHTML = ''; }
        }
        render();
      });
    });

    // -------- Recherche de domaine (proxy OVH) --------
    if (isDom && domBox){
      var input   = domBox.querySelector('.gnl-domain-input');
      var btn     = domBox.querySelector('.gnl-domain-btn');
      var results = domBox.querySelector('.gnl-domain-results');

      function search(){
        var q = (input.value || '').trim().toLowerCase();
        results.innerHTML = '';
        if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9-]+)+$/.test(q)){
          results.innerHTML = '<div class="gnl-note">Entrez un domaine valide avec son extension, par exemple <strong>mon-site.fr</strong>.</div>';
          return;
        }
        btn.disabled = true;
        results.innerHTML = '<div class="gnl-note"><span class="gnl-spin"></span> Vérification de « ' + q +' » auprès d\'OVH&hellip;</div>';

        fetch('/product-configuration?ajax=domain&domain=' + encodeURIComponent(q), { headers:{'Accept':'application/json'} })
          .then(function(r){ return r.json(); })
          .then(function(res){
            btn.disabled = false;
            results.innerHTML = '';
            if (!res.ok){
              if (res.error === 'ovh_not_configured'){
                manualFallback(q, 'La recherche automatique OVH n\'est pas encore active. Vous pouvez réserver ce domaine, notre équipe confirmera sa disponibilité.');
              } else if (res.error === 'invalid'){
                results.innerHTML = '<div class="gnl-note">Domaine invalide.</div>';
              } else {
                manualFallback(q, 'Impossible de vérifier la disponibilité pour le moment. Vous pouvez tout de même demander ce domaine.');
              }
              return;
            }
            if (!res.results || !res.results.length){
              manualFallback(q, 'Aucun résultat renvoyé. Vous pouvez demander ce domaine, nous le confirmerons.');
              return;
            }
            res.results.forEach(function(item){ results.appendChild(domRow(item)); });
          })
          .catch(function(){
            btn.disabled = false;
            manualFallback(q, 'Erreur réseau pendant la vérification. Vous pouvez demander ce domaine malgré tout.');
          });
      }

      function domRow(item){
        var row = document.createElement('div');
        row.className = 'gnl-dom ' + (item.orderable ? 'is-ok' : 'is-ko');
        var left = document.createElement('div');
        left.innerHTML = '<span class="gnl-dom-name">' + item.domain + '</span>';
        var right = document.createElement('div'); right.className = 'gnl-dom-right';
        if (item.orderable){
          var badge = document.createElement('span'); badge.className = 'gnl-badge ok'; badge.textContent = 'Disponible';
          right.appendChild(badge);
          if (item.price){ var pr = document.createElement('span'); pr.textContent = item.price; right.appendChild(pr); }
          var add = document.createElement('button'); add.type = 'button'; add.className = 'gnl-add'; add.textContent = 'Choisir';
          add.addEventListener('click', function(){
            results.querySelectorAll('.gnl-add').forEach(function(b){ b.classList.remove('is-added'); b.textContent = 'Choisir'; });
            add.classList.add('is-added'); add.textContent = 'Choisi ✓';
            state.domain = { name:item.domain, price:item.price || '', priceNum:item.priceNum || 0 };
            render();
          });
          right.appendChild(add);
        } else {
          var b2 = document.createElement('span'); b2.className = 'gnl-badge ko'; b2.textContent = 'Indisponible';
          right.appendChild(b2);
        }
        row.appendChild(left); row.appendChild(right);
        return row;
      }

      function manualFallback(q, msg){
        var note = document.createElement('div'); note.className = 'gnl-note'; note.textContent = msg;
        results.appendChild(note);
        var row = document.createElement('div'); row.className = 'gnl-dom is-ok';
        row.innerHTML = '<span class="gnl-dom-name">' + q + '</span>';
        var right = document.createElement('div'); right.className = 'gnl-dom-right';
        var add = document.createElement('button'); add.type = 'button'; add.className = 'gnl-add'; add.textContent = 'Demander';
        add.addEventListener('click', function(){
          add.classList.add('is-added'); add.textContent = 'Demandé ✓';
          state.domain = { name:q, price:'à confirmer', priceNum:0 };
          render();
        });
        right.appendChild(add); row.appendChild(right); results.appendChild(row);
      }

      btn.addEventListener('click', search);
      input.addEventListener('keydown', function(e){ if (e.key === 'Enter'){ e.preventDefault(); search(); } });
    }
  });

  render();
})();
