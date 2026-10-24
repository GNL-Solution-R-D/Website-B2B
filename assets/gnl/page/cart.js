(function(){
  var cb = document.getElementById('gnl-cart-cgv'), pay = document.getElementById('gnl-cart-pay'), hint = document.getElementById('gnl-cart-cgv-hint');
  if (!cb || !pay) return;
  function sync(){ pay.classList.toggle('is-ready', cb.checked); pay.setAttribute('aria-disabled', cb.checked ? 'false' : 'true'); if (cb.checked && hint) hint.hidden = true; }
  cb.addEventListener('change', sync);
  pay.addEventListener('click', function(e){ if (!cb.checked){ e.preventDefault(); if (hint) hint.hidden = false; cb.focus(); } });
  sync();
})();
