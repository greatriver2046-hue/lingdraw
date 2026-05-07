@echo off
echo ========================================
echo Æô¶¯ Worker Server
echo ========================================
echo.
cd /d "%~dp0"
php think worker:server
pause
