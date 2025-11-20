 @php
    // as it is possible that we can be redirected with persistent table we save the alerts in a variable
    // and flush them from session, so we will get them later from localStorage.
    $backpack_alerts = \Alert::getMessages();
    \Alert::flush();

    $rowStackColspan = count($crud->columns());
    if ($crud->buttons()->where('stack', 'line')->count()) {
        $rowStackColspan++;
    }
    $rowStackColspan = max(1, $rowStackColspan);

    $rowStackLayouts = [
        'top' => $crud->getRowStackLayout('top'),
        'bottom' => $crud->getRowStackLayout('bottom'),
    ];

 @endphp

  {{-- DATA TABLES SCRIPT --}}
  <script type="text/javascript" src="{{ asset('packages/datatables.net/js/jquery.dataTables.min.js') }}"></script>
  <script type="text/javascript" src="{{ asset('packages/datatables.net-bs4/js/dataTables.bootstrap4.min.js') }}"></script>
  <script type="text/javascript" src="{{ asset('packages/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
  <script type="text/javascript" src="{{ asset('packages/datatables.net-responsive-bs4/js/responsive.bootstrap4.min.js') }}"></script>
  <script type="text/javascript" src="{{ asset('packages/datatables.net-fixedheader/js/dataTables.fixedHeader.min.js') }}"></script>
  <script type="text/javascript" src="{{ asset('packages/datatables.net-fixedheader-bs4/js/fixedHeader.bootstrap4.min.js') }}"></script>

  <script>
    // here we will check if the cached dataTables paginator length is conformable with current paginator settings.
    // datatables caches the ajax responses with pageLength in LocalStorage so when changing this
    // settings in controller users get unexpected results. To avoid that we will reset
    // the table cache when both lengths don't match.
    let $dtCachedInfo = JSON.parse(localStorage.getItem('DataTables_crudTable_/{{$crud->getRoute()}}'))
        ? JSON.parse(localStorage.getItem('DataTables_crudTable_/{{$crud->getRoute()}}')) : [];
    var $dtDefaultPageLength = {{ $crud->getDefaultPageLength() }};
    let $dtStoredPageLength = localStorage.getItem('DataTables_crudTable_/{{$crud->getRoute()}}_pageLength');

    if(!$dtStoredPageLength && $dtCachedInfo.length !== 0 && $dtCachedInfo.length !== $dtDefaultPageLength) {
        localStorage.removeItem('DataTables_crudTable_/{{$crud->getRoute()}}');
    }

    // in this page we allways pass the alerts to localStorage because we can be redirected with
    // persistent table, and this way we guarantee non-duplicate alerts.
    $oldAlerts = JSON.parse(localStorage.getItem('backpack_alerts'))
        ? JSON.parse(localStorage.getItem('backpack_alerts')) : {};

    $newAlerts = @json($backpack_alerts);

    Object.entries($newAlerts).forEach(function(type) {
        if(typeof $oldAlerts[type[0]] !== 'undefined') {
            type[1].forEach(function(msg) {
                $oldAlerts[type[0]].push(msg);
            });
        } else {
            $oldAlerts[type[0]] = type[1];
        }
    });

    // always store the alerts in localStorage for this page
    localStorage.setItem('backpack_alerts', JSON.stringify($oldAlerts));

    @if ($crud->getPersistentTable())

        var saved_list_url = localStorage.getItem('{{ Str::slug($crud->getRoute()) }}_list_url');

        //check if saved url has any parameter or is empty after clearing filters.
        if (saved_list_url && saved_list_url.indexOf('?') < 1) {
            var saved_list_url = false;
        } else {
            var persistentUrl = saved_list_url+'&persistent-table=true';
        }

    var arr = window.location.href.split('?');
    // check if url has parameters.
    if (arr.length > 1 && arr[1] !== '') {
        // IT HAS! Check if it is our own persistence redirect.
        if (window.location.search.indexOf('persistent-table=true') < 1) {
            // IF NOT: we don't want to redirect the user.
            saved_list_url = false;
        }
    }

    @if($crud->getPersistentTableDuration())
        var saved_list_url_time = localStorage.getItem('{{ Str::slug($crud->getRoute()) }}_list_url_time');

        if (saved_list_url_time) {
            var $current_date = new Date();
            var $saved_time = new Date(parseInt(saved_list_url_time));
            $saved_time.setMinutes($saved_time.getMinutes() + {{$crud->getPersistentTableDuration()}});

            // if the save time is not expired we force the filter redirection.
            if($saved_time > $current_date) {
                if (saved_list_url && persistentUrl!=window.location.href) {
                    window.location.href = persistentUrl;
                }
            } else {
                // persistent table expired, let's not redirect the user
                saved_list_url = false;
            }
        }

    @endif
        if (saved_list_url && persistentUrl!=window.location.href) {
            // finally redirect the user.
            window.location.href = persistentUrl;
        }
    @endif

    window.crud = {
      exportButtons: JSON.parse('{!! json_encode($crud->get('list.export_buttons')) !!}'),
      functionsToRunOnDataTablesDrawEvent: [],
      columnStyleHashes: {},
      ensureColumnStyles: function (payload) {
          if (!payload) {
              return;
          }

          var entries = Array.isArray(payload) ? payload : [payload];
          var self = this;

          entries.forEach(function (item) {
              if (!item) {
                  return;
              }

              var content = item.content || item;
              if (!content) {
                  return;
              }

              var hash = item.hash || content;
              if (hash && self.columnStyleHashes[hash]) {
                  return;
              }

              $('head').append(content);

              if (hash) {
                  self.columnStyleHashes[hash] = true;
              }
          });
      },
      addFunctionToDataTablesDrawEventQueue: function (functionName) {
          if (this.functionsToRunOnDataTablesDrawEvent.indexOf(functionName) == -1) {
          this.functionsToRunOnDataTablesDrawEvent.push(functionName);
        }
      },
      responsiveToggle: function(dt) {
          $(dt.table().header()).find('th').toggleClass('all');
          dt.responsive.rebuild();
          dt.responsive.recalc();
      },
      executeFunctionByName: function(str, args) {
        var arr = str.split('.');
        var fn = window[ arr[0] ];

        for (var i = 1; i < arr.length; i++)
        { fn = fn[ arr[i] ]; }
        fn.apply(window, args);
      },
      updateUrl : function (new_url) {
        url_start = "{{ url($crud->route) }}";
        url_end = new_url.replace(url_start, '');
        url_end = url_end.replace('/search', '');
        new_url = url_start + url_end;

        window.history.pushState({}, '', new_url);
        localStorage.setItem('{{ Str::slug($crud->getRoute()) }}_list_url', new_url);
      },
      dataTableConfiguration: {

        @if ($crud->getResponsiveTable())
        responsive: {
            details: {
                display: $.fn.dataTable.Responsive.display.modal( {
                    header: function ( row ) {
                        // show the content of the first column
                        // as the modal header
                        // var data = row.data();
                        // return data[0];
                        return '';
                    }
                } ),
                renderer: function ( api, rowIdx, columns ) {

                  var data = $.map( columns, function ( col, i ) {
                      var columnHeading = crud.table.columns().header()[col.columnIndex];

                      // hide columns that have VisibleInModal false
                      if ($(columnHeading).attr('data-visible-in-modal') == 'false') {
                        return '';
                      }

                      return '<tr data-dt-row="'+col.rowIndex+'" data-dt-column="'+col.columnIndex+'">'+
                                '<td style="vertical-align:top; border:none;"><strong>'+col.title.trim()+':'+'<strong></td> '+
                                '<td style="padding-left:10px;padding-bottom:10px; border:none;">'+col.data+'</td>'+
                              '</tr>';
                  } ).join('');

                  return data ?
                      $('<table class="table table-striped mb-0">').append( '<tbody>' + data + '</tbody>' ) :
                      false;
                },
            }
        },
        fixedHeader: true,
        @else
        responsive: false,
        scrollX: true,
        @endif

        @if ($crud->getPersistentTable())
        stateSave: true,
        /*
            if developer forced field into table 'visibleInTable => true' we make sure when saving datatables state
            that it reflects the developer decision.
        */

        stateSaveParams: function(settings, data) {

            localStorage.setItem('{{ Str::slug($crud->getRoute()) }}_list_url_time', data.time);

            data.columns.forEach(function(item, index) {
                var columnHeading = crud.table.columns().header()[index];
                if ($(columnHeading).attr('data-visible-in-table') == 'true') {
                    return item.visible = true;
                }
            });
        },
        @if($crud->getPersistentTableDuration())
        stateLoadParams: function(settings, data) {
            var $saved_time = new Date(data.time);
            var $current_date = new Date();

            $saved_time.setMinutes($saved_time.getMinutes() + {{$crud->getPersistentTableDuration()}});

            //if the save time as expired we force datatabled to clear localStorage
            if($saved_time < $current_date) {
                if (localStorage.getItem('{{ Str::slug($crud->getRoute())}}_list_url')) {
                    localStorage.removeItem('{{ Str::slug($crud->getRoute()) }}_list_url');
                }
                if (localStorage.getItem('{{ Str::slug($crud->getRoute())}}_list_url_time')) {
                    localStorage.removeItem('{{ Str::slug($crud->getRoute()) }}_list_url_time');
                }
               return false;
            }
        },
        @endif
        @endif
        autoWidth: false,
        pageLength: $dtDefaultPageLength,
        lengthMenu: @json($crud->getPageLengthMenu()),
        /* Disable initial sort */
        aaSorting: [],
        language: {
              "emptyTable":     "{{ trans('backpack::crud.emptyTable') }}",
              "info":           "{{ trans('backpack::crud.info') }}",
              "infoEmpty":      "{{ trans('backpack::crud.infoEmpty') }}",
              "infoFiltered":   "{{ trans('backpack::crud.infoFiltered') }}",
              "infoPostFix":    "{{ trans('backpack::crud.infoPostFix') }}",
              "thousands":      "{{ trans('backpack::crud.thousands') }}",
              "lengthMenu":     "{{ trans('backpack::crud.lengthMenu') }}",
              "loadingRecords": "{{ trans('backpack::crud.loadingRecords') }}",
              "processing":     "<img src='{{ asset('packages/backpack/crud/img/ajax-loader.gif') }}' alt='{{ trans('backpack::crud.processing') }}'>",
              "search": "_INPUT_",
              "searchPlaceholder": "{{ trans('backpack::crud.search') }}...",
              "zeroRecords":    "{{ trans('backpack::crud.zeroRecords') }}",
              "paginate": {
                  "first":      "{{ trans('backpack::crud.paginate.first') }}",
                  "last":       "{{ trans('backpack::crud.paginate.last') }}",
                  "next":       ">",
                  "previous":   "<"
              },
              "aria": {
                  "sortAscending":  "{{ trans('backpack::crud.aria.sortAscending') }}",
                  "sortDescending": "{{ trans('backpack::crud.aria.sortDescending') }}"
              },
              "buttons": {
                  "copy":   "{{ trans('backpack::crud.export.copy') }}",
                  "excel":  "{{ trans('backpack::crud.export.excel') }}",
                  "csv":    "{{ trans('backpack::crud.export.csv') }}",
                  "pdf":    "{{ trans('backpack::crud.export.pdf') }}",
                  "print":  "{{ trans('backpack::crud.export.print') }}",
                  "colvis": "{{ trans('backpack::crud.export.column_visibility') }}"
              },
          },
          processing: true,
          serverSide: true,
          searching: @json($crud->getOperationSetting('searchableTable') ?? true),
          ajax: {
              "url": "{!! url($crud->route.'/search').'?'.Request::getQueryString() !!}",
              "type": "POST"
          },
          dom:
            "<'row hidden'<'col-sm-6'i><'col-sm-6 d-print-none'f>>" +
            "<'row'<'col-sm-12'tr>>" +
            "<'row mt-2 d-print-none '<'col-sm-12 col-md-4'l><'col-sm-0 col-md-4 text-center'B><'col-sm-12 col-md-4 'p>>",
      }
  }

  window.crud.rowStacks = {
      top: @json($crud->hasTopRowColumns()),
      bottom: @json($crud->hasBottomRowColumns()),
  };
  window.crud.rowStackLayouts = @json($rowStackLayouts);
  window.crud.hasRowStacks = window.crud.rowStacks.top || window.crud.rowStacks.bottom;
  window.crud.rowStackColspan = {{ $rowStackColspan }};
  window.crud.rowStackColumnInfo = null;
  window.crud.getActualColumnIndexFromUserIndex = function(userIndex, isStart) {
      if (!this.rowStackColumnInfo) {
          this.rowStackColumnInfo = this.computeRowStackColumnInfo();
      }

      var info = this.rowStackColumnInfo;
      if (!info.total) {
            return 0;
      }

      if (!userIndex || userIndex < 1) {
          return isStart ? 0 : info.total - 1;
      }

      var parsed = parseInt(userIndex, 10);
      if (isNaN(parsed)) {
          return isStart ? 0 : info.total - 1;
      }

      var match = info.userColumns.find(function(column) {
          return column.userIndex === parsed;
      });

      if (match) {
          return match.index;
      }

      if (parsed > info.maxUserIndex) {
          var lastUser = info.userColumns[info.userColumns.length - 1];
          if (lastUser) {
              return lastUser.index;
          }
      }

      var firstUser = info.userColumns[0];
      if (firstUser) {
          return firstUser.index;
      }

      return isStart ? 0 : info.total - 1;
  };
  window.crud.computeRowStackColumnInfo = function () {
      var headerColumns = $('#crudTable thead tr').first().find('th');
      var info = {
          total: headerColumns.length,
          userColumns: [],
          maxUserIndex: 0
      };

      headerColumns.each(function(index) {
          var userIndex = $(this).data('row-stack-user-index');
          var parsed = parseInt(userIndex, 10);

          if (!isNaN(parsed)) {
              info.userColumns.push({ index: index, userIndex: parsed });
              if (parsed > info.maxUserIndex) {
                  info.maxUserIndex = parsed;
              }
          }
      });

      return info;
  };
  window.crud.resolveRowStackSpan = function (stack) {
      if (!this.rowStacks[stack]) {
          return null;
      }

      var layout = this.rowStackLayouts[stack] || {};
      var spanStart = this.getActualColumnIndexFromUserIndex(layout.colspan_start, true);
      var spanEnd = this.getActualColumnIndexFromUserIndex(layout.colspan_end, false);
      
      if (spanEnd < spanStart) {
          spanEnd = spanStart;
      }

      if (spanStart < 0) {
          spanStart = 0;
      }

      if (!this.rowStackColumnInfo) {
          this.rowStackColumnInfo = this.computeRowStackColumnInfo();
      }

      if (spanEnd >= this.rowStackColumnInfo.total) {
          spanEnd = this.rowStackColumnInfo.total - 1;
      }

      return {
          start: spanStart,
          end: spanEnd
      };
  };
  window.crud.insertRowStacks = function () {
      if (!window.crud.hasRowStacks || !window.crud.table) {
          return;
      }

      window.crud.rowStackColumnInfo = window.crud.computeRowStackColumnInfo();
      var totalColumns = window.crud.rowStackColumnInfo.total || window.crud.rowStackColspan || $('#crudTable thead tr:first th').length || 1;
      var spanTop = window.crud.resolveRowStackSpan('top');
      var spanBottom = window.crud.resolveRowStackSpan('bottom');

      var buildRow = function (position, html) {
          var span = position === 'top' ? spanTop : spanBottom;
          if (!span) {
              span = { start: 0, end: totalColumns - 1 };
          }

          var width = span.end - span.start + 1;
          var $row = $("<tr class='crud-row-stack crud-row-stack--"+position+"'></tr>");

          if (position === 'top' && span.start > 0) {
              $row.append("<td class='crud-row-stack__gap' colspan='"+span.start+"'></td>");
          }

          var $cell = $("<td class='crud-row-stack__cell' colspan='"+width+"'></td>");
          var $content = $("<div class='crud-row-stack__content' data-stack='"+position+"'></div>");
          $content.html(html);
          $cell.append($content);
          $row.append($cell);

          if (position === 'top' && span.end < totalColumns - 1) {
              var trailing = totalColumns - span.end - 1;
              $row.append("<td class='crud-row-stack__gap' colspan='"+trailing+"'></td>");
          }

          return $row;
      };

      var applyVariantClass = function ($targetRow, variant) {
          if (! $targetRow || ! $targetRow.length) {
              return;
          }

          $targetRow.removeClass('crud-row-unit crud-row-unit--odd crud-row-unit--even')
                    .addClass('crud-row-unit crud-row-unit--'+variant);
      };

      var applyRowSpansToBaseRow = function ($baseRow, hasBottomRow) {
          if (!hasBottomRow || !spanBottom) {
              $baseRow.find('td[data-row-stack-rowspan]').removeAttr('rowspan data-row-stack-rowspan');
              return;
          }

          var rowspanValue = 2;
          var columnIndex = 0;

          $baseRow.find('td').each(function () {
              if (columnIndex < spanBottom.start || columnIndex > spanBottom.end) {
                  $(this).attr('rowspan', rowspanValue).attr('data-row-stack-rowspan', 'true');
              } else {
                  $(this).removeAttr('rowspan data-row-stack-rowspan');
              }

              columnIndex++;
          });
      };

      $('#crudTable tbody tr.crud-row-stack').remove();

      window.crud.table.rows({page:'current'}).every(function () {
          var rowData = this.data() || {};
          var $row = $(this.node());
          var variant = $row.hasClass('even') ? 'even' : 'odd';

          applyVariantClass($row, variant);

          if (window.crud.rowStacks.top && rowData.___top_row) {
              var $topRow = buildRow('top', rowData.___top_row);
              applyVariantClass($topRow, variant);
              $topRow.insertBefore($row);
          }

          var hasBottomRowForEntry = window.crud.rowStacks.bottom && rowData.___bottom_row;

          if (hasBottomRowForEntry) {
              var $target = $row.next();
              var $bottomRow = buildRow('bottom', rowData.___bottom_row);
              applyVariantClass($bottomRow, variant);

              if ($target.hasClass('child')) {
                  $bottomRow.insertBefore($target);
              } else {
                  $bottomRow.insertAfter($row);
              }
          }

          applyRowSpansToBaseRow($row, hasBottomRowForEntry);
      });
  };

  @if ($crud->hasTopRowColumns() || $crud->hasBottomRowColumns())
      window.crud.addFunctionToDataTablesDrawEventQueue('crud.insertRowStacks');
  @endif
  </script>

  @include('crud::inc.export_buttons')

  <script type="text/javascript">
    jQuery(document).ready(function($) {

      window.crud.table = $("#crudTable").DataTable(window.crud.dataTableConfiguration);

      if (window.crud.hasRowStacks) {
        $('#crudTable').addClass('has-row-stacks');
      }

      $('#crudTable').on('xhr.dt', function (event, settings, json) {
          if (json && json.column_styles) {
              crud.ensureColumnStyles(json.column_styles);
          }
      });

      // move search bar
      $("#crudTable_filter").appendTo($('#datatable_search_stack' ));
      $("#crudTable_filter input").removeClass('form-control-sm');

      // move "showing x out of y" info to header
      @if($crud->getSubheading())
      $('#crudTable_info').hide();
      @else
      $("#datatable_info_stack").html($('#crudTable_info')).css('display','inline-flex').addClass('animated fadeIn');
      @endif

      @if($crud->getOperationSetting('resetButton') ?? true)
        // create the reset button
        var crudTableResetButton = '<a href="{{url($crud->route)}}" class="ml-1" id="crudTable_reset_button">{{ trans('backpack::crud.reset') }}</a>';

        $('#datatable_info_stack').append(crudTableResetButton);

          // when clicking in reset button we clear the localStorage for datatables.
        $('#crudTable_reset_button').on('click', function() {

          //clear the filters
          if (localStorage.getItem('{{ Str::slug($crud->getRoute())}}_list_url')) {
              localStorage.removeItem('{{ Str::slug($crud->getRoute()) }}_list_url');
          }
          if (localStorage.getItem('{{ Str::slug($crud->getRoute())}}_list_url_time')) {
              localStorage.removeItem('{{ Str::slug($crud->getRoute()) }}_list_url_time');
          }

          //clear the table sorting/ordering/visibility
          if(localStorage.getItem('DataTables_crudTable_/{{ $crud->getRoute() }}')) {
              localStorage.removeItem('DataTables_crudTable_/{{ $crud->getRoute() }}');
          }
        });
      @endif

      // move the bottom buttons before pagination
      $("#bottom_buttons").insertBefore($('#crudTable_wrapper .row:last-child' ));

      // override ajax error message
      $.fn.dataTable.ext.errMode = 'none';
      $('#crudTable').on('error.dt', function(e, settings, techNote, message) {
          new Noty({
              type: "error",
              text: "<strong>{{ trans('backpack::crud.ajax_error_title') }}</strong><br>{{ trans('backpack::crud.ajax_error_text') }}"
          }).show();
      });

        // when changing page length in datatables, save it into localStorage
        // so in next requests we know if the length changed by user
        // or by developer in the controller.
        $('#crudTable').on( 'length.dt', function ( e, settings, len ) {
            localStorage.setItem('DataTables_crudTable_/{{$crud->getRoute()}}_pageLength', len);
        });

      // make sure AJAX requests include XSRF token
      $.ajaxPrefilter(function(options, originalOptions, xhr) {
          var token = $('meta[name="csrf_token"]').attr('content');

          if (token) {
                return xhr.setRequestHeader('X-XSRF-TOKEN', token);
          }
      });

      // on DataTable draw event run all functions in the queue
      // (eg. delete and details_row buttons add functions to this queue)
      $('#crudTable').on( 'draw.dt',   function () {
         crud.functionsToRunOnDataTablesDrawEvent.forEach(function(functionName) {
            crud.executeFunctionByName(functionName);
         });
      } ).dataTable();

      // when datatables-colvis (column visibility) is toggled
      // rebuild the datatable using the datatable-responsive plugin
      $('#crudTable').on( 'column-visibility.dt',   function (event) {
         crud.table.responsive.rebuild();
      } ).dataTable();

      @if ($crud->getResponsiveTable())
        // when columns are hidden by reponsive plugin,
        // the table should have the has-hidden-columns class
        crud.table.on( 'responsive-resize', function ( e, datatable, columns ) {
            if (crud.table.responsive.hasHidden()) {
              $("#crudTable").removeClass('has-hidden-columns').addClass('has-hidden-columns');
             } else {
              $("#crudTable").removeClass('has-hidden-columns');
             }
        } );
      @else
        // make sure the column headings have the same width as the actual columns
        // after the user manually resizes the window
        var resizeTimer;
        function resizeCrudTableColumnWidths() {
          clearTimeout(resizeTimer);
          resizeTimer = setTimeout(function() {
            // Run code here, resizing has "stopped"
            crud.table.columns.adjust();
          }, 250);
        }
        $(window).on('resize', function(e) {
          resizeCrudTableColumnWidths();
        });
        $('.sidebar-toggler').click(function() {
          resizeCrudTableColumnWidths();
        });
      @endif

    });
  </script>

  @include('crud::inc.details_row_logic')
