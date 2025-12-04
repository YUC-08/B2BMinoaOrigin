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
$branch = $_SESSION["WhsCode"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// Herkes normal kullanıcı gibi çalışacak (anadepo kullanıcı mantığı kaldırıldı)
$isAnadepoUser = false;

// FromWarehouse sorgusu (ana depo)
$fromWarehouseFilter = "U_ASB2B_FATH eq 'Y' and U_AS_OWNR eq '{$uAsOwnr}'";
$fromWarehouseQuery = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=" . urlencode($fromWarehouseFilter);
$fromWarehouseData = $sap->get($fromWarehouseQuery);
$fromWarehouses = $fromWarehouseData['response']['value'] ?? [];
$fromWarehouse = !empty($fromWarehouses) ? $fromWarehouses[0]['WarehouseCode'] : null;
$fromWarehouseName = !empty($fromWarehouses) ? ($fromWarehouses[0]['WarehouseName'] ?? null) : null;
$fromWarehouseNotFound = ($fromWarehouseData['status'] ?? 0) == 200 && empty($fromWarehouses);

// ToWarehouse sorgusu (kullanıcının şube sevkiyat deposu)
$toWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '2'";
$toWarehouseQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($toWarehouseFilter);
$toWarehouseData = $sap->get($toWarehouseQuery);
$toWarehouses = $toWarehouseData['response']['value'] ?? [];
$toWarehouse = !empty($toWarehouses) ? $toWarehouses[0]['WarehouseCode'] : null;
$toWarehouseNotFound = ($toWarehouseData['status'] ?? 0) == 200 && empty($toWarehouses);

// Debug: Session ve sorgu bilgileri
if (isset($_GET['debug'])) {
    error_log("[ANADEPO DEBUG] U_AS_OWNR: " . $uAsOwnr);
    error_log("[ANADEPO DEBUG] Branch (WhsCode): " . ($branch ?: 'EMPTY'));
    error_log("[ANADEPO DEBUG] FromWarehouse: " . ($fromWarehouse ?: 'NOT FOUND'));
    error_log("[ANADEPO DEBUG] ToWarehouse: " . ($toWarehouse ?: 'NOT FOUND'));
}

// Hata kontrolü ve bilgilendirme
$errorMsg = '';
$infoMsg = '';

if (empty($fromWarehouse)) {
    if ($fromWarehouseNotFound) {
        // SAP'de yok - sessizce geç, sadece bilgi mesajı göster
        $infoMsg = "<strong>Bilgi:</strong> {$uAsOwnr} sektörü için ana depo (FromWarehouse) SAP'de tanımlı değil. Bu sektör için Ana Depo Tedarik listesi boş görünecektir.";
    } else {
        // Bağlantı hatası veya başka bir sorun
        $errorMsg = "FromWarehouse sorgusu başarısız!";
        if (isset($fromWarehouseData['status']) && $fromWarehouseData['status'] != 200) {
            $errorMsg .= " (HTTP " . $fromWarehouseData['status'] . ")";
        }
    }
}

if (empty($toWarehouse)) {
    if ($toWarehouseNotFound) {
        // SAP'de yok - bilgi mesajına ekle
        if (empty($infoMsg)) {
            $infoMsg = "<strong>Bilgi:</strong> {$uAsOwnr} sektörü ve {$branch} şubesi için sevkiyat deposu (ToWarehouse) SAP'de tanımlı değil.";
        } else {
            $infoMsg .= "<br><strong>Bilgi:</strong> {$uAsOwnr} sektörü ve {$branch} şubesi için sevkiyat deposu (ToWarehouse) SAP'de tanımlı değil.";
        }
    } else {
        // Bağlantı hatası veya başka bir sorun
        $errorMsg .= ($errorMsg ? '<br>' : '') . "ToWarehouse sorgusu başarısız!";
        if (isset($toWarehouseData['status']) && $toWarehouseData['status'] != 200) {
            $errorMsg .= " (HTTP " . $toWarehouseData['status'] . ")";
        }
        if (empty($branch)) {
            $errorMsg .= " (Branch/WhsCode session'da yok!)";
        }
    }
}

// Filtreler (GET parametrelerinden)
$filterStatus = $_GET['status'] ?? '';
$filterStartDate = $_GET['start_date'] ?? '';
$filterEndDate = $_GET['end_date'] ?? '';

// InventoryTransferRequests sorgusu (herkes için aynı: FromWarehouse + ToWarehouse)
$data = ['response' => ['value' => []]];
// Query çalıştırma koşulu: Hata yoksa VE FromWarehouse VE ToWarehouse varsa
if (!$errorMsg && $fromWarehouse && $toWarehouse) {
    // Herkes için: FromWarehouse ve ToWarehouse ile filter
    // U_ASB2B_TYPE filtresi kaldırıldı - tüm kayıtlar gösterilecek (eski ve yeni)
    $transferFilter = "U_AS_OWNR eq '{$uAsOwnr}' and FromWarehouse eq '{$fromWarehouse}' and ToWarehouse eq '{$toWarehouse}'"; 
    
    // Status filtresi
    if (!empty($filterStatus)) {
        $transferFilter .= " and U_ASB2B_STATUS eq '{$filterStatus}'";
    }
    
    // Tarih filtreleri
    if (!empty($filterStartDate)) {
        $startDateFormatted = date('Y-m-d', strtotime($filterStartDate));
        $transferFilter .= " and DocDate ge '{$startDateFormatted}'";
    }
    if (!empty($filterEndDate)) {
        $endDateFormatted = date('Y-m-d', strtotime($filterEndDate));
        $transferFilter .= " and DocDate le '{$endDateFormatted}'";
    }
    
    $selectValue = "DocEntry,DocDate,DueDate,U_ASB2B_NumAtCard,U_ASB2B_STATUS,U_ASWHSF";
    $filterEncoded = urlencode($transferFilter);
    $orderByEncoded = urlencode("DocEntry desc");
    $transferQuery = "InventoryTransferRequests?\$select=" . urlencode($selectValue) . "&\$filter=" . $filterEncoded . "&\$orderby=" . $orderByEncoded . "&\$top=100";
    
    // Debug
    error_log("[ANADEPO] U_AS_OWNR: " . $uAsOwnr);
    error_log("[ANADEPO] Branch: " . $branch);
    error_log("[ANADEPO] FromWarehouse: " . $fromWarehouse);
    error_log("[ANADEPO] ToWarehouse: " . $toWarehouse);
    error_log("[ANADEPO] Transfer Query: " . $transferQuery);
    
    $data = $sap->get($transferQuery);
    
    error_log("[ANADEPO] Response Status: " . ($data['status'] ?? 'NO STATUS'));
    error_log("[ANADEPO] Response Count: " . (count($data['response']['value'] ?? [])));
    
    // Response Status 0 ise, cURL hatası veya timeout olabilir
    if (($data['status'] ?? 0) == 0) {
        error_log("[ANADEPO] ERROR: Response status is 0 - Possible cURL error or timeout");
        if (isset($data['response']['raw'])) {
            error_log("[ANADEPO] Raw Response: " . substr($data['response']['raw'], 0, 500));
        }
    }
    
    if (!empty($data['response']['value'])) {
        error_log("[ANADEPO] First Record: " . json_encode($data['response']['value'][0] ?? []));
    }
} else {
    error_log("[ANADEPO] Query not executed - errorMsg: " . ($errorMsg ?: 'none') . ", fromWarehouse: " . ($fromWarehouse ?: 'empty') . ", toWarehouse: " . ($toWarehouse ?: 'empty'));
}

$allRows = $data['response']['value'] ?? [];

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

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}
?> 


