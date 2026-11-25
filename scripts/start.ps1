param([switch]$NoBrowser, [switch]$Stop)
$ErrorActionPreference = 'Stop'
try {
    $projectRoot = Split-Path -Parent $PSScriptRoot
    $publicDir = Join-Path $projectRoot 'public'
    $routerPath = Join-Path $publicDir 'router.php'
    $storageDir = Join-Path $projectRoot 'storage'
    $runtimeFile = Join-Path $storageDir 'server.json'
    if ($Stop) {
        if (!(Test-Path -LiteralPath $runtimeFile)) { Write-Host 'El servidor no esta iniciado desde este lanzador.'; exit 0 }
        $runtime = Get-Content -LiteralPath $runtimeFile -Raw | ConvertFrom-Json
        $server = Get-Process -Id ([int]$runtime.process_id) -ErrorAction SilentlyContinue
        if ($server -and $server.ProcessName -eq 'php' -and $runtime.start_ticks -and $server.StartTime.ToUniversalTime().Ticks.ToString() -eq $runtime.start_ticks) {
            Stop-Process -Id $server.Id
            Write-Host 'Servidor de Caleidos detenido.'
        }
        Remove-Item -LiteralPath $runtimeFile
        exit 0
    }
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) { $phpPath = $phpCommand.Source }
    elseif (Test-Path -LiteralPath 'C:\xampp\php\php.exe') { $phpPath = 'C:\xampp\php\php.exe' }
    else { throw 'No se encontro PHP. Instala PHP 8.1+ o XAMPP y agrega PHP al PATH.' }
    $extensions = & $phpPath -m
    if (!($extensions -contains 'pdo_sqlite')) { throw 'Activa extension=pdo_sqlite en php.ini.' }
    New-Item -ItemType Directory -Force -Path $storageDir | Out-Null
    $url = $null
    if (Test-Path -LiteralPath $runtimeFile) {
        try {
            $runtime = Get-Content -LiteralPath $runtimeFile -Raw | ConvertFrom-Json
            if ($runtime.url -match '^http://127\.0\.0\.1:87[0-9]{2}/$' -and $runtime.instance_token) {
                $health = Invoke-RestMethod -Uri "$($runtime.url)index.php?health=1" -TimeoutSec 1
                if ($health.project -eq 'caleidos-gestion-contractual' -and $health.instance_id -eq $runtime.instance_token) { $url = $runtime.url }
            }
        } catch { }
    }
    foreach ($port in 8765..8785) {
        if ($url) { break }
        $candidate = "http://127.0.0.1:$port/"
        $listener = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $port)
        try { $listener.Start() } catch { continue } finally { $listener.Stop() }
        $arguments = @('-d', 'upload_max_filesize=64M', '-d', 'post_max_size=70M', '-d', 'display_errors=0', '-d', 'log_errors=1', '-S', "127.0.0.1:$port", '-t', ('"' + $publicDir + '"'), ('"' + $routerPath + '"'))
        $instanceToken = [Guid]::NewGuid().ToString('N')
        $previousToken = $env:CALEIDOS_INSTANCE_TOKEN
        $env:CALEIDOS_INSTANCE_TOKEN = $instanceToken
        try {
            $process = Start-Process -FilePath $phpPath -ArgumentList $arguments -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $storageDir 'server-output.log') -RedirectStandardError (Join-Path $storageDir 'server-error.log') -PassThru
        } finally { $env:CALEIDOS_INSTANCE_TOKEN = $previousToken }
        $ready = $false
        for ($attempt = 0; $attempt -lt 40; $attempt++) {
            Start-Sleep -Milliseconds 250
            if ($process.HasExited) { throw 'PHP no pudo iniciar. Revisa storage/server-error.log.' }
            try { $health = Invoke-RestMethod -Uri "${candidate}index.php?health=1" -TimeoutSec 1; if ($health.project -eq 'caleidos-gestion-contractual') { $ready = $true; break } } catch { }
        }
        if (!$ready) { Stop-Process -Id $process.Id; throw 'El servidor no respondio a tiempo.' }
        @{ process_id = $process.Id; url = $candidate; instance_token = $instanceToken; start_ticks = $process.StartTime.ToUniversalTime().Ticks.ToString() } | ConvertTo-Json | Set-Content -LiteralPath $runtimeFile -Encoding UTF8
        $url = $candidate
        break
    }
    if (!$url) { throw 'No hay un puerto disponible entre 8765 y 8785.' }
    $loginUrl = "${url}index.php?page=login"
    Write-Host "Caleidos Gestion Contractual: $loginUrl"
    Write-Host 'Para cerrar el servidor, ejecuta detener.cmd.'
    if (!$NoBrowser) { Start-Process $loginUrl }
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
