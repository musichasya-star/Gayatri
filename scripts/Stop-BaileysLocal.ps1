$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path -Parent $PSScriptRoot
$statePath = Join-Path $taskRoot 'services\baileys\data\local-processes.json'
if (-not (Test-Path -LiteralPath $statePath)) { throw 'Catatan proses tidak ada. Periksa service yang berjalan sebelum menghentikannya.' }
$state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
if ($state.root -ne $taskRoot) { throw 'Catatan proses berasal dari workspace berbeda.' }
function Stop-GayatriProcessTree {
    param([int]$TaskProcessId)
    $taskProcess = Get-CimInstance Win32_Process -Filter ('ProcessId={0}' -f $TaskProcessId)
    if (-not $taskProcess) { return }
    if ($taskProcess.CommandLine -notlike ('*'+$taskRoot+'*')) {
        throw ('PID {0} bukan lagi proses Gayatri. Penghentian dibatalkan.' -f $TaskProcessId)
    }
    foreach ($child in @(Get-CimInstance Win32_Process -Filter ('ParentProcessId={0}' -f $TaskProcessId))) {
        if ($child.CommandLine -like ('*'+$taskRoot+'*')) {
            Stop-GayatriProcessTree -TaskProcessId $child.ProcessId
        }
    }
    Stop-Process -Id $TaskProcessId -ErrorAction SilentlyContinue
}
foreach ($kind in @('rental_scheduler', 'birthday_scheduler', 'web', 'queue', 'gateway')) {
    if (-not $state.$kind) { continue }
    Stop-GayatriProcessTree -TaskProcessId ([int]$state.$kind)
}
Remove-Item -LiteralPath $statePath
Write-Output 'Proses local Gayatri dan Baileys dihentikan. Database demo dan sesi WhatsApp tetap tersimpan.'
