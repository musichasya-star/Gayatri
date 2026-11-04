@include('partials.admin-operations-assets')
<link rel="stylesheet" href="{{ asset('css/laundry.css') }}">
<x-ui.module-navigation module="laundry" :compact="$compactNavigation ?? false" />
<x-ui.form-feedback />
