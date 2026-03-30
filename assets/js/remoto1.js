document.addEventListener('DOMContentLoaded', function(){
    console.log('remoto1.js: loaded and initializing');
    // Date presets
    function formatYMD(d){
        function pad(n){return n<10?'0'+n:''+n}
        return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());
    }
    var dateFrom = document.getElementById('date_from');
    var dateTo = document.getElementById('date_to');
    // Initialize flatpickr instances (if available) and attach to elements
    try {
        if (typeof flatpickr !== 'undefined') {
            if (dateFrom) dateFrom._flatpickr = flatpickr(dateFrom, { altInput: true, altFormat: 'd/m/Y', dateFormat: 'Y-m-d', locale: 'es', allowInput: true });
            if (dateTo) dateTo._flatpickr = flatpickr(dateTo, { altInput: true, altFormat: 'd/m/Y', dateFormat: 'Y-m-d', locale: 'es', allowInput: true });
        }
    } catch (e) {
        console.warn('flatpickr init failed (assets)', e);
    }

    function setRange(fromDate, toDate){
        if(!dateFrom || !dateTo) return;
        if(dateFrom._flatpickr){ dateFrom._flatpickr.setDate(fromDate, true); } else { dateFrom.value = formatYMD(fromDate); }
        if(dateTo._flatpickr){ dateTo._flatpickr.setDate(toDate, true); } else { dateTo.value = formatYMD(toDate); }
    }
    function clearRange(){ if(dateFrom && dateFrom._flatpickr) { dateFrom._flatpickr.clear(); dateTo._flatpickr.clear(); } else if(dateFrom && dateTo) { dateFrom.value=''; dateTo.value=''; } }
    var todayBtn = document.getElementById('preset-today');
    var yesterBtn = document.getElementById('preset-yesterday');
    var d7Btn = document.getElementById('preset-7');
    var d30Btn = document.getElementById('preset-30');
    var clearBtn = document.getElementById('preset-clear');
    if(todayBtn) todayBtn.addEventListener('click', function(){ var t=new Date(); setRange(t,t); });
    if(yesterBtn) yesterBtn.addEventListener('click', function(){ var t=new Date(); t.setDate(t.getDate()-1); setRange(t,t); });
    if(d7Btn) d7Btn.addEventListener('click', function(){ var t=new Date(); var from=new Date(); from.setDate(t.getDate()-6); setRange(from,t); });
    if(d30Btn) d30Btn.addEventListener('click', function(){ var t=new Date(); var from=new Date(); from.setDate(t.getDate()-29); setRange(from,t); });
    if(clearBtn) clearBtn.addEventListener('click', function(){ clearRange(); });

    // Modality search & quick buttons
    var modFilter = document.getElementById('modality-filter');
    var modOptions = document.querySelectorAll('#modality-options .modality-option');
    console.log('remoto1.js: modality DOM references', {modFilter: !!modFilter, modOptions: (modOptions && modOptions.length) || 0, modalityToggle: !!document.getElementById('modality-toggle')});
    var modSelectAll = document.getElementById('modality-select-all');
    var modClear = document.getElementById('modality-clear');
    console.log('remoto1.js: modality elements found', { modFilter: !!modFilter, modOptionsCount: modOptions.length, modSelectAll: !!modSelectAll, modClear: !!modClear });

    window.updateToggleText = function(){
        var toggle = document.getElementById('modality-toggle');
        if(!toggle) return;
        var checkedBtns = document.querySelectorAll('#modality-options .modality-item.selected');
        var texts = Array.from(checkedBtns).map(function(b){return b.getAttribute('data-value');});
        if(texts.length === 0){
            toggle.innerHTML = 'Seleccionar modalidades <i class="bi bi-chevron-down ms-2"></i>';
        } else {
            var displayText = '';
            if(texts.length <= 3) displayText = texts.join(', ');
            else displayText = texts.slice(0,3).join(', ') + '...';
            // show count
            displayText = displayText + ' (' + texts.length + ')';
            var esc = document.createElement('div'); esc.textContent = displayText; var safe = esc.innerHTML;
            toggle.innerHTML = safe + ' <i class="bi bi-chevron-down ms-2"></i>';
        }
        // set tooltip title to full list for hover preview
        try{
            var full = texts.join(', ');
            if(full){
                toggle.setAttribute('title', full);
                // re-init tooltip
                var existing = bootstrap.Tooltip.getInstance(toggle);
                if(existing) existing.dispose();
                new bootstrap.Tooltip(toggle, {placement: 'bottom'});
            } else {
                toggle.removeAttribute('title');
            }
        }catch(e){/* ignore */}
    };

    if(modFilter){
        modFilter.addEventListener('input', function(e){
            var q = (this.value || '').toLowerCase();
            Array.from(modOptions || []).forEach(function(opt){
                var label = (opt.textContent || '').toLowerCase();
                opt.style.display = label.indexOf(q) === -1 ? 'none' : '';
            });
        });
    }
    // helper to find the search form (closest form ancestor)
    var modalToggleEl = document.getElementById('modality-toggle');
    var searchForm = null;
    try {
        searchForm = modalToggleEl ? modalToggleEl.closest('form') : document.querySelector('form[method="get"]');
    } catch (e) {
        console.warn('remoto1.js: error finding closest form for modality-toggle', e);
        searchForm = document.querySelector('form[method="get"]');
    }
    if(!searchForm) console.warn('remoto1.js: search form not found; hidden inputs will not be added/removed');

    function addHiddenMod(value){
        if(!searchForm) { console.warn('addHiddenMod: no searchForm to append to, value=', value); return; }
        // avoid duplicates by checking existing inputs
        var existingInputs = Array.from(searchForm.querySelectorAll('input[name="modalities[]"]'));
        for(var i=0;i<existingInputs.length;i++){ if(existingInputs[i].value === value) return; }
        var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'modalities[]'; inp.value = value;
        searchForm.appendChild(inp);
    }
    function removeHiddenMod(value){
        if(!searchForm) { console.warn('removeHiddenMod: no searchForm available, value=', value); return; }
        var existingInputs = Array.from(searchForm.querySelectorAll('input[name="modalities[]"]'));
        existingInputs.forEach(function(n){ if(n.value === value) n.parentNode.removeChild(n); });
    }

    if(modSelectAll){
        modSelectAll.addEventListener('click', function(){
            document.querySelectorAll('#modality-options .modality-item').forEach(function(btn){
                btn.classList.add('selected');
                addHiddenMod(btn.getAttribute('data-value'));
            });
            updateToggleText();
        });
    }
    if(modClear){
        modClear.addEventListener('click', function(){
            document.querySelectorAll('#modality-options .modality-item.selected').forEach(function(btn){
                btn.classList.remove('selected');
                removeHiddenMod(btn.getAttribute('data-value'));
            });
            updateToggleText();
        });
    }

    // View all modalities in a modal
    var viewAllBtn = document.getElementById('modality-view-all');
    if(viewAllBtn){
        viewAllBtn.addEventListener('click', function(){
            var allBtns = Array.from(document.querySelectorAll('#modality-options .modality-item'));
            var list = allBtns.map(function(b){ return b.getAttribute('data-value'); });
            var modal = document.getElementById('modal-modalities-full');
            if(modal){
                var body = modal.querySelector('.modal-body');
                if(body){
                    body.innerHTML = '';
                    var ul = document.createElement('ul'); ul.className = 'list-unstyled mb-0';
                    allBtns.forEach(function(b){
                        var li = document.createElement('li');
                        var txt = b.getAttribute('data-value');
                        var span = document.createElement('span'); span.textContent = txt;
                        if(b.classList.contains('selected')){
                            span.className = 'badge bg-primary text-white ms-2';
                            li.innerHTML = '<strong>' + txt + '</strong> ';
                            // show selected marker
                            li.innerHTML = '<span>' + txt + '</span>' + ' <span class="badge bg-success ms-2">Seleccionado</span>';
                        } else {
                            li.appendChild(span);
                        }
                        ul.appendChild(li);
                    });
                    body.appendChild(ul);
                }
                var bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            }
        });
    }

    // attach click handlers to each modality item to toggle selection
    var modalityItems = Array.from(document.querySelectorAll('#modality-options .modality-item'));
    console.log('remoto1.js: modalityItems count', modalityItems.length);
    modalityItems.forEach(function(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            var val = this.getAttribute('data-value');
            console.log('remoto1.js: modality item clicked', val);
            if(this.classList.contains('selected')){
                this.classList.remove('selected');
                removeHiddenMod(val);
            } else {
                this.classList.add('selected');
                addHiddenMod(val);
            }
            updateToggleText();
        });
    });

    // Per-page dropdown: submit value when selecting an option
    var perPageForm = document.getElementById('per-page-form');
    if(perPageForm){
        document.querySelectorAll('.per-page-option').forEach(function(btn){
            btn.addEventListener('click', function(e){
                e.preventDefault();
                var v = this.getAttribute('data-value');
                var input = document.getElementById('per_page_input');
                if(input) input.value = v;
                var label = perPageForm.querySelector('.per-page-label');
                if(label) label.textContent = v;
                perPageForm.submit();
            });
        });
    }

    // Ensure per-page dropdown is not clipped: append menu to body when opened (Chrome clipping fix)
    document.querySelectorAll('.per-page-btn').forEach(function(btn){
        var btnGroup = btn.closest('.btn-group');
        if(!btnGroup) return;
        var menu = btnGroup.querySelector('.dropdown-menu');
        if(!menu) return;

        btn.addEventListener('shown.bs.dropdown', function(){
            // compute position
            var rect = btn.getBoundingClientRect();
            // store original parent for restore
            menu._origParent = menu.parentNode;
            menu._origNext = menu.nextSibling;
            document.body.appendChild(menu);
            menu.style.position = 'absolute';
            menu.style.left = Math.max(8, rect.left) + 'px';
            menu.style.top = (rect.bottom + window.scrollY) + 'px';
            menu.style.minWidth = 'auto';
            menu.style.zIndex = 3000;
            menu.classList.add('per-page-floating');
        });

        btn.addEventListener('hidden.bs.dropdown', function(){
            if(menu._origParent){
                // restore
                if(menu._origNext){
                    menu._origParent.insertBefore(menu, menu._origNext);
                } else {
                    menu._origParent.appendChild(menu);
                }
                menu.style.position = '';
                menu.style.left = '';
                menu.style.top = '';
                menu.style.zIndex = '';
                menu.classList.remove('per-page-floating');
                delete menu._origParent;
                delete menu._origNext;
            }
        });
    });

    // initialize toggle text based on initial selections
    if(typeof updateToggleText === 'function') updateToggleText();
    console.log('remoto1.js: initialization complete');

});
