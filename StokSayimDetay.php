<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

// DocumentEntry parametresi gerekli
$documentEntry = isset($_GET['DocumentEntry']) ? intval($_GET['DocumentEntry']) : null;

if (empty($documentEntry)) {
    header("Location: Stok.php");
    exit;
}

// InventoryCounting belgesini çek
$countingQuery = "InventoryCountings({$documentEntry})?\$expand=InventoryCountingLines";
$countingData = $sap->get($countingQuery);

if (($countingData['status'] ?? 0) != 200) {
    die("Sayım belgesi bulunamadı veya erişilemedi.");
}

$counting = $countingData['response'] ?? $countingData;
$lines = $counting['InventoryCountingLines'] ?? [];
$documentStatus = $counting['DocumentStatus'] ?? '';
$isClosed = ($documentStatus === 'bost_Close');

// Status mapping
function getStatusText($status) {
    $statusMap = [
        'bost_Open' => 'Açık',
        'bost_Close' => 'Kapalı'
    ];
    return $statusMap[$status] ?? 'Bilinmiyor';
}

function getStatusClass($status) {
    $classMap = [
        'bost_Open' => 'status-open',
        'bost_Close' => 'status-closed'
    ];
    return $classMap[$status] ?? 'status-unknown';
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// PATCH: Sayım satırlarını güncelle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    header('Content-Type: application/json');
    
    if ($isClosed) {
        echo json_encode(['success' => false, 'message' => 'Kapalı sayım güncellenemez']);
        exit;
    }
    
    $lines = isset($_POST['lines']) ? json_decode($_POST['lines'], true) : [];
    
    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'En az bir kalem gereklidir']);
        exit;
    }
    
    $payload = [
        'InventoryCountingLines' => []
    ];
    
    foreach ($lines as $line) {
        if (!isset($line['LineNum'])) {
            continue;
        }
        
        $lineData = [
            'LineNum' => intval($line['LineNum']),
            'CountedQuantity' => floatval($line['CountedQuantity'] ?? 0)
        ];
        
        $payload['InventoryCountingLines'][] = $lineData;
    }
    
    $result = $sap->patch("InventoryCountings({$documentEntry})", $payload);
    
    if (($result['status'] ?? 0) == 200 || ($result['status'] ?? 0) == 204) {
        echo json_encode(['success' => true, 'message' => 'Sayım güncellendi']);
    } else {
        $error = $result['response']['error']['message']['value'] ?? $result['response']['error']['message'] ?? 'Bilinmeyen hata';
        echo json_encode(['success' => false, 'message' => 'Sayım güncellenemedi: ' . $error]);
    }
    exit;
}

