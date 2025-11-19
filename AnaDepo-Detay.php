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




// InventoryTransferRequests({doc}) çağır - Tüm alanları çek (DocumentStatus dahil)
// $select ile sadece gerekli alanları çekmek yerine tüm alanları çekiyoruz
$docQuery = "InventoryTransferRequests({$doc})";
// Cache'i önlemek için her zaman fresh data çek
$docData = $sap->get($docQuery);

$requestData = $docData['response'] ?? null;

if (!$requestData) {
    echo "Belge bulunamadı!";
    if (isset($docData['response']['error'])) {
        echo "<br>Hata: " . json_encode($docData['response']['error']);
    }
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

$docEntry = $requestData['DocEntry'] ?? '';
$docDate = formatDate($requestData['DocDate'] ?? '');
$dueDate = formatDate($requestData['DueDate'] ?? '');
// Status'u string'e çevir (SAP'den integer gelebilir)
// Hem U_ASB2B_STATUS hem de DocumentStatus'u kontrol et
$udfStatus = (string)($requestData['U_ASB2B_STATUS'] ?? '1');
$documentStatus = $requestData['DocumentStatus'] ?? null;

// Debug: Tüm status bilgilerini logla
error_log("[ANADEPO-DETAY] DocEntry: {$docEntry}");
error_log("[ANADEPO-DETAY] U_ASB2B_STATUS (raw): " . ($requestData['U_ASB2B_STATUS'] ?? 'NULL'));
error_log("[ANADEPO-DETAY] DocumentStatus: " . ($documentStatus ?? 'NULL'));

// Status belirleme: Önce U_ASB2B_STATUS'u kullan, ama DocumentStatus'a göre de kontrol et
$status = $udfStatus;
$statusUpdated = false;

// DocumentStatus'a göre status senkronizasyonu ve UDF güncelleme
// Eğer DocumentStatus kapalıysa ama U_ASB2B_STATUS hala açık durumdaysa, SAP'de UDF'yi güncelle
if ($documentStatus == 'bost_Closed' && in_array($udfStatus, ['1', '2'])) {
    // DocumentStatus kapalı ama UDF hala açık durumda - SAP'de UDF'yi güncelle
    $updatePayload = ['U_ASB2B_STATUS' => '3']; // Sevk Edildi
    $updateResult = $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);
    
    if (($updateResult['status'] ?? 0) == 200 || ($updateResult['status'] ?? 0) == 204) {
        $status = '3';
        $statusUpdated = true;
        error_log("[ANADEPO-DETAY] DocumentStatus kapalı - U_ASB2B_STATUS '3' (Sevk Edildi) olarak güncellendi");
    } else {
        error_log("[ANADEPO-DETAY] UDF güncelleme başarısız: HTTP " . ($updateResult['status'] ?? 'NO STATUS'));
        if (isset($updateResult['response']['error'])) {
            error_log("[ANADEPO-DETAY] UDF güncelleme hatası: " . json_encode($updateResult['response']['error']));
        }
        // Hata olsa bile status'u güncelle (kullanıcıya doğru bilgi göster)
        $status = '3';
    }
} elseif ($documentStatus == 'bost_Open' && in_array($udfStatus, ['3', '4'])) {
    // DocumentStatus açık ama UDF kapalı durumda - UDF'yi öncelikli kabul et (değişiklik yapılmış olabilir)
    $status = $udfStatus;
    error_log("[ANADEPO-DETAY] DocumentStatus açık ama UDF kapalı - UDF öncelikli, status={$status}");
}

$statusText = getStatusText($status);
error_log("[ANADEPO-DETAY] Final Status: {$status} ({$statusText})");

// Eğer status güncellendiyse, sayfayı yeniden yükle (fresh data için)
if ($statusUpdated) {
    // Status güncellendi, sayfayı yeniden yükle
    header("Location: AnaDepo-Detay.php?doc={$docEntry}");
    exit;
}
$numAtCard = $requestData['U_ASB2B_NumAtCard'] ?? '-';
$ordSum = $requestData['U_ASB2B_ORDSUM'] ?? '-';
$branchCode = $requestData['U_ASB2B_BRAN'] ?? '-';
$journalMemo = $requestData['JournalMemo'] ?? '-';
$fromWarehouse = $requestData['FromWarehouse'] ?? '';
$toWarehouse = $requestData['ToWarehouse'] ?? '';
$aliciSube = $requestData['U_ASWHST'] ?? '-'; // Alıcı Şube
$gonderSube = $requestData['U_ASWHSF'] ?? ''; // Gönderen Şube adı
$lines = $requestData['StockTransferLines'] ?? [];

// Depo bilgilerini çek (WarehouseName için)
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

