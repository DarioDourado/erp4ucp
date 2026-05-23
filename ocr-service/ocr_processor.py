"""
OCR Processor Module
====================
Handles image preprocessing and text extraction using EasyOCR (deep learning).
Provides layout-aware OCR with bounding box information.

Pipeline improvements:
- Perspective correction for angled/tilted document photos
- Deskew for rotation correction
- EasyOCR handles varying resolutions and contrast natively
"""
import cv2
import numpy as np
from PIL import Image
import easyocr
import re
from pathlib import Path
import logging

logger = logging.getLogger(__name__)


# ── EasyOCR Reader Singleton ─────────────────────────────────────────────────

_reader: easyocr.Reader | None = None


def get_reader() -> easyocr.Reader:
    global _reader
    if _reader is None:
        _reader = easyocr.Reader(['pt', 'en'], gpu=True)
        logger.info("EasyOCR Reader initialized (pt, en)")
    return _reader


# ── Geometry Helpers ──────────────────────────────────────────────────────────

def order_points(pts: np.ndarray) -> np.ndarray:
    """
    Order 4 corner points in clockwise order:
    top-left, top-right, bottom-right, bottom-left.

    Args:
        pts: Array of shape (4, 2) with unordered points

    Returns:
        Ordered array of shape (4, 2)
    """
    rect = np.zeros((4, 2), dtype="float32")

    # Sum: smallest sum → top-left, largest sum → bottom-right
    s = pts.sum(axis=1)
    rect[0] = pts[np.argmin(s)]
    rect[2] = pts[np.argmax(s)]

    # Difference: smallest diff → top-right, largest diff → bottom-left
    diff = np.diff(pts, axis=1)
    rect[1] = pts[np.argmin(diff)]
    rect[3] = pts[np.argmax(diff)]

    return rect


# ── Perspective Correction ────────────────────────────────────────────────────

