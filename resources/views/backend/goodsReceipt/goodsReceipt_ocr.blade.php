@extends('admin.admin_master')

@section('admin')
<div class="page-content" id="goodsReceiptOcrPage">
    <div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0">Entrada de Mercadoria com OCR</h4>
            </div>
            <p class="text-muted mb-4">Tire uma foto ao documento do fornecedor ou carregue uma imagem para extrair automaticamente os dados.</p>
        </div>
    </div>

    <div class="row">
        <!-- Secção 1: Câmara e Upload -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="card-title mb-0">1. Capturar Documento</h4>
                    </div>

                    <div class="alert alert-light border py-2 mb-3" role="alert">
                        Pode usar a câmara ou carregar um ficheiro (PDF/JPEG/PNG, até 20MB).
                    </div>

                    <!-- Câmara -->
                    <div id="camera-section" class="mb-3">
                        <label class="form-label">Câmara</label>
                        <div class="position-relative camera-wrapper">
                            <video id="camera-preview" 
                                   width="100%" 
                                   style="display: block; background: #000; min-height: 300px;">
                            </video>
                            <div id="camera-loading" class="position-absolute top-50 start-50 translate-middle text-white" 
                                 style="display: none;">
                                <div class="spinner-border spinner-border-sm" role="status">
                                    <span class="visually-hidden">A carregar câmara...</span>
                                </div>
                                <p class="small mt-2 mb-0">A iniciar câmara...</p>
                            </div>
                        </div>
                        <button type="button" id="capture-btn" class="btn btn-primary btn-sm w-100 mt-2" 
                                title="Tirar fotografia">
                            <i class="fas fa-camera"></i> Tirar Foto
                        </button>
                        <button type="button" id="toggle-camera-btn" class="btn btn-outline-secondary btn-sm w-100 mt-2">
                            <i class="fas fa-times"></i> Fechar Câmara
                        </button>
                    </div>

                    <hr>

                    <!-- Upload de Arquivo -->
                    <div id="upload-section">
                        <label class="form-label">Carregar Documento</label>
                        <div class="input-group">
                            <input type="file" 
                                   id="document-input" 
                                   accept="image/*,.pdf" 
                                   class="form-control"
                                   aria-label="Selecionar documento">
                            <label class="input-group-text" for="document-input">
                                <i class="fas fa-upload"></i>
                            </label>
                        </div>
                        <small class="text-muted d-block mt-1">
                            Tipos suportados: PDF, JPEG, PNG (máx. 20MB)
                        </small>
                    </div>

                    <hr>

                    <!-- Canvas para captura (oculto) -->
                    <canvas id="capture-canvas" style="display: none;"></canvas>

                    <!-- Botão de Processar -->
                    <button type="button" 
                            id="process-btn" 
                            class="btn btn-primary w-100 mt-3" 
                            disabled>
                        <i class="fas fa-magic"></i> Processar com OCR
                    </button>

                    <!-- Indicador de Processamento -->
                    <div id="processing-indicator" class="text-center mt-3" style="display: none;">
                        <div class="spinner-border text-primary spinner-border-sm" role="status">
                            <span class="visually-hidden">A processar...</span>
                        </div>
                        <p class="small text-muted mt-2 mb-0">A processar documento, isto pode levar alguns segundos.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Secção 2: Resultado do OCR -->
        <div class="col-lg-6 mb-4">
            <div class="card" id="result-card" style="display: none;">
                <div class="card-body">
                    <h4 class="card-title mb-3">2. Dados Extraídos</h4>
                    <div id="extracted-data">
                        <div class="alert alert-info" role="alert">
                            <small>Os dados serão exibidos aqui após o processamento</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alertas de Erro -->
            <div id="error-alert" class="alert alert-danger" role="alert" style="display: none;">
                <i class="fas fa-exclamation-circle"></i>
                <span id="error-message"></span>
            </div>
        </div>
    </div>

    <!-- Secção 3: Formulário de Confirmação -->
    <div class="row mt-4" id="form-section" style="display: none;">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                        <h4 class="card-title mb-0">3. Confirmar e Registar Entrada</h4>
                    </div>

                    <form id="goods-receipt-form" method="POST" action="{{ route('goodsReceipt.store', [], false) }}">
                        @csrf

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="po-number" class="form-label">Nº Encomenda:</label>
                                    <input type="text" id="po-number" class="form-control" readonly>
                                    <input type="hidden" id="purchaseOrderId" name="purchaseOrderId">
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="supplier-name" class="form-label">Fornecedor:</label>
                                    <input type="text" id="supplier-name" class="form-control" readonly>
                                    <input type="hidden" id="supplier-code" name="supplierCode">
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="gr-number" class="form-label">Nº Entrada:</label>
                                    <input type="number" id="gr-number" name="gRNumber" class="form-control" required>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="gr-date" class="form-label">Data de Entrada:</label>
                                    <input type="date" id="gr-date" name="gRDate" class="form-control" 
                                           value="{{ date('Y-m-d') }}" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="supplier-guide-number" class="form-label">Nº Guia do Fornecedor:</label>
                                    <input type="text" id="supplier-guide-number" name="supplierGuideNumber" 
                                           class="form-control" placeholder="Opcional">
                                </div>
                            </div>
                        </div>

                        <!-- Tabela de Linhas -->
                        <div class="table-responsive mb-4">
                            <table class="table table-bordered table-hover" id="lines-table">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 12%;">Artigo</th>
                                        <th style="width: 28%;">Descrição</th>
                                        <th style="width: 12%;">Encomendado</th>
                                        <th style="width: 12%;">Recebido</th>
                                        <th style="width: 12%;">Preço Unit.</th>
                                        <th style="width: 12%;">Total</th>
                                        <th style="width: 12%;">Taxa</th>
                                    </tr>
                                </thead>
                                <tbody id="lines-body">
                                </tbody>
                            </table>
                        </div>

                        <!-- Resumo de Totais -->
                        <div class="row mb-4">
                            <div class="col-md-6 ms-auto">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <div class="row mb-2">
                                            <div class="col-8">
                                                <strong>Valor Líquido:</strong>
                                            </div>
                                            <div class="col-4 text-end">
                                                <span id="total-net">0.00</span>€
                                            </div>
                                        </div>
                                        <div class="row mb-2">
                                            <div class="col-8">
                                                <strong>IVA:</strong>
                                            </div>
                                            <div class="col-4 text-end">
                                                <span id="total-tax">0.00</span>€
                                            </div>
                                        </div>
                                        <div class="row" style="border-top: 2px solid #dee2e6; padding-top: 10px;">
                                            <div class="col-8">
                                                <strong class="h5">TOTAL:</strong>
                                            </div>
                                            <div class="col-4 text-end">
                                                <strong class="h5"><span id="total-gross">0.00</span>€</strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Observações -->
                        <div class="mb-4">
                            <label for="observation" class="form-label">Observações:</label>
                            <textarea id="observation" name="gRObservation" class="form-control" 
                                      rows="3" placeholder="Notas sobre a entrada..."></textarea>
                        </div>

                        <!-- Botões de Ação -->
                        <div class="d-flex gap-2 justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check-circle"></i> Confirmar Entrada
                            </button>
                            <button type="button" class="btn btn-outline-secondary" onclick="location.reload()">
                                <i class="fas fa-times-circle"></i> Cancelar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    </div>
