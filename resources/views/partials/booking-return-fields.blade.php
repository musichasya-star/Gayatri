@php($returnContext = \App\Support\OperationalNavigation::context(request()))
@if($returnContext)
<input type="hidden" name="return_to" value="{{ $returnContext['return_to'] }}">
@foreach($returnContext['return_filters'] ?? [] as $key => $value)
<input type="hidden" name="return_filters[{{ $key }}]" value="{{ $value }}">
@endforeach
@endif
