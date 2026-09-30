param(
    [string]$LoginPath = 'sdm-backup',
    [string]$Database = 'sdm_bbpmp_jatim',
    [Parameter(Mandatory=$true)][string]$BackupDirectory
)
$ErrorActionPreference = 'Stop'
if ($Database -notmatch '^[a-zA-Z0-9_]+$') { throw 'Nama database tidak valid.' }
if (-not (Test-Path -LiteralPath $BackupDirectory -PathType Container)) { throw 'Direktori backup harus sudah dibuat pada volume terenkripsi.' }
$targetDirectory = (Resolve-Path -LiteralPath $BackupDirectory).Path
$output = Join-Path $targetDirectory ($Database + '-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.sql')
& mysqldump "--login-path=$LoginPath" --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF "--result-file=$output" $Database
if ($LASTEXITCODE -ne 0) { throw 'Backup MySQL gagal. Periksa koneksi login-path dan hak akses.' }
Write-Output "Backup selesai: $output"
