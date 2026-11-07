<#
.SYNOPSIS
    跑 composer audit 并把结果封成带时间戳的报告，供 security-audit.php 的 E-62 判读。

.DESCRIPTION
    为什么不直接在 security-audit.php 里跑：那支脚本必须用项目 php.ini（E-02…E-05 的依据），
    而项目 php.ini 禁了 exec / proc_open，并且 open_basedir 只放行 server/ 与 D:\hqlib\tmp ——
    composer 要写临时文件，在项目 ini 下会一声不响地什么都不输出（实测 exit 0 + 空）。
    所以 composer 必须在全局 ini 下跑，跑完把 JSON 落到 reports 里，再由 PHP 侧读。

    composer audit 走 packagist 的漏洞库，**必须有网**。没网时它会报错而不是报「无漏洞」，
    这一点很重要：E-62 只有在拿到一份新鲜的、明确成功的报告时才判 pass。

.PARAMETER PhpPath
    PHP 可执行文件。默认 D:\webserver\php\php.exe（本机 PATH 里没有 php）

.PARAMETER OutFile
    报告落点，默认 server\storage\reports\composer-audit.json

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File bin\check-deps.ps1

.NOTES
    退出码：0 = 有漏洞库数据且无未修复漏洞；1 = 有漏洞；2 = 跑不起来（多半是没网）。
#>

[CmdletBinding()]
param(
    [string] $PhpPath = 'D:\webserver\php\php.exe',
    [string] $OutFile = ''
)

$ErrorActionPreference = 'Stop'

$here = $PSScriptRoot
if (-not $here) { $here = Split-Path -Parent $MyInvocation.MyCommand.Definition }
$server = Split-Path -Parent $here
if (-not $OutFile) { $OutFile = Join-Path $server 'storage\reports\composer-audit.json' }

if (-not (Test-Path -LiteralPath $PhpPath)) {
    Write-Output "[!] 找不到 PHP：$PhpPath（用 -PhpPath 指定）"
    exit 2
}

Push-Location $server
try {
    # --locked 只审 lock 里锁定的版本（审 composer.json 的范围没有意义）
    $raw = & $PhpPath composer.phar audit --locked --format=json --no-interaction 2>&1
    $code = $LASTEXITCODE
} finally {
    Pop-Location
}

$text = ($raw | Out-String).Trim()

if ($code -ne 0 -and $code -ne 1) {
    Write-Output "[!] composer audit 未能完成（退出码 $code），多半是没网："
    Write-Output $text
    exit 2
}

try {
    $parsed = $text | ConvertFrom-Json
} catch {
    Write-Output "[!] composer 输出不是 JSON，无法判读："
    Write-Output $text
    exit 2
}

$adv = @($parsed.advisories)
$abandoned = @($parsed.abandoned)

$lockPath = Join-Path $server 'composer.lock'
$packages = 0
if (Test-Path -LiteralPath $lockPath) {
    $lock = Get-Content -LiteralPath $lockPath -Raw | ConvertFrom-Json
    $packages = @($lock.packages).Count + @($lock.'packages-dev').Count
}

$report = [pscustomobject]@{
    checked_at   = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz')
    composer     = (& $PhpPath (Join-Path $server 'composer.phar') --version --no-interaction 2>&1 | Select-Object -First 1)
    packages     = $packages
    advisories   = $adv
    abandoned    = $abandoned
    ok           = ($adv.Count -eq 0 -and $abandoned.Count -eq 0)
}

$dir = Split-Path -Parent $OutFile
if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
$report | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $OutFile -Encoding UTF8

if ($report.ok) {
    Write-Output "[OK] $packages 个包，漏洞库无命中，无废弃包。"
    exit 0
}

Write-Output "[FAIL] 漏洞 $($adv.Count) 条、废弃包 $($abandoned.Count) 个 —— 详见 $OutFile"
exit 1