// Gönderen Şube formatı: KT-00 / Beşiktaş Kitapevi Ana Depo
$gonderSubeDisplay = $fromWarehouse;
if (!empty($gonderSube)) {
    $gonderSubeDisplay = $fromWarehouse . ' / ' . $gonderSube;
} elseif (!empty($fromWarehouseName)) {
    $gonderSubeDisplay = $fromWarehouse . ' / ' . $fromWarehouseName;
}

// Alıcı Şube formatı: 200-KT-1 / Kadıköy Rıhtım Depo
$aliciSubeDisplay = $toWarehouse;
if (!empty($toWarehouseName)) {
    $aliciSubeDisplay = $toWarehouse . ' / ' . $toWarehouseName;
}

// Teslimat Tarihi (StockTransfer varsa onun DocDate'i, yoksa boş)
$teslimatTarihi = '';

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
$stockTransferLinesMap = []; // Ana deponun sevk ettiği miktar
$deliveryTransferLinesMap = []; // Kullanıcının teslim aldığı miktar
$stockTransferInfo 	 = null;
$deliveryTransferInfo = null;

// Sadece Sevk Edildi (3) ve Tamamlandı (4) durumlarında bağlı StockTransfer belgelerini çek
if ($status == '3' || $status == '4') {
    // 1. Ana deponun sevk ettiği belge (BaseType = 1250000001 => InventoryTransferRequest)
    $stockTransferFilter = "BaseType eq 1250000001 and BaseEntry eq {$docEntry}";
    $stockTransferQuery 	= "StockTransfers?\$filter=" . urlencode($stockTransferFilter) . "&\$expand=StockTransferLines&\$orderby=DocEntry desc&\$top=1";
    $stockTransferData = $sap->get($stockTransferQuery);
    $stockTransfers 	 = $stockTransferData['response']['value'] ?? [];
    
    if (!empty($stockTransfers)) {
        $stockTransferInfo = $stockTransfers[0];
        
        // Teslimat Tarihi: StockTransfer'in DocDate'i
        $teslimatTarihi = formatDate($stockTransferInfo['DocDate'] ?? '');
        
        // StockTransfer satırlarındaki Quantity'leri topla (sevk miktarı)
        $stLines = $stockTransferInfo['StockTransferLines'] ?? [];
        foreach ($stLines as $stLine) {
            $itemCode = $stLine['ItemCode'] ?? '';
            $qty = (float)($stLine['Quantity'] ?? 0);
            $stockTransferLinesMap[$itemCode] = $qty; 
        }
    }
    
    // 2. Kullanıcının teslim aldığı belge (FromWarehouse = ToWarehouse, ToWarehouse = Şube ana deposu, U_ASB2B_TYPE = 'MAIN', U_ASB2B_STATUS = '4')
    // Önce InventoryTransferRequest'ten ToWarehouse'u al
    $toWarehouse = $requestData['ToWarehouse'] ?? '';
    $uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
    $branch = $_SESSION["WhsCode"] ?? $_SESSION["Branch2"]["Name"] ?? '';
    $deliveryTransfers = [];
    
    if (!empty($toWarehouse) && !empty($uAsOwnr) && !empty($branch)) {
        // Şubenin ana deposunu bul (U_ASB2B_MAIN=1)
        $targetWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '1'";
        $targetWarehouseQuery = "Warehouses?\$filter=" . urlencode($targetWarehouseFilter);
        $targetWarehouseData = $sap->get($targetWarehouseQuery);
        $targetWarehouses = $targetWarehouseData['response']['value'] ?? [];
        $targetWarehouse = !empty($targetWarehouses) ? $targetWarehouses[0]['WarehouseCode'] : null;
        
        if (!empty($targetWarehouse)) {
            // Kullanıcının teslim aldığı StockTransfer belgesini bul
            // Önce InventoryTransferRequest'in Comments'inden DELIVERY_DocEntry'yi oku
            $deliveryDocEntry = null;
            $requestComments = $requestData['Comments'] ?? '';
            
            if (preg_match('/DELIVERY_DocEntry:(\d+)/', $requestComments, $matches)) {
                $deliveryDocEntry = intval($matches[1]);
            }
            
            if ($deliveryDocEntry) {
                // DocEntry ile doğrudan belgeyi bul (en güvenilir yöntem)
                $deliveryTransferQuery = "StockTransfers({$deliveryDocEntry})?\$expand=StockTransferLines";
                $deliveryTransferData = $sap->get($deliveryTransferQuery);
                $deliveryTransferInfo = $deliveryTransferData['response'] ?? null;
                
                if ($deliveryTransferInfo) {
                    $deliveryTransfers = [$deliveryTransferInfo];
                }
            } else {
                // Fallback: Warehouse ve type ile filtrele (eski yöntem)
                $deliveryTransferFilter = "FromWarehouse eq '{$toWarehouse}' and ToWarehouse eq '{$targetWarehouse}' and U_ASB2B_TYPE eq 'MAIN'";
                $deliveryTransferQuery = "StockTransfers?\$filter=" . urlencode($deliveryTransferFilter) . "&\$expand=StockTransferLines&\$orderby=DocEntry desc&\$top=1";
                $deliveryTransferData = $sap->get($deliveryTransferQuery);
                $deliveryTransfers = $deliveryTransferData['response']['value'] ?? [];
            }
            
            if (!empty($deliveryTransfers)) {
                $deliveryTransferInfo = $deliveryTransfers[0];
                
                // Teslim alınan miktarları map'e ekle
                $dtLines = $deliveryTransferInfo['StockTransferLines'] ?? [];
                foreach ($dtLines as $dtLine) {
                    $itemCode = $dtLine['ItemCode'] ?? '';
                    $qty = (float)($dtLine['Quantity'] ?? 0);
                    // Eğer aynı item için birden fazla satır varsa, topla
                    if (isset($deliveryTransferLinesMap[$itemCode])) {
                        $deliveryTransferLinesMap[$itemCode] += $qty;
                    } else {
                        $deliveryTransferLinesMap[$itemCode] = $qty;
                    }
                }
            }
        }
    }
    
    // DEBUG Bilgisi
    if (empty($stockTransfers)) {
        error_log("DEBUG: DocEntry {$docEntry} için sevk StockTransfer bulunamadı. Filtre: {$stockTransferFilter}");
    } else {
        error_log("DEBUG: DocEntry {$docEntry} için sevk StockTransfer DocEntry: {$stockTransferInfo['DocEntry']}");
    }
    
    if (empty($deliveryTransfers)) {
        error_log("DEBUG: DocEntry {$docEntry} için teslimat StockTransfer bulunamadı. ToWarehouse: {$toWarehouse}, TargetWarehouse: " . ($targetWarehouse ?? 'NULL'));
    } else {
        $deliveryTransferDocEntry = $deliveryTransferInfo['DocEntry'] ?? 'UNKNOWN';
        error_log("DEBUG: DocEntry {$docEntry} için teslimat StockTransfer DocEntry: {$deliveryTransferDocEntry}, Lines count: " . count($deliveryTransferInfo['StockTransferLines'] ?? []));
        error_log("DEBUG: Teslimat miktarları: " . json_encode($deliveryTransferLinesMap));
    }
    
}


