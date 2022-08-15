param([string] $Php = 'php')

$ErrorActionPreference = 'Stop'
if ($env:OS -ne 'Windows_NT') {
    Write-Output 'Windows junction check skipped on this platform.'
    exit 0
}

$repo = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$tempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
$probe = [System.IO.Path]::GetFullPath((Join-Path $tempRoot ('legacylens-junction-' + [guid]::NewGuid().ToString('N'))))
if (-not $probe.StartsWith($tempRoot, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Temporary test path is outside the expected directory.'
}
$inputDir = Join-Path $probe 'input'
$outsideDir = Join-Path $probe 'outside'
$outsideLink = Join-Path $inputDir 'outside-link'
$cycleLink = Join-Path $inputDir 'cycle'
$output = Join-Path $probe 'report.json'
New-Item -ItemType Directory -Path $inputDir, $outsideDir -Force | Out-Null
Set-Content -LiteralPath (Join-Path $inputDir 'Visible.php') -Value '<?php class Visible {}' -Encoding ascii
Set-Content -LiteralPath (Join-Path $outsideDir 'Outside.php') -Value '<?php class Outside {}' -Encoding ascii

try {
    New-Item -ItemType Junction -Path $outsideLink -Target $outsideDir | Out-Null
    New-Item -ItemType Junction -Path $cycleLink -Target $inputDir | Out-Null
    & $Php (Join-Path $repo 'bin/legacylens') scan $inputDir "--output=$output"
    if ($LASTEXITCODE -ne 2) { throw 'Expected a warning exit for skipped junctions.' }
    $report = Get-Content -LiteralPath $output -Raw | ConvertFrom-Json
    if ($report.scan.fileCount -ne 1) { throw 'The scanner followed a junction.' }
    $linkWarnings = @($report.scan.warnings | Where-Object { $_.code -eq 'link-or-outside-root-skipped' })
    if ($linkWarnings.Count -ne 2) { throw 'Both junctions must be reported.' }
    if (-not (Test-Path -LiteralPath (Join-Path $outsideDir 'Outside.php'))) { throw 'The outside file changed.' }
    Write-Output 'Windows junction checks passed.'
} finally {
    foreach ($link in @($outsideLink, $cycleLink)) {
        if (Test-Path -LiteralPath $link) {
            $item = Get-Item -LiteralPath $link -Force
            if (-not ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint)) {
                throw 'Refusing to remove an unexpected directory.'
            }
            Remove-Item -LiteralPath $link -Force
        }
    }
    foreach ($file in @($output, (Join-Path $inputDir 'Visible.php'), (Join-Path $outsideDir 'Outside.php'))) {
        if (Test-Path -LiteralPath $file) { Remove-Item -LiteralPath $file -Force }
    }
    foreach ($directory in @($inputDir, $outsideDir, $probe)) {
        if (Test-Path -LiteralPath $directory) { Remove-Item -LiteralPath $directory }
    }
}
exit 0
