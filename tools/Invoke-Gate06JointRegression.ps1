# Gate 06 A+B+C joint regression guard for Windows PowerShell 5.1.
# Default mode is read-only. -RunTests requires an interactive explicit data-reset acknowledgement.
# Never run against the main manga_app database.
[CmdletBinding()]
param(
    [string]$ProjectRoot = 'D:\Manga_Gate04_Staging_20261018',
    [string]$PhpExe = 'D:\DevTools\php84\php.exe',
    [switch]$RunTests
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

$settings = [ordered]@{
    'APP_ENV' = 'testing'
    'DB_CONNECTION' = 'pgsql'
    'DB_HOST' = '127.0.0.1'
    'DB_PORT' = '5432'
    'DB_DATABASE' = 'manga_gate06_test'
    'DB_URL' = ''
}
$original = @{}
$locationPushed = $false

try {
    $root = [System.IO.Path]::GetFullPath($ProjectRoot)
    $platform = Join-Path $root 'platform'
    $auditor = Join-Path $platform 'tools\gate06_readonly_audit.php'
    if (-not (Test-Path -LiteralPath $PhpExe -PathType Leaf)) { throw 'PHP_EXECUTABLE_NOT_FOUND' }
    if (-not (Test-Path -LiteralPath $auditor -PathType Leaf)) { throw 'READONLY_AUDITOR_NOT_FOUND' }
    if (-not (Test-Path -LiteralPath (Join-Path $platform 'vendor\autoload.php') -PathType Leaf)) { throw 'VENDOR_AUTOLOAD_NOT_FOUND' }
    if (-not (Test-Path -LiteralPath (Join-Path $platform 'artisan') -PathType Leaf)) { throw 'ARTISAN_NOT_FOUND' }
    if (Test-Path -LiteralPath (Join-Path $platform 'bootstrap\cache\config.php') -PathType Leaf) {
        throw 'CONFIG_CACHE_PRESENT: stop and review configuration; this tool does not clear cache.'
    }
    $testDir = Join-Path $platform 'tests\Feature\Gate06'
    foreach ($file in @('DomainIntegrityTest.php', 'ReaderDataIntegrityTest.php', 'CommunityDataIntegrityTest.php')) {
        if (-not (Test-Path -LiteralPath (Join-Path $testDir $file) -PathType Leaf)) {
            throw "MISSING_TEST_CLASS: $file"
        }
    }

    foreach ($name in $settings.Keys) {
        $original[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, [string]$settings[$name], 'Process')
    }

    Push-Location $platform
    $locationPushed = $true
    $auditOutput = @(& $PhpExe $auditor 2>&1)
    $auditExitCode = $LASTEXITCODE
    $auditLines = @($auditOutput | ForEach-Object { [string]$_ })
    foreach ($line in $auditLines) {
        if ($line -match '^(DATABASE_GUARD|DOMAIN_TABLES|MIGRATIONS|TRIGGERS|CRITICAL_FKS|CHECK_CONSTRAINTS|GATE06_DATABASE_READONLY_AUDIT)=') {
            Write-Output $line
        }
    }
    if ($auditExitCode -ne 0 -or -not ($auditLines -contains 'GATE06_DATABASE_READONLY_AUDIT=PASS')) {
        throw "READONLY_AUDIT_FAILED_EXIT_$($auditExitCode): tests NOT RUN"
    }
    Write-Output 'GATE06_JOINT_PREFLIGHT=PASS'

    if (-not $RunTests) {
        Write-Output 'GATE06_JOINT_REGRESSION=NOT_RUN (read-only default)'
    } else {
        Write-Warning 'The next action runs Laravel RefreshDatabase tests. The manga_gate06_test database may be reset and lose all its data.'
        Write-Warning 'Only proceed if manga_gate06_test is disposable or you have a verified backup. The original manga_app database must not be used.'
        $answer = Read-Host 'Type exactly: RESET manga_gate06_test'
        if ($answer -cne 'RESET manga_gate06_test') {
            throw 'RESET_NOT_ACKNOWLEDGED: tests NOT RUN'
        }
        Write-Output 'GATE06_JOINT_REGRESSION=RUNNING'
        & $PhpExe artisan test 'tests/Feature/Gate06' '--no-ansi'
        $testExitCode = $LASTEXITCODE
        if ($testExitCode -ne 0) {
            throw "GATE06_JOINT_REGRESSION=FAIL (test process exit $testExitCode)"
        }
        Write-Output 'GATE06_JOINT_REGRESSION=PASS (inspect PHPUnit counts above)'
    }
} catch {
    Write-Output ("GATE06_JOINT_WRAPPER=FAIL: " + $_.Exception.Message)
    exit 1
} finally {
    if ($locationPushed) { Pop-Location }
    foreach ($name in $settings.Keys) {
        if ($original.ContainsKey($name)) {
            [Environment]::SetEnvironmentVariable($name, $original[$name], 'Process')
        }
    }
}
Write-Output 'GATE06_JOINT_WRAPPER=PASS'
exit 0
