/* =====================================================================
   Rafraîchissement automatique de l'état (toutes les 60 s) via ?ajax=1
   ===================================================================== */
(function () {
    var initial = null; try { initial = JSON.parse(document.getElementById('gnlst-live').getAttribute('data-state')); } catch (e) {}

    var STATUS = {
        up:       ['Opérationnel', 'up'],
        degraded: ['Dégradé', 'degraded'],
        down:     ['Panne', 'down'],
        unknown:  ['Indéterminé', 'nd']
    };
    var OVERALL = {
        up:       ['Tous les systèmes sont opérationnels', 'up'],
        degraded: ['Certains systèmes sont en mode dégradé', 'degraded'],
        partial:  ['Incident en cours sur certains systèmes', 'down'],
        down:     ['Panne majeure en cours', 'down'],
        unknown:  ['État des systèmes indisponible', 'nd']
    };
    var CELL_CLS = {'-1':'nd','0':'up','1':'degraded','2':'down'};
    var CELL_LBL = {'-1':'Aucune donnée','0':'Opérationnel','1':'Dégradé','2':'Panne'};

    function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
    function nf(n){ return Number(n).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }

    var MOIS = ['janv.','févr.','mars','avr.','mai','juin','juil.','août','sept.','oct.','nov.','déc.'];
    function fmtDt(ts){
        if(!ts) return '';
        var d = new Date(ts*1000);
        function p(n){ return (n<10?'0':'')+n; }
        if (window.GNL_LANG && window.GNL_LANG !== 'fr') { try { return d.toLocaleString(window.GNL_LOCALE, {day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'}); } catch (e) {} }
        return d.getDate()+' '+MOIS[d.getMonth()]+' '+d.getFullYear()+' à '+p(d.getHours())+':'+p(d.getMinutes());
    }
    function maintHTML(list){
        if(!list || !list.length) return '';
        var html = '<section class="gnlst-card gnlst-maint"><h2 class="gnlst-card-title">Maintenances planifiées</h2>';
        list.forEach(function(m){
            var cls = m.state==='active' ? 'active' : 'scheduled';
            var lbl = m.state==='active' ? 'En cours' : 'Planifiée';
            var period = fmtDt(m.from) + (m.till ? ' → '+fmtDt(m.till) : '');
            html += '<div class="gnlst-maint-row"><div class="gnlst-maint-head">'
                 +  '<svg class="gnlst-maint-ic" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M22.7 19.3l-6.4-6.4a5.5 5.5 0 0 0-6.9-7L12.6 3 11 1.4 7.7 4.6a5.5 5.5 0 0 0 7 6.9l6.4 6.4a1 1 0 0 0 1.4 0l.2-.2a1 1 0 0 0 0-1.4z"/></svg>'
                 +  '<span class="gnlst-maint-name">'+esc(m.name)+'</span>'
                 +  '<span class="gnlst-maint-badge '+cls+'">'+lbl+'</span></div>';
            if (period)      html += '<div class="gnlst-maint-period">'+esc(period)+'</div>';
            if (m.desc)      html += '<div class="gnlst-maint-desc">'+esc(m.desc)+'</div>';
            if (m.impacted && m.impacted.length) html += '<div class="gnlst-maint-impacted"><span class="gnlst-maint-lbl">Service(s) impacté(s)</span>'+esc(m.impacted.join(' · '))+'</div>';
            if (m.targets && m.targets.length) html += '<div class="gnlst-maint-targets"><span class="gnlst-maint-lbl">Hôtes concernés</span>'+esc(m.targets.join(' · '))+'</div>';
            html += '</div>';
        });
        return html + '</section>';
    }

    function stripHTML(days){
        days = (days||[]).slice(-90);
        var html = '', pad = 90 - days.length, i;
        for(i=0;i<pad;i++) html += '<span class="gnlst-cell nd" title="Aucune donnée"></span>';
        days.forEach(function(d){
            var cls = CELL_CLS[String(d.code)] || 'nd';
            var lbl = CELL_LBL[String(d.code)] || 'Aucune donnée';
            html += '<span class="gnlst-cell '+cls+'" title="'+esc(d.date+' — '+lbl)+'"></span>';
        });
        return html;
    }

    function render(state){
        var om = OVERALL[state.overall] || OVERALL.unknown;
        var html = '';

        html += '<div class="gnlst-banner '+om[1]+'"><span class="gnlst-dot"></span>'
             +  '<div class="gnlst-banner-txt"><strong>'+esc(om[0])+'</strong>';
        if (state.stale) html += '<span class="gnlst-note">Données de secours — connexion à Zabbix momentanément indisponible.</span>';
        else if (!state.ok && state.error) html += '<span class="gnlst-note">'+esc(state.error)+'</span>';
        html += '</div></div>';

        html += maintHTML(state.maintenances);

        var comps = state.components || [];
        if (!comps.length){
            html += '<div class="gnlst-empty"><p>Aucun composant à afficher pour le moment.</p>'
                 +  (state.error ? '<p class="gnlst-note">'+esc(state.error)+'</p>' : '') + '</div>';
            document.getElementById('gnlst-live').innerHTML = html;
            return;
        }

        var groups = {}, order = [];
        comps.forEach(function(c){ if(!groups[c.group]){groups[c.group]=[];order.push(c.group);} groups[c.group].push(c); });

        order.forEach(function(g){
            html += '<section class="gnlst-card"><h2 class="gnlst-card-title">'+esc(g)+'</h2>';
            groups[g].forEach(function(c){
                var sm = STATUS[c.status] || STATUS.unknown;
                html += '<div class="gnlst-row"><div class="gnlst-row-head">'
                     +  '<span class="gnlst-dot '+sm[1]+'"></span>'
                     +  '<span class="gnlst-name">'+esc(c.name)+'</span>'
                     +  '<span class="gnlst-badge '+sm[1]+'">'+esc(sm[0])+'</span>';
                if (c.uptime !== null && c.uptime !== undefined)
                    html += '<span class="gnlst-uptime">'+nf(c.uptime)+' %</span>';
                html += '</div>';
                if (c.detail && c.status !== 'up')
                    html += '<div class="gnlst-detail">'+esc(c.detail)+'</div>';
                html += '<div class="gnlst-strip">'+stripHTML(c.history)+'</div>'
                     +  '<div class="gnlst-strip-legend"><span>il y a 90 jours</span><span>aujourd’hui</span></div>';
                html += '</div>';
            });
            html += '</section>';
        });

        document.getElementById('gnlst-live').innerHTML = html;
    }

    var lastUpdated = initial.updated ? initial.updated * 1000 : Date.now();
    function relative(ms){
        var s = Math.max(0, Math.round((Date.now()-ms)/1000));
        if (s < 5)  return "à l’instant";
        if (s < 60) return 'il y a {n} s'.replace('{n}', s);
        var m = Math.round(s/60);
        if (m < 60) return 'il y a {n} min'.replace('{n}', m);
        var h = Math.round(m/60);
        return 'il y a {n} h'.replace('{n}', h);
    }
    function tick(){
        var el = document.getElementById('gnlst-updated');
        if (el) el.textContent = 'Mis à jour ' + relative(lastUpdated);
    }

    function refresh(){
        fetch('?ajax=1', {headers:{'Accept':'application/json'}, cache:'no-store'})
            .then(function(r){ return r.json(); })
            .then(function(state){
                render(state);
                lastUpdated = state.updated ? state.updated * 1000 : Date.now();
                tick();
            })
            .catch(function(){ /* on garde l'affichage courant */ });
    }

    // Rendu initial identique + horloge + auto-refresh
    render(initial);
    tick();
    setInterval(tick, 1000);
    setInterval(refresh, 60000);
})();
