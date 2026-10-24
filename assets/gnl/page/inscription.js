(function(){
  var auth = document.getElementById('gnl-auth');
  var radios = document.querySelectorAll('input[name="profil_type"]');
  var card = document.getElementById('org-panel');
  var reqs = card ? card.querySelectorAll('[data-req]') : [];
  var tvaChk = document.getElementById('tva_assujetti');
  var tvaBox = document.getElementById('tva-field');
  var tvaIn  = document.getElementById('org_tva');
  var siret  = document.getElementById('org_siret');
  var siren  = document.getElementById('siren_display');
  var statusEl = document.getElementById('siret-status');
  var lastLookup = ''; var lookupTimer = null;

  function profil(){ for (var i = 0; i < radios.length; i++) if (radios[i].checked) return radios[i].value; return 'particulier'; }
  function group(d, sizes){
    var out = [], i = 0;
    for (var s = 0; s < sizes.length && i < d.length; s++){ out.push(d.substr(i, sizes[s])); i += sizes[s]; }
    return out.join(' ');
  }
  function setReq(el, on){ if(!el) return; if(on) el.setAttribute('required',''); else el.removeAttribute('required'); }
  function syncTva(){
    var on = profil() === 'entreprise' && tvaChk && tvaChk.checked;
    if (tvaBox) tvaBox.hidden = !on;
    setReq(tvaIn, on);
  }
  function syncCard(){
    var p = profil(), on = p === 'entreprise';
    var ne = document.getElementById('note-etu'), no = document.getElementById('note-org');
    if (ne) ne.hidden = p !== 'etudiant';
    if (no) no.hidden = !on;
    if (auth) auth.classList.toggle('has-org', on);
    for (var i = 0; i < reqs.length; i++) setReq(reqs[i], on);
    syncTva();
  }
  function setStatus(txt, cls){
    if (!statusEl) return;
    statusEl.textContent = txt || '';
    statusEl.style.color = cls === 'ok' ? '#1f7a4a' : (cls === 'err' ? '#b42318' : '');
  }
  function fillField(id, val){ var el = document.getElementById(id); if (el && val != null && val !== '') el.value = val; }
  function lookup(siretDigits){
    if (siretDigits === lastLookup) return;
    lastLookup = siretDigits;
    setStatus('Recherche de l\'entreprise…', '');
    fetch('/entreprise-lookup?siret=' + encodeURIComponent(siretDigits), { headers: { 'Accept': 'application/json' } })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok || !j.data){ setStatus('Aucune entreprise trouvée pour ce SIRET.', 'err'); return; }
        var d = j.data;
        fillField('org_nom_commercial', d.nom_commercial);
        fillField('org_raison', d.raison);
        fillField('org_entite_legal', d.entite_legal);
        fillField('org_voie_nbr', d.voie_nbr);
        fillField('org_voie_name', d.voie_name);
        fillField('org_cp', d.cp);
        fillField('org_commune', d.commune);
        fillField('org_pays', d.pays || 'France');
        if (tvaIn && d.tva) tvaIn.value = d.tva;
        var name = d.nom_commercial || d.raison || 'Entreprise trouvée';
        if (d.etat === 'F') setStatus('⚠ ' + name + ' — établissement fermé, vérifiez le SIRET', 'err');
        else setStatus('✓ ' + name, 'ok');
      })
      .catch(function(){ setStatus('Recherche indisponible pour le moment.', 'err'); });
  }
  function fmtSiret(){
    if (!siret) return;
    var d = siret.value.replace(/\D/g, '').slice(0, 14);
    siret.value = group(d, [3,3,3,5]);
    if (siren) siren.value = group(d.slice(0, 9), [3,3,3]);
    if (d.length === 14){
      clearTimeout(lookupTimer);
      lookupTimer = setTimeout(function(){ lookup(d); }, 400);
    } else { lastLookup = ''; setStatus('', ''); }
  }
  for (var i = 0; i < radios.length; i++) radios[i].addEventListener('change', syncCard);
  if (tvaChk) tvaChk.addEventListener('change', syncTva);
  if (siret)  siret.addEventListener('input', fmtSiret);
  syncCard(); fmtSiret();
})();
