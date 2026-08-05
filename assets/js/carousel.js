document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.gnl-carousel').forEach(function(car){
    var track=car.querySelector('.gnl-track'),nav=car.querySelector('.gnl-nav'),vp=car.querySelector('.gnl-viewport');
    if(!track||!nav||!vp)return;
    var prev=nav.querySelector('.gnl-prev'),next=nav.querySelector('.gnl-next');
    var index=0,GAP=30;
    function slides(){return track.children;}
    function slideW(){var s=slides()[0];return s?s.getBoundingClientRect().width:vp.clientWidth;}
    function perView(){var w=slideW();return Math.max(1,Math.round((vp.clientWidth+GAP)/(w+GAP)));}
    function maxIndex(){return Math.max(0,slides().length-perView());}
    function apply(){
      var mi=maxIndex();
      if(index>mi)index=mi; if(index<0)index=0;
      track.style.transform='translateX(-'+(index*(slideW()+GAP))+'px)';
      if(mi<=0){nav.setAttribute('hidden','');}else{nav.removeAttribute('hidden');}
      prev.setAttribute('aria-disabled',index<=0?'true':'false');
      next.setAttribute('aria-disabled',index>=mi?'true':'false');
    }
    prev.addEventListener('click',function(e){e.preventDefault();index--;apply();});
    next.addEventListener('click',function(e){e.preventDefault();index++;apply();});
    [prev,next].forEach(function(b){b.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();b.click();}});});
    window.addEventListener('resize',function(){index=0;apply();});
    window.addEventListener('load',apply);
    apply();
  });
});