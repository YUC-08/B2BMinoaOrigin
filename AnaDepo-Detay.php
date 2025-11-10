<?php
session_start();
if (!isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// URL'den doc parametresi al
$doc = $_GET['doc'] ?? '';

if (empty($doc)) {
    header("Location: AnaDepo.php");
    exit;
}

// InventoryTransferRequests({doc}) çağır
$docQuery = "InventoryTransferRequests({$doc})";
$docData = $sap->get($docQuery);
$requestData = $docData['response'] ?? null;

if (!$requestData) {
    echo "Belge bulunamadı!";
    exit;
}

// Status mapping
function getStatusText($status) {
    $statusMap = [
        '1' => 'Onay Bekliyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk Edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal Edildi'
    ];
    return $statusMap[$status] ?? 'Bilinmiyor';
}

function getStatusClass($status) {
    $classMap = [
        '1' => 'status-pending',
        '2' => 'status-processing',
        '3' => 'status-shipped',
        '4' => 'status-completed',
        '5' => 'status-cancelled'
    ];
    return $classMap[$status] ?? 'status-unknown';
}

function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

$docEntry = $requestData['DocEntry'] ?? '';
$docDate = formatDate($requestData['DocDate'] ?? '');
$dueDate = formatDate($requestData['DueDate'] ?? '');
$status = $requestData['U_ASB2B_STATUS'] ?? '1';
$statusText = getStatusText($status);
$numAtCard = $requestData['U_ASB2B_NumAtCard'] ?? '-';
$ordSum = $requestData['U_ASB2B_ORDSUM'] ?? '-';
$branchCode = $requestData['U_ASB2B_BRAN'] ?? '-';
$journalMemo = $requestData['JournalMemo'] ?? '-';
$fromWarehouse = $requestData['FromWarehouse'] ?? '';
$toWarehouse = $requestData['ToWarehouse'] ?? '';
$aliciSube = $requestData['U_ASWHST'] ?? '-'; // Alıcı Şube
$lines = $requestData['StockTransferLines'] ?? [];

// TEST: Durumu Onay Bekliyor'a döndür (GEÇİCİ - SONRA KALDIRILACAK)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_status'])) {
    $resetPayload = [
        'U_ASB2B_STATUS' => '1' // Onay Bekliyor
    ];
    $resetResult = $sap->patch("InventoryTransferRequests({$doc})", $resetPayload);
    
    if ($resetResult['status'] == 200 || $resetResult['status'] == 204) {
        // Başarılı, sayfayı yenile
        header("Location: AnaDepo-Detay.php?doc={$doc}");
        exit;
    } else {
        error_log("[TEST RESET] Status reset başarısız: " . ($resetResult['status'] ?? 'NO STATUS'));
    }
}

// Sevk / Teslim miktarları için haritalama: ItemCode => Toplam Miktar (StockTransfers'tan gelen)
$stockTransferLinesMap = [];
$stockTransferInfo 	 = null;

// Sadece Sevk Edildi (3) ve Tamamlandı (4) durumlarında bağlı StockTransfer belgesini çekerek
// Teslimat Miktarını bu belgelerdeki Quantity'den hesaplamak için ilk StockTransfer belgesini çek
if ($status === '3' || $status === '4') {
    // İlk bağlı StockTransfer belgesini BaseEntry ile çek
    // BaseType = 1250000001  => InventoryTransferRequest
    $stockTransferFilter = "BaseType eq 1250000001 and BaseEntry eq {$docEntry}";
    // En son (veya ilk) StockTransfer belgesini çek (Birden fazla transfer varsa ilkini alıyoruz)
    $stockTransferQuery 	= "StockTransfers?\$filter=" . urlencode($stockTransferFilter) . "&\$expand=StockTransferLines&\$orderby=DocEntry desc&\$top=1";
    $stockTransferData = $sap->get($stockTransferQuery);
    $stockTransfers 	 = $stockTransferData['response']['value'] ?? [];
    
    if (!empty($stockTransfers)) {
        $stockTransferInfo = $stockTransfers[0];
        
        // StockTransfer satırlarındaki Quantity'leri topla (sadece ilk belge)
        $stLines = $stockTransferInfo['StockTransferLines'] ?? [];
        foreach ($stLines as $stLine) {
            $itemCode = $stLine['ItemCode'] ?? '';
            $qty = (float)($stLine['Quantity'] ?? 0);
            // Bu map'i tek bir StockTransfer belgesindeki miktarları tutmak için kullanıyoruz
            $stockTransferLinesMap[$itemCode] = $qty; 
        }
    }
    
    // DEBUG Bilgisi - StockTransfer bulundu mu?
    if (empty($stockTransfers)) {
        error_log("DEBUG: DocEntry {$docEntry} için StockTransfer bulunamadı. Filtre: {$stockTransferFilter}");
    } else {
        error_log("DEBUG: DocEntry {$docEntry} için StockTransfer DocEntry: {$stockTransferInfo['DocEntry']}");
    }
}


