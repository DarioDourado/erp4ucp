"""
OCR Processor Module
====================
Handles image preprocessing and text extraction using pytesseract.
Provides layout-aware OCR with bounding box information.

Pipeline improvements:
- Perspective correction for angled/tilted document photos
- CLAHE adaptive histogram equalization for better local contrast
- Sharpening after upscaling for crisper text edges
- Multi-method thresholding (adaptive + Otsu with fallback)
"""
import cv2
import numpy as np
from PIL import Image
import pytesseract
from pathlib import Path
import logging

logger = logging.getLogger(__name__)


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

    **Safeguard**: if the detected quadrilateral covers less than 25% of the
    total image area, it likely represents an inner content region rather than
    the full document page. In this case perspective correction is **skipped**
    and the original image is returned — a bad perspective crop is worse than
    no correction at all. All other enhancements (CLAHE, sharpening, etc.) are
    still applied later in the pipeline.

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

    MIN_AREA_RATIO = 0.50  # at least 50% of original image area — stricter threshold

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
        f"(ratio={area_ratio:.1%})"
    )
    return warped


# ── Image Enhancement Helpers ─────────────────────────────────────────────────

def apply_clahe(gray: np.ndarray, clip_limit: float = 2.0,
                grid_size: tuple[int, int] = (8, 8)) -> np.ndarray:
    """
    Apply Contrast Limited Adaptive Histogram Equalization (CLAHE) for
    improved local contrast. Essential for documents with uneven lighting
    or shadows (common in photos taken at an angle).

    Args:
        gray: Grayscale image
        clip_limit: Contrast clipping limit (higher = more contrast)
        grid_size: Tile grid size for local equalization

    Returns:
        Contrast-enhanced grayscale image
    """
    clahe = cv2.createCLAHE(clipLimit=clip_limit, tileGridSize=grid_size)
    return clahe.apply(gray)


def sharpen_image(img: np.ndarray) -> np.ndarray:
    """
    Apply unsharp masking to sharpen the image.
    Helps text edges become crisper after upscaling.

    Args:
        img: Input image (grayscale or BGR)

    Returns:
        Sharpened image
    """
    # Gaussian blur
    blurred = cv2.GaussianBlur(img, (0, 0), 1.5)
    # Unsharp mask: original + (original - blurred) * amount
    sharpened = cv2.addWeighted(img, 1.5, blurred, -0.5, 0)
    return sharpened


def auto_threshold(gray: np.ndarray) -> np.ndarray:
    """
    Apply the best thresholding method for the given image.

    Tries Otsu's binarization first (works well for high-contrast documents).
    Falls back to adaptive thresholding if Otsu produces poor results.

    Args:
        gray: Grayscale image

    Returns:
        Binary image
    """
    # Try Otsu's binarization
    _, otsu = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

    # Calculate the ratio of white pixels — if reasonable, use Otsu
    white_ratio = np.sum(otsu == 255) / otsu.size
    if 0.1 < white_ratio < 0.9:
        return otsu

    # Fallback to adaptive thresholding
    binary = cv2.adaptiveThreshold(
        gray, 255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY,
        blockSize=31,
        C=10,
    )
    return binary


# ── Main Preprocessing Pipeline ───────────────────────────────────────────────

