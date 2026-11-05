@once
    @push('styles')<link rel="stylesheet" href="{{ asset('css/admin-operations.css').'?v='.config('gayatri_frontend.asset_version') }}">@endpush
    @push('scripts')<script src="{{ asset('js/admin-operations.js') }}" defer></script>@endpush
@endonce
