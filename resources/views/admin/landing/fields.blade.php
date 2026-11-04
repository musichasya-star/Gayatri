@foreach(is_array($fields) ? $fields : [] as $key=>$value)
@php($fieldTitle = $fieldLabels[$key] ?? ucfirst(str_replace('_',' ',(string)$key)))
@if(is_array($value))
<details class="ops-section" style="grid-column:1/-1"><summary>{{ $fieldTitle }}</summary><div class="ops-section-body ops-fields">@include('admin.landing.fields',['fields'=>$value,'prefix'=>$prefix.'['.$key.']'])</div></details>
@else
<label>{{ $fieldTitle }}
@if(is_bool($value))<select class="input" name="{{ $prefix }}[{{ $key }}]"><option value="1" @selected($value)>Ya</option><option value="0" @selected(!$value)>Tidak</option></select>
@elseif(mb_strlen((string)$value)>120 || str_contains((string)$value,"\n"))<textarea class="input" name="{{ $prefix }}[{{ $key }}]" rows="3">{{ $value }}</textarea>
@else<input class="input" name="{{ $prefix }}[{{ $key }}]" value="{{ $value }}">@endif
</label>
@endif
@endforeach