def preprocess_image(image_path: str | Path,
                     output_path: str | Path | None = None) -> np.ndarray:
    """
    Preprocess an image to maximise OCR quality.

    **IMPORTANT**: Returns a *grayscale* image (not binary). Tesseract
    performs its own internal binarization which is superior to manual
    thresholding, especially for small/table text where aggressive
    preprocessing destroys character structure.

    Pipeline:
    1. Load image
    2. Perspective correction — flatten angled document photos
    3. Upscale to ≥2000px longest side
    4. Sharpen — crisp text after upscaling
    5. Grayscale conversion
    6. Deskew — rotation correction

    Removed (they destroyed small table text):
    - Bilateral filter: too aggressive, merged small characters
    - CLAHE: over-amplified noise in small/thin table text
    - Auto-threshold + binarization: Tesseract's internal is better
    - Morphological close: not needed on grayscale

    Args:
        image_path: Path to the input image (JPEG, PNG, TIFF)
        output_path: Optional path to save the preprocessed image for debugging

    Returns:
        Preprocessed grayscale image as numpy array
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
    # Target: at least 2000px on the longest side for good OCR
    min_dim = 2000
    if max(width, height) < min_dim:
        scale = min_dim / max(width, height)
        new_width = int(width * scale)
        new_height = int(height * scale)
        img = cv2.resize(img, (new_width, new_height),
                         interpolation=cv2.INTER_CUBIC)
        logger.info(f"Upscaled to {new_width}x{new_height} (scale={scale:.2f})")
        height, width = img.shape[:2]

    # ── 4. Sharpen ────────────────────────────────────────────────────
    img = sharpen_image(img)

    # ── 5. Convert to grayscale ───────────────────────────────────────
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

    # ── 6. Deskew — rotation correction ───────────────────────────────
    # Use Otsu threshold ONLY for finding text coordinates (not for final output)
    _, temp_binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    coords = np.column_stack(np.where(temp_binary > 0))
    if len(coords) > 0:
        angle = cv2.minAreaRect(coords)[-1]
        if angle < -45:
            angle = 90 + angle
        if abs(angle) > 0.5:
            (h, w) = gray.shape[:2]
            center = (w // 2, h // 2)
            M = cv2.getRotationMatrix2D(center, angle, 1.0)
            gray = cv2.warpAffine(
                gray, M, (w, h),
                flags=cv2.INTER_CUBIC,
                borderMode=cv2.BORDER_REPLICATE,
            )
            logger.info(f"Deskewed by {angle:.2f} degrees")

    # Save preprocessed image if output path provided
    if output_path:
        cv2.imwrite(str(output_path), gray)
        logger.info(f"Preprocessed image saved to {output_path}")

    return gray


def extract_text_with_layout(image_path: str | Path) -> dict:
    """
    Extract text from an image using Tesseract OCR with layout analysis.

    Returns both raw text and structured data with bounding boxes.

    Args:
        image_path: Path to the input image

    Returns:
        dict with keys:
            - raw_text: Full extracted text
            - lines: List of {text, bbox (x, y, w, h), conf}
            - tables: Detected tabular regions with cells
    """
    # Preprocess the image
    processed = preprocess_image(image_path)

    # --- Configuration for better Portuguese document OCR ---
    custom_config = r'--oem 3 --psm 4 -l por+eng'

    # Get detailed OCR data with bounding boxes
    ocr_data = pytesseract.image_to_data(
        processed,
        config=custom_config,
        output_type=pytesseract.Output.DICT
    )

    # Get raw text
    raw_text = pytesseract.image_to_string(
        processed,
        config=custom_config
    )

    # Get hOCR format for XML-like structure with layout info
    hocr_output = pytesseract.image_to_pdf_or_hocr(
        processed,
        extension='hocr',
        config=custom_config
    )

    # Assemble structured lines from OCR data
    lines = []
    current_line = ""
    current_bbox = None
    current_conf_sum = 0
    current_conf_count = 0

    # Track previous WORD's metadata (NOT using i-1 which can point to
    # non-word-level entries like line/block/paragraph, causing silent
    # line break detection failures).
    prev_word_line_num = -1
    prev_word_block_num = -1
    prev_word_par_num = -1

    n_boxes = len(ocr_data['level'])
    for i in range(n_boxes):
        if ocr_data['level'][i] == 5:  # Word level
            text = ocr_data['text'][i].strip()
            conf = int(ocr_data['conf'][i])

            if text:
                cur_line_num = ocr_data['line_num'][i]
                cur_block_num = ocr_data['block_num'][i]
                cur_par_num = ocr_data['par_num'][i]

                x, y, w, h = (
                    ocr_data['left'][i],
                    ocr_data['top'][i],
                    ocr_data['width'][i],
                    ocr_data['height'][i],
                )

                # Detect line break by comparing with PREVIOUS WORD metadata
                # (not i-1, which may be a non-word-level entry)
                is_new_line = (
                    prev_word_line_num != -1 and (
                        cur_line_num != prev_word_line_num or
                        cur_block_num != prev_word_block_num or
                        cur_par_num != prev_word_par_num
                    )
                )

                if current_line and is_new_line:
                    avg_conf = current_conf_sum / max(current_conf_count, 1)
                    lines.append({
                        'text': current_line.strip(),
                        'bbox': current_bbox,
                        'confidence': round(avg_conf, 1),
                    })
                    current_line = ""
                    current_bbox = None
                    current_conf_sum = 0
                    current_conf_count = 0

                # Update previous word tracking
                prev_word_line_num = cur_line_num
                prev_word_block_num = cur_block_num
                prev_word_par_num = cur_par_num

                # Append word to current line
                separator = " " if current_line else ""
                current_line += separator + text

                # Update bounding box
                if current_bbox is None:
                    current_bbox = [x, y, w, h]
                else:
                    current_bbox[0] = min(current_bbox[0], x)
                    current_bbox[1] = min(current_bbox[1], y)
                    current_bbox[2] = max(current_bbox[0] + current_bbox[2], x + w) - current_bbox[0]
                    current_bbox[3] = max(current_bbox[1] + current_bbox[3], y + h) - current_bbox[1]

                current_conf_sum += conf
                current_conf_count += 1

    # Don't forget the last line
    if current_line:
        avg_conf = current_conf_sum / max(current_conf_count, 1)
        lines.append({
            'text': current_line.strip(),
            'bbox': current_bbox,
            'confidence': round(avg_conf, 1),
        })

    # --- Attempt table detection ---
    # Simple heuristic: find lines with multiple numbers (potential table rows)
    tables = detect_tables(lines)

    return {
        'raw_text': raw_text.strip(),
        'lines': lines,
        'tables': tables,
        'hocr': hocr_output.decode('utf-8') if isinstance(hocr_output, bytes) else hocr_output,
    }


def detect_tables(lines: list[dict]) -> list[dict]:
    """
    Detect tabular regions in the OCR lines.

    A table is identified as a sequence of lines where each line
    contains a mix of text and numbers (typical of product tables).

    Returns:
        List of table objects, each containing rows of cells
    """
    import re

    tables = []
    potential_table_lines = []

    for line_data in lines:
        text = line_data['text']
        # Count words and numbers in the line
        words = text.split()
        numbers = [w for w in words if re.match(r'^[\d.,]+$', w.replace('.', '').replace(',', ''))]
        has_mixed_content = len(words) >= 3 and len(numbers) >= 2

        if has_mixed_content or (len(words) >= 4 and len(numbers) >= 1):
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

    Uses PSM 3 (automatic page segmentation) for general documents.
    """
    processed = preprocess_image(image_path)
    custom_config = r'--oem 3 --psm 3 -l por+eng'
    text = pytesseract.image_to_string(processed, config=custom_config)
    return text.strip()
