param(
    [string]$OutputDirectory = "runtime/app_packages"
)

$ErrorActionPreference = "Stop"
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$source = (Resolve-Path (Join-Path $projectRoot "app/apps/aigc_music_cover")).Path
$manifest = Get-Content -LiteralPath (Join-Path $source "manifest.json") -Raw -Encoding UTF8 | ConvertFrom-Json
$output = [System.IO.Path]::GetFullPath((Join-Path $projectRoot $OutputDirectory))
$stage = Join-Path ([System.IO.Path]::GetTempPath()) ("likeadmin-music-cover-" + [guid]::NewGuid().ToString("N"))
$package = Join-Path $output ("aigc_music_cover-" + $manifest.version + ".zip")

try {
    New-Item -ItemType Directory -Path $stage -Force | Out-Null
    New-Item -ItemType Directory -Path $output -Force | Out-Null
    Copy-Item -Path (Join-Path $source "*") -Destination $stage -Recurse -Force

    $hashes = [ordered]@{}
    Get-ChildItem -LiteralPath $stage -Recurse -File |
        Where-Object { $_.Name -ne "signature.json" } |
        Sort-Object FullName |
        ForEach-Object {
            $relative = $_.FullName.Substring($stage.Length + 1).Replace("\", "/")
            $hashes[$relative] = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        }
    $signature = [ordered]@{
        algorithm = "sha256"
        app_code = [string]$manifest.code
        version = [string]$manifest.version
        sha256 = $hashes
    }
    $signatureJson = $signature | ConvertTo-Json -Depth 8
    [System.IO.File]::WriteAllText((Join-Path $stage "signature.json"), $signatureJson, (New-Object System.Text.UTF8Encoding($false)))

    if (Test-Path -LiteralPath $package) {
        Remove-Item -LiteralPath $package -Force
    }
    Compress-Archive -Path (Join-Path $stage "*") -DestinationPath $package -CompressionLevel Optimal
    $result = [ordered]@{
        path = $package
        app_code = [string]$manifest.code
        version = [string]$manifest.version
        size = (Get-Item -LiteralPath $package).Length
        sha256 = (Get-FileHash -LiteralPath $package -Algorithm SHA256).Hash.ToLowerInvariant()
        signed_files = $hashes.Count
    }
    $result | ConvertTo-Json -Depth 4
}
finally {
    if (Test-Path -LiteralPath $stage) {
        Remove-Item -LiteralPath $stage -Recurse -Force
    }
}