def _try_perspective_correction(image: np.ndarray) -> np.ndarray:
    """
    Core perspective correction logic: detect a quadrilateral contour
    and warp the image to a top-down view.

    Returns:
        Perspective-corrected image, or None if no valid quad found.
    """
    height, width = image.shape[:2]
    total_area = width * height

    # ── Helper: apply perspective transform with margin ──────────────
    def _warp_with_margin(src_pts: np.ndarray) -> np.ndarray | None:
        ordered = order_points(src_pts)
        (tl, tr, br, bl) = ordered

        w = max(int(np.linalg.norm(br - bl)), int(np.linalg.norm(tr - tl)))
        h = max(int(np.linalg.norm(tr - br)), int(np.linalg.norm(tl - bl)))
        w = max(w, 100)
        h = max(h, 100)

        # Expand destination with 15% margin to avoid tight cropping
        margin_pct = 0.15
        margin_x = int(w * margin_pct)
        margin_y = int(h * margin_pct)
        dst_w = w + 2 * margin_x
        dst_h = h + 2 * margin_y

        dst = np.array([
            [margin_x, margin_y],
            [dst_w - 1 - margin_x, margin_y],
            [dst_w - 1 - margin_x, dst_h - 1 - margin_y],
            [margin_x, dst_h - 1 - margin_y],
        ], dtype="float32")

        M = cv2.getPerspectiveTransform(ordered, dst)
        warped = cv2.warpPerspective(
            image, M, (dst_w, dst_h),
            flags=cv2.INTER_CUBIC,
            borderMode=cv2.BORDER_REPLICATE,
        )
        return warped

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blurred = cv2.GaussianBlur(gray, (5, 5), 0)

    # ── Strategy 1: Canny edge detection ─────────────────────────────
    for low, high in [(50, 150), (30, 100), (70, 200)]:
        edges = cv2.Canny(blurred, low, high)
        dilated = cv2.dilate(edges, np.ones((3, 3), np.uint8), iterations=2)
        contours, _ = cv2.findContours(
            dilated, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE
        )
        if not contours:
            continue
        contours = sorted(contours, key=cv2.contourArea, reverse=True)
        for contour in contours[:5]:
            area = cv2.contourArea(contour)
            if area < 0.05 * total_area:
                continue
            peri = cv2.arcLength(contour, True)
            approx = cv2.approxPolyDP(contour, 0.02 * peri, True)
            if len(approx) == 4:
                result = _warp_with_margin(approx.reshape(4, 2).astype("float32"))
                if result is not None:
                    logger.info(f"Persp corr [Canny {low}/{high}]: area/ratio={area/total_area:.1%}")
                    return result

    # ── Strategy 2: Adaptive threshold + larger epsilon ──────────────
    binary = cv2.adaptiveThreshold(
        blurred, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY, 31, 10
    )
    dilated = cv2.dilate(binary, np.ones((5, 5), np.uint8), iterations=3)
    contours, _ = cv2.findContours(
        dilated, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE
    )
    if contours:
        contours = sorted(contours, key=cv2.contourArea, reverse=True)
        for contour in contours[:5]:
            area = cv2.contourArea(contour)
            if area < 0.05 * total_area:
                continue
            peri = cv2.arcLength(contour, True)
            for eps_mult in [0.03, 0.04, 0.05]:
                approx = cv2.approxPolyDP(contour, eps_mult * peri, True)
                if len(approx) == 4:
                    result = _warp_with_margin(approx.reshape(4, 2).astype("float32"))
                    if result is not None:
                        logger.info(f"Persp corr [adaptive eps={eps_mult}]: area/ratio={area/total_area:.1%}")
                        return result

    # ── Strategy 3: Convex hull fallback ─────────────────────────────
    edges = cv2.Canny(blurred, 50, 150)
    dilated = cv2.dilate(edges, np.ones((3, 3), np.uint8), iterations=2)
    contours, _ = cv2.findContours(
        dilated, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE
    )
    if contours:
        largest = max(contours, key=cv2.contourArea)
        hull = cv2.convexHull(largest)
        peri = cv2.arcLength(hull, True)
        approx = cv2.approxPolyDP(hull, 0.02 * peri, True)
        if len(approx) == 4:
            result = _warp_with_margin(approx.reshape(4, 2).astype("float32"))
            if result is not None:
                logger.info(f"Persp corr [convex hull]: area/ratio={cv2.contourArea(largest)/total_area:.1%}")
                return result

    return None


def correct_perspective(image: np.ndarray) -> np.ndarray:
    """
    Detect the document quadrilateral in the image and apply a 4-point
    perspective transform to obtain a flat, top-down view.

    This is **critical** for documents photographed at an angle (trapezoidal /
    keystone distortion). Without correction, text farther from the camera
    becomes compressed and unreadable by OCR.

    **Safeguard**: if the detected quadrilateral covers less than 65% of the
    total image area (after perspective warp + 15% margin expansion), it likely
    represents an inner content region (e.g. a table or box) rather than the
    full document page. In this case perspective correction is **skipped**
    and the original image is returned — a bad perspective crop is worse than
    no correction at all.

    Args:
        image: Input BGR image (numpy array)

    Returns:
        Perspective-corrected image if a valid large quadrilateral is found,
        otherwise the original image unchanged.
    """
    height, width = image.shape[:2]
    total_area = width * height

    # Attempt perspective correction
    warped = _try_perspective_correction(image)

    if warped is None:
        logger.info("Perspective correction: no quad found, returning original")
        return image

    # Evaluate result: if the corrected image is too small relative to
    # the original, the contour was likely an inner region, not the full page.
    warped_area = warped.shape[0] * warped.shape[1]
    area_ratio = warped_area / total_area

    MIN_AREA_RATIO = 0.65  # at least 65% of original image area — avoids cropping to inner regions

    if area_ratio < MIN_AREA_RATIO:
        logger.warning(
            f"Perspective correction SKIPPED — result too small "
            f"({warped.shape[1]}x{warped.shape[0]} = {area_ratio:.1%} of "
            f"original {width}x{height}). Using original image."
        )
        return image

    logger.info(
        f"Perspective correction APPLIED: "
        f"{width}x{height} -> {warped.shape[1]}x{warped.shape[0]} "
        f"(ratio={area_ratio:.1%}, threshold={MIN_AREA_RATIO:.0%})"
    )
    return warped


