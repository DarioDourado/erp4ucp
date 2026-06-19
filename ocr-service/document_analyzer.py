"""
Document Analyzer Module
========================
Uses llama-cpp (local LLM server with OpenAI-compatible API) to interpret
OCR-extracted text and extract structured data from purchase orders.

The LLM understands the document structure and can identify:
- Supplier name, NIF, address
- Document date and number
- Product lines (code, description, quantity, unit price, totals)
"""

import json
import logging
import os
import requests
from dataclasses import dataclass, field, asdict
from typing import Optional
from dotenv import load_dotenv

load_dotenv()

logger = logging.getLogger(__name__)

LLM_BASE_URL = os.environ.get("LLM_BASE_URL", "http://127.0.0.1:8080/v1")
DEFAULT_MODEL = os.environ.get("LLM_MODEL", "qwen2.5-7b-instruct-q4_k_m")
LLM_MAX_CONTEXT = int(os.environ.get("LLM_MAX_CONTEXT", "4096"))
ENABLE_FALLBACK = os.environ.get("ENABLE_FALLBACK", "true").lower() in ("true", "1", "yes")

SYSTEM_PROMPT = """You are a specialized AI for extracting structured data from Portuguese purchase order (encomenda a fornecedor) OCR text.

CRITICAL: Extract EVERY product line. Do not skip or omit any. Count lines in input → produce same count in output.

HEADER DETECTION: Scan for a header row first. Common Portuguese headers:
Code: Ref./Referência/Cód./Código/Art./Artigo
Desc: Descrição/Designação/Artigo/Produto
Qty: Qtd./Quant./Quantidade/Un./Qtd Enc (ordered) vs Qtd Ent (delivered, ignore)
Price: Pr. Unit./Preço Unit./P. Unit./Valor Unit./Preço
Total: Total/Valor/Import./Líquido
VAT: IVA/Taxa/%
Unit: Un./UN/Unidade/Medida
If no header, infer from data pattern. Different suppliers = different column orders.
IMPORTANT: For each line, extract the VAT/tax rate (IVA column value like 6%, 13%, 23%) into the taxRate field.

SUPPLIER: Look near "FORNECEDOR:", "Fornecedor", "Cliente:". NIF = 9 digits (from NIF:/NIPC:/Contribuinte:). Date → YYYY-MM-DD.

LAYOUT VARIATIONS:
A) Simple: "CODE  DESC  QTY  PRICE"
B) Table with headers then data rows
C) Bracket/pipe: "CODE [DESC | QTY[unit] PRICE] VAT% | TOTAL"
D) Multi-column grid (scattered OCR)
E) Rich table with Qtd Enc + Qtd Ent + multiple value columns

NUMBER HANDLING:
- Portuguese comma = decimal (1,15=1.15). Thousands dot (1.000=1000). Convert to standard decimal.
- VAT rates (6%,13%,23%) are NOT quantities or prices. Row-end Total is NOT unit price.
- Embedded numbers in descriptions (25KG, 500ML, M8x20) stay in description.

OCR text may have recognition errors — use context to infer. Accented chars may be misrecognized. Strip bracket/pipe/percentage artifacts from descriptions. Merge wrapped description lines. Respond in VALID JSON only, no markdown."""

FEW_SHOT_EXAMPLES = """Example 1 (simple: code + desc + qty + price):
Input:
ENCOMENDA Nº 2025/058 | FORNECEDOR: Distribuidora Lusitânia, Lda | NIF: 500123456 | DATA: 20/05/2025
1001  Arroz Agulha  20  1,15
1002  Massa Espiral  24  0,79

Output: {"supplier":{"name":"Distribuidora Lusitânia, Lda","nif":"500123456","address":null},"documentDate":"2025-05-20","documentNumber":"2025/058","lines":[{"productCode":"1001","productDescription":"Arroz Agulha","quantity":20.0,"unitPrice":1.15,"unit":null,"taxRate":null},{"productCode":"1002","productDescription":"Massa Espiral","quantity":24.0,"unitPrice":0.79,"unit":null,"taxRate":null}],"totalNet":null,"totalGross":null}

Example 2 (table with headers Ref./Designação/Quant./Pr. Unit./IVA/Valor):
Input:
Fornecedor: Cervejaria Portuguesa, SA | NIF: 512345678 | Data: 10/06/2026 | Encomenda Nº: NE-2026-09002
Código  Descrição do Artigo                     Qtd  Un  P.Unit (€)  IVA  Total (€)
BEB-001 Cerveja Super Bock 33cl (pack 24)      40   pack 14,40       23%  576,00
BEB-002 Vinho Verde Branco 75cl (Casal Garcia)  60   un   3,20        23%  192,00

Output: {"supplier":{"name":"Cervejaria Portuguesa, SA","nif":"512345678","address":null},"documentDate":"2026-06-10","documentNumber":"NE-2026-09002","lines":[{"productCode":"BEB-001","productDescription":"Cerveja Super Bock 33cl (pack 24)","quantity":40.0,"unitPrice":14.40,"unit":"pack","taxRate":23.0},{"productCode":"BEB-002","productDescription":"Vinho Verde Branco 75cl (Casal Garcia)","quantity":60.0,"unitPrice":3.20,"unit":"un","taxRate":23.0}],"totalNet":null,"totalGross":null}

Example 3 (bracket/pipe format: CODE [DESC | QTY[unit] PRICE] VAT% | TOTAL):
Input:
0122 [Batata Monalisa Sac 10kg | 5[Cx] 8,60] 6% | 42,50)
0344 [Tomate Chucha Amad. 25KG | 25[KG| 1,85] 6% | 46,25|
0551 Salsa Frisada Molho 20] UN 6%

Output: {"supplier":null,"lines":[{"productCode":"0122","productDescription":"Batata Monalisa Sac 10kg","quantity":5.0,"unitPrice":8.60,"unit":"CX","taxRate":6.0},{"productCode":"0344","productDescription":"Tomate Chucha Amad. 25KG","quantity":25.0,"unitPrice":1.85,"unit":"KG","taxRate":6.0},{"productCode":"0551","productDescription":"Salsa Frisada Molho","quantity":20.0,"unitPrice":null,"unit":"UN","taxRate":6.0}]}

KEY RULES:
- Identify column headers FIRST, then map data columns. Different suppliers use different column orders.
- \"Qtd Enc\" = quantity ordered (use this); \"Qtd Ent\" = delivered (ignore).
- The row-end \"Total\" column is NOT unit price. Skip VAT rates (6%, 13%, 23%) as unit prices but EXTRACT them into the taxRate field.
- Extract unit from hints: Cx→CX, Kg→KG, Un→UN, Sac→KG, Molho→MOLHO, L→L, Ml→ML.
- Portuguese comma = decimal (1,15=1.15). Thousands use dot (1.000=1000).
- Extract ALL product lines. Never include header/divider/summary rows.
- Every line item MUST include taxRate (float or null). IVA column values (6%, 13%, 23%) → taxRate field.
- Return ONLY valid JSON, no markdown or extra text."""


