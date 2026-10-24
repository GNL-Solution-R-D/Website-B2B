document.querySelectorAll('form[data-gnl-auth]').forEach(function(f){
  f.addEventListener('submit', function(){
    var b = f.querySelector('button[type=submit]');
    if(b){ b.disabled = true; b.dataset.lbl = b.textContent; b.textContent = 'Veuillez patienter…'; }
  });
});
