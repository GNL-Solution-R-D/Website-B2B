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
