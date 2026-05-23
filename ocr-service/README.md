# OCR Service — Microserviço de Reconhecimento de Documentos

Este microserviço Python combina **EasyOCR (deep learning) com pré-processamento de imagem** e **llama-cpp LLM local (OpenAI-compatible API)** para extrair dados estruturados de documentos de compra (encomendas) em português, sem depender de APIs externas pagas.

---

## Quick Start — Após Reiniciar o Computador

Sempre que ligar o computador, siga estes passos **por ordem**:

### 1. Iniciar o llama-cpp server (servidor de IA local)

Inicie o servidor llama-cpp com o seu modelo GGUF:

```bash
python -m llama_cpp.server --model "c:\caminho\para\qwen2.5-7b-instruct-q4_k_m-00001-of-00002.gguf" --host 127.0.0.1 --port 8080
```

> Nota: llama-cpp detecta automaticamente as partes de um GGUF dividido e carrega todas.

### 2. Verificar se o servidor está acessível

```bash
curl http://127.0.0.1:8080/v1/models
```

Deverá ver o modelo disponível na resposta JSON.

### 3. Iniciar o serviço OCR

```bash
# Método 1 — Script automático (recomendado)
ocr-service\start_service.bat

# Método 2 — Manual
cd c:\git\Pessoal\erp4ucp && python ocr-service/app.py
```

### 4. Confirmar que está tudo ativo

```bash
curl http://127.0.0.1:5050/health
```

Resposta esperada:
```json
{
  "status": "healthy",
  "ocr_engine_available": true,
  "llm_available": true,
  "llm_models": ["qwen2.5-7b-instruct-q4_k_m"]
}
```

> ✅ O serviço está pronto a usar quando o `health` devolver `"status": "healthy"`.

---

## Índice