@dataclass
class ParsedDocument:
    """Structured output from document analysis."""
    supplier: dict = field(default_factory=lambda: {
        "name": None,
        "nif": None,
        "address": None,
    })
    documentDate: Optional[str] = None
    documentNumber: Optional[str] = None
    lines: list = field(default_factory=list)
    totalNet: Optional[float] = None
    totalGross: Optional[float] = None

    def to_dict(self) -> dict:
        return {
            "supplier": self.supplier,
            "documentDate": self.documentDate,
            "documentNumber": self.documentNumber,
            "lines": self.lines,
            "totalNet": self.totalNet,
            "totalGross": self.totalGross,
        }


def analyze_document_with_llm(
    ocr_text: str,
    model: str = DEFAULT_MODEL,
    llm_url: str = LLM_BASE_URL,
    temperature: float = 0.1,
) -> ParsedDocument:
    """
    Send OCR text to llama-cpp LLM for structured document analysis.

    Uses the OpenAI-compatible /v1/chat/completions endpoint.

    Args:
        ocr_text: The raw text extracted by OCR
        model: llama-cpp model name
        llm_url: llama-cpp API base URL (OpenAI-compatible)
        temperature: LLM temperature (low = more deterministic)

    Returns:
        ParsedDocument with structured fields
    """
    chat_url = llm_url.rstrip("/") + "/chat/completions"

    USER_TEMPLATE_HEAD = """Now analyze this purchase order document and extract the structured data.
Return ONLY valid JSON, no other text.

OCR Text:
```
"""
    USER_TEMPLATE_TAIL = """```"""

    prompt_overhead_tokens = (
        _estimate_tokens(SYSTEM_PROMPT)
        + _estimate_tokens(FEW_SHOT_EXAMPLES)
        + _estimate_tokens(USER_TEMPLATE_HEAD)
        + _estimate_tokens(USER_TEMPLATE_TAIL)
    )
    available_tokens = LLM_MAX_CONTEXT - prompt_overhead_tokens - 512
    max_ocr_chars = max(1000, available_tokens * 4)

    original_len = len(ocr_text)
    truncated_text = _truncate_ocr_text(ocr_text, max_ocr_chars)

    messages = [
        {"role": "system", "content": SYSTEM_PROMPT},
        {"role": "user", "content": f"""{FEW_SHOT_EXAMPLES}

{USER_TEMPLATE_HEAD}{truncated_text}
{USER_TEMPLATE_TAIL}"""},
    ]

    logger.info(f"Sending document to LLM (llama-cpp) model: {model} at {chat_url}")
    logger.debug(f"OCR text length: {original_len} chars (sent: {len(truncated_text)} chars)")

    try:
        estimated_prompt_tokens = (
            prompt_overhead_tokens
            + _estimate_tokens(truncated_text)
        )
        dynamic_max_tokens = max(512, LLM_MAX_CONTEXT - estimated_prompt_tokens - 128)
        logger.debug(
            f"Est. prompt tokens: {estimated_prompt_tokens}, "
            f"max_tokens: {dynamic_max_tokens}, "
            f"context: {LLM_MAX_CONTEXT}"
        )

        response = requests.post(
            chat_url,
            json={
                "model": model,
                "messages": messages,
                "temperature": temperature,
                "max_tokens": dynamic_max_tokens,
            },
            timeout=120,
        )
        response.raise_for_status()
        result = response.json()

        llm_output = result["choices"][0]["message"]["content"].strip()
        logger.debug(f"LLM raw response: {llm_output[:500]}...")
        # DIAGNOSTIC: Log full LLM response
        logger.info(f"DIAGNOSTIC LLM FULL RESPONSE ({len(llm_output)} chars):\n{llm_output[:2000]}")

        parsed = _extract_json_from_llm_output(llm_output)

        if parsed:
            doc = _convert_to_parsed_document(parsed)
            fallback_doc = _fallback_parse(ocr_text)

            llm_lines = len(doc.lines)
            fb_lines = len(fallback_doc.lines)

            llm_prices = sum(1 for l in doc.lines if l.get('unitPrice'))
            fb_prices = sum(1 for l in fallback_doc.lines if l.get('unitPrice'))

            # DIAGNOSTIC: Log LLM vs fallback comparison with line details
            logger.info(
                f"DIAGNOSTIC PARSER COMPARISON: "
                f"LLM={llm_lines} lines/{llm_prices} prices, "
                f"Fallback={fb_lines} lines/{fb_prices} prices, "
                f"LLM supplier={doc.supplier.get('name')}, "
                f"LLM date={doc.documentDate}, "
                f"FB supplier={fallback_doc.supplier.get('name')}, "
                f"FB date={fallback_doc.documentDate}"
            )
            for i, line in enumerate(doc.lines[:5]):
                logger.info(f"DIAGNOSTIC LLM line[{i}]: code={line.get('productCode')}, desc={line.get('productDescription')}, qty={line.get('quantity')}, price={line.get('unitPrice')}")
            for i, line in enumerate(fallback_doc.lines[:5]):
                logger.info(f"DIAGNOSTIC FB line[{i}]: code={line.get('productCode')}, desc={line.get('productDescription')}, qty={line.get('quantity')}, price={line.get('unitPrice')}")

            # Prefer LLM result — it's more accurate at extracting structured data.
            # Only use fallback if LLM clearly failed (0 lines, no supplier, no document number, no prices).
            llm_failed = (
                llm_lines == 0
                or not doc.supplier.get('name')
                or not doc.documentNumber
                or llm_prices == 0
            )
            if llm_failed:
                if not ENABLE_FALLBACK:
                    logger.warning(
                        f"LLM result appears incomplete, returning as-is "
                        f"(fallback disabled, LLM: {llm_lines} lines / {llm_prices} prices)"
                    )
                    return doc
                logger.warning(
                    f"LLM result appears incomplete, preferring fallback "
                    f"(LLM: {llm_lines} lines / {llm_prices} prices, "
                    f"Fallback: {fb_lines} lines / {fb_prices} prices)"
                )
                return fallback_doc
            return doc

    except requests.exceptions.ConnectionError:
        logger.error(f"Cannot connect to LLM at {llm_url}")
        if not ENABLE_FALLBACK:
            raise
        logger.info("LLM not available, falling back to regex-based parsing")
        return _fallback_parse(ocr_text)
    except requests.exceptions.Timeout:
        logger.error("LLM request timed out")
        if not ENABLE_FALLBACK:
            raise
        return _fallback_parse(ocr_text)
    except Exception as e:
        logger.error(f"LLM analysis failed: {e}")
        if not ENABLE_FALLBACK:
            raise
        return _fallback_parse(ocr_text)


def _extract_json_from_llm_output(text: str) -> dict | None:
    """Extract JSON object from LLM output, handling markdown fences."""
    import re

    json_pattern = r'```(?:json)?\s*([\s\S]*?)```'
    matches = re.findall(json_pattern, text)
    if matches:
        for match in matches:
            try:
                return json.loads(match.strip())
            except json.JSONDecodeError:
                continue

    try:
        return json.loads(text)
    except json.JSONDecodeError:
        pass

    try:
        obj_pattern = r'\{[\s\S]*\}'
        match = re.search(obj_pattern, text)
        if match:
            return json.loads(match.group())
    except json.JSONDecodeError:
        pass

    return None


