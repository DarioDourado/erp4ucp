# Purchase Order OCR — Análise Completa de Falhas e Recomendações

## 1. O Documento (`encomenda_1.png`)

O ficheiro é uma **encomenda a fornecedor** (Purchase Order) da empresa **Mercearia Bom Preço** para o fornecedor **Distribuidora Lusitânia, Lda.**, datada de **20/05/2025**, com 8 linhas de artigos (códigos 1001–1008).

**Dados esperados:**

| Campo | Valor Esperado |
|-------|---------------|
| Fornecedor | Distribuidora Lusitânia, Lda. |
| NIF Fornecedor | 500 123 456 |
| Data Encomenda | 20/05/2025 |
| Nº Encomenda | 2025/058 |
| Total Linhas | 8 (artigos 1001–1008) |
| Total c/ IVA | 182,49 € |

---

## 2. O que o Tesseract Realmente Extrai

Executei o Tesseract v5 com `-l por` (português) sobre [`encomenda_1.png`](encomenda_1.png). O output completo:

```
Na Mercearia (Qnnuaçãs Hora 24 ENCOMENDA Nº 2025/058

[e]
UN Bo m Preço R$ 211234567 DATA DA ENCOMENDA: DATA PREVISTA DE ENTREGA: FORNECEDOR:

bomprecoQmercearia pt 20/05/2025 22/05/2025 (5.º feira) Distribuidora Lusitânia, Lda
Qualidade do bairro, perto de si. NIF: 500 123 456 CONTACTO:
219876543
EDIR como ETA ADE UANTIDADE PREÇO UNI VALOR TO'
ENCOMENDADA CONFIRM; (s/ IVA) (s/ IVA)
1001 Arroz Agulha Cacarola kg 20 20 1,15€ 23,00 €
1002 Massa Espiral Milanesa 500 g 24 24 0,79 € 18,96 €
1003 Óleo Alimentar Fula dat 15 15 1,69€ 25,35 €
1004 Atum em Óleo Bom Petisco 120g 30 30 1,09 € 32,70 €
1005 Leite Meio Gordo Mimosa ui 20 20 0,72€ 14,40€
1006 Café Torrado Moído Delta 250g 10 10 1,68€ 16,80 €
1007 Açúcar Branco Sidul 1kg 15 15 0,85 € 12,75 €
1008 Papel Higiénico Renova Pack 4 rolos 12 12 2,35 € 28,20 €
OBSERVAÇÕES CONDIÇÕES
ç TOTAL SEM IVA 172,16 €
...
```

A qualidade é **medíocre**: resolução estimada de apenas **163 DPI** (o ideal é 300+), com vários erros de reconhecimento (`Qnnuaçãs` em vez de `Encomendas`, `Cacarola` em vez de `Carolino`, `Fula dat` em vez de `Fula 1L`).

---

## 3. Análise Detalhada — 6 Falhas que Bloqueiam a Extração

### 🔴 Falha #1: Nome do Fornecedor Errado

**Código**: [`parsePurchaseOrderDocument()`](app/Http/Controllers/Actl/PurchaseOrderController.php:976-1006)

O algoritmo percorre as linhas à procura da **primeira linha não vazia, com ≥3 caracteres, que não seja header/endereço/NIF**:

```
Linha 1: "Na Mercearia (Qnnuaçãs Hora 24 ENCOMENDA Nº 2025/058"  ← ≥3 chars, NÃO começa com "encomenda"
                                                                     (começa com "Na"), NÃO é endereço/NIF
                                                                     → TORNA-SE O NOME DO FORNECEDOR! ❌
```

**Resultado**: O fornecedor fica `"Na Mercearia (Qnnuaçãs Hora 24 ENCOMENDA Nº 2025/058"` em vez de `"Distribuidora Lusitânia, Lda"`.

**Causa raiz**: O parser não reconhece cabeçalhos de documento que não comecem exatamente com palavras-chave. Não há procura pelo label `FORNECEDOR:` que existe no documento original.

### 🔴 Falha #2: NIF não Extraído (Espaços no dígito)

**Código**: [`parsePurchaseOrderDocument()`](app/Http/Controllers/Actl/PurchaseOrderController.php:961)

```
Regex: /\b(?:NIF|Contribuinte|Fiscal\s*N[oº]?)\s*[:\s]*(\d{9})\b/i

Texto real: "NIF: 500 123 456"
           → \d{9} espera 9 dígitos consecutivos
           → "500 123 456" tem espaços → NÃO CORRESPONDE ❌
```

### 🔴 Falha #3: Data da Encomenda Não Extraída

