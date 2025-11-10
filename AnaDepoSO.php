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
$userName = $_SESSION["UserName"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// FromWarehouse ve ToWarehouse sorguları
$fromWarehouseFilter = "U_ASB2B_FATH eq 'Y' and U_AS_OWNR eq '{$uAsOwnr}'";
$fromWarehouseQuery = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=" . urlencode($fromWarehouseFilter);
$fromWarehouseData = $sap->get($fromWarehouseQuery);
$fromWarehouses = $fromWarehouseData['response']['value'] ?? [];
$fromWarehouse = !empty($fromWarehouses) ? $fromWarehouses[0]['WarehouseCode'] : null;
$fromWhsName = !empty($fromWarehouses) ? ($fromWarehouses[0]['WarehouseName'] ?? '') : '';

$toWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '2'";
$toWarehouseQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($toWarehouseFilter);
$toWarehouseData = $sap->get($toWarehouseQuery);
$toWarehouses = $toWarehouseData['response']['value'] ?? [];
$toWarehouse = !empty($toWarehouses) ? $toWarehouses[0]['WarehouseCode'] : null;

// POST işlemi: InventoryTransferRequests oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_request') {
    header('Content-Type: application/json');
    
    $selectedItems = json_decode($_POST['items'] ?? '[]', true);
    
    if (empty($selectedItems)) {
        echo json_encode(['success' => false, 'message' => 'Lütfen en az bir kalem seçin!']);
        exit;
    }
    
    if (empty($fromWarehouse) || empty($toWarehouse)) {
        echo json_encode(['success' => false, 'message' => 'Depo bilgileri bulunamadı!']);
        exit;
    }
    
    $stockTransferLines = [];
    foreach ($selectedItems as $item) {
        $userQuantity = floatval($item['quantity'] ?? 0);
        if ($userQuantity > 0) {
            // BaseQty mantığı: Kullanıcının girdiği miktar × BaseQty = SAP'ye giden miktar
            $baseQty = floatval($item['baseQty'] ?? 1.0); // Default 1.0
            $sapQuantity = $userQuantity * $baseQty;
            
            // Not: U_ASB2B_OrdUom alanı StockTransferLine'da geçerli değil, bu yüzden kaldırıldı
            // BaseQty mantığı ile Quantity hesaplaması yeterli
            $stockTransferLines[] = [
                'ItemCode' => $item['itemCode'] ?? '',
                'Quantity' => $sapQuantity, // SAP'ye giden miktar = kullanıcı miktarı × BaseQty
                'FromWarehouseCode' => $fromWarehouse,
                'WarehouseCode' => $toWarehouse
            ];
        }
    }
    
    if (empty($stockTransferLines)) {
        echo json_encode(['success' => false, 'message' => 'Miktarı girilen kalem bulunamadı!']);
        exit;
    }
    
    $payload = [
        'DocDate' => date('Y-m-d'),
        'FromWarehouse' => $fromWarehouse,
        'ToWarehouse' => $toWarehouse,
        'Comments' => 'Stok nakil talebi',
        'U_ASB2B_BRAN' => $branch,
        'U_AS_OWNR' => $uAsOwnr,
        'U_ASB2B_STATUS' => '1',
        'U_ASB2B_TYPE' => 'MAIN',
        'U_ASB2B_User' => $userName,
        'StockTransferLines' => $stockTransferLines
    ];
    
    $result = $sap->post('InventoryTransferRequests', $payload);
    
    if ($result['status'] == 200 || $result['status'] == 201) {
        echo json_encode(['success' => true, 'message' => 'Talep başarıyla oluşturuldu!', 'data' => $result]);
    } else {
        $errorMsg = 'Talep oluşturulamadı: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        echo json_encode(['success' => false, 'message' => $errorMsg, 'response' => $result]);
    }
    exit;
}

