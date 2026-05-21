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

REM Set Tesseract path (adjust if different)
set "TESSERACT_PATH=C:\Program Files\Tesseract-OCR"
if exist "!TESSERACT_PATH!\tesseract.exe" (
    set "PATH=!PATH!;!TESSERACT_PATH!"
    echo [OCR Service] Tesseract found at: !TESSERACT_PATH!
) else (
    echo [OCR Service] WARNING: Tesseract not found at !TESSERACT_PATH!
    echo [OCR Service] Install from: https://github.com/UB-Mannheim/tesseract/wiki
)

REM Check Python
python --version >nul 2>&1
if errorlevel 1 (
    echo [OCR Service] ERROR: Python not found. Please install Python 3.8+.
    pause
    exit /b 1
)

REM Check Ollama
call :check_ollama

echo.
echo [OCR Service] Starting server on http://127.0.0.1:5050
echo [OCR Service] Default model: qwen2.5:7b
echo [OCR Service] Press Ctrl+C to stop
echo.

powershell -NoProfile -Command "try { python '%SCRIPT_DIR%app.py' } finally { Write-Host ''; Write-Host '[OCR Service] Finished. Press any key to close this window.'; try { [System.Console]::ReadKey($true) | Out-Null } catch { cmd /c pause | Out-Null } }"

endlocal
exit /b 0

:check_ollama
ollama --version >nul 2>&1
if errorlevel 1 (
    echo [OCR Service] WARNING: Ollama not found
    echo [OCR Service] Install Ollama from ollama.com
    echo [OCR Service] Make sure Ollama service is running
) else (
    echo [OCR Service] Ollama found
    ollama list 2>nul | findstr /c:"qwen2.5:7b" >nul
    if errorlevel 1 (
        echo [OCR Service] Pulling qwen2.5:7b model (first time^)...
        ollama pull qwen2.5:7b
    )
)
exit /b 0