1. [Pré-requisitos](#pré-requisitos)
2. [Instalação](#instalação)
3. [Arquitetura](#arquitetura)
4. [Configuração](#configuração)
    - [Variáveis de ambiente (Python)](#variáveis-de-ambiente-python)
    - [Variáveis de ambiente (Laravel `.env`)](#variáveis-de-ambiente-laravel-env)
5. [Como iniciar o serviço](#como-iniciar-o-serviço)
6. [Endpoints da API](#endpoints-da-api)
7. [Modelo LLM recomendado](#modelo-llm-recomendado)
8. [Troubleshooting](#troubleshooting)
9. [Estrutura de ficheiros](#estrutura-de-ficheiros)

---

## Pré-requisitos

| Componente | Versão Mínima | Notas |
|-----------|--------------|-------|
| **Python** | 3.10+ | Testado com 3.14.4 |
| **EasyOCR** | 1.7+ | Engine OCR (deep learning) |
| **llama-cpp** | — | Servidor de LLM local (OpenAI-compatible API) |
| **Pip** | — | Gestor de pacotes Python |
| **Laravel** | 11.x | Aplicação que consome o serviço |

### Dependências Python

- `fastapi` + `uvicorn` — Servidor HTTP assíncrono
- `opencv-python-headless` — Pré-processamento de imagem
- `pillow` — Manipulação de imagem
- `easyocr` — Engine OCR baseada em deep learning (PyTorch)
- `requests` — Chamadas HTTP ao llama-cpp (OpenAI-compatible API)
- `numpy` — Suporte a arrays numéricos

---

## Instalação

### 1. Dependências Python

```bash
cd c:\git\Pessoal\erp4ucp
pip install -r ocr-service/requirements.txt
```

> ⚠️ `easyocr` depende de `torch` (PyTorch). A instalação pode ser grande (~2 GB com CUDA, ~800 MB CPU-only). Se tiver GPU NVIDIA, instale o PyTorch com CUDA primeiro para melhor performance:
> ```bash
> pip install torch torchvision --index-url https://download.pytorch.org/whl/cu118
> pip install -r ocr-service/requirements.txt
> ```

### 2. llama-cpp (servidor LLM)

Instale o llama-cpp-python com suporte a servidor:

```bash
pip install llama-cpp-python[server]
```

Descarregue o modelo GGUF recomendado e inicie o servidor:

```bash
python -m llama_cpp.server --model "caminho/para/qwen2.5-7b-instruct-q4_k_m-00001-of-00002.gguf" --host 127.0.0.1 --port 8080
```

Pode verificar se o servidor está acessível:

```bash
curl http://127.0.0.1:8080/v1/models
```

---

## Arquitetura

```
┌─────────────────────────────────────────────────────────────┐
│                    Laravel (PHP 8.x)                         │
│                                                             │
│  PurchaseOrderController.php                                │
│       │                                                     │
│       ▼                                                     │
│  OcrService.php ──────HTTP POST (multipart)──────┐          │
└───────────────────────────────────────────────────┤          │
                                                     ▼          │
┌──────────────────────────────────────────────────────────────┐
│              FastAPI Microservice (Python)                    │
│                                                              │
│  POST /analyze                                               │
│       │                                                      │
│       ├─► 1. Guarda imagem temporariamente                   │
│       ├─► 2. ocr_processor.py                                │
│       │       ├─ Perspective correction                      │
│       │       ├─ Upscale (min 800px shortest side)           │
│       │       ├─ Deskew (correção de rotação)                │
│       │       └─ EasyOCR (deep learning, pt+en)              │
│       ├─► 3. document_analyzer.py                            │
│       │       ├─ LLM (llama-cpp) → análise semântica          │
│       │       ├─ Fallback regex → extração manual             │
│       │       └─ JSON estruturado                             │
│       └─► 4. Resposta JSON                                    │
│                                                               │
│              llama-cpp server (OpenAI-compatible)              │
│                  Servidor local: 127.0.0.1:8080               │
└──────────────────────────────────────────────────────────────┘
```

### Fluxo de processamento

1. O utilizador faz upload de uma imagem/PDF no Laravel
2. `PurchaseOrderController` envia a imagem para o microserviço via `OcrService`
3. O microserviço pré-processa a imagem (perspective correction, upscale, deskew)
4. Executa EasyOCR com deteção de layout (deep learning)
5. Envia o texto extraído para o LLM com **few-shot prompting**
6. O LLM analisa o texto e devolve JSON estruturado
7. Se o LLM falhar, cai no **fallback regex**
8. O Laravel converte o formato LLM para o formato interno e persiste os dados

---

## Configuração

### Variáveis de ambiente (Python)

O serviço lê as seguintes variáveis de ambiente (com defaults):

| Variável | Default | Descrição |
|---------|---------|-----------|
| `OCR_SERVICE_HOST` | `127.0.0.1` | IP de bind do servidor FastAPI |
| `OCR_SERVICE_PORT` | `5050` | Porta do servidor FastAPI |
| `LLM_BASE_URL` | `http://127.0.0.1:8080/v1` | URL base do servidor llama-cpp (OpenAI-compatible) |
| `LLM_MODEL` | `qwen2.5-7b-instruct-q4_k_m` | Modelo LLM a usar para análise |

Estas variáveis podem ser definidas no sistema ou passadas no `start_service.bat`.

### Variáveis de ambiente (Laravel `.env`)

Adicionar ao ficheiro `.env` do projeto Laravel:

```env
# URL base do microserviço OCR
OCR_SERVICE_URL=http://127.0.0.1:5050

# Modelo LLM para análise de documentos (deve estar carregado no llama-cpp)
OCR_LLM_MODEL=qwen2.5-7b-instruct-q4_k_m

# Timeout (segundos) para chamadas ao microserviço
OCR_SERVICE_TIMEOUT=120
```

**Nota:** O timeout deve ser generoso (120s+) porque a chamada ao LLM pode demorar 30-50 segundos.

---

## Como iniciar o serviço

> ⚠️ **IMPORTANTE:** Antes de iniciar este serviço, certifique-se de que o **llama-cpp server está a correr** (na porta 8080).
> Consulte a secção [Quick Start — Após Reiniciar o Computador](#quick-start--após-reiniciar-o-computador) no topo deste documento.

### Método 1: Script de início rápido (Windows) — ✅ RECOMENDADO

```bash
ocr-service\start_service.bat
```

Este script:
1. Verifica se Python está disponível
2. Verifica se o llama-cpp server está acessível (http://127.0.0.1:8080)
3. Inicia o servidor FastAPI na porta `5050`

### Método 2: Manual

```bash
# CMD (uma linha)
cd c:\git\Pessoal\erp4ucp && python ocr-service/app.py

# Ou com variáveis customizadas
set OCR_SERVICE_PORT=5050
set LLM_MODEL=qwen2.5-7b-instruct-q4_k_m
python ocr-service/app.py
```

### Método 3: Para produção (uvicorn com reload)

```bash
cd c:\git\Pessoal\erp4ucp
uvicorn ocr-service.app:app --host 127.0.0.1 --port 5050 --reload
```

### Verificar que o serviço está ativo

```bash
curl http://127.0.0.1:5050/health
```

Resposta esperada:
```json
{
  "status": "healthy",
  "ocr_engine_available": true,
  "llm_available": true,
  "llm_models": ["qwen2.5-7b-instruct-q4_k_m"]
}
```

---

## Endpoints da API

### `GET /health`

Verifica o estado do serviço, disponibilidade do EasyOCR e LLM.

**Resposta (200):**
```json
{
  "status": "healthy",
  "ocr_engine_available": true,
  "llm_available": true,
  "llm_models": ["qwen2.5-7b-instruct-q4_k_m"]
}
```

---

### `POST /analyze`

Endpoint principal. Aceita um documento (imagem ou PDF) e devolve dados estruturados.

**Request (multipart/form-data):**

| Parâmetro | Tipo | Obrigatório | Descrição |
|-----------|------|-------------|-----------|
| `file` | UploadFile | Sim | Documento a analisar (PNG, JPG, PDF) |
| `model` | str | Não | Modelo LLM a usar (default: definido em `LLM_MODEL`) |
| `use_llm` | bool | Não | Se `false`, salta a análise LLM e usa apenas OCR + fallback regex (default: `true`) |

**Exemplo com `curl`:**
```bash
curl -X POST http://127.0.0.1:5050/analyze `
  -F "file=@encomenda_1.png" `
  -F "model=qwen2.5-7b-instruct-q4_k_m" `
  -F "use_llm=true"
```

**Resposta (200):**
```json
{
  "success": true,
  "data": {
    "document_number": "ENCOMENDA Nº 2025/0001",
    "supplier": {
      "name": "Distribuidora Lusitânia, Lda.",
      "nif": "500123456",
      "address": "Rua do Comércio, 45, 4000-100 Porto"
    },
    "date": "20/05/2025",
    "notes": "",
    "lines": [
      {
        "productCode": "1001",
        "productDescription": "Arroz Carolino 1kg",
        "quantity": 10,
        "unitPrice": 1.35,
        "taxRatePercent": 6.0,
        "total": null
      }
    ],
    "total_amount": 0.0
  },
  "processing_time": 35.4,
  "llm_used": true
}
```

**Resposta (422 — erro de validação):**
```json
{
  "detail": "No file uploaded"
}
```

---

### `POST /ocr-only`

Apenas extração de texto bruto, sem análise LLM. Útil para debug.

**Request (multipart/form-data):**

| Parâmetro | Tipo | Obrigatório | Descrição |
|-----------|------|-------------|-----------|
| `file` | UploadFile | Sim | Documento a processar |

**Resposta (200):**
```json
{
  "success": true,
  "text": "ENCOMENDA Nº 2025/0001\nFORNECEDOR: Distribuidora Lusitânia, Lda.\n...",
  "pages": 1,
  "processing_time": 1.2
}
```

---

## Modelo LLM recomendado

### Modelo atual: `qwen2.5:7b` ⭐

| Característica | Valor |
|---------------|-------|
| Parâmetros | 7B |
| RAM necessária | ~4-6 GB |
| Velocidade | ~15-30 tokens/s (CPU) |
| Precisão na extração | Muito Alta — melhor compreensão de contexto e OCR ruidoso |
| Suporte a JSON | Excelente — raramente precisa de fallback |
| 🇵🇹 Português | Excelente — lidar bem com acentos, nomes e estruturas PT-PT |

### Alternativas disponíveis

| Modelo | Vantagens | Desvantagens |
|--------|-----------|--------------|
| `qwen2.5-7b-instruct-q4_k_m` | ✅ **Recomendado** — melhor equilíbrio qualidade/recursos | ~4-6 GB RAM |
| `qwen2.5-coder-3b-q4_k_m` | ✅ Mais rápido, ~2-3 GB RAM | Menos preciso em OCR ruidoso; caracteres acentuados podem falhar |
| `llama-3.1-8b-q4_k_m` | ✅ Segue instruções de formato rigorosamente | ~4-6 GB RAM, PT ligeiramente inferior |

### Recomendação

**`qwen2.5-7b-instruct-q4_k_m`** — modelo padrão configurado no `document_analyzer.py`. Adequado para a maioria dos sistemas com 8-16 GB RAM.

Se o desempenho for lento ou tiver recursos limitados (≤8 GB RAM total), pode usar um modelo mais pequeno:

```bash
# Para usar modelo alternativo (temporário):
set LLM_MODEL=qwen2.5-coder-1.5b-q4_k_m
python ocr-service/app.py

# Ou na chamada à API:
curl -X POST http://127.0.0.1:5050/analyze -F "file=@doc.jpg" -F "model=qwen2.5-coder-1.5b-q4_k_m"
```

### Como funciona o prompt

O sistema usa **few-shot prompting** com 2 exemplos completos de documentos portugueses. O prompt instrui o modelo a:

1. Ignorar cabeçalhos e rodapés irrelevantes
2. Extrair FORNECEDOR, NIF, DATA DA ENCOMENDA, Nº ENCOMENDA
3. Para cada linha de produto: código, descrição, quantidade, preço unitário, taxa de IVA
4. Devolver SEMPRE JSON válido

Se o LLM falhar (não devolver JSON válido), o sistema cai automaticamente no **fallback regex**, que procura padrões como:

- `FORNECEDOR:\s*(.+)` — nome do fornecedor
- `NIF:\s*(\d{3}\s*\d{3}\s*\d{3})` — NIF com ou sem espaços
- `DATA DA ENCOMENDA:\s*(\d{2}/\d{2}/\d{4})` — data
- `ENCOMENDA Nº\s*([\d/]+)` — número do documento
- `^\s*(\d{3,})\s+(.+?)\s+(\d+)\s+([\d.,]+)\s*$` — linhas de produto (código + descrição + qtd + preço)

---

## Troubleshooting

### "EasyOCR is not available"

O EasyOCR reader pode não ter carregado corretamente. Verifique se o PyTorch está instalado:

```bash
python -c "import torch; print(torch.__version__)"
```

Se não estiver instalado:
```bash
pip install torch torchvision
```

Se tiver GPU NVIDIA, instale a versão CUDA:
```bash
pip install torch torchvision --index-url https://download.pytorch.org/whl/cu118
```

### "LLM is not available"

O servidor llama-cpp não está a correr.

**Solução:**
```bash
# Verificar se o llama-cpp server está a correr
curl http://127.0.0.1:8080/v1/models

# Iniciar o servidor
python -m llama_cpp.server --model "caminho/para/modelo.gguf" --host 127.0.0.1 --port 8080
```

### "Connection refused" no Laravel

O microserviço não está ativo quando o Laravel tenta contactá-lo.

**Solução:** Garantir que o serviço está a correr antes de usar o OCR:

```bash
# Verificar health
curl http://127.0.0.1:5050/health
```

### Resposta LLM inconsistente

O modelo pode ocasionalmente alucinar valores (especialmente NIFs).

**Solução:** O fallback regex (`_fallback_parse()` em `document_analyzer.py`) entra em ação quando o JSON do LLM é inválido. Se o problema persistir, experimentar:

1. Aumentar o `temperature` no prompt (menos criatividade)
2. Usar um modelo maior (ex: GGUF com quantização Q5 ou Q8)
3. Adicionar mais few-shot examples no `SYSTEM_PROMPT`

### A extração está muito lenta

Tempos esperados:

| Operação | Tempo típico |
|----------|-------------|
| Pré-processamento + OCR (EasyOCR, CPU) | 3-10 segundos |
| Pré-processamento + OCR (EasyOCR, GPU) | 1-3 segundos |
| Análise LLM (llama-cpp) | 25-50 segundos |
| Total (CPU) | 30-55 segundos |

Se for demasiado lento:

1. **Desativar o LLM** (`use_llm=false`) — usa apenas regex fallback (mais rápido, menos preciso)
2. **Reduzir o upscale** — em `ocr_processor.py`, alterar `min_dim = 600` em vez de `800`
3. **Usar GPU** — EasyOCR com CUDA é 3-5x mais rápido que CPU
4. **Usar um modelo mais pequeno** — GGUF quantizados Q2/Q3 (mais rápido, menos preciso)

### Erro "No file uploaded" (422)

O parâmetro `file` não foi enviado ou está vazio.

**Solução:** Verificar se o nome do campo no formulário Laravel corresponde a `file`:

```php
// Em OcrService.php
'multipart' => [
    ['name' => 'file', 'contents' => fopen($file->getRealPath(), 'r'), 'filename' => $file->getClientOriginalName()],
    ['name' => 'use_llm', 'contents' => 'true'],
]
```

---

## Estrutura de ficheiros

```
ocr-service/
├── app.py                 # Servidor FastAPI (endpoints /health, /analyze, /ocr-only)
├── ocr_processor.py       # Pré-processamento de imagem + EasyOCR
├── document_analyzer.py   # Integração llama-cpp LLM + fallback regex
├── requirements.txt       # Dependências Python
├── start_service.bat      # Script de início rápido (Windows)
└── README.md              # Este documento
```

### Ficheiros Laravel relacionados

```
app/Services/OcrService.php          # Cliente HTTP para o microserviço
config/services.php                  # Configuração 'ocr' (URL, modelo, timeout)
app/Http/Controllers/Actl/PurchaseOrderController.php  # Controller com fallback OcrService
```

---

## Testar o serviço

### Teste rápido com Python

```python
import requests

# Health check
print(requests.get("http://127.0.0.1:5050/health").json())

# Analisar documento
with open("encomenda_1.png", "rb") as f:
    resp = requests.post(
        "http://127.0.0.1:5050/analyze",
        files={"file": f},
        data={"model": "qwen2.5-7b-instruct-q4_k_m", "use_llm": "true"}
    )
print(resp.json())
```

### Teste a partir do Laravel

```bash
php artisan tinker
```

```php
$service = app(\App\Services\OcrService::class);
$file = new \Illuminate\Http\UploadedFile(
    base_path('encomenda_1.png'),
    'encomenda_1.png',
    'image/png',
    null,
    true
);
$result = $service->analyzeDocument($file, true);
dump($result);
```

---

## Segurança

- O serviço corre apenas em `127.0.0.1` (localhost) — não exposto à rede
- Não requer autenticação porque só aceita tráfego local
- Os ficheiros temporários são limpos após processamento
- O llama-cpp corre localmente — nenhum dado sai da máquina

---

## Licença

Parte integrante do projecto ERP4UCP. Uso interno.
