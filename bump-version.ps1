# Bump the module version everywhere it lives, and nowhere else.
#
# The version is read by three consumers that must agree: the tag Packagist
# turns into a release, ApiClient::VERSION that the module sends as its
# User-Agent and provisioning payload (CI fails the tag when they differ), and
# the ZIP name README.md tells non-Composer stores to download. This script is
# the list; a release never edits them by hand.
#
#   ./bump-version.ps1 1.2.2

param([Parameter(Mandatory)][string]$Version)

$ErrorActionPreference = 'Stop'
Push-Location (Split-Path -Parent $MyInvocation.MyCommand.Path)
try {
    if ($Version -notmatch '^\d+\.\d+\.\d+$') { throw "Version must be X.Y.Z, got '$Version'" }

    $apiClient = Get-Content -Raw Model/ApiClient.php
    if ($apiClient -notmatch "VERSION\s*=\s*'([^']+)'") { throw 'Could not read VERSION from Model/ApiClient.php' }
    $current = $Matches[1]

    if ([version]$Version -le [version]$current) { throw "$Version is not newer than the current $current" }
    if (git tag --list $Version) { throw "Tag $Version already exists; Packagist tags are immutable, pick the next one" }

    $files = @{
        'Model/ApiClient.php' = "VERSION = '$current'";
        'README.md'           = "pingview-monitoring-$current.zip";
    }
    foreach ($file in $files.Keys) {
        $needle = $files[$file]
        $text = Get-Content -Raw $file
        if ($text -notmatch [regex]::Escape($needle)) { throw "$file does not contain '$needle'" }
        $text = $text.Replace($needle, $needle.Replace($current, $Version))
        [IO.File]::WriteAllText((Resolve-Path $file), $text)   # keeps LF, no BOM
        Write-Output "$file  $current -> $Version"
    }

    $stale = git grep -n --fixed-strings $current -- ':!i18n' ':!build' ':!*.ps1'
    if ($stale) { Write-Warning "Old version still present:`n$stale" }
} finally {
    Pop-Location
}