def _convert_to_parsed_document(data: dict) -> ParsedDocument:
    """Convert raw dict to ParsedDocument with validation."""
    doc = ParsedDocument()

    supplier = data.get("supplier") or {}
    doc.supplier = {
        "name": supplier.get("name") or None,
        "nif": _clean_nif(supplier.get("nif")),
        "address": supplier.get("address") or None,
    }

    doc.documentDate = data.get("documentDate") or None
    doc.documentNumber = data.get("documentNumber") or None

    raw_lines = data.get("lines") or []
    for line in raw_lines:
        if isinstance(line, dict):
            raw_desc = str(line.get("productDescription") or "").strip()
            raw_unit = str(line.get("unit") or "").strip().upper()
            raw_tax = line.get("taxRate")
            cleaned_line = {
                "productCode": str(line.get("productCode") or "").strip() or None,
                "productDescription": _clean_description(raw_desc) or None,
                "quantity": _to_float(line.get("quantity")),
                "unitPrice": _to_float(line.get("unitPrice")),
                "unit": raw_unit or None,
                "taxRate": _to_float(raw_tax) if raw_tax is not None else None,
            }
            if cleaned_line["productDescription"] or cleaned_line["productCode"]:
                doc.lines.append(cleaned_line)

    doc.totalNet = _to_float(data.get("totalNet"))
    doc.totalGross = _to_float(data.get("totalGross"))

    return doc


def _clean_description(desc: str) -> str:
    """
    Clean a product description extracted from OCR, removing
    bracket/pipe/percentage artifacts common in table-formatted documents.

    Examples:
      "fizz fesmanmmasososms | ex]"         -> "fizz fesmanmmasososms"
      "Tomate ChuchaAmad. 25 RG"            -> "Tomate ChuchaAmad. 25 RG"
      "fooor fosoapocoPórcia | is[k6| iáo[" -> "fooor fosoapocoPórcia"
      "Couve Coração Seleção | 12[UN|oss|"  -> "Couve Coração Seleção"
      "Batata Monalisa Sac 10kg | 5[Cx]"    -> "Batata Monalisa Sac 10kg"
      "[Couve Coração Seleção"              -> "Couve Coração Seleção"
      ", , , ,"                             -> ""  (all commas = empty)
    """
    import re

    if not desc:
        return desc

    desc = re.sub(r'^\[', '', desc).strip()
    desc = re.sub(r'\s*\].*$', '', desc).strip()
    desc = re.sub(
        r'\s*\|\s*\d*\[?[A-Za-z]*(?:\]?\s*\d*\s*%?.*)?$',
        '', desc
    ).strip()
    desc = re.sub(r'\s*\|\s*$', '', desc).strip()
    desc = re.sub(r'\s*\d+\s*%\s*$', '', desc).strip()
    desc = re.sub(r'\s*\[[^\]]*\]', '', desc).strip()
    desc = re.sub(r'\s*\)\s*$', '', desc).strip()
    desc = re.sub(r'\s{2,}', ' ', desc).strip()

    # Remove internal comma artifacts (e.g., ", ," from table cell edges misread by OCR)
    desc = re.sub(r'(?:\s*,\s*){2,}', ' ', desc).strip()
    # Remove dash/hyphen artifacts (e.g., "---" or "- - -" from table borders misread by OCR)
    # Also handles Unicode dash variants (– U+2013, — U+2014, − U+2212)
    desc = re.sub(r'(?:[-–—−]+\s*){2,}', ' ', desc).strip()
    desc = re.sub(r'\s{2,}', ' ', desc).strip()

    # Remove common OCR artifacts that remain after cleaning
    desc = re.sub(r'^[-–—−,.\s;|\])]+', '', desc).strip()
    desc = re.sub(r'[-–—−,.\s;|\])]+$', '', desc).strip()

    # If the description became only punctuation/spaces, return empty
    # À-ÿ range includes Portuguese accented characters (ã, ç, ê, ó, etc.)
    alpha_clean = re.sub(r'[^A-Za-z0-9À-ÿ]', '', desc)
    if len(alpha_clean) < 2:
        return ""

    return desc


def _parse_bracket_pipe_line(line: str) -> dict | None:
    """
    Parse a bracket/pipe table format line found in Portuguese supplier documents.

    Handles formats:
      - "0122 [Batata Monalisa Sac 10kg | 5[Cx] 8,60] 6% | 42,50)"
      - "0344 [Tomate Chucha Amad. 25KG | 25[KG| 1,85] 6% | 46,25|"
      - "0091 [Cebola Doce Pérola | 15|kG| 1.40] 6% | 21,00]"
      - "1102 [Couve Coração Seleção | 12|uN| 0.98] 6% | 11,40"
      - "0551 Salsa Frisada Molho 20] UN 6%"

    The structure is: CODE DESCRIPTION_WITH_UNITS | QTY[UNIT] PRICE] VAT% ...
    """
    import re

    stripped = line.strip()
    code_m = re.match(r'^([A-Za-z0-9]+(?:-[A-Za-z0-9]+)?)\s+', stripped)
    if not code_m:
        return None

    code = code_m.group(1)
    rest = stripped[code_m.end():].strip()

    # Detect if this looks like a bracket/pipe table line
    has_pipe = '|' in rest
    has_bracket_close = ']' in rest

    if not has_pipe and not has_bracket_close:
        return None

    # ── Strategy: split by | to isolate description and data ──
    if has_pipe:
        # Split on first pipe, but be careful — description may contain no pipe
        parts = rest.split('|', 1)
        desc_raw = parts[0].strip()
        data_raw = parts[1].strip() if len(parts) > 1 else rest

        # Clean description: remove [ at start, ] at end
        desc = re.sub(r'^\[?\s*', '', desc_raw)
        desc = re.sub(r'\s*$', '', desc)
    else:
        # No pipe — look for trailing bracket structure "20] UN 6%"
        # Split description before the bracket digits
        bracket_split = re.split(r'\s*(\d+)\s*\]', rest, maxsplit=1)
        if len(bracket_split) >= 3:
            desc = bracket_split[0].strip()
            data_raw = bracket_split[1] + ']' + bracket_split[2]
        else:
            return None

    # ── Extract qty and price from data part ──
    # First, extract unit from bracket markers [Cx], [KG], [UN], [KG|, |uN|, etc.
    unit_matches = re.findall(r'\[([A-Za-z]+)', data_raw)
    if not unit_matches:
        unit_matches = re.findall(r'\|([A-Za-z]+)\|', data_raw)
    unit = unit_matches[-1].upper() if unit_matches else None
    if unit and unit in {'KG', 'CX', 'UN', 'G', 'L', 'ML', 'LT', 'MM', 'CM', 'M', 'PCT', 'SAC', 'MOLHO', 'DOSE'}:
        pass
    else:
        # Also check for unit in parentheses or standalone after quantity
        unit_words = re.findall(r'\b(KG|CX|UN|G|ML|LT|L|M|PCT|SAC|MOLHO)\b', data_raw, re.IGNORECASE)
        unit = unit_words[-1].upper() if unit_words else None

    # Remove bracket content like [Cx], [KG], [UN], [KG| (unit markers)
    data_clean = re.sub(r'\[[A-Za-z|]+\]?', ' ', data_raw)
    # Remove closing brackets that are artifacts
    data_clean = re.sub(r'\]', ' ', data_clean)
    # Remove pipe separators
    data_clean = re.sub(r'\|', ' ', data_clean)
    # Extract VAT percentage from raw data (ex: "6%", "13%")
    tax_rate = None
    vat_pct = re.search(r'\b(\d{1,2})\s*%', data_raw)
    if vat_pct:
        tax_rate = float(vat_pct.group(1))

    # Remove trailing parenthesis and percent signs
    data_clean = re.sub(r'[\)%]', '', data_clean)

    numbers_raw = re.findall(r'(\d+(?:[.,]\d+)?)', data_clean)

    if len(numbers_raw) < 2:
        if len(numbers_raw) == 1:
            return {
                "productCode": code,
                "productDescription": _clean_description(desc),
                "quantity": _to_float(numbers_raw[0]),
                "unitPrice": None,
                "unit": unit,
                "taxRate": tax_rate,
            }
        return None

    numbers = [_to_float(n) for n in numbers_raw]

    # Heuristic: skip VAT rate percentages (typically 6, 13, 23)
    # and totals (which are larger than qty * price)
    filtered = []
    vat_rates = {6, 6.0, 13, 13.0, 23, 23.0}
    for n in numbers:
        if n is None:
            continue
        # Skip typical VAT rate values (only if we already have at least quantity)
        if n in vat_rates and len(filtered) >= 1:
            continue
        # Skip total values (approximately qty * price)
        if len(filtered) >= 2 and filtered[0] is not None and filtered[1] is not None:
            expected = filtered[0] * filtered[1]
            if expected > 0 and abs(n - expected) / expected < 0.15:
                continue
        filtered.append(n)

    if len(filtered) >= 2:
        qty = filtered[0]
        # If the second number looks like a VAT rate, don't use it as price
        price_candidate = filtered[1]
        if price_candidate in vat_rates:
            price = None
        else:
            price = price_candidate
    elif len(filtered) == 1:
        qty = filtered[0]
        price = None
    else:
        qty = numbers_raw[0] if numbers_raw else None
        price = numbers_raw[1] if len(numbers_raw) > 1 else None

    return {
        "productCode": code,
        "productDescription": _clean_description(desc),
        "quantity": qty if isinstance(qty, (int, float)) else _to_float(qty),
        "unitPrice": price if isinstance(price, (int, float)) else _to_float(price),
        "unit": unit,
        "taxRate": tax_rate,
    }


