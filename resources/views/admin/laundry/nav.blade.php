@include('partials.admin-operations-assets')
<link rel="stylesheet" href="{{ asset('css/laundry.css').'?v='.config('gayatri_frontend.asset_version') }}">
<x-ui.module-navigation module="laundry" :compact="$compactNavigation ?? false" />
<x-ui.form-feedback />