// AJAX: Items listesi getir (YENİ: view.svc/ASB2B_MainWhsItem_B1SLQuery kullanılıyor)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'items') {
    header('Content-Type: application/json');
    
    // Debug log
    error_log('[ANADEPOSO] FromWhsName: ' . ($fromWhsName ?: 'EMPTY'));
    error_log('[ANADEPOSO] FromWarehouse: ' . ($fromWarehouse ?: 'EMPTY'));
    
    if (empty($fromWhsName)) {
        // Eğer FromWhsName boşsa, FromWarehouse kodunu kullanmayı dene veya hata döndür
        error_log('[ANADEPOSO] FromWhsName boş, FromWarehouse: ' . ($fromWarehouse ?: 'EMPTY'));
        echo json_encode(['data' => [], 'count' => 0, 'hasMore' => false, 'error' => 'Ana depo adı bulunamadı! FromWarehouse: ' . ($fromWarehouse ?: 'BULUNAMADI')]);
        exit;
    }
    
    $skip = intval($_GET['skip'] ?? 0);
    $top = intval($_GET['top'] ?? 25);
    $search = trim($_GET['search'] ?? '');
    $itemNames = isset($_GET['item_names']) ? json_decode($_GET['item_names'], true) : [];
    $itemGroups = isset($_GET['item_groups']) ? json_decode($_GET['item_groups'], true) : [];
    $stockStatus = trim($_GET['stock_status'] ?? '');
    
    // YENİ: view.svc/ASB2B_MainWhsItem_B1SLQuery kullanılıyor
    // FromWhsName ile filtreleme
    $fromWhsNameEscaped = str_replace("'", "''", $fromWhsName);
    $filter = "FromWhsName eq '{$fromWhsNameEscaped}'";
    
    // Kalem Tanımı filtresi (multi-select)
    if (!empty($itemNames) && is_array($itemNames)) {
        $itemNameConditions = [];
        foreach ($itemNames as $itemName) {
            $itemNameEscaped = str_replace("'", "''", $itemName);
            $itemNameConditions[] = "ItemName eq '{$itemNameEscaped}'";
        }
        if (!empty($itemNameConditions)) {
            $filter .= " and (" . implode(" or ", $itemNameConditions) . ")";
        }
    } else if (!empty($search)) {
        // Genel arama
        $searchEscaped = str_replace("'", "''", $search);
        $filter .= " and (ItemCode eq '{$searchEscaped}' or ItemName eq '{$searchEscaped}' or startswith(ItemName, '{$searchEscaped}'))";
    }
    
    // Kalem Grubu filtresi (multi-select)
    if (!empty($itemGroups) && is_array($itemGroups)) {
        $itemGroupConditions = [];
        foreach ($itemGroups as $itemGroup) {
            $itemGroupEscaped = str_replace("'", "''", $itemGroup);
            $itemGroupConditions[] = "ItemGroup eq '{$itemGroupEscaped}'";
        }
        if (!empty($itemGroupConditions)) {
            $filter .= " and (" . implode(" or ", $itemGroupConditions) . ")";
        }
    }
    
    // Stok durumu filtresi (MainQty alanı kullanılıyor)
    if (!empty($stockStatus)) {
        if ($stockStatus === 'var') {
            $filter .= " and MainQty gt 0";
        } else if ($stockStatus === 'yok') {
            $filter .= " and MainQty le 0";
        }
    }
    
    $itemsQuery = "view.svc/ASB2B_MainWhsItem_B1SLQuery?\$filter=" . urlencode($filter) . "&\$orderby=ItemCode&\$top={$top}&\$skip={$skip}";
    
    error_log('[ANADEPOSO] Query: ' . $itemsQuery);
    
    $itemsData = $sap->get($itemsQuery);
    
    error_log('[ANADEPOSO] Response Status: ' . ($itemsData['status'] ?? 'NO STATUS'));
    
    if (isset($itemsData['response']['error'])) {
        error_log('[ANADEPOSO] Error: ' . json_encode($itemsData['response']['error']));
    }
    
    $items = $itemsData['response']['value'] ?? [];
    
    error_log('[ANADEPOSO] Items count: ' . count($items));
    
    // Her item için stok bilgisini ekle (MainQty kullanılıyor)
    foreach ($items as &$item) {
        $mainQty = floatval($item['MainQty'] ?? 0);
        $item['_stock'] = $mainQty;
        $item['_hasStock'] = $mainQty > 0;
        // BaseQty ve UomCode zaten view'den geliyor
    }
    
    echo json_encode([
        'data' => $items,
        'count' => count($items),
        'hasMore' => count($items) >= $top
    ]);
    exit;
}

// AJAX: ItemNames ve ItemGroups listesi getir (filtre dropdown'ları için - YENİ: view servisi kullanılıyor)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'filter_options') {
    header('Content-Type: application/json');
    
    if (empty($fromWhsName)) {
        echo json_encode(['itemNames' => [], 'itemGroups' => []]);
        exit;
    }
    
    $fromWhsNameEscaped = str_replace("'", "''", $fromWhsName);
    $itemsQuery = "view.svc/ASB2B_MainWhsItem_B1SLQuery?\$filter=FromWhsName eq '{$fromWhsNameEscaped}'&\$select=ItemName,ItemGroup&\$top=1000";
    $itemsData = $sap->get($itemsQuery);
    $items = $itemsData['response']['value'] ?? [];
    
    $itemNames = [];
    $itemGroups = [];
    
    foreach ($items as $item) {
        if (isset($item['ItemName']) && !empty($item['ItemName'])) {
            if (!in_array($item['ItemName'], $itemNames)) {
                $itemNames[] = $item['ItemName'];
            }
        }
        
        $groupCode = $item['ItemGroup'] ?? '';
        if (!empty($groupCode) && !in_array($groupCode, $itemGroups)) {
            $itemGroups[] = $groupCode;
        }
    }
    
    sort($itemNames);
    sort($itemGroups);
    
    echo json_encode([
        'itemNames' => $itemNames,
        'itemGroups' => $itemGroups
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ana Depo Siparişi Oluştur</title>
    <link rel="stylesheet" href="styles.css">
    <style>
/* Modern mavi-beyaz tema ve layout düzenlemeleri */
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
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}


.card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 0;
}

