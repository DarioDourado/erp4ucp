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

logger = logging.getLogger(__name__)

LLM_BASE_URL = os.environ.get("LLM_BASE_URL", "http://127.0.0.1:8080/v1")
DEFAULT_MODEL = os.environ.get("LLM_MODEL", "qwen2.5-7b-instruct-q4_k_m")

SYSTEM_PROMPT = """You are a specialized document understanding AI for Portuguese purchase orders (encomendas a fornecedores).

Your task is to analyze OCR text extracted from a purchase order document and extract structured data.

CRITICAL RULE — EXTRACT EVERY LINE:
- You MUST extract EVERY SINGLE product line present in the document. Do NOT skip or omit any line.
- Count how many product lines exist in the OCR text, and ensure your output contains the same number.
- If the OCR text shows 5 product references, your JSON must have 5 entries in the "lines" array.
- For bracket/pipe format tables, each row is a separate line — extract them ALL.

Rules:
1. Extract ONLY the information that is clearly present in the text.
2. For the supplier name, look for text near labels like "FORNECEDOR:", "Fornecedor", "Supplier".
3. For NIF, extract exactly 9 digits (remove any spaces or punctuation).
4. For dates, format as YYYY-MM-DD.
5. For each product line found in the document, extract: product code, description, quantity, unit price.
6. If a field cannot be determined, use null or empty string — but still include the line if any data is present.
7. Respond in VALID JSON format only, without any additional text or markdown formatting.
8. The response must be a valid JSON object that can be parsed directly.

IMPORTANT: The text comes from OCR and may have recognition errors. Use context to infer the correct values. Portuguese text may have accented characters that were misrecognized.

NOTE ABOUT TABLE FORMATS: Supplier documents often use table-like formats with brackets [ ] and pipes | to separate columns.
For example a line like: "0122 [Batata Monalisa Sac 10kg | 5[Cx] 8,60] 6% | 42,50)"
Should be parsed as: code="0122", description="Batata Monalisa Sac 10kg", quantity=5, unitPrice=8.60

The pattern is: CODE [DESCRIPTION | QTY unit PRICE] VAT% | TOTAL
- CODE comes first (2-4 digits)
- DESCRIPTION is between [ and | (or between [ and first numeric if no |)
- QTY is the first number after the description separator
- PRICE is the number after the QTY/unit
- Ignore VAT percentages (6%, 23%, etc.) and totals at the end of each line

Parse EVERY line with this format, even if some lines have garbled OCR text due to recognition errors."""

