@foreach(request()->only(['search','page','unread','need_followup','need_admin','ai_handled','assigned_user_id']) as $key=>$value)
<input type="hidden" name="inbox_filters[{{ $key }}]" value="{{ $value }}">
@endforeach