<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ana Depo Talepleri - CREMMAVERSE</title>
    <link rel="stylesheet" href="styles.css">
    <style>
/* Modern mavi-beyaz tema ile yeni tasarım */
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

/* Main content - adjusted for sidebar */
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
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}

/* Alert Styles */
.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 24px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 14px;
    line-height: 1.5;
}

.alert-warning {
    background: #fef3c7;
    border: 1px solid #fbbf24;
    color: #92400e;
}

.alert-info {
    background: #dbeafe;
    border: 1px solid #3b82f6;
    color: #1e40af;
}

.alert-success {
    background: #d1fae5;
    border: 1px solid #10b981;
    color: #065f46;
}

/* Card Styles */
.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible; /* Changed from hidden to visible for dropdown */
}

/* Filter Section */
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
    font-weight: 600;
    color: #1e3a8a;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Single Select Dropdown */
.single-select-container {
    position: relative;
    width: 100%;
}

.single-select-input {
    display: flex;
    align-items: center;
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    min-height: 42px;
    transition: all 0.2s ease;
}

.single-select-input:hover {
    border-color: #3b82f6;
}

.single-select-input.active {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.single-select-input input {
    border: none;
    outline: none;
    flex: 1;
    background: transparent;
    cursor: pointer;
    font-size: 14px;
    color: #2c3e50;
}

.dropdown-arrow {
    transition: transform 0.2s;
    color: #6b7280;
    font-size: 12px;
}

.single-select-input.active .dropdown-arrow {
    transform: rotate(180deg);
}

/* Increased z-index to 9999 to ensure dropdown appears above all elements */
.single-select-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #3b82f6;
    border-top: none;
    border-radius: 0 0 8px 8px;
    max-height: 240px;
    overflow-y: auto;
    z-index: 9999;
    display: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    margin-top: -2px;
}

