@php
    $translatableContext = backpack_translatable_component_context($field, $crud, $entry ?? null);
    $translatable = $translatableContext['is_translatable'];
    $translatableAttribute = $translatableContext['attribute'];
    $localeStates = $translatableContext['locale_states'];
    $availableLocales = $translatableContext['available_locales'];
    $iconPosition = config('backpack.crud.translatable_field_icon_position', 'right');
@endphp
@if ($translatable && config('backpack.crud.show_translatable_field_icon'))
    <span class="translatable-indicator pull-{{ $iconPosition }} d-inline-flex align-items-center flex-wrap" style="gap:4px;margin-top:3px;">
        <i class="la la-flag-checkered" title="This field is translatable{{ $translatableAttribute ? ' ('.$translatableAttribute.')' : '' }}."></i>
        @if (!empty($localeStates))
            <span class="translatable-indicator__locales d-inline-flex flex-wrap" style="gap:2px;">
                @foreach ($localeStates as $localeCode => $state)
                    @php
                        $badgeClass = $state['filled'] ? 'badge-success' : 'badge-secondary';
                        $localeLabel = $availableLocales[$localeCode] ?? strtoupper($localeCode);
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