?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Ana Depo Talep Detayı - CREMMAVERSE</title>
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
            <h2>Ana Depo Talep Detayı</h2> 

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
                    <h3>Ana Depo Talebi: <strong><?= htmlspecialchars($docEntry) ?></strong></h3>
                </div>
            </div>

            <div class="detail-card">
                <div class="detail-grid">
                    <!-- Sol Sütun -->
                    <div class="detail-column">
                        <div class="detail-item">
                            <label>Talep No:</label>
                            <div class="detail-value"><?= htmlspecialchars($docEntry) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Tahmini Teslimat Tarihi:</label>
                            <div class="detail-value"><?= htmlspecialchars($dueDate) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Teslimat Belge No:</label>
                            <div class="detail-value"><?= htmlspecialchars($numAtCard) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Talep Özeti:</label>
                            <div class="detail-value"><?= htmlspecialchars($ordSum) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Talep Notu:</label>
                            <div class="detail-value"><?= htmlspecialchars($journalMemo) ?></div>
                        </div>
                    </div>
                    
                    <!-- Sağ Sütun -->
                    <div class="detail-column">
                        <div class="detail-item">
                            <label>Talep Durumu:</label>
                            <div class="detail-value">
                                <span class="status-badge <?= getStatusClass($status) ?>"><?= htmlspecialchars($statusText) ?></span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <label>Gönderen Şube:</label>
                            <div class="detail-value"><?= htmlspecialchars($gonderSubeDisplay) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Alıcı Şube:</label>
                            <div class="detail-value"><?= htmlspecialchars($aliciSubeDisplay) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Talep Tarihi:</label>
                            <div class="detail-value"><?= htmlspecialchars($docDate) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Teslimat Tarihi:</label>
                            <div class="detail-value"><?= htmlspecialchars($teslimatTarihi ?: '-') ?></div>
                        </div>
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
                            <?php if ($status == '3' || $status == '4'): ?>
                                <th>Sevk Miktarı</th>
                                <th>Teslimat Miktarı</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($lines)): ?>
                            <?php foreach ($lines as $line): ?>
                                <?php 
                                    $quantity = (float)($line['Quantity'] ?? 0);
                                    $remaining = (float)($line['RemainingOpenQuantity'] ?? 0);
                                    $itemCode = $line['ItemCode'] ?? '';
                                    $uomCode = $line['UoMCode'] ?? 'AD';
                                    $shipped = 0; // Sevk Miktarı
                                    $delivered = 0; // Teslimat Miktarı
                                    
                                    // Sevk ve Teslimat Miktarı hesaplama mantığı: Sadece Sevk Edildi (3) ve Tamamlandı (4) durumunda
                                    if ($status == '3' || $status == '4') {
                                        // Sevk Miktarı: Ana deponun sevk ettiği miktar (StockTransfer belgesinden)
                                        $shipped = $stockTransferLinesMap[$itemCode] ?? 0;
                                        
                                        // Eğer StockTransfer'den miktar gelmediyse, RemainingOpenQuantity'ye göre hesapla
                                        if ($shipped == 0 && $quantity > 0) {
                                            // RemainingOpenQuantity < Quantity ise, sevk edilen miktar = Quantity - RemainingOpenQuantity
                                            if ($remaining < $quantity) {
                                                $shipped = $quantity - $remaining;
                                            } else {
                                                // RemainingOpenQuantity = Quantity ise, henüz sevk edilmemiş demektir
                                                // Ama status "Sevk Edildi" ise, talep miktarını göster (ana depo göndermiş sayılır)
                                                if ($status == '3') {
                                                    $shipped = $quantity;
                                                }
                                            }
                                            
                                            // Tamamlandı durumunda: Eğer hala 0 ise ve StockTransfer yoksa, talep miktarını göster
                                            if ($shipped == 0 && $status == '4' && empty($stockTransferInfo) && $quantity > 0) {
                                                $shipped = $quantity;
                                            }
                                        }
                                        
                                        // Teslimat Miktarı: Kullanıcının gerçekten teslim aldığı miktar (Teslim Al işleminden oluşan StockTransfer belgesinden)
                                        $delivered = $deliveryTransferLinesMap[$itemCode] ?? 0;
                                        
                                        // Eğer teslimat belgesi yoksa, teslimat miktarı 0'dır (henüz teslim alınmamış)
                                        // Kullanıcı "Teslim Al" işlemini yapmadıysa, teslimat miktarı gösterilmez (0 olarak kalır)
                                    }
                                    
                                    // Talep Miktarı formatı: "1 AD" (0 ise sadece "0")
                                    $quantityDisplay = formatQuantity($quantity);
                                    if ($quantity > 0) {
                                        $quantityDisplay .= ' ' . htmlspecialchars($uomCode);
                                    }
                                    
                                    // Sevk Miktarı formatı: "1 AD" (0 ise sadece "0")
                                    $shippedDisplay = '';
                                    if ($status == '3' || $status == '4') {
                                        $shippedFormatted = formatQuantity($shipped);
                                        if ($shipped > 0) {
                                            $shippedDisplay = $shippedFormatted . ' ' . htmlspecialchars($uomCode);
                                        } else {
                                            $shippedDisplay = '0';
                                        }
                                    }
                                    
                                    // Teslimat Miktarı formatı: "1 AD" (0 ise sadece "0")
                                    $deliveredDisplay = '';
                                    if ($status == '3' || $status == '4') {
                                        $deliveredFormatted = formatQuantity($delivered);
                                        if ($delivered > 0) {
                                            $deliveredDisplay = $deliveredFormatted . ' ' . htmlspecialchars($uomCode);
                                        } else {
                                            $deliveredDisplay = '0';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($itemCode) ?></td>
                                    <td><?= htmlspecialchars($line['ItemDescription'] ?? '-') ?></td>
                                    <td><?= $quantityDisplay ?></td>
                                    <?php if ($status == '3' || $status == '4'): ?>
                                        <td><?= $shippedDisplay ?></td>
                                        <td><?= $deliveredDisplay ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="<?= ($status == '3' || $status == '4') ? '5' : '3' ?>" style="text-align:center;color:#888;">Kalem bulunamadı.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script src="script.js"></script>
    <script>
    // Sayfayı otomatik yenile (30 saniyede bir) - SAP'deki status değişikliklerini görmek için
    let autoRefreshInterval = setInterval(function() {
        // Sayfa görünür durumdaysa yenile
        if (!document.hidden) {
            // Sadece GET parametrelerini koruyarak yenile (POST işlemi yapmadan)
            if (window.location.search.indexOf('refresh=') === -1) {
                window.location.href = window.location.pathname + window.location.search + (window.location.search ? '&' : '?') + 'refresh=' + Date.now();
            } else {
                // Zaten refresh parametresi varsa, sadece timestamp'i güncelle
                const url = new URL(window.location);
                url.searchParams.set('refresh', Date.now());
                window.location.href = url.toString();
            }
        }
    }, 30000); // 30 saniye
    
    // Sayfa görünür olduğunda da kontrol et
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            // Sayfa tekrar görünür olduğunda yenile
            const url = new URL(window.location);
            url.searchParams.set('refresh', Date.now());
            window.location.href = url.toString();
        }
    });
    </script>
</body>
</html>
