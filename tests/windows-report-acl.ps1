param([string] $Php = 'php')

$ErrorActionPreference = 'Stop'
if ($env:OS -ne 'Windows_NT') {
    Write-Output 'Windows report ACL check skipped on this platform.'
    exit 0
}

$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$tempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
$probe = [IO.Path]::GetFullPath((Join-Path $tempRoot ('unknot-acl-' + [guid]::NewGuid().ToString('N'))))
if (-not $probe.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Temporary test path is outside the expected directory.'
}
$output = Join-Path $probe 'report.json'
New-Item -ItemType Directory -Path $probe | Out-Null
try {
    $sid = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $acl = Get-Acl -LiteralPath $probe
    $acl.SetAccessRuleProtection($true, $false)
    $rule = [Security.AccessControl.FileSystemAccessRule]::new($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
    $acl.AddAccessRule($rule)
    Set-Acl -LiteralPath $probe -AclObject $acl

    & $Php (Join-Path $repo 'bin/unknot') scan (Join-Path $repo 'fixtures/sample-legacy-app') "--output=$output"
    if ($LASTEXITCODE -notin @(0, 2) -or -not (Test-Path -LiteralPath $output -PathType Leaf)) {
        throw 'The scanner did not create the ACL test report.'
    }
    $reportAcl = Get-Acl -LiteralPath $output
    $rules = @($reportAcl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
    $allows = @($rules | Where-Object { $_.AccessControlType -eq 'Allow' })
    if (-not ($allows | Where-Object { $_.IdentityReference -eq $sid -and $_.IsInherited })) {
        throw 'The report did not inherit the expected current-user access rule.'
    }
    if (@($allows | Where-Object { $_.IdentityReference -ne $sid }).Count -ne 0) {
        throw 'The report inherited an unexpected allow rule.'
    }
    Write-Output 'Windows report ACL inheritance check passed.'
} finally {
    if (Test-Path -LiteralPath $output) { Remove-Item -LiteralPath $output -Force }
    if (Test-Path -LiteralPath $probe) { Remove-Item -LiteralPath $probe }
}
exit 0
