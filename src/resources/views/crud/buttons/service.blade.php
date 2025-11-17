@if ($crud->hasAccess('service'))
    <a href="{{ url($crud->route.'/'.$entry->getKey().'/service') }}" class="btn btn-sm btn-secondary">
        <i class="la la-tools"></i> 
        <!-- {{ __('Обслуживание') }} -->
    </a>
@endif
