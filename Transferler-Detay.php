<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// URL'den parametreler
$docEntry = $_GET['docEntry'] ?? '';
$type = $_GET['type'] ?? 'incoming'; // incoming veya outgoing

if (empty($docEntry)) {
    header("Location: Transferler.php");
    exit;
}

// InventoryTransferRequests({docEntry}) çağır
$docQuery = "InventoryTransferRequests({$docEntry})";
$docData = $sap->get($docQuery);
$requestData = $docData['response'] ?? null;

if (!$requestData) {
    echo "Belge bulunamadı!";
    exit;
}

$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch = $_SESSION["WhsCode"] ?? $_SESSION["Branch2"]["Name"] ?? '';

// Miktar formatı: 10.00 → 10, 10.5 → 10,5, 10.25 → 10,25
function formatQuantity($qty) {
    $num = floatval($qty);
    if ($num == 0) return '0';
    // Tam sayı ise küsurat gösterme
    if ($num == floor($num)) {
        return (string)intval($num);
    }
    // Küsurat varsa virgül ile göster
    return str_replace('.', ',', rtrim(rtrim(sprintf('%.2f', $num), '0'), ','));
}

// Status mapping
function getStatusText($status) {
    $statusMap = [
        '0' => 'Onay Bekliyor',
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
        '0' => 'status-pending',
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

function isReceivableStatus($status) {
    $s = trim((string)$status);
    return in_array($s, ['2', '3'], true);
}

function isApprovalStatus($status) {
    $s = trim((string)$status);
    return in_array($s, ['0', '1'], true);
}

$docDate = formatDate($requestData['DocDate'] ?? '');
$dueDate = formatDate($requestData['DueDate'] ?? '');
$status = $requestData['U_ASB2B_STATUS'] ?? '0';
$statusText = getStatusText($status);
$statusClass = getStatusClass($status);
$numAtCard = $requestData['U_ASB2B_NumAtCard'] ?? '-';
$comments = $requestData['Comments'] ?? '-';
$fromWarehouse = $requestData['FromWarehouse'] ?? '';
$toWarehouse = $requestData['ToWarehouse'] ?? '';
$lines = $requestData['StockTransferLines'] ?? [];

// Warehouse isimlerini çek
$fromWarehouseName = '';
$toWarehouseName = '';
if (!empty($fromWarehouse)) {
    $fromWhsQuery = "Warehouses('{$fromWarehouse}')?\$select=WarehouseCode,WarehouseName";
    $fromWhsData = $sap->get($fromWhsQuery);
    $fromWarehouseName = $fromWhsData['response']['WarehouseName'] ?? '';
}
if (!empty($toWarehouse)) {
    $toWhsQuery = "Warehouses('{$toWarehouse}')?\$select=WarehouseCode,WarehouseName";
    $toWhsData = $sap->get($toWhsQuery);
    $toWarehouseName = $toWhsData['response']['WarehouseName'] ?? '';
}

// Gönderen Şube formatı: 100-KT-0 / Şube Adı
$gonderSubeDisplay = $fromWarehouse;
if (!empty($fromWarehouseName)) {
    $gonderSubeDisplay = $fromWarehouse . ' / ' . $fromWarehouseName;
} elseif (empty($fromWarehouse)) {
    $gonderSubeDisplay = '-';
}

// Alıcı Şube formatı: 100-KT-1 / Şube Adı
$aliciSubeDisplay = $toWarehouse;
if (!empty($toWarehouseName)) {
    $aliciSubeDisplay = $toWarehouse . ' / ' . $toWarehouseName;
} elseif (empty($toWarehouse)) {
    $aliciSubeDisplay = '-';
}

// StockTransfer bilgileri (Sevk Edildi/Tamamlandı durumunda)
$stockTransferInfo = null;
$stockTransferLinesMap = [];

if ($status === '3' || $status === '4') {
    $stockTransferFilter = "BaseType eq 1250000001 and BaseEntry eq {$docEntry}";
    $stockTransferQuery = "StockTransfers?\$filter=" . urlencode($stockTransferFilter) . "&\$expand=StockTransferLines&\$orderby=DocEntry desc&\$top=1";
    $stockTransferData = $sap->get($stockTransferQuery);
    $stockTransfers = $stockTransferData['response']['value'] ?? [];
    
    if (!empty($stockTransfers)) {
        $stockTransferInfo = $stockTransfers[0];
        $stLines = $stockTransferInfo['StockTransferLines'] ?? [];
        foreach ($stLines as $stLine) {
            $itemCode = $stLine['ItemCode'] ?? '';
            $qty = (float)($stLine['Quantity'] ?? 0);
            $stockTransferLinesMap[$itemCode] = $qty;
        }
    }
}

$canReceive = isReceivableStatus($status);
$canApprove = isApprovalStatus($status);
$showNewRequestButton = ($type === 'incoming'); // Sadece gelen transferler için
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Detay - MINOA</title>
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
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 24px;
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
    gap: 2rem;
}

.detail-column {
    display: flex;
    flex-direction: column;
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

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    table-layout: fixed;
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
    width: 25%;
}

.data-table th:nth-child(3) {
    text-align: center;
}

.data-table td {
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    color: #374151;
    width: 25%;
}

.data-table td:nth-child(3) {
    text-align: center;
}

.data-table tbody tr {
    transition: background 0.15s ease;
}

.data-table tbody tr:hover {
    background: #f8fafc;
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
    background: #e0e7ff;
    color: #4338ca;
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

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #f0f9ff;
}

.btn-receive {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.btn-receive:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    transform: translateY(-1px);
}

.btn-approve {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

.btn-approve:hover {
    background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
    transform: translateY(-1px);
}

.header-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Transfer Detay</h2>
            <div class="header-actions">
                <?php if ($showNewRequestButton): ?>
                    <a href="TransferlerSO.php">
                        <button class="btn btn-primary">+ Yeni Talep Oluştur</button>
                    </a>
                <?php endif; ?>
                <?php if ($canReceive && $type === 'incoming'): ?>
                    <a href="Transferler-TeslimAl.php?docEntry=<?= urlencode($docEntry) ?>">
                        <button class="btn btn-primary">✓ Teslim Al</button>
                    </a>
                <?php endif; ?>
                <?php if ($canApprove && $type === 'outgoing'): ?>
                    <a href="Transferler-Onayla.php?docEntry=<?= urlencode($docEntry) ?>&action=approve">
                        <button class="btn btn-approve">✓ Onayla</button>
                    </a>
                    <a href="Transferler-Onayla.php?docEntry=<?= urlencode($docEntry) ?>&action=reject">
                        <button class="btn" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">✗ İptal</button>
                    </a>
                <?php endif; ?>
                <button class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=<?= $type ?>'">← Geri Dön</button>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="detail-header">
                <div class="detail-title">
                    <h3>Transfer Talebi: <strong><?= htmlspecialchars($docEntry) ?></strong></h3>
                </div>
            </div>

            <div class="detail-card">
                <div class="detail-grid">
                    <!-- Sol Sütun -->
                    <div class="detail-column">
                        <div class="detail-item">
                            <label>Transfer No:</label>
                            <div class="detail-value"><?= htmlspecialchars($docEntry) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Talep Tarihi:</label>
                            <div class="detail-value"><?= htmlspecialchars($docDate) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Vade Tarihi:</label>
                            <div class="detail-value"><?= htmlspecialchars($dueDate) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Teslimat Belge No:</label>
                            <div class="detail-value"><?= htmlspecialchars($numAtCard) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Not:</label>
                            <div class="detail-value"><?= !empty($comments) && $comments !== '-' ? htmlspecialchars($comments) : 'Stok nakil talebi' ?></div>
                        </div>
                    </div>
                    
                    <!-- Sağ Sütun -->
                    <div class="detail-column">
                        <div class="detail-item">
                            <label>Kaynak Depo:</label>
                            <div class="detail-value"><?= htmlspecialchars($gonderSubeDisplay) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Hedef Depo:</label>
                            <div class="detail-value"><?= htmlspecialchars($aliciSubeDisplay) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Durum:</label>
                            <div class="detail-value">
                                <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                            </div>
                        </div>
                        <?php if ($stockTransferInfo): ?>
                            <div class="detail-item">
                                <label>Sevk Tarihi:</label>
                                <div class="detail-value"><?= formatDate($stockTransferInfo['DocDate'] ?? '') ?></div>
                            </div>
                            <div class="detail-item">
                                <label>StockTransfer DocEntry:</label>
                                <div class="detail-value"><?= htmlspecialchars($stockTransferInfo['DocEntry'] ?? '-') ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <section class="card">
                <div class="section-title">Transfer Detayı</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kalem Numarası</th>
                            <th>Kalem Tanımı</th>
                            <th>Talep Miktarı</th>
                            <th>Teslimat Miktarı</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lines)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 2rem; color: #9ca3af;">Satır bulunamadı.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($lines as $line): 
                                $itemCode = $line['ItemCode'] ?? '';
                                $itemName = $line['ItemDescription'] ?? '';
                                $quantity = (float)($line['Quantity'] ?? 0);
                                $uomCode = $line['UoMCode'] ?? 'AD';
                                
                                // Teslimat miktarı
                                $delivered = 0;
                                if ($status === '3' || $status === '4') {
                                    $delivered = $stockTransferLinesMap[$itemCode] ?? 0;
                                }
                                
                                // Miktar formatı
                                $quantityFormatted = formatQuantity($quantity);
                                $deliveredFormatted = formatQuantity($delivered);
                                
                                $quantityDisplay = $quantity > 0 ? $quantityFormatted . ' ' . htmlspecialchars($uomCode) : '0';
                                $deliveredDisplay = $delivered > 0 ? $deliveredFormatted . ' ' . htmlspecialchars($uomCode) : '0';
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($itemCode) ?></td>
                                    <td><?= htmlspecialchars($itemName) ?></td>
                                    <td><?= $quantityDisplay ?></td>
                                    <td><?= $deliveredDisplay ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>
</body>
</html>
