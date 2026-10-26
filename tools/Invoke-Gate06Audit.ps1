# Source audit by default. -CheckDatabase enables only a READ ONLY catalog check on manga_gate06_test.
# Windows PowerShell 5.1 compatible; does not invoke migrate, tests, seeds, rollback or deployment.
[CmdletBinding()]
param(
    [string]$ProjectRoot = 'D:\Manga_Gate04_Staging_20261018',
    [string]$PhpExe = 'D:\DevTools\php84\php.exe',
    [switch]$CheckDatabase
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

try {
    $root = [System.IO.Path]::GetFullPath($ProjectRoot)
    $platform = Join-Path $root 'platform'
    $migrationDir = Join-Path $platform 'database\migrations'
    $toolsDir = Join-Path $platform 'tools'
    if (-not (Test-Path -LiteralPath $migrationDir -PathType Container)) { throw 'Missing platform/database/migrations folder.' }
    if (-not (Test-Path -LiteralPath $PhpExe -PathType Leaf)) { throw "Missing PHP executable: $PhpExe" }

    $files = @(Get-ChildItem -LiteralPath $migrationDir -Filter '2026_10_19_0001*.php' -File)
    if ($files.Count -ne 11) { throw "Expected 11 Gate 06 migrations, found $($files.Count)." }
    foreach ($no in 101..111) {
        $pattern = '2026_10_19_{0:D6}_*.php' -f $no
        $matched = @($files | Where-Object { $_.Name -like $pattern })
        if ($matched.Count -ne 1) { throw "Expected exactly one $pattern, found $($matched.Count)." }
    }

    $hashes = @{
        'database\migrations\2026_10_19_000104_add_manga_pg_constraints.php' = 'aba507b8c1db34694bed06fe646064aaf8d6157161aab5581b3d330707dbfd2f';
        'app\Models\CustomList.php' = '3dd483ed32d0e03682c8bf75196af1804ac16bd93480d75ba563fb2bd90ba32d'
    }
    foreach ($relative in $hashes.Keys) {
        $absolute = Join-Path $platform $relative
        if (-not (Test-Path -LiteralPath $absolute -PathType Leaf)) { throw "Missing anchor source: $relative" }
        $hash = (Get-FileHash -LiteralPath $absolute -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($hash -ne $hashes[$relative]) { throw "SHA256 mismatch for $relative. Stop and review local changes." }
    }

    $phpFiles = @(
        'app', 'database', 'tests' | ForEach-Object {
            Get-ChildItem -LiteralPath (Join-Path $platform $_) -Filter '*.php' -File -Recurse
        }
    )
    if ($phpFiles.Count -lt 80) { throw "Suspicious PHP file count: $($phpFiles.Count)." }
    foreach ($file in $phpFiles) {
        $lintOutput = & $PhpExe -l $file.FullName 2>&1
        if ($LASTEXITCODE -ne 0) { throw "PHP lint FAIL for $($file.FullName): $($lintOutput -join ' ')" }
    }
    Write-Output "GATE06_STATIC_AUDIT=PASS migrations=$($files.Count) php_lint=$($phpFiles.Count) corrected_hashes=2"

    if ($CheckDatabase) {
        $auditor = Join-Path $toolsDir 'gate06_readonly_audit.php'
        if (-not (Test-Path -LiteralPath $auditor -PathType Leaf)) { throw 'Missing tools/gate06_readonly_audit.php.' }
        if (-not (Test-Path -LiteralPath (Join-Path $platform 'vendor\autoload.php') -PathType Leaf)) { throw 'Missing Composer vendor/autoload.php. Database check not run.' }
        Push-Location $platform
        try {
            & $PhpExe $auditor
            if ($LASTEXITCODE -ne 0) { throw "Database catalog audit FAIL (exit $LASTEXITCODE)." }
        } finally {
            Pop-Location
        }
    } else {
        Write-Output 'GATE06_DATABASE_READONLY_AUDIT=NOT_RUN (add -CheckDatabase only after checking local .env)'
    }
    Write-Output 'GATE06_AUDIT_WRAPPER=PASS'
    exit 0
} catch {
    Write-Error "GATE06_AUDIT_WRAPPER=FAIL $($_.Exception.Message)"
    exit 1
}
