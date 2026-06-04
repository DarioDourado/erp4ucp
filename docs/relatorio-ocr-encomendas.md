# Relatório Técnico — Solução de Importação de Encomendas por OCR

> **Projeto:** ERP4U  
> **Data:** Junho 2026  
> **Versão:** 1.0

---

## Índice

1. [Visão Geral](#1-visão-geral)
2. [Arquitetura e Stack Tecnológica](#2-arquitetura-e-stack-tecnológica)
3. [Ficheiros-Chave do Sistema](#3-ficheiros-chave-do-sistema)
4. [Fluxo Passo-a-Passo](#4-fluxo-passo-a-passo)
5. [Modelos de Dados e Tabelas](#5-modelos-de-dados-e-tabelas)
6. [Configuração](#6-configuração)
7. [Motores de OCR e Processamento](#7-motores-de-ocr-e-processamento)
8. [Resolução de Entidades](#8-resolução-de-entidades)
9. [Mapeamento OCR → Encomenda](#9-mapeamento-ocr--encomenda)
10. [Gestão de Estado (Sessão)](#10-gestão-de-estado-sessão)
11. [Tratamento de Erros e Fallbacks](#11-tratamento-de-erros-e-fallbacks)
12. [Requisitos de Infraestrutura](#12-requisitos-de-infraestrutura)

---

## 1. Visão Geral

A solução de importação de encomendas por OCR permite digitalizar documentos de fornecedores (faturas, notas de encomenda, orçamentos) através de fotografia, upload de imagem ou PDF, extraindo automaticamente:

- **Dados do fornecedor** (nome, NIF, morada)
- **Data e número do documento**
- **Linhas de produtos** (código, descrição, quantidade, preço unitário, unidade, taxa de IVA)
- **Totais do documento** (valor líquido, valor bruto)

Os dados extraídos são apresentados ao utilizador para revisão e edição, com resolução automática de entidades (fornecedores e produtos) na base de dados. Após validação, a encomenda é criada no ERP com um clique.

### Principais funcionalidades:

- Captura por câmara (dispositivos móveis) ou upload de ficheiro (PNG, JPG, PDF até 10 MB)
- Pipeline dual de OCR (Python EasyOCR + fallback Tesseract PHP)
- Compreensão semântica via LLM local (Qwen2.5 7B)
- Resolução automática de fornecedores e produtos (fuzzy matching + auto-criação)
- Validação de subtotais (comparação documento vs. soma das linhas)
- Interface de revisão/edição interativa
- Pré-preenchimento do formulário de criação de encomenda

---

## 2. Arquitetura e Stack Tecnológica

### Diagrama de Arquitetura

```
┌─────────────────────────────────────────────────────────────────┐
│                        NAVEGADOR (Frontend)                      │
│  purchaseOrder_ocr.blade.php  ──  purchaseOrderC_add.blade.php  │
│  (Captura, upload, revisão,    (Formulário de encomenda          │
│   edição de dados OCR)          pré-preenchido)                  │
└──────────────────────────┬──────────────────────────────────────┘
                           │ HTTP POST (multipart/form-data)
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│                     LARAVEL 12.x (PHP 8.2+)                      │
│                                                                  │
│  PurchaseOrderController (2678 linhas)                           │
│  ├── uploadPurchaseOrderDocument()      ← entrada do ficheiro   │
│  ├── updatePurchaseOrderOCRData()       ← edição AJAX           │
│  ├── PurchaseOrderAdd()                 ← pré-preenchimento     │
│  ├── PurchaseOrderStore()               ← criação final         │
│  │                                                               │
│  ├── parsePurchaseOrderDocument()       ← parser PHP (regex)    │
│  ├── enrichPurchaseOrderDataWithDatabase() ← resolução entidades│
│  ├── findOrCreateSupplierFromOcr()      ← fuzzy match supplier  │
│  ├── findOrCreateProductFromOcr()       ← fuzzy match product   │
│  └── convertLLMFormatToInternal()       ← normalização formato  │
│                                                                  │
│  OcrService (192 linhas)                                         │
│  └── analyzeDocument($file)   ← cliente HTTP → Python           │
└──────────────────────────┬──────────────────────────────────────┘
                           │ HTTP POST (127.0.0.1:5050)
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│                MICROSSERVIÇO PYTHON (FastAPI)                    │
│                                                                  │
│  app.py (322 linhas)                                             │
│  ├── /analyze          ← OCR + LLM (endpoint principal)         │
│  ├── /ocr-only         ← apenas OCR, sem LLM                    │
│  └── /health           ← health check                           │
│                                                                  │
│  ocr_processor.py (778 linhas)                                   │
│  ├── Pré-processamento: correção de perspetiva, deskew, upscale │
│  ├── EasyOCR: extração de texto (pt + en)                       │
│  ├── Agrupamento de linhas lógicas                              │
│  └── Deteção de tabelas                                         │
│                                                                  │
│  document_analyzer.py (1218+ linhas)                             │
│  ├── Few-shot prompting (3 exemplos em PT)                      │
│  ├── Chamada LLM via llama-cpp (OpenAI-compatible API)          │
│  └── Fallback: parser regex + deteção de colunas                │
└──────────────────────────┬──────────────────────────────────────┘
                           │ HTTP (OpenAI-compatible API)
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│              SERVIDOR LLM (llama-cpp, 127.0.0.1:8080)           │
│                                                                  │
│  Modelo: Qwen2.5 7B Instruct (GGUF, Q4 quantizado)              │
│  Contexto: 4096 tokens                                           │
│  Temperatura: 0.1                                                │
└─────────────────────────────────────────────────────────────────┘
```

### Resumo da Stack

| Camada | Tecnologia | Função |
|--------|-----------|--------|
| **Frontend** | Blade + JavaScript vanilla | Captura por câmara, upload, revisão/edição |
| **Backend PHP** | Laravel 12.x | Orquestração, parsing regex, resolução de entidades, CRUD |
| **Base de Dados** | SQLite (configurável) | Persistência de encomendas, fornecedores, produtos |
| **OCR Primário** | EasyOCR (PyTorch, Python) | Extração de texto com IA (português + inglês) |
| **OCR Fallback** | Tesseract 5 (PHP) | Extração de texto tradicional |
| **LLM** | llama-cpp + Qwen2.5 7B | Compreensão semântica do documento |
| **Pré-processamento** | OpenCV, PyMuPDF | Correção de imagem, renderização de PDF |
| **Comunicação** | HTTP REST (Laravel HTTP Client ↔ FastAPI) | Integração entre serviços |

---

## 3. Ficheiros-Chave do Sistema

### Backend Laravel (PHP)

| Ficheiro | Linhas | Função |
|----------|--------|--------|
| `app/Http/Controllers/Actl/PurchaseOrderController.php` | 2678 | Hub central: upload, processamento OCR, parsing, resolução de entidades, criação de PO |
| `app/Services/OcrService.php` | 192 | Cliente HTTP para o microserviço Python |
| `config/services.php` (linhas 49-53) | — | Configuração do serviço OCR (URL, modelo, timeout) |
| `config/purchaseorder.php` | 52 | Configuração de auto-criação (fornecedor, produto, família, unidade, IVA padrão) |
| `app/Models/PurchaseOrderC.php` | — | Modelo do cabeçalho da encomenda |
| `app/Models/PurchaseOrderD.php` | — | Modelo das linhas da encomenda |
| `app/Models/Supplier.php` | — | Modelo de fornecedor |
| `app/Models/Product.php` | — | Modelo de produto/artigo |
| `app/Models/Family.php` | — | Modelo de família de produtos |
| `app/Models/TaxRate.php` | — | Modelo de taxa de IVA |
| `app/Models/UnitMeasure.php` | — | Modelo de unidade de medida |
| `routes/web.php` (linhas 116-120) | — | Rotas OCR |
| `resources/views/backend/purchaseOrder/purchaseOrder_ocr.blade.php` | 853 | Frontend de captura/upload e revisão OCR |
| `resources/views/backend/purchaseOrder/purchaseOrderC_add.blade.php` | — | Formulário de criação de encomenda (recebe dados OCR) |

### Microserviço Python (`ocr-service/`)

| Ficheiro | Linhas | Função |
|----------|--------|--------|
| `ocr-service/app.py` | 322 | Servidor FastAPI (endpoints `/analyze`, `/ocr-only`, `/health`) |
| `ocr-service/ocr_processor.py` | 778 | Pré-processamento de imagem, EasyOCR, agrupamento de linhas, deteção de tabelas |
| `ocr-service/document_analyzer.py` | 1218+ | Integração LLM, few-shot prompting, parser fallback |
| `ocr-service/requirements.txt` | — | Dependências Python |
| `ocr-service/start_service.bat` | — | Script de arranque (Windows) |

---

## 4. Fluxo Passo-a-Passo

### Fase 1 — Captura do Documento (Frontend)

**1.1** O utilizador acede a `/purchaseOrder/ocr`. A view `purchaseOrder_ocr.blade.php` é renderizada.

**1.2** A página oferece dois métodos de captura:

- **Câmara:** Utiliza `navigator.mediaDevices.getUserMedia()` (câmara traseira em dispositivos móveis). Ao capturar, o `<canvas>` é convertido para JPEG (`Blob`/`File`).
- **Upload de ficheiro:** `<input type="file" accept="image/*,.pdf">` aceita PNG, JPG, PDF até 10 MB.

**1.3** O utilizador clica em **"Processar com OCR"**. O JavaScript envia o ficheiro via `POST multipart/form-data` para:

```
POST /purchaseOrder/upload-document → uploadPurchaseOrderDocument()
```

### Fase 2 — Processamento OCR (Backend)

**2.1** O controller valida o tipo de ficheiro (`jpg, jpeg, png, pdf`) e tamanho máximo (10 MB).

**2.2a — Tentativa via Python OCR Service (primário):**

O `OcrService` envia o ficheiro via HTTP POST para `http://127.0.0.1:5050/analyze` com os parâmetros `model` (nome do LLM) e `use_llm`.

**2.2b — Processamento no microserviço Python (`/analyze`):**

1. Guarda o ficheiro recebido como temporário.
2. Chama `extract_text_with_layout()` (`ocr_processor.py`):
   - **Pré-processamento de imagem:**
     - Correção de perspetiva (transformação de 4 pontos via OpenCV — 3 estratégias: Canny edges, threshold adaptativo, convex hull)
     - Deskew (correção de rotação via `minAreaRect`)
     - Upscale para ≥800px no lado menor
   - **EasyOCR:** Executa com modelos de português + inglês (`['pt', 'en']`), com aceleração GPU se disponível.
   - **Agrupamento de linhas:** Agrupa deteções por posição vertical em linhas lógicas.
   - **Deteção de tabelas:** Identifica regiões tabulares (linhas com mistura de texto e números).
3. Chama `analyze_document_with_llm()` (`document_analyzer.py`):
   - Envia texto agrupado para o servidor llama-cpp (`http://127.0.0.1:8080/v1/chat/completions`).
   - **Prompt few-shot:** 3 exemplos detalhados em português a ensinar o modelo a extrair:
     - Fornecedor (nome, NIF, morada)
     - Data e número do documento
     - Linhas de produtos (código, descrição, quantidade, preço unitário, unidade, taxa de IVA)
   - **Temperatura:** 0.1 (determinístico).
   - **Fallback:** Se o LLM falhar, `_fallback_parse()` usa regex, deteção de cabeçalhos de colunas e parsers de formato pipe/bracket.
4. Retorna JSON estruturado para o Laravel.

**2.2c — Fallback para Tesseract (se Python indisponível):**

Se o serviço Python estiver offline (connection refused) ou retornar erro:

1. Guarda o ficheiro em `storage/ocr-temp/`.
2. **Para PDFs:** Converte cada página para PNG com `pdftoppm -png -r 300` (Poppler).
3. **Para imagens:** Executa Tesseract com `-l por` (português).
4. Passa o texto bruto para `parsePurchaseOrderDocument()` (parser PHP baseado em regex).

**2.3 — Normalização de formato:**

O método `convertLLMFormatToInternal()` converte o formato JSON do LLM para o formato interno PHP:

```
LLM: { supplier: { name, nif, address }, lines: [{ productCode, productDescription, ... }] }
  ↓
PHP: { supplier: { nome, nif, morada }, lines: [{ codigo, descricao, quantidade, precoUnitario, unidade, iva }] }
```

**2.4 — Comparação LLM vs. Parser PHP (abordagem híbrida):**

O sistema executa **AMBOS** o LLM e o parser PHP sobre o texto OCR e compara:
- Número de linhas encontradas
- Se foram detetados preços
- Se foram detetadas descrições
- Discrepâncias de preços

Seleciona automaticamente o parser que produziu melhores resultados (mais linhas, melhores preços, melhores descrições).

### Fase 3 — Resolução de Entidades

O método `enrichPurchaseOrderDataWithDatabase()` enriquece os dados extraídos com informação da base de dados.

**3.1 — Resolução de Fornecedor (`findOrCreateSupplierFromOcr()`):**

1. Procura correspondência exata por NIF na tabela `Supplier`.
2. Procura correspondência exata por nome.
3. Procura correspondência fuzzy por nome (similaridade combinada de caracteres + tokens ≥ 60%).
4. Se não encontrado **E** `auto_create_supplier = true`:
   - Gera código automático (`max(code) + 1`).
   - Cria registo em `Supplier` (nome, NIF, morada do OCR).
   - Regista a criação em log.

**3.2 — Resolução de Produtos (`findOrCreateProductFromOcr()`):**

Para cada linha extraída:

1. Procura correspondência exata por código na tabela `Product`.
2. Procura correspondência exata por descrição.
3. Procura correspondência fuzzy por descrição (similaridade ≥ 65%).
4. Se não encontrado **E** `auto_create_product = true` **E** descrição tem ≥ 2 caracteres:
   - Garante família padrão (`ensureDefaultFamily()` → cria "GERAL" se necessário).
   - Resolve unidade do OCR (`resolveUnitFromOcr()` → mapeia KG, CX, UN, L, M, etc.; fallback "UN").
   - Resolve taxa de IVA (`resolveTaxRateFromOcr()` → procura percentagem na tabela `TaxRate`; fallback para config default, tipicamente 23%).
   - Gera código de produto (`generateProductCode()` → `ART-{next_id}`).
   - Cria registo em `Product`.
   - Regista a criação em log.

**3.3 — Validação de Subtotais (`buildSubtotalValidation()`):**

Compara o subtotal/total extraído do documento com a soma calculada das linhas (quantidade × preço unitário). Reporta discrepâncias com estado: **OK** / **Aviso** / **Erro**.

### Fase 4 — Revisão e Edição (Frontend)

**4.1** O JSON enriquecido é retornado ao navegador.

**4.2** O JavaScript em `purchaseOrder_ocr.blade.php` renderiza:

- **Vista de leitura:** Nome/NIF/código do fornecedor, data do documento, tabela de linhas com checkboxes (ativar/desativar cada linha), estado de validação (subtotal OK/divergente).
- **Vista de edição** (toggle "Editar"): Inputs editáveis para nome do fornecedor, NIF, data, e todos os campos das linhas (código, descrição, quantidade, preço, taxa de IVA).
- **Cartão de resumo:** Contagem de linhas selecionadas, produtos existentes vs. novos.

**4.3** O utilizador pode:

- Ativar/desativar linhas individuais (persistido na sessão via `updatePurchaseOrderOCRData()`).
- Editar qualquer campo extraído (também persistido na sessão).
- Ver avisos de validação cruzada de subtotais.

**4.4** O botão **"Criar Encomenda"** redireciona para `/purchaseOrder/add?supplier_code=XXX`.

### Fase 5 — Criação da Encomenda

**5.1** Ao clicar "Criar Encomenda", o utilizador é redirecionado para `PurchaseOrderAdd()`.

**5.2** `PurchaseOrderAdd()` lê os dados OCR da sessão (`ocr_purchase_order_data`):

- Mapeia nome/NIF do fornecedor OCR para fornecedor existente (por NIF, se possível).
- Garante que todos os produtos existem na BD antes da renderização do formulário (chama `enrichPurchaseOrderDataWithDatabase()` com `createIfMissing=true`).
- Passa `initialLines` (código, quantidade, preço unitário) e `ocrSupplierCode` para a view.

**5.3** O formulário de encomenda (`purchaseOrderC_add.blade.php`) renderiza com fornecedor e linhas pré-preenchidos.

**5.4** O utilizador preenche campos restantes (número da encomenda, data, observações, desconto financeiro) e submete.

**5.5** `PurchaseOrderStore()`:

- Valida todos os inputs (fornecedor existe, produtos existem).
- Calcula totais das linhas, IVA, desconto financeiro.
- Cria registo `PurchaseOrderC` (cabeçalho: número, fornecedor, data, totais, estado).
- Cria registos `PurchaseOrderD` (linhas: produto, quantidade, preço unitário, taxa de IVA, etc.).
- Limpa dados OCR da sessão.
- Redireciona para lista de encomendas com mensagem de sucesso.

---

## 5. Modelos de Dados e Tabelas

### Tabelas envolvidas no processo OCR

| Modelo Eloquent | Tabela DB | Campos Relevantes | Relevância OCR |
|-----------------|-----------|-------------------|----------------|
| `PurchaseOrderC` | `PurchaseOrderC` | `id`, `pONumber`, `supplierCode`, `pODate`, `pOObservation`, `financialDiscount`, `totalNet`, `totalTax`, `totalGross`, `status`, `created_by`, `updated_by` | Cabeçalho criado após revisão OCR |
| `PurchaseOrderD` | `PurchaseOrderD` | `id`, `pONumber`, `productCode`, `productFamily`, `productUnit`, `taxRateCode`, `quantity`, `deliveryQuantity`, `unitPrice`, `sellingPrice`, `status` | Linhas criadas a partir do OCR |
| `Supplier` | `Supplier` | `code` (PK, int), `name`, `nif`, `address1`, `address2`, `town`, `postalCode`, `status` | Auto-criado ou correspondido do OCR |
| `Product` | `Product` | `code` (PK, string), `description`, `family`, `unit`, `taxRateCode`, `image`, `status` | Auto-criado ou correspondido do OCR |
| `Family` | `Family` | `family` (PK, string) | "GERAL" auto-criada se em falta |
| `UnitMeasure` | `UnitMeasure` | `unit` (PK, string) | "UN" auto-criada se em falta; deteção de unidade do OCR |
| `TaxRate` | `TaxRate` | `taxRateCode` (PK, int), `descriptionTaxRate`, `taxRate` (float %), `status` | Taxa de IVA correspondida por percentagem |

---

## 6. Configuração

### Variáveis de Ambiente Laravel (`.env`)

```env
OCR_SERVICE_URL=http://127.0.0.1:5050
OCR_LLM_MODEL=qwen2.5-7b-instruct-q4_k_m
OCR_SERVICE_TIMEOUT=120
LLM_BASE_URL=http://127.0.0.1:8080/v1
LLM_MODEL=qwen2.5-7b-instruct-q4_k_m
```

### `config/services.php` — secção `ocr`

| Chave | Valor Padrão | Descrição |
|-------|-------------|-----------|
| `base_url` | `http://127.0.0.1:5050` | URL do microserviço Python FastAPI |
| `llm_model` | `qwen2.5-7b-instruct-q4_k_m` | Nome do modelo LLM |
| `timeout` | `120` | Timeout HTTP em segundos |

### `config/purchaseorder.php` — secção `ocr`

| Chave | Env | Valor Padrão | Descrição |
|-------|-----|-------------|-----------|
| `auto_create_supplier` | `PO_OCR_AUTO_CREATE_SUPPLIER` | `true` | Criar fornecedor automaticamente se não encontrado |
| `auto_create_product` | `PO_OCR_AUTO_CREATE_PRODUCT` | `true` | Criar produto automaticamente se não encontrado |
| `default_family` | `PO_OCR_DEFAULT_FAMILY` | `GERAL` | Família atribuída a produtos auto-criados |
| `default_unit` | `PO_OCR_DEFAULT_UNIT` | `UN` | Unidade de medida padrão para produtos auto-criados |
| `default_tax_rate_code` | `PO_OCR_DEFAULT_TAX_RATE_CODE` | `23` | Código de taxa de IVA fallback |
| `product_code_prefix` | `PO_OCR_PRODUCT_CODE_PREFIX` | `ART-` | Prefixo para códigos de produtos auto-gerados |

### Variáveis de Ambiente Python

```env
OCR_SERVICE_HOST=127.0.0.1
OCR_SERVICE_PORT=5050
LLM_BASE_URL=http://127.0.0.1:8080/v1
LLM_MODEL=qwen2.5-7b-instruct-q4_k_m
LLM_MAX_CONTEXT=4096
```

---

## 7. Motores de OCR e Processamento

### Abordagem em Camadas (Tiered)

O sistema utiliza uma arquitetura de duas camadas com fallback automático.

### Camada 1 — EasyOCR (Python, Deep Learning)

| Característica | Detalhe |
|----------------|---------|
| **Biblioteca** | `easyocr` (baseado em PyTorch) |
| **Idiomas** | Português (`pt`) + Inglês (`en`) |
| **Aceleração GPU** | Sim (CUDA), via `gpu=True` |
| **Padrão Singleton** | Reader inicializado uma vez e reutilizado |

**Pipeline de pré-processamento:**

1. **Correção de perspetiva** — Transformação de 4 pontos via OpenCV (3 estratégias: Canny edges, threshold adaptativo, convex hull). Corrige fotos tiradas em ângulo.
2. **Upscale** — Amplia para mínimo de 800px no lado menor (melhora precisão do OCR).
3. **Deskew** — Correção de rotação via `minAreaRect`.
4. **Conversão RGB** — Garante formato correto para o EasyOCR.

**Pós-processamento:**

- Agrupamento de deteções por posição vertical em linhas lógicas.
- Fusão de grupos palavra-por-linha em linhas de tabela.
- Deteção de regiões tabulares (linhas com mistura de texto e números).

### Camada 2 — Tesseract 5 (PHP, OCR Tradicional)

| Característica | Detalhe |
|----------------|---------|
| **Biblioteca** | `thiagoalessio/tesseract_ocr` (wrapper PHP) |
| **Idioma** | Português (`por`) |
| **Suporte PDF** | `pdftoppm` (Poppler) converte páginas para PNG a 300 DPI |
| **Ativação** | Quando o serviço Python está offline ou retorna erro |

### LLM para Compreensão Semântica

| Característica | Detalhe |
|----------------|---------|
| **Motor** | llama-cpp (servidor local com API compatível com OpenAI) |
| **Modelo** | Qwen2.5 7B Instruct (GGUF, quantização Q4_K_M) |
| **Contexto máximo** | 4096 tokens |
| **Temperatura** | 0.1 (determinístico) |
| **Prompting** | Few-shot com 3 exemplos detalhados em português, instruções explícitas de output JSON |
| **Fallback** | Se o LLM falhar, `_fallback_parse()` usa regex + deteção de cabeçalhos de colunas + parsers bracket/pipe |

---

## 8. Resolução de Entidades

### Fornecedores (`findOrCreateSupplierFromOcr`)

```
NIF extraído do OCR
        │
        ▼
┌─ Correspondência exata por NIF? ──→ SIM ──→ Retorna fornecedor
└─ NÃO
        │
        ▼
┌─ Correspondência exata por nome? ──→ SIM ──→ Retorna fornecedor
└─ NÃO
        │
        ▼
┌─ Fuzzy match nome (similaridade ≥ 60%)? ──→ SIM ──→ Retorna fornecedor
└─ NÃO
        │
        ▼
┌─ auto_create_supplier = true? ──→ SIM ──→ Cria Supplier (código auto-incremental)
└─ NÃO
        │
        ▼
    Retorna null (fornecedor não resolvido)
```

### Produtos (`findOrCreateProductFromOcr`)

```
Código extraído do OCR
        │
        ▼
┌─ Correspondência exata por código? ──→ SIM ──→ Retorna produto
└─ NÃO
        │
        ▼
┌─ Correspondência exata por descrição? ──→ SIM ──→ Retorna produto
└─ NÃO
        │
        ▼
┌─ Fuzzy match descrição (similaridade ≥ 65%)? ──→ SIM ──→ Retorna produto
└─ NÃO
        │
        ▼
┌─ auto_create_product = true E descrição ≥ 2 chars? ──→ SIM
│                                                            │
│    ┌─ Garante família "GERAL"                              │
│    ├─ Resolve unidade (KG, CX, UN, L, M, etc.)            │
│    ├─ Resolve taxa de IVA (% → TaxRate)                    │
│    ├─ Gera código "ART-{next_id}"                          │
│    └─ Cria Product                                         │
└─ NÃO
        │
        ▼
    Retorna null (produto não resolvido)
```

---

## 9. Mapeamento OCR → Encomenda

### Pipeline de Dados

```
Documento (PDF/Imagem)
        │
        ▼
    EasyOCR / Tesseract
        │
        ▼
    Texto bruto + coordenadas
        │
        ▼
    LLM (Qwen2.5) / Parser Regex
        │
        ▼
┌─────────────────────────────────────┐
│ JSON Estruturado (formato LLM)       │
│ {                                    │
│   supplier: { name, nif, address },  │
│   documentDate, documentNumber,      │
│   lines: [{ productCode,             │
│             productDescription,      │
│             quantity, unitPrice,     │
│             unit, taxRate }],        │
│   totalNet, totalGross               │
│ }                                    │
└─────────────────────────────────────┘
        │ convertLLMFormatToInternal()
        ▼
┌─────────────────────────────────────┐
│ JSON Interno PHP                     │
│ {                                    │
│   supplier: { nome, nif, morada },   │
│   lines: [{ codigo, descricao,       │
│             quantidade,              │
│             precoUnitario,           │
│             unidade, iva }],         │
│   documentDate, documentNumber       │
│ }                                    │
└─────────────────────────────────────┘
        │ enrichPurchaseOrderDataWithDatabase()
        ▼
┌─────────────────────────────────────┐
│ JSON Enriquecido (com DB info)       │
│ {                                    │
│   supplier: { code, name, nif,       │
│               found: true/false },   │
│   lines: [{ productCode, description,│
│             productFamily,           │
│             productUnit, taxRateCode,│
│             taxRate, quantity,       │
│             unitPrice, found,        │
│             enabled, ocrRaw }],      │
│   validation: { subtotal: {          │
│     extracted, calculated,           │
│     discrepancy, status } }          │
│ }                                    │
└─────────────────────────────────────┘
        │ Revisão do utilizador + sessão
        ▼
┌─────────────────────────────────────┐
│ PurchaseOrderC (cabeçalho)           │
│ PurchaseOrderD (linhas)              │
└─────────────────────────────────────┘
```

### Correspondência de Campos

| Campo OCR | Coluna DB | Notas |
|-----------|-----------|-------|
| `supplier.code` | `PurchaseOrderC.supplierCode` | FK → `Supplier.code` |
| `documentDate` | `PurchaseOrderC.pODate` | Data do documento |
| `documentNumber` / auto-numeração | `PurchaseOrderC.pONumber` | Preenchido pelo utilizador |
| `line.productCode` | `PurchaseOrderD.productCode` | FK → `Product.code` |
| `line.productFamily` | `PurchaseOrderD.productFamily` | Do produto ou default |
| `line.productUnit` | `PurchaseOrderD.productUnit` | Do produto ou do OCR |
| `line.taxRateCode` | `PurchaseOrderD.taxRateCode` | Resolvido da % de IVA |
| `line.quantity` | `PurchaseOrderD.quantity` | Conforme extraído |
| `line.unitPrice` | `PurchaseOrderD.unitPrice` | Conforme extraído |
| Calculado | `PurchaseOrderC.totalNet` | Σ (qtd × preço unit.) |
| Calculado | `PurchaseOrderC.totalTax` | Σ (líquido linha × taxa IVA/100) |
| Calculado | `PurchaseOrderC.totalGross` | totalNet + totalTax − desconto financeiro |
| Calculado | `PurchaseOrderC.financialDiscount` | Introduzido pelo utilizador |

---

## 10. Gestão de Estado (Sessão)

O fluxo de dados OCR atravessa várias páginas via sessão Laravel:

| Chave de Sessão | Conteúdo | Ciclo de Vida |
|-----------------|----------|---------------|
| `ocr_purchase_order_data` | Estrutura completa de dados enriquecidos após processamento OCR | Criada no upload, atualizada via AJAX, limpa após `PurchaseOrderStore()` |

**Endpoints de gestão de sessão:**

- `POST /purchaseOrder/upload-document` → Processa OCR, guarda dados na sessão.
- `POST /purchaseOrder/update-ocr-data` → Atualiza campos editados (AJAX), persiste na sessão.
- `GET /purchaseOrder/add` → Lê sessão para pré-preenchimento do formulário.
- `POST /purchaseOrder` → Lê sessão, cria encomenda, limpa sessão.

Isto permite que o utilizador navegue entre a página OCR e o formulário de encomenda sem perder os dados extraídos.

---

## 11. Tratamento de Erros e Fallbacks

### Estratégia de Resiliência

```
uploadPurchaseOrderDocument()
        │
        ▼
   Tenta Python OCR Service
        │
   ┌────┴────┐
   │ SUCESSO │──────────→ Processa resultado JSON
   └─────────┘
        │
   ┌────┴────┐
   │  FALHA  │ (connection refused, timeout, erro 500)
   └─────────┘
        │
        ▼
   Fallback Tesseract PHP
        │
   ┌────┴────┐
   │ SUCESSO │──────────→ Processa texto com parser regex PHP
   └─────────┘
        │
   ┌────┴────┐
   │  FALHA  │──────────→ Erro para o utilizador
   └─────────┘
```

### Fallbacks Específicos

| Ponto de Falha | Fallback |
|----------------|----------|
| Serviço Python offline | Tesseract 5 + parser regex PHP |
| LLM (llama-cpp) offline/erro | `_fallback_parse()` — regex + deteção de colunas |
| Fornecedor não encontrado na BD | Auto-criação (se configurado) ou fornecedor não resolvido |
| Produto não encontrado na BD | Auto-criação (se configurado) ou produto não resolvido |
| PDF sem `pdftoppm` instalado | Erro informativo para o utilizador |
| Tesseract não instalado | Erro informativo para o utilizador |

### Abordagem Híbrida LLM + Regex

Quando ambos os serviços estão disponíveis, o sistema executa os DOIS parsers (LLM e regex PHP) e compara os resultados, selecionando automaticamente o melhor com base em:
- Número de linhas detetadas
- Presença/qualidade de preços
- Presença/qualidade de descrições
- Discrepâncias de preços entre parsers

---

## 12. Requisitos de Infraestrutura

### Serviços Necessários

| Serviço | Porta | Obrigatório? | Descrição |
|---------|-------|-------------|-----------|
| **Laravel (PHP)** | 8000 (dev) | Sim | Aplicação principal ERP |
| **Python FastAPI** | 5050 | Recomendado | OCR com EasyOCR + LLM |
| **llama-cpp** | 8080 | Recomendado | Servidor LLM local |
| **Tesseract 5** | — | Fallback | OCR tradicional (binário de sistema) |
| **Poppler (pdftoppm)** | — | Fallback | Conversão PDF → imagem |

### Dependências Python (`ocr-service/requirements.txt`)

```
fastapi
uvicorn
python-multipart
easyocr
opencv-python-headless
PyMuPDF
numpy
Pillow
requests
```

### Modelo LLM

- **Ficheiro:** `qwen2.5-7b-instruct-q4_k_m.gguf`
- **Tamanho:** ~4.7 GB (quantizado Q4)
- **RAM recomendada:** 8+ GB

### Arranque dos Serviços

```bash
# Laravel (desenvolvimento)
php artisan serve

# Microserviço Python OCR
cd ocr-service
python app.py
# ou Windows:
start_service.bat

# Servidor LLM (llama-cpp)
llama-server.exe -m qwen2.5-7b-instruct-q4_k_m.gguf --port 8080
```

---

## Anexo: Diagrama de Sequência Simplificado

```
Utilizador          Navegador           Laravel            Python OCR         llama-cpp
   │                   │                   │                   │                  │
   │  Acede /ocr       │                   │                   │                  │
   │──────────────────→│                   │                   │                  │
   │                   │                   │                   │                  │
   │  Tira foto/upload │                   │                   │                  │
   │──────────────────→│                   │                   │                  │
   │                   │  POST /upload     │                   │                  │
   │                   │──────────────────→│                   │                  │
   │                   │                   │  POST /analyze    │                  │
   │                   │                   │──────────────────→│                  │
   │                   │                   │                   │  EasyOCR         │
   │                   │                   │                   │  + pré-process   │
   │                   │                   │                   │                  │
   │                   │                   │                   │  POST /chat      │
   │                   │                   │                   │─────────────────→│
   │                   │                   │                   │  LLM response    │
   │                   │                   │                   │←─────────────────│
   │                   │                   │                   │                  │
   │                   │                   │  JSON estruturado │                  │
   │                   │                   │←──────────────────│                  │
   │                   │                   │                   │                  │
   │                   │                   │  Resolve entidades na BD             │
   │                   │                   │  (fornecedores, produtos)            │
   │                   │                   │                   │                  │
   │                   │  JSON enriquecido │                   │                  │
   │                   │←──────────────────│                   │                  │
   │                   │                   │                   │                  │
   │  Mostra revisão   │                   │                   │                  │
   │←──────────────────│                   │                   │                  │
   │                   │                   │                   │                  │
   │  Edita/revisa     │                   │                   │                  │
   │──────────────────→│  AJAX /update     │                   │                  │
   │                   │──────────────────→│                   │                  │
   │                   │                   │                   │                  │
   │  Clica "Criar"    │                   │                   │                  │
   │──────────────────→│  GET /add         │                   │                  │
   │                   │──────────────────→│                   │                  │
   │                   │  Form pré-preench │                   │                  │
   │                   │←──────────────────│                   │                  │
   │                   │                   │                   │                  │
   │  Submete form     │                   │                   │                  │
   │──────────────────→│  POST /store      │                   │                  │
   │                   │──────────────────→│                   │                  │
   │                   │                   │  Cria PurchaseOrderC/D              │
   │                   │  Redirect OK      │                   │                  │
   │                   │←──────────────────│                   │                  │
```

---

*Documento gerado automaticamente com base na análise do código-fonte do ERP4U.*
