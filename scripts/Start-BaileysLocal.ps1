param([string]$PhpPath = 'C:\xampp\php\php.exe')
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path -Parent $PSScriptRoot
$serviceDir = Join-Path $taskRoot 'services\baileys'
$runtimeDir = Join-Path $serviceDir 'data'
$nodePath = (Get-Command node -ErrorAction Stop).Source
if (-not (Test-Path -LiteralPath $PhpPath)) { throw 'PHP tidak ditemukan. Gunakan -PhpPath untuk menentukan php.exe.' }
if (Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object LocalPort -in @(8000, 3001)) {
    throw 'Port 8000 atau 3001 sudah digunakan. Periksa service local yang sedang berjalan.'
}
Push-Location $taskRoot
try {
    & $nodePath (Join-Path $serviceDir 'scripts\configure-local.mjs')
    if ($LASTEXITCODE -ne 0) { throw 'Konfigurasi local gagal.' }
    if (-not (Test-Path (Join-Path $serviceDir 'node_modules\@whiskeysockets\baileys\package.json'))) {
        & npm.cmd ci --prefix $serviceDir
        if ($LASTEXITCODE -ne 0) { throw 'Instalasi dependency Baileys gagal.' }
    }
    & $PhpPath artisan whatsapp:prepare-local --env=baileys-local
    if ($LASTEXITCODE -ne 0) { throw 'Persiapan database local gagal.' }
    New-Item -ItemType Directory -Path $runtimeDir -Force | Out-Null
    $artisanPath = '"' + (Join-Path $taskRoot 'artisan') + '"'
    $web = Start-Process -FilePath $PhpPath -ArgumentList @($artisanPath, 'serve', '--env=baileys-local', '--no-reload', '--host=127.0.0.1', '--port=8000') -WorkingDirectory $taskRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDir 'web.log') -RedirectStandardError (Join-Path $runtimeDir 'web-error.log')
    $queue = Start-Process -FilePath $PhpPath -ArgumentList @($artisanPath, 'queue:work', '--env=baileys-local', '--sleep=1', '--tries=3', '--timeout=90') -WorkingDirectory $taskRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDir 'queue.log') -RedirectStandardError (Join-Path $runtimeDir 'queue-error.log')
    $envArgument = '--env-file="' + (Join-Path $serviceDir '.env') + '"'
    $serverArgument = '"' + (Join-Path $serviceDir 'src\server.mjs') + '"'
    $gateway = Start-Process -FilePath $nodePath -ArgumentList @($envArgument, $serverArgument) -WorkingDirectory $serviceDir -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDir 'gateway.log') -RedirectStandardError (Join-Path $runtimeDir 'gateway-error.log')
    $birthdayScheduler = Start-Process -FilePath $PhpPath -ArgumentList @($artisanPath, 'crm:work-birthdays', '--env=baileys-local') -WorkingDirectory $taskRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDir 'birthday-scheduler.log') -RedirectStandardError (Join-Path $runtimeDir 'birthday-scheduler-error.log')
    $rentalScheduler = Start-Process -FilePath $PhpPath -ArgumentList @($artisanPath, 'crm:work-rentals', '--env=baileys-local') -WorkingDirectory $taskRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDir 'rental-scheduler.log') -RedirectStandardError (Join-Path $runtimeDir 'rental-scheduler-error.log')
    @{ root=$taskRoot; web=$web.Id; queue=$queue.Id; gateway=$gateway.Id; birthday_scheduler=$birthdayScheduler.Id; rental_scheduler=$rentalScheduler.Id } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $runtimeDir 'local-processes.json') -Encoding utf8
    $webReady = $false
    $gatewayReady = $false
    for ($attempt=0; $attempt -lt 30; $attempt++) {
        $listeners = @(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Select-Object -ExpandProperty LocalPort)
        $webReady = $listeners -contains 8000
        $gatewayReady = $listeners -contains 3001
        if ($webReady -and $gatewayReady) { break }
        if ($web.HasExited -or $queue.HasExited -or $gateway.HasExited -or $birthdayScheduler.HasExited -or $rentalScheduler.HasExited) { throw 'Salah satu proses berhenti. Periksa services/baileys/data/*-error.log.' }
        Start-Sleep -Milliseconds 500
    }
    if (-not ($webReady -and $gatewayReady)) { throw 'Service belum siap. Periksa log local.' }
    Write-Output ('Gayatri: http://127.0.0.1:8000 | Gateway: http://127.0.0.1:3001 | PID web={0}, queue={1}, gateway={2}' -f $web.Id,$queue.Id,$gateway.Id)
    Write-Output 'Login demo: owner@gayatri.local / password. Buka WhatsApp Gateway untuk scan QR.'
    Write-Output ('Scheduler ulang tahun aktif (PID {0}); pengiriman menunggu fitur diaktifkan di menu Ucapan Ulang Tahun.' -f $birthdayScheduler.Id)
    Write-Output ('Scheduler rental aktif (PID {0}); pengiriman menunggu fitur diaktifkan di menu WhatsApp Rental.' -f $rentalScheduler.Id)
} finally {
    Pop-Location
}
