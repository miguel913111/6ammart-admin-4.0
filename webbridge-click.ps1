param([string]$ref)
$req = @{
    action = 'click'
    args = @{ selector = $ref }
    session = 'railway-deploy-6ammart-admin-4'
} | ConvertTo-Json -Depth 10 -Compress
$path = "$env:TEMP\webbridge-req-$([System.Guid]::NewGuid().ToString().Substring(0,8)).json"
[IO.File]::WriteAllText($path, $req, [System.Text.UTF8Encoding]::new($false))
$result = curl.exe -s -X POST http://127.0.0.1:10086/command -H "Content-Type: application/json" --data-binary "@$path"
Remove-Item $path
$bytes = [System.Text.Encoding]::UTF8.GetBytes($result)
if ($bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) { $bytes = $bytes[3..($bytes.Length-1)] }
$outPath = 'C:\xampp\htdocs\NEX+NEXFOOD\workspace\admin-panel-4.0\webbridge-last-response.json'
[IO.File]::WriteAllBytes($outPath, $bytes)
Write-Output $outPath
