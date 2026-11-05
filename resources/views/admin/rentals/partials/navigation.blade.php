@include('partials.admin-operations-assets')
<link rel="stylesheet" href="{{ asset('css/rental.css').'?v='.config('gayatri_frontend.asset_version') }}">
<x-ui.module-navigation module="rentals" :compact="$compactNavigation ?? false" />
<x-ui.form-feedback />
