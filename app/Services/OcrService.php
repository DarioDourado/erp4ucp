<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OCR Service — Interface with the Python OCR microservice.
 *
 * The OCR microservice uses EasyOCR with image preprocessing + llama-cpp (local LLM)
 * for document understanding, providing much better accuracy than OCR alone.
 *
 * @see ocr-service/app.py
 */
class OcrService
{
    /**
     * Base URL of the OCR microservice.
     */
    protected string $baseUrl;

    /**
     * LLM model to use for document understanding.
     */
    protected string $llmModel;

    /**
     * Request timeout in seconds.
     */
    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.ocr.base_url', 'http://127.0.0.1:5050');
        $this->llmModel = config('services.ocr.llm_model', 'qwen2.5-7b-instruct-q4_k_m');
        $this->timeout = config('services.ocr.timeout', 120);
    }

    /**
     * Analyze a document image and extract structured purchase order data.
     *
     * @param  UploadedFile  $file  The uploaded document image
     * @param  bool  $useLlm  Whether to use LLM for document understanding
     * @return array{success: bool, data?: array, error?: string}
     */
    public function analyzeDocument(UploadedFile $file, bool $useLlm = true): array
    {
        $startTime = microtime(true);
        set_time_limit(max(300, $this->timeout + 60));

        try {
            $response = Http::timeout($this->timeout)
                ->attach(
                    'file',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                )
                ->post("{$this->baseUrl}/analyze", [
                    'model' => $this->llmModel,
                    'use_llm' => $useLlm ? 'true' : 'false',
                ]);

            $duration = round((microtime(true) - $startTime) * 1000, 1);

            if (!$response->successful()) {
                Log::error('[OCR Service] HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'duration_ms' => $duration,
                ]);

                return [
                    'success' => false,
                    'error' => "OCR service returned HTTP {$response->status()}",
                ];
            }

            $result = $response->json();

            if (!($result['success'] ?? false)) {
                Log::warning('[OCR Service] Analysis failed', [
                    'error' => $result['error'] ?? 'Unknown error',
                    'duration_ms' => $duration,
                ]);

                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Erro desconhecido no OCR',
                ];
            }

            // Extract the structured data
            $data = $result['data'] ?? [];
            $parsed = $data['parsed'] ?? [];
            $rawText = $data['ocr_text'] ?? $result['raw_text'] ?? '';

            Log::info('[OCR Service] Document analyzed successfully', [
                'supplier' => $parsed['supplier']['name'] ?? 'unknown',
                'lines' => count($parsed['lines'] ?? []),
                'duration_ms' => $duration,
                'ocr_chars' => strlen($rawText),
            ]);

            return [
                'success' => true,
                'data' => [
                    'parsed' => $parsed,
                    'raw_text' => $rawText,
                    'processing_time_ms' => $result['processing_time_ms'] ?? $duration,
                ],
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('[OCR Service] Connection refused — is the service running?', [
                'base_url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'O serviço OCR não está disponível. Execute "python ocr-service/app.py" para iniciá-lo.',
            ];
        } catch (\Exception $e) {
            Log::error('[OCR Service] Unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => 'Erro ao comunicar com o serviço OCR: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Check if the OCR service is running and healthy.
     */
    public function isHealthy(): array
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/health");

            if ($response->successful()) {
                return [
                    'healthy' => true,
                    'info' => $response->json(),
                ];
            }

            return [
                'healthy' => false,
                'error' => "HTTP {$response->status()}",
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract raw text from a document without LLM analysis.
     */
    public function extractTextOnly(UploadedFile $file): array
    {
        set_time_limit(120);
        try {
            $response = Http::timeout(60)
                ->attach(
                    'file',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                )
                ->post("{$this->baseUrl}/ocr-only");

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => "HTTP {$response->status()}",
                ];
            }

            return $response->json();
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
