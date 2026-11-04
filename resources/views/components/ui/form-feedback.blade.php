@once
    @if(session('status'))
        <div class="admin-feedback admin-feedback-success" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="admin-feedback admin-feedback-error" role="alert">
            <strong>Periksa kembali isian berikut.</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
@endonce
