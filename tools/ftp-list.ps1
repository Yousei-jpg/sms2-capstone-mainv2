param(
    [string]$FtpUser = $env:SMS2_FTP_USER,
    [string]$FtpPass = $env:SMS2_FTP_PASS
)

if (-not $FtpUser -or -not $FtpPass) {
    Write-Error 'Set SMS2_FTP_USER and SMS2_FTP_PASS before running this tool.'
}

$FtpHost = 'ftpupload.net'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

function List-FtpDir([string]$remotePath) {
    $uri = "ftp://${FtpHost}${remotePath}"
    $request = [System.Net.FtpWebRequest]::Create($uri)
    $request.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectory
    $request.Credentials = New-Object System.Net.NetworkCredential($FtpUser, $FtpPass)
    $request.EnableSsl = $true
    $request.UsePassive = $true
    $response = $request.GetResponse()
    $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
    $listing = $reader.ReadToEnd()
    $reader.Close()
    $response.Close()
    return $listing
}

Write-Host '=== / ==='
List-FtpDir '/' | Write-Host
Write-Host '=== /htdocs ==='
List-FtpDir '/htdocs/' | Write-Host
Write-Host '=== /htdocs/setup ==='
List-FtpDir '/htdocs/setup/' | Write-Host