def _detect_column_structure(lines: list[str]) -> dict:
    """
    Detect column headers in a purchase order document and return a
    mapping of column type → position index (0-based).

    Scans lines for a header row with column labels like:
      "Ref.  Designação  Quant.  Pr. Unit.  Valor"
      "Cód.  Descrição   Qtd Enc  Qtd Ent  Preço  Total"

    Returns:
        dict with keys: code_col, desc_col, qty_col, price_col, total_col, unit_col
        Each value is a 0-based column index, or None if not detected.
        'column_count' gives the total number of columns found in the header.
        'header_line_idx' is the line index of the detected header.
    """
    import re

    # Known column header patterns mapped to canonical keys
    COLUMN_DEFS = {
        'code': [
            r'\bRef\.?\b', r'\bReferencia\b', r'\bC[oó]d\.?\b', r'\bC[oó]digo\b',
            r'\bCod\.?\b', r'\bArt\.?\b', r'\bArtigo\b', r'\bRef\b',
        ],
        'desc': [
            r'\bDescri[cç][aã]o\b', r'\bDesigna[cç][aã]o\b', r'\bArtigo\b',
            r'\bProduto\b', r'\bDesc\.?\b', r'\bDesign\.?\b',
        ],
        'qty': [
            r'\bQtd\.?\s*Enc', r'\bQuant\.?\s*Enc', r'\bQtd\.?\b', r'\bQuant\.?\b',
            r'\bQuantidade\b', r'\bQty\b', r'\bQtd\b', r'\bUnid\.?\b',
            r'\bUn\.?\b', r'\bUnidades?\b',
        ],
        'qty_delivered': [
            r'\bQtd\.?\s*Ent', r'\bQuant\.?\s*Ent', r'\bQtd\.?\s*Rec',
            r'\bEntregue\b', r'\bRecebido\b',
        ],
        'price': [
            r'\bPr\.?\s*Unit\.?\b', r'\bPre[cç]o\s*Unit\.?\b', r'\bP\.?\s*Unit\.?\b',
            r'\bValor\s*Unit\.?\b', r'\bPre[cç]o\b', r'\bUnit\.?\s*Price\b',
            r'\bPr\.?\b',
        ],
        'total': [
            r'\bTotal\b', r'\bValor\b', r'\bImport\.?\b', r'\bImport[aâ]ncia\b',
            r'\bL[ií]quido\b',
        ],
        'vat': [
            r'\bIVA\b', r'\bTaxa\b', r'\bIVA\s*%', r'\b%\s*IVA\b',
        ],
        'unit': [
            r'\bUn\.?\b', r'\bUN\b', r'\bUnidade\b', r'\bMedida\b',
        ],
    }

    # Lines that look like header separators (dashes, equals)
    separator_pattern = re.compile(r'^[\s\-_=]{5,}$')

    for idx, line in enumerate(lines):
        stripped = line.strip()
        if not stripped:
            continue
        if separator_pattern.match(stripped):
            continue
        # Skip lines that have a lot of numbers (likely data, not header)
        digit_ratio = sum(c.isdigit() for c in stripped) / max(len(stripped), 1)
        if digit_ratio > 0.3:
            continue

        # For each column type, check if any known pattern matches
        # and record the match position
        found_columns = {}  # canonical_key -> (start_pos, end_pos)

        for canonical_key, patterns in COLUMN_DEFS.items():
            for pattern in patterns:
                match = re.search(pattern, stripped, re.IGNORECASE)
                if match:
                    start = match.start()
                    end = match.end()
                    # Only keep the earliest match for this key, or prefer
                    # matches that don't overlap with already-found columns
                    if canonical_key not in found_columns:
                        found_columns[canonical_key] = (start, end)
                    break

        # Need at least 2 known columns to consider this a header row
        if len(found_columns) >= 2:
            # Sort columns by their position in the line
            sorted_cols = sorted(found_columns.items(), key=lambda x: x[1][0])

            # Build column structure
            col_map = {
                'code_col': None, 'desc_col': None, 'qty_col': None,
                'qty_delivered_col': None, 'price_col': None, 'total_col': None,
                'vat_col': None, 'unit_col': None,
                'column_count': len(sorted_cols),
                'header_line_idx': idx,
                'column_names': [key for key, _ in sorted_cols],
            }

            for col_idx, (canonical_key, _) in enumerate(sorted_cols):
                if canonical_key == 'code':
                    col_map['code_col'] = col_idx
                elif canonical_key == 'desc':
                    col_map['desc_col'] = col_idx
                elif canonical_key == 'qty':
                    col_map['qty_col'] = col_idx
                elif canonical_key == 'qty_delivered':
                    col_map['qty_delivered_col'] = col_idx
                elif canonical_key == 'price':
                    col_map['price_col'] = col_idx
                elif canonical_key == 'total':
                    col_map['total_col'] = col_idx
                elif canonical_key == 'vat':
                    col_map['vat_col'] = col_idx
                elif canonical_key == 'unit':
                    col_map['unit_col'] = col_idx

            logger.info(f"Detected column structure at line {idx}: {col_map['column_names']}")
            return col_map

    return {
        'code_col': None, 'desc_col': None, 'qty_col': None,
        'qty_delivered_col': None, 'price_col': None, 'total_col': None,
        'vat_col': None, 'unit_col': None,
        'column_count': 0, 'header_line_idx': None, 'column_names': [],
    }