// POST: Sayımı onayla (InventoryPostings oluştur)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm') {
    header('Content-Type: application/json');
    
    if ($isClosed) {
        echo json_encode(['success' => false, 'message' => 'Sayım zaten kapalı']);
        exit;
    }
    
    $lines = isset($_POST['lines']) ? json_decode($_POST['lines'], true) : [];
    
    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'En az bir kalem gereklidir']);
        exit;
    }
    
    // Fark hesaplama: CountedQuantity - SystemQuantity (eğer varsa)
    // Şimdilik sadece CountedQuantity kullanıyoruz, fark hesaplaması opsiyonel
    $postingLines = [];
    
    foreach ($lines as $line) {
        $countedQty = floatval($line['CountedQuantity'] ?? 0);
        $systemQty = floatval($line['SystemQuantity'] ?? 0);
        $difference = $countedQty - $systemQty;
        
        // Fark 0 değilse InventoryPostingLines'e ekle
        if (abs($difference) > 0.0001) {
            $postingLine = [
                'ItemCode' => $line['ItemCode'] ?? '',
                'WarehouseCode' => $line['WarehouseCode'] ?? '',
                'Quantity' => $difference,
                'BaseEntry' => $documentEntry,
                'BaseLine' => intval($line['LineNum'] ?? 0)
            ];
            
            $postingLines[] = $postingLine;
        }
    }
    
    if (empty($postingLines)) {
        echo json_encode(['success' => false, 'message' => 'Fark bulunamadı. Tüm miktarlar sistem miktarıyla eşleşiyor.']);
        exit;
    }
    
    // InventoryPostings oluştur
    $postingPayload = [
        'Remarks' => 'Sayım farkı bağlı belge',
        'InventoryPostingLines' => $postingLines
    ];
    
    $postingResult = $sap->post('InventoryPostings', $postingPayload);
    
    if (($postingResult['status'] ?? 0) == 200 || ($postingResult['status'] ?? 0) == 201) {
        // Sayımı kapat (opsiyonel - SAP genelde otomatik kapatır)
        $closePayload = [
            'DocumentStatus' => 'bost_Close'
        ];
        $closeResult = $sap->patch("InventoryCountings({$documentEntry})", $closePayload);
        
        echo json_encode(['success' => true, 'message' => 'Sayım onaylandı ve fark belgesi oluşturuldu']);
    } else {
        $error = $postingResult['response']['error']['message']['value'] ?? $postingResult['response']['error']['message'] ?? 'Bilinmeyen hata';
        echo json_encode(['success' => false, 'message' => 'Sayım onaylanamadı: ' . $error]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sayım Detay - MINOA</title>
    <link rel="stylesheet" href="styles.css">
    <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: #f5f7fa;
    color: #111827;
}

.main-content {
    width: 100%;
    background: whitesmoke;
    padding: 0;
    min-height: 100vh;
}

.page-header {
    background: white;
    padding: 20px 2rem;
    border-radius: 0 0 0 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 0;
    position: sticky;
    top: 0;
    z-index: 100;
    height: 80px;
    box-sizing: border-box;
}

.page-header h2 {
    color: #1e40af;
    font-size: 1.75rem;
    font-weight: 600;
    margin: 0;
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible;
}

.card-header {
    padding: 20px 24px 0 24px;
}

.card-header h3 {
    color: #1e40af;
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 0;
}

.card-body {
    padding: 16px 24px 24px 24px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.info-label {
    font-size: 12px;
    color: #6b7280;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    font-size: 15px;
    color: #111827;
    font-weight: 600;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-open {
    background: #d1fae5;
    color: #065f46;
}

.status-closed {
    background: #f3f4f6;
    color: #6b7280;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.data-table thead {
    background: #f8fafc;
}

.data-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    color: #4b5563;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.data-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.15s;
}

.data-table tbody tr:hover {
    background: #f9fafb;
}

.data-table td {
    padding: 12px 16px;
    color: #374151;
}

.input-small {
    padding: 6px 10px;
    border: 2px solid #e5e7eb;
    border-radius: 4px;
    font-size: 13px;
    width: 100px;
    transition: all 0.2s;
}

.input-small:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.input-small:read-only {
    background: #f3f4f6;
    cursor: not-allowed;
    color: #374151;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.btn-primary:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    transform: translateY(-1px);
}

.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.btn-success:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    transform: translateY(-1px);
}

.btn-success:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #f0f9ff;
}

.action-buttons {
    display: flex;
    gap: 12px;
    margin-top: 24px;
    justify-content: flex-end;
}

.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
    display: none;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #10b981;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #ef4444;
}

