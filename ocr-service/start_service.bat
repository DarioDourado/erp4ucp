@echo off
setlocal enabledelayedexpansion

REM ======================================================
REM  OCR Service — Startup Script for Windows
REM ======================================================

REM Use the directory where this script is located
set "SCRIPT_DIR=%~dp0"

echo.
echo [OCR Service] Starting...
echo.

REM Detect Python command (try python, python3, then py -3.14 launcher)
set "PYTHON_CMD="
python --version >nul 2>&1
if not errorlevel 1 (
    set "PYTHON_CMD=python"
) else (
    python3 --version >nul 2>&1
    if not errorlevel 1 (
        set "PYTHON_CMD=python3"
    ) else (
        py -3.14 --version >nul 2>&1
        if not errorlevel 1 (
            set "PYTHON_CMD=py -3.14"
        ) else (
            py --version >nul 2>&1
            if not errorlevel 1 (
                set "PYTHON_CMD=py"
            )
        )
    )
)

if "%PYTHON_CMD%"=="" (
    echo [OCR Service] ERROR: Python not found. Please install Python 3.10+.
    pause
    exit /b 1
)
echo [OCR Service] Using Python: %PYTHON_CMD%

REM Check llama-cpp server
call :check_llamacpp

echo.
echo [OCR Service] Starting server on http://127.0.0.1:5050
echo [OCR Service] LLM server: http://127.0.0.1:8080
echo [OCR Service] Press Ctrl+C to stop
echo.

powershell -NoProfile -Command "try { %PYTHON_CMD% '%SCRIPT_DIR%app.py' } finally { Write-Host ''; Write-Host '[OCR Service] Finished. Press any key to close this window.'; try { [System.Console]::ReadKey($true) | Out-Null } catch { cmd /c pause | Out-Null } }"

endlocal
exit /b 0

:check_llamacpp
echo [OCR Service] Checking llama-cpp server at http://127.0.0.1:8080...
powershell -NoProfile -Command "try { $r = Invoke-WebRequest -Uri 'http://127.0.0.1:8080/v1/models' -TimeoutSec 5 -UseBasicParsing; if ($r.StatusCode -eq 200) { Write-Host '[OCR Service] llama-cpp server is running' } else { Write-Host '[OCR Service] WARNING: llama-cpp server returned unexpected status' } } catch { Write-Host '[OCR Service] WARNING: llama-cpp server not reachable at http://127.0.0.1:8080'; Write-Host '[OCR Service] Start it with: python -m llama_cpp.server --model path/to/model.gguf --host 127.0.0.1 --port 8080' }"
exit /b 0