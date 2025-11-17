<?php

namespace Backpack\CRUD\app\Http\Controllers\Operations;

use Backpack\CRUD\app\Library\ServiceOperation\MergeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

trait ServiceOperation
{
    /**
     * Register routes for ServiceOperation.
     */
    protected function setupServiceRoutes($segment, $routeName, $controller)
    {
        Route::get($segment.'/{id}/service', [
            'as' => $routeName.'.service',
            'uses' => $controller.'@service',
            'operation' => 'service',
        ]);

        Route::post($segment.'/{id}/service/merge', [
            'as' => $routeName.'.service.merge',
            'uses' => $controller.'@serviceMerge',
            'operation' => 'service',
        ]);

        Route::get($segment.'/service/merge-candidates', [
            'as' => $routeName.'.service.merge-candidates',
            'uses' => $controller.'@serviceMergeCandidates',
            'operation' => 'service',
        ]);
    }

    protected function setupServiceDefaults()
    {
        $this->crud->allowAccess('service');

        $this->crud->operation('service', function () {
            $this->crud->loadDefaultOperationSettingsFromConfig();
        });

        $this->crud->operation('list', function () {
            $this->crud->addButton('line', 'service', 'view', 'crud::buttons.service', 'beginning');
        });
    }

    public function service($id)
    {
        $this->crud->hasAccessOrFail('service');

        $entry = $this->crud->getEntry($id);
        abort_if(! $entry, 404);

        $mergeService = $this->makeMergeService($entry);

        $this->data['crud'] = $this->crud;
        $this->data['entry'] = $entry;
        $this->data['serviceMerge'] = $mergeService->getDefinition();
        $this->data['serviceMergeFields'] = $mergeService->getFields();
        $this->data['serviceDeleteDefault'] = $mergeService->shouldDeleteSourceByDefault();
        $this->data['serviceCandidatesEndpoint'] = $this->getServiceCandidatesEndpoint();

        $this->data['title'] = $this->crud->getTitle() ?? __('Режим обслуживания');

        return view($this->crud->getServiceView(), $this->data);
    }

    public function serviceMerge(Request $request, $id)
    {
        $this->crud->hasAccessOrFail('service');

        $sourceEntry = $this->crud->getEntry($id);
        abort_if(! $sourceEntry, 404);

        $validated = $request->validate([
            'target_entry_id' => ['required', 'integer'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['string'],
            'force' => ['array'],
            'force.*' => ['string'],
            'delete_source' => ['nullable', 'boolean'],
        ]);

        $targetId = (int) $validated['target_entry_id'];

        if ($targetId === $sourceEntry->getKey()) {
            return back()->withErrors(['target_entry_id' => __('Невозможно объединить запись саму с собой')])->withInput();
        }

        $targetEntry = $this->crud->model->newQuery()->find($targetId);

        if (! $targetEntry) {
            return back()->withErrors(['target_entry_id' => __('Выберите запись для объединения')])->withInput();
        }

        $mergeService = $this->makeMergeService($sourceEntry);
        $fields = array_map('strval', $validated['fields']);
        $forced = array_map('strval', $validated['force'] ?? []);
        $deleteSource = (bool) ($validated['delete_source'] ?? false);

        try {
            $result = $mergeService->mergeInto($targetEntry, $fields, $forced, $deleteSource);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['service_merge' => $e->getMessage()])->withInput();
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['service_merge' => __('Не удалось выполнить слияние. Повторите попытку позже.')])->withInput();
        }

        \Alert::success(__('Слияние успешно выполнено.'))->flash();

        return redirect()->to(url($this->crud->route.'/'.$result['target']->getKey().'/service'));
    }

    public function serviceMergeCandidates(Request $request)
    {
        $this->crud->hasAccessOrFail('service');

        $sourceId = $request->input('source_id');
        $source = null;

        if ($sourceId) {
            $source = $this->crud->model->newQuery()->find($sourceId);
        }

        $mergeService = $this->makeMergeService($source);

        if ($request->filled('selected')) {
            $selectedIds = Arr::wrap($request->input('selected'));
            $results = $mergeService->resolveCandidatesByIds($selectedIds, $source);

            return response()->json(['results' => $results]);
        }

        $results = $mergeService->searchCandidates($request->input('q'), $source);

        return response()->json(['results' => $results]);
    }

    protected function makeMergeService(?Model $sourceEntry = null): MergeService
    {
        return new MergeService($this->crud, $sourceEntry);
    }

    protected function getServiceCandidatesEndpoint(): string
    {
        return url($this->crud->route.'/service/merge-candidates');
    }
}