@media (max-width: 768px) {
    .page-header {
        padding: 16px 1rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        height: auto;
    }

    .content-wrapper {
        padding: 16px;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Sayım Detay</h2>
            <div style="display: flex; gap: 12px;">
                <button class="btn btn-secondary" onclick="window.location.href='Stok.php'">← Geri Dön</button>
                <?php if (!$isClosed): ?>
                <button class="btn btn-secondary" onclick="window.location.href='StokSayimSO.php?DocumentEntry=<?= $documentEntry ?>&continue=1'">Güncelle (Devam Et)</button>
                <?php endif; ?>
            </div>
        </header>

        <div class="content-wrapper">
            <div id="alertMessage" class="alert"></div>

            <!-- Üst Bilgi Kartı -->
            <section class="card">
                <div class="card-header">
                    <h3>Üst Bilgiler</h3>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Doküman No</span>
                            <span class="info-value"><?= htmlspecialchars($counting['DocumentEntry'] ?? '') ?></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Depo</span>
                            <span class="info-value"><?= htmlspecialchars($counting['WarehouseCode'] ?? '') ?></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Sayım Tarihi</span>
                            <span class="info-value"><?= formatDate($counting['CountDate'] ?? '') ?></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Açıklama</span>
                            <span class="info-value"><?= htmlspecialchars($counting['Remarks'] ?? '') ?></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Durum</span>
                            <span class="info-value">
                                <span class="status-badge <?= getStatusClass($documentStatus) ?>">
                                    <?= getStatusText($documentStatus) ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Satır Tablosu -->
            <section class="card">
                <div class="card-header">
                    <h3>Sayım Satırları</h3>
                </div>
                <div class="card-body">
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>LineNum</th>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>Depo</th>
                                    <th>Birim</th>
                                    <th>Sayılan Miktar</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="linesTableBody">
                                <?php if (empty($lines)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #6b7280;">
                                        Sayım satırı bulunmamaktadır.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($lines as $line): ?>
                                <tr data-line-num="<?= $line['LineNum'] ?? '' ?>">
                                    <td><?= htmlspecialchars($line['LineNum'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['ItemCode'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['ItemDescription'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['WarehouseCode'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['UoMCode'] ?? '') ?></td>
                                    <td>
                                        <input type="number" 
                                               class="input-small" 
                                               value="<?= htmlspecialchars($line['CountedQuantity'] ?? 0) ?>" 
                                               step="0.01" 
                                               min="0" 
                                               data-line-num="<?= $line['LineNum'] ?? '' ?>"
                                               data-item-code="<?= htmlspecialchars($line['ItemCode'] ?? '') ?>"
                                               data-warehouse-code="<?= htmlspecialchars($line['WarehouseCode'] ?? '') ?>"
                                               data-system-quantity="<?= htmlspecialchars($line['SystemQuantity'] ?? 0) ?>"
                                               <?= $isClosed ? 'readonly' : '' ?>>
                                    </td>
                                    <td>
                                        <span style="color: #9ca3af; font-size: 12px;">Değişiklik</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Butonlar -->
            <?php if (!$isClosed): ?>
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="updateCounting()">Güncelle</button>
                <button class="btn btn-success" onclick="confirmCounting()">Sayımı Onayla</button>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
const documentEntry = <?= $documentEntry ?>;
const isClosed = <?= $isClosed ? 'true' : 'false' ?>;

function showAlert(message, type) {
    const alert = document.getElementById('alertMessage');
    alert.textContent = message;
    alert.className = 'alert alert-' + type;
    alert.style.display = 'block';
    
    setTimeout(() => {
        alert.style.display = 'none';
    }, 5000);
    
    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateCounting() {
    const lines = [];
    const inputs = document.querySelectorAll('#linesTableBody input[type="number"]');
    
    inputs.forEach(input => {
        const lineNum = input.getAttribute('data-line-num');
        const countedQty = parseFloat(input.value) || 0;
        
        if (lineNum) {
            lines.push({
                LineNum: parseInt(lineNum),
                CountedQuantity: countedQty
            });
        }
    });
    
    if (lines.length === 0) {
        alert('Güncellenecek satır bulunamadı');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update');
    formData.append('lines', JSON.stringify(lines));
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert('Sayım güncellendi', 'success');
        } else {
            showAlert('Hata: ' + data.message, 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showAlert('Bir hata oluştu', 'error');
    });
}

function confirmCounting() {
    if (!confirm('Sayımı onaylamak istediğinizden emin misiniz? Fark belgesi oluşturulacak ve sayım kapatılacak.')) {
        return;
    }
    
    const lines = [];
    const inputs = document.querySelectorAll('#linesTableBody input[type="number"]');
    
    inputs.forEach(input => {
        const lineNum = input.getAttribute('data-line-num');
        const itemCode = input.getAttribute('data-item-code');
        const warehouseCode = input.getAttribute('data-warehouse-code');
        const systemQty = parseFloat(input.getAttribute('data-system-quantity')) || 0;
        const countedQty = parseFloat(input.value) || 0;
        
        if (lineNum && itemCode && warehouseCode) {
            lines.push({
                LineNum: parseInt(lineNum),
                ItemCode: itemCode,
                WarehouseCode: warehouseCode,
                CountedQuantity: countedQty,
                SystemQuantity: systemQty
            });
        }
    });
    
    if (lines.length === 0) {
        alert('Onaylanacak satır bulunamadı');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'confirm');
    formData.append('lines', JSON.stringify(lines));
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert('Sayım onaylandı ve fark belgesi oluşturuldu', 'success');
            setTimeout(() => {
                window.location.href = 'Stok.php';
            }, 2000);
        } else {
            showAlert('Hata: ' + data.message, 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showAlert('Bir hata oluştu', 'error');
    });
}
    </script>
</body>
</html>