.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.alert-warning {
    background: #fef3c7;
    border: 1px solid #f59e0b;
    color: #92400e;
}

.btn {
    padding: 0.625rem 1.25rem;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.btn-secondary {
    background: #f3f4f6;
    color: #374151;
}

.btn-secondary:hover {
    background: #e5e7eb;
}

.filter-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.25rem;
    padding: 1.5rem;
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border-radius: 12px;
    margin-bottom: 0;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-group label {
    font-weight: 600;
    color: #1e40af;
    font-size: 0.9rem;
}

.multi-select-container,
.single-select-container {
    position: relative;
    width: 100%;
}

.multi-select-input,
.single-select-input {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    padding: 10px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    min-height: 42px;
    transition: all 0.2s;
}

.multi-select-input:hover,
.single-select-input:hover {
    border-color: #3b82f6;
}

.multi-select-input.active,
.single-select-input.active {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.multi-select-input input,
.single-select-input input {
    border: none;
    outline: none;
    flex: 1;
    background: transparent;
    min-width: 120px;
    font-size: 0.95rem;
}

.multi-select-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    padding: 4px 10px;
    border-radius: 16px;
    font-size: 0.85rem;
    font-weight: 500;
}

.multi-select-tag .remove {
    cursor: pointer;
    font-weight: bold;
    font-size: 16px;
    margin-left: 4px;
    transition: opacity 0.2s;
}

.multi-select-tag .remove:hover {
    opacity: 0.7;
}

.dropdown-arrow {
    transition: transform 0.2s;
    color: #6b7280;
    font-size: 0.75rem;
}

.multi-select-input.active .dropdown-arrow,
.single-select-input.active .dropdown-arrow {
    transform: rotate(180deg);
}

.multi-select-dropdown,
.single-select-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    max-height: 280px;
    overflow-y: auto;
    z-index: 9999;
    display: none;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.multi-select-dropdown.show,
.single-select-dropdown.show {
    display: block;
}

.multi-select-dropdown-search {
    padding: 10px;
    border-bottom: 1px solid #e5e7eb;
    position: sticky;
    top: 0;
    background: white;
    z-index: 1;
}

.multi-select-dropdown-search input {
    width: 100%;
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 0.9rem;
    transition: border-color 0.2s;
}

.multi-select-dropdown-search input:focus {
    outline: none;
    border-color: #3b82f6;
}

.multi-select-option,
.single-select-option {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.15s;
    font-size: 0.9rem;
}

.multi-select-option:hover,
.single-select-option:hover {
    background: #f9fafb;
}

.multi-select-option.selected {
    background: #dbeafe;
    color: #1e40af;
    font-weight: 500;
}

.single-select-option.selected {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    font-weight: 500;
}

.table-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.25rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.show-entries {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #4b5563;
    font-size: 0.9rem;
}

.entries-select {
    padding: 0.5rem 0.75rem;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    background: white;
    font-size: 0.9rem;
    cursor: pointer;
    transition: border-color 0.2s;
}

.entries-select:focus {
    outline: none;
    border-color: #3b82f6;
}

.search-box {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.search-input {
    padding: 0.5rem 0.75rem;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    min-width: 220px;
    font-size: 0.9rem;
    transition: border-color 0.2s;
}

.search-input:focus {
    outline: none;
    border-color: #3b82f6;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.data-table thead {
    background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
    color: white;
}

.data-table th {
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.data-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.15s;
}

.data-table tbody tr:hover {
    background: #f9fafb;
}

.data-table td {
    padding: 1rem;
    color: #374151;
}

.quantity-controls {
    display: flex;
    gap: 6px;
    align-items: center;
    justify-content: center;
}

.qty-btn {
    padding: 6px 12px;
    border: 2px solid #e5e7eb;
    background: white;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 1rem;
    min-width: 36px;
    transition: all 0.2s;
    color: #374151;
}

.qty-btn:hover {
    background: #f3f4f6;
    border-color: #3b82f6;
    color: #3b82f6;
}

.qty-input {
    width: 90px;
    text-align: center;
    padding: 0.5rem;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 0.95rem;
    transition: border-color 0.2s;
}

.qty-input:focus {
    outline: none;
    border-color: #3b82f6;
}

.stock-badge {
    padding: 6px 12px;
    border-radius: 16px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-block;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stock-yes {
    background: #d1fae5;
    color: #065f46;
}

.stock-no {
    background: #fee2e2;
    color: #991b1b;
}

/* Sepet Badge */
.sepet-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: #ef4444;
    color: white;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    border: 2px solid white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.sepet-btn {
    position: relative;
}

/* Ana Layout Container */
.main-layout-container {
    display: flex;
    gap: 24px;
    transition: all 0.3s ease;
    padding: 0;
}

.main-content-left {
    flex: 1;
    transition: flex 0.3s ease;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.main-layout-container.sepet-open .main-content-left {
    flex: 1;
}

.main-content-right.sepet-panel {
    flex: 1;
    min-width: 400px;
    max-width: 500px;
    display: flex;
    flex-direction: column;
}

.main-content-right.sepet-panel .card {
    margin: 0;
}

/* Sepet Panel */
.sepet-panel {
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.sepet-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid #bfdbfe;
    background: white;
    margin-bottom: 0.75rem;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.sepet-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
}

.sepet-item-info {
    flex: 1;
}

.sepet-item-name {
    font-weight: 600;
    margin-bottom: 8px;
    color: #1e40af;
}

.sepet-item-qty {
    display: flex;
    gap: 8px;
    align-items: center;
}

.sepet-item-qty input {
    width: 90px;
    text-align: center;
    padding: 6px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 0.9rem;
}

.remove-sepet-btn {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 500;
    transition: all 0.2s;
}

.remove-sepet-btn:hover {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}

.pagination {
    display: flex;
    justify-content: center;
    gap: 0.75rem;
    align-items: center;
    margin-top: 1.5rem;
}

.pagination button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

#pageInfo {
    color: #4b5563;
    font-weight: 500;
    min-width: 100px;
    text-align: center;
}

@media (max-width: 768px) {
    .content-wrapper {
        padding: 16px 20px;
    }
    
    .filter-section {
        grid-template-columns: 1fr;
    }
    
    .table-controls {
        flex-direction: column;
        align-items: stretch;
    }
    
    .data-table {
        font-size: 0.85rem;
    }
    
    .data-table th,
    .data-table td {
        padding: 0.75rem 0.5rem;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Ana Depo Siparişi Oluştur</h2>
            <div style="display: flex; gap: 12px; align-items: center;">
                <button class="btn btn-primary sepet-btn" id="sepetToggleBtn" onclick="toggleSepet()" style="position: relative;">
                    🛒 Sepet
                    <span class="sepet-badge" id="sepetBadge" style="display: none;">0</span>
                </button>
                <button class="btn btn-secondary" onclick="window.location.href='config/logout.php'">Çıkış Yap →</button>
            </div>
        </header>

        <div class="content-wrapper">
            <?php if (empty($fromWarehouse) || empty($toWarehouse)): ?>
                <div class="alert alert-warning">
                    <strong>Uyarı:</strong> Depo bilgileri bulunamadı!
                </div>
            <?php endif; ?>

            <!-- Ana Container - Sepet açıkken ikiye bölünecek -->
            <div class="main-layout-container" id="mainLayoutContainer">
                <!-- Sol taraf: Filtreler ve Tablo -->
                <div class="main-content-left" id="mainContentLeft">
                    <!-- Filtreler -->
                    <section class="card">
                <div class="filter-section">
                    <div class="filter-group">
                        <label>Kalem Tanımı Filtresi</label>
                        <div class="multi-select-container">
                            <div class="multi-select-input" onclick="toggleMultiSelect('itemName')">
                                <div id="itemNameTags"></div>
                                <input type="text" id="filterItemName" placeholder="Seçiniz..." readonly>
                                <span class="dropdown-arrow">▼</span>
                            </div>
                            <div class="multi-select-dropdown" id="itemNameDropdown">
                                <div class="multi-select-dropdown-search">
                                    <input type="text" id="itemNameSearch" placeholder="Ara..." onkeyup="filterDropdown('itemName', this.value)">
                                </div>
                                <div id="itemNameOptions"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label>Kalem Grubu Filtresi</label>
                        <div class="multi-select-container">
                            <div class="multi-select-input" onclick="toggleMultiSelect('itemGroup')">
                                <div id="itemGroupTags"></div>
                                <input type="text" id="filterItemGroup" placeholder="Seçiniz..." readonly>
                                <span class="dropdown-arrow">▼</span>
                            </div>
                            <div class="multi-select-dropdown" id="itemGroupDropdown">
                                <div class="multi-select-dropdown-search">
                                    <input type="text" id="itemGroupSearch" placeholder="Ara..." onkeyup="filterDropdown('itemGroup', this.value)">
                                </div>
                                <div id="itemGroupOptions"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label>Stokta Filtresi</label>
                        <div class="single-select-container">
                            <div class="single-select-input" onclick="toggleSingleSelect('stockStatus')">
                                <input type="text" id="filterStockStatus" value="Tümü" placeholder="Seçiniz..." readonly>
                                <span class="dropdown-arrow">▼</span>
                            </div>
                            <div class="single-select-dropdown" id="stockStatusDropdown">
                                <div class="single-select-option selected" data-value="" onclick="selectStockStatus('')">Tümü</div>
                                <div class="single-select-option" data-value="var" onclick="selectStockStatus('var')">Var</div>
                                <div class="single-select-option" data-value="yok" onclick="selectStockStatus('yok')">Yok</div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Tablo -->
            <section class="card">
                <div class="table-controls">
                    <div class="show-entries">
                        Sayfada 
                        <select class="entries-select" id="entriesPerPage" onchange="updatePageSize()">
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="75">75</option>
                        </select>
                        kayıt göster
                    </div>
                    <div class="search-box">
                        <input type="text" class="search-input" id="tableSearch" placeholder="Ara..." onkeyup="if(event.key==='Enter') loadItems()">
                        <button class="btn btn-secondary" onclick="loadItems()">🔍</button>
                    </div>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kalem Kodu</th>
                            <th>Kalem Tanımı</th>
                            <th>Kalem Grubu</th>
                            <th>Stokta</th>
                            <th>Stoktaki Miktar</th>
                            <th>Minimum Miktar</th>
                            <th>Sipariş Miktarı</th>
                            <th>Ölçü Birimi</th>
                            <th>Dönüşüm</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <tr>
                            <td colspan="9" style="text-align:center;color:#888;padding:20px;">
                                Filtre seçerek veya arama yaparak kalemleri görüntüleyin.
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="pagination">
                    <button class="btn btn-secondary" id="prevBtn" onclick="changePage(-1)" disabled>← Önceki</button>
                    <span id="pageInfo">Sayfa 1</span>
                    <button class="btn btn-secondary" id="nextBtn" onclick="changePage(1)" disabled>Sonraki →</button>
                </div>
            </section>
                </div>
                <!-- Sağ taraf: Sepet (açıkken görünecek) -->
                <div class="main-content-right sepet-panel" id="sepetPanel" style="display: none;">
                    <section class="card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                            <h3 style="margin: 0; color: #1e40af; font-size: 1.25rem; font-weight: 600;">🛒 Sepet</h3>
                            <button class="btn btn-secondary" onclick="toggleSepet()" style="padding: 0.5rem 1rem; font-size: 0.875rem;">✕ Kapat</button>
                        </div>
                        <div id="sepetList"></div>
                        <div style="margin-top: 1.5rem; text-align: right; padding-top: 1.5rem; border-top: 1px solid #e5e7eb;">
                            <button class="btn btn-primary" onclick="saveRequest()">✓ Sipariş Oluştur</button>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </main>

    <script>
let currentPage = 0;
let pageSize = 25;
let selectedItems = {};
let hasMore = false;
let selectedItemNames = [];
let selectedItemGroups = [];
let selectedStockStatus = '';
let allItemNames = [];
let allItemGroups = [];
let filteredItemNames = [];
let filteredItemGroups = [];
let itemsData = []; // YENİ: Items data'sını global scope'ta sakla (BaseQty ve UomCode için)

// Sayfa yüklendiğinde filtre seçeneklerini yükle
document.addEventListener('DOMContentLoaded', function() {
    loadFilterOptions();
});

function loadFilterOptions() {
    fetch('AnaDepoSO.PHP?ajax=filter_options')
        .then(res => res.json())
        .then(data => {
            allItemNames = data.itemNames || [];
            allItemGroups = data.itemGroups || [];
            filteredItemNames = allItemNames;
            filteredItemGroups = allItemGroups;
            populateDropdowns();
        })
        .catch(err => {
            console.error('Filtre seçenekleri yüklenirken hata:', err);
        });
}

function populateDropdowns() {
    // ItemNames dropdown
    const itemNameOptions = document.getElementById('itemNameOptions');
    itemNameOptions.innerHTML = allItemNames.map(name => {
        const isSelected = selectedItemNames.includes(name);
        return `<div class="multi-select-option ${isSelected ? 'selected' : ''}" data-value="${name.replace(/"/g, '&quot;')}" onclick="toggleItemName('${name.replace(/'/g, "\\'")}')">${name}</div>`;
    }).join('');
    
    // ItemGroups dropdown
    const itemGroupOptions = document.getElementById('itemGroupOptions');
    itemGroupOptions.innerHTML = allItemGroups.map(group => {
        const isSelected = selectedItemGroups.includes(group);
        return `<div class="multi-select-option ${isSelected ? 'selected' : ''}" data-value="${group.replace(/"/g, '&quot;')}" onclick="toggleItemGroup('${group.replace(/'/g, "\\'")}')">${group}</div>`;
    }).join('');
}

function filterDropdown(type, searchTerm) {
    const options = type === 'itemName' ? allItemNames : allItemGroups;
    const filtered = options.filter(item => 
        item.toLowerCase().includes(searchTerm.toLowerCase())
    );
    
    if (type === 'itemName') {
        filteredItemNames = filtered;
        const itemNameOptions = document.getElementById('itemNameOptions');
        itemNameOptions.innerHTML = filtered.map(name => {
            const isSelected = selectedItemNames.includes(name);
            return `<div class="multi-select-option ${isSelected ? 'selected' : ''}" data-value="${name.replace(/"/g, '&quot;')}" onclick="toggleItemName('${name.replace(/'/g, "\\'")}')">${name}</div>`;
        }).join('');
    } else {
        filteredItemGroups = filtered;
        const itemGroupOptions = document.getElementById('itemGroupOptions');
        itemGroupOptions.innerHTML = filtered.map(group => {
            const isSelected = selectedItemGroups.includes(group);
            return `<div class="multi-select-option ${isSelected ? 'selected' : ''}" data-value="${group.replace(/"/g, '&quot;')}" onclick="toggleItemGroup('${group.replace(/'/g, "\\'")}')">${group}</div>`;
        }).join('');
    }
}

function toggleMultiSelect(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    const input = dropdown.parentElement.querySelector('.multi-select-input');
    const isOpen = dropdown.classList.contains('show');
    
    document.querySelectorAll('.multi-select-dropdown').forEach(d => d.classList.remove('show'));
    document.querySelectorAll('.multi-select-input').forEach(d => d.classList.remove('active'));
    
    if (!isOpen) {
        dropdown.classList.add('show');
        input.classList.add('active');
        // Search input'u temizle ve focus et
        const searchInput = dropdown.querySelector('input');
        if (searchInput) {
            searchInput.value = '';
            searchInput.focus();
        }
    }
}

function toggleSingleSelect(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    const input = dropdown.parentElement.querySelector('.single-select-input');
    const isOpen = dropdown.classList.contains('show');
    
    document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
    document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
    
    if (!isOpen) {
        dropdown.classList.add('show');
        input.classList.add('active');
    }
}

function toggleItemName(name) {
    const index = selectedItemNames.indexOf(name);
    if (index > -1) {
        selectedItemNames.splice(index, 1);
    } else {
        selectedItemNames.push(name);
    }
    updateFilterDisplay('itemName');
    currentPage = 0;
    loadItems();
}

function toggleItemGroup(group) {
    const index = selectedItemGroups.indexOf(group);
    if (index > -1) {
        selectedItemGroups.splice(index, 1);
    } else {
        selectedItemGroups.push(group);
    }
    updateFilterDisplay('itemGroup');
    currentPage = 0;
    loadItems();
}

function selectStockStatus(value) {
    selectedStockStatus = value;
    const text = value === 'var' ? 'Var' : (value === 'yok' ? 'Yok' : 'Tümü');
    document.getElementById('filterStockStatus').value = text;
    document.querySelectorAll('#stockStatusDropdown .single-select-option').forEach(opt => opt.classList.remove('selected'));
    document.querySelector(`#stockStatusDropdown .single-select-option[data-value="${value}"]`).classList.add('selected');
    currentPage = 0;
    loadItems();
}

function updateFilterDisplay(type) {
    const tagsContainer = document.getElementById(type + 'Tags');
    const input = document.getElementById('filter' + type.charAt(0).toUpperCase() + type.slice(1));
    const selected = type === 'itemName' ? selectedItemNames : selectedItemGroups;
    
    tagsContainer.innerHTML = '';
    
    if (selected.length === 0) {
        input.placeholder = 'Seçiniz...';
    } else {
        input.placeholder = '';
        selected.forEach(value => {
            const tag = document.createElement('span');
            tag.className = 'multi-select-tag';
            tag.innerHTML = `${value} <span class="remove" onclick="removeFilter('${type}', '${value.replace(/'/g, "\\'")}')">×</span>`;
            tagsContainer.appendChild(tag);
        });
    }
    
    // Dropdown'daki seçili durumları güncelle
    const dropdown = document.getElementById(type + 'Dropdown');
    dropdown.querySelectorAll('.multi-select-option').forEach(opt => {
        const value = opt.getAttribute('data-value');
        if (selected.includes(value)) {
            opt.classList.add('selected');
        } else {
            opt.classList.remove('selected');
        }
    });
}

function removeFilter(type, value) {
    if (type === 'itemName') {
        selectedItemNames = selectedItemNames.filter(v => v !== value);
    } else if (type === 'itemGroup') {
        selectedItemGroups = selectedItemGroups.filter(v => v !== value);
    }
    updateFilterDisplay(type);
    currentPage = 0;
    loadItems();
}

function updatePageSize() {
    pageSize = parseInt(document.getElementById('entriesPerPage').value);
    currentPage = 0;
    loadItems();
}

function loadItems() {
    const search = document.getElementById('tableSearch').value.trim();
    const skip = currentPage * pageSize;
    
    let url = `AnaDepoSO.PHP?ajax=items&skip=${skip}&top=${pageSize}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    if (selectedItemNames.length > 0) url += `&item_names=${encodeURIComponent(JSON.stringify(selectedItemNames))}`;
    if (selectedItemGroups.length > 0) url += `&item_groups=${encodeURIComponent(JSON.stringify(selectedItemGroups))}`;
    if (selectedStockStatus) url += `&stock_status=${encodeURIComponent(selectedStockStatus)}`;
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            hasMore = data.hasMore || false;
            itemsData = data.data || []; // YENİ: Items data'sını global scope'ta sakla
            renderItems(itemsData);
            updatePagination();
        })
        .catch(err => {
            console.error('Hata:', err);
            document.getElementById('itemsTableBody').innerHTML = 
                '<tr><td colspan="9" style="text-align:center;color:#dc3545;">Veri yüklenirken hata oluştu.</td></tr>';
        });
}

function renderItems(items) {
    const tbody = document.getElementById('itemsTableBody');
    
    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#888;">Kayıt bulunamadı.</td></tr>';
        return;
    }
    
    tbody.innerHTML = items.map(item => {
        const itemCode = item.ItemCode || '';
        const itemName = item.ItemName || item.ItemDescription || '';
        const itemGroup = item.ItemGroup || (item.ItemGroups && item.ItemGroups.length > 0 ? item.ItemGroups[0].ItemsGroupCode : '-'); // YENİ: ItemGroup view'den geliyor
        const hasStock = item._hasStock || false;
        const stockQty = item.MainQty || item._stock || 0; // MainQty kullanılıyor
        const minQty = item.MinQty || 0;
        const uomCode = item.UomCode || item.UoMCode || '-'; // YENİ: UomCode view'den geliyor
        const baseQty = parseFloat(item.BaseQty || 1.0); // YENİ: BaseQty view'den geliyor
        const uomConvert = parseFloat(item.UomConvert || item.UOMConvert || 1); // UomConvert view'den geliyor
        const isInSepet = selectedItems.hasOwnProperty(itemCode);
        const sepetQty = isInSepet ? selectedItems[itemCode].quantity : 0;
        
        // Dönüşüm kolonu: Eğer sipariş miktarı varsa "miktar x UomConvert", yoksa sadece UomConvert
        let conversionText = '-';
        if (uomConvert && uomConvert !== 1) {
            if (sepetQty > 0) {
                // Sipariş miktarı × UomConvert formatında göster
                conversionText = `${sepetQty.toFixed(0)}x${uomConvert.toFixed(0)}`;
            } else {
                // Sipariş miktarı yoksa sadece UomConvert göster
                conversionText = uomConvert.toFixed(0);
            }
        } else if (uomConvert === 1) {
            // Standart (1 adet) ise sadece miktarı göster veya boş bırak
            if (sepetQty > 0) {
                conversionText = sepetQty.toFixed(0);
            } else {
                conversionText = '-';
            }
        }
        
        return `
            <tr>
                <td>${itemCode}</td>
                <td>${itemName}</td>
                <td>${itemGroup}</td>
                <td><span class="stock-badge ${hasStock ? 'stock-yes' : 'stock-no'}">${hasStock ? 'Var' : 'Yok'}</span></td>
                <td>${stockQty.toFixed(2)}</td>
                <td>${minQty}</td>
                <td>
                    <div class="quantity-controls">
                        <button type="button" class="qty-btn" onclick="changeQuantity('${itemCode}', -1)">-</button>
                        <input type="number" 
                               id="qty_${itemCode}"
                               value="${sepetQty}" 
                               min="0" 
                               step="0.01"
                               class="qty-input"
                               onchange="updateQuantity('${itemCode}', this.value)"
                               oninput="updateQuantity('${itemCode}', this.value)">
                        <button type="button" class="qty-btn" onclick="changeQuantity('${itemCode}', 1)">+</button>
                    </div>
                </td>
                <td>${uomCode}</td>
                <td style="text-align: center; font-weight: 600; color: #3b82f6;">${conversionText}</td>
            </tr>
        `;
    }).join('');
}

function changeQuantity(itemCode, delta) {
    const input = document.getElementById('qty_' + itemCode);
    if (!input) return;
    
    let value = parseFloat(input.value) || 0;
    value += delta;
    if (value < 0) value = 0;
    input.value = value;
    updateQuantity(itemCode, value);
}

function updateQuantity(itemCode, quantity) {
    const qty = parseFloat(quantity) || 0;
    
    if (qty > 0) {
        // Sepete ekle veya güncelle
        if (!selectedItems[itemCode]) {
            // Item bilgilerini bul (tablodan veya data'dan)
            const row = document.getElementById('qty_' + itemCode).closest('tr');
            const itemName = row.cells[1].textContent;
            
            // BaseQty ve UomCode bilgilerini itemsData'dan bul
            const itemData = itemsData.find(i => i.ItemCode === itemCode);
            const baseQty = itemData ? parseFloat(itemData.BaseQty || 1.0) : 1.0;
            const uomCode = itemData ? (itemData.UomCode || itemData.UoMCode || '') : '';
            
            selectedItems[itemCode] = {
                itemCode: itemCode,
                itemName: itemName,
                quantity: qty,
                baseQty: baseQty,  // YENİ: BaseQty eklendi
                uomCode: uomCode  // YENİ: UomCode eklendi
            };
        } else {
            selectedItems[itemCode].quantity = qty;
        }
    } else {
        // Sepetten çıkar
        if (selectedItems[itemCode]) {
            delete selectedItems[itemCode];
        }
    }
    
    updateSepet();
    // Dönüşüm kolonunu güncellemek için tabloyu yeniden render et
    if (itemsData && itemsData.length > 0) {
        renderItems(itemsData);
    }
}

function toggleSepet() {
    const panel = document.getElementById('sepetPanel');
    const container = document.getElementById('mainLayoutContainer');
    const isOpen = panel.style.display !== 'none';
    
    if (isOpen) {
        panel.style.display = 'none';
        container.classList.remove('sepet-open');
    } else {
        panel.style.display = 'block';
        container.classList.add('sepet-open');
        updateSepet();
    }
}

function updateSepet() {
    const list = document.getElementById('sepetList');
    const badge = document.getElementById('sepetBadge');
    const itemCount = Object.keys(selectedItems).length;
    
    // Badge güncelle
    if (itemCount > 0) {
        badge.textContent = itemCount;
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
    
    // Sepet listesi güncelle
    if (itemCount === 0) {
        list.innerHTML = '<div style="text-align: center; padding: 2rem; color: #9ca3af;">Sepetiniz boş</div>';
        return;
    }
    
    list.innerHTML = Object.values(selectedItems).map(item => `
        <div class="sepet-item">
            <div class="sepet-item-info">
                <div class="sepet-item-name">${item.itemCode} - ${item.itemName}</div>
                <div class="sepet-item-qty">
                    <button type="button" class="qty-btn" onclick="changeQuantity('${item.itemCode}', -1)">-</button>
                    <input type="number" 
                           value="${item.quantity}" 
                           min="0" 
                           step="0.01"
                           onchange="updateQuantity('${item.itemCode}', this.value)"
                           oninput="updateQuantity('${item.itemCode}', this.value)">
                    <button type="button" class="qty-btn" onclick="changeQuantity('${item.itemCode}', 1)">+</button>
                </div>
            </div>
            <button type="button" class="remove-sepet-btn" onclick="removeFromSepet('${item.itemCode}')">Kaldır</button>
        </div>
    `).join('');
}

function removeFromSepet(itemCode) {
    if (selectedItems[itemCode]) {
        delete selectedItems[itemCode];
        const input = document.getElementById('qty_' + itemCode);
        if (input) input.value = 0;
        updateSepet();
        // Dönüşüm kolonunu güncellemek için tabloyu yeniden render et
        if (itemsData && itemsData.length > 0) {
            renderItems(itemsData);
        }
    }
}

function changePage(delta) {
    currentPage += delta;
    if (currentPage < 0) currentPage = 0;
    loadItems();
}

function updatePagination() {
    document.getElementById('pageInfo').textContent = `Sayfa ${currentPage + 1}`;
    document.getElementById('prevBtn').disabled = currentPage === 0;
    document.getElementById('nextBtn').disabled = !hasMore;
}

function saveRequest() {
    const items = Object.values(selectedItems).filter(item => item.quantity > 0);
    
    if (items.length === 0) {
        alert('Lütfen en az bir kalem seçin!');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'create_request');
    formData.append('items', JSON.stringify(items));
    
    if (!confirm('Talebi oluşturmak istediğinize emin misiniz?')) {
        return;
    }
    
    fetch('AnaDepoSO.PHP', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Talep başarıyla oluşturuldu!');
            window.location.href = 'AnaDepo.php';
        } else {
            alert('Hata: ' + (data.message || 'Bilinmeyen hata'));
        }
    })
    .catch(err => {
        console.error('Hata:', err);
        alert('Talep oluşturulurken hata oluştu!');
    });
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.multi-select-container') && !e.target.closest('.single-select-container')) {
        document.querySelectorAll('.multi-select-dropdown').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.multi-select-input').forEach(d => d.classList.remove('active'));
        document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
    }
});
    </script>
</body>
</html>
