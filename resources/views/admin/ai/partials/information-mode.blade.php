@if(! app(\App\Services\AI\AiInteractionPolicy::class)->allowsLegacyAutomation())
    @php($interactionPolicy = app(\App\Services\AI\AiInteractionPolicy::class)->snapshot())
    <details class="ops-section"><summary>Aturan asisten & pemesanan web</summary>
        <div class="card-body">
            <strong>Mode AI: {{ $interactionPolicy['enabled'] ? 'Informasi & pemesanan web' : 'Balasan otomatis dihentikan' }}</strong>
            <p class="muted" style="margin-bottom:0;">Transaksi dan perubahan profil dari AI lama dikunci, termasuk job serta approval/reject yang tertunda. Asisten memakai menu layanan, katalog aktif dan knowledge publik yang terbit. Pemesanan melalui web; cek status memerlukan kode, nomor pemesanan, dan identitas WhatsApp pemilik yang terverifikasi. Kontak yang belum cocok dan perubahan transaksi dibantu admin.</p>
        </div>
    </details>
@endif