.single-select-dropdown.show {
    display: block;
}

.single-select-option {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    transition: background 0.15s ease;
}

.single-select-option:hover {
    background: #f8fafc;
}

.single-select-option.selected {
    background: #3b82f6;
    color: white;
    font-weight: 500;
}

.single-select-option:last-child {
    border-bottom: none;
}

/* Date Input */
.filter-group input[type="date"] {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: white;
}

.filter-group input[type="date"]:hover {
    border-color: #3b82f6;
}

.filter-group input[type="date"]:focus {
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

/* Table Styles */
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

/* Status Badges */
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

/* Button Styles */
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

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    padding: 24px;
    font-size: 14px;
    color: #6b7280;
}

/* Responsive */
@media (max-width: 768px) {
    .content-wrapper {
        padding: 16px 20px;
    }
    
    .page-header {
        height: auto;
        min-height: 80px;
        padding: 16px 20px;
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .filter-section {
        grid-template-columns: 1fr;
    }
    
    .data-table {
        font-size: 12px;
    }
    
    .data-table th,
    .data-table td {
        padding: 12px 10px;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Ana Depo Talepleri</h2>
            <button class="btn btn-primary" onclick="window.location.href='AnaDepoSO.php'">+ Yeni Talep Oluştur</button>
        </header>

        <div class="content-wrapper">
            <?php if ($errorMsg): ?>
                <div class="alert alert-warning">
                    <strong>Uyarı:</strong> <?= $errorMsg ?>
                </div>
            <?php endif; ?>
            
            <?php if ($infoMsg): ?>
                <div class="alert alert-info">
                    <?= $infoMsg ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['debug'])): ?>
                <div class="alert alert-info" style="font-family: monospace; font-size: 12px;">
                    <strong>Debug Bilgileri:</strong><br>
                    U_AS_OWNR: <?= htmlspecialchars($uAsOwnr) ?><br>
                    Branch (WhsCode): <?= htmlspecialchars($branch ?: 'BOŞ') ?><br>
                    FromWarehouse: <?= htmlspecialchars($fromWarehouse ?? 'BULUNAMADI') ?><br>
                    ToWarehouse: <?= htmlspecialchars($toWarehouse ?? 'BULUNAMADI') ?><br>
                    ErrorMsg: <?= htmlspecialchars($errorMsg ?: 'Yok') ?><br>
                    <br>
                    <strong>FromWarehouse Query:</strong><br>
                    <?= htmlspecialchars($fromWarehouseQuery ?? 'Query oluşturulmadı') ?><br>
                    FromWarehouse Status: <?= isset($fromWarehouseData['status']) ? $fromWarehouseData['status'] : 'YOK' ?><br>
                    <br>
                    <strong>ToWarehouse Query:</strong><br>
                    <?= htmlspecialchars($toWarehouseQuery ?? 'Query oluşturulmadı') ?><br>
                    ToWarehouse Status: <?= isset($toWarehouseData['status']) ? $toWarehouseData['status'] : 'YOK' ?><br>
                    <br>
                    Query Çalıştırıldı: <?= (!$errorMsg && $fromWarehouse) ? 'EVET' : 'HAYIR' ?><br>
                    Response Status: <?= isset($data['status']) ? $data['status'] : 'YOK' ?><br>
                    <?php if (isset($data['response']['error'])): ?>
                        Response Error: <?= htmlspecialchars($data['response']['error']) ?><br>
                    <?php endif; ?>
                    Kayıt Sayısı: <?= count($rows) ?><br>
                    <br>
                    <strong>Full Query:</strong><br>
                    <?= htmlspecialchars($transferQuery ?? 'Query oluşturulmadı') ?><br>
                    <br>
                    <strong>Full URL (baseUrl + endpoint):</strong><br>
                    <?= htmlspecialchars('https://192.168.54.185:50000/b1s/v2/' . ($transferQuery ?? '')) ?><br>
                    <br>
                    <strong>Test için Insomnia'da kullanın:</strong><br>
                    <code style="font-size:11px; word-break:break-all;"><?= htmlspecialchars('https://192.168.54.185:50000/b1s/v2/InventoryTransferRequests?$filter=U_AS_OWNR eq \'KT\' and FromWarehouse eq \'KT-00\' and ToWarehouse eq \'100-KT-1\'&$orderby=DocEntry desc&$top=25') ?></code>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'ok'): ?>
                <div id="successMsg" class="alert alert-success" style="position: relative;">
                    <strong>Başarılı:</strong> Teslim alma işlemi tamamlandı.
                    <button onclick="document.getElementById('successMsg').style.display='none';" style="position: absolute; right: 10px; top: 10px; background: none; border: none; color: #065f46; font-size: 18px; cursor: pointer; font-weight: bold;">×</button>
                </div>
                <script>
                    setTimeout(function() {
                        const msg = document.getElementById('successMsg');
                        if (msg) {
                            msg.style.transition = 'opacity 0.5s';
                            msg.style.opacity = '0';
                            setTimeout(function() {
                                msg.style.display = 'none';
                            }, 500);
                        }
                    }, 5000);
                </script>
            <?php endif; ?>

            <section class="card">
                <div class="filter-section">
                    <div class="filter-group">
                        <label>Talep Durumu</label>
                        <div class="single-select-container">
                            <div class="single-select-input" onclick="toggleDropdown('status')">
                                <input type="text" id="filterStatus" value="<?= $filterStatus ? getStatusText($filterStatus) : 'Tümü' ?>" placeholder="Seçiniz..." readonly>
                                <span class="dropdown-arrow">▼</span>
                            </div>
                            <div class="single-select-dropdown" id="statusDropdown">
                                <div class="single-select-option <?= empty($filterStatus) ? 'selected' : '' ?>" data-value="" onclick="selectStatus('')">Tümü</div>
                                <div class="single-select-option <?= $filterStatus === '1' ? 'selected' : '' ?>" data-value="1" onclick="selectStatus('1')">Onay Bekliyor</div>
                                <div class="single-select-option <?= $filterStatus === '2' ? 'selected' : '' ?>" data-value="2" onclick="selectStatus('2')">Hazırlanıyor</div>
                                <div class="single-select-option <?= $filterStatus === '3' ? 'selected' : '' ?>" data-value="3" onclick="selectStatus('3')">Sevk Edildi</div>
                                <div class="single-select-option <?= $filterStatus === '4' ? 'selected' : '' ?>" data-value="4" onclick="selectStatus('4')">Tamamlandı</div>
                                <div class="single-select-option <?= $filterStatus === '5' ? 'selected' : '' ?>" data-value="5" onclick="selectStatus('5')">İptal Edildi</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label>Başlangıç Tarihi</label>
                        <input type="date" id="start-date" value="<?= htmlspecialchars($filterStartDate) ?>" onblur="applyFilters()">
                    </div>
                    
                    <div class="filter-group">
                        <label>Bitiş Tarihi</label>
                        <input type="date" id="end-date" value="<?= htmlspecialchars($filterEndDate) ?>" onblur="applyFilters()">
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="table-controls">
                    <div class="show-entries">
                        Sayfada 
                        <select class="entries-select" id="entriesPerPage" onchange="applyFilters()">
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="75">75</option>
                        </select>
                        kayıt göster
                    </div>
                    <div class="search-box">
                        <input type="text" class="search-input" id="tableSearch" placeholder="Ara..." onkeyup="if(event.key==='Enter') performSearch()">
                        <button class="btn btn-secondary" onclick="performSearch()">🔍</button>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Talep No</th>
                            <th>Talep Tarihi</th>
                            <th>Vade Tarihi</th>
                            <th>Teslimat Belge No</th>
                            <th>Gönderen</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                     <?php