def _parse_data_line_with_columns(
    line: str, col_map: dict
) -> dict | None:
    """
    Parse a data line using the detected column structure.

    Extracts code and description from tokens, then identifies
    quantity and unit price from trailing numeric tokens (accounting
    for variable-width descriptions). VAT rates and total column
    values are filtered out automatically.
    """
    import re

    stripped = line.strip()
    if not stripped:
        return None

    # Split line into tokens (words and numbers, preserving brackets)
    tokens = re.findall(r'(?:\[?[A-Za-zÀ-ÿ0-9.,%/)+-]+\]?)', stripped)
    tokens = [t.strip() for t in tokens if t.strip()]
    if not tokens:
        return None

    total_cols = col_map.get('column_count', 0)
    if total_cols < 2:
        return None

    result = {
        "productCode": None,
        "productDescription": None,
        "quantity": None,
        "unitPrice": None,
        "unit": None,
        "taxRate": None,
    }

    # ── Detect unit from tokens (before description parsing) ──
    unit_keywords = {'KG', 'CX', 'UN', 'G', 'L', 'ML', 'LT', 'M', 'PCT', 'SAC', 'MOLHO', 'EMB', 'PAR', 'DOSE'}
    for token in tokens:
        upper = token.strip('[]|()').upper()
        if upper in unit_keywords:
            result["unit"] = upper
            break

    # ── Extract VAT by scanning all tokens for % pattern ──
    # (header column index is unreliable because descriptions span variable tokens)
    for token in tokens:
        vat_match = re.match(r'^(\d{1,2})\s*%$', token)
        if vat_match:
            result["taxRate"] = float(vat_match.group(1))
            break

    # ── Extract code (first token if it matches code pattern) ──
    code_idx = col_map.get('code_col')
    code_token = None
    if code_idx is not None and code_idx < len(tokens):
        code_token = tokens[code_idx]
    if code_token and re.match(r'^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)?$', code_token):
        result["productCode"] = code_token
    else:
        code_token = None  # no valid code found

    # ── Compute description range ──
    # Description starts after code; ends before the trailing numeric block
    # (quantity → unit → price → vat → total).  Embedded numbers in the
    # description ("Pack 4", "25KG") must NOT split it, so we identify the
    # boundary by scanning forward for the *last* isolated numeric token
    # that is part of the multi-number trailing block.
    desc_idx = col_map.get('desc_col')
    desc_start = desc_idx if desc_idx is not None else (code_idx + 1 if code_idx is not None else 0)
    desc_end = len(tokens)

    if desc_start < len(tokens):
        # Collect numeric token positions after desc_start
        num_positions = []
        for i in range(desc_start, len(tokens)):
            if re.match(r'^\d+(?:[.,]\d+)?$', tokens[i]):
                num_positions.append(i)

        if num_positions:
            # Walk backwards through numeric positions to find the start
            # of the trailing numeric block.  A gap of 1–2 non-numeric
            # tokens (unit, vat %) is expected between qty/price/total.
            # A gap of 3+ tokens means the number is embedded in the
            # description, not part of the numeric columns.
            block_start = num_positions[-1]  # last number = total
            prev_pos = num_positions[-1]
            for pos in reversed(num_positions[:-1]):
                gap = prev_pos - pos - 1
                if gap <= 2:
                    # Small gap — still in the trailing numeric block
                    block_start = pos
                    prev_pos = pos
                else:
                    # Large gap — this number is in the description.
                    # Description boundary is right after this gap.
                    break
            desc_end = block_start

    # Parse description from the identified token range
    if desc_start < desc_end:
        desc_parts = tokens[desc_start:desc_end]
        # Filter out tokens that look like VAT rates, standalone numbers,
        # unit keywords (handled separately), or bracket artifacts
        desc_clean = ' '.join(
            t for t in desc_parts
            if not re.match(r'^(?:6|13|23)\s*%?$', t)
            and not re.match(r'^\d+([.,]\d+)?\s*%?$', t)
            and t.strip('[]|()').upper() not in unit_keywords
            and t not in {'[', ']', '|', '(', ')'}
        ).strip()
        if desc_clean:
            result["productDescription"] = _clean_description(desc_clean)

    # ── Extract quantity and price from trailing numeric tokens ──
    # Instead of using absolute column indices (which break when
    # description width varies), extract ALL numbers from the trailing
    # section and use the same heuristic as the legacy fallback parser.
    trailing = tokens[desc_end:]
    numeric_vals = []
    for t in trailing:
        # Only keep pure numbers (not units, not VAT percentages)
        if re.match(r'^\d+(?:[.,]\d+)?$', t):
            val = _to_float(t)
            if val is not None:
                numeric_vals.append(val)
        elif re.match(r'^\d+\s*%$', t):
            # VAT percentage token — skip
            pass

    vat_rates = {6, 6.0, 13, 13.0, 23, 23.0}

    if len(numeric_vals) >= 2:
        # Heuristic: skip VAT-like values and totals
        # A total ≈ qty × price within 15% tolerance
        filtered = []
        for val in numeric_vals:
            if val in vat_rates and len(filtered) >= 1:
                continue
            if len(filtered) >= 2 and filtered[-2] and filtered[-1]:
                expected = filtered[-2] * filtered[-1]
                if expected > 0 and abs(val - expected) / expected < 0.15:
                    continue
            filtered.append(val)

        if len(filtered) >= 2:
            result["quantity"] = filtered[-2]
            result["unitPrice"] = filtered[-1]
        elif len(filtered) == 1:
            result["quantity"] = filtered[0]
    elif len(numeric_vals) == 1:
        result["quantity"] = numeric_vals[0]

    # ── Quality checks ──
    desc = result.get("productDescription") or ""
    code = result.get("productCode") or ""
    qty = result.get("quantity")
    price = result.get("unitPrice")

    # Reject lines with no description AND no code
    if not desc and not code:
        return None

    # Reject lines with no numeric data (qty and price both None) —
    # these are likely header/date/address fragments, not products.
    # Let the legacy parsers have a chance instead.
    if qty is None and price is None:
        return None

    # Reject lines with very long descriptions (10+ words) and no
    # product code — these are likely merged address/header fragments
    desc_word_count = len(desc.split())
    if not code and desc_word_count >= 10:
        return None

    # Reject lines whose tokens (including trailing) contain address/contact
    # patterns: NIF (9 consecutive digits), phone numbers (3 groups of 3-4
    # digits), emails, or VAT prefixes (PT, ES, etc.)
    # Check the raw tokens, not just the cleaned description.
    raw_tokens_text = ' '.join(tokens)
    if re.search(r'\b\d{9}\b', raw_tokens_text):
        return None
    # Phone: 3 groups of digits (e.g. "253 222 111" or "+351 253 222 111")
    if re.search(r'(?:\+?\d{2,4}\s+)?\d{3,4}\s+\d{3,4}\s+\d{3,4}\b', raw_tokens_text):
        return None
    if re.search(r'\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b', raw_tokens_text):
        return None

    # Reject lines whose description consists entirely of header-keywords
    header_keywords = {
        'nota', 'de', 'encomenda', 'fornecedor', 'entrega', 'prevista',
        'documento', 'data', 'emissao', 'emissão', 'código', 'artigo',
        'descrição', 'descricao', 'qtd', 'quant', 'quantidade',
        'preço', 'preco', 'total', 'linha', 'nif', 'tel', 'contacto',
        'contribuinte', 'página', 'pagina', 'obs',
    }
    desc_words = set(desc.lower().split())
    if desc_words and desc_words.issubset(header_keywords):
        logger.debug(f"Column parser: rejected header-like line: {desc[:60]}")
        return None

    # Reject lines that look like URL or file paths
    if re.match(r'^file\s*:', desc, re.IGNORECASE):
        return None

    # Reject lines that have a code but description is only a date fragment
    if code and not desc:
        return None

    # For lines with only a code and no useful description: reject
    alpha_clean = re.sub(r'[^A-Za-zÀ-ÿ]', '', desc)
    if code and len(alpha_clean) < 2 and (qty is None and price is None):
        return None

    return result


