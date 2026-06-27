@extends('layouts.app')

@section('title', 'AI Simulator - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Management" title="AI Chat Simulator" description="Uji jawaban AI tanpa mengirim pesan ke WhatsApp customer." />
    <div class="grid grid-2">
        <div class="card"><div class="card-body">
            <form method="POST" action="{{ route('admin.ai.simulator.test') }}" class="grid">
                @csrf
                <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Persona</span><select class="input" name="persona_id"><option value="">Persona aktif</option>@foreach($personas as $persona)<option value="{{ $persona->id }}" @selected(($personaId ?? null)==$persona->id)>{{ $persona->name }}{{ $persona->is_active ? ' (aktif)' : '' }}</option>@endforeach</select></label>
                <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Pertanyaan Customer</span><textarea class="input" rows="8" name="message" required>{{ old('message', $message ?? '') }}</textarea></label>
                <button class="button button-primary" type="submit"><i data-lucide="bot"></i> Generate Jawaban</button>
            </form>
        </div></div>
        <div class="card"><div class="card-body">
            <h2 class="section-title">Hasil Simulator</h2>
            @if ($result)
                <p><strong>Status:</strong> <span class="badge {{ $result['status'] === 'success' ? 'badge-green' : 'badge-red' }}">{{ $result['status'] }}</span></p>
                <p><strong>Confidence:</strong> {{ number_format($result['confidence'] * 100, 0) }}%</p>
                @if ($result['fallback_reason'])<p><strong>Fallback:</strong> {{ $result['fallback_reason'] }}</p>@endif
                <div style="padding:1rem;border-radius:1rem;background:rgba(248,243,234,.8);line-height:1.7;">{{ $result['reply'] }}</div>
                <p class="muted"><strong>Sources:</strong> {{ implode(', ', $result['sources']) ?: '-' }}</p>
                <p class="muted"><strong>AI Log ID:</strong> {{ $result['log_id'] }}</p>
            @else
                <p class="muted">Masukkan contoh pertanyaan untuk melihat jawaban AI, confidence score, sumber knowledge, dan log.</p>
            @endif
        </div></div>
    </div>
@endsection
