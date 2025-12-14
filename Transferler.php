<?php
session_start();

if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// Session'dan bilgileri al
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';   // KT / MS / YE...
$branch  = $_SESSION["WhsCode"] ?? $_SESSION["Branch2"]["Name"] ?? ''; // 100 / 200 / 300

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

$userName = $_SESSION["UserName"] ?? '';

// ====================================================================================
// 1. ADIM: KULLANICININ GERÇEK DEPO KODUNU BUL (AnaDepo.php Mantığı)
// ====================================================================================
// Kullanıcının şubesine (Branch) ve Sektörüne (U_AS_OWNR) ait depoyu buluyoruz.
// AnaDepo.php'deki 'toWarehouse' mantığının aynısıdır.
// U_ASB2B_MAIN eq '2' -> Bu filtre projenize göre değişebilir, AnaDepo.php referans alındı.

$myWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '2'";
$myWarehouseQuery = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=" . urlencode($myWarehouseFilter);
$myWarehouseData = $sap->get($myWarehouseQuery);
$myWarehouses = $myWarehouseData['response']['value'] ?? [];

// Kullanıcının işlem yapacağı depo kodu:
$currentUserWhsCode = !empty($myWarehouses) ? $myWarehouses[0]['WarehouseCode'] : null;
$currentUserWhsName = !empty($myWarehouses) ? ($myWarehouses[0]['WarehouseName'] ?? '') : '';

// Eğer depo bulunamazsa hata mesajı veya fallback
$warningMsg = '';
if (empty($currentUserWhsCode)) {
    // Eğer MAIN='2' ile bulunamazsa, sadece Branch ve Owner ile ilk bulduğunu getirmeyi deneyebiliriz
    // Veya varsayılan olarak branch prefix'ini kullanabiliriz (eski yöntem)
    $warningMsg = "Dikkat: {$branch} şubesi ve {$uAsOwnr} sektörü için tanımlı depo (MAIN=2) bulunamadı. Filtreleme çalışmayabilir.";
    // Fallback: Eski yöntem (ama riskli)
    $currentUserWhsCode = $branch; 
}
// ====================================================================================


// View type belirleme (incoming veya outgoing)
$viewType = $_GET['view'] ?? 'incoming';

// Filtre parametreleri
$filterStatus = $_GET['status'] ?? '';
$filterStartDate = $_GET['start_date'] ?? '';
$filterEndDate = $_GET['end_date'] ?? '';

// Durum metinleri
function getStatusText($status) {
    $statusMap = [
        '0' => 'Onay Bekliyor',
        '1' => 'Onay Bekliyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk Edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal Edildi'
    ];
    return $statusMap[$status] ?? 'Bilinmeyen';
}

function getStatusClass($status) {
    $statusMap = [
        '0' => 'status-pending',
        '1' => 'status-pending',
        '2' => 'status-processing',
        '3' => 'status-shipped',
        '4' => 'status-completed',
        '5' => 'status-cancelled'
    ];
    return $statusMap[$status] ?? 'status-unknown';
}

// -------------------------------------------------------------------------
// 2. ADIM: GELEN TRANSFERLER (INCOMING)
// -------------------------------------------------------------------------
$incomingTransfers = [];
$incomingDebugInfo = [
    'branch' => $branch,
    'uAsOwnr' => $uAsOwnr,
    'foundWarehouse' => $currentUserWhsCode // Debug için bulduğumuz depo
];

