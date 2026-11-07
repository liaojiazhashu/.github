@echo off
rem ============================================================
rem  HQ Library - analysis workers only
rem    use this when MySQL(3307) and site(8090) are already up
rem    usage:  启动拆解worker.bat         (default 2 workers)
rem            启动拆解worker.bat 3       (3 workers)
rem ============================================================
set PHPEXE=D:\webserver\php\php.exe
set APPDIR=D:\AITEST\huangqi-scifi\server
set N=%1
if "%N%"=="" set N=2

for /l %%i in (1,1,%N%) do (
    start "" /MIN "%PHPEXE%" -c "%APPDIR%\php.ini" "%APPDIR%\bin\worker.php" --name=w%%i --max-seconds=86400
)

echo.
echo %N% worker(s) started. Logs: storage\logs\worker-*.log
echo Queue: %APPDIR%\bin\console.php analysis:batch --stats
echo.
timeout /t 3 /nobreak >nul