FEW_SHOT_EXAMPLES = """
Example 1 (simple format):
Input text:
```
ENCOMENDA Nº 2025/058
FORNECEDOR: Distribuidora Lusitânia, Lda
NIF: 500123456
DATA DA ENCOMENDA: 20/05/2025
1001  Arroz Agulha  20  1,15
1002  Massa Espiral  24  0,79
```

Output:
{
  "supplier": {
    "name": "Distribuidora Lusitânia, Lda",
    "nif": "500123456",
    "address": null
  },
  "documentDate": "2025-05-20",
  "documentNumber": "2025/058",
  "lines": [
    {
      "productCode": "1001",
      "productDescription": "Arroz Agulha",
      "quantity": 20.0,
      "unitPrice": 1.15
    },
    {
      "productCode": "1002",
      "productDescription": "Massa Espiral",
      "quantity": 24.0,
      "unitPrice": 0.79
    }
  ],
  "totalNet": null,
  "totalGross": null
}

Example 2 (table with headers):
Input text:
```
FACTURA Nº FT-2025/123
DATA: 15-03-2025
FORNECEDOR: Azeites do Sul, SA
NIF: 509876543
CONTRIBUINTE: 509876543
ARTIGO          QTD  PRECO  IVA
AZEITE VIRGEM 500ML  50  3.20  13%
AZEITE GALA 1L      30  5.50  13%
VINHO TINTO 75CL     20  2.80  23%
```

Output:
{
  "supplier": {
    "name": "Azeites do Sul, SA",
    "nif": "509876543",
    "address": null
  },
  "documentDate": "2025-03-15",
  "documentNumber": "FT-2025/123",
  "lines": [
    {
      "productCode": null,
      "productDescription": "AZEITE VIRGEM 500ML",
      "quantity": 50.0,
      "unitPrice": 3.20
    },
    {
      "productCode": null,
      "productDescription": "AZEITE GALA 1L",
      "quantity": 30.0,
      "unitPrice": 5.50
    },
    {
      "productCode": null,
      "productDescription": "VINHO TINTO 75CL",
      "quantity": 20.0,
      "unitPrice": 2.80
    }
  ],
  "totalNet": null,
  "totalGross": null
}

Example 3 (bracket/pipe table format — extract ALL 5 lines):
Input text:
```
0122 [Batata Monalisa Sac 10kg | 5[Cx] 8,60] 6% | 42,50)
0344 [Tomate Chucha Amad. 25KG | 25[KG| 1,85] 6% | 46,25|
0091 [Cebola Doce Pérola | 15|kG| 1.40] 6% | 21,00]
1102 [Couve Coração Seleção | 12|uN| 0.98] 6% | 11,40
0551 Salsa Frisada Molho 20] UN 6%
```

Output:
{
  "supplier": {
    "name": null,
    "nif": null,
    "address": null
  },
  "documentDate": null,
  "documentNumber": null,
  "lines": [
    {
      "productCode": "0122",
      "productDescription": "Batata Monalisa Sac 10kg",
      "quantity": 5.0,
      "unitPrice": 8.60
    },
    {
      "productCode": "0344",
      "productDescription": "Tomate Chucha Amad. 25KG",
      "quantity": 25.0,
      "unitPrice": 1.85
    },
    {
      "productCode": "0091",
      "productDescription": "Cebola Doce Pérola",
      "quantity": 15.0,
      "unitPrice": 1.40
    },
    {
      "productCode": "1102",
      "productDescription": "Couve Coração Seleção",
      "quantity": 12.0,
      "unitPrice": 0.98
    },
    {
      "productCode": "0551",
      "productDescription": "Salsa Frisada Molho",
      "quantity": 20.0,
      "unitPrice": null
    }
  ],
  "totalNet": null,
  "totalGross": null
}

IMPORTANT: Notice that in Example 3, ALL 5 lines are extracted even when:
- Line 2 has garbled OCR text around the numbers (Tomate Chucha Amad. 25KG)
- Line 5 has a different format (no brackets, code+description+number at end)
- Some lines have quantity embedded with unit markers like [Cx], [KG], [UN]
- Some unit prices are followed by VAT% and totals

ALWAYS extract every line from the document — never skip any product entry."""


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

    messages = [
        {"role": "system", "content": SYSTEM_PROMPT},
        {"role": "user", "content": f"""{FEW_SHOT_EXAMPLES}

Now analyze this purchase order document and extract the structured data.
Return ONLY valid JSON, no other text.

OCR Text:
```
{ocr_text}
```"""},
    ]

    logger.info(f"Sending document to LLM (llama-cpp) model: {model} at {chat_url}")
    logger.debug(f"OCR text length: {len(ocr_text)} chars")

    try:
        response = requests.post(
            chat_url,
            json={
                "model": model,
                "messages": messages,
                "temperature": temperature,
                "max_tokens": 4096,
            },
            timeout=120,
        )
        response.raise_for_status()
        result = response.json()

        llm_output = result["choices"][0]["message"]["content"].strip()
        logger.debug(f"LLM raw response: {llm_output[:500]}...")

        parsed = _extract_json_from_llm_output(llm_output)

        if parsed:
            doc = _convert_to_parsed_document(parsed)
            fallback_doc = _fallback_parse(ocr_text)

            llm_lines = len(doc.lines)
            fb_lines = len(fallback_doc.lines)

            llm_prices = sum(1 for l in doc.lines if l.get('unitPrice'))
            fb_prices = sum(1 for l in fallback_doc.lines if l.get('unitPrice'))

            if fb_lines > llm_lines or fb_prices > llm_prices:
                logger.warning(
                    f"Preferring fallback result over LLM "
                    f"(LLM: {llm_lines} lines / {llm_prices} prices, "
                    f"Fallback: {fb_lines} lines / {fb_prices} prices)"
                )
                return fallback_doc
            return doc

    except requests.exceptions.ConnectionError:
        logger.error(f"Cannot connect to LLM at {llm_url}")
        logger.info("LLM not available, falling back to regex-based parsing")
        return _fallback_parse(ocr_text)
    except requests.exceptions.Timeout:
        logger.error("LLM request timed out")
        return _fallback_parse(ocr_text)
    except Exception as e:
        logger.error(f"LLM analysis failed: {e}")
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
            cleaned_line = {
                "productCode": str(line.get("productCode") or "").strip() or None,
                "productDescription": _clean_description(raw_desc) or None,
                "quantity": _to_float(line.get("quantity")),
                "unitPrice": _to_float(line.get("unitPrice")),
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
    code_m = re.match(r'^(\d{2,4})\s+', stripped)
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
    # Remove bracket content like [Cx], [KG], [UN], [KG| (unit markers)
    data_clean = re.sub(r'\[[A-Za-z|]+\]?', ' ', data_raw)
    # Remove closing brackets that are artifacts
    data_clean = re.sub(r'\]', ' ', data_clean)
    # Remove pipe separators
    data_clean = re.sub(r'\|', ' ', data_clean)
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
    }


