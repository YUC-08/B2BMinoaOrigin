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

// Ana depo - Kullanıcının şubesine göre ana depo (U_ASB2B_MAIN eq '1')
$fromWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '1'";
$fromWarehouseQuery = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=" . urlencode($fromWarehouseFilter);
$fromWarehouseData = $sap->get($fromWarehouseQuery);
$fromWarehouses = $fromWarehouseData['response']['value'] ?? [];
$fromWarehouse = !empty($fromWarehouses) ? $fromWarehouses[0]['WarehouseCode'] : null;
$fromWhsName = !empty($fromWarehouses) ? ($fromWarehouses[0]['WarehouseName'] ?? '') : '';

// Gideceği depo (talep eden depo) - Sevkiyat deposu (U_ASB2B_MAIN eq '2')
$toWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '2'";
$toWarehouseQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($toWarehouseFilter); 
$toWarehouseData = $sap->get($toWarehouseQuery);
$toWarehouses = $toWarehouseData['response']['value'] ?? [];
$toWarehouse = !empty($toWarehouses) ? $toWarehouses[0]['WarehouseCode'] : null; 

// Kayıt dışı mod kontrolü
$isUnregisteredMode = isset($_GET['mode']) && $_GET['mode'] === 'unregistered';

// Kayıt dışı mod için tedarikçi listesi çek
$vendors = [];
if ($isUnregisteredMode) {
    
    // Query string'i parçalara ayırarak oluşturuyoruz
    $filterValue = "CardType eq 'cSupplier'";
    $vendorsQuery = 'BusinessPartners?$select=CardCode,CardName&$filter=' . urlencode($filterValue) . '&$orderby=CardName&$top=500';
    $vendorsData = $sap->get($vendorsQuery);
    $vendors = $vendorsData['response']['value'] ?? [];
}

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
    
    // Kayıt dışı mod için ekstra bilgiler
    if (isset($_POST['is_unregistered']) && $_POST['is_unregistered'] === '1') {
        $vendorCode = trim($_POST['vendor_code'] ?? '');
        $irsaliyeNo = trim($_POST['irsaliye_no'] ?? '');
        
        // CardCode ekle (Tedarikçi/Muhatap)
        if (!empty($vendorCode)) {
            $payload['CardCode'] = $vendorCode;
        }
        
        // İrsaliye No'yu Comments'e ekle
        $unregisteredInfo = [];
        if (!empty($irsaliyeNo)) {
            $unregisteredInfo[] = "İrsaliye No: {$irsaliyeNo}";
        }
        if (!empty($unregisteredInfo)) {
            $payload['Comments'] = $comments . ' | ' . implode(' | ', $unregisteredInfo);
        }
    }
    
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
    
    // WhsCode kontrolü
    if (empty($fromWarehouse)) {
        echo json_encode([
            'data' => [],
            'count' => 0,
            'hasMore' => false,
            'error' => 'Ana depo kodu bulunamadı! U_AS_OWNR: ' . $uAsOwnr . ', Branch: ' . $branch
        ]);
        exit;
    }
    
    $skip = intval($_GET['skip'] ?? 0);
    $top = intval($_GET['top'] ?? 25);
    $search = trim($_GET['search'] ?? '');
    $itemNames = isset($_GET['item_names']) ? json_decode($_GET['item_names'], true) : [];
    $itemGroups = isset($_GET['item_groups']) ? json_decode($_GET['item_groups'], true) : [];
    $stockStatus = trim($_GET['stock_status'] ?? '');
    
    // WhsCode ile filtreleme
    $fromWarehouseEscaped = str_replace("'", "''", $fromWarehouse);
    $filter = "WhsCode eq '{$fromWarehouseEscaped}'";

    // Kalem Tanımı filtresi (multi-select) - ItemCode - ItemName formatından parse et
    if (!empty($itemNames) && is_array($itemNames)) {
        $itemNameConditions = [];
        foreach ($itemNames as $itemDisplay) {
            // ✅ Format: "ItemCode - ItemName" veya sadece "ItemName"
            if (strpos($itemDisplay, ' - ') !== false) {
                // ItemCode - ItemName formatı
                list($itemCode, $itemName) = explode(' - ', $itemDisplay, 2);
                $itemCodeEscaped = str_replace("'", "''", trim($itemCode));
                $itemNameEscaped = str_replace("'", "''", trim($itemName));
                // Hem ItemCode hem ItemName ile filtrele
                $itemNameConditions[] = "(ItemCode eq '{$itemCodeEscaped}' or ItemName eq '{$itemNameEscaped}')";
            } else {
                // Sadece ItemName (eski format)
                $itemNameEscaped = str_replace("'", "''", $itemDisplay);
                $itemNameConditions[] = "ItemName eq '{$itemNameEscaped}'";
            }
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
    
    // Şube Stok Durumu filtresi (BranQty alanı kullanılıyor)
    if (!empty($stockStatus)) {
        if ($stockStatus === 'var') {
            $filter .= " and BranQty gt 0";
        } else if ($stockStatus === 'yok') {
            $filter .= " and BranQty le 0";
        }
    }
    
    $itemsQuery = 'view.svc/ASB2B_MainWhsItem_B1SLQuery?$filter=' . urlencode($filter) . '&$orderby=ItemCode&$top=' . $top . '&$skip=' . $skip;
    
    $itemsData = $sap->get($itemsQuery);
    $items = $itemsData['response']['value'] ?? [];

    // Aynı kalemin (ItemCode + ItemName) birden fazla şube / kayıt nedeniyle tekrar etmesini önle
    // Örn: Insomnia'da 100 ve 105 için aynı kalem 2 satır dönüyorsa, burada tekilleştiriyoruz.
    $uniqueItems = [];
    $seenKeys = [];
    foreach ($items as $item) {
        $code = trim($item['ItemCode'] ?? '');
        $name = trim($item['ItemName'] ?? '');
        $key = $code . '|' . $name;
        if (isset($seenKeys[$key])) {
            continue;
        }
        $seenKeys[$key] = true;
        $uniqueItems[] = $item;
    }
    $items = $uniqueItems;
    
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
    
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX: ItemNames ve ItemGroups listesi getir (filtre dropdown'ları için - YENİ: view servisi kullanılıyor)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'filter_options') {
    header('Content-Type: application/json');
    
    if (empty($fromWarehouse)) {
        echo json_encode(['itemNames' => [], 'itemGroups' => []]);
        exit;
    }
    
    $fromWarehouseEscaped = str_replace("'", "''", $fromWarehouse);
    // ✅ ItemCode da dahil edildi (kalem kodları için)
    $itemsQuery = "view.svc/ASB2B_MainWhsItem_B1SLQuery?\$filter=WhsCode eq '{$fromWarehouseEscaped}'&\$select=ItemCode,ItemName,ItemGroup&\$top=1000";
    $itemsData = $sap->get($itemsQuery);
    $items = $itemsData['response']['value'] ?? [];
    
    $itemNames = [];
    $itemGroups = [];
    
    foreach ($items as $item) {
        // ✅ Kalem Tanımı: ItemCode - ItemName formatında
        if (isset($item['ItemCode']) && isset($item['ItemName']) && !empty($item['ItemName'])) {
            $itemDisplay = $item['ItemCode'] . ' - ' . $item['ItemName'];
            if (!in_array($itemDisplay, $itemNames)) {
                $itemNames[] = $itemDisplay;
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
    <title>Dış Tedarik Talebi Oluştur</title>
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
    position: relative;
    overflow-x: hidden;
}

/* Sayfa geçiş animasyonları */
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
    flex-shrink: 0;
    white-space: nowrap;
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
            <h2>Dış Tedarik Talebi Oluştur</h2>
            <div style="display: flex; gap: 12px; align-items: center;">
                <?php if (!$isUnregisteredMode): ?>
                <button class="btn btn-secondary" onclick="navigateToUnregistered()" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; border: none; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);">📦 Kayıt Dışı Gelen Mal</button>
                <?php else: ?>
                <button class="btn btn-secondary" onclick="navigateToNormal()" style="background: #3b82f6; color: white; border: none;">📝 Talep Oluştur</button>
                <?php endif; ?>
                <button class="btn btn-primary sepet-btn" id="sepetToggleBtn" onclick="toggleSepet()" style="position: relative;">
                    🛒 Sepet
                    <span class="sepet-badge" id="sepetBadge" style="display: none;">0</span>
                </button>
                <button class="btn btn-secondary" onclick="window.location.href='DisTedarik.php'">← Geri Dön</button>
            </div>
        </header>

        <div class="content-wrapper">
            <!-- Ana Container - Sepet açıkken ikiye bölünecek -->
            <div class="main-layout-container" id="mainLayoutContainer">
                <!-- Sol taraf: Filtreler ve Tablo -->
                <div class="main-content-left" id="mainContentLeft">
                    <!-- Kayıt Dışı Mod için Bilgi Alanları -->
            <?php if ($isUnregisteredMode): ?>
            <section class="card" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 2px solid #3b82f6; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);">
                <div style="padding: 20px;">
                    <h3 style="margin: 0 0 1rem 0; color: #1e40af; font-size: 1.1rem; font-weight: 600;">📦 Kayıt Dışı Gelen Mal Bilgileri</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                        <div>
                            <label style="display: block; font-weight: 600; color: #1e40af; margin-bottom: 0.5rem;">Tedarikçi / Muhatap *</label>
                            <div style="position: relative;">
                                <div class="vendor-select-container" style="position: relative;">
                                    <input type="text" 
                                           id="vendorInput" 
                                           required
                                           placeholder="Tedarikçi ara veya seçiniz..." 
                                           autocomplete="off"
                                           style="width: 100%; padding: 0.5rem; border: 2px solid #3b82f6; border-radius: 6px; font-size: 0.875rem; background: white; color: #1f2937;"
                                           onkeyup="filterVendors(this.value)"
                                           onfocus="showVendorDropdown()"
                                           onclick="showVendorDropdown()">
                                    <input type="hidden" id="vendorCode" name="vendor_code" required>
                                    <div id="vendorDropdown" class="vendor-dropdown" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 2px solid #3b82f6; border-radius: 6px; max-height: 300px; overflow-y: auto; z-index: 1000; margin-top: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                                        <?php if (empty($vendors)): ?>
                                            <div style="padding: 1rem; text-align: center; color: #6b7280;">
                                                Tedarikçi bulunamadı. Lütfen sayfayı yenileyin.
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($vendors as $vendor): ?>
                                                <div class="vendor-option" 
                                                     data-code="<?= htmlspecialchars($vendor['CardCode'] ?? '') ?>"
                                                     data-name="<?= htmlspecialchars($vendor['CardName'] ?? '') ?>"
                                                     onclick="selectVendor('<?= htmlspecialchars($vendor['CardCode'] ?? '') ?>', '<?= htmlspecialchars($vendor['CardName'] ?? '') ?>')"
                                                     onmouseenter="this.style.backgroundColor='#f3f4f6'"
                                                     onmouseleave="this.style.backgroundColor='white'"
                                                     style="padding: 0.75rem; cursor: pointer; border-bottom: 1px solid #e5e7eb; transition: background-color 0.2s;">
                                                    <div style="font-weight: 600; color: #1f2937;"><?= htmlspecialchars($vendor['CardName'] ?? '') ?></div>
                                                    <div style="font-size: 0.8rem; color: #6b7280;"><?= htmlspecialchars($vendor['CardCode'] ?? '') ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label style="display: block; font-weight: 600; color: #1e40af; margin-bottom: 0.5rem;">İrsaliye Numarası *</label>
                            <input type="text" id="irsaliyeNo" required placeholder="İrsaliye numarasını giriniz" style="width: 100%; padding: 0.5rem; border: 2px solid #3b82f6; border-radius: 6px; font-size: 0.875rem; background: white; color: #1f2937;">
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>
            
                    <!-- Filtreler -->
            <section class="card" id="filterSection">
                <div class="filter-section">
                    <div class="filter-group">
                        
                        <div class="multi-select-container">
                            <div class="multi-select-input" onclick="toggleDropdown('itemName')">
                                <div id="itemNameTags"></div>
                                <input type="text" id="filterItemName" class="filter-input" placeholder="KALEM TANIMI" onkeyup="handleFilterInput('itemName', this.value)" onfocus="openDropdownIfClosed('itemName')" onclick="event.stopPropagation();">
                            </div>
                            <div class="multi-select-dropdown" id="itemNameDropdown">
                                <div class="multi-select-option" data-value="" onclick="selectOption('itemName', '', 'Tümü')">Tümü</div>
                                <div id="itemNameOptions"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        
                        <div class="multi-select-container">
                            <div class="multi-select-input" onclick="toggleDropdown('itemGroup')">
                                <div id="itemGroupTags"></div>
                                <input type="text" id="filterItemGroup" class="filter-input" placeholder="KALEM GRUBU" onkeyup="handleFilterInput('itemGroup', this.value)" onfocus="openDropdownIfClosed('itemGroup')" onclick="event.stopPropagation();">
                            </div>
                            <div class="multi-select-dropdown" id="itemGroupDropdown">
                                <div class="multi-select-option" data-value="" onclick="selectOption('itemGroup', '', 'Tümü')">Tümü</div>
                                <div id="itemGroupOptions"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        
                        <div class="multi-select-container">
                            <div class="multi-select-input" onclick="toggleDropdown('stockStatus')">
                                <div id="stockStatusTags"></div>
                                <input type="text" id="filterStockStatus" class="filter-input" placeholder="ŞUBE STOK DURUMU" readonly>
                            </div>
                            <div class="multi-select-dropdown" id="stockStatusDropdown">
                                <div class="multi-select-option" data-value="" onclick="selectOption('stockStatus', '', 'Tümü')">Tümü</div>
                                <div class="multi-select-option" data-value="Var" onclick="selectOption('stockStatus', 'Var', 'Var')">Var</div>
                                <div class="multi-select-option" data-value="Yok" onclick="selectOption('stockStatus', 'Yok', 'Yok')">Yok</div>
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
                            <th>Şube Miktarı</th>
                            <th>Minimum</th>
                            <th>Talep Miktarı</th>
                            <th>Birim</th>
                            <th>Dönüşüm</th>
                            <th>Varsayılan Tedarikçi</th>
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
                            <?php if ($isUnregisteredMode): ?>
                            <div style="margin-bottom: 1rem; padding: 1rem; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border-radius: 6px; border: 1px solid #3b82f6;">
                                <div style="font-size: 0.875rem; color: #1e40af; margin-bottom: 0.5rem; font-weight: 600;">📦 Kayıt Dışı Bilgiler (Yukarıdan otomatik alınacak)</div>
                                <div style="font-size: 0.8rem; color: #1e3a8a;">
                                    <div><strong>Tedarikçi:</strong> <span id="sepetVendorDisplay">-</span></div>
                                    <div><strong>İrsaliye No:</strong> <span id="sepetIrsaliyeDisplay">-</span></div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div style="text-align: right;">
                                <button class="btn btn-primary" onclick="saveRequest()">✓ <?= $isUnregisteredMode ? 'Talep Oluştur / Teslim Al' : 'Talep Oluştur' ?></button>
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

// Sayfa yüklendiğinde verileri getir (filtre seçenekleri dropdown açıldığında yüklenecek)
document.addEventListener('DOMContentLoaded', function() {
    // ✅ Sayfa yüklendiğinde otomatik olarak verileri getir
    loadItems();
    updateSepet(); // Sepet badge'ini yükle
    
    // ✅ Input'tan focus çıktığında tag'ları tekrar göster
    const filterItemName = document.getElementById('filterItemName');
    const filterItemGroup = document.getElementById('filterItemGroup');
    
    if (filterItemName) {
        filterItemName.addEventListener('blur', function() {
            setTimeout(() => {
                const tagsContainer = document.getElementById('itemNameTags');
                if (tagsContainer && this.value.trim() === '' && selectedItemNames.length > 0) {
                    tagsContainer.style.display = '';
                    updateFilterDisplay('itemName');
                }
                if (this.value.trim() === '' && selectedItemNames.length === 0) {
                    this.placeholder = 'KALEM TANIMI';
                }
            }, 200);
        });
    }
    
    if (filterItemGroup) {
        filterItemGroup.addEventListener('blur', function() {
            setTimeout(() => {
                const tagsContainer = document.getElementById('itemGroupTags');
                if (tagsContainer && this.value.trim() === '' && selectedItemGroups.length > 0) {
                    tagsContainer.style.display = '';
                    updateFilterDisplay('itemGroup');
                }
                if (this.value.trim() === '' && selectedItemGroups.length === 0) {
                    this.placeholder = 'KALEM GRUBU';
                }
            }, 200);
        });
    }
});

// ✅ Türkçe karakter desteği ile büyük/küçük harf duyarsız karşılaştırma
function normalizeForSearch(text) {
    if (!text) return '';
    // Önce Türkçe karakterleri normalize et, sonra küçük harfe çevir
    return text
        .replace(/İ/g, 'i')  // Büyük İ → i
        .replace(/I/g, 'i')  // Büyük I → i (İngilizce I)
        .replace(/ı/g, 'i')   // Küçük ı → i
        .replace(/Ğ/g, 'g')  // Büyük Ğ → g
        .replace(/ğ/g, 'g')   // Küçük ğ → g
        .replace(/Ü/g, 'u')   // Büyük Ü → u
        .replace(/ü/g, 'u')   // Küçük ü → u
        .replace(/Ş/g, 's')   // Büyük Ş → s
        .replace(/ş/g, 's')   // Küçük ş → s
        .replace(/Ö/g, 'o')   // Büyük Ö → o
        .replace(/ö/g, 'o')   // Küçük ö → o
        .replace(/Ç/g, 'c')   // Büyük Ç → c
        .replace(/ç/g, 'c')   // Küçük ç → c
        .toLowerCase()        // Son olarak tüm harfleri küçük harfe çevir
        .trim();
}

function populateDropdowns() {
    // ItemNames dropdown - Filtrelenmiş verileri göster
    const itemNameOptions = document.getElementById('itemNameOptions');
    const filterItemNameInput = document.getElementById('filterItemName');
    const searchText = filterItemNameInput ? normalizeForSearch(filterItemNameInput.value) : '';
    
    if (itemNameOptions) {
        // ✅ Filtreleme: Büyük/küçük harf ve Türkçe karakter duyarsız
        const filtered = searchText 
            ? filteredItemNames.filter(name => normalizeForSearch(name).includes(searchText))
            : filteredItemNames;
        
        itemNameOptions.innerHTML = filtered.map(name => {
            const isSelected = selectedItemNames.includes(name);
            const escapedName = name.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            return `<div class="multi-select-option ${isSelected ? 'selected' : ''}" data-value="${escapedName}" onclick="selectOption('itemName', '${escapedName}', '${escapedName}')">${name}</div>`;
        }).join('');
    }
    
    // ItemGroups dropdown - Filtrelenmiş verileri göster
    const itemGroupOptions = document.getElementById('itemGroupOptions');
    const filterItemGroupInput = document.getElementById('filterItemGroup');
    const searchTextGroup = filterItemGroupInput ? normalizeForSearch(filterItemGroupInput.value) : '';
    
    if (itemGroupOptions) {
        // ✅ Filtreleme: Büyük/küçük harf ve Türkçe karakter duyarsız
        const filtered = searchTextGroup 
            ? filteredItemGroups.filter(group => normalizeForSearch(group).includes(searchTextGroup))
            : filteredItemGroups;
        
        itemGroupOptions.innerHTML = filtered.map(group => {
            const isSelected = selectedItemGroups.includes(group);
            const escapedGroup = group.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            return `<div class="multi-select-option ${isSelected ? 'selected' : ''}" data-value="${escapedGroup}" onclick="selectOption('itemGroup', '${escapedGroup}', '${escapedGroup}')">${group}</div>`;
        }).join('');
    }
}

// ✅ Dropdown'ı aç (eğer kapalıysa)
function openDropdownIfClosed(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    if (dropdown && !dropdown.classList.contains('show')) {
        toggleDropdown(type);
    }
}

// ✅ Input'a yazı yazıldığında dropdown'ı filtrele
function handleFilterInput(type, value) {
    // Dropdown'ı aç (kapalıysa)
    openDropdownIfClosed(type);
    
    // Tag'ları geçici olarak gizle (yazı yazarken)
    const tagsContainer = document.getElementById(type === 'itemName' ? 'itemNameTags' : 'itemGroupTags');
    if (tagsContainer && value.trim() !== '') {
        tagsContainer.style.display = 'none';
    } else if (tagsContainer && value.trim() === '') {
        // Input boşsa ve seçili item'lar varsa tag'ları göster
        const selected = type === 'itemName' ? selectedItemNames : selectedItemGroups;
        if (selected.length > 0) {
            tagsContainer.style.display = '';
        }
    }
    
    // Dropdown'ı güncelle (filtrelenmiş verilerle)
    populateDropdowns();
}

// ✅ AnaDepo.php'deki gibi toggleDropdown fonksiyonu - Dropdown açıldığında SAP'den veri çek
function toggleDropdown(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    const input = document.querySelector(`#filter${type.charAt(0).toUpperCase() + type.slice(1)}`).parentElement;
    const isOpen = dropdown.classList.contains('show');
    
    // Close all dropdowns
    document.querySelectorAll('.multi-select-dropdown').forEach(d => d.classList.remove('show'));
    document.querySelectorAll('.multi-select-input').forEach(d => d.classList.remove('active'));
    
    if (!isOpen) {
        dropdown.classList.add('show');
        input.classList.add('active');
        
        // ✅ Dropdown açıldığında SAP'den veri çek
        if (type === 'itemName' || type === 'itemGroup') {
            loadFilterOptionsForType(type);
        }
    }
}

// ✅ Dropdown açıldığında SAP'den filtre seçeneklerini yükle
function loadFilterOptionsForType(type) {
    // ✅ Önce tablodaki mevcut verilerden dropdown'ları doldur (hızlı görünüm için)
    updateDropdownsFromTable();
    populateDropdowns(); // Tablodaki verilerle hemen göster
    
    // ✅ Sonra SAP'den tüm verileri çek (güncel veriler için)
    fetch('DisTedarikSO.php?ajax=filter_options')
        .then(res => res.json())
        .then(data => {
            const sapItemNames = data.itemNames || [];
            const sapItemGroups = data.itemGroups || [];
            
            // ✅ Mevcut verilerle birleştir (duplicate'leri kaldır)
            const existingItemNames = new Set(allItemNames);
            const existingItemGroups = new Set(allItemGroups);
            
            sapItemNames.forEach(name => existingItemNames.add(name));
            sapItemGroups.forEach(group => existingItemGroups.add(group));
            
            allItemNames = Array.from(existingItemNames).sort();
            allItemGroups = Array.from(existingItemGroups).sort();
            filteredItemNames = allItemNames;
            filteredItemGroups = allItemGroups;
            
            // ✅ Güncellenmiş verilerle dropdown'ları yeniden doldur
            populateDropdowns();
        })
        .catch(err => {
            console.error('Filtre seçenekleri yüklenirken hata:', err);
            // Hata olsa bile tablodaki verilerle dropdown'ı doldur
            populateDropdowns();
        });
}

// ✅ Tablodaki mevcut verilerden dropdown'ları güncelle
function updateDropdownsFromTable() {
    if (!itemsData || itemsData.length === 0) return;
    
    itemsData.forEach(item => {
        const itemCode = item.ItemCode || '';
        const itemName = item.ItemName || item.ItemDescription || '';
        const itemGroup = item.ItemGroup || '';
        
        // ✅ Kalem Tanımı: ItemCode - ItemName formatında ekle
        if (itemCode && itemName) {
            const itemDisplay = itemCode + ' - ' + itemName;
            if (!allItemNames.includes(itemDisplay)) {
                allItemNames.push(itemDisplay);
            }
        }
        
        // ✅ Kalem Grubu ekle
        if (itemGroup && !allItemGroups.includes(itemGroup)) {
            allItemGroups.push(itemGroup);
        }
    });
    
    // Sırala
    allItemNames.sort();
    allItemGroups.sort();
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

// ✅ AnaDepo.php'deki gibi selectOption fonksiyonu (multi-select için)
function selectOption(type, value, text) {
    let selectedArray;
    
    if (type === 'itemName') {
        selectedArray = selectedItemNames;
    } else if (type === 'itemGroup') {
        selectedArray = selectedItemGroups;
    } else if (type === 'stockStatus') {
        // StockStatus için tek seçim (multi-select değil)
        if (value === '') {
            selectedStockStatus = '';
        } else {
            selectedStockStatus = value.toLowerCase();
        }
        updateFilterDisplay('stockStatus');
        currentPage = 0;
        loadItems();
        return;
    } else {
        return;
    }
    
    // Multi-select için toggle mantığı
    if (value === '') {
        // Tümü seçildiğinde tüm seçimleri temizle
        selectedArray.length = 0;
    } else {
        const index = selectedArray.indexOf(value);
        if (index > -1) {
            selectedArray.splice(index, 1);
        } else {
            selectedArray.push(value);
        }
    }
    
    // ✅ Seçim yapıldıktan sonra input'u temizle
    const input = type === 'itemName' ? document.getElementById('filterItemName') : document.getElementById('filterItemGroup');
    if (input) {
        input.value = '';
    }
    
    updateFilterDisplay(type);
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
    let tagsContainer;
    let input;
    let selected;
    
    if (type === 'itemName') {
        tagsContainer = document.getElementById('itemNameTags');
        input = document.getElementById('filterItemName');
        selected = selectedItemNames;
    } else if (type === 'itemGroup') {
        tagsContainer = document.getElementById('itemGroupTags');
        input = document.getElementById('filterItemGroup');
        selected = selectedItemGroups;
    } else if (type === 'stockStatus') {
        tagsContainer = document.getElementById('stockStatusTags');
        input = document.getElementById('filterStockStatus');
        // StockStatus için tek değer
        if (selectedStockStatus === '') {
            input.value = 'Tümü';
            if (tagsContainer) tagsContainer.innerHTML = '';
        } else {
            const text = selectedStockStatus === 'var' ? 'Var' : 'Yok';
            input.value = text;
            if (tagsContainer) {
                tagsContainer.innerHTML = `<span class="multi-select-tag">${text} <span class="remove" onclick="selectOption('stockStatus', '', 'Tümü')">×</span></span>`;
            }
        }
        // Dropdown'daki seçili durumları güncelle
        const dropdown = document.getElementById('stockStatusDropdown');
        if (dropdown) {
            dropdown.querySelectorAll('.single-select-option').forEach(opt => {
                const optValue = opt.getAttribute('data-value');
                if ((selectedStockStatus === '' && optValue === '') || (selectedStockStatus === optValue.toLowerCase())) {
                    opt.classList.add('selected');
                } else {
                    opt.classList.remove('selected');
                }
            });
        }
        return;
    } else {
        return;
    }
    
    if (!tagsContainer || !input) return;
    
    // ✅ Eğer input'ta yazı varsa tag'ları gizle
    if (input.value.trim() !== '') {
        tagsContainer.style.display = 'none';
        return;
    }
    
    // Tag container'ı göster
    tagsContainer.style.display = '';
    tagsContainer.innerHTML = '';
    
    if (selected.length === 0) {
        input.placeholder = 'DURUMU';
        input.value = '';
    } else {
        input.placeholder = '';
        input.value = '';
        selected.forEach(value => {
            const escapedValue = value.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            const tag = document.createElement('span');
            tag.className = 'multi-select-tag';
            tag.innerHTML = `${value} <span class="remove" onclick="removeFilter('${type}', '${escapedValue}')">×</span>`;
            tagsContainer.appendChild(tag);
        });
    }
    
    // Dropdown'daki seçili durumları güncelle
    const dropdown = document.getElementById(type + 'Dropdown');
    if (dropdown) {
        dropdown.querySelectorAll('.multi-select-option').forEach(opt => {
            const value = opt.getAttribute('data-value');
            if (selected.includes(value)) {
                opt.classList.add('selected');
            } else {
                opt.classList.remove('selected');
            }
        });
    }
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
            hasMore = data.hasMore || false;
            itemsData = data.data || []; // YENİ: Items data'sını global scope'ta sakla
            
            // ✅ Tablodaki verilerden dropdown'ları güncelle
            updateDropdownsFromTable();
            
            renderItems(itemsData);
            updatePagination();
        })
        .catch(err => {
            console.error('Hata:', err);
            document.getElementById('itemsTableBody').innerHTML = 
                '<tr><td colspan="9" style="text-align:center;color:#dc3545;">Veri yüklenirken hata oluştu. Console\'u kontrol edin.</td></tr>';
        });
}

function renderItems(items) {
    const tbody = document.getElementById('itemsTableBody');
    
    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#888;">Kayıt bulunamadı.</td></tr>';
        return;
    }
    
    // Miktar formatı: 10.00 → 10, 10.5 → 10,5, 10.25 → 10,25
    function formatQuantity(qty) {
        const num = parseFloat(qty);
        if (isNaN(num)) return '0';
        // Tam sayı ise küsurat gösterme
        if (num % 1 === 0) {
            return num.toString();
        }
        // Küsurat varsa virgül ile göster
        return num.toString().replace('.', ',');
    }
    
    tbody.innerHTML = items.map(item => {
        const itemCode = item.ItemCode || '';
        const itemName = item.ItemName || item.ItemDescription || '';
        const itemGroup = item.ItemGroup || '-';
        const mainQty = item.MainQty || item._stock || 0; // Ana depo miktarı
        const branQty = item.BranQty || 0; // Şube miktarı
        const minQty = item.MinQty || 0;
        const uomCode = item.UomCode || item.UoMCode || '-';
        const baseQty = parseFloat(item.BaseQty || 1.0); // BaseQty view'den geliyor
        const uomConvert = parseFloat(item.UomConvert || item.UOMConvert || 1); // UomConvert view'den geliyor
        const fromWhsName = item.FromWhsName || '-';
        const defaultVendor = item.DefaultVendor || '-';
        const isInSepet = selectedItems.hasOwnProperty(itemCode);
        const sepetQty = isInSepet ? selectedItems[itemCode].quantity : 0;
        
        // Dönüşüm kolonu: BaseQty kullanarak hesaplama gösterimi
        let conversionText = '-';
        if (baseQty && baseQty !== 1 && baseQty > 0) {
            if (sepetQty > 0) {
                // Talep miktarı × BaseQty = AD karşılığı formatında göster
                const adKarşılığı = sepetQty * baseQty;
                conversionText = `${formatQuantity(sepetQty)}x${formatQuantity(baseQty)} = ${formatQuantity(adKarşılığı)} AD`;
            } else {
                // Sipariş miktarı yoksa örnek göster: 1xBaseQty = BaseQty AD
                conversionText = `1x${formatQuantity(baseQty)} = ${formatQuantity(baseQty)} AD`;
            }
        } else if (baseQty === 1) {
            // Standart (1 adet) ise sadece miktarı göster veya boş bırak
            if (sepetQty > 0) {
                conversionText = formatQuantity(sepetQty);
            } else {
                conversionText = '-';
            }
        }
        
        return `
            <tr>
                <td>${itemCode}</td>
                <td>${itemName}</td>
                <td>${itemGroup}</td>
                <td>${formatQuantity(branQty)}</td>
                <td>${formatQuantity(minQty)}</td>
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
            // Item bilgilerini bul (tablodan veya data'dan)
            const row = document.getElementById('qty_' + itemCode).closest('tr');
            const itemName = row.cells[1].textContent;
            
            // BaseQty ve UomCode bilgilerini itemsData'dan bul
            const itemData = itemsData.find(i => i.ItemCode === itemCode);
            const baseQty = itemData ? parseFloat(itemData.BaseQty || 1.0) : 1.0;
            const uomCode = itemData ? (itemData.UomCode || itemData.UoMCode || '') : '';
            const defaultVendor = itemData ? (itemData.DefaultVendor || '') : '';
            
            selectedItems[itemCode] = {
                itemCode: itemCode,
                itemName: itemName,
                quantity: qty,
                baseQty: baseQty,  // YENİ: BaseQty eklendi
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
        updateUnregisteredInfoInSepet(); // Kayıt dışı mod için bilgileri güncelle
    }
}

function updateSepet() {
    const list = document.getElementById('sepetList');
    const badge = document.getElementById('sepetBadge');
    const itemCount = Object.keys(selectedItems).length;
    
    // Miktar formatı: 10.00 → 10, 10.5 → 10,5, 10.25 → 10,25
    function formatQuantity(qty) {
        const num = parseFloat(qty);
        if (isNaN(num)) return '0';
        // Tam sayı ise küsurat gösterme
        if (num % 1 === 0) {
            return num.toString();
        }
        // Küsurat varsa virgül ile göster
        return num.toString().replace('.', ',');
    }
    
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
    
    list.innerHTML = Object.values(selectedItems).map(item => {
        const qty = parseFloat(item.quantity) || 0;
        const baseQty = parseFloat(item.baseQty || 1.0);
        const uomCode = item.uomCode || 'AD';
        
        // Miktar + birim gösterimi
        let qtyDisplay = `${formatQuantity(qty)} ${uomCode}`;
        
        // Eğer çevrimli ise (BaseQty !== 1), AD karşılığını da göster
        let conversionInfo = '';
        if (baseQty !== 1 && baseQty > 0) {
            const adKarşılığı = qty * baseQty;
            qtyDisplay += ` <span style="font-size: 0.85rem; color: #6b7280; font-weight: normal;">(${formatQuantity(adKarşılığı)} AD)</span>`;
            conversionInfo = `<div style="font-size: 0.8rem; color: #3b82f6; margin-top: 4px;">Dönüşüm: ${formatQuantity(qty)}x${formatQuantity(baseQty)} = ${formatQuantity(adKarşılığı)} AD</div>`;
        }
        
        return `
        <div class="sepet-item">
            <div class="sepet-item-info">
                <div class="sepet-item-name">${item.itemCode} - ${item.itemName}</div>
                <div style="margin-bottom: 0.5rem; font-size: 0.9rem; color: #3b82f6; font-weight: 600;">${qtyDisplay}</div>
                ${conversionInfo}
                <div class="sepet-item-qty">
                    <button type="button" class="qty-btn" onclick="changeQuantity('${item.itemCode}', -1)">-</button>
                    <input type="number" 
                           value="${qty}" 
                           min="0" 
                           step="0.01"
                           onchange="updateQuantity('${item.itemCode}', this.value)"
                           oninput="updateQuantity('${item.itemCode}', this.value)">
                    <button type="button" class="qty-btn" onclick="changeQuantity('${item.itemCode}', 1)">+</button>
                </div>
        </div>
            <button type="button" class="remove-sepet-btn" onclick="removeFromSepet('${item.itemCode}')">Kaldır</button>
        </div>
        `;
    }).join('');
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
    
    // Kayıt dışı mod kontrolü
    const urlParams = new URLSearchParams(window.location.search);
    const isUnregisteredMode = urlParams.get('mode') === 'unregistered';
    
    const formData = new FormData();
    formData.append('action', 'create_request');
    formData.append('items', JSON.stringify(items));
    formData.append('comments', comments);
    formData.append('required_date', requiredDate);
    
    // Kayıt dışı mod için ekstra bilgiler
        if (isUnregisteredMode) {
            const vendorCode = document.getElementById('vendorCode')?.value || '';
            const irsaliyeNo = document.getElementById('irsaliyeNo')?.value || '';
            
            if (!vendorCode || !irsaliyeNo) {
                alert('Lütfen tüm kayıt dışı bilgilerini doldurun! (Tedarikçi, İrsaliye No)');
                return;
            }
            
            formData.append('is_unregistered', '1');
            formData.append('vendor_code', vendorCode);
            formData.append('irsaliye_no', irsaliyeNo);
        }
    
    const confirmMsg = isUnregisteredMode 
        ? 'Kayıt dışı gelen mal için talebi oluşturmak istediğinize emin misiniz?'
        : 'Talebi oluşturmak istediğinize emin misiniz?';
    
    if (!confirm(confirmMsg)) {
        return;
    }
    
    fetch('DisTedarikSO.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(isUnregisteredMode ? 'Kayıt dışı gelen mal talebi başarıyla oluşturuldu!' : 'Talep başarıyla oluşturuldu!');
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

// Kayıt dışı mod için sepet panelinde bilgileri güncelle
// Tedarikçi dropdown fonksiyonları
function showVendorDropdown() {
    const dropdown = document.getElementById('vendorDropdown');
    if (dropdown) {
        dropdown.style.display = 'block';
    }
}

function hideVendorDropdown() {
    const dropdown = document.getElementById('vendorDropdown');
    if (dropdown) {
        dropdown.style.display = 'none';
    }
}

function filterVendors(searchText) {
    const dropdown = document.getElementById('vendorDropdown');
    if (!dropdown) return;
    
    const options = dropdown.querySelectorAll('.vendor-option');
    const searchLower = normalizeForSearch(searchText);
    
    options.forEach(option => {
        const name = option.getAttribute('data-name') || '';
        const code = option.getAttribute('data-code') || '';
        const nameNormalized = normalizeForSearch(name);
        const codeNormalized = normalizeForSearch(code);
        
        if (searchLower === '' || nameNormalized.includes(searchLower) || codeNormalized.includes(searchLower)) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
    
    // Dropdown'ı göster
    showVendorDropdown();
}

function selectVendor(cardCode, cardName) {
    const vendorInput = document.getElementById('vendorInput');
    const vendorCode = document.getElementById('vendorCode');
    
    if (vendorInput) {
        vendorInput.value = cardName;
    }
    if (vendorCode) {
        vendorCode.value = cardCode;
    }
    
    hideVendorDropdown();
    updateUnregisteredInfoInSepet();
}

function updateUnregisteredInfoInSepet() {
    const urlParams = new URLSearchParams(window.location.search);
    const isUnregisteredMode = urlParams.get('mode') === 'unregistered';
    
    if (!isUnregisteredMode) return;
    
    const vendorInput = document.getElementById('vendorInput');
    const vendorCode = document.getElementById('vendorCode');
    const irsaliyeNo = document.getElementById('irsaliyeNo');
    
    if (vendorInput && vendorCode && irsaliyeNo) {
        const vendorDisplay = document.getElementById('sepetVendorDisplay');
        const irsaliyeDisplay = document.getElementById('sepetIrsaliyeDisplay');
        
        if (vendorDisplay) {
            vendorDisplay.textContent = vendorInput.value || '-';
        }
        if (irsaliyeDisplay) {
            irsaliyeDisplay.textContent = irsaliyeNo.value || '-';
        }
    }
}

// Sayfa geçiş animasyonu - Normal talep oluştur
function navigateToNormal() {
    const mainContent = document.querySelector('.main-content');
    
    // Normal talep oluştur: sola kayarak çık
    mainContent.classList.add('page-slide-out-left');
    
    // Animasyon bitince sayfayı yükle
    setTimeout(() => {
        window.location.href = 'DisTedarikSO.php';
    }, 300);
}

// Sayfa geçiş animasyonu - Kayıt dışı gelen mal
function navigateToUnregistered() {
    const mainContent = document.querySelector('.main-content');
    
    // Kayıt dışı gelen mal: sağa kayarak çık
    mainContent.classList.add('page-slide-out-right');
    
    // Animasyon bitince sayfayı yükle
    setTimeout(() => {
        window.location.href = 'DisTedarikSO.php?mode=unregistered';
    }, 300);
}

// Kayıt dışı alanlar değiştiğinde sepet panelini güncelle
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const isUnregisteredMode = urlParams.get('mode') === 'unregistered';
    const mainContent = document.querySelector('.main-content');
    
    // Sayfa yüklendiğinde animasyon ekle (kısa bir gecikme ile daha smooth)
    setTimeout(() => {
        if (isUnregisteredMode) {
            // Kayıt dışı mod: sağdan kayarak gel
            mainContent.style.animation = 'slideInFromRight 0.6s ease-out';
        } else {
            // Normal mod: soldan kayarak gel
            mainContent.style.animation = 'slideInFromLeft 0.6s ease-out';
        }
    }, 50);
    
    if (isUnregisteredMode) {
        const vendorInput = document.getElementById('vendorInput');
        const irsaliyeNo = document.getElementById('irsaliyeNo');
        
        if (vendorInput) {
            vendorInput.addEventListener('input', updateUnregisteredInfoInSepet);
        }
        if (irsaliyeNo) {
            irsaliyeNo.addEventListener('input', updateUnregisteredInfoInSepet);
        }
    }
});

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.multi-select-container') && !e.target.closest('.single-select-container') && !e.target.closest('.vendor-select-container')) {
        document.querySelectorAll('.multi-select-dropdown').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.multi-select-input').forEach(d => d.classList.remove('active'));
        document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
        hideVendorDropdown();
    }
});
    </script>
</body>
</html>
