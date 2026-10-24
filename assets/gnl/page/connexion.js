(function(){
  var t = document.getElementById('gnl-forgot-toggle');
  var f = document.getElementById('gnl-forgot-form');
  if(t && f){ t.addEventListener('click', function(e){
    e.preventDefault();
    var open = !f.hidden;
    f.hidden = open;
    t.setAttribute('aria-expanded', open ? 'false' : 'true');
    if(!open){ var i=f.querySelector('input[type=email]'); var u=document.getElementById('username');
      if(i){ if(u && u.value && u.value.indexOf('@')>0 && !i.value) i.value=u.value; i.focus(); } }
  }); }
})();