</div>

<!-- Script -->
<script>
    let currentFile = null;
    let currentStream = null;

    // Iniciar câmara ao carregar
    document.addEventListener('DOMContentLoaded', async function() {
        await startCamera();
        setupEventListeners();
    });

    async function startCamera() {
        try {
            document.getElementById('camera-loading').style.display = 'block';
            const constraints = {
                video: {
                    facingMode: 'environment',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                }
            };
            currentStream = await navigator.mediaDevices.getUserMedia(constraints);
            document.getElementById('camera-preview').srcObject = currentStream;
            document.getElementById('camera-loading').style.display = 'none';
        } catch (error) {
            console.warn('Câmara não disponível:', error);
            document.getElementById('camera-loading').innerHTML = 
                '<p class="small mt-2">Câmara não disponível. Use o carregamento de arquivo.</p>';
            document.getElementById('camera-section').style.display = 'none';
        }
    }

    function stopCamera() {
        if (currentStream) {
            currentStream.getTracks().forEach(track => track.stop());
            currentStream = null;
        }
    }

    function setupEventListeners() {
        document.getElementById('capture-btn').addEventListener('click', capturePhoto);
        document.getElementById('toggle-camera-btn').addEventListener('click', toggleCamera);
        document.getElementById('document-input').addEventListener('change', handleFileSelect);
        document.getElementById('process-btn').addEventListener('click', processOCR);
    }

    function toggleCamera() {
        const btn = document.getElementById('toggle-camera-btn');
        const video = document.getElementById('camera-preview');
        
        if (currentStream) {
            stopCamera();
            btn.innerHTML = '<i class="fas fa-video"></i> Abrir Câmara';
            video.srcObject = null;
        } else {
            btn.innerHTML = '<i class="fas fa-times"></i> Fechar Câmara';
            startCamera();
        }
    }

    function capturePhoto() {
        const video = document.getElementById('camera-preview');
        const canvas = document.getElementById('capture-canvas');
        const ctx = canvas.getContext('2d');

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        ctx.drawImage(video, 0, 0);

        canvas.toBlob(blob => {
            currentFile = new File([blob], 'captured.jpg', { type: 'image/jpeg' });
            document.getElementById('process-btn').disabled = false;
            alert('✓ Foto capturada com sucesso! Clique em "Processar com OCR".');
        }, 'image/jpeg', 0.95);
    }

    function handleFileSelect(event) {
        const file = event.target.files[0];
        if (file) {
            currentFile = file;
            document.getElementById('process-btn').disabled = false;
            console.log('Arquivo selecionado:', file.name);
        }
    }

    async function processOCR() {
        if (!currentFile) {
            alert('Por favor, tire uma foto ou selecione um arquivo.');
            return;
        }

        const formData = new FormData();
        formData.append('document', currentFile);

        try {
            document.getElementById('processing-indicator').style.display = 'block';
            document.getElementById('error-alert').style.display = 'none';

            const response = await fetch('{{ route("goodsReceipt.uploadDocument", [], false) }}', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('[name="_token"]').value
                }
            });

            // Log da resposta para debugging
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers.get('content-type'));

            // Verificar se é JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                document.getElementById('processing-indicator').style.display = 'none';
                const text = await response.text();
                console.error('Resposta não é JSON. Conteúdo:', text.substring(0, 500));
                showError('Erro do servidor: ' + (response.status === 401 ? 'Sessão expirada. Faça login novamente.' : 'Resposta inválida do servidor.'));
                return;
            }

            const result = await response.json();
            document.getElementById('processing-indicator').style.display = 'none';

            if (!result.success) {
                showError(result.error || 'Erro ao processar documento');
                return;
            }

            populateForm(result.data);
            document.getElementById('result-card').style.display = 'block';
            document.getElementById('form-section').style.display = 'block';

            // Scroll para a secção de confirmação
            document.getElementById('form-section').scrollIntoView({ behavior: 'smooth' });
        } catch (error) {
            document.getElementById('processing-indicator').style.display = 'none';
            console.error('Erro completo:', error);
            showError('Erro de conexão: ' + error.message);
        }
    }

    function showError(message) {
        document.getElementById('error-message').textContent = message;
        document.getElementById('error-alert').style.display = 'block';
        document.getElementById('result-card').style.display = 'none';
        document.getElementById('form-section').style.display = 'none';
    }

    function populateForm(data) {
        // Dados básicos
        document.getElementById('po-number').value = data.poNumber;
        document.getElementById('supplier-name').value = data.supplier;
        document.getElementById('supplier-code').value = data.supplierCode;
        document.getElementById('purchaseOrderId').value = data.purchaseOrderId;
        document.getElementById('gr-number').value = data.nextGRNumber;
        document.getElementById('supplier-guide-number').value = data.supplierGuideNumber || '';

        // Mostrar dados extraídos
        let extractedHTML = `
            <div class="alert alert-success">
                <strong>✓ Dados Extraídos com Sucesso!</strong>
            </div>
            <dl class="row small">
                <dt class="col-sm-5">Encomenda:</dt>
                <dd class="col-sm-7"><strong>${data.poNumber}</strong></dd>
                
                <dt class="col-sm-5">Fornecedor:</dt>
                <dd class="col-sm-7"><strong>${data.supplier}</strong></dd>

                <dt class="col-sm-5">Guia Fornecedor:</dt>
                <dd class="col-sm-7"><strong>${data.supplierGuideNumber || '-'}</strong></dd>
                
                <dt class="col-sm-5">Linhas encontradas:</dt>
                <dd class="col-sm-7"><strong>${data.lines.length}</strong></dd>
                
                <dt class="col-sm-5">Valor Total:</dt>
                <dd class="col-sm-7"><strong>${data.totalValue.toFixed(2)}€</strong></dd>
            </dl>
        `;
        document.getElementById('extracted-data').innerHTML = extractedHTML;

        // Preencher tabela de linhas
        const tbody = document.getElementById('lines-body');
        tbody.innerHTML = '';

        let totalNet = 0;
        let totalTax = 0;

        data.lines.forEach((line, index) => {
            const lineNet = line.quantityReceived * line.unitPrice;
            const lineTax = lineNet * 0.23; // IVA 23% (ajustar conforme necessário)
            
            totalNet += lineNet;
            totalTax += lineTax;

            const row = `
                <tr>
                    <td><code>${line.productCode}</code></td>
                    <td>${line.productName}</td>
                    <td class="text-center">${line.quantityOrdered.toLocaleString('pt-PT')}</td>
                    <td>
                        <input type="number" 
                               name="lines[${index}][receiveQuantity]" 
                               value="${line.quantityReceived}" 
                               class="form-control form-control-sm qty-input"
                               min="0"
                               step="0.01">
                        <input type="hidden" name="lines[${index}][purchaseOrderDId]" 
                               value="${line.purchaseOrderDId}">
                        <input type="hidden" name="lines[${index}][taxRateCode]" 
                               value="${line.taxRateCode}">
                    </td>
                    <td class="text-end">${line.unitPrice.toFixed(4)}€</td>
                    <td class="text-end line-total">${line.totalPrice.toFixed(2)}€</td>
                    <td class="text-center">${line.taxRateCode}</td>
                </tr>
            `;
            tbody.innerHTML += row;
        });

        // Atualizar totais
        document.getElementById('total-net').textContent = totalNet.toFixed(2);
        document.getElementById('total-tax').textContent = totalTax.toFixed(2);
        document.getElementById('total-gross').textContent = (totalNet + totalTax).toFixed(2);

        // Listener para atualizar totais quando mudar quantidades
        document.querySelectorAll('.qty-input').forEach(input => {
            input.addEventListener('change', updateLineTotal);
        });
    }

    function updateLineTotal(event) {
        // Recalcular totais se necessário
        const form = document.getElementById('goods-receipt-form');
        // Implementar lógica se necessário
    }
</script>

<style>
    #goodsReceiptOcrPage .card-title {
        font-size: 1rem;
        font-weight: 600;
    }

    .table th {
        background-color: #f8f9fa;
        font-weight: 600;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .camera-wrapper {
        background: #000;
        border-radius: 8px;
        overflow: hidden;
    }

    #camera-preview {
        border-radius: 8px;
        object-fit: cover;
    }

    .card {
        border-radius: 8px;
        padding: 1rem 1.5rem;
    }

    .form-control, .form-control:focus {
        border-radius: 6px;
        border: 1px solid #dee2e6;
    }

    #result-card dl dt,
    #result-card dl dd {
        margin-bottom: 0.35rem;
    }

    .btn-sm {
        font-size: 0.875rem;
    }

    .spinner-border-sm {
        width: 1.5rem;
        height: 1.5rem;
    }
</style>
@endsection
