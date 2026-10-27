(function(){
  var sel = document.getElementById('gnl-abuse-cat'); if (!sel) return;
  function sync(){
    var anon = sel.value === 'csam';
    document.querySelectorAll('.gnl-idreq').forEach(function(i){ if (anon) i.removeAttribute('required'); else i.setAttribute('required',''); });
    document.querySelectorAll('.gnl-reqmark').forEach(function(s){ s.hidden = anon; });
    var n = document.getElementById('gnl-anon'); if (n) n.hidden = !anon;
  }
  sel.addEventListener('change', sync); sync();
})();

/* « Depuis quand ? » : pas de date future (heure locale du visiteur, mise à jour à chaque ouverture). */
(function(){
  var d = document.querySelector('input[name="depuis"]'); if (!d) return;
  function setMax(){
    var n = new Date(); n.setSeconds(0, 0);
    d.max = new Date(n.getTime() - n.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  }
  setMax();
  d.addEventListener('focus', setMax);
  d.addEventListener('click', setMax);
})();
