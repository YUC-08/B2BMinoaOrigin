<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

// Session'dan bilgileri al
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch = $_SESSION["Branch2"]["Code"] ?? $_SESSION["WhsCode"] ?? $_SESSION["Branch2"]["Name"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// DocEntry parametresi gerekli
$docEntry = isset($_GET['DocEntry']) ? intval($_GET['DocEntry']) : null;

if (empty($docEntry)) {
    header("Location: Sevkiyat.php");
    exit;
}

// InventoryTransferRequest belgesini çek
$transferQuery = "InventoryTransferRequests({$docEntry})";
$transferData = $sap->get($transferQuery);

if (($transferData['status'] ?? 0) != 200) {
    die("Sevkiyat belgesi bulunamadı veya erişilemedi.");
}

$transfer = $transferData['response'] ?? $transferData;

// Kullanıcının şubesine ait olmayan belgelerin detayına erişimi engelle
$fromWarehouse = $transfer['FromWarehouse'] ?? '';
$toWarehouse = $transfer['ToWarehouse'] ?? '';
$transferBranch = $transfer['U_ASB2B_BRAN'] ?? '';
$transferOwnr = $transfer['U_AS_OWNR'] ?? '';

// Kullanıcının şubesine ait depoları bul
$userWarehouses = [];
$warehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and (U_ASB2B_MAIN eq '1' or U_ASB2B_MAIN eq '2')";
$warehouseQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($warehouseFilter);
$warehouseData = $sap->get($warehouseQuery);

if (($warehouseData['status'] ?? 0) == 200) {
    $warehouses = $warehouseData['response']['value'] ?? $warehouseData['value'] ?? [];
    foreach ($warehouses as $whs) {
        $whsCode = $whs['WarehouseCode'] ?? '';
        if (!empty($whsCode)) {
            $userWarehouses[] = $whsCode;
        }
    }
}

// Erişim kontrolü: FromWarehouse veya ToWarehouse kullanıcının depolarından biri olmalı
// VEYA U_ASB2B_BRAN ve U_AS_OWNR eşleşmeli
$hasAccess = false;
$isSender = false; // Gönderen şube mi?
$isReceiver = false; // Alan şube mi?

if (!empty($userWarehouses)) {
    $isSender = in_array($fromWarehouse, $userWarehouses);
    $isReceiver = in_array($toWarehouse, $userWarehouses);
    $hasAccess = $isSender || $isReceiver;
}
// Fallback: Branch ve Owner kontrolü
if (!$hasAccess) {
    $hasAccess = ($transferBranch == $branch && $transferOwnr == $uAsOwnr);
    // Fallback'te de gönderen/alan kontrolü yap
    if ($hasAccess) {
        // ToWarehouse'un branch'ini kontrol et
        $toWarehouseParts = explode('-', $toWarehouse);
        $toWarehouseBranch = $toWarehouseParts[0] ?? '';
        $isReceiver = ($toWarehouseBranch == $branch);
        
        // FromWarehouse'un branch'ini kontrol et
        $fromWarehouseParts = explode('-', $fromWarehouse);
        $fromWarehouseBranch = $fromWarehouseParts[0] ?? '';
        $isSender = ($fromWarehouseBranch == $branch);
    }
}

if (!$hasAccess) {
    die("Bu sevkiyat belgesine erişim yetkiniz yok.");
}

// Statü bilgisi
$status = $transfer['U_ASB2B_STATUS'] ?? '';

// Satırları çek - InventoryTransferRequestLines collection'ından
$lines = [];

// Önce header'dan InventoryTransferRequestLines'ı kontrol et
if (isset($transfer['InventoryTransferRequestLines']) && is_array($transfer['InventoryTransferRequestLines'])) {
    $lines = $transfer['InventoryTransferRequestLines'];
}

// Eğer hala boşsa, direkt collection path'i dene
if (empty($lines)) {
    $linesQuery = "InventoryTransferRequests({$docEntry})/InventoryTransferRequestLines";
    $linesData = $sap->get($linesQuery);
    
    if (($linesData['status'] ?? 0) == 200) {
        $linesResponse = $linesData['response'] ?? $linesData;
        
        if (isset($linesResponse['value']) && is_array($linesResponse['value'])) {
            $lines = $linesResponse['value'];
        } elseif (isset($linesResponse['InventoryTransferRequestLines']) && is_array($linesResponse['InventoryTransferRequestLines'])) {
            $lines = $linesResponse['InventoryTransferRequestLines'];
        } elseif (is_array($linesResponse) && !isset($linesResponse['error']) && !isset($linesResponse['@odata.context'])) {
            $lines = $linesResponse;
        }
    }
}

// Eğer hala boşsa, StockTransferLines ile dene (fallback - bazı durumlarda bu kullanılabilir)
if (empty($lines)) {
    $stockTransferLinesQuery = "InventoryTransferRequests({$docEntry})/StockTransferLines";
    $stockTransferLinesData = $sap->get($stockTransferLinesQuery);
    
    if (($stockTransferLinesData['status'] ?? 0) == 200) {
        $stockTransferLinesResponse = $stockTransferLinesData['response'] ?? $stockTransferLinesData;
        
        if (isset($stockTransferLinesResponse['value']) && is_array($stockTransferLinesResponse['value'])) {
            $lines = $stockTransferLinesResponse['value'];
        } elseif (isset($stockTransferLinesResponse['StockTransferLines']) && is_array($stockTransferLinesResponse['StockTransferLines'])) {
            $lines = $stockTransferLinesResponse['StockTransferLines'];
        } elseif (is_array($stockTransferLinesResponse) && !isset($stockTransferLinesResponse['error']) && !isset($stockTransferLinesResponse['@odata.context'])) {
            $lines = $stockTransferLinesResponse;
        }
    }
}

// Durum mapping
function getStatusText($status) {
    $statusMap = [
        '1' => 'Onay bekleniyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal edildi'
    ];
    return $statusMap[$status] ?? '-';
}

function getStatusClass($status) {
    $classMap = [
        '1' => 'status-warning',
        '2' => 'status-info',
        '3' => 'status-primary',
        '4' => 'status-success',
        '5' => 'status-danger'
    ];
    return $classMap[$status] ?? '';
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// Sayı formatlama
function formatNumber($num) {
    if (empty($num) && $num !== 0) return '-';
    return number_format((float)$num, 2, ',', '.');
}

// Toplam hesapla
$grandTotal = 0;
foreach ($lines as $line) {
    $quantity = floatval($line['Quantity'] ?? 0);
    $unitPrice = floatval($line['UnitPrice'] ?? 0);
    $grandTotal += $quantity * $unitPrice;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sevkiyat Detay - MINOA</title>
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
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }

        .card-header {
            padding: 0;
            margin-bottom: 1rem;
        }

        .card-header h3 {
            color: #1e40af;
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }

        .card-body {
            padding: 0;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .detail-label {
            font-size: 12px;
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

        .status-badge {
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .status-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-primary {
            background: #bfdbfe;
            color: #1e3a8a;
        }

        .status-success {
            background: #d1fae5;
            color: #065f46;
        }

        .status-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
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

        .data-table tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.15s ease;
        }

        .data-table tbody tr:hover {
            background: #f8fafc;
        }

        .data-table td {
            padding: 16px 20px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
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

        .empty-message {
            text-align: center;
            padding: 3rem;
            color: #9ca3af;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    <main class="main-content">
        <header class="page-header">
            <h2>Sevkiyat Detay</h2>
            <button class="btn btn-secondary" onclick="window.location.href='Sevkiyat.php'">← Geri Dön</button>
        </header>

        <div class="content-wrapper">
            <!-- Üst Bilgiler -->
            <section class="card">
                <div class="card-header">
                    <h3>Üst Bilgiler</h3>
                </div>
                <div class="card-body">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Belge No</span>
                            <span class="detail-value"><?= htmlspecialchars($transfer['DocEntry'] ?? '-') ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Gönderen Depo</span>
                            <span class="detail-value"><?= htmlspecialchars($transfer['FromWarehouse'] ?? '-') ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Alan Depo</span>
                            <span class="detail-value"><?= htmlspecialchars($transfer['ToWarehouse'] ?? '-') ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Talep Tarihi</span>
                            <span class="detail-value"><?= formatDate($transfer['DocDate'] ?? '') ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Sevk / Planlanan Tarih</span>
                            <span class="detail-value"><?= formatDate($transfer['DueDate'] ?? '') ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Referans / Belge No</span>
                            <span class="detail-value"><?= htmlspecialchars($transfer['U_ASB2B_NumAtCard'] ?? '-') ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Durum</span>
                            <span class="detail-value">
                                <span class="status-badge <?= getStatusClass($transfer['U_ASB2B_STATUS'] ?? '') ?>">
                                    <?= getStatusText($transfer['U_ASB2B_STATUS'] ?? '') ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Satır Listesi -->
            <section class="card">
                <div class="card-header">
                    <h3>Satır Listesi</h3>
                </div>
                <div class="card-body">
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Satır No</th>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>Birim</th>
                                </tr>
                            </thead>
                            <tbody id="linesTableBody">
                                <?php if (empty($lines)): ?>
                                <tr>
                                    <td colspan="4" class="empty-message">Satır bulunamadı</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($lines as $line): 
                                    $lineNum = $line['LineNum'] ?? $line['LineNumber'] ?? '';
                                    $itemCode = $line['ItemCode'] ?? '';
                                    $itemName = $line['ItemDescription'] ?? $line['ItemName'] ?? '';
                                    $uomCode = $line['UoMCode'] ?? '';
                                    $quantity = floatval($line['Quantity'] ?? 0);
                                    $formattedQty = formatNumber($quantity);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($lineNum) ?></td>
                                    <td><strong><?= htmlspecialchars($itemCode) ?></strong></td>
                                    <td><?= htmlspecialchars($itemName) ?></td>
                                    <td><?= htmlspecialchars($formattedQty) ?> <?= htmlspecialchars($uomCode) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Butonlar -->
            <div class="action-buttons">
                <button class="btn btn-secondary" onclick="window.location.href='Sevkiyat.php'">Geri Dön</button>
                <button class="btn btn-primary" onclick="window.print()">Yazdır</button>
            </div>
        </div>
    </main>

    <script>
        // Sayfa yüklendiğinde scroll pozisyonunu sıfırla
        document.addEventListener('DOMContentLoaded', function() {
            window.scrollTo(0, 0);
        });
    </script>
</body>
</html>

