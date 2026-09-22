# Build the app/code release archive for stores that are not Composer-managed.
#
# This delegates to `git archive`, so the ZIP and the Composer dist tarball are
# produced from the same source of truth: the export-ignore rules in
# .gitattributes. The previous version kept its own hand-written exclusion list,
# which had already drifted - it shipped .gitattributes and CAPABILITIES.md that
# the dist archive strips.
#
# Consequence worth knowing: git archive reads committed content, never the
# working tree. Uncommitted edits are not in the ZIP, which is what you want for
# a release and surprising during development, so a dirty tree warns below.

param(
    [string]$Ref = 'HEAD',
    [string]$Output
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Push-Location $repoRoot

try {
    # Version comes from the constant the module actually sends as its
    # User-Agent, so the archive name can never claim a version the code denies.
    $apiClient = Get-Content -LiteralPath (Join-Path $repoRoot 'Model/ApiClient.php') -Raw
    if ($apiClient -notmatch "VERSION\s*=\s*'([^']+)'") {
        throw "Could not read VERSION from Model/ApiClient.php"
    }
    $version = $Matches[1]

    if (-not $Output) {
        $Output = "build/pingview-monitoring-$version.zip"
    }
    $outputPath = Join-Path $repoRoot $Output
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $outputPath) | Out-Null
    if (Test-Path -LiteralPath $outputPath) {
        Remove-Item -LiteralPath $outputPath
    }

    if (git status --porcelain) {
        Write-Warning "Working tree is dirty. $Ref is archived; uncommitted changes are NOT in the ZIP."
    }

    # Staged under PingView/Monitoring so the archive unzips straight into
    # app/code. A flat archive made the merchant create both directories by
    # hand, and a module dropped one level too high never registers.
    git archive --format=zip --prefix='PingView/Monitoring/' --output="$outputPath" $Ref
    if ($LASTEXITCODE -ne 0) {
        throw "git archive failed with exit code $LASTEXITCODE"
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead($outputPath)
    try { $count = $zip.Entries.Count } finally { $zip.Dispose() }

    Write-Output "$outputPath  ($version, $count entries from $Ref)"
} finally {
    Pop-Location
}
