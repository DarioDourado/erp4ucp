"""
OCR Service — FastAPI Application
==================================
A lightweight microservice that provides document OCR and understanding
capabilities using Tesseract (with image preprocessing) + Ollama (local LLM).

Endpoints:
- POST /analyze: Full document analysis (preprocess → OCR → LLM parsing)
- POST /ocr-only: Just OCR text extraction (no LLM)
- GET /health: Health check + diagnostics
"""

import json
import logging
import os
import tempfile
from pathlib import Path
from typing import Optional

import uvicorn
from fastapi import FastAPI, File, UploadFile, HTTPException, Form
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from pydantic import BaseModel

from ocr_processor import extract_text_with_layout, extract_text_simple
from document_analyzer import analyze_document_with_llm, DEFAULT_MODEL, OLLAMA_BASE_URL

# ── Logging ──────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(name)-20s | %(levelname)-5s | %(message)s",
)
logger = logging.getLogger("ocr-service")

# ── App Setup ────────────────────────────────────────────────────────────────
app = FastAPI(
    title="ERP4U OCR Service",
    description="Document OCR and understanding service for purchase orders",
    version="1.0.0",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# ── Models ───────────────────────────────────────────────────────────────────

class AnalysisResponse(BaseModel):
    success: bool
    data: Optional[dict] = None
    raw_text: Optional[str] = None
    ocr_lines: Optional[list] = None
    tables_detected: Optional[list] = None
    error: Optional[str] = None
    processing_time_ms: Optional[float] = None


class HealthResponse(BaseModel):
    status: str
    tesseract_available: bool
    ollama_available: bool
    ollama_models: list = []
    version: str = "1.0.0"


# ── Endpoints ────────────────────────────────────────────────────────────────

@app.get("/health", response_model=HealthResponse)
async def health_check():
    """Health check endpoint with diagnostics."""
    # Check Tesseract
    try:
        import pytesseract
        tesseract_version = pytesseract.get_tesseract_version()
        tesseract_ok = tesseract_version is not None
    except Exception:
        tesseract_ok = False

    # Check Ollama
    try:
        import requests
        r = requests.get(f"{OLLAMA_BASE_URL}/api/tags", timeout=5)
        ollama_ok = r.status_code == 200
        models = [m["name"] for m in r.json().get("models", [])] if ollama_ok else []
    except Exception:
        ollama_ok = False
        models = []

    return HealthResponse(
        status="healthy" if (tesseract_ok or ollama_ok) else "degraded",
        tesseract_available=tesseract_ok,
        ollama_available=ollama_ok,
        ollama_models=models,
    )


@app.post("/analyze", response_model=AnalysisResponse)
async def analyze_document(
    file: UploadFile = File(...),
    model: str = Form(DEFAULT_MODEL),
    use_llm: bool = Form(True),
):
    """
    Full document analysis pipeline:
    1. Save uploaded file temporarily
    2. Preprocess image (upscale, denoise, binarize, deskew)
    3. Extract text with Tesseract (layout-aware)
    4. (Optional) Send to Ollama LLM for structured understanding
    5. Return structured JSON

    Args:
        file: Image or PDF file (JPEG, PNG, PDF)
        model: Ollama model name to use
        use_llm: Whether to use LLM for document understanding
    """
    import time
    start_time = time.time()

    # Validate file type
    allowed_types = {"image/jpeg", "image/png", "image/tiff", "application/pdf"}
    if file.content_type and file.content_type not in allowed_types:
        # Still try to process if unknown type
        logger.warning(f"Unexpected content type: {file.content_type}")

    # Save uploaded file
    suffix = Path(file.filename or "document.png").suffix or ".png"
    with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
        content = await file.read()
        tmp.write(content)
        tmp_path = tmp.name

    try:
        # ── Step 1: OCR Extraction ──────────────────────────────────────
        logger.info(f"Processing file: {file.filename} ({len(content)} bytes)")

        try:
            ocr_result = extract_text_with_layout(tmp_path)
        except Exception as e:
            logger.error(f"OCR extraction failed: {e}")
            # Fallback to simple text extraction
            raw_text = extract_text_simple(tmp_path)
            ocr_result = {
                "raw_text": raw_text,
                "lines": [],
                "tables": [],
                "hocr": "",
            }

        raw_text = ocr_result["raw_text"]
        ocr_lines = ocr_result["lines"]
        tables = ocr_result["tables"]

        if not raw_text:
            return AnalysisResponse(
                success=False,
                error="Não foi possível extrair texto do documento. " +
                      "Certifique-se de que a imagem tem boa resolução e contraste.",
                processing_time_ms=round((time.time() - start_time) * 1000, 1),
            )

        logger.info(f"OCR extracted {len(raw_text)} chars, {len(ocr_lines)} lines, {len(tables)} tables")

        # ── Step 2: LLM Document Understanding ──────────────────────────
        parsed_data = None
        if use_llm:
            try:
                parsed = analyze_document_with_llm(
                    ocr_text=raw_text,
                    model=model,
                )
                parsed_data = parsed.to_dict()
                logger.info(f"LLM parsed: supplier={parsed_data['supplier']['name']}, "
                           f"lines={len(parsed_data['lines'])}")
            except Exception as e:
                logger.error(f"LLM analysis failed: {e}")
                parsed_data = None

        # ── Step 3: Assemble Response ───────────────────────────────────
        processing_time = round((time.time() - start_time) * 1000, 1)

        response_data = {
            "parsed": parsed_data,
            "ocr_text": raw_text,
        }

        return AnalysisResponse(
            success=True,
            data=response_data,
            raw_text=raw_text,
            ocr_lines=[
                {"text": l["text"], "confidence": l.get("confidence")}
                for l in ocr_lines[:100]  # Limit to first 100 lines
            ],
            tables_detected=[
                {
                    "row_count": len(t["rows"]),
                    "rows": [r["text"] for r in t["rows"]],
                }
                for t in tables
            ],
            processing_time_ms=processing_time,
        )

    except Exception as e:
        logger.exception("Unexpected error during document analysis")
        return AnalysisResponse(
            success=False,
            error=f"Erro interno: {str(e)}",
            processing_time_ms=round((time.time() - start_time) * 1000, 1),
        )
    finally:
        # Clean up temp file
        try:
            os.unlink(tmp_path)
        except Exception:
            pass


@app.post("/ocr-only")
async def ocr_only(file: UploadFile = File(...)):
    """
    Extract text from document without LLM analysis.
    Useful for testing OCR quality independently.
    """
    import time
    start_time = time.time()

    suffix = Path(file.filename or "document.png").suffix or ".png"
    with tempfile.NamedTemporaryFile(delete=False, suffix=suffix) as tmp:
        content = await file.read()
        tmp.write(content)
        tmp_path = tmp.name

    try:
        ocr_result = extract_text_with_layout(tmp_path)
        processing_time = round((time.time() - start_time) * 1000, 1)

        return JSONResponse({
            "success": True,
            "raw_text": ocr_result["raw_text"],
            "lines_count": len(ocr_result["lines"]),
            "lines": [
                {"text": l["text"], "confidence": l.get("confidence")}
                for l in ocr_result["lines"]
            ],
            "tables": [
                {
                    "row_count": len(t["rows"]),
                    "rows": [r["text"] for r in t["rows"]],
                }
                for t in ocr_result.get("tables", [])
            ],
            "processing_time_ms": processing_time,
        })
    except Exception as e:
        return JSONResponse({
            "success": False,
            "error": str(e),
        }, status_code=500)
    finally:
        try:
            os.unlink(tmp_path)
        except Exception:
            pass


# ── Main ─────────────────────────────────────────────────────────────────────

def main():
    """Run the OCR service server."""
    host = os.environ.get("OCR_SERVICE_HOST", "127.0.0.1")
    port = int(os.environ.get("OCR_SERVICE_PORT", "5050"))

    logger.info(f"Starting OCR Service on {host}:{port}")
    uvicorn.run(
        "app:app",
        host=host,
        port=port,
        reload=False,
        log_level="info",
    )


if __name__ == "__main__":
    main()