# ── Main Preprocessing Pipeline ───────────────────────────────────────────────

def preprocess_image(image_path: str | Path,
                     output_path: str | Path | None = None) -> np.ndarray:
    """
    Preprocess an image for EasyOCR.

    **IMPORTANT**: Returns an *RGB* image (EasyOCR expects RGB, not grayscale).
    EasyOCR performs its own internal contrast handling via its deep learning
    models, so aggressive thresholding/sharpen is unnecessary.

    Pipeline:
    1. Load image
    2. Perspective correction — flatten angled document photos
    3. Upscale to ≥800px shortest side (moderate, for small images)
    4. Deskew — rotation correction
    5. Convert to RGB

    Args:
        image_path: Path to the input image (JPEG, PNG, TIFF)
        output_path: Optional path to save the preprocessed image for debugging

    Returns:
        Preprocessed RGB image as numpy array
    """
    # ── 1. Load image ─────────────────────────────────────────────────
    img = cv2.imread(str(image_path))
    if img is None:
        raise ValueError(f"Could not load image from {image_path}")

    height, width = img.shape[:2]
    logger.info(f"Original image size: {width}x{height}")

    # ── 2. Perspective correction ─────────────────────────────────────
    # MUST be done early, before any cropping/thresholding removes edge info
    img = correct_perspective(img)
    height, width = img.shape[:2]

    # ── 3. Upscale small images ───────────────────────────────────────
    # Target: at least 800px on the shortest side for good OCR
    min_dim = 800
    shortest_side = min(width, height)
    if shortest_side < min_dim:
        scale = min_dim / shortest_side
        new_width = int(width * scale)
        new_height = int(height * scale)
        img = cv2.resize(img, (new_width, new_height),
                         interpolation=cv2.INTER_CUBIC)
        logger.info(f"Upscaled to {new_width}x{new_height} (scale={scale:.2f})")
        height, width = img.shape[:2]

    # ── 4. Deskew — rotation correction ───────────────────────────────
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    _, temp_binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    coords = np.column_stack(np.where(temp_binary > 0))
    if len(coords) > 0:
        angle = cv2.minAreaRect(coords)[-1]
        if angle < -45:
            angle = 90 + angle
        if abs(angle) > 0.5:
            (h, w) = img.shape[:2]
            center = (w // 2, h // 2)
            M = cv2.getRotationMatrix2D(center, angle, 1.0)
            img = cv2.warpAffine(
                img, M, (w, h),
                flags=cv2.INTER_CUBIC,
                borderMode=cv2.BORDER_REPLICATE,
            )
            logger.info(f"Deskewed by {angle:.2f} degrees")

    # ── 5. Convert to RGB ─────────────────────────────────────────────
    rgb = cv2.cvtColor(img, cv2.COLOR_BGR2RGB)

    # Save preprocessed image if output path provided
    if output_path:
        cv2.imwrite(str(output_path), cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR))
        logger.info(f"Preprocessed image saved to {output_path}")

    return rgb


# ── Bbox Conversion Helper ────────────────────────────────────────────────────

def _polygon_to_rect(bbox: list) -> list[int]:
    """
    Convert EasyOCR polygon bbox [[x1,y1],[x2,y2],[x3,y3],[x4,y4]]
    to rectangle [x, y, w, h].
    """
    xs = [p[0] for p in bbox]
    ys = [p[1] for p in bbox]
    x = int(min(xs))
    y = int(min(ys))
    w = int(max(xs) - x)
    h = int(max(ys) - y)
    return [x, y, w, h]


