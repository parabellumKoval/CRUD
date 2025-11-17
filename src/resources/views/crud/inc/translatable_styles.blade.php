@php
    $dimColor = config('backpack.crud.translatable_alternative_value_color', '#999999');
@endphp
<style>
    .bp-translatable-alt-value {
        color: {{ $dimColor }} !important;
    }

    .bp-translatable-alt-field .form-control,
    .bp-translatable-alt-field .select2-selection__rendered,
    .bp-translatable-alt-field .select2-selection,
    .bp-translatable-alt-field .ck-editor__editable,
    .bp-translatable-alt-field .ck-editor__editable_inline,
    .bp-translatable-alt-field .note-editor.note-frame .note-editable,
    .bp-translatable-alt-field .ql-editor,
    .bp-translatable-alt-field iframe {
        color: {{ $dimColor }} !important;
    }
    .bp-translatable-alt-field iframe {
        opacity: 0.6;
    }
</style>
