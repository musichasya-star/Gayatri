@include('partials.admin-operations-assets')
@once
@push('styles')<link rel="stylesheet" href="{{ asset('css/admin-workbench.css').'?v='.config('gayatri_frontend.asset_version') }}">@endpush
@push('scripts')<script src="{{ asset('js/admin-workbench.js').'?v='.config('gayatri_frontend.admin_script_version', config('gayatri_frontend.asset_version')) }}" defer></script>@endpush
@endonce
