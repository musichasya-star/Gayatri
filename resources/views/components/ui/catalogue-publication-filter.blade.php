@props(['value' => null])
<label>Publikasi<select class="input" name="active"><option value="" @selected($value===null)>Tayang dan diarsipkan</option><option value="1" @selected((string)$value==='1')>Tayang</option><option value="0" @selected((string)$value==='0')>Diarsipkan</option></select></label>