**Código**: [`parsePurchaseOrderDocument()`](app/Http/Controllers/Actl/PurchaseOrderController.php:943-1018)

**O parser não tem qualquer lógica para extrair data.** O campo `pODate` existe na BD (`PurchaseOrderC.pODate`) mas não é extraído do documento.

O texto tem claramente `"DATA DA ENCOMENDA: 20/05/2025"` mas nenhum código o captura.

### 🔴 Falha #4: Regex de Linhas Exige `\s{2,}` mas Documento Usa Espaço Simples

**Código**: [`extractPOTabularLines()`](app/Http/Controllers/Actl/PurchaseOrderController.php:1043-1050)

O padrão tabular principal:
```php
$tabularPattern = '/^' .
    '(?:\s*(?:\d{2,}[-\s])?(?P<codigo>...)?\s+)?' .
    '(?P<descricao>.+?)' .
    '\s{2,}' .                    // ← REQUER 2+ ESPAÇOS CONSECUTIVOS
    '(?P<quantidade>\d+(?:[.,]\d+)?)' .
    '\s{2,}' .                    // ← REQUER 2+ ESPAÇOS CONSECUTIVOS
    '(?P<preco>\d+(?:[.,]\d+)?)' .
    '\s*$/u';
```

**O problema**: O Tesseract extrai as linhas com espaços **simples** entre colunas. Exemplo real:
```
1001 Arroz Agulha Cacarola kg 20 20 1,15€ 23,00 €
```

Não há **2+ espaços consecutivos** em lado nenhum da linha. O `\s{2,}` nunca corresponde → **0 linhas extraídas**.

### 🔴 Falha #5: Símbolo `€` Quebra o Regex de Preço

Mesmo que o `\s{2,}` fosse corrigido, o preço `"1,15€"` contém o símbolo `€`. O grupo de captura do preço é `\d+(?:[.,]\d+)?` que só captura dígitos — a regex falharia porque depois de `1,15` ainda há `€ 23,00 €` antes do fim da linha (`$`).

### 🔴 Falha #6: Sem Preprocessamento de Imagem

**Código**: [`runTesseractOnImage()`](app/Http/Controllers/Actl/PurchaseOrderController.php:1224-1245)

A imagem é passada diretamente ao Tesseract sem:
- Redimensionamento para 300+ DPI
- Binarização/thresholding
- Remoção de ruído
- Correção de skew/inclinação
- Aumento de contraste

O Tesseract reporta `Estimating resolution as 163` — abaixo do mínimo recomendado de 300 DPI para OCR de qualidade.

---

## 4. Comparação: Abordagem Atual vs. Abordagem Ideal

| Aspeto | Atual (Tesseract + Regex) | Ideal |
|--------|--------------------------|-------|
| Engine OCR | Tesseract 5 (genérico) | Document Understanding AI específico para faturas/encomendas |
| Preprocessamento | Nenhum | Binarização + deskew + upscale para 300+ DPI |
| Parsing | Regex posicional frágil | ML-based layout analysis ou LLM vision |
| Compreensão contexto | Nula (texto plano) | Entende estrutura do documento (cabeçalho, tabela, rodapé) |
| Suporte a variações | Muito baixo | Alto (modelos treinados em milhares de layouts) |
| Manutenção | Regex precisa ser ajustado para cada formato | Modelo adapta-se automaticamente |

---

## 5. Soluções Recomendadas

### 🏆 Recomendação Principal: LLM com Visão (Claude API / GPT-4o)

**Porquê**: É a solução mais prática e de implementação imediata. Um LLM com capacidade de visão consegue:
- **Interpretar a imagem diretamente** (sem passar por OCR intermédio)
- **Entender a estrutura** do documento (cabeçalho → tabela → rodapé)
- **Extrair campos específicos** com base em instruções em linguagem natural
- **Lidar com variações** de layout sem alterações de código

**Implementação**:
```php
// NOVO: Serviço de Document Understanding via API
class DocumentUnderstandingService
{
    public function analyze(UploadedFile $file): array
    {
        $imageBase64 = base64_encode($file->getContent());
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.anthropic.key'),
            'Content-Type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 4096,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => <<<PROMPT
Analise esta encomenda a fornecedor (purchase order) em português.
Extraia os seguintes dados em formato JSON:

{
  "supplier": {
    "name": "nome completo do fornecedor",
    "nif": "NIF (9 dígitos, sem espaços)",
    "address": "morada completa"
  },
  "documentDate": "YYYY-MM-DD",
  "documentNumber": "número do documento",
  "lines": [
    {
      "productCode": "código do artigo",
      "productDescription": "descrição do artigo",
      "quantity": 0,
      "unitPrice": 0.00
    }
  ],
  "totalNet": 0.00,
  "totalGross": 0.00
}

Devolva APENAS o JSON, sem texto adicional.
PROMPT
                    ],
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $file->getMimeType(),
                            'data' => $imageBase64,
                        ],
                    ],
                ],
            ]],
        ]);
        
        return $response->json();
    }
}
```

