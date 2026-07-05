@echo off
setlocal
cd /d "%~dp0"

echo TMDB Poster Download - Local
rem Runs from the repo checkout: everything lives beside this launcher.
set "SCRIPT=%~dp0local-poster-preflight.ps1"
set "INPUT=%~dp0titles.txt"

rem TMDB key resolution (never hardcoded in the repo):
rem   1) TMDB_API_KEY environment variable
rem   2) git-ignored tmdb-key.txt beside this launcher
rem   3) typed at the prompt
set "TMDB_KEY=%TMDB_API_KEY%"
if "%TMDB_KEY%"=="" if exist "%~dp0tmdb-key.txt" set /p TMDB_KEY=<"%~dp0tmdb-key.txt"
if "%TMDB_KEY%"=="" set /p TMDB_KEY=Paste TMDB API key:

echo.
if not exist "%SCRIPT%" (
    echo Script not found: %SCRIPT%
    pause
    exit /b 1
)

if not exist "%INPUT%" (
    echo Input list not found: %INPUT%
    echo Export one from WordPress: Reviews - IMDb Guard - poster manifest buttons.
    pause
    exit /b 1
)

echo Choose mode:
echo   1 ^) Preview only ^(report CSV, no image downloads^)
echo   2 ^) Download posters locally
set /p CHOICE=Enter 1 or 2:

set "MODE=preview"
if "%CHOICE%"=="2" set "MODE=download"

echo.
echo Running mode: %MODE%
echo Source: TMDB only
echo Input: %INPUT%
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -InputFile "%INPUT%" -Mode "%MODE%" -Source tmdb -NoPrompt -TmdbApiKey "%TMDB_KEY%"

if errorlevel 1 (
    echo.
    echo Run failed. Check the TMDB key and the input list.
)

echo.
echo Done. Check posters in %~dp0poster-output\
pause
endlocal
