@php($reviewContext = \App\Support\AdminReviewContext::carry(request(),$reviewModule))
@if($reviewContext)
<input type="hidden" name="_list" value="{{ $reviewContext['_list'] }}">
@foreach($reviewContext['_filters'] as $key=>$value)<input type="hidden" name="_filters[{{ $key }}]" value="{{ $value }}">@endforeach
@endif
