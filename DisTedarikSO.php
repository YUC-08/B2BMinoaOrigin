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
$branch = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? '';
$userName = $_SESSION["UserName"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// Ana depo (FromWhsName için) - U_ASB2B_FATH eq 'Y'
$fromWarehouseFilter = "U_ASB2B_FATH eq 'Y' and U_AS_OWNR eq '{$uAsOwnr}'";
$fromWarehouseQuery = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=" . urlencode($fromWarehouseFilter);
$fromWarehouseData = $sap->get($fromWarehouseQuery);
$fromWarehouses = $fromWarehouseData['response']['value'] ?? [];
$fromWarehouse = !empty($fromWarehouses) ? $fromWarehouses[0]['WarehouseCode'] : null;
$fromWhsName = !empty($fromWarehouses) ? ($fromWarehouses[0]['WarehouseName'] ?? '') : '';

// Gideceği depo (talep eden depo) - U_ASB2B_MAIN eq '2'
$toWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '2'";
$toWarehouseQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($toWarehouseFilter);
$toWarehouseData = $sap->get($toWarehouseQuery);
$toWarehouses = $toWarehouseData['response']['value'] ?? [];
$toWarehouse = !empty($toWarehouses) ? $toWarehouses[0]['WarehouseCode'] : null;

// POST işlemi: PurchaseRequests oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_request') {
    header('Content-Type: application/json');
    
    $selectedItems = json_decode($_POST['items'] ?? '[]', true);
    
    if (empty($selectedItems)) {
        echo json_encode(['success' => false, 'message' => 'Lütfen en az bir kalem seçin!']);
        exit;
    }
    
    if (empty($toWarehouse)) {
        echo json_encode(['success' => false, 'message' => 'Hedef depo bilgisi bulunamadı!']);
        exit;
    }
    
    // SAP'de PurchaseRequests için DocumentLines kullanılmalı (StockTransferLines değil!)
    // Insomnia_Requests.md'de örnek: DocumentLines kullanılıyor
    $documentLines = [];
    foreach ($selectedItems as $item) {
        $userQuantity = floatval($item['quantity'] ?? 0);
        $itemCode = $item['itemCode'] ?? '';
        
        // Miktar > 0 ve ItemCode boş değil olmalı
        if ($userQuantity > 0 && !empty($itemCode)) {
            $documentLines[] = [
                'ItemCode' => $itemCode, // Seçilen kalem
                'Quantity' => $userQuantity, // İstenen miktar
                'UoMCode' => $item['uomCode'] ?? '', // Birim
                'WarehouseCode' => $toWarehouse, // Gideceği depo (talep eden depo)
                'VendorNum' => $item['defaultVendor'] ?? '' // DefaultVendor (view'den)
            ];
        }
    }
    
    if (empty($documentLines)) {
        echo json_encode(['success' => false, 'message' => 'Miktarı girilen kalem bulunamadı!']);
        exit;
    }
    
    // Spec'e göre: POST /b1s/v2/PurchaseRequests
    $requiredDate = $_POST['required_date'] ?? date('Y-m-d', strtotime('+7 days'));
    $comments = $_POST['comments'] ?? 'Satınalma talebi';
    $docDate = date('Y-m-d'); // Doküman tarihi
    $docDueDate = $requiredDate; // Vade tarihi (RequriedDate ile aynı)
    
    $payload = [
        'DocDate' => $docDate, // Doküman tarihi
        'DocDueDate' => $docDueDate, // Vade tarihi
        'RequriedDate' => $requiredDate, // Teslimat istenen tarih (kullanıcıdan alınan)
        'Comments' => $comments, // Ekrandaki açıklama
        'U_ASB2B_BRAN' => $branch, // Login şubesi
        'U_AS_OWNR' => $uAsOwnr, // Login kitabevi
        'U_ASB2B_STATUS' => '1', // Her zaman 1 = Yeni/Onay bekleniyor
        'U_ASB2B_User' => $userName, // Login kullanıcı adı
        'DocumentLines' => $documentLines // ✅ SAP'de PurchaseRequests için DocumentLines kullanılmalı
    ];
    
    // Debug: Payload'ı logla
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    file_put_contents(
        $logDir . '/pr_payload_' . date('Ymd_His') . '.json',
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    
    $result = $sap->post('PurchaseRequests', $payload);
    
    if ($result['status'] == 200 || $result['status'] == 201) {
        echo json_encode(['success' => true, 'message' => 'Dış Tedarik talebi başarıyla oluşturuldu!', 'data' => $result]);
    } else {
        $errorMsg = 'Talep oluşturulamadı: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        echo json_encode(['success' => false, 'message' => $errorMsg, 'response' => $result]);
    }
    exit;
}

// AJAX: Items listesi getir (view.svc/ASB2B_MainWhsItem_B1SLQuery kullanılıyor)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'items') {
    header('Content-Type: application/json');
    
    // FromWhsName kontrolü
    if (empty($fromWhsName)) {
        echo json_encode([
            'data' => [],
            'count' => 0,
            'hasMore' => false,
            'error' => 'Ana depo adı bulunamadı! FromWarehouse: ' . ($fromWarehouse ?: 'BULUNAMADI')
        ]);
        exit;
    }
    
    $skip = intval($_GET['skip'] ?? 0);
    $top = intval($_GET['top'] ?? 25);
    $search = trim($_GET['search'] ?? '');
    $itemNames = isset($_GET['item_names']) ? json_decode($_GET['item_names'], true) : [];
    $itemGroups = isset($_GET['item_groups']) ? json_decode($_GET['item_groups'], true) : [];
    $stockStatus = trim($_GET['stock_status'] ?? '');
    
    // FromWhsName ile filtreleme (AnaDepoSO.php'deki gibi)
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
    
    $itemsQuery = 'view.svc/ASB2B_MainWhsItem_B1SLQuery?$filter=' . urlencode($filter) . '&$orderby=ItemCode&$top=' . $top . '&$skip=' . $skip;
    
    $itemsData = $sap->get($itemsQuery);
    $items = $itemsData['response']['value'] ?? [];
    
    // Her item için stok bilgisini ekle (MainQty kullanılıyor)
    foreach ($items as &$item) {
        $mainQty = floatval($item['MainQty'] ?? 0);
        $item['_stock'] = $mainQty;
        $item['_hasStock'] = $mainQty > 0;
    }
    
    $response = [
        'data' => $items,
        'count' => count($items),
        'hasMore' => count($items) >= $top
    ];
    
    // Debug bilgileri ekle (eğer hata varsa veya veri yoksa)
    if (empty($items) || ($itemsData['status'] ?? 0) != 200) {
        $response['debug'] = [
            'session_uAsOwnr' => $uAsOwnr,
            'session_branch' => $branch,
            'filter' => $filter,
            'query' => $itemsQuery,
            'http_status' => $itemsData['status'] ?? 'NO STATUS',
            'response_keys' => isset($itemsData['response']) ? array_keys($itemsData['response']) : [],
            'has_value' => isset($itemsData['response']['value']),
            'error' => $itemsData['error'] ?? null,
            'response_error' => $itemsData['response']['error'] ?? null,
            'full_response' => $itemsData['response'] ?? null
        ];
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX: ItemNames ve ItemGroups listesi getir (filtre dropdown'ları için)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'filter_options') {
    header('Content-Type: application/json');
    
    // FromWhsName kontrolü
    if (empty($fromWhsName)) {
        echo json_encode([
            'itemNames' => [],
            'itemGroups' => [],
            'error' => 'Ana depo adı bulunamadı!'
        ]);
        exit;
    }
    
    // FromWhsName ile filtreleme
    $fromWhsNameEscaped = str_replace("'", "''", $fromWhsName);
    $filter = "FromWhsName eq '{$fromWhsNameEscaped}'";
    $itemsQuery = 'view.svc/ASB2B_MainWhsItem_B1SLQuery?$filter=' . urlencode($filter) . '&$select=ItemName,ItemGroup&$top=1000';
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
    <title>Dis Tedarik Talebi Oluştur</title>
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
            <h2>Dis Tedarik Talebi Oluştur</h2>
            <div style="display: flex; gap: 12px; align-items: center;">
                <button class="btn btn-primary sepet-btn" id="sepetToggleBtn" onclick="toggleSepet()" style="position: relative;">
                    🛒 Sepet
                    <span class="sepet-badge" id="sepetBadge" style="display: none;">0</span>
                </button>
                <button class="btn btn-secondary" onclick="window.location.href='config/logout.php'">Çıkış Yap →</button>
            </div>
        </header>

        <div class="content-wrapper">
            <?php if (empty($toWarehouse)): ?>
                <div class="alert alert-warning">
                    <strong>Uyarı:</strong> Hedef depo bilgisi bulunamadı! Lütfen SAP'de "U_ASB2B_MAIN=2" olarak tanımlanmış bir depo olduğundan emin olun.
                </div>
            <?php else: ?>
                <!-- Spec'e göre: Gideceği depo (talep eden depo) bilgisi -->
                <div class="card" style="background: #eff6ff; border: 2px solid #3b82f6; margin-bottom: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div>
                            <div style="font-size: 0.75rem; font-weight: 600; color: #1e40af; text-transform: uppercase; margin-bottom: 0.25rem;">Gideceği Depo (Talep Edilen Depo)</div>
                            <div style="font-size: 1.25rem; color: #1e3a8a; font-weight: 700;"><?= htmlspecialchars($toWarehouse) ?></div>
                        </div>
                    </div>
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

            <!-- Debug Container -->
            <div id="debugContainer" style="display: none;"></div>

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
                            <th>Ana Depo Stok Durumu</th>
                            <th>Şube Miktarı</th>
                            <th>Minimum Miktar</th>
                            <th>Sipariş Miktarı</th>
                            <th>Birim</th>
                            <th>Ana Depo</th>
                            <th>Varsayılan Tedarikçi</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <tr>
                            <td colspan="10" style="text-align:center;color:#888;padding:20px;">
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
                        <div style="margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #e5e7eb;">
                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; font-weight: 600; color: #374151; margin-bottom: 0.5rem;">Talep Tarihi (Teslimat İstenen Tarih) *</label>
                                <input type="date" 
                                       id="requiredDate" 
                                       required
                                       value="<?= date('Y-m-d', strtotime('+7 days')) ?>"
                                       style="width: 100%; padding: 0.5rem; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 0.875rem;">
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <label style="display: block; font-weight: 600; color: #374151; margin-bottom: 0.5rem;">Açıklama</label>
                                <textarea id="requestComments" 
                                          placeholder="Talep ile ilgili açıklama giriniz..." 
                                          style="width: 100%; padding: 0.5rem; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 0.875rem; min-height: 80px; resize: vertical;"></textarea>
                            </div>
                            <div style="text-align: right;">
                                <button class="btn btn-primary" onclick="saveRequest()">✓ Talep Oluştur</button>
                            </div>
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
let itemsData = [];

// Debug bilgilerini göster
function showDebugInfo(debug) {
    const container = document.getElementById('debugContainer');
    if (!container) return;
    
    let html = '<div class="card" style="background: #fef3c7; border: 2px solid #f59e0b; margin-bottom: 1.5rem;">';
    html += '<h3 style="color: #92400e; margin-bottom: 1rem;">🔍 Debug Bilgileri</h3>';
    html += '<div style="font-family: monospace; font-size: 0.85rem; color: #78350f;">';
    html += `<p><strong>Session U_AS_OWNR:</strong> ${debug.session_uAsOwnr || 'N/A'}</p>`;
    html += `<p><strong>Session Branch:</strong> ${debug.session_branch || 'N/A'}</p>`;
    html += `<p><strong>Filter:</strong> ${debug.filter || 'N/A'}</p>`;
    html += `<p><strong>Query URL:</strong> ${debug.query || 'N/A'}</p>`;
    html += `<p><strong>HTTP Status:</strong> ${debug.http_status || 'N/A'}</p>`;
    html += `<p><strong>Response Keys:</strong> ${(debug.response_keys || []).join(', ') || 'N/A'}</p>`;
    html += `<p><strong>Has 'value' key:</strong> ${debug.has_value ? 'Evet' : 'Hayır'}</p>`;
    
    if (debug.error) {
        html += `<p style="color: #dc2626;"><strong>Error:</strong> <pre style="background: white; padding: 0.5rem; border-radius: 4px; overflow-x: auto;">${JSON.stringify(debug.error, null, 2)}</pre></p>`;
    }
    
    if (debug.response_error) {
        html += `<p style="color: #dc2626;"><strong>Response Error:</strong> <pre style="background: white; padding: 0.5rem; border-radius: 4px; overflow-x: auto;">${JSON.stringify(debug.response_error, null, 2)}</pre></p>`;
    }
    
    if (debug.full_response) {
        html += `<p style="color: #dc2626;"><strong>Full Response:</strong> <pre style="background: white; padding: 1rem; border-radius: 6px; overflow-x: auto; max-height: 400px; overflow-y: auto;">${JSON.stringify(debug.full_response, null, 2)}</pre></p>`;
    }
    
    html += '</div>';
    html += '</div>';
    
    container.innerHTML = html;
    container.style.display = 'block';
}

// Sayfa yüklendiğinde filtre seçeneklerini yükle
document.addEventListener('DOMContentLoaded', function() {
    loadFilterOptions();
    updateSepet(); // Sepet badge'ini yükle
});

function loadFilterOptions() {
    fetch('DisTedarikSO.php?ajax=filter_options')
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
    
    let url = `DisTedarikSO.php?ajax=items&skip=${skip}&top=${pageSize}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    if (selectedItemNames.length > 0) url += `&item_names=${encodeURIComponent(JSON.stringify(selectedItemNames))}`;
    if (selectedItemGroups.length > 0) url += `&item_groups=${encodeURIComponent(JSON.stringify(selectedItemGroups))}`;
    if (selectedStockStatus) url += `&stock_status=${encodeURIComponent(selectedStockStatus)}`;
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            // Debug bilgilerini kontrol et
            if (data.debug) {
                console.error('🔍 Debug Bilgileri:', data.debug);
                showDebugInfo(data.debug);
            }
            
            hasMore = data.hasMore || false;
            itemsData = data.data || [];
            renderItems(itemsData);
            updatePagination();
        })
        .catch(err => {
            console.error('Hata:', err);
            document.getElementById('itemsTableBody').innerHTML = 
                '<tr><td colspan="11" style="text-align:center;color:#dc3545;">Veri yüklenirken hata oluştu. Console\'u kontrol edin.</td></tr>';
        });
}

function renderItems(items) {
    const tbody = document.getElementById('itemsTableBody');
    
    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#888;">Kayıt bulunamadı.</td></tr>';
        return;
    }
    
    tbody.innerHTML = items.map(item => {
        const itemCode = item.ItemCode || '';
        const itemName = item.ItemName || item.ItemDescription || '';
        const itemGroup = item.ItemGroup || '-';
        const mainQty = item.MainQty || item._stock || 0; // Ana depo miktarı
        const branQty = item.BranQty || 0; // Şube miktarı
        const minQty = item.MinQty || 0;
        const uomCode = item.UomCode || item.UoMCode || '-';
        const fromWhsName = item.FromWhsName || '-';
        const defaultVendor = item.DefaultVendor || '-';
        const isInSepet = selectedItems.hasOwnProperty(itemCode);
        const sepetQty = isInSepet ? selectedItems[itemCode].quantity : 0;
        
        return `
            <tr>
                <td>${itemCode}</td>
                <td>${itemName}</td>
                <td>${itemGroup}</td>
                <td>${mainQty.toFixed(2)}</td>
                <td>${branQty.toFixed(2)}</td>
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
                <td>${fromWhsName}</td>
                <td>${defaultVendor}</td>
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
            // Item bilgilerini bul
            const row = document.getElementById('qty_' + itemCode).closest('tr');
            const itemName = row.cells[1].textContent;
            
            // Item data'sından bilgileri al
            const itemData = itemsData.find(i => i.ItemCode === itemCode);
            const uomCode = itemData ? (itemData.UomCode || itemData.UoMCode || '') : '';
            const defaultVendor = itemData ? (itemData.DefaultVendor || '') : '';
            
            selectedItems[itemCode] = {
                itemCode: itemCode,
                itemName: itemName,
                quantity: qty,
                uomCode: uomCode,
                defaultVendor: defaultVendor
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
    
    const comments = document.getElementById('requestComments')?.value || '';
    const requiredDate = document.getElementById('requiredDate')?.value || '';
    
    if (!requiredDate) {
        alert('Lütfen talep tarihini girin!');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'create_request');
    formData.append('items', JSON.stringify(items));
    formData.append('comments', comments);
    formData.append('required_date', requiredDate); 
    
    if (!confirm('Talebi oluşturmak istediğinize emin misiniz?')) {
        return;
    }
    
    fetch('DisTedarikSO.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Talep başarıyla oluşturuldu!');
            window.location.href = 'DisTedarik.php';
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