?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ana Depo Sipariş Detayı - CREMMAVERSE</title>
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
    color: #2c3e50;
    line-height: 1.6;
}

/* Main content now full width with top padding for fixed navbar */
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
    flex-wrap: wrap;
    gap: 12px;
    height: 80px;
    box-sizing: border-box;
}

.page-header h2 {
    color: #1e40af;
    font-size: 1.75rem;
    font-weight: 600;
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}

.detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e5e7eb;
}

.detail-title h3 {
    font-size: 1.5rem;
    color: #2c3e50;
    font-weight: 400;
}

.detail-title h3 strong {
    font-weight: 600;
    color: #3b82f6;
}

.detail-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    padding: 24px;
    margin-bottom: 24px;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.detail-item label {
    font-size: 13px;
    color: #1e3a8a;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    font-size: 15px;
    color: #2c3e50;
    font-weight: 500;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    color: #1e3a8a;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e5e7eb;
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

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-processing {
    background: #dbeafe;
    color: #1e40af;
}

.status-shipped {
    background: #bfdbfe;
    color: #1e3a8a;
}

.status-completed {
    background: #d1fae5;
    color: #065f46;
}

.status-cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.status-unknown {
    background: #f3f4f6;
    color: #6b7280;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: hidden;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
}

.data-table th {
    padding: 16px 20px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    color: #1e3a8a;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    color: #374151;
}

.data-table tbody tr {
    transition: background 0.15s ease;
}

.data-table tbody tr:hover {
    background: #f8fafc;
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

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #f0f9ff;
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

.btn-warning {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

.btn-warning:hover {
    background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
    transform: translateY(-1px);
}

@media (max-width: 768px) {
    .content-wrapper {
        padding: 16px 20px;
    }
    
    .page-header {
        padding: 16px 20px;
        flex-direction: column;
        align-items: flex-start;
    }
    
    .detail-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .detail-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Ana Depo Sipariş Detayı</h2> 

            <div>
                <?php if ($status == '1' || $status == '2'): ?> 
                <button class="btn btn-primary"
                        onclick="window.location.href='anadepo_hazirla.php?doc=<?= $docEntry ?>'"
                        style="margin-right: 10px;">
                    📦 Hazırla
                </button>
                <?php endif; ?>
                
                <?php if ($status == '3'): ?>
                    <button class="btn btn-success"
                            onclick="window.location.href='anadepo_teslim_al.php?doc=<?= $docEntry ?>'"
                            style="margin-right: 10px;">
                        ✓ Teslim Al
                    </button>
                <?php endif; ?>

                <?php if ($status == '3' || $status == '4'): ?>
                    <form method="POST"
                          action="AnaDepo-Detay.php?doc=<?= $docEntry ?>"
                          style="display: inline-block; margin-right: 10px;">
                        <input type="hidden" name="reset_status" value="1">
                        <button type="submit" class="btn btn-warning"
                                onclick="return confirm('Durumu Onay Bekliyor olarak sıfırlamak istediğinize emin misiniz? (Test amaçlı)');">
                            🔄 Onay Bekliyor'a Döndür (Test)
                        </button>
                    </form>
                <?php endif; ?>

                <button class="btn btn-secondary" onclick="window.location.href='AnaDepo.php'">
                    ← Geri Dön
                </button>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="detail-header">
                <div class="detail-title">
                    <h3>Ana Depo Siparişi: <strong><?= htmlspecialchars($docEntry) ?></strong></h3>
                </div>
            </div>

            <div class="detail-card">
                <div class="detail-grid">
                    <div class="detail-item">
                        <label>Sipariş No:</label>
                        <div class="detail-value"><?= htmlspecialchars($docEntry) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Sipariş Tarihi:</label>
                        <div class="detail-value"><?= htmlspecialchars($docDate) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Sipariş Özeti:</label>
                        <div class="detail-value"><?= htmlspecialchars($ordSum) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Şube Kodu:</label>
                        <div class="detail-value"><?= htmlspecialchars($branchCode) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Tahmini Teslimat Tarihi:</label>
                        <div class="detail-value"><?= htmlspecialchars($dueDate) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Sipariş Durumu:</label>
                        <div class="detail-value">
                            <span class="status-badge <?= getStatusClass($status) ?>"><?= htmlspecialchars($statusText) ?></span>
                        </div>
                    </div>
                    <div class="detail-item">
                        <label>Teslimat Belge No:</label>
                        <div class="detail-value"><?= htmlspecialchars($numAtCard) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Alıcı Şube:</label>
                        <div class="detail-value"><?= htmlspecialchars($aliciSube) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Sipariş Notu:</label>
                        <div class="detail-value"><?= htmlspecialchars($journalMemo) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Gönderen Depo:</label>
                        <div class="detail-value"><?= htmlspecialchars($fromWarehouse) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Alıcı Depo (Hedef):</label>
                        <div class="detail-value"><?= htmlspecialchars($toWarehouse) ?></div>
                    </div>
                </div>
            </div>

            <?php if ($stockTransferInfo): ?>
                <div class="section-title">Sevk Bilgileri (SAP StockTransfers Tablosu)</div>
                <div class="detail-card">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <label>StockTransfer DocEntry:</label>
                            <div class="detail-value"><?= htmlspecialchars($stockTransferInfo['DocEntry'] ?? '-') ?></div>
                        </div>
                        <div class="detail-item">
                            <label>StockTransfer DocNum:</label>
                            <div class="detail-value"><?= htmlspecialchars($stockTransferInfo['DocNum'] ?? '-') ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Sevk Tarihi:</label>
                            <div class="detail-value"><?= formatDate($stockTransferInfo['DocDate'] ?? '') ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Gönderen Depo (Sevk):</label>
                            <div class="detail-value"><?= htmlspecialchars($stockTransferInfo['FromWarehouse'] ?? '-') ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Gittiği Depo (Sevk):</label>
                            <div class="detail-value"><strong><?= htmlspecialchars($stockTransferInfo['ToWarehouse'] ?? '-') ?></strong></div>
                        </div>
                        <div class="detail-item">
                            <label>Durum:</label>
                            <div class="detail-value">
                                <?php
                                $stStatus = $stockTransferInfo['DocumentStatus'] ?? '';
                                $stStatusText = $stStatus == 'bost_Closed' ? 'Kapalı (Sevk Edildi)' : ($stStatus == 'bost_Open' ? 'Açık' : $stStatus);
                                ?>
                                <?= htmlspecialchars($stStatusText) ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="section-title">Sipariş Kalemleri</div>

            <div class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kalem Numarası</th>
                            <th>Kalem Tanımı</th>
                            <th>Talep Miktarı</th>
                            <th>Teslimat Miktarı</th>
                            <th>Birim</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($lines)): ?>
                            <?php foreach ($lines as $line): ?>
                                <?php 
                                    $quantity = (float)($line['Quantity'] ?? 0);
                                    $remaining = (float)($line['RemainingOpenQuantity'] ?? 0);
                                    $itemCode = $line['ItemCode'] ?? '';
                                    $delivered = 0; // Default 0
                                    
                                    // Teslimat Miktarı hesaplama mantığı:
                                    if ($status === '1' || $status === '2' || $status === '5') {
                                        // Onay Bekliyor, Hazırlanıyor, İptal Edildi: Teslimat Miktarı 0
                                        $delivered = 0;
                                    } else if ($status === '4') {
                                        // *** DÜZELTME: Tamamlandı durumunda StockTransfer bağlantısı hatalıysa Talep Miktarını göster *** 
                                        // 1. Kontrol: StockTransfers'tan gelen miktarı kullan (daha güvenilir)
                                        $delivered = $stockTransferLinesMap[$itemCode] ?? 0;
                                        
                                        // 2. Kontrol (Tamamlandı için Güçlendirilmiş Geri Dönüş): StockTransfer bulunamazsa ve Tamamlandıysa, Talep Miktarını göster.
                                        if ($delivered == 0 && empty($stockTransferInfo) && $quantity > 0) {
                                            $delivered = $quantity; 
                                        } else if ($delivered == 0 && $quantity > 0 && $remaining < $quantity) {
                                            // StockTransfer yok ama açık kalan miktar < Talep Miktarı (Kısmi veya tam kapanmış olabilir)
                                            $delivered = $quantity - $remaining;
                                        }
                                    } else if ($status === '3') {
                                        // Sevk Edildi: StockTransfers'tan gelen miktarı kullan
                                        $delivered = $stockTransferLinesMap[$itemCode] ?? 0;
                                        
                                        // Geri Dönüş: StockTransfer yok ama kısmi kapandıysa
                                        if ($delivered == 0 && $quantity > 0 && $remaining < $quantity) {
                                            $delivered = $quantity - $remaining;
                                        }
                                    } 
                                    
                                    // Formatlama
                                    $deliveredDisplay = number_format($delivered, 2, '.', '');
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($itemCode) ?></td>
                                    <td><?= htmlspecialchars($line['ItemDescription'] ?? '-') ?></td>
                                    <td><?= number_format($quantity, 2, '.', '') ?></td>
                                    <td><?= $deliveredDisplay ?></td>
                                    <td><?= htmlspecialchars($line['UoMCode'] ?? '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" style="text-align:center;color:#888;">Kalem bulunamadı.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script src="script.js"></script>
</body>
</html>