# ── Line Assembly ─────────────────────────────────────────────────────────────

def _assemble_lines_from_ocr_data(results: list) -> list[dict]:
    """
    Assemble structured lines from EasyOCR results.

    EasyOCR returns: [(bbox, text, confidence), ...]
    where bbox is [[x1,y1],[x2,y2],[x3,y3],[x4,y4]].

    Heuristic:
    1. Sort detections by Y coordinate (top of bbox)
    2. Group detections whose vertical intervals overlap (same "line")
    3. Sort each group by X coordinate (left to right)
    4. Join group text with spaces → 1 line
    5. Calculate consolidated bbox and average confidence

    Args:
        results: EasyOCR readtext() output

    Returns:
        List of {text, bbox: [x,y,w,h], confidence}
    """
    if not results:
        return []

    detections = []
    for bbox, text, confidence in results:
        text = text.strip()
        if not text:
            continue
        rect = _polygon_to_rect(bbox)
        # rect is [x, y, w, h]
        y_center = rect[1] + rect[3] / 2
        y_top = rect[1]
        y_bottom = rect[1] + rect[3]
        detections.append({
            'text': text,
            'bbox': rect,
            'confidence': confidence,
            'y_center': y_center,
            'y_top': y_top,
            'y_bottom': y_bottom,
            'x': rect[0],
        })

    if not detections:
        return []

    # Sort by Y center
    detections.sort(key=lambda d: d['y_center'])

    # Group by vertical overlap
    lines = []
    current_group = [detections[0]]
    avg_line_height = np.mean([d['bbox'][3] for d in detections])
    # Tolerate 40% of average line height for vertical grouping
    y_tolerance = avg_line_height * 0.4

    for d in detections[1:]:
        # Check if this detection overlaps vertically with the current group
        group_y_tops = [g['y_top'] for g in current_group]
        group_y_bottoms = [g['y_bottom'] for g in current_group]
        group_min_top = min(group_y_tops) - y_tolerance
        group_max_bottom = max(group_y_bottoms) + y_tolerance

        if d['y_top'] <= group_max_bottom and d['y_bottom'] >= group_min_top:
            current_group.append(d)
        else:
            # Close current group and start a new one
            lines.append(_flush_line_group(current_group))
            current_group = [d]

    if current_group:
        lines.append(_flush_line_group(current_group))

    return lines


def _flush_line_group(group: list[dict]) -> dict:
    """
    Convert a group of same-line detections into a single line entry.
    Sort words left-to-right and join with spaces.
    """
    group.sort(key=lambda d: d['x'])

    text = ' '.join(d['text'] for d in group)
    confidences = [d['confidence'] for d in group]
    avg_conf = round(sum(confidences) / len(confidences), 1)

    xs = [d['bbox'][0] for d in group]
    ys = [d['bbox'][1] for d in group]
    right_edges = [d['bbox'][0] + d['bbox'][2] for d in group]
    bottom_edges = [d['bbox'][1] + d['bbox'][3] for d in group]

    x = min(xs)
    y = min(ys)
    w = max(right_edges) - x
    h = max(bottom_edges) - y

    return {
        'text': text,
        'bbox': [x, y, w, h],
        'confidence': avg_conf,
    }


# ── Text Extraction ───────────────────────────────────────────────────────────

