@extends(backpack_view('blank'))

@php
    $defaultBreadcrumbs = [
        trans('backpack::crud.admin') => backpack_url('dashboard'),
        $crud->entity_name_plural => url($crud->route),
        __('Режим обслуживания') => false,
    ];

    $breadcrumbs = $breadcrumbs ?? $defaultBreadcrumbs;
    $selectedFields = old('fields', $serviceMerge['default_fields'] ?? []);
    $forcedFields = old('force', collect($serviceMergeFields)->filter(fn ($field) => $field['force'])->pluck('key')->all());
    $deleteSource = old('delete_source', $serviceDeleteDefault ? 1 : 0);
    $serviceRelations = $serviceRelations ?? [];
    $relationDefaults = $serviceRelationsDefault ?? [];
    $selectedRelations = old('relations', $relationDefaults);
    $strategyLabels = [
        'translations' => __('Переводы'),
        'append' => __('Добавление'),
        'replace' => __('Замена'),
    ];
@endphp

@section('header')
    <section class="container-fluid">
        <h2>
            <span class="text-capitalize">{!! $crud->entity_name_plural !!}</span>
            <small>{{ __('Режим обслуживания записи #:id', ['id' => $entry->getKey()]) }}</small>
        </h2>
    </section>
@endsection

@section('content')
<div class="row">
    <div class="{{ $crud->getServiceContentClass() }}">
        @include('crud::inc.grouped_errors')

        <form method="post" action="{{ url($crud->route.'/'.$entry->getKey().'/service/merge') }}">
            @csrf

            <div class="card mb-4">
                <div class="card-header font-weight-bold">{{ $serviceMerge['label'] ?? __('Слияние записей') }}</div>
                <div class="card-body">
                    <p class="text-muted mb-4">{{ $serviceMerge['description'] ?? '' }}</p>

                    <div class="form-group">
                        <label for="service-merge-target" class="font-weight-bold">
                            {{ __('Запись-приемник') }}
                        </label>
                        <select name="target_entry_id"
                                id="service-merge-target"
                                class="form-control"
                                data-selected="{{ old('target_entry_id') }}"
                                data-source-id="{{ $entry->getKey() }}"
                                data-endpoint="{{ $serviceCandidatesEndpoint }}"
                                required></select>
                        @if ($errors->has('target_entry_id'))
                            <span class="text-danger small">{{ $errors->first('target_entry_id') }}</span>
                        @endif
                    </div>

                    <div class="form-group form-check">
                        <input type="hidden" name="delete_source" value="0">
                        <input type="checkbox"
                               class="form-check-input"
                               id="service-delete-source"
                               name="delete_source"
                               value="1"
                               {{ $deleteSource ? 'checked' : '' }}>
                        <label class="form-check-label" for="service-delete-source">
                            {{ __('Удалить текущую запись после слияния') }}
                        </label>
                    </div>

                    @if ($serviceRelations !== [])
                        <hr>
                        <div class="form-group mb-0">
                            <label class="font-weight-bold d-block mb-2">{{ __('Связи для слияния') }}</label>
                            <div class="list-group">
                                @foreach ($serviceRelations as $relation)
                                    @php
                                        $relationChecked = in_array($relation['key'], (array) $selectedRelations, true);
                                    @endphp
                                    <div class="list-group-item">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox"
                                                   class="custom-control-input"
                                                   id="merge-relation-{{ $relation['key'] }}"
                                                   name="relations[]"
                                                   value="{{ $relation['key'] }}"
                                                   {{ $relationChecked ? 'checked' : '' }}>
                                            <label class="custom-control-label font-weight-bold" for="merge-relation-{{ $relation['key'] }}">
                                                {{ $relation['label'] }}
                                            </label>
                                        </div>
                                        @if ($relation['help'])
                                            <p class="text-muted small mb-0 mt-2">{{ $relation['help'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header font-weight-bold">{{ __('Поля для слияния') }}</div>
                <div class="card-body">
                    @if ($serviceMergeFields === [])
                        <p class="text-muted mb-0">{{ __('Для этой модели не настроены поля слияния.') }}</p>
                    @else
                        <div class="list-group">
                            @foreach ($serviceMergeFields as $field)
                                @php
                                    $isSelected = in_array($field['key'], $selectedFields, true);
                                    $isForce = in_array($field['key'], $forcedFields, true);
                                @endphp
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="custom-control custom-checkbox">
                                                <input type="checkbox"
                                                       class="custom-control-input js-merge-field"
                                                       id="merge-field-{{ $field['key'] }}"
                                                       name="fields[]"
                                                       value="{{ $field['key'] }}"
                                                       {{ $isSelected ? 'checked' : '' }}>
                                                <label class="custom-control-label font-weight-bold" for="merge-field-{{ $field['key'] }}">
                                                    {{ $field['label'] }}
                                                </label>
                                                <span class="badge badge-light ml-2">{{ $strategyLabels[$field['strategy']] ?? \Illuminate\Support\Str::title($field['strategy']) }}</span>
                                            </div>
                                            @if ($field['help'])
                                                <p class="text-muted mb-0 small mt-2">{{ $field['help'] }}</p>
                                            @endif
                                        </div>

                                        @if ($field['forceable'])
                                            <div class="custom-control custom-switch">
                                                <input type="checkbox"
                                                       class="custom-control-input js-merge-force"
                                                       id="merge-force-{{ $field['key'] }}"
                                                       name="force[]"
                                                       value="{{ $field['key'] }}"
                                                       {{ $isForce ? 'checked' : '' }}
                                                       {{ $isSelected ? '' : 'disabled' }}>
                                                <label class="custom-control-label" for="merge-force-{{ $field['key'] }}">
                                                    {{ __('Force') }}
                                                </label>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            @if ($errors->has('service_merge'))
                <div class="alert alert-danger">
                    {{ $errors->first('service_merge') }}
                </div>
            @endif

            <div class="text-right">
                <button class="btn btn-primary" type="submit" {{ $serviceMergeFields === [] ? 'disabled' : '' }}>
                    {{ __('Выполнить слияние') }}
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('after_styles')
    <link href="{{ asset('packages/select2/dist/css/select2.min.css') }}" rel="stylesheet">
    <link href="{{ asset('packages/select2-bootstrap-theme/dist/select2-bootstrap.min.css') }}" rel="stylesheet">
@endpush

@push('after_scripts')
    <script src="{{ asset('packages/select2/dist/js/select2.full.min.js') }}"></script>
    @if (app()->getLocale() !== 'en')
        <script src="{{ asset('packages/select2/dist/js/i18n/' . str_replace('_', '-', app()->getLocale()) . '.js') }}"></script>
    @endif
    <script>
        (function($){
            const $target = $('#service-merge-target');
            const endpoint = $target.data('endpoint');
            const sourceId = $target.data('source-id');
            const selected = $target.data('selected');

            $target.select2({
                theme: 'bootstrap',
                placeholder: '{{ __('Выберите запись для слияния') }}',
                ajax: {
                    url: endpoint,
                    dataType: 'json',
                    delay: 200,
                    data: function(params) {
                        return {
                            q: params.term,
                            page: params.page || 1,
                            source_id: sourceId,
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data.results || []
                        };
                    }
                },
                allowClear: true,
            });

            if (selected) {
                $.get(endpoint, { selected: selected }, function(response) {
                    if (response && response.results && response.results.length) {
                        const entry = response.results[0];
                        const option = new Option(entry.text, entry.id, true, true);
                        $target.append(option).trigger('change');
                    }
                });
            }

            $('.js-merge-field').on('change', function(){
                const fieldKey = $(this).val();
                const checked = $(this).is(':checked');
                $("#merge-force-" + fieldKey).prop('disabled', !checked);
            });
        })(jQuery);
    </script>
@endpush
