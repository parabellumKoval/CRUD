<script>
(function() {

  function debounce(fn, ms){ let t; return function(){ clearTimeout(t); t=setTimeout(()=>fn.apply(this, arguments), ms); } }
  function csrf(){ return $('meta[name="csrf-token"]').attr('content') || '{{ csrf_token() }}'; }

  // --- ВСПОМОГАТЕЛЬНЫЕ ---
  function extractIdFromRow(row){
    if (row.id) return row.id;
    var v0 = row['0'] || '';
    var m = String(v0).match(/data-entry-id="(\d+)"/); // details кнопка
    if (m) return m[1];
    var v1 = row['1'] || '';
    m = String(v1).match(/data-primary-key-value="(\d+)"/); // bulk checkbox
    if (m) return m[1];
    return undefined;
  }
  function hasDetailsColumn(row){
    return /details-row-button/.test(String(row['0'] || ''));
  }
  function hasBulkColumn(row){
    return /crud_bulk_actions_row_checkbox/.test(String(row['1'] || ''));
  }
  function numericKeysOf(row){
    return Object.keys(row).filter(k => /^\d+$/.test(k)).map(Number).sort((a,b)=>a-b);
  }

  function initTreeContainer($wrap){
    if(!$wrap.length) return;
    if($wrap.attr('data-bp-inited') === '1') return;
    $wrap.attr('data-bp-inited', '1');

    const parentId    = $wrap.data('parent-id');
    const tableId     = $wrap.data('table-id');
    const searchUrl   = $wrap.data('search-url');
    const detailsBase = $wrap.data('details-base');
    const columns     = $wrap.data('columns') || []; // только для заголовков/кол-ва
    const length      = parseInt($wrap.data('page-length') || 10, 10);

    const $table  = $('#'+tableId);
    const $tbody  = $table.find('tbody');
    const $pager  = $table.find('.bp-tree-pager');
    const $search = $table.closest('.bp-tree-children').find('.bp-tree-search');


    let state = {
      start: 0,
      length: length,
      orderCol: 0,        // индекс среди ВИДИМЫХ колонок вложенной таблицы (без toggle)
      orderDir: 'asc',
      term: '',
      shift: null         // фактическое смещение индексов на сервере (details/bulk)
    };

    function buildPager(total, start, length){
      const curr = Math.floor(start/length)+1;
      const pages = Math.max(1, Math.ceil(total/length));
      let html = '<ul class="pagination pagination-sm mb-0">';
      function li(disabled, active, label, pageStart){
        const cls = 'page-item'+(disabled?' disabled':'')+(active?' active':'');
        return `<li class="${cls}"><a class="page-link" href="#" data-start="${pageStart}">${label}</a></li>`;
      }
      html += li(curr<=1,false,'<',0);
      for (let p=Math.max(1,curr-2); p<=Math.min(pages, curr+2); p++){
        html += li(false, p===curr, p, (p-1)*length);
      }
      html += li(curr>=pages,false,'>',(pages-1)*length);
      html += '</ul>';
      return html;
    }

    function load(){
      const postData = {
        _token: csrf(),
        parent_id: parentId,
        start: state.start,
        length: state.length,
        // DataTables совместимые поля: ПРИМЕНЯЕМ СМЕЩЕНИЕ
        'order[0][column]': (state.orderCol + (state.shift ?? 0)),
        'order[0][dir]':    state.orderDir,
        'search[value]':    state.term,
        'search[regex]':    false
      };

      $tbody.html('<tr class="text-muted"><td colspan="'+(2+columns.length)+'"><span class="spinner-border spinner-border-sm me-1"></span>Загрузка…</td></tr>');

      $.post(searchUrl, postData)
        .done(function(json){
          const rows = json.data || [];
          const total = json.recordsFiltered ?? json.recordsTotal ?? rows.length;

          if(!rows.length){
            $tbody.html('<tr class="text-muted"><td colspan="'+(2+columns.length)+'">Нет данных</td></tr>');
            $pager.html('');
            return;
          }

          // --- Определяем СМЕЩЕНИЕ: сколько служебных колонок слева отдаёт сервер
          if (state.shift === null) {
            const sample = rows[0];
            let shift = 0;
            if (hasDetailsColumn(sample)) shift += 1;  // "0" колонка
            if (hasBulkColumn(sample))    shift += 1;  // "1" колонка
            state.shift = shift;
          }

          const trs = rows.map(function(row){
            const id = extractIdFromRow(row);
            const nKeys = numericKeysOf(row);
            if (!nKeys.length) return '';

            // Берём диапазон «полезных» колонок: всё между служебными слева и actions справа
            const firstDataIdx = state.shift || 0;         // обычно 2
            const lastIdx      = nKeys[nKeys.length-1];    // обычно actions
            const dataIdxs     = nKeys.filter(i => i >= firstDataIdx && i < lastIdx);

            let html = '<tr data-id="'+(id ?? '')+'">';

            // 1) toggle-колонка
            if (row.has_children) {
              html += '<td class="align-middle"><button type="button" class="btn btn-link p-0 bp-tree-toggle" title="Показать дочерние"><i class="la la-plus-square-o la-lg"></i></button></td>';
            } else {
              html += '<td></td>';
            }

            // 2) сами данные
            dataIdxs.forEach(function(i){
              html += '<td>'+(row[i] ?? '')+'</td>';
            });

            // 3) actions (последняя числовая)
            html += '<td>'+(row[lastIdx] ?? '')+'</td>';
            html += '</tr>';
            return html;
          }).join('');

          $tbody.html(trs);
          $pager.html(buildPager(total, state.start, state.length));
        })
        .fail(function(xhr){
          const msg = 'Ошибка загрузки ('+xhr.status+'). ' + ((xhr.status===419||xhr.status===403)?'Проверьте CSRF/сессию.':'');
          $tbody.html('<tr><td colspan="'+(2+columns.length)+'" class="text-danger">'+msg+'</td></tr>');
          $pager.html('');
        });
    }

    // сортировка по клику на заголовок
    $table.find('th.bp-tree-sort').css('cursor','pointer').off('click').on('click', function(){
      const idx = parseInt($(this).data('col-idx'), 10) || 0;
      if (state.orderCol === idx) {
        state.orderDir = (state.orderDir === 'asc') ? 'desc' : 'asc';
      } else {
        state.orderCol = idx;
        state.orderDir = 'asc';
      }
      state.start = 0;
      load();
    });

    // поиск (debounce)
    $search.off('input').on('input', debounce(function(){
      state.term = $(this).val();
      state.start = 0;
      load();
    }, 300));

    // пагинация
    $pager.off('click', 'a.page-link').on('click', 'a.page-link', function(e){
      e.preventDefault();
      const nextStart = parseInt($(this).data('start'), 10);
      if (isNaN(nextStart)) return;
      state.start = nextStart;
      load();
    });

    // раскрытие следующего уровня
        $table.off('click', '.bp-tree-toggle').on('click', '.bp-tree-toggle', function(){
      const $btn = $(this);
      const $tr  = $btn.closest('tr');
      const id   = $tr.data('id');
      const opened = $tr.data('bp-opened') === 1;

      if (opened) {
        $tr.data('bp-opened', 0);
        $btn.find('i').removeClass('la-minus-square-o').addClass('la-plus-square-o');
        $tr.next('.bp-tree-details').remove();
        return;
      }

      $tr.data('bp-opened', 1);
      $btn.find('i').removeClass('la-plus-square-o').addClass('la-minus-square-o');

      const detailsUrl = detailsBase + '/' + id + '/details';
      const totalColumns = $tr.children('td').length;
      // Remove the extra td and use full colspan
      const $detailsTr = $('<tr class="bp-tree-details"><td colspan="' + totalColumns + '"><div class="p-2"><span class="spinner-border spinner-border-sm me-1"></span>Загрузка…</div></td></tr>');
      $detailsTr.insertAfter($tr);

      $.get(detailsUrl)
        .done(function(html){
          const $content = $detailsTr.find('> td > div');
          $content.html(html);
          $content.find('.bp-tree-children[data-bp-inited="0"]').each(function(){ initTreeContainer($(this)); });
        })
        .fail(function(xhr){
          $detailsTr.find('> td > div').html('<div class="text-danger p-2">Ошибка загрузки ('+xhr.status+')</div>');
        });
    });

    // первичная загрузка
    load();
  }

  // автоинициализация контейнеров
  $('.bp-tree-children[data-bp-inited="0"]').each(function(){ initTreeContainer($(this)); });
  const observer = new MutationObserver(function(mutations){
    mutations.forEach(function(m){
      $(m.addedNodes).each(function(){
        if (this.nodeType !== 1) return;
        const $el = $(this);
        $el.find('.bp-tree-children[data-bp-inited="0"]').each(function(){ initTreeContainer($(this)); });
        if ($el.is('.bp-tree-children[data-bp-inited="0"]')) { initTreeContainer($el); }
      });
    });
  });
  observer.observe(document.body, { childList:true, subtree:true });
})();
</script>