if ($viewType === 'incoming') {
    $search = $_GET['search'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $itemsPerPage = isset($_GET['entries']) ? (int)$_GET['entries'] : 25;
    
    // FİLTRE DÜZELTİLDİ: substringof yerine net eşleşme
    // Bu depoya (User'ın deposuna) gelen transferler. 
    $incomingFilter = "U_AS_OWNR eq '{$uAsOwnr}' and WhsCode eq '{$currentUserWhsCode}'";
    
    // Status filtresi
    if (!empty($filterStatus)) {
        $incomingFilter .= " and U_ASB2B_STATUS eq '{$filterStatus}'";
    }
    // Tarih filtreleri
    if (!empty($filterStartDate)) {
        $startDateFormatted = date('Y-m-d', strtotime($filterStartDate));
        $incomingFilter .= " and DocDate ge '{$startDateFormatted}'";
    }
    if (!empty($filterEndDate)) {
        $endDateFormatted = date('Y-m-d', strtotime($filterEndDate));
        $incomingFilter .= " and DocDate le '{$endDateFormatted}'";
    }
    
    // Arama Mantığı (Search)
    $searchFields = ['U_ASB2B_NumAtCard', 'FromWhsCode', 'WhsCode', 'ItemCode', 'Dscription'];
    $search = trim($search);

    if ($search !== '') {
        if (ctype_digit($search)) {
            $incomingFilter .= " and DocEntry eq {$search}";
        } else {
            $searchEsc = str_replace("'", "''", $search);
            $parts = [];
            foreach ($searchFields as $field) {
                $parts[] = "substringof('{$searchEsc}', {$field})";
            }
            $incomingFilter .= " and (" . implode(' or ', $parts) . ")";
        }
    }
    
    // Sorguyu Çalıştır
    $incomingQuery = "view.svc/ASB2B_TransferRequestList_B1SLQuery?" . 
                     "\$filter=" . urlencode($incomingFilter) . 
                     "&\$orderby=" . urlencode("DocEntry desc");
    
    $incomingData = $sap->get($incomingQuery);
    $incomingTransfersRaw = $incomingData['response']['value'] ?? [];
    
    // Sayfalama işlemleri (Orijinal kodunuzdaki gibi)
    usort($incomingTransfersRaw, function($a, $b) {
        $docEntryA = $a['DocEntry'] ?? 0;
        $docEntryB = $b['DocEntry'] ?? 0;
        if ($docEntryB != $docEntryA) return $docEntryB - $docEntryA;
        return ($a['LineNum'] ?? 0) - ($b['LineNum'] ?? 0);
    });
    
    $totalItems = count($incomingTransfersRaw);
    $totalPages = ceil($totalItems / $itemsPerPage);
    $skip = ($page - 1) * $itemsPerPage;
    $incomingTransfers = array_slice($incomingTransfersRaw, $skip, $itemsPerPage);
    
    $incomingPagination = [
        'current_page' => $page,
        'items_per_page' => $itemsPerPage,
        'total_items' => $totalItems,
        'total_pages' => $totalPages
    ];

    // Debug güncelleme
    $incomingDebugInfo['incomingFilter'] = $incomingFilter;
    $incomingDebugInfo['rawCount'] = count($incomingTransfersRaw);
} else {
    // Pagination placeholder
    $incomingPagination = ['current_page' => 1, 'items_per_page' => 25, 'total_items' => 0, 'total_pages' => 0];
}

// -------------------------------------------------------------------------
// 3. ADIM: GİDEN TRANSFERLER (OUTGOING)
// -------------------------------------------------------------------------
$outgoingTransfers = [];
$debugInfo = [
    'branch' => $branch,
    'uAsOwnr' => $uAsOwnr,
    'foundWarehouse' => $currentUserWhsCode // Debug için
];

if ($viewType === 'outgoing') {
    $search = $_GET['search'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $itemsPerPage = isset($_GET['entries']) ? (int)$_GET['entries'] : 25;
    
    
    // 1. Depo kodunun kökünü al (Örn: '150-KT-1' ise '150-KT' kısmını alıyoruz)

   $warehousePrefix = substr($currentUserWhsCode, 0, strrpos($currentUserWhsCode, '-'));
   
   // 2. Filtreyi 'startswith' kullanarak güncelliyoruz.
   // Böylece hem 150-KT-0 hem de 150-KT-1 deposundan çıkanları görebilir.
    $outgoingFilter = "U_AS_OWNR eq '{$uAsOwnr}' and startswith(FromWhsCode, '{$warehousePrefix}')";
    
    if (!empty($filterStatus)) {
        $outgoingFilter .= " and U_ASB2B_STATUS eq '{$filterStatus}'";
    }
    if (!empty($filterStartDate)) {
        $startDateFormatted = date('Y-m-d', strtotime($filterStartDate));
        $outgoingFilter .= " and DocDate ge '{$startDateFormatted}'";
    }
    if (!empty($filterEndDate)) {
        $endDateFormatted = date('Y-m-d', strtotime($filterEndDate));
        $outgoingFilter .= " and DocDate le '{$endDateFormatted}'";
    }
    
    // Arama Mantığı
    $searchFields = ['U_ASB2B_NumAtCard', 'FromWhsCode', 'WhsCode', 'ItemCode', 'Dscription'];
    if ($search !== '') {
        if (ctype_digit($search)) {
            $outgoingFilter .= " and DocEntry eq {$search}";
        } else {
            $searchEsc = str_replace("'", "''", $search);
            $parts = [];
            foreach ($searchFields as $field) {
                $parts[] = "substringof('{$searchEsc}', {$field})";
            }
            $outgoingFilter .= " and (" . implode(' or ', $parts) . ")";
        }
    }
    
    // Sorguyu Çalıştır
    $outgoingQuery = "view.svc/ASB2B_TransferRequestList_B1SLQuery?" . 
                     "\$filter=" . urlencode($outgoingFilter) . 
                     "&\$orderby=" . urlencode("DocEntry desc");
    
    $outgoingData = $sap->get($outgoingQuery);
    $outgoingTransfersRaw = $outgoingData['response']['value'] ?? [];
    
    // Gruplama ve Lines oluşturma (Orijinal kodunuzdaki logic)
    usort($outgoingTransfersRaw, function($a, $b) {
        $docEntryA = $a['DocEntry'] ?? 0;
        $docEntryB = $b['DocEntry'] ?? 0;
        if ($docEntryB != $docEntryA) return $docEntryB - $docEntryA;
        return ($a['LineNum'] ?? 0) - ($b['LineNum'] ?? 0);
    });
    
    $outgoingTransfersFiltered = $outgoingTransfersRaw;

    // Lines gruplama (Sepet işlemleri için)
    $linesByDocEntry = [];
    $processedDocEntries = [];
    
    foreach ($outgoingTransfersFiltered as $row) {
        $docEntry = $row['DocEntry'] ?? '';
        $status = (string)($row['U_ASB2B_STATUS'] ?? '0');
        $itemCode = $row['ItemCode'] ?? '';
        $lineNum = (int)($row['LineNum'] ?? 0);
        
        if (!empty($docEntry) && ($status == '0' || $status == '1')) {
            if (!isset($linesByDocEntry[$docEntry])) {
                $linesByDocEntry[$docEntry] = [];
                $processedDocEntries[$docEntry] = true;
            }
            
            // SAP'den RemainingOpenQuantity çek (kısmi sevk için kritik)
            $remainingQty = 0;
            if (!isset($processedDocEntries["{$docEntry}_{$itemCode}_{$lineNum}"])) {
                $itrlQuery = "InventoryTransferRequests({$docEntry})/InventoryTransferRequestLines?\$filter=ItemCode eq '{$itemCode}' and LineNum eq {$lineNum}";
                $itrlData = $sap->get($itrlQuery);
                if (($itrlData['status'] ?? 0) == 200) {
                    $itrlResponse = $itrlData['response'] ?? null;
                    $itrlLines = [];
                    if (isset($itrlResponse['value']) && is_array($itrlResponse['value'])) {
                        $itrlLines = $itrlResponse['value'];
                    } elseif (is_array($itrlResponse) && !isset($itrlResponse['value'])) {
                        $itrlLines = $itrlResponse;
                    }
                    if (!empty($itrlLines)) {
                        $remainingQty = (float)($itrlLines[0]['RemainingOpenQuantity'] ?? $row['Quantity'] ?? 0);
                    }
                }
                $processedDocEntries["{$docEntry}_{$itemCode}_{$lineNum}"] = true;
            }
            
            // Eğer RemainingOpenQuantity bulunamadıysa, Quantity'yi kullan
            if ($remainingQty == 0) {
                $remainingQty = (float)($row['Quantity'] ?? 0);
            }

            $linesByDocEntry[$docEntry][] = [
                'ItemCode' => $itemCode,
                'ItemDescription' => $row['Dscription'] ?? '',
                
                // GÖRÜNTÜLEME İÇİN: Toplam Sipariş Miktarı
                'Quantity' => (float)($row['Quantity'] ?? 0), 
                
                // SEPET MANTIĞI İÇİN: Kalan Açık Miktar (RemainingOpenQuantity)
                'OpenQty' => (float)($row['OpenQty'] ?? 0),//$remainingQty,
                
                'LineNum' => $lineNum,
                'BaseQty' => 1.0, 
                'UoMCode' => $row['UoMCode'] ?? 'AD',
                // Satır durumu (Kısmi sevk kontrolü için)
                'LineStatus' => $row['LineStatus'] ?? 'O'
            ];

            
        }
    }
    
    foreach ($outgoingTransfersFiltered as &$row) {
        $docEntry = $row['DocEntry'] ?? '';
        $row['InventoryTransferRequestLines'] = $linesByDocEntry[$docEntry] ?? [];
    }
    unset($row);
    
    // Sayfalama
    $totalItems = count($outgoingTransfersFiltered);
    $totalPages = ceil($totalItems / $itemsPerPage);
    $skip = ($page - 1) * $itemsPerPage;
    $outgoingTransfers = array_slice($outgoingTransfersFiltered, $skip, $itemsPerPage);
    
    $outgoingPagination = [
        'current_page' => $page,
        'items_per_page' => $itemsPerPage,
        'total_items' => $totalItems,
        'total_pages' => $totalPages
    ];

    // Debug
    $debugInfo['outgoingFilter'] = $outgoingFilter;
    $debugInfo['rawCount'] = count($outgoingTransfersRaw);
} else {
    $outgoingPagination = ['current_page' => 1, 'items_per_page' => 25, 'total_items' => 0, 'total_pages' => 0];
}

// Tarih formatlama yardımcı fonksiyonu
function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) return date('d.m.Y', strtotime(substr($date, 0, 10)));
    return date('d.m.Y', strtotime($date));
}

function buildSearchData(...$parts) {
    $textParts = [];
    foreach ($parts as $part) {
        if (!empty($part) && $part !== '-') $textParts[] = $part;
    }
    return mb_strtolower(implode(' ', $textParts), 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transferler - MINOA</title>
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
    overflow-x: hidden;
}

/* Main content - adjusted for sidebar */
.main-content {
    width: 100%;
    background: whitesmoke;
    padding: 0;
    min-height: 100vh;
    position: relative;
    overflow-x: hidden;
    animation: slideInFromRight 0.4s ease-out;
}

.main-content.page-slide-out-left {
    animation: slideOutToLeft 0.3s ease-in;
}

.main-content.page-slide-out-right {
    animation: slideOutToRight 0.3s ease-in;
}

@keyframes slideInFromLeft {
    from {
        transform: translateX(-100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideInFromRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slideOutToLeft {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(-100%);
        opacity: 0;
    }
}

@keyframes slideOutToRight {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(100%);
        opacity: 0;
    }
}

.sidebar.expanded ~ .main-content {
    margin-left: 260px;
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
}

.content-wrapper {
    padding: 32px;
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

        .filter-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;

    padding: 24px;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
        }

        .filter-group {
            display: flex;
            flex-direction: column;



    gap: 8px;
        }

        .filter-group label {



    color: #1e3a8a;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-group input[type="date"],
.filter-group select {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
            font-size: 14px;

    transition: all 0.2s ease;
    background: white;
}

.filter-group input[type="date"]:hover,
.filter-group select:hover {
    border-color: #3b82f6;
}

.filter-group input[type="date"]:focus,
.filter-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Table Controls */
.table-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #6b7280;
}

.entries-select {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
            border-radius: 6px;

    font-size: 14px;
            cursor: pointer;

    transition: all 0.2s ease;
}

.entries-select:hover {
    border-color: #3b82f6;
}

.entries-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.search-box {
    display: flex;
    gap: 8px;
    align-items: center;
}

.search-input {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    min-width: 220px;
    transition: border-color 0.2s;
}

.search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
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

.btn-secondary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-icon {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;

    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}


.btn-view {
    background: #f0f9ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}

.btn-view:hover {
    background: #dbeafe;
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

.table-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

/* Modern Checkbox Styling */
input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    border: 2px solid #cbd5e1;
    border-radius: 4px;
    background: white;
    position: relative;
    transition: all 0.2s ease;
}

input[type="checkbox"]:hover {
    border-color: #3b82f6;
    background: #f0f9ff;
}

input[type="checkbox"]:checked {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    border-color: #3b82f6;
}

input[type="checkbox"]:checked::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 14px;
    font-weight: bold;
    line-height: 1;
}

input[type="checkbox"]:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
}

/* Sepet Panel */
.sepet-panel {
    position: fixed;
    right: 0;
    top: 80px;
    width: 420px;
    height: calc(100vh - 80px);
    background: white;
    box-shadow: -2px 0 12px rgba(0,0,0,0.15);
    z-index: 50;
    display: none;
    flex-direction: column;
}

.sepet-panel-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 2px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
}

.sepet-panel-content {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem;
}

.sepet-panel-footer {
    padding: 1.25rem 1.5rem;
    border-top: 2px solid #e5e7eb;
    background: white;
}
    </style>
</head>
<body>
        <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
                <h2><?= $viewType === 'incoming' ? 'Gelen Transferler' : 'Giden Transferler' ?></h2>

            <div style="display: flex; gap: 12px; align-items: center;">
                <?php if ($viewType === 'incoming'): ?>
                    <button class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=outgoing<?= !empty($filterStatus) ? '&status=' . urlencode($filterStatus) : '' ?><?= !empty($filterStartDate) ? '&start_date=' . urlencode($filterStartDate) : '' ?><?= !empty($filterEndDate) ? '&end_date=' . urlencode($filterEndDate) : '' ?>'">
                        📤 Giden Transferler
                    </button>
                    <button class="btn btn-primary" onclick="window.location.href='TransferlerSO.php'">+ Yeni Transfer Oluştur</button>

                <?php else: ?>
                    <button class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=incoming<?= !empty($filterStatus) ? '&status=' . urlencode($filterStatus) : '' ?><?= !empty($filterStartDate) ? '&start_date=' . urlencode($filterStartDate) : '' ?><?= !empty($filterEndDate) ? '&end_date=' . urlencode($filterEndDate) : '' ?>'">
                        📥 Gelen Transferler
                    </button>
                    <button class="btn btn-primary" onclick="sepetToggle()" id="sepetBtn" style="display: inline-flex;">
                        🛒 Sepet (<span id="sepetCount">0</span>)
                    </button>
                    <button class="btn btn-primary" onclick="window.location.href='TransferlerSO.php'">+ Yeni Transfer Oluştur</button>
                <?php endif; ?>
                        </div>




        </header>



        <div class="content-wrapper">
            <section class="card">
                    <div class="filter-section">
                        <div class="filter-group">
                            <label>Transfer Durumu</label>

                        <select class="filter-select" id="filterStatus" onchange="applyFilters()">
                                <option value="">Tümü</option>

                            <option value="0" <?= $filterStatus === '0' ? 'selected' : '' ?>>Onay Bekliyor</option>
                            <option value="2" <?= $filterStatus === '2' ? 'selected' : '' ?>>Hazırlanıyor</option>
                            <option value="3" <?= $filterStatus === '3' ? 'selected' : '' ?>>Sevk Edildi</option>
                            <option value="4" <?= $filterStatus === '4' ? 'selected' : '' ?>>Tamamlandı</option>
                            <option value="5" <?= $filterStatus === '5' ? 'selected' : '' ?>>İptal Edildi</option>
                            </select>
                        </div>

                        <div class="filter-group">



                            <label>Başlangıç Tarihi</label>


                        <input type="date" class="filter-input" id="start-date" value="<?= htmlspecialchars($filterStartDate) ?>" onchange="applyFilters()">
                        </div>


                        <div class="filter-group">


                            <label>Bitiş Tarihi</label>

                                
                        <input type="date" class="filter-input" id="end-date" value="<?= htmlspecialchars($filterEndDate) ?>" onchange="applyFilters()">
                            </div>

                        </div>


                <?php if ($viewType === 'incoming'): ?>
                <!-- Gelen Transferler (Diğer şubeden gelen) -->
                <div class="table-controls">
                    <div class="show-entries">
                        Sayfada 
                        <select class="entries-select" id="entriesPerPage" onchange="applyFilters()">
                            <option value="25" <?= (isset($incomingPagination['items_per_page']) && $incomingPagination['items_per_page'] == 25) ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= (isset($incomingPagination['items_per_page']) && $incomingPagination['items_per_page'] == 50) ? 'selected' : '' ?>>50</option>
                            <option value="75" <?= (isset($incomingPagination['items_per_page']) && $incomingPagination['items_per_page'] == 75) ? 'selected' : '' ?>>75</option>
                        </select>
                        kayıt göster
                    </div>

                    <div class="search-box">
                        <input type="text" class="search-input" id="tableSearch" placeholder="Ara..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" onkeyup="if(event.key==='Enter') performSearch()">
                        <button class="btn btn-secondary" onclick="performSearch()">🔍</button>
                        </div>
                    </div>

                    <?php if (isset($incomingDebugInfo) && !empty($incomingDebugInfo)): ?>
                        <details style="margin: 1rem 0; padding: 0; background: #fef3c7; border-radius: 6px; font-size: 0.875rem; text-align: left;">
                            <summary style="padding: 1rem; cursor: pointer; font-weight: bold; user-select: none; list-style: none; display: flex; align-items: center; gap: 0.5rem;">
                                <span style="font-size: 1.2rem;">🔍</span>
                                <span>Debug Bilgisi (Gelen Transferler)</span>
                                <span style="margin-left: auto; font-size: 0.75rem; color: #6b7280;">(Tıklayarak aç/kapat)</span>
                            </summary>
                            <div style="padding: 1rem; padding-top: 0;">
                            <strong>🔍 Debug Bilgisi (Gelen Transferler):</strong><br>
                            <strong>Kullanıcı:</strong> <?= htmlspecialchars($incomingDebugInfo['userName'] ?? 'BULUNAMADI') ?><br>
                            <strong>Branch:</strong> <?= htmlspecialchars($incomingDebugInfo['branch'] ?? 'BULUNAMADI') ?><br>
                            <strong>U_AS_OWNR:</strong> <?= htmlspecialchars($incomingDebugInfo['uAsOwnr'] ?? 'BULUNAMADI') ?><br>
                            <hr style="margin: 1rem 0; border: 1px solid #d1d5db;">
                            <strong style="color: #1e40af; font-size: 1rem;">📡 View Sorgu Bilgileri:</strong><br>
                            <strong>Incoming Query:</strong> <code style="background: #f3f4f6; padding: 2px 4px; border-radius: 3px;"><?= htmlspecialchars($incomingDebugInfo['incomingQuery'] ?? 'YOK') ?></code><br>
                            <strong>Incoming Filter:</strong> <code style="background: #f3f4f6; padding: 2px 4px; border-radius: 3px;"><?= htmlspecialchars($incomingDebugInfo['incomingFilter'] ?? 'YOK') ?></code><br>
                            <strong>Incoming HTTP Status:</strong> <?= htmlspecialchars($incomingDebugInfo['incomingHttpStatus'] ?? '0') ?><br>
                            <strong>Incoming Raw Count:</strong> <?= htmlspecialchars($incomingDebugInfo['incomingRawCount'] ?? '0') ?> (View'dan gelen toplam satır sayısı)<br>
                            <strong>Incoming Filtered Count:</strong> <?= htmlspecialchars($incomingDebugInfo['incomingFilteredCount'] ?? '0') ?> (Gösterilen transfer sayısı)<br>
                            
                            
                            <?php if (isset($incomingPagination)): ?>
                                <hr style="margin: 1rem 0; border: 1px solid #d1d5db;">
                                <strong>Pagination:</strong> Sayfa <?= $incomingPagination['current_page'] ?> / <?= $incomingPagination['total_pages'] ?> (Toplam: <?= $incomingPagination['total_items'] ?> kayıt)<br>
                            <?php endif; ?>
                            <?php if (isset($incomingDebugInfo['error'])): ?>
                                <strong style="color: #dc2626;">Error:</strong> <?= htmlspecialchars(json_encode($incomingDebugInfo['error'])) ?><br>
                            <?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>

                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Transfer No</th>
                                    <th>Kalem No</th>
                                    <th>Kalem Tanımı</th>
                                    <th>Tarih<br><small style="font-weight: normal;">Talep / Vade</small></th>
                                    <th>Gönderen Şube</th>
                                    <th>Durum</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>

                    <tbody>
                        <?php if (empty($incomingTransfers)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    Gelen transfer bulunamadı.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($incomingTransfers as $row): 
    // 1. Veritabanındaki Başlık durumu
    $headerStatus = (string)($row['U_ASB2B_STATUS'] ?? '0');
    $status = $headerStatus;

    // 2. SAP Durum Kontrolleri
    $sapDocStatus = $row['DocumentStatus'] ?? ''; 
    $sapLineStatus = $row['LineStatus'] ?? ''; // SQL View'a eklediğimiz satır durumu

    // MANTIK:
    // A) Belge tamamen kapalıysa -> HEPSİ SEVK EDİLDİ
    // B) Belge açık ama BU SATIR kapalıysa -> BU SATIR SEVK EDİLDİ
    // C) Belge açık ve satır açıksa -> ONAY BEKLİYOR (Kısmi kalanlar için)
    
    if ($status != '5' && $status != '4') {
        if ($sapDocStatus === 'bost_Close' || $sapDocStatus === 'C') {
            $status = '3'; // Belge kapalı, hepsi gitti
        } elseif ($sapLineStatus === 'bost_Close' || $sapLineStatus === 'C') {
            $status = '3'; // Sadece bu satır gitti
        } else {
            // Satır hala açıksa, veritabanındaki başlık durumuna (0/1/2) geri dönmeliyiz.
            // Eğer başlık '3' yapılmışsa ama satır hala 'Açık' ise, bunu '0' (Onay Bekliyor) gibi gösterelim
            if ($headerStatus === '3') {
                $status = '0'; // Kısmi kalanlar bekliyor görünsün
            }
        }
    }

    $statusText = getStatusText($status);
    $statusClass = getStatusClass($status);
    
    // Gelen transferlerde: Sadece Sevk Edildi (3) durumunda Teslim Al butonu göster
    $canReceive = ($status === '3'); 

    $fromWhsCode = $row['FromWhsCode'] ?? '';
    $fromWhsName = $row['FromWhsName'] ?? ''; 
    $fromWhsDisplay = $fromWhsCode;
    if (!empty($fromWhsName)) {
        $fromWhsDisplay = $fromWhsCode . ' / ' . $fromWhsName;
    }
    
    $docDate = formatDate($row['DocDate'] ?? '');
    $dueDate = formatDate($row['DocDueDate'] ?? '');
    $numAtCard = htmlspecialchars($row['U_ASB2B_NumAtCard'] ?? '-');
    $docEntry = htmlspecialchars($row['DocEntry'] ?? '-');
    $itemCode = htmlspecialchars($row['ItemCode'] ?? '-');
    $dscription = htmlspecialchars($row['Dscription'] ?? '-');
    
    $searchData = buildSearchData($docEntry, $fromWhsDisplay, $docDate, $dueDate, $numAtCard, $statusText);
?>
                                <tr data-row data-search="<?= htmlspecialchars($searchData, ENT_QUOTES, 'UTF-8') ?>">
                                    <td style="font-weight: 600; color: #1e40af;"><?= $docEntry ?></td>
                                    <td><?= $itemCode ?></td>
                                    <td><?= $dscription ?></td>
                                    <td style="text-align: center; line-height: 1.4;">
                                        <div><?= $docDate ?></div>
                                        <div style="font-size: 0.85em; color: #6b7280;"><?= $dueDate ?></div>
                                    </td>
                                    <td><?= $fromWhsDisplay ?></td>
                                    <td>
                                        <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="Transferler-Detay.php?docEntry=<?= urlencode($row['DocEntry']) ?>&type=incoming&itemCode=<?= urlencode($row['ItemCode'] ?? '') ?>&lineNum=<?= urlencode($row['LineNum'] ?? '') ?>">
                                                <button class="btn-icon btn-view">👁️ Detay</button>
                                            </a>
                                            <?php if ($canReceive): ?>
                                                <a href="Transferler-TeslimAl.php?docEntry=<?= urlencode($row['DocEntry']) ?>&itemCode=<?= urlencode($row['ItemCode'] ?? '') ?>&lineNum=<?= urlencode($row['LineNum'] ?? '') ?>">
                                                    <button class="btn-icon btn-receive">✓ Teslim Al</button>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <?php if (isset($incomingPagination) && $incomingPagination['total_pages'] >= 1): ?>
                <div style="padding: 20px; display: flex; justify-content: center; align-items: center; gap: 8px;">
                    <?php
                    $currentPage = $incomingPagination['current_page'];
                    $totalPages = $incomingPagination['total_pages'];
                    $maxPagesToShow = 7;
                    
                    $searchParam = !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '';
                    $statusParam = !empty($filterStatus) ? '&status=' . urlencode($filterStatus) : '';
                    $startDateParam = !empty($filterStartDate) ? '&start_date=' . urlencode($filterStartDate) : '';
                    $endDateParam = !empty($filterEndDate) ? '&end_date=' . urlencode($filterEndDate) : '';
                    $entriesParam = isset($incomingPagination['items_per_page']) ? '&entries=' . $incomingPagination['items_per_page'] : '';
                    $baseParams = $statusParam . $startDateParam . $endDateParam . $entriesParam . $searchParam;
                    
                    // İlk sayfa
                    if ($currentPage > 1): ?>
                        <a href="?view=incoming&page=1<?= $baseParams ?>" 
                           style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; color: #374151; background: white;">« İlk</a>
                    <?php endif; ?>
                    
                    <?php
                    // Sayfa numaralarını hesapla
                    $startPage = max(1, $currentPage - floor($maxPagesToShow / 2));
                    $endPage = min($totalPages, $startPage + $maxPagesToShow - 1);
                    if ($endPage - $startPage < $maxPagesToShow - 1) {
                        $startPage = max(1, $endPage - $maxPagesToShow + 1);
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="?view=incoming&page=<?= $i ?><?= $baseParams ?>" 
                           style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; color: #374151; background: <?= $i == $currentPage ? '#3b82f6; color: white;' : 'white;' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?view=incoming&page=<?= $totalPages ?><?= $baseParams ?>" 
                           style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; color: #374151; background: white;">Son »</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <!-- Giden Transferler (Bu şubeden giden) -->
                <div class="table-controls">
                    <div class="show-entries">
                        Sayfada 
                        <select class="entries-select" id="entriesPerPage" onchange="applyFilters()">
                            <option value="25" <?= (isset($outgoingPagination['items_per_page']) && $outgoingPagination['items_per_page'] == 25) ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= (isset($outgoingPagination['items_per_page']) && $outgoingPagination['items_per_page'] == 50) ? 'selected' : '' ?>>50</option>
                            <option value="75" <?= (isset($outgoingPagination['items_per_page']) && $outgoingPagination['items_per_page'] == 75) ? 'selected' : '' ?>>75</option>
                        </select>
                        kayıt göster
                    </div>
                    <div class="search-box">
                        <input type="text" class="search-input" id="tableSearch" placeholder="Ara..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" onkeyup="if(event.key==='Enter') performSearch()">
                        <button class="btn btn-secondary" onclick="performSearch()">🔍</button>
                    </div>
                        </div>

                    <?php if (isset($debugInfo) && !empty($debugInfo)): ?>
                        <details style="margin: 1rem 0; padding: 0; background: #fef3c7; border-radius: 6px; font-size: 0.875rem; text-align: left;">
                            <summary style="padding: 1rem; cursor: pointer; font-weight: bold; user-select: none; list-style: none; display: flex; align-items: center; gap: 0.5rem;">
                                <span style="font-size: 1.2rem;">🔍</span>
                                <span>Debug Bilgisi (Giden Transferler)</span>
                                <span style="margin-left: auto; font-size: 0.75rem; color: #6b7280;">(Tıklayarak aç/kapat)</span>
                            </summary>
                            <div style="padding: 1rem; padding-top: 0;">
                            <strong>🔍 Debug Bilgisi (Giden Transferler):</strong><br>
                            <strong>Kullanıcı:</strong> <?= htmlspecialchars($debugInfo['userName'] ?? 'BULUNAMADI') ?><br>
                            <strong>Branch:</strong> <?= htmlspecialchars($debugInfo['branch'] ?? 'BULUNAMADI') ?><br>
                            <strong>U_AS_OWNR:</strong> <?= htmlspecialchars($debugInfo['uAsOwnr'] ?? 'BULUNAMADI') ?><br>
                            <hr style="margin: 1rem 0; border: 1px solid #d1d5db;">
                            <strong style="color: #1e40af; font-size: 1rem;">📡 View Sorgu Bilgileri:</strong><br>
                            <strong>Outgoing Query:</strong> <code style="background: #f3f4f6; padding: 2px 4px; border-radius: 3px;"><?= htmlspecialchars($debugInfo['outgoingQuery'] ?? 'YOK') ?></code><br>
                            <strong>Outgoing Filter:</strong> <code style="background: #f3f4f6; padding: 2px 4px; border-radius: 3px;"><?= htmlspecialchars($debugInfo['outgoingFilter'] ?? 'YOK') ?></code><br>
                            <strong>Outgoing HTTP Status:</strong> <?= htmlspecialchars($debugInfo['outgoingHttpStatus'] ?? '0') ?><br>
                            <strong>Outgoing Raw Count:</strong> <?= htmlspecialchars($debugInfo['outgoingRawCount'] ?? '0') ?> (View'dan gelen toplam satır sayısı)<br>
                            <strong>Outgoing Filtered Count:</strong> <?= htmlspecialchars($debugInfo['outgoingFilteredCount'] ?? '0') ?> (Ana depo filtresi sonrası satır sayısı)<br>
                            <strong>Outgoing Paginated Count:</strong> <?= htmlspecialchars($debugInfo['outgoingPaginatedCount'] ?? '0') ?> (Sayfalama sonrası gösterilen satır sayısı)<br>
                            
                            
                            <?php if (isset($debugInfo['outgoingPagination'])): ?>
                                <hr style="margin: 1rem 0; border: 1px solid #d1d5db;">
                                <strong>Pagination:</strong> Sayfa <?= $debugInfo['outgoingPagination']['current_page'] ?> / <?= $debugInfo['outgoingPagination']['total_pages'] ?> (Toplam: <?= $debugInfo['outgoingPagination']['total_items'] ?> kayıt)<br>
                            <?php endif; ?>
                            <?php if (isset($debugInfo['error'])): ?>
                                <strong style="color: #dc2626;">Error:</strong> <?= htmlspecialchars(json_encode($debugInfo['error'])) ?><br>
                            <?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>

                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                                    <th>Transfer No</th>
                                    <th>Kalem No</th>
                                    <th>Kalem Tanımı</th>
                                    <th>Tarih<br><small style="font-weight: normal;">Talep / Vade</small></th>
                                    <th>Alıcı Şube</th>
                                    <th>Durum</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>

                    <tbody>
                        <?php if (empty($outgoingTransfers)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    Giden transfer bulunamadı.
                                    <?php if (isset($debugInfo) && !empty($debugInfo)): ?>
                                        <details style="margin-top: 1rem; padding: 0; background: #fef3c7; border-radius: 6px; font-size: 0.875rem; text-align: left; max-width: 800px; margin-left: auto; margin-right: auto;">
                                            <summary style="padding: 1rem; cursor: pointer; font-weight: bold; user-select: none; list-style: none; display: flex; align-items: center; gap: 0.5rem;">
                                                <span style="font-size: 1.2rem;">🔍</span>
                                                <span>Debug Bilgisi (Giden Transferler)</span>
                                                <span style="margin-left: auto; font-size: 0.75rem; color: #6b7280;">(Tıklayarak aç/kapat)</span>
                                            </summary>
                                            <div style="padding: 1rem; padding-top: 0;">
                                            <strong>🔍 Debug Bilgisi (Giden Transferler):</strong><br>
                                            <strong>Kullanıcı:</strong> <?= htmlspecialchars($debugInfo['userName'] ?? 'BULUNAMADI') ?><br>
                                            <strong>Branch:</strong> <?= htmlspecialchars($debugInfo['branch'] ?? 'BULUNAMADI') ?><br>
                                            <strong>U_AS_OWNR:</strong> <?= htmlspecialchars($debugInfo['uAsOwnr'] ?? 'BULUNAMADI') ?><br>
                                            <?php if (isset($debugInfo['outgoingQuery'])): ?>
                                                <strong>Outgoing Query:</strong> <?= htmlspecialchars($debugInfo['outgoingQuery'] ?? '') ?><br>
                                                <strong>Outgoing Filter:</strong> <?= htmlspecialchars($debugInfo['outgoingFilter'] ?? '') ?><br>
                                                <strong>Outgoing HTTP Status:</strong> <?= htmlspecialchars($debugInfo['outgoingHttpStatus'] ?? '0') ?><br>
                                                <strong>Outgoing Raw Count:</strong> <?= htmlspecialchars($debugInfo['outgoingRawCount'] ?? '0') ?><br>
                                            <?php endif; ?>
                                            <?php if (isset($debugInfo['error'])): ?>
                                                <strong style="color: #dc2626;">Error:</strong> <?= htmlspecialchars(json_encode($debugInfo['error'])) ?><br>
                                            <?php endif; ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                            </td>

                            </tr>
                        <?php else: ?>
                            <?php foreach ($outgoingTransfers as $row): 
    // 1. Veritabanındaki Başlık durumu
    $headerStatus = (string)($row['U_ASB2B_STATUS'] ?? '0');
    $status = $headerStatus;

    // 2. SAP Durum Kontrolleri
    $sapDocStatus = $row['DocumentStatus'] ?? ''; 
    $sapLineStatus = $row['LineStatus'] ?? ''; // SQL View'a eklediğimiz satır durumu

    // MANTIK:
    // A) Belge tamamen kapalıysa -> HEPSİ SEVK EDİLDİ
    // B) Belge açık ama BU SATIR kapalıysa -> BU SATIR SEVK EDİLDİ
    // C) Belge açık ve satır açıksa -> ONAY BEKLİYOR (Kısmi kalanlar için)
    
    if ($status != '5' && $status != '4') {
        if ($sapDocStatus === 'bost_Close' || $sapDocStatus === 'C') {
            $status = '3'; // Belge kapalı, hepsi gitti
        } elseif ($sapLineStatus === 'bost_Close' || $sapLineStatus === 'C') {
            $status = '3'; // Sadece bu satır gitti
        } else {
            // Satır hala açıksa, veritabanındaki başlık durumuna (0/1/2) geri dönmeliyiz.
            // Eğer başlık '3' yapılmışsa ama satır hala 'Açık' ise, bunu '0' (Onay Bekliyor) gibi gösterelim
            if ($headerStatus === '3') {
                $status = '0'; // Kısmi kalanlar bekliyor görünsün
            }
        }
    }

    $statusText = getStatusText($status);
    $statusClass = getStatusClass($status);
    
    // Giden transferlerde: Onay Bekliyor (0, 1) durumunda Onayla/İptal butonları
    $canApprove = in_array($status, ['0', '1']); 

    $toWhsCode = $row['WhsCode'] ?? ''; 
    $toWhsName = $row['ToWhsName'] ?? ''; 
    $toWhsDisplay = $toWhsCode;
    if (!empty($toWhsName)) {
        $toWhsDisplay = $toWhsCode . ' / ' . $toWhsName;
    }
    
    $docDate = formatDate($row['DocDate'] ?? '');
    $dueDate = formatDate($row['DocDueDate'] ?? '');
    $numAtCard = htmlspecialchars($row['U_ASB2B_NumAtCard'] ?? '-');
    $docEntry = htmlspecialchars($row['DocEntry'] ?? '-');
    $itemCode = htmlspecialchars($row['ItemCode'] ?? '-');
    $dscription = htmlspecialchars($row['Dscription'] ?? '-');
    
    $searchData = buildSearchData($docEntry, $toWhsDisplay, $docDate, $dueDate, $numAtCard, $statusText);
    $lines = $row['InventoryTransferRequestLines'] ?? [];
    
    $transferLinesJson = !empty($lines) ? htmlspecialchars(json_encode($lines), ENT_QUOTES, 'UTF-8') : '[]';
?>
                                <tr data-row data-search="<?= htmlspecialchars($searchData, ENT_QUOTES, 'UTF-8') ?>" data-docentry="<?= $docEntry ?>" data-itemcode="<?= htmlspecialchars($itemCode) ?>" data-linenum="<?= htmlspecialchars($row['LineNum'] ?? '') ?>" data-lines="<?= $transferLinesJson ?>">
                                    <td style="text-align: center;">
                                       
                                            <input type="checkbox" class="transfer-checkbox" value="<?= $docEntry ?>" data-docentry="<?= $docEntry ?>" data-itemcode="<?= htmlspecialchars($itemCode) ?>" data-linenum="<?= htmlspecialchars($row['LineNum'] ?? '') ?>"<?= $canApprove ? '' : 'disabled readonly' ?>
                                        
                                    </td>
                                    <td style="font-weight: 600; color: #1e40af;"><?= $docEntry ?></td>
                                    <td><?= $itemCode ?></td>
                                    <td><?= $dscription ?></td>
                                    <td style="text-align: center;">
                                        <div style="display: flex; flex-direction: column; gap: 2px;">
                                            <span><?= $docDate ?></span>
                                            <span style="font-size: 0.875rem; color: #6b7280;"><?= $dueDate ?></span>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($toWhsDisplay) ?></td>
                                    <td>
                                        <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="Transferler-Detay.php?docEntry=<?= urlencode($row['DocEntry']) ?>&type=outgoing&itemCode=<?= urlencode($row['ItemCode'] ?? '') ?>&lineNum=<?= urlencode($row['LineNum'] ?? '') ?>">
                                                <button class="btn-icon btn-view">👁️ Detay</button>
                                            </a>
                                            <?php if ($canApprove): ?>
                                                <a href="Transferler-Onayla.php?docEntry=<?= urlencode($row['DocEntry']) ?>&action=approve">
                                                    <button class="btn-icon btn-approve">✓ Onayla</button>
                                                </a>
                                                <a href="Transferler-Onayla.php?docEntry=<?= urlencode($row['DocEntry']) ?>&action=reject">
                                                    <button class="btn-icon" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">✗ İptal</button>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        
                        <?php if (isset($outgoingPagination) && $outgoingPagination['total_pages'] > 1): ?>
                        <div style="padding: 20px; display: flex; justify-content: center; align-items: center; gap: 8px;">
                            <?php
                            $currentPage = $outgoingPagination['current_page'];
                            $totalPages = $outgoingPagination['total_pages'];
                            $maxPagesToShow = 7;
                            
                            $searchParam = !empty($_GET['search']) ? '&search=' . urlencode($_GET['search']) : '';
                            $statusParam = !empty($filterStatus) ? '&status=' . urlencode($filterStatus) : '';
                            $startDateParam = !empty($filterStartDate) ? '&start_date=' . urlencode($filterStartDate) : '';
                            $endDateParam = !empty($filterEndDate) ? '&end_date=' . urlencode($filterEndDate) : '';
                            $entriesParam = isset($outgoingPagination['items_per_page']) ? '&entries=' . $outgoingPagination['items_per_page'] : '';
                            $baseParams = $statusParam . $startDateParam . $endDateParam . $entriesParam . $searchParam;
                            
                            // İlk sayfa
                            if ($currentPage > 1): ?>
                                <a href="?view=outgoing&page=1<?= $baseParams ?>" 
                                   style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; color: #374151; background: white;">« İlk</a>
                            <?php endif; ?>
                            
                            <?php
                            // Sayfa numaralarını hesapla
                            $startPage = max(1, $currentPage - floor($maxPagesToShow / 2));
                            $endPage = min($totalPages, $startPage + $maxPagesToShow - 1);
                            if ($endPage - $startPage < $maxPagesToShow - 1) {
                                $startPage = max(1, $endPage - $maxPagesToShow + 1);
                            }
                            
                            for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <a href="?view=outgoing&page=<?= $i ?><?= $baseParams ?>" 
                                   style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; color: #374151; background: <?= $i == $currentPage ? '#3b82f6; color: white;' : 'white;' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                            
                            <?php if ($currentPage < $totalPages): ?>
                                <a href="?view=outgoing&page=<?= $totalPages ?><?= $baseParams ?>" 
                                   style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; color: #374151; background: white;">Son »</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                <?php endif; ?>
            </section>
            
            <?php if ($viewType === 'outgoing'): ?>
            <!-- Sepet Panel -->
            <div id="sepetPanel" class="sepet-panel">
                <div class="sepet-panel-header">
                    <h3 style="margin: 0; color: #1e40af; font-size: 1.25rem; font-weight: 600;">🛒 Sepet</h3>
                    <button class="btn btn-secondary" onclick="sepetToggle()" style="padding: 0.5rem 1rem; font-size: 0.875rem;">✕ Kapat</button>
                    </div>


                <div id="sepetContent" class="sepet-panel-content">
                    <div style="text-align: center; padding: 2rem; color: #9ca3af;">
                        Sepet boş. Lütfen onaylamak istediğiniz transferleri seçin.
                    </div>
                </div>

                <div class="sepet-panel-footer">
                    <button class="btn btn-primary" onclick="sepetOnayla()" style="width: 100%; padding: 12px; font-size: 1rem; font-weight: 600;">
                        ✓ Toplu Onayla
                    </button>
            </div>

        </div>

            <?php endif; ?>
        </div>
    </main>

        <script>

        // ============================================
        // SEPET SİSTEMİ - YENİDEN YAZILDI
        // ============================================
        
        let sepet = {}; // { docEntry: { toWarehouse, lines: [...] } }
        
        // Sepet panelini aç/kapat
        function sepetToggle() {
            const panel = document.getElementById('sepetPanel');
            if (panel) {
                const isVisible = panel.style.display === 'flex';
                panel.style.display = isVisible ? 'none' : 'flex';
            }
        }
        
        // Checkbox değiştiğinde - Artık ürün bazlı
        function checkboxChanged(checkbox) {
            const docEntry = checkbox.getAttribute('data-docentry');
            const itemCode = checkbox.getAttribute('data-itemcode');
            const lineNum = checkbox.getAttribute('data-linenum');   
   
            console.log('Checkbox changed:', { docEntry, itemCode, lineNum, checked: checkbox.checked });
            
            if (!docEntry || !itemCode) {
                console.error('Eksik parametre:', { docEntry, itemCode });
                return;
            }
            
            if (checkbox.checked) {
                sepetEkle(docEntry, itemCode, lineNum);
            } else {
                sepetCikar(docEntry, itemCode);
            }
            sepetGuncelle();
            console.log('Sepet durumu:', sepet);
        }
        
        // Tümünü seç/seçimi kaldır - Artık ürün bazlı
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('input.transfer-checkbox');
            const isChecked = selectAll.checked;
            
            checkboxes.forEach(cb => {
                cb.checked = isChecked;
                const docEntry = cb.getAttribute('data-docentry');
                const itemCode = cb.getAttribute('data-itemcode');
                const lineNum = cb.getAttribute('data-linenum');

                if (isChecked) {
                    if (docEntry && itemCode) {
                        sepetEkle(docEntry, itemCode, lineNum);
                    }
                } else {
                    if (docEntry && itemCode) {
                        sepetCikar(docEntry, itemCode);
                    }
                }
            });
            
            sepetGuncelle();
        }
        
        function sepetEkle(docEntry, itemCode, lineNum) {
            if (!docEntry || !itemCode) return;
            
            const sepetKey = `${docEntry}_${itemCode}`;
            if (sepet[sepetKey]) return; // Zaten sepette
            
            const row = document.querySelector(`tr[data-docentry="${docEntry}"][data-itemcode="${itemCode}"]`);
            if (!row) return;
           
            // Lines bilgisini al
            const linesJson = row.getAttribute('data-lines') || '[]';
           
            let allLines = [];
            try {
                allLines = JSON.parse(linesJson);
                // SAP formatı düzeltmeleri
                if (allLines && allLines.value) allLines = allLines.value;
                if (allLines && allLines.StockTransferLines) allLines = allLines.StockTransferLines;
            } catch (e) { allLines = []; }
         
            // Satırı Bul
            let selectedLine = null;
            if (lineNum) {
                selectedLine = allLines.find(l => (l.LineNum || l.LineNum === 0) && String(l.LineNum) === String(lineNum));
            }
            if (!selectedLine) {
                selectedLine = allLines.find(l => (l.ItemCode || '') === itemCode);
            }
            
            // Fallback (Bulunamazsa boş obje)
            if (!selectedLine) {
                const itemName = row.querySelector('td:nth-child(3)')?.textContent.trim() || '';
                selectedLine = { ItemCode: itemCode, ItemName: itemName, Quantity: 0, OpenQty: 0 };
            }

            let kalanMiktar = 0;

            if (selectedLine.OpenQty !== undefined && selectedLine.OpenQty !== null && selectedLine.OpenQty !== '') {
                kalanMiktar = parseFloat(selectedLine.OpenQty);
            } else {
                kalanMiktar = parseFloat(selectedLine.Quantity) || 0;
            }            

            
            // -------------------------------------------------------------
            // KRİTİK MİKTAR HESABI (OPENQTY KULLANIMI)
            // -------------------------------------------------------------
            // OpenQty (Kalan) varsa onu kullan, yoksa Quantity (Toplam) kullan
            //let kalanMiktar = parseFloat(selectedLine.OpenQty);        

            // Eğer kalan miktar 0 ise uyarı ver ve ekleme
            if (kalanMiktar <= 0) {
                alert('Bu ürün tamamen sevk edilmiş, gönderilecek miktar kalmamış!');
                const cb = document.querySelector(`input[data-docentry="${docEntry}"][data-itemcode="${itemCode}"]`);
                if(cb) cb.checked = false;
                return;
            }
            // -------------------------------------------------------------

            const baseQty = parseFloat(selectedLine._BaseQty || selectedLine.BaseQty || 1.0);
            
            sepet[sepetKey] = {
                docEntry: docEntry,
                itemCode: itemCode,
                toWarehouse: row.querySelector('td:nth-child(6)')?.textContent.trim() || '',
                lines: [{
                    ItemCode: selectedLine.ItemCode || itemCode,
                    ItemName: selectedLine.ItemDescription || selectedLine.ItemName || '',
                    UoMCode: selectedLine.UoMCode || 'AD',
                    LineNum: selectedLine.LineNum || lineNum || 0,
                    BaseQty: baseQty,
                    // Ekranda Toplam Sipariş görünsün
                    RequestedQty: parseFloat(selectedLine.Quantity || 0), 
                    StockQty: parseFloat(selectedLine._StockQty || selectedLine.StockQty || 0),
                    // Gönderilecek kutusuna otomatik olarak KALAN miktar gelsin
                    SentQty: kalanMiktar 
                }]
            };
            
            sepetGuncelle();
        }
        
        // Sepetten çıkar - Artık ürün bazlı
        function sepetCikar(docEntry, itemCode) {
            if (!docEntry || !itemCode) return;
            const sepetKey = `${docEntry}_${itemCode}`;
            if (sepet[sepetKey]) {
                delete sepet[sepetKey];
            }
        }
        
        // Sepeti güncelle (görsel)
        function sepetGuncelle() {
            const count = Object.keys(sepet).length;
            
            const sepetCountEl = document.getElementById('sepetCount');
            const sepetBtn = document.getElementById('sepetBtn');
            const sepetContent = document.getElementById('sepetContent');
            const sepetPanel = document.getElementById('sepetPanel');
            
            // Sepet sayısını güncelle
            if (sepetCountEl) {
                sepetCountEl.textContent = count;
            }
            
            // Sepet boşsa butonu ve paneli gizle
            if (count === 0) {
                if (sepetBtn) {
                    sepetBtn.style.display = 'none';
                }
                if (sepetContent) {
                    sepetContent.innerHTML = '<div style="text-align: center; padding: 2rem; color: #9ca3af;">Sepet boş. Lütfen onaylamak istediğiniz transferleri seçin.</div>';
                }
                if (sepetPanel) {
                    sepetPanel.style.display = 'none';
                }
                return;
            }
            
            // Sepet doluysa butonu göster
            if (sepetBtn) {
                sepetBtn.style.display = 'inline-flex';
            }
            
            // Sepet doluysa paneli göster (sepetToggle ile açılabilir)
            // Panel başlangıçta gizli, kullanıcı butona tıklayınca açılır
            
            // Sepet içeriğini oluştur - Artık ürün bazlı
            if (sepetContent) {
                let html = '';
                for (const [sepetKey, t] of Object.entries(sepet)) {
                    html += `<div style="margin-bottom: 1.5rem; padding: 1rem; background: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">`;
                    html += `<div style="font-weight: 600; color: #1e40af; margin-bottom: 0.5rem; font-size: 1rem;">Transfer No: ${t.docEntry}</div>`;
                    html += `<div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.5rem;">Kalem No: ${t.itemCode}</div>`;
                    html += `<div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.75rem;">Alıcı: ${t.toWarehouse}</div>`;
                    
                    if (!t.lines || t.lines.length === 0) {
                        html += `<div style="padding: 0.75rem; background: #e0edff; border-radius: 6px; font-size: 0.85rem; color: #1d4ed8;">ℹ Kalem bilgisi yüklenemedi.</div>`;
                    } else {
                        t.lines.forEach((line, idx) => {
                            const baseQty = parseFloat(line.BaseQty || 1.0);
                            const req = parseFloat(line.RequestedQty || 0);
                            const stk = parseFloat(line.StockQty || 0);
                            const send = parseFloat(line.SentQty || req);
                            const uomCode = line.UoMCode || 'AD';
                            
                            // En fazla talep edilen kadar gönderilebilir (stok kontrolü yok)
                            const max = req;
                            
                            // BaseQty dönüşüm bilgisi
                            let conversionText = '';
                            if (baseQty !== 1 && baseQty > 0) {
                                const reqAd = req * baseQty;
                                conversionText = ` <span style="font-size: 0.75rem; color: #6b7280;">(${reqAd.toFixed(2)} AD)</span>`;
                            }
                            
                            html += `<div style="margin-bottom: 1rem; padding: 0.75rem; background: #fff; border-radius: 6px; border: 1px solid #e5e7eb;">`;
                            html += `<div style="font-weight: 600; color: #111827; margin-bottom: 0.5rem;">${line.ItemCode} - ${line.ItemName}</div>`;
                            html += `<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.85rem; margin-bottom: 0.5rem;">`;
                            html += `<div><span style="color: #6b7280;">Talep edilen:</span> <strong>${req.toFixed(2)} ${uomCode}${conversionText}</strong></div>`;
                            html += `<div><span style="color: #6b7280;">Stok:</span> <strong style="color: ${stk > 0 ? '#10b981' : '#ef4444'}">${stk.toFixed(2)} ${uomCode}</strong></div>`;
                            html += `</div>`;
                            html += `<div style="display: flex; align-items: center; gap: 0.5rem;">`;
                            html += `<span style="font-size: 0.85rem; color: #6b7280;">Gönderilecek:</span>`;
                            html += `<button onclick="sepetMiktarDegistir('${sepetKey}', ${idx}, -1)" style="width: 32px; height: 32px; border: 1px solid #d1d5db; background: #fff; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">-</button>`;
                            html += `<input type="number" id="sent_${sepetKey}_${idx}" value="${send.toFixed(2)}" min="0" max="${max.toFixed(2)}" step="0.01" onchange="sepetMiktarInput('${sepetKey}', ${idx}, ${max}, this.value)" style="width: 80px; padding: 0.25rem; text-align: center; border: 1px solid #d1d5db; border-radius: 6px; font-weight: 600; font-size: 14px;">`;
                            html += `<button onclick="sepetMiktarDegistir('${sepetKey}', ${idx}, 1)" style="width: 32px; height: 32px; border: 1px solid #d1d5db; background: #fff; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">+</button>`;
                            html += `<span style="font-size: 0.85rem; color: #6b7280;">${uomCode}</span>`;
                            html += `</div>`;
                            html += `</div>`;
                        });
                    }
                    html += `</div>`;
                }
                sepetContent.innerHTML = html;
            }
        }
        
        // Miktar değiştir (+/- butonları için) - Artık sepetKey kullanıyor
        function sepetMiktarDegistir(sepetKey, idx, delta) {
            const t = sepet[sepetKey];
            if (!t || !t.lines[idx]) return;
            
            const line = t.lines[idx];
            const req = parseFloat(line.RequestedQty || 0);
            const max = req;
            
            let yeni = parseFloat(line.SentQty || 0) + delta;
            if (yeni < 0) yeni = 0;
            if (yeni > max) yeni = max;
            
            line.SentQty = yeni;
            sepetGuncelle();
        }
        
        // Miktar güncelle (input için) - Artık sepetKey kullanıyor
        function sepetMiktarInput(sepetKey, idx, max, value) {
            const t = sepet[sepetKey];
            if (!t || !t.lines[idx]) return;
            
            let v = parseFloat(value) || 0;
            if (v < 0) v = 0;
            if (v > max) v = max;
            
            t.lines[idx].SentQty = v;
            sepetGuncelle();
        }
        
        // Debug bilgilerini göster
        function showDebugInfo(docEntry, debugInfo, isSuccess) {
            // Debug paneli oluştur veya güncelle
            let debugPanel = document.getElementById('debugPanel');
            if (!debugPanel) {
                debugPanel = document.createElement('div');
                debugPanel.id = 'debugPanel';
                debugPanel.style.cssText = 'position: fixed; bottom: 20px; right: 20px; width: 600px; max-height: 80vh; background: #fff; border: 2px solid #3b82f6; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); z-index: 10000; overflow: hidden; display: none;';
                document.body.appendChild(debugPanel);
            }
            
            const header = document.createElement('div');
            header.style.cssText = 'background: #3b82f6; color: white; padding: 12px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; cursor: pointer;';
            header.innerHTML = `
                <span>🔍 Debug Bilgileri - Transfer ${docEntry}</span>
                <button onclick="document.getElementById('debugPanel').style.display='none'" style="background: transparent; border: none; color: white; font-size: 18px; cursor: pointer;">✕</button>
            `;
            
            const content = document.createElement('div');
            content.style.cssText = 'padding: 16px; max-height: calc(80vh - 60px); overflow-y: auto; font-family: monospace; font-size: 12px;';
            
            let html = '<div style="margin-bottom: 16px;">';
            html += `<div style="color: ${isSuccess ? '#10b981' : '#ef4444'}; font-weight: 600; margin-bottom: 8px;">Durum: ${isSuccess ? '✅ Başarılı' : '❌ Başarısız'}</div>`;
            html += `<div style="color: #6b7280; margin-bottom: 12px;">Zaman: ${debugInfo.timestamp || 'N/A'}</div>`;
            html += '</div>';
            
            // Gönderilen Veriler
            html += '<div style="margin-bottom: 16px; padding: 12px; background: #f3f4f6; border-radius: 6px;">';
            html += '<div style="font-weight: 600; margin-bottom: 8px; color: #1e40af;">📤 Gönderilen Veriler:</div>';
            html += `<div style="margin-bottom: 8px;"><strong>DocEntry:</strong> ${debugInfo.docEntry}</div>`;
            html += `<div style="margin-bottom: 8px;"><strong>FromWarehouse:</strong> ${debugInfo.fromWarehouse || 'N/A'}</div>`;
            html += `<div style="margin-bottom: 8px;"><strong>ToWarehouse:</strong> ${debugInfo.toWarehouse || 'N/A'}</div>`;
            html += '<details style="margin-top: 8px;"><summary style="cursor: pointer; font-weight: 600;">Cart Lines (Sepet)</summary><pre style="background: #fff; padding: 8px; border-radius: 4px; margin-top: 4px; overflow-x: auto;">' + JSON.stringify(debugInfo.cartLines || [], null, 2) + '</pre></details>';
            html += '<details style="margin-top: 8px;"><summary style="cursor: pointer; font-weight: 600;">Request Lines (View)</summary><pre style="background: #fff; padding: 8px; border-radius: 4px; margin-top: 4px; overflow-x: auto;">' + JSON.stringify(debugInfo.requestLines || [], null, 2) + '</pre></details>';
            html += '<details style="margin-top: 8px;"><summary style="cursor: pointer; font-weight: 600;">StockTransferLines (Temizlenmeden Önce)</summary><pre style="background: #fff; padding: 8px; border-radius: 4px; margin-top: 4px; overflow-x: auto;">' + JSON.stringify(debugInfo.stockTransferLines_before_clean || [], null, 2) + '</pre></details>';
            html += '<details style="margin-top: 8px;"><summary style="cursor: pointer; font-weight: 600; color: #10b981;">✅ cleanStockTransferLines (SAP\'ye Giden)</summary><pre style="background: #fff; padding: 8px; border-radius: 4px; margin-top: 4px; overflow-x: auto;">' + JSON.stringify(debugInfo.cleanStockTransferLines || [], null, 2) + '</pre></details>';
            html += '<details style="margin-top: 8px;"><summary style="cursor: pointer; font-weight: 600; color: #1e40af;">📦 StockTransfer Payload (Tam)</summary><pre style="background: #fff; padding: 8px; border-radius: 4px; margin-top: 4px; overflow-x: auto;">' + JSON.stringify(debugInfo.stockTransferPayload || {}, null, 2) + '</pre></details>';
            html += '</div>';
            
            // Gelen Yanıt
            html += '<div style="margin-bottom: 16px; padding: 12px; background: #fef3c7; border-radius: 6px;">';
            html += '<div style="font-weight: 600; margin-bottom: 8px; color: #d97706;">📥 SAP Response:</div>';
            html += `<div style="margin-bottom: 8px;"><strong>HTTP Status:</strong> ${debugInfo.stockTransferResponse?.status || 'N/A'}</div>`;
            html += '<details style="margin-top: 8px;"><summary style="cursor: pointer; font-weight: 600;">Response Body</summary><pre style="background: #fff; padding: 8px; border-radius: 4px; margin-top: 4px; overflow-x: auto;">' + JSON.stringify(debugInfo.stockTransferResponse?.response || {}, null, 2) + '</pre></details>';
            if (debugInfo.stockTransferResponse?.error) {
                html += '<div style="margin-top: 8px; padding: 8px; background: #fee2e2; border-radius: 4px; color: #dc2626;"><strong>Hata:</strong> ' + JSON.stringify(debugInfo.stockTransferResponse.error, null, 2) + '</div>';
            }
            html += '</div>';
            
            content.innerHTML = html;
            
            // Panel içeriğini güncelle
            debugPanel.innerHTML = '';
            debugPanel.appendChild(header);
            debugPanel.appendChild(content);
            debugPanel.style.display = 'block';
            
            // Header'a tıklanınca aç/kapat
            header.onclick = function(e) {
                if (e.target.tagName !== 'BUTTON') {
                    content.style.display = content.style.display === 'none' ? 'block' : 'none';
                }
            };
        }
        
        // Toplu onayla
        function sepetOnayla() {
            const count = Object.keys(sepet).length;
            if (count === 0) {
                alert('Sepet boş!');
                    return;
                }

            
            if (!confirm(`${count} transfer onaylanacak. Devam etmek istiyor musunuz?`)) {
                return;
            }
            
            let successCount = 0;
            let failedCount = 0;
            const promises = [];
            
            for (const [sepetKey, transfer] of Object.entries(sepet)) {
                // Lines'ı BaseQty ile çarpılmış haliyle hazırla (SAP'ye gönderilecek format)
                const sapLines = transfer.lines.map(line => {
                    const baseQty = parseFloat(line.BaseQty || 1.0);
                    const sentQty = parseFloat(line.SentQty || 0);
                    // SAP'ye giden miktar = kullanıcı miktarı × BaseQty
                    const sapQuantity = sentQty * baseQty;
                    
                    return {
                        ...line,
                        Quantity: sapQuantity, // SAP'ye giden miktar
                        _SentQty: sentQty // Kullanıcı miktarı (orijinal)
                    };
                });
                
                const promise = fetch('Transferler-Onayla.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        docEntry: transfer.docEntry,
                        itemCode: transfer.itemCode,
                        action: 'approve',
                        lines: JSON.stringify(sapLines)
                    })
                })
                .then(response => response.json())
                .then(data => {
                    // Debug bilgilerini göster
                    if (data.debug) {
                        showDebugInfo(transfer.docEntry, data.debug, data.success);
                    }
                    
                    if (data.success) {
                        successCount++;
                    } else {
                        failedCount++;
                        console.error('Onaylama hatası:', transfer.docEntry, transfer.itemCode, data.message);
                    }
                })
                .catch(error => {
                    failedCount++;
                    console.error('Onaylama hatası:', transfer.docEntry, transfer.itemCode, error);
                });
                
                promises.push(promise);
            }
            
            Promise.all(promises).then(() => {
                if (failedCount === 0) {
                    alert(`Tüm transferler onaylandı! (${successCount} adet)`);
                    window.location.reload();
                } else {
                    alert(`${successCount} transfer onaylandı, ${failedCount} transfer onaylanamadı.`);
                    if (successCount > 0) {
                        window.location.reload();
                    }
                }
            });
        }
        
        // Sayfa yüklendiğinde
        document.addEventListener('DOMContentLoaded', function() {
            // İlk güncelleme
            sepetGuncelle();
            
            // Checkbox listener'ları ekle
            const checkboxes = document.querySelectorAll('input.transfer-checkbox');
            checkboxes.forEach((cb) => {
                cb.addEventListener('change', function(e) {
                    e.stopPropagation();
                    checkboxChanged(this);
                });
            });
            
            // SelectAll listener
            const selectAll = document.getElementById('selectAll');
            if (selectAll) {
                selectAll.addEventListener('change', function(e) {
                    e.stopPropagation();
                    toggleSelectAll();
                });
            }
            });
        </script>


    <script>
        function applyFilters() {
            const status = document.getElementById('filterStatus').value;
            const startDate = document.getElementById('start-date').value;
            const endDate = document.getElementById('end-date').value;
            const viewType = '<?= $viewType ?>';
            const entriesPerPage = document.getElementById('entriesPerPage')?.value || '25';
            const search = document.getElementById('tableSearch')?.value || '';
            
            const params = new URLSearchParams();
            params.append('view', viewType);
            params.append('page', '1'); // entries değiştiğinde ilk sayfaya dön
            if (status) params.append('status', status);
            if (startDate) params.append('start_date', startDate);
            if (endDate) params.append('end_date', endDate);
            if (entriesPerPage) params.append('entries', entriesPerPage);
            if (search) params.append('search', search);
            
            window.location.href = 'Transferler.php?' + params.toString();
        }

        function performSearch() {
            // Arama parametresini URL'e ekle ve sayfayı yenile
            applyFilters();
        }
        
        // Sayfa yüklendiğinde kayma animasyonunu tetikle
        document.addEventListener('DOMContentLoaded', function() {
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                // Sayfa yüklendiğinde sağdan giriş animasyonu
                mainContent.style.animation = 'slideInFromRight 0.4s ease-out';
            }
        });
    </script>
</body>
</html>
               
