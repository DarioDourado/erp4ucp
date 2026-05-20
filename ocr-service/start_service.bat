@echo off
REM ======================================================
REM  OCR Service — Startup Script for Windows
REM ======================================================
REM  Starts the Python FastAPI OCR microservice.
REM  Run this before using OCR features in the ERP.
REM
REM  IMPORTANT: Before running this script, ensure Ollama
REM  is running (it starts automatically with Windows).
REM  Verify with: ollama list
REM
REM  Prerequisites:
REM    1. Python 3.14+ with pip
REM    2. Tesseract OCR installed (C:\Program Files\Tesseract-OCR)
REM    3. Ollama installed with model pulled (qwen2.5:7b)
REM
REM  Install dependencies:
REM     pip install -r ocr-service\requirements.txt
REM
REM  Pull Ollama model (one-time):
REM     ollama pull qwen2.5:7b
REM ======================================================

echo.
echo [OCR Service] Starting...
echo.

REM Set Tesseract path (adjust if different)
set TESSERACT_PATH=C:\Program Files\Tesseract-OCR
if exist "%TESSERACT_PATH%\tesseract.exe" (
    set PATH=%PATH%;%TESSERACT_PATH%
    echo [OCR Service] Tesseract found at: %TESSERACT_PATH%
) else (
    echo [OCR Service] WARNING: Tesseract not found at %TESSERACT_PATH%
    echo [OCR Service] Install from: https://github.com/UB-Mannheim/tesseract/wiki
)

REM Check Ollama
ollama --version >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo [OCR Service] Ollama found
    REM Ensure model is available
    ollama list 2>&1 | findstr "qwen2.5:7b" >nul
    if %ERRORLEVEL% NEQ 0 (
        echo [OCR Service] Pulling qwen2.5:7b model (first time)...
        ollama pull qwen2.5:7b
    )
) else (
    echo [OCR Service] WARNING: Ollama not found. LLM document understanding will be disabled.
    echo [OCR Service] Install from: https://ollama.com
    echo [OCR Service] Make sure Ollama service is running (starts automatically with Windows).
)

echo.
echo [OCR Service] Starting server on http://127.0.0.1:5050
echo [OCR Service] Default model: qwen2.5:7b
echo [OCR Service] Press Ctrl+C to stop
echo.

python ocr-service/app.py
