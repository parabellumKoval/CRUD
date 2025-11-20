<?php

namespace Backpack\CRUD\app\Http\Controllers\Operations;

use Illuminate\Support\Facades\Route;

trait ListOperation
{
    /**
     * Define which routes are needed for this operation.
     *
     * @param  string  $segment  Name of the current entity (singular). Used as first URL segment.
     * @param  string  $routeName  Prefix of the route name.
     * @param  string  $controller  Name of the current CrudController.
     */
    protected function setupListRoutes($segment, $routeName, $controller)
    {
        Route::get($segment.'/', [
            'as'        => $routeName.'.index',
            'uses'      => $controller.'@index',
            'operation' => 'list',
        ]);

        Route::post($segment.'/search', [
            'as'        => $routeName.'.search',
            'uses'      => $controller.'@search',
            'operation' => 'list',
        ]);

        Route::get($segment.'/{id}/details', [
            'as'        => $routeName.'.showDetailsRow',
            'uses'      => $controller.'@showDetailsRow',
            'operation' => 'list',
        ]);
    }

    /**
     * Add the default settings, buttons, etc that this operation needs.
     */
    protected function setupListDefaults()
    {
        $this->crud->allowAccess('list');

        $this->crud->operation('list', function () {
            $this->crud->loadDefaultOperationSettingsFromConfig();
        });
    }

    /**
     * Display all rows in the database for this entity.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $this->crud->hasAccessOrFail('list');

        $this->data['crud'] = $this->crud;
        $this->data['title'] = $this->crud->getTitle() ?? mb_ucfirst($this->crud->entity_name_plural);

        // load the view from /resources/views/vendor/backpack/crud/ if it exists, otherwise load the one in the package
        return view($this->crud->getListView(), $this->data);
    }

    /**
     * The search function that is called by the data table.
     *
     * @return array JSON Array of cells in HTML form.
     */
    public function search()
    {
        $this->crud->hasAccessOrFail('list');
        $this->crud->applyUnappliedFilters();

        // Фильтрация по parent_id для вложенных таблиц (НЕ ломая основной список)
        $parentId = request('parent_id');
        if ($parentId !== null && $parentId !== '') {
            $this->crud->query->where('parent_id', $parentId);
        }

        // total / filtered
        $baseCountQuery = clone $this->crud->query;
        $totalRows = $baseCountQuery->toBase()->getCountForPagination();
        $filteredRows = $totalRows;
        $startIndex = (int) (request('start') ?? 0);

        // поиск
        if ($term = data_get(request()->input('search'), 'value')) {
            $this->crud->applySearchTerm($term);
            $filteredRows = (clone $this->crud->query)->toBase()->getCountForPagination();
        }

        // пагинация
        if (request()->filled('start'))  $this->crud->skip((int) request('start'));
        if (request()->filled('length')) $this->crud->take((int) request('length'));

        // порядок (как в Backpack)
        if ($orders = request('order')) {
            $this->crud->query->getQuery()->orders = null;
            foreach ($orders as $order) {
                $colIdx = (int) $order['column'];
                $dir    = strtolower((string) $order['dir']) === 'asc' ? 'ASC' : 'DESC';
                $column = $this->crud->findColumnById($colIdx);
                if ($column['tableColumn'] && !isset($column['orderLogic'])) {
                    $this->crud->orderByWithPrefix($column['name'], $dir);
                }
                if (isset($column['orderLogic'])) {
                    $this->crud->customOrderBy($column, $dir);
                }
            }
        }

        // если сортировка по PK не задана — добавим
        $table = $this->crud->model->getTable();
        $key   = $this->crud->model->getKeyName();
        $ordersNow = $this->crud->query->toBase()->orders;
        $hasOrderByPk = collect($ordersNow)->some(function ($item) use ($key, $table) {
            return (isset($item['column']) && $item['column'] === $key)
                || (isset($item['sql']) && str_contains($item['sql'], "$table.$key"));
        });
        if (!$hasOrderByPk) {
            $this->crud->orderByWithPrefix($key, 'DESC');
        }

        $entries = $this->crud->getEntries();

        // строим стандартный JSON backpack
        $json = $this->crud->getEntriesAsJsonForDatatables($entries, $totalRows, $filteredRows, $startIndex);

        // ---- DRESS ----
        $childrenMap = [];

        if($parentId) {
            // возьмём PK и посчитаем детей для них
            $ids  = $entries->pluck($key)->all();

            if (!empty($ids)) {
                $childrenMap = $this->crud->model->newQuery()
                    ->selectRaw('parent_id, COUNT(*) AS cnt')
                    ->whereIn('parent_id', $ids)
                    ->groupBy('parent_id')
                    ->pluck('cnt', 'parent_id')
                    ->all();
            }
        }

        // аккуратно вплетаем в json построчно (порядок сохранён)
        foreach (array_values($entries->all()) as $i => $entry) {
            $id = $entry->getKey();
            // гарантируем два ключа для клиента
            $json['data'][$i]['___id'] = $id;
            $json['data'][$i]['has_children'] = !empty($childrenMap[$id]);
        }

        $columnStylesStack = $this->crud->pullListColumnStyleStacks();
        if (! empty($columnStylesStack)) {
            $json['column_styles'] = $columnStylesStack;
        }

        return $json;
    }


    /**
     * Used with AJAX in the list view (datatables) to show extra information about that row that didn't fit in the table.
     * It defaults to showing some dummy text.
     *
     * @return \Illuminate\View\View
     */
    public function showDetailsRow($id)
    {
        $this->crud->hasAccessOrFail('list');

        // get entry ID from Request (makes sure its the last ID for nested resources)
        $id = $this->crud->getCurrentEntryId() ?? $id;

        $this->data['entry'] = $this->crud->getEntry($id);
        $this->data['crud'] = $this->crud;

        // load the view from /resources/views/vendor/backpack/crud/ if it exists, otherwise load the one in the package
        return view($this->crud->getDetailsRowView(), $this->data);
    }
}
