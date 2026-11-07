<#
.SYNOPSIS
    检查 server\.env 的 NTFS 访问控制，产出可被 security-audit.php 读的 JSON。

.DESCRIPTION
    为什么要单独一支 PowerShell：php.ini 里 disable_functions 把 exec / system /
    shell_exec / proc_open 全禁了（E-04 的依据），所以 PHP 端**没有**任何办法去问
    Windows 谁对这个文件有权限。而 fileperms() 在 Windows 上返回的是假的。

    于是分工：PowerShell 能看（Get-Acl 是 .NET API，不需要子进程），PHP 能判。
    本脚本只负责采集 + 给一个初判，最终结论由 security-audit.php 的 E-58 下，
    并且报告过期（默认 30 天）会自动退回 manual —— 一张去年的截图不算证据。

.PARAMETER Path
    要检查的文件，默认 server\.env

.PARAMETER OutFile
    报告落点，默认 server\storage\reports\env-acl.json

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File bin\check-env-acl.ps1

.NOTES
    退出码：0 = 合规；1 = 有非特权主体可访问；2 = 文件不存在。
#>

[CmdletBinding()]
param(
    [string] $Path    = '',
    [string] $OutFile = ''
)

$ErrorActionPreference = 'Stop'

# $PSScriptRoot 在 param 默认值里还拿不到（PS 5.1 的行为），所以默认值在函数体里补。
$here = $PSScriptRoot
if (-not $here) { $here = Split-Path -Parent $MyInvocation.MyCommand.Definition }
if (-not $Path)    { $Path    = Join-Path $here '..\.env' }
if (-not $OutFile) { $OutFile = Join-Path $here '..\storage\reports\env-acl.json' }

if (-not (Test-Path -LiteralPath $Path)) {
    Write-Output "[!] 找不到 $Path"
    exit 2
}

$full = (Resolve-Path -LiteralPath $Path).Path

# 允许的主体：管理员组、SYSTEM、以及当前执行用户（PHP 常驻进程就是它启动的）。
# 其余任何主体只要拿到读权限，就等于拿到了 DB 口令与 APP_KEK。
$allowedIds = @(
    'BUILTIN\Administrators',
    'NT AUTHORITY\SYSTEM',
    ("{0}\{1}" -f $env:USERDOMAIN, $env:USERNAME),
    $env:USERNAME
)

$acl = Get-Acl -LiteralPath $full

$rows = @()
$offenders = @()

foreach ($rule in $acl.Access) {
    $id     = $rule.IdentityReference.Value
    $rights = $rule.FileSystemRights.ToString()
    $type   = $rule.AccessControlType.ToString()
    $inh    = [bool]$rule.IsInherited

    $isAllowed = $false
    foreach ($a in $allowedIds) {
        if ($id -ieq $a) { $isAllowed = $true; break }
    }

    $rows += [pscustomobject]@{
        principal = $id
        rights    = $rights
        type      = $type
        inherited = $inh
        trusted   = $isAllowed
    }

    if ($type -eq 'Allow' -and -not $isAllowed) {
        $offenders += $id
    }
}

$ok = ($offenders.Count -eq 0)

$report = [pscustomobject]@{
    checked_at        = (Get-Date).ToString('yyyy-MM-ddTHH:mm:sszzz')
    path              = $full
    inheritance_on    = -not $acl.AreAccessRulesProtected
    owner             = $acl.Owner
    ok                = $ok
    offenders         = $offenders
    allowed_principals = $allowedIds
    acl               = $rows
}

$dir = Split-Path -Parent $OutFile
if (-not (Test-Path -LiteralPath $dir)) {
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
}

$report | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $OutFile -Encoding UTF8

if ($ok) {
    Write-Output "[OK] $full 仅 Administrators / SYSTEM / 当前用户可访问。"
    exit 0
}

Write-Output "[FAIL] 以下主体不应当访问 $full ："
Write-Output ("       - " + ($offenders -join "`r`n       - "))
Write-Output "       修法见 server\README.md §3（icacls / Set-Acl 去继承 + 只留三主体）"
exit 1