**Custos aprox.**:
- Claude Sonnet 4: ~$3-5/1M imagens (cents por documento)
- GPT-4o: ~$5-10/1M imagens
- Para uso interno (<1000 docs/mês): <$10/mês

### 🥈 Alternativa Cloud: Google Document AI — Procurement Parser

**Porquê**: Existe um parser especializado para Procurement (compras/faturas) que cobre:
- Extração de PO Number, supplier, dates, line items, totals
- Invoice e Purchase Order comprehension
- Suporte multi-idioma (incluindo português)
- API REST simples

**Implementação**:
```php
use Google\Cloud\DocumentAI\V1\DocumentProcessorServiceClient;
use Google\Cloud\DocumentAI\V1\ProcessRequest;

$client = new DocumentProcessorServiceClient();
$name = $client->processorName('project-id', 'location', 'processor-id');

$request = (new ProcessRequest())
    ->setName($name)
    ->setRawDocument([
        'content' => $file->getContent(),
        'mime_type' => $file->getMimeType(),
    ]);

$result = $client->processDocument($request);
$entities = $result->getDocument()->getEntities();
```

**Custos**: ~$0.01–0.02 por página (Google Document AI)

### 🥉 Alternativa Local: PaddleOCR + Layout Detection

**Porquê**: Se precisam de solução 100% on-premise (sem enviar documentos para cloud):

```python
# PaddleOCR (Python microservice)
from paddleocr import PaddleOCR

ocr = PaddleOCR(use_angle_cls=True, lang='pt')

def extract_from_image(image_path):
    result = ocr.ocr(image_path, cls=True)
    # Reconstrói layout bidimensional a partir das bounding boxes
    lines = reconstruct_layout(result)
    return lines
```

**Stack recomendado**: 
- **PaddleOCR** para reconhecimento de texto (substitui Tesseract)
- **YOLOv8** ou **TableTransformer** para deteção de tabelas
- **Post-processing** com heurísticas (ou LLM local como Llama 3) para interpretação

**Custos**: 0 (open-source), requer servidor com GPU (ou CPU para baixo volume)

### 🚀 Melhoria Imediata (Sem Mudar de Stack)

Se quiserem continuar com Tesseract, as correções necessárias:

1. **Pré-processamento da imagem** antes do OCR:
```php
// Usar Imagick/GD para melhorar a imagem
$img = new \Imagick($path);
$img->setImageResolution(300, 300);
$img->resampleImage(300, 300, \Imagick::FILTER_LANCZOS, 1);
$img->setImageType(\Imagick::IMGTYPE_GRAYSCALEMATTE);
$img->contrastImage(true);
$img->deskewImage(0.4); // Correção de inclinação
$img->writeImage($path);
```

2. **Substituir `\s{2,}` por `\s+`** nos padrões regex de linhas (e usar separadores de coluna mais flexíveis)

3. **Parser de fornecedor baseado no label `FORNECEDOR:`**

4. **Parser de data** com regex `DATA DA ENCOMENDA:\s*(\d{2}/\d{2}/\d{4})`

5. **Remover `€` dos preços** antes de aplicar regex (`str_replace('€', '', $line)`)

---

## 6. Resumo e Decisão

| Solução | Complexidade | Custo | Qualidade | Privacidade |
|---------|-------------|-------|-----------|-------------|
| ✅ **Claude/GPT-4 Vision** | Média (API) | ~€0.01/doc | ⭐⭐⭐⭐⭐ Excelente | Dados vão para cloud |
| ✅ **Google Document AI** | Baixa (API) | ~€0.02/doc | ⭐⭐⭐⭐⭐ Excelente | Dados vão para cloud |
| ⚡ **PaddleOCR + LLM local** | Alta | 0 (on-prem) | ⭐⭐⭐⭐ Muito Bom | 100% privado |
| 🛠️ **Melhorar Tesseract atual** | Média | 0 | ⭐⭐⭐ Razoável | 100% privado |

**Recomendo implementar a solução Claude/GPT-4 Vision como camada primária** (mais rápida de implementar, menos frágil) e **manter o Tesseract como fallback** para quando a API não estiver disponível (ex.: sem internet).

Quer que eu implemente uma das soluções sugeridas?
