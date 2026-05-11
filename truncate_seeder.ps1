$path = Join-Path $PSScriptRoot "database\seeders\CleanDemoSeeder.php"
$lines = [System.IO.File]::ReadAllLines($path)
$truncated = $lines[0..368]
[System.IO.File]::WriteAllLines($path, $truncated)
Write-Host "Truncated to 369 lines"
