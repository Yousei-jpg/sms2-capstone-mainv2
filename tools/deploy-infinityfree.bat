@echo off
setlocal
echo SMS2 InfinityFree Deploy
echo Configure SMS2_FTP_USER, SMS2_FTP_PASS, and SMS2_DEPLOY_TOKEN before deployment.
echo.
set /p SMS2_FTP_USER=Enter hosting account username: 
set /p SMS2_FTP_PASS=Enter hosting account password: 
set /p SMS2_DEPLOY_TOKEN=Enter deployment token: 
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0build-infinityfree-zip.ps1" -Password "%SMS2_FTP_PASS%"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0deploy-infinityfree.ps1" -FtpUser "%SMS2_FTP_USER%" -FtpPass "%SMS2_FTP_PASS%" -DeployToken "%SMS2_DEPLOY_TOKEN%"
echo.
echo Open the deployment setup page using the configured token.
pause