// Sayfalama
$entriesPerPage = intval($_GET['entries'] ?? 25);
$currentPage = intval($_GET['page'] ?? 1);
$totalRows = count($allRows);
$totalPages = ceil($totalRows / $entriesPerPage);
$startIndex = ($currentPage - 1) * $entriesPerPage;
$rows = array_slice($allRows, $startIndex, $entriesPerPage);

if (!empty($rows)) {
    foreach ($rows as $row) {
        $status = $row['U_ASB2B_STATUS'] ?? '1';
        $statusText = getStatusText($status);
        $statusClass = getStatusClass($status);
        $docEntry = $row['DocEntry'] ?? '';
        $docDate = formatDate($row['DocDate'] ?? '');
        $dueDate = formatDate($row['DueDate'] ?? '');
        $numAtCard = $row['U_ASB2B_NumAtCard'] ?? '-';
        $aliciSube = $row['U_ASWHSF'] ?? '-'; 

        echo "<tr>
                <td>{$docEntry}</td>
                <td>{$docDate}</td>
                <td>{$dueDate}</td>
                <td>{$numAtCard}</td>
                <td>{$aliciSube}</td>
                <td><span class='status-badge {$statusClass}'>{$statusText}</span></td>
                <td>
                    <a href='AnaDepo-Detay.php?doc={$docEntry}'>
                        <button class='btn-icon btn-view'>👁️ Detay</button>
                    </a>";
        
        // Teslim Al butonu (sadece status=3 için, herkes için)
        if ($status === '3') {
            echo "    <a href='anadepo_teslim_al.php?doc={$docEntry}' style='margin-left:5px;'>
                        <button class='btn-icon btn-primary'>✓ Teslim Al</button>
                    </a>";
        }
        
        echo "    </td>
              </tr>";
    }
} else {
    $colspan = 7;
    
    // Boş liste mesajı
    if ($errorMsg) {
        // Sistem hatası varsa
        $emptyMsg = 'Depo bilgileri eksik olduğu için kayıt bulunamadı.';
    } else if ($infoMsg) {
        // SAP'de depo tanımlı değilse
        $emptyMsg = 'Bu sektör/şube için kayıt bulunamadı. SAP\'de uygun depo tanımlı değil olabilir.';
    } else if (!isset($data['status'])) {
        // Query çalıştırılmadıysa
        $emptyMsg = 'Sorgu çalıştırılamadı. Depo bilgileri kontrol edilmelidir.';
    } else {
        // Query çalıştı ama boş döndü
        $emptyMsg = 'Kayıt bulunamadı.';
        if (isset($data['status'])) {
            $emptyMsg .= ' (HTTP Status: ' . $data['status'] . ')';
        }
    }
    
    echo "<tr><td colspan='{$colspan}' style='text-align:center;color:#888;padding:20px;'>" . $emptyMsg . "</td></tr>"; 
}
?>
                    </tbody>
                </table>
                
                <!-- Sayfalama -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <button class="btn btn-secondary" onclick="changePage(<?= $currentPage - 1 ?>)" <?= $currentPage <= 1 ? 'disabled' : '' ?>>← Önceki</button>
                        <span>Sayfa <?= $currentPage ?> / <?= $totalPages ?> (Toplam <?= $totalRows ?> kayıt)</span>
                        <button class="btn btn-secondary" onclick="changePage(<?= $currentPage + 1 ?>)" <?= $currentPage >= $totalPages ? 'disabled' : '' ?>>Sonraki →</button>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
