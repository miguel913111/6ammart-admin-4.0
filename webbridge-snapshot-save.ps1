$req = @{
    action = 'snapshot'
    args = @{}
    session = 'railway-deploy-6ammart-admin-4'
} | ConvertTo-Json -Depth 10 -Compress
$path = "$env:TEMP\webbridge-req-$([System.Guid]::NewGuid().ToString().Substring(0,8)).json"
$out = "$env:TEMP\webbridge-snapshot-$([System.Guid]::NewGuid().ToString().Substring(0,8)).json"
[IO.File]::WriteAllText($path, $req, [System.Text.UTF8Encoding]::new($false))
curl.exe -s -X POST http://127.0.0.1:10086/command -H "Content-Type: application/json" --data-binary "@$path" > $out
Remove-Item $path
Write-Output $out