def _fallback_parse(ocr_text: str) -> ParsedDocument:
    """
    Fallback parser when LLM is unavailable.
    Uses regex patterns AND column header detection to extract information.
    """
    import re

    doc = ParsedDocument()
    lines = ocr_text.split('\n')

    # ── Detect column structure FIRST ──
    col_map = _detect_column_structure(lines)

    # ── Supplier name extraction ──
    # Strategy 1: Look for "FORNECEDOR:" label
    for line in lines:
        m = re.search(r'FORNECEDOR:\s*(.+)', line, re.IGNORECASE)
        if m:
            name = m.group(1).strip()
            name = re.sub(r'\s+\d{2}/\d{2}/\d{4}.*$', '', name)
            name = re.sub(r'\s+NIF:.*$', '', name, flags=re.IGNORECASE)
            name = re.sub(r'\s+CONTACTO:.*$', '', name, flags=re.IGNORECASE)
            doc.supplier["name"] = name.strip()
            break

    # Strategy 2: Look for known company suffixes (Lda, SA, etc.)
    # Find company name by extracting text near the suffix,
    # removing any digit-heavy prefixes (NIF, phone, zip).
    if not doc.supplier["name"]:
        company_suffixes = r'\b(Lda\.?|S\.A\.|S\.?A\b|Unipessoal|LTDA|Ltda\.?|CRL|S\.C\.)'
        for line in lines:
            suffix_m = re.search(company_suffixes, line, re.IGNORECASE)
            if suffix_m:
                # Take text before the suffix (up to 80 chars)
                raw_before = line[:suffix_m.start()]
                # Split on large digit blocks (4+ digits = NIF/phone/zip)
                # and take the LAST segment — that's the company name
                segments = re.split(r'\s*\b\d{4,}\b\s*', raw_before)
                candidate = segments[-1].strip() if segments else raw_before.strip()
                # Remove leading non-alpha characters
                candidate = re.sub(r'^[^A-Za-zÀ-ÿ]+', '', candidate)
                # Trim to last 60 chars if too long
                if len(candidate) > 60:
                    candidate = candidate[-60:].lstrip(',. ')
                if len(candidate) >= 4:
                    # Include the suffix in the name
                    full_name = (candidate + ' ' + suffix_m.group(0)).strip()
                    doc.supplier["name"] = full_name
                    break

    # Strategy 3: Fallback — look for lines with company suffix anywhere
    if not doc.supplier["name"]:
        for line in lines:
            m = re.search(
                r'([A-Za-zÀ-ÿ][A-Za-zÀ-ÿ\s&.,]{3,60}?)\s*\b(Lda\.?|S\.A\.|S\.?A\b|Unipessoal|LTDA)\b',
                line, re.IGNORECASE
            )
            if m:
                doc.supplier["name"] = m.group(1).strip()
                break

    # ── NIF extraction ──
    for line in lines:
        m = re.search(r'NIF:\s*([\d\s]{9,})', line, re.IGNORECASE)
        if m:
            nif_raw = m.group(1).strip()
            nif_clean = re.sub(r'\s+', '', nif_raw)
            if len(nif_clean) >= 9:
                doc.supplier["nif"] = nif_clean[:9]
                break

    # ── Date extraction ──
    # Try multiple date patterns: "DATA DA ENCOMENDA:", "Data Emissão:",
    # "Entrega Prevista:", generic "Data:" followed by date, or plain date patterns
    date_patterns = [
        r'DATA\s+DA\s+ENCOMENDA:\s*(\d{2})[/-](\d{2})[/-](\d{4})',
        r'Entrega\s+Prevista\s*:?\s*(\d{2})[/-](\d{2})[/-](\d{4})',
        r'Data\s+Emiss[ãa]o\s*:?\s*(\d{2})[/-](\d{2})[/-](\d{4})',
        r'\bData\b[^:]*:\s*(\d{2})[/-](\d{2})[/-](\d{4})',
        # Plain date on its own line: "20/05/2025" or "20-05-2025"
        r'^(\d{2})[/-](\d{2})[/-](\d{4})\s*$',
    ]
    for line in lines:
        for pattern in date_patterns:
            m = re.search(pattern, line, re.IGNORECASE)
            if m:
                day, month, year = m.group(1), m.group(2), m.group(3)
                doc.documentDate = f"{year}-{month}-{day}"
                break
        if doc.documentDate:
            break

    # ── Document number extraction ──
    # Try multiple patterns: "ENCOMENDA Nº", "Documento Nº:", "NE-...", etc.
    doc_num_patterns = [
        r'ENCOMENDA\s+N[ºo]\s*([\d/]+)',
        r'Documento\s+N[ºo]\s*:?\s*([A-Z0-9\-/]+)',
        r'\bNE[-\s](\d{4}[-\s]?\d+)',
    ]
    for line in lines:
        for pattern in doc_num_patterns:
            m = re.search(pattern, line, re.IGNORECASE)
            if m:
                doc.documentNumber = m.group(1).strip()
                break
        if doc.documentNumber:
            break

    header_idx = col_map.get('header_line_idx')

    # ── Build a set of header-like keywords for early exclusion ──
    _HEADER_KEYWORDS = {
        'nota', 'de', 'encomenda', 'fornecedor', 'entrega', 'prevista',
        'documento', 'data', 'emissao', 'emissão', 'código', 'codigo', 'artigo',
        'descrição', 'descricao', 'designação', 'designacao',
        'qtd', 'quant', 'quantidade', 'qty',
        'preço', 'preco', 'unitário', 'unitario',
        'total', 'linha', 'nif', 'tel', 'telefone', 'contacto',
        'contribuinte', 'página', 'pagina', 'obs', 'rua',
        'ref', 'referencia', 'referência',
    }
    _HEADER_PREFIX_PATTERN = re.compile(
        r'^(?:NOTA\s+DE|DATA|EMISS[ÃA]O|ENTREGA|PREVISTA|'
        r'C[ÓO]DIGO|ARTIGO|DESCRI[ÇC][ÃA]O|'
        r'TOTAL|SUBTOTAL|IVA|EUR|NIF|CONTRIBUINTE|'
        r'P[ÁA]GINA|OBS|TEL|TELEFONE|CONTACTO|'
        r'DOCUMENTO|FORNECEDOR|RUA|AV\.|AVENIDA)',
        re.IGNORECASE
    )
    _URL_PATTERN = re.compile(r'^(?:file|http)s?[:/]', re.IGNORECASE)
    _DATE_ONLY_PATTERN = re.compile(r'^\d{1,2}[/-]\d{1,2}[/-]\d{2,4}[,\s]')
    _PAGE_NUM_PATTERN = re.compile(r'^\d+\s*/\s*\d+\s*$')

    for idx, line in enumerate(lines):
        line_stripped = line.strip()
        if not line_stripped:
            continue

        # Skip the header row itself
        if header_idx is not None and idx == header_idx:
            continue

        # Skip separator lines (dashes, equals)
        if re.match(r'^[\s\-_=]{5,}$', line_stripped):
            continue

        # ── Early exclusion: skip obvious non-product lines ──
        if _URL_PATTERN.match(line_stripped):
            continue
        if _PAGE_NUM_PATTERN.match(line_stripped):
            continue
        if _DATE_ONLY_PATTERN.match(line_stripped):
            continue

        # Lines composed entirely of header keywords are not products
        words = set(
            re.sub(r'[^A-Za-zÀ-ÿ0-9]', ' ', line_stripped.lower()).split()
        )
        if words and words.issubset(_HEADER_KEYWORDS):
            continue

        # ── Try bracket/pipe parser first (handles supplier table format) ──
        bracket_result = _parse_bracket_pipe_line(line_stripped)
        if bracket_result:
            desc = bracket_result.get("productDescription", "")
            excluded = r'^(?:TOTAL|SUBTOTAL|IVA|REF|ARTIGO|COD|VALOR\s+TOTAL)'
            if desc and not re.match(excluded, desc, re.IGNORECASE):
                doc.lines.append(bracket_result)
                continue

        # ── Try column-structure-based parsing ──
        if col_map.get('column_count', 0) >= 2:
            col_result = _parse_data_line_with_columns(line_stripped, col_map)
            if col_result:
                desc = col_result.get("productDescription") or ""
                code = col_result.get("productCode")
                qty = col_result.get("quantity")
                price = col_result.get("unitPrice")
                desc_words = desc.split()
                tokens_in_line = len(re.findall(r'\S+', line_stripped))
                # Quality check: reject low-quality column parser results
                # so legacy parsers get a chance.
                is_low_quality = (
                    (code is None and len(desc_words) <= 2 and tokens_in_line >= 5)
                    or (qty is None and price is None and code is not None and len(desc_words) <= 3)
                )
                if desc and not is_low_quality:
                    doc.lines.append(col_result)
                    continue

        # ── Legacy parsers below (for documents without detectable headers) ──
        line_clean = line.replace('€', '').replace(',', '.').strip()

        # Extract VAT from the raw line before cleaning
        legacy_tax_rate = None
        vat_pct_legacy = re.search(r'\b(\d{1,2})\s*%', line)
        if vat_pct_legacy:
            legacy_tax_rate = float(vat_pct_legacy.group(1))

        code_m = re.match(r'^([A-Za-z0-9]+(?:-[A-Za-z0-9]+)?)\s+', line_clean)
        if code_m:
            code = code_m.group(1)
            # Skip lines where the code portion is a header/address keyword
            if re.match(
                r'^(?:NOTA|DATA|ENTREGA|PREVISTA|DOCUMENTO|EMISS[AÃ]O|'
                r'C[ÓO]DIGO|DESCRI[ÇC][ÃA]O|QTD|TOTAL|FORNECEDOR|NIF|TEL|'
                r'CONTRIBUINTE|RUA|AV\.|P[ÁA]GINA|OBS|IVA|EUR)$',
                code, re.IGNORECASE
            ):
                # This is a header/address fragment, not a product — skip
                pass
            else:
                rest = line_clean[code_m.end():].strip()

                trailing_match = re.search(r'((?:\d+(?:\.\d+)?(?:\s+|$))+)\s*$', rest)
                if trailing_match:
                    num_tokens = re.findall(r'\d+(?:\.\d+)?', trailing_match.group(1))
                else:
                    num_tokens = []

                if len(num_tokens) >= 2:
                    if len(num_tokens) >= 4:
                        qty_idx = -3
                        price_idx = -2
                    elif len(num_tokens) >= 3:
                        try:
                            second_last = float(num_tokens[-2])
                            last = float(num_tokens[-1])
                            if second_last > 0 and last / second_last > 1.5:
                                qty_idx = -3
                                price_idx = -2
                            else:
                                qty_idx = -3
                                price_idx = -1
                        except (ValueError, ZeroDivisionError):
                            qty_idx = -3
                            price_idx = -1
                    else:
                        qty_idx = -2
                        price_idx = -1

                    qty_str = num_tokens[qty_idx]
                    price_str = num_tokens[price_idx]

                    desc = rest
                    for tok in reversed(num_tokens):
                        desc = re.sub(r'\s*' + re.escape(tok) + r'\s*$', '', desc, count=1)
                    desc = desc.strip()

                    if desc:
                        doc.lines.append({
                            "productCode": code,
                            "productDescription": _clean_description(desc),
                            "quantity": _to_float(qty_str),
                            "unitPrice": _to_float(price_str),
                            "taxRate": legacy_tax_rate,
                        })
                        continue

        m_simple = re.match(
            r'^([A-Za-z0-9]+(?:-[A-Za-z0-9]+)?)\s+'
            r'(.+?)\s+'
            r'(\d+(?:\.\d+)?)\s+'
            r'(\d+(?:\.\d+)?)\s*$',
            line_clean
        )
        if m_simple:
            code = m_simple.group(1)
            desc = m_simple.group(2).strip()
            # Also reject header-like codes
            code_is_header = bool(re.match(
                r'^(?:NOTA|DATA|ENTREGA|PREVISTA|DOCUMENTO|EMISS[AÃ]O|'
                r'C[ÓO]DIGO|DESCRI[ÇC][ÃA]O|QTD|TOTAL|FORNECEDOR|NIF|TEL|'
                r'CONTRIBUINTE|RUA|AV\.|P[ÁA]GINA|OBS|IVA|EUR)$',
                code, re.IGNORECASE
            ))
            excluded = r'^(?:TOTAL|SUBTOTAL|IVA|EUR|NIF|CONTRIBUINTE|DATA|P[AÁ]GINA|OBS|REF|ARTIGO|COD|NOTA|ENCOMENDA|ENTREGA|PREVISTA|DOCUMENTO|EMISS[ÃA]O|FORNECEDOR|TEL|TELEFONE|C[ÓO]DIGO|DESCRI[ÇC][ÃA]O)'
            if desc and not code_is_header and not re.match(excluded, desc, re.IGNORECASE) and not _HEADER_PREFIX_PATTERN.match(desc):
                doc.lines.append({
                    "productCode": code,
                    "productDescription": _clean_description(desc),
                    "quantity": float(m_simple.group(3)),
                    "unitPrice": float(m_simple.group(4)),
                })
                continue

        m2 = re.match(
            r'^'
            r'([A-Za-z0-9]+(?:-[A-Za-z0-9]+)?)\s*'
            r'\[?'
            r'(.+?)\s*'
            r'(?:\|\s*)?'
            r'(\d+(?:[.,]\d+)?)\s*'
            r'(?:\[?[A-Za-z]+\]?\s*)?'
            r'(\d+(?:[.,]\d+)?)\s*'
            r'(?:\s+.*)?$',
            line_stripped
        )
        if m2:
            code = m2.group(1)
            desc = m2.group(2).strip()
            desc = _clean_description(desc)
            code_is_header = bool(re.match(
                r'^(?:NOTA|DATA|ENTREGA|PREVISTA|DOCUMENTO|EMISS[AÃ]O|'
                r'C[ÓO]DIGO|DESCRI[ÇC][ÃA]O|QTD|TOTAL|FORNECEDOR|NIF|TEL|'
                r'CONTRIBUINTE|RUA|AV\.|P[ÁA]GINA|OBS|IVA|EUR)$',
                code, re.IGNORECASE
            ))
            excluded = r'^(?:TOTAL|SUBTOTAL|IVA|EUR|NIF|CONTRIBUINTE|DATA|P[AÁ]GINA|OBS|REF|ARTIGO|COD|NOTA|ENCOMENDA|ENTREGA|PREVISTA|DOCUMENTO|EMISS[ÃA]O|FORNECEDOR|TEL|TELEFONE|C[ÓO]DIGO|DESCRI[ÇC][ÃA]O)'
            if desc and not code_is_header and not re.match(excluded, desc, re.IGNORECASE) and not _HEADER_PREFIX_PATTERN.match(desc):
                qty_str = m2.group(3).replace(',', '.')
                price_str = m2.group(4).replace(',', '.')

                doc.lines.append({
                    "productCode": code,
                    "productDescription": desc,
                    "quantity": _to_float(qty_str),
                    "unitPrice": _to_float(price_str),
                })
                continue

        # ── Pattern B2: Description only + trailing numbers (no code prefix) ──
        # Handles lines like "Arroz Agulha 20 1,15" without a product code
        no_code_match = re.match(
            r'^([A-Za-zÀ-ÿ0-9][A-Za-zÀ-ÿ0-9\s\-\.\+]+?)\s+'
            r'(\d+(?:[.,]\d+)?)\s+'
            r'(\d+(?:[.,]\d+)?)\s*$',
            line_stripped
        )
        if no_code_match:
            desc = no_code_match.group(1).strip()
            desc = _clean_description(desc)
            qty = _to_float(no_code_match.group(2))
            price = _to_float(no_code_match.group(3))
            excluded = r'^(?:TOTAL|SUBTOTAL|IVA|REF|ARTIGO|COD|VALOR\s+TOTAL|DATA|ENCOMENDA|FORNECEDOR|NIF|CONTRIBUINTE|NOTA|ENTREGA|PREVISTA|DOCUMENTO|EMISS[ÃA]O|TEL|TELEFONE|C[ÓO]DIGO|DESCRI[ÇC][ÃA]O)'
            if desc and not re.match(excluded, desc, re.IGNORECASE) and not _HEADER_PREFIX_PATTERN.match(desc):
                doc.lines.append({
                    "productCode": None,
                    "productDescription": desc,
                    "quantity": qty,
                    "unitPrice": price,
                })
                continue

        m3 = re.match(
            r'^([A-Za-z0-9]+(?:-[A-Za-z0-9]+)?)\s+'
            r'(.+)',
            line_stripped
        )
        if m3:
            desc = m3.group(2).strip()
            desc = _clean_description(desc)
            code = m3.group(1)
            excluded_patterns = (
                r'^(?:REF|ARTIGO|COD|TOTAL|SUBTOTAL|IVA|EUR|NIF|CONTRIBUINTE|'
                r'DATA|P[AÁ]GINA|OBS|NOTA|ENCOMENDA|ENTREGA|PREVISTA|'
                r'DOCUMENTO|EMISS[ÃA]O|FORNECEDOR|TEL|TELEFONE|'
                r'C[ÓO]DIGO|DESCRI[ÇC][ÃA]O|QTD|RUA|AV\.)'
            )
            # Also check the code portion — if it looks like a header keyword, skip
            code_is_header = bool(re.match(
                r'^(?:NOTA|DATA|ENTREGA|PREVISTA|DOCUMENTO|EMISS[AÃ]O|'
                r'C[ÓO]DIGO|DESCRI[ÇC][ÃA]O|QTD|TOTAL|FORNECEDOR|NIF|TEL|'
                r'CONTRIBUINTE|RUA|AV\.|P[ÁA]GINA|OBS|IVA|EUR)$',
                code, re.IGNORECASE
            ))
            if desc and not code_is_header and not re.match(excluded_patterns, desc, re.IGNORECASE) and not _HEADER_PREFIX_PATTERN.match(desc):
                desc_clean = re.sub(r'\W+', '', desc)
                if len(desc_clean) >= 3:
                    doc.lines.append({
                        "productCode": m3.group(1),
                        "productDescription": desc,
                        "quantity": None,
                        "unitPrice": None,
                    })

    for line in lines:
        line_clean = line.replace('€', '').replace(',', '.').strip()
        m = re.search(r'TOTAL\s+SEM\s+IVA\s*([\d.]+)', line_clean, re.IGNORECASE)
        if m:
            doc.totalNet = float(m.group(1))
        m = re.search(r'TOTAL\s+COM\s+IVA\s*([\d.]+)', line_clean, re.IGNORECASE)
        if m:
            doc.totalGross = float(m.group(1))
        m = re.search(r'Total\s+L[ií]quido\s*:?\s*([\d.,]+)', line_clean, re.IGNORECASE)
        if m:
            doc.totalNet = _to_float(m.group(1))

    return doc


