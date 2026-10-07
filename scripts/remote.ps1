#Requires -Version 5.1
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('sync', 'test', 'phpunit', 'run')]
    [string]$Action,
    [string]$Arg = '',
    [string]$Remote = '',
    [string]$Dir = ''
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

function Get-EnvValue([string]$Key) {
    $file = Join-Path $Root '.env'
    if (-not (Test-Path $file)) { return '' }
    $line = Get-Content $file | Where-Object { $_ -match "^\s*$Key\s*=" } | Select-Object -Last 1
    if (-not $line) { return '' }
    return ($line -replace "^\s*$Key\s*=\s*", '').Trim().Trim('"', "'")
}

if (-not $Remote) { $Remote = Get-EnvValue 'REMOTE' }
if (-not $Dir) { $Dir = Get-EnvValue 'REMOTE_DIR' }
if (-not $Dir) { $Dir = 'gdeslon-api' }
$Mirror = Get-EnvValue 'COMPOSER_MIRROR'
if (-not $Remote) { throw 'Сервер не задан: REMOTE=user@host в .env (см. .env.example) или make remote-test REMOTE=user@host' }

# ConnectTimeout не задаём: в Windows OpenSSH с ним каждое подключение ждёт весь таймаут целиком.
$ssh = @('-o', 'BatchMode=yes', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=4', $Remote)
$env:GIT_SSH_COMMAND = 'ssh -o BatchMode=yes -o ServerAliveInterval=15 -o ServerAliveCountMax=4'
# 32-битный make (GnuWin32) запускает 32-битный PowerShell, которому ssh.exe виден только через Sysnative.
$sshExe = @("$env:windir\Sysnative\OpenSSH\ssh.exe", "$env:windir\System32\OpenSSH\ssh.exe") |
    Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $sshExe) {
    $found = Get-Command ssh -ErrorAction SilentlyContinue
    if ($found) { $sshExe = $found.Source } else { throw 'ssh.exe не найден (OpenSSH-клиент Windows или Git for Windows)' }
}

function Invoke-Native([string]$What, [scriptblock]$Command) {
    # нативные утилиты пишут прогресс в stderr; при 'Stop' PS 5.1 превратил бы это в ошибку
    $ErrorActionPreference = 'Continue'
    & $Command
    $code = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    if ($code -ne 0) { throw "${What}: ошибка (exit $code)" }
}

function Sync-Remote {
    Invoke-Native 'удалённый репозиторий' { & $sshExe @ssh "mkdir -p '$Dir' && cd '$Dir' && { git rev-parse --git-dir >/dev/null 2>&1 || git init -q; }" }

    # отдельный index: снимок захватывает и неотслеживаемые файлы, не трогая настоящий index
    $index = Join-Path $env:TEMP 'gdeslon-api-remote-sync.index'
    $realIndex = Join-Path $Root '.git\index'
    if (Test-Path $realIndex) { Copy-Item $realIndex $index -Force } elseif (Test-Path $index) { Remove-Item $index -Force }
    $env:GIT_INDEX_FILE = $index
    try {
        Invoke-Native 'git add' { git -c core.safecrlf=false add -A }
        $tree = (git write-tree).Trim()
    } finally {
        Remove-Item Env:GIT_INDEX_FILE
    }
    $ErrorActionPreference = 'Continue'
    git rev-parse --verify -q HEAD *> $null
    $hasHead = $LASTEXITCODE -eq 0
    $ErrorActionPreference = 'Stop'
    $parent = if ($hasHead) { @('-p', 'HEAD') } else { @() }
    $commit = (git commit-tree $tree @parent -m 'remote sync snapshot').Trim()

    Write-Host "==> Sync $($commit.Substring(0, 10)) -> ${Remote}:$Dir"
    Invoke-Native 'git push' { git push -q --force "${Remote}:$Dir" "${commit}:refs/heads/sync" }
    $script:Commit = $commit
}

# checkout и прогон под одним flock: второй make remote-* ждёт, а не подменяет файлы под работающими тестами.
function Invoke-Remote([string]$Script, [string[]]$Arguments) {
    $quoted = ($Arguments | Where-Object { $_ -ne '' } | ForEach-Object { "'" + ($_ -replace "'", "'\''") + "'" }) -join ' '
    $checkout = "git checkout -qf --detach $($script:Commit) && git clean -fdq"
    # без двойных кавычек: PowerShell 5.1 не экранирует их в аргументах нативных команд
    $lock = "exec 9>`$HOME/.$Dir.lock; flock -n 9 || { echo '==> Waiting for another remote run...'; flock -w 7200 9 || exit 75; }"
    $envs = if ($Mirror) { "COMPOSER_MIRROR='$Mirror' " } else { '' }
    $run = if ($Script) { " && ${envs}bash scripts/sh/$Script $quoted" } else { '' }
    $ErrorActionPreference = 'Continue'
    & $sshExe @ssh "cd '$Dir' && $lock; $checkout$run"
    $code = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    exit $code
}

function ConvertTo-Base64([string]$Text) {
    return [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($Text))
}

Sync-Remote

switch ($Action) {
    'sync' { Invoke-Remote '' @() }
    'test' { Invoke-Remote 'test.sh' @($(if ($Arg) { $Arg } else { 'all' })) }
    'phpunit' { Invoke-Remote 'phpunit.sh' ($Arg.Trim() -split '\s+', 2) }
    'run' {
        if (-not $Arg) { throw 'Нужна команда: make remote-run CMD="..."' }
        Invoke-Remote 'run.sh' @(ConvertTo-Base64 $Arg)
    }
}
