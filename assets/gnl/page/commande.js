(function(){
  var box = document.getElementById('gnl-freq');
  if(!box) return;
  var PLANS = {
    mensuel:     {months:1,  suffix:'/ mois',      per:'par mois'},
    trimestriel: {months:3,  suffix:'/ trimestre', per:'par trimestre'},
    annuel:      {months:12, suffix:'/ an',        per:'par an'}
  };
  var MOIS = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
  var monthly = parseFloat(box.getAttribute('data-monthly')) || 0;
  var once    = parseFloat(box.getAttribute('data-once')) || 0;
  function money(v){ return v.toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}) + '\u00a0€'; }
  function frDate(months){ var d = new Date(); d.setMonth(d.getMonth()+months); try { return d.toLocaleDateString(window.GNL_LOCALE || 'fr-FR', {day:'numeric', month:'long', year:'numeric'}); } catch (e) { return d.getDate()+' '+MOIS[d.getMonth()]+' '+d.getFullYear(); } }
  function set(id, txt){ var el = document.getElementById(id); if(el) el.textContent = txt; }
  function apply(key){
    var p = PLANS[key] || PLANS.mensuel;
    var period = monthly * p.months;
    set('gnl-freq-suffix', p.suffix);
    set('gnl-period-total', money(period));
    set('gnl-today-total',  money(period + once));
    set('gnl-recurr-note',  money(period) + ' ' + p.per);
    set('gnl-renew-date',   frDate(p.months));
    box.querySelectorAll('.gnl-freq-opt').forEach(function(l){
      var r = l.querySelector('input'); l.classList.toggle('is-active', !!(r && r.checked));
    });
  }
  box.querySelectorAll('input[name="frequence"]').forEach(function(r){
    r.addEventListener('change', function(){ if(r.checked) apply(r.value); });
  });
  var cur = box.querySelector('input[name="frequence"]:checked');
  if(cur) apply(cur.value);
})();

