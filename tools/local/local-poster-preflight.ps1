<#
.SYNOPSIS
    Lunara poster batch preflight + downloader (TMDB).

.DESCRIPTION
    Reads a title manifest where every line carries the film's IMDb ID and/or
    its title, verifies each entry against TMDB, and either writes a preflight
    report (preview mode) or downloads the posters locally (download mode).

    Accepted line formats (blank lines and # comments are skipped):
        tt0038192 | The True Glory (1945)
        The True Glory (1945) | tt0038192
        tt0038192
        The True Glory (1945)
        The True Glory

    Every downloaded poster is named "<imdb-id> - <title>.jpg" so the
    WordPress media importers (which key on the tt-id in the filename) map it
    automatically, and the media library stays human-readable. Title-only
    lines are resolved through TMDB search and their IMDb id is fetched from
    TMDB's external ids, so the filename still carries the bridge id.

.NOTES
    The TMDB API key is never stored in this repository. Pass -TmdbApiKey,
    or set the TMDB_API_KEY environment variable, or place the key on the
    first line of a git-ignored tmdb-key.txt beside this script.
#>
param(
    [Parameter(Mandatory = $true)][string]$InputFile,
    [ValidateSet('preview', 'download')][string]$Mode = 'preview',
    [ValidateSet('tmdb')][string]$Source = 'tmdb',
    [string]$TmdbApiKey = '',
    [string]$OutputDir = '',
    [ValidateSet('w342', 'w500', 'w780', 'original')][string]$PosterSize = 'original',
    [int]$SleepMs = 260,
    [switch]$NoPrompt
)

$ErrorActionPreference = 'Stop'
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

# ---------------------------------------------------------------- API key --
if ([string]::IsNullOrWhiteSpace($TmdbApiKey)) {
    $TmdbApiKey = $env:TMDB_API_KEY
}
if ([string]::IsNullOrWhiteSpace($TmdbApiKey)) {
    $keyFile = Join-Path $scriptDir 'tmdb-key.txt'
    if (Test-Path $keyFile) {
        $TmdbApiKey = (Get-Content -Path $keyFile -TotalCount 1).Trim()
    }
}
if ([string]::IsNullOrWhiteSpace($TmdbApiKey) -and -not $NoPrompt) {
    $TmdbApiKey = Read-Host 'TMDB API key'
}
if ([string]::IsNullOrWhiteSpace($TmdbApiKey)) {
    Write-Error 'No TMDB API key. Pass -TmdbApiKey, set TMDB_API_KEY, or create tmdb-key.txt beside this script.'
    exit 1
}

if (-not (Test-Path $InputFile)) {
    Write-Error "Input list not found: $InputFile"
    exit 1
}

if ([string]::IsNullOrWhiteSpace($OutputDir)) {
    $OutputDir = Join-Path $scriptDir 'poster-output'
}

$stamp      = Get-Date -Format 'yyyyMMdd-HHmmss'
$reportPath = Join-Path $scriptDir ("poster-preflight-report-{0}.csv" -f $stamp)

if ($Mode -eq 'download' -and -not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir | Out-Null
}

[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

# --------------------------------------------------------------- helpers --
function Normalize-Title([string]$value) {
    if ([string]::IsNullOrWhiteSpace($value)) { return '' }
    return ($value.ToLowerInvariant() -replace '[^a-z0-9]', '')
}

function Safe-FileName([string]$value) {
    $safe = $value -replace '[\\/:*?"<>|]', ''
    $safe = $safe -replace '\s{2,}', ' '
    $safe = $safe.Trim()
    if ($safe.Length -gt 120) { $safe = $safe.Substring(0, 120).Trim() }
    return $safe
}

function Parse-ManifestLine([string]$line) {
    $entry = [ordered]@{ imdb_id = ''; title = ''; year = '' }

    $parts = $line -split '\|'
    foreach ($part in $parts) {
        $token = $part.Trim()
        if ($token -match '^(?i)tt\d{7,9}$') {
            $entry.imdb_id = $token.ToLowerInvariant()
        } elseif ($token -match '(?i)imdb\.com/title/(tt\d{7,9})') {
            $entry.imdb_id = $Matches[1].ToLowerInvariant()
        } elseif ($token -ne '') {
            $entry.title = $token
        }
    }

    if ($entry.title -match '^(.*)\((\d{4})\)\s*$') {
        $entry.title = $Matches[1].Trim()
        $entry.year  = $Matches[2]
    }

    return $entry
}

function Invoke-Tmdb([string]$url) {
    Start-Sleep -Milliseconds $SleepMs
    return Invoke-RestMethod -Uri $url -Method Get -TimeoutSec 30
}

# ------------------------------------------------------------------- run --
Write-Host ''
Write-Host ("Lunara poster preflight - mode: {0}, source: {1}" -f $Mode, $Source)
Write-Host ("Input : {0}" -f $InputFile)
if ($Mode -eq 'download') { Write-Host ("Output: {0}" -f $OutputDir) }
Write-Host ''

$rows      = @()
$lineNo    = 0
$found     = 0
$downloads = 0
$misses    = 0

foreach ($rawLine in Get-Content -Path $InputFile) {
    $lineNo++
    $line = $rawLine.Trim()
    if ($line -eq '' -or $line.StartsWith('#')) { continue }

    $entry  = Parse-ManifestLine $line
    $status = ''
    $tmdbTitle = ''
    $tmdbId = ''
    $posterPath = ''
    $releaseYear = ''
    $file = ''
    $movie = $null

    try {
        if ($entry.imdb_id -ne '') {
            $findUrl = "https://api.themoviedb.org/3/find/$($entry.imdb_id)?api_key=$TmdbApiKey&external_source=imdb_id"
            $find = Invoke-Tmdb $findUrl
            if ($find.movie_results.Count -gt 0) {
                $movie = $find.movie_results[0]
            } elseif ($find.tv_results.Count -gt 0) {
                $movie = $find.tv_results[0]
            }
        } elseif ($entry.title -ne '') {
            $query = [uri]::EscapeDataString($entry.title)
            $searchUrl = "https://api.themoviedb.org/3/search/movie?api_key=$TmdbApiKey&query=$query"
            if ($entry.year -ne '') { $searchUrl += "&year=$($entry.year)" }
            $search = Invoke-Tmdb $searchUrl
            if ($search.results.Count -gt 0) {
                $movie = $search.results[0]
                # Title-only line: fetch the IMDb id so the output filename
                # still carries the bridge id the site importers key on.
                try {
                    $ext = Invoke-Tmdb "https://api.themoviedb.org/3/movie/$($movie.id)/external_ids?api_key=$TmdbApiKey"
                    if ($ext.imdb_id -match '^(?i)tt\d{7,9}$') {
                        $entry.imdb_id = $ext.imdb_id.ToLowerInvariant()
                    }
                } catch { }
            }
        } else {
            $status = 'SKIP: no id or title on line'
        }

        if ($null -ne $movie) {
            $found++
            $tmdbId     = [string]$movie.id
            $tmdbTitle  = if ($movie.title) { [string]$movie.title } else { [string]$movie.name }
            $posterPath = [string]$movie.poster_path
            $dateField  = if ($movie.release_date) { [string]$movie.release_date } else { [string]$movie.first_air_date }
            if ($dateField -match '^(\d{4})') { $releaseYear = $Matches[1] }

            $titleMatch = 'n/a'
            if ($entry.title -ne '') {
                $titleMatch = if ((Normalize-Title $entry.title) -eq (Normalize-Title $tmdbTitle)) { 'yes' } else { 'NO' }
            }

            if ($posterPath -eq '') {
                $status = 'FOUND: no poster on TMDB'
                $misses++
            } else {
                $status = "OK (title match: $titleMatch)"

                if ($Mode -eq 'download') {
                    $displayTitle = if ($entry.title -ne '') { $entry.title } else { $tmdbTitle }
                    $idPart = if ($entry.imdb_id -ne '') { $entry.imdb_id } else { "tmdb$tmdbId" }
                    $namePart = Safe-FileName $displayTitle
                    if ($releaseYear -ne '' -and $namePart -notmatch '\(\d{4}\)') {
                        $namePart = "$namePart ($releaseYear)"
                    }
                    $file = Join-Path $OutputDir ("{0} - {1}.jpg" -f $idPart, $namePart)

                    if (Test-Path $file) {
                        $status = 'SKIP: already downloaded'
                    } else {
                        $posterUrl = "https://image.tmdb.org/t/p/$PosterSize$posterPath"
                        Invoke-WebRequest -Uri $posterUrl -OutFile $file -TimeoutSec 60 -UseBasicParsing | Out-Null
                        $downloads++
                    }
                }
            }
        } elseif ($status -eq '') {
            $status = 'MISS: nothing found on TMDB'
            $misses++
        }
    } catch {
        $status = "ERROR: $($_.Exception.Message)"
        $misses++
    }

    $rows += [pscustomobject]@{
        line        = $lineNo
        input       = $line
        imdb_id     = $entry.imdb_id
        tmdb_id     = $tmdbId
        input_title = $entry.title
        tmdb_title  = $tmdbTitle
        year        = $releaseYear
        poster      = $posterPath
        status      = $status
        file        = $file
    }

    Write-Host ("[{0}] {1} -> {2}" -f $lineNo, $line, $status)
}

$rows | Export-Csv -Path $reportPath -NoTypeInformation -Encoding UTF8

Write-Host ''
Write-Host ("Done. {0} matched, {1} downloaded, {2} missed." -f $found, $downloads, $misses)
Write-Host ("Report: {0}" -f $reportPath)
if ($Mode -eq 'download') { Write-Host ("Posters: {0}" -f $OutputDir) }
exit 0