def extract_text_with_layout(image_path: str | Path) -> dict:
    """
    Extract text from an image using EasyOCR with layout analysis.

    Uses EasyOCR single-pass detection (no multi-PSM fallback needed).
    EasyOCR uses deep learning models that handle varying layouts natively.

    Returns both raw text and structured data with bounding boxes.

    Args:
        image_path: Path to the input image

    Returns:
        dict with keys:
            - raw_text: Full extracted text
            - lines: List of {text, bbox (x, y, w, h), confidence}
            - tables: Detected tabular regions with cells
            - hocr: Empty string (not available from EasyOCR)
            - psm_used: None (not applicable)
    """
    # Preprocess the image
    processed = preprocess_image(image_path)
    height, width = processed.shape[:2]
    logger.info(f"Running EasyOCR on {width}x{height} image")

    # Single-pass OCR with EasyOCR
    reader = get_reader()
    results = reader.readtext(processed, paragraph=False)

    logger.info(f"EasyOCR detected {len(results)} text regions")

    # Assemble raw text and structured lines
    raw_text = '\n'.join(text for _, text, _ in results) if results else ''

    lines = _assemble_lines_from_ocr_data(results)

    logger.info(f'OCR complete: {len(raw_text)} chars, {len(lines)} lines')

    return {
        'raw_text': raw_text,
        'lines': lines,
        'tables': detect_tables(lines),
        'hocr': '',
        'psm_used': None,
    }


def _has_product_unit(text: str) -> bool:
    """Check if text contains a product unit/packaging abbreviation.

    Catches lines like "Banana da Madeira (Kg)" or "Alface Roxa frisada (Caixa)"
    that table-detection heuristics otherwise miss because they have no numbers.
    """
    unit_patterns = [
        r'\b(?:Kg|kg|Kgs|kgs|G|g|L|l|Ml|ml)\b',
        r'\b(?:Un|UN|un|Und|Unds|unds)\b',
        r'\b(?:Cx|cx|Caixa|caixa|Caixas|caixas)\b',
        r'\b(?:Pct|pct|Pacote|pacote|Pacotes|pacotes)\b',
        r'\b(?:Saco|saco|Sacos|sacos)\b',
        r'\b(?:M|m|Cm|cm|Mm|mm)\b',
        r'\b(?:Pç|pç|Peça|peça|Peças|peças)\b',
        r'\b(?:Molho|molho|Molhos|molhos)\b',
        r'\b(?:Dose|dose|Doses|doses)\b',
        r'\b(?:Lt|lt|Litro|litro|Litros|litros)\b',
    ]
    combined = '|'.join(unit_patterns)
    return bool(re.search(combined, text))


def detect_tables(lines: list[dict]) -> list[dict]:
    """
    Detect tabular regions in the OCR lines.

    A table is identified as a sequence of lines where each line
    contains a mix of text and numbers (typical of product tables).

    Returns:
        List of table objects, each containing rows of cells
    """
    tables = []
    potential_table_lines = []

    for line_data in lines:
        text = line_data['text']
        # Count words and numbers in the line
        words = text.split()
        numbers = [w for w in words if re.match(r'^[\d.,]+$', w.replace('.', '').replace(',', ''))]
        has_mixed_content = len(words) >= 3 and len(numbers) >= 2

        has_unit = len(words) >= 2 and len(words) <= 15 and _has_product_unit(text)

        if has_mixed_content or (len(words) >= 4 and len(numbers) >= 1) or has_unit:
            potential_table_lines.append(line_data)
        else:
            # If we had accumulated table lines, close the table
            if len(potential_table_lines) >= 3:
                tables.append({
                    'rows': [
                        {
                            'text': l['text'],
                            'bbox': l['bbox'],
                            'confidence': l['confidence'],
                        }
                        for l in potential_table_lines
                    ]
                })
            potential_table_lines = []

    # Don't forget the last potential table
    if len(potential_table_lines) >= 3:
        tables.append({
            'rows': [
                {
                    'text': l['text'],
                    'bbox': l['bbox'],
                    'confidence': l['confidence'],
                }
                for l in potential_table_lines
            ]
        })

    return tables


def extract_text_simple(image_path: str | Path) -> str:
    """
    Simple text extraction without layout (fallback).

    Uses EasyOCR with paragraph mode for general documents.
    """
    processed = preprocess_image(image_path)
    reader = get_reader()
    results = reader.readtext(processed, paragraph=False)
    text = '\n'.join(t for _, t, _ in results) if results else ''
    return text.strip()
