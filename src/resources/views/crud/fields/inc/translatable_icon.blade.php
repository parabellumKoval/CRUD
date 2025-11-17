@php
    $modelInstance = $entry ?? $crud->entry ?? $crud->model;
    $translatable = false;
    $translatableAttribute = null;
    $localeStates = [];
    $availableLocales = [];

    if ($modelInstance && method_exists($modelInstance, 'translationEnabled') && $modelInstance->translationEnabled()) {
        foreach ((array) $field['name'] as $fieldName) {
            if ($modelInstance->isTranslatableAttribute($fieldName)) {
                $translatable = true;
                $translatableAttribute = $fieldName;
                break;
            }
        }

        if (! $translatableAttribute && isset($field['store_in']) && $modelInstance->isTranslatableAttribute($field['store_in'])) {
            $translatable = true;
            $translatableAttribute = $field['store_in'];
        }

        if ($translatable && method_exists($modelInstance, 'getTranslationLocalesState')) {
            $localeStates = $modelInstance->getTranslationLocalesState($translatableAttribute);
        }

        if (method_exists($modelInstance, 'getAvailableLocales')) {
            $availableLocales = (array) $modelInstance->getAvailableLocales();
        } else {
            $availableLocales = (array) config('backpack.crud.locales', []);
        }
    }

    $iconPosition = config('backpack.crud.translatable_field_icon_position', 'right');
@endphp
@if ($translatable && config('backpack.crud.show_translatable_field_icon'))
    <span class="translatable-indicator pull-{{ $iconPosition }} d-inline-flex align-items-center flex-wrap" style="gap:4px;margin-top:3px;">
        <i class="la la-flag-checkered" title="This field is translatable{{ $translatableAttribute ? ' ('.$translatableAttribute.')' : '' }}."></i>
        @if (!empty($availableLocales))
            <span class="translatable-indicator__locales d-inline-flex flex-wrap" style="gap:2px;">
                @foreach ($availableLocales as $localeKey => $label)
                    @php
                        $localeCode = is_string($localeKey) ? $localeKey : $label;
                        $state = $localeStates[$localeCode] ?? ['filled' => false, 'length' => 0];
                        $badgeClass = $state['filled'] ? 'badge-success' : 'badge-secondary';
                        $localeLabel = is_string($label) && ! is_int($localeKey) ? $label : strtoupper($localeCode);
                        $tooltipText = strtoupper($localeCode).' · '.($state['filled'] ? __('Translation available') : __('No translation')).' ('.$state['length'].')';
                    @endphp
                    <span class="badge {{ $badgeClass }} badge-pill text-uppercase small" title="{{ $tooltipText }}">
                        {{ strtoupper($localeCode) }}
                    </span>
                @endforeach
            </span>
        @endif
    </span>
@endif