let selectedStatus = '<?= htmlspecialchars($filterStatus) ?>';

function toggleDropdown(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    const input = document.querySelector(`#filter${type.charAt(0).toUpperCase() + type.slice(1)}`).parentElement;
    const isOpen = dropdown.classList.contains('show');
    
    // Close all dropdowns
    document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
    document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
    
    if (!isOpen) {
        dropdown.classList.add('show');
        input.classList.add('active');
    }
}

function selectStatus(value) {
    selectedStatus = value;
    const statusText = document.querySelector(`#statusDropdown .single-select-option[data-value="${value}"]`).textContent;
    document.getElementById('filterStatus').value = statusText;
    document.querySelectorAll('#statusDropdown .single-select-option').forEach(opt => opt.classList.remove('selected'));
    document.querySelector(`#statusDropdown .single-select-option[data-value="${value}"]`).classList.add('selected');
    applyFilters();
}

function applyFilters() {
    // Tarih input'larından önce değerleri al (input focus'ta olabilir)
    const status = selectedStatus;
    const startDateInput = document.getElementById('start-date');
    const endDateInput = document.getElementById('end-date');
    const startDate = startDateInput ? startDateInput.value : '';
    const endDate = endDateInput ? endDateInput.value : '';
    const entries = document.getElementById('entriesPerPage').value;
    
    const params = new URLSearchParams();
    if (status) params.append('status', status);
    if (startDate) params.append('start_date', startDate);
    if (endDate) params.append('end_date', endDate);
    if (entries) params.append('entries', entries);
    
    window.location.href = 'AnaDepo.php' + (params.toString() ? '?' + params.toString() : '');
}