def _fallback_parse(ocr_text: str) -> ParsedDocument:
    """
    Fallback parser when LLM is unavailable.
    Uses regex patterns to extract basic information.
    """
    import re

    doc = ParsedDocument()
    lines = ocr_text.split('\n')

    for line in lines:
        m = re.search(r'FORNECEDOR:\s*(.+)', line, re.IGNORECASE)
        if m:
            name = m.group(1).strip()
            name = re.sub(r'\s+\d{2}/\d{2}/\d{4}.*$', '', name)
            name = re.sub(r'\s+NIF:.*$', '', name, flags=re.IGNORECASE)
            name = re.sub(r'\s+CONTACTO:.*$', '', name, flags=re.IGNORECASE)
            doc.supplier["name"] = name.strip()
            break

    if not doc.supplier["name"]:
        for line in lines:
            m = re.search(r'^([A-Za-zÀ-ÿ\s,]+(?:Lda|SA|S\.A\.|Unipessoal|LTDA|Lda\.))', line)
            if m:
                doc.supplier["name"] = m.group(1).strip()
                break

    for line in lines:
        m = re.search(r'NIF:\s*([\d\s]{9,})', line, re.IGNORECASE)
        if m:
            nif_raw = m.group(1).strip()
            nif_clean = re.sub(r'\s+', '', nif_raw)
            if len(nif_clean) >= 9:
                doc.supplier["nif"] = nif_clean[:9]
                break

    for line in lines:
        m = re.search(r'DATA\s+DA\s+ENCOMENDA:\s*(\d{2})[/-](\d{2})[/-](\d{4})', line, re.IGNORECASE)
        if m:
            day, month, year = m.group(1), m.group(2), m.group(3)
            doc.documentDate = f"{year}-{month}-{day}"
            break

    for line in lines:
        m = re.search(r'ENCOMENDA\s+N[ºo]\s*([\d/]+)', line, re.IGNORECASE)
        if m:
            doc.documentNumber = m.group(1).strip()
            break

    for line in lines:
        line_stripped = line.strip()
        if not line_stripped:
            continue

        # ── Try bracket/pipe parser first (handles supplier table format) ──
        bracket_result = _parse_bracket_pipe_line(line_stripped)
        if bracket_result:
            desc = bracket_result.get("productDescription", "")
            if desc and not re.match(r'^(?:TOTAL|SUBTOTAL|IVA|REF|ARTIGO|COD)', desc, re.IGNORECASE):
                doc.lines.append(bracket_result)
                continue

        line_clean = line.replace('€', '').replace(',', '.').strip()

        code_m = re.match(r'^(\d{2,})\s+', line_clean)
        if code_m:
            code = code_m.group(1)
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
                    })
                    continue

        m_simple = re.match(
            r'^(\d{2,})\s+'
            r'(.+?)\s+'
            r'(\d+(?:\.\d+)?)\s+'
            r'(\d+(?:\.\d+)?)\s*$',
            line_clean
        )
        if m_simple:
            desc = m_simple.group(2).strip()
            if desc and not re.match(r'^(?:TOTAL|SUBTOTAL|IVA)', desc, re.IGNORECASE):
                doc.lines.append({
                    "productCode": m_simple.group(1),
                    "productDescription": _clean_description(desc),
                    "quantity": float(m_simple.group(3)),
                    "unitPrice": float(m_simple.group(4)),
                })
                continue

        m2 = re.match(
            r'^'
            r'(\d{2,4})\s*'
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
            desc = m2.group(2).strip()
            desc = _clean_description(desc)

            qty_str = m2.group(3).replace(',', '.')
            price_str = m2.group(4).replace(',', '.')

            doc.lines.append({
                "productCode": m2.group(1),
                "productDescription": desc,
                "quantity": _to_float(qty_str),
                "unitPrice": _to_float(price_str),
            })
            continue

        m3 = re.match(
            r'^(\d{2,4})\s+'
            r'(.+)',
            line_stripped
        )
        if m3:
            desc = m3.group(2).strip()
            desc = _clean_description(desc)
            # Filter out headers, totals, and lines that look like non-product metadata
            excluded_patterns = r'^(?:REF|ARTIGO|COD|TOTAL|SUBTOTAL|IVA|EUR|NIF|CONTRIBUINTE|DATA|P[AÁ]GINA|OBS)'
            if desc and not re.match(excluded_patterns, desc, re.IGNORECASE):
                # Only add as a line if the description contains meaningful text
                # (not just isolated numbers or short garbage)
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

    return doc


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