def _estimate_tokens(text: str) -> int:
    """Rough token count estimate (~4 chars per token)."""
    return max(1, len(text) // 4)


def _truncate_ocr_text(ocr_text: str, max_chars: int) -> str:
    """Truncate OCR text to fit within a character budget, preserving line structure."""
    if len(ocr_text) <= max_chars:
        return ocr_text
    truncated = ocr_text[:max_chars]
    last_newline = truncated.rfind('\n')
    if last_newline > max_chars * 0.8:
        truncated = truncated[:last_newline]
    logger.warning(
        f"OCR text truncated from {len(ocr_text)} to {len(truncated)} chars "
        f"({_estimate_tokens(ocr_text)} to {_estimate_tokens(truncated)} est. tokens)"
    )
    return truncated


def _clean_nif(nif_value: str | None) -> str | None:
    """Clean NIF: remove spaces, keep only digits, ensure 9 chars."""
    if not nif_value:
        return None
    digits = re.sub(r'\D', '', str(nif_value))
    return digits[:9] if len(digits) >= 9 else None


def _to_float(value) -> float | None:
    """Safely convert a value to float, handling comma as decimal separator."""
    if value is None:
        return None
    try:
        if isinstance(value, str):
            value = value.strip().replace('€', '').replace(' ', '')
            if ',' in value and '.' in value:
                value = value.replace('.', '').replace(',', '.')
            elif ',' in value:
                value = value.replace(',', '.')
            return float(value)
        return float(value)
    except (ValueError, TypeError):
        return None


import re
