# Hand the released ZIP to the frontend that serves it.
#
# The version, filename, size and checksum on /for/magento all come from one
# record in frontend-nextjs/src/lib/plugins/catalog.ts, and llms.txt repeats
# the filename for crawlers. Four literals that drifted for months on the
# PrestaShop page before the catalog existed; this script rewrites them all
# from the ZIP it just built, so a release cannot forget one.
#
#   ./publish-frontend.ps1            # after the release tag is pushed

param([string]$Frontend = (Join-Path (Split-Path -Parent $PSScriptRoot) 'frontend-nextjs'))

$ErrorActionPreference = 'Stop'
Push-Location $PSScriptRoot
try {
    if (git status --porcelain) { throw 'Working tree is dirty; commit the release first, the ZIP is built from HEAD' }

    $built = & ./build-module-zip.ps1
    Write-Output $built
    if ($built -notmatch '\((\d+\.\d+\.\d+),') { throw 'build-module-zip.ps1 did not report a version' }
    $version = $Matches[1]
    $zip = "build/pingview-monitoring-$version.zip"

    $catalog = Join-Path $Frontend 'src/lib/plugins/catalog.ts'
    $text = Get-Content -Raw $catalog
    if ($text -notmatch "archiveFile: 'magento/pingview-monitoring-(\d+\.\d+\.\d+)\.zip'") { throw "No magento archiveFile in $catalog" }
    $old = $Matches[1]
    if ($old -eq $version) { Write-Output "Frontend already at $version"; return }

    $dest = Join-Path $Frontend "public/downloads/magento/pingview-monitoring-$version.zip"
    Copy-Item $zip $dest
    Remove-Item (Join-Path $Frontend "public/downloads/magento/pingview-monitoring-$old.zip") -ErrorAction SilentlyContinue

    $sha = (Get-FileHash $dest -Algorithm SHA256).Hash.ToLower()
    $kb = [math]::Round((Get-Item $dest).Length / 1KB)

    # The magento record only: the same keys exist for wordpress and prestashop.
    $block = [regex]::Match($text, "(?s)  magento: \{.*?\n  \},")
    if (-not $block.Success) { throw 'magento block not found in catalog.ts' }
    $new = $block.Value
    $new = $new -replace "version: '$old'", "version: '$version'"
    $new = $new -replace "pingview-monitoring-$old\.zip", "pingview-monitoring-$version.zip"
    $new = $new -replace "downloadSize: '[^']*'", "downloadSize: '$kb KB'"
    $new = $new -replace "downloadSha256: '[0-9a-f]{64}'", "downloadSha256: '$sha'"
    $text = $text.Replace($block.Value, $new)
    $text = $text -replace "(magento2-plugin/Model/ApiClient\.php:\d+\s+)\($old\)", "`$1($version)"
    [IO.File]::WriteAllText($catalog, $text)

    foreach ($f in 'public/llms.txt', 'public/llms-full.txt', 'src/tests/seo-content-consistency.test.ts') {
        $p = Join-Path $Frontend $f
        $t = Get-Content -Raw $p
        $t = $t.Replace("pingview-monitoring-$old.zip", "pingview-monitoring-$version.zip").Replace("v$old, Magento", "v$version, Magento")
        [IO.File]::WriteAllText($p, $t)
    }

    Write-Output "frontend: $old -> $version, $kb KB, sha256 $sha"
    Write-Output "next: cd $Frontend; npx jest seo-content-consistency; git add -A public/downloads/magento src/lib/plugins/catalog.ts public/llms*.txt src/tests/seo-content-consistency.test.ts; commit"
} finally {
    Pop-Location
}