function changePage(page) {
    if (page < 1) return;
    
    const status = '<?= htmlspecialchars($filterStatus) ?>';
    const startDate = '<?= htmlspecialchars($filterStartDate) ?>';
    const endDate = '<?= htmlspecialchars($filterEndDate) ?>';
    const entries = document.getElementById('entriesPerPage').value;
    
    const params = new URLSearchParams();
    if (status) params.append('status', status);
    if (startDate) params.append('start_date', startDate);
    if (endDate) params.append('end_date', endDate);
    if (entries) params.append('entries', entries);
    params.append('page', page);
    
    window.location.href = 'AnaDepo.php?' + params.toString();
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.single-select-container')) {
        document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
    }
});

// Genel arama fonksiyonu - tüm kolonlarda serbest text search
function performSearch() {
    const searchInput = document.getElementById('tableSearch');
    const searchTerm = searchInput.value.toLowerCase().trim();
    const tableBody = document.querySelector('.data-table tbody');
    const rows = tableBody.querySelectorAll('tr');
    
    if (!tableBody || !rows) return;
    
    let visibleCount = 0;
    
    rows.forEach(row => {
        // Tüm hücrelerde ara (Transfer No, Talep Tarihi, Vade Tarihi, Teslimat Belge No, Gönderen, Durum)
        const cells = row.querySelectorAll('td');
        let found = false;
        
        if (searchTerm === '') {
            // Arama boşsa tüm satırları göster
            row.style.display = '';
            visibleCount++;
        } else {
            cells.forEach(cell => {
                const cellText = cell.textContent.toLowerCase();
                if (cellText.includes(searchTerm)) {
                    found = true;
                }
            });
            
            if (found) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }
    });
    
    // Eğer hiç sonuç yoksa mesaj göster
    let noResultsRow = tableBody.querySelector('.no-results-message');
    if (searchTerm !== '' && visibleCount === 0) {
        if (!noResultsRow) {
            noResultsRow = document.createElement('tr');
            noResultsRow.className = 'no-results-message';
            noResultsRow.innerHTML = '<td colspan="7" style="text-align: center; padding: 20px; color: #888;">Sonuç bulunamadı.</td>';
            tableBody.appendChild(noResultsRow);
        }
        noResultsRow.style.display = '';
    } else {
        if (noResultsRow) {
            noResultsRow.style.display = 'none';
        }
    }
}

// Arama input'una real-time arama ekle (opsiyonel - her tuş vuruşunda arama yapar)
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('tableSearch');
    if (searchInput) {
        // Her tuş vuruşunda arama yap (debounce olmadan)
        searchInput.addEventListener('input', function() {
            performSearch();
        });
    }
});
    </script>
    <script src="script.js"></script>
</body>
</html>