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
$branch = $_SESSION["WhsCode"] ?? $_SESSION["Branch2"]["Name"] ?? '';
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

$branch = (string)$branch;

// ToWarehouse (talep eden depo - sevkiyat deposu) - POST işlemi için gerekli
$toWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_MAIN eq '2' and U_ASB2B_BRAN eq '{$branch}'";
$toWarehouseQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($toWarehouseFilter);
$toWarehouseData = $sap->get($toWarehouseQuery);
$toWarehouses = $toWarehouseData['response']['value'] ?? [];
$toWarehouse = !empty($toWarehouses) ? $toWarehouses[0]['WarehouseCode'] : null;

// Diğer şubeler (filtreleme için) - View'dan FromWhsName kullanılacak, sadece isim listesi için gerekli
$allOtherWarehousesFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_MAIN eq '1'";
$allOtherWarehousesQuery = "Warehouses?\$select=WarehouseCode,WarehouseName,U_ASB2B_BRAN&\$filter=" . urlencode($allOtherWarehousesFilter);
$allOtherWarehousesData = $sap->get($allOtherWarehousesQuery);
$allOtherWarehouses = $allOtherWarehousesData['response']['value'] ?? [];

$otherWarehouses = [];
foreach ($allOtherWarehouses as $whs) {
    if ((string)($whs['U_ASB2B_BRAN'] ?? '') !== $branch) {
        $otherWarehouses[] = $whs;
    }
}

// POST işlemi: InventoryTransferRequests oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_request') {
    header('Content-Type: application/json');

    $selectedItems = json_decode($_POST['items'] ?? '[]', true);
    
    if (empty($selectedItems)) {
        echo json_encode(['success' => false, 'message' => 'Lütfen en az bir kalem seçin!']);
        exit;
    }
    
    // ToWarehouse kontrolü: Kullanıcının kendi şubesinin sevkiyat deposu olmalı
    // FromWarehouse kontrolü yapmıyoruz çünkü her item farklı şubeden olabilir
    if (empty($toWarehouse)) {
        $errorMsg = 'ToWarehouse (Sevkiyat Deposu) bulunamadı. ';
        $errorMsg .= 'Şube: ' . htmlspecialchars($branch) . ' için sevkiyat deposu (U_ASB2B_MAIN=2) SAP\'de tanımlı değil.';
        
        echo json_encode([
            'success' => false, 
            'message' => $errorMsg
        ]);
        exit;
    }

    // Sepetteki ürünleri FromWarehouse'a göre grupla
    $itemsByFromWarehouse = [];
    foreach ($selectedItems as $item) {
        $userQuantity = floatval($item['quantity'] ?? 0);
        if ($userQuantity > 0) {
            $itemFromWarehouse = $item['fromWhsCode'] ?? '';
            if (empty($itemFromWarehouse)) continue;
            
            if (!isset($itemsByFromWarehouse[$itemFromWarehouse])) {
                $itemsByFromWarehouse[$itemFromWarehouse] = [];
            }
            
            $itemsByFromWarehouse[$itemFromWarehouse][] = [
                'ItemCode' => $item['itemCode'] ?? '',
                'Quantity' => $userQuantity * floatval($item['baseQty'] ?? 1.0),
                'FromWarehouseCode' => $itemFromWarehouse,
                'WarehouseCode' => $toWarehouse,
                'U_ASB2B_STATUS' => '1', 
            ]; 
        }
    }
    
    if (empty($itemsByFromWarehouse)) {
        echo json_encode(['success' => false, 'message' => 'Miktarı girilen kalem bulunamadı!']);
        exit;
    }
    
    // Her FromWarehouse için ayrı InventoryTransferRequest oluştur
    $results = [];
    $successCount = 0;
    $errorCount = 0;
    $errorMessages = [];
    
    foreach ($itemsByFromWarehouse as $fromWhs => $stockTransferLines) {
        $payload = [
            'DocDate' => date('Y-m-d'),
            'FromWarehouse' => $fromWhs,
            'ToWarehouse' => $toWarehouse,
            'Comments' => 'Transfer nakil talebi',
            'U_ASB2B_BRAN' => $branch,
            'U_AS_OWNR' => $uAsOwnr,
            'U_ASB2B_TYPE' => 'TRANSFER',
            'U_ASB2B_User' => $userName,
            'StockTransferLines' => $stockTransferLines
        ];
        
        $result = $sap->post('InventoryTransferRequests', $payload);
        
        if ($result['status'] == 200 || $result['status'] == 201) {
            $successCount++;
            $results[] = [
                'fromWarehouse' => $fromWhs,
                'success' => true,
                'data' => $result
            ];
        } else {
            $errorCount++;
            $errorMsg = 'FromWarehouse: ' . $fromWhs . ' - HTTP ' . ($result['status'] ?? 'NO STATUS');
            if (isset($result['response']['error'])) {
                $errorMsg .= ' - ' . json_encode($result['response']['error']);
            }
            $errorMessages[] = $errorMsg;
            $results[] = [
                'fromWarehouse' => $fromWhs,
                'success' => false,
                'error' => $errorMsg,
                'response' => $result
            ];
        }
    }
    
    // Sonuçları döndür
    if ($errorCount == 0) {
        // Tüm belgeler başarılı
        $message = $successCount > 1 
            ? "{$successCount} adet transfer talebi başarıyla oluşturuldu!" 
            : 'Transfer talebi başarıyla oluşturuldu!';
        echo json_encode([
            'success' => true, 
            'message' => $message, 
            'count' => $successCount,
            'results' => $results
        ]);
    } elseif ($successCount > 0) {
        // Bazı belgeler başarılı, bazıları başarısız
        $message = "{$successCount} adet transfer talebi oluşturuldu, {$errorCount} adet başarısız oldu. ";
        $message .= "Hatalar: " . implode('; ', $errorMessages);
        echo json_encode([
            'success' => false, 
            'message' => $message,
            'successCount' => $successCount,
            'errorCount' => $errorCount,
            'results' => $results
        ]);
    } else {
        // Tüm belgeler başarısız
        $errorMsg = 'Transfer talepleri oluşturulamadı! ';
        $errorMsg .= implode('; ', $errorMessages);
        echo json_encode([
            'success' => false, 
            'message' => $errorMsg,
            'results' => $results
        ]);
    }
    exit;
}

// AJAX: Items listesi getir
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'items') {
    header('Content-Type: application/json');
    
    // Items listesi için ToWarehouse gerekli değil, sadece FromWhsName ile filtreleme yapılıyor
    // ToWarehouse sadece POST işleminde (transfer talebi oluştururken) gerekli
    if (empty($otherWarehouses)) {
        echo json_encode([
            'data' => [],
            'count' => 0,
            'hasMore' => false,
            'error' => 'Diğer şube depo bilgileri bulunamadı!'
        ]);
        exit;
    }

    $skip = intval($_GET['skip'] ?? 0);
    $top = intval($_GET['top'] ?? 25);
    $search = trim($_GET['search'] ?? '');
    $itemNames = isset($_GET['item_names']) ? json_decode($_GET['item_names'], true) : [];
    $itemGroups = isset($_GET['item_groups']) ? json_decode($_GET['item_groups'], true) : [];
    $branches = isset($_GET['branches']) ? json_decode($_GET['branches'], true) : [];
    $stockStatus = trim($_GET['stock_status'] ?? '');
    
    // FromWhsName filtresi
    $fromWhsNameConditions = [];
    if (!empty($branches) && is_array($branches)) {
        foreach ($branches as $branchName) {
            $fromWhsNameConditions[] = "FromWhsName eq '" . str_replace("'", "''", $branchName) . "'";
        }
    } else {
        foreach ($otherWarehouses as $whs) {
            $whsName = $whs['WarehouseName'] ?? '';
            if (!empty($whsName)) {
                $fromWhsNameConditions[] = "FromWhsName eq '" . str_replace("'", "''", $whsName) . "'";
            }
        }
    }
    
    if (empty($fromWhsNameConditions)) {
        echo json_encode(['data' => [], 'count' => 0, 'hasMore' => false, 'error' => 'Diğer şube depo adları bulunamadı!']);
        exit;
    }
    
    // Sadece FromWhsName ile filtreleme (AnaDepoSO.php'deki gibi)
    // WhsCode filtresi kaldırıldı - view'de WhsCode değerleri farklı olabilir
    $filter = "(" . implode(" or ", $fromWhsNameConditions) . ")";
    
    if (!empty($search)) {
        $searchEscaped = str_replace("'", "''", $search);
        $filter .= " and (contains(ItemCode, '{$searchEscaped}') or contains(ItemName, '{$searchEscaped}'))";
    }
    
    if (!empty($itemNames) && is_array($itemNames)) {
        $itemNameConditions = [];
        foreach ($itemNames as $itemDisplay) {
            if (strpos($itemDisplay, ' - ') !== false) {
                list($itemCode, $itemName) = explode(' - ', $itemDisplay, 2);
                $itemCodeEscaped = str_replace("'", "''", trim($itemCode));
                $itemNameEscaped = str_replace("'", "''", trim($itemName));
                $itemNameConditions[] = "(ItemCode eq '{$itemCodeEscaped}' or ItemName eq '{$itemNameEscaped}')";
            } else {
                $itemNameEscaped = str_replace("'", "''", $itemDisplay);
                $itemNameConditions[] = "ItemName eq '{$itemNameEscaped}'";
            }
        }
        if (!empty($itemNameConditions)) {
            $filter .= " and (" . implode(" or ", $itemNameConditions) . ")";
        }
    }
    
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
    
    if (!empty($stockStatus)) {
        $filter .= $stockStatus === 'var' ? " and OtherBranQty gt 0" : " and OtherBranQty le 0";
    }
    
    // View sorgusunu çalıştır
    $itemsQuery = "view.svc/ASB2B_BranchWhsItem_B1SLQuery?\$filter=" . urlencode($filter) . "&\$orderby=ItemCode&\$top={$top}&\$skip={$skip}";
    $itemsData = $sap->get($itemsQuery);
    $items = $itemsData['response']['value'] ?? [];

    // Deduplication
    $uniqueItems = [];
    $seenKeys = [];
    foreach ($items as $item) {
        $key = trim($item['ItemCode'] ?? '') . '|' . trim($item['FromWhsCode'] ?? '');
        if (!isset($seenKeys[$key])) {
            $seenKeys[$key] = true;
            $otherBranQty = floatval($item['OtherBranQty'] ?? 0);
            $item['_stock'] = $otherBranQty;
            $item['_hasStock'] = $otherBranQty > 0;
            $item['MainQty'] = $otherBranQty;
            $uniqueItems[] = $item;
        }
    }
    
    echo json_encode([
        'data' => $uniqueItems,
        'count' => count($uniqueItems),
        'hasMore' => count($uniqueItems) >= $top
    ]);
    exit;
}

// AJAX: Filter options
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'filter_options') {
    header('Content-Type: application/json');
    
    if (empty($otherWarehouses)) {
        echo json_encode(['itemNames' => [], 'itemGroups' => [], 'branches' => []]);
        exit;
    }
    
    $branchList = array_filter(array_map(function($whs) { return $whs['WarehouseName'] ?? ''; }, $otherWarehouses));
    sort($branchList);
    
    $fromWhsNameConditions = [];
    foreach ($otherWarehouses as $whs) {
        $whsName = $whs['WarehouseName'] ?? '';
        if (!empty($whsName)) {
            $fromWhsNameConditions[] = "FromWhsName eq '" . str_replace("'", "''", $whsName) . "'";
        }
    }
    
    if (empty($fromWhsNameConditions)) {
        echo json_encode(['itemNames' => [], 'itemGroups' => [], 'branches' => $branchList]);
        exit;
    }
    
    // Sadece FromWhsName ile filtreleme
    $filter = "(" . implode(" or ", $fromWhsNameConditions) . ")";
    
    // View sorgusunu çalıştır
    $itemsQuery = "view.svc/ASB2B_BranchWhsItem_B1SLQuery?\$filter=" . urlencode($filter) . "&\$select=ItemCode,ItemName,ItemGroup,FromWhsName&\$top=1000";
    $itemsData = $sap->get($itemsQuery);
    $items = $itemsData['response']['value'] ?? [];
    
    $itemNames = [];
    $itemGroups = [];
    foreach ($items as $item) {
        if (!empty($item['ItemCode']) && !empty($item['ItemName'])) {
            $itemDisplay = $item['ItemCode'] . ' - ' . $item['ItemName'];
            if (!in_array($itemDisplay, $itemNames)) {
                $itemNames[] = $itemDisplay;
            }
        }
        if (!empty($item['ItemGroup']) && !in_array($item['ItemGroup'], $itemGroups)) {
            $itemGroups[] = $item['ItemGroup'];
        }
    }
    
    sort($itemNames);
    sort($itemGroups);
    
    echo json_encode(['itemNames' => $itemNames, 'itemGroups' => $itemGroups, 'branches' => $branchList]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transfer Oluştur - CREMMAVERSE</title>
<link rel="stylesheet" href="styles.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;background:#f5f7fa;color:#2c3e50;line-height:1.6}
.main-content{width:100%;background:whitesmoke;padding:0;min-height:100vh}
.page-header{background:white;padding:20px 2rem;border-radius:0 0 0 20px;box-shadow:0 2px 8px rgba(0,0,0,0.1);display:flex;justify-content:space-between;align-items:center;margin:0;position:sticky;top:0;z-index:100;height:80px;box-sizing:border-box}
.page-header h2{color:#1e40af;font-size:1.75rem;font-weight:600}
.content-wrapper{padding:24px 32px;max-width:1400px;margin:0 auto;display:flex;flex-direction:column;gap:1.5rem}
.card{background:white;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,0.1);margin-bottom:0}
.alert{padding:1rem 1.5rem;border-radius:8px;margin-bottom:1.5rem}
.alert-warning{background:#fef3c7;border:1px solid #f59e0b;color:#92400e}
.btn{padding:0.625rem 1.25rem;border:none;border-radius:8px;font-size:0.95rem;font-weight:500;cursor:pointer;transition:all 0.2s;display:inline-flex;align-items:center;gap:0.5rem}
.btn-primary{background:linear-gradient(135deg,#3b82f6 0%,#2563eb 100%);color:white}
.btn-primary:hover{background:linear-gradient(135deg,#2563eb 0%,#1d4ed8 100%);transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,0.3)}
.btn-secondary{background:#f3f4f6;color:#374151}
.btn-secondary:hover{background:#e5e7eb}
.filter-section{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;margin-bottom:20px;padding:20px;background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%);border-radius:8px;border:1px solid #e0e0e0}
.filter-group{display:flex;flex-direction:column}
.filter-group label{font-weight:600;color:#1e40af;font-size:0.9rem}
.filter-input,.filter-select{padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;transition:border-color 0.3s}
.filter-input:focus,.filter-select:focus{outline:none;border-color:#1e40af;box-shadow:0 0 0 2px rgba(255,87,34,0.1)}
.multi-select-container{position:relative}
.multi-select-input{display:flex;flex-wrap:wrap;gap:5px;padding:8px 12px;border:1px solid #ddd;border-radius:6px;min-height:40px;cursor:pointer;background:white}
.multi-select-input:hover{border-color:#1e40af}
.multi-select-input.active{border-color:#1e40af;box-shadow:0 0 0 2px rgba(255,87,34,0.1)}
.multi-select-input input{border:none;outline:none;flex:1;background:transparent;min-width:120px;font-size:14px;cursor:pointer}
.multi-select-tag{display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#3b82f6 0%,#2563eb 100%);color:white;padding:4px 10px;border-radius:16px;font-size:0.85rem;font-weight:500}
.multi-select-tag .remove{cursor:pointer;font-weight:bold}
.multi-select-dropdown{position:absolute;top:100%;left:0;right:0;background:white;border:1px solid #ddd;border-radius:6px;max-height:200px;overflow-y:auto;z-index:1000;display:none;box-shadow:0 2px 8px rgba(0,0,0,0.1);margin-top:4px}
.multi-select-dropdown.show{display:block}
.multi-select-option{padding:10px 14px;cursor:pointer;border-bottom:1px solid #f3f4f6;transition:background 0.15s;font-size:0.9rem}
.multi-select-option:hover{background:#f9fafb}
.multi-select-option.selected{background:#dbeafe;color:#1e40af;font-weight:500}
.table-controls{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:1rem}
.show-entries{display:flex;align-items:center;gap:0.5rem;color:#4b5563;font-size:0.9rem}
.entries-select{padding:0.5rem 0.75rem;border:2px solid #e5e7eb;border-radius:6px;background:white;font-size:0.9rem;cursor:pointer;transition:border-color 0.2s}
.entries-select:focus{outline:none;border-color:#3b82f6}
.search-box{display:flex;gap:0.5rem;align-items:center}
.search-input{padding:0.5rem 0.75rem;border:2px solid #e5e7eb;border-radius:6px;min-width:220px;font-size:0.9rem;transition:border-color 0.2s}
.search-input:focus{outline:none;border-color:#3b82f6}
.data-table{width:100%;border-collapse:collapse;font-size:0.9rem}
.data-table thead{background:linear-gradient(135deg,#1e40af 0%,#1e3a8a 100%);color:white}
.data-table th{padding:1rem;text-align:left;font-weight:600;font-size:0.85rem;text-transform:uppercase;letter-spacing:0.5px}
.data-table tbody tr{border-bottom:1px solid #e5e7eb;transition:background 0.15s}
.data-table tbody tr:hover{background:#f9fafb}
.data-table td{padding:1rem;color:#374151}
.quantity-controls{display:flex;gap:6px;align-items:center;justify-content:center}
.qty-btn{padding:6px 12px;border:2px solid #e5e7eb;background:white;border-radius:6px;cursor:pointer;font-weight:600;font-size:1rem;min-width:36px;transition:all 0.2s;color:#374151}
.qty-btn:hover{background:#f3f4f6;border-color:#3b82f6;color:#3b82f6}
.qty-input{width:90px;text-align:center;padding:0.5rem;border:2px solid #e5e7eb;border-radius:6px;font-size:0.95rem;transition:border-color 0.2s}
.qty-input:focus{outline:none;border-color:#3b82f6}
.stock-badge{padding:6px 12px;border-radius:16px;font-size:0.8rem;font-weight:600;display:inline-block;text-transform:uppercase;letter-spacing:0.5px}
.stock-yes{background:#d1fae5;color:#065f46}
.stock-no{background:#fee2e2;color:#991b1b}
.sepet-badge{position:absolute;top:-8px;right:-8px;background:#ef4444;color:white;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid white;box-shadow:0 2px 4px rgba(0,0,0,0.2)}
.sepet-btn{position:relative}
.main-layout-container{display:flex;gap:24px;transition:all 0.3s ease;padding:0}
.main-content-left{flex:1;transition:flex 0.3s ease;display:flex;flex-direction:column;gap:24px}
.main-content-right.sepet-panel{flex:1;min-width:400px;max-width:500px;display:flex;flex-direction:column}
.main-content-right.sepet-panel .card{margin:0}
.sepet-panel{animation:slideIn 0.3s ease}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
.sepet-item{display:flex;justify-content:space-between;align-items:center;padding:1rem;border-bottom:1px solid #bfdbfe;background:white;margin-bottom:0.75rem;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.05)}
.sepet-item:last-child{border-bottom:none;margin-bottom:0}
.sepet-item-info{flex:1}
.sepet-item-name{font-weight:600;margin-bottom:8px;color:#1e40af}
.sepet-item-qty{display:flex;gap:8px;align-items:center}
.sepet-item-qty input{width:90px;text-align:center;padding:6px;border:2px solid #e5e7eb;border-radius:6px;font-size:0.9rem}
.remove-sepet-btn{background:linear-gradient(135deg,#ef4444 0%,#dc2626 100%);color:white;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:0.85rem;font-weight:500;transition:all 0.2s}
.remove-sepet-btn:hover{background:linear-gradient(135deg,#dc2626 0%,#b91c1c 100%);transform:translateY(-1px);box-shadow:0 4px 12px rgba(220,38,38,0.3)}
.pagination{display:flex;justify-content:center;gap:0.75rem;align-items:center;margin-top:1.5rem}
.pagination button:disabled{opacity:0.5;cursor:not-allowed}
#pageInfo{color:#4b5563;font-weight:500;min-width:100px;text-align:center}
@media (max-width:768px){.content-wrapper{padding:16px 20px}.filter-section{grid-template-columns:1fr}.table-controls{flex-direction:column;align-items:stretch}.data-table{font-size:0.85rem}.data-table th,.data-table td{padding:0.75rem 0.5rem}}
</style>
</head>
<body>
<div class="app-container">
    <?php include 'navbar.php'; ?>
    <main class="main-content">
        <header class="page-header">
            <h2>Transfer Oluştur</h2>
            <div style="display: flex; gap: 12px; align-items: center;">
                <button class="btn btn-primary sepet-btn" id="sepetToggleBtn" onclick="toggleSepet()" style="position: relative;">
                    🛒 Sepet
                    <span class="sepet-badge" id="sepetBadge" style="display: none;">0</span>
                </button>
                <button class="btn btn-secondary" onclick="window.location.href='Transferler.php'">← Geri Dön</button>
        </div>
        </header>

        <div class="content-wrapper">
            <?php if (empty($toWarehouse)): ?>
                <div class="alert alert-warning">
                    <strong>Uyarı:</strong> ToWarehouse bulunamadı (Şube: <?= htmlspecialchars($branch) ?>). 
                    Transfer talebi oluşturmak için ToWarehouse gerekli. Ürün listesini görmek için sorun yok.
                </div>
            <?php endif; ?>
            <?php if (empty($otherWarehouses)): ?>
                <div class="alert alert-danger">
                    <strong>HATA:</strong> Diğer şube depo bilgileri bulunamadı! Ürün listesi yüklenemez.
                </div>
            <?php endif; ?>

            <div class="main-layout-container" id="mainLayoutContainer">
                <div class="main-content-left" id="mainContentLeft">
                    <section class="card">
            <div class="filter-section">
                <div class="filter-group">
                    <label>Kalem Tanımı</label>
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
                    <label>Kalem Grubu</label>
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
                    <label>Şube</label>
                    <div class="multi-select-container">
                        <div class="multi-select-input" onclick="toggleDropdown('branch')">
                            <div id="branchTags"></div>
                            <input type="text" id="filterBranch" class="filter-input" placeholder="ŞUBE" onkeyup="handleFilterInput('branch', this.value)" onfocus="openDropdownIfClosed('branch')" onclick="event.stopPropagation();">
                        </div>
                        <div class="multi-select-dropdown" id="branchDropdown">
                            <div class="multi-select-option" data-value="" onclick="selectOption('branch', '', 'Tümü')">Tümü</div>
                            <div id="branchOptions"></div>
                        </div>
                    </div>
                </div>
                <div class="filter-group">
                    <label>Stok Durumu</label>
                    <div class="multi-select-container">
                        <div class="multi-select-input" onclick="toggleDropdown('stockStatus')">
                            <div id="stockStatusTags"></div>
                            <input type="text" id="filterStockStatus" class="filter-input" placeholder="STOK DURUMU" readonly>
                        </div>
                        <div class="multi-select-dropdown" id="stockStatusDropdown">
                            <div class="multi-select-option" data-value="" onclick="selectOption('stockStatus', '', 'Tümü')">Tümü</div>
                            <div class="multi-select-option" data-value="Var" onclick="selectOption('stockStatus', 'Var', 'Var')">Var</div>
                            <div class="multi-select-option" data-value="Yok" onclick="selectOption('stockStatus', 'Yok', 'Yok')">Yok</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="table-controls">
                <div class="show-entries">
                    Sayfada <select class="entries-select" id="entriesPerPage" onchange="updatePageSize()">
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="75">75</option>
                    </select> kayıt göster
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
                        <th>Şube Adı</th>
                        <th class="stock-column" style="display: none;">Stokta</th>
                        <th class="stock-column" style="display: none;">Stoktaki Miktar</th>
                        <th>Şube Miktarı</th>
                        <th>Talep Miktarı</th>
                        <th>Ölçü Birimi</th>
                        <th>Dönüşüm</th>
                    </tr>
                </thead>
                <tbody id="itemsTableBody">
                    <tr>
                        <td colspan="8" style="text-align:center;color:#888;padding:20px;">Filtre seçerek veya arama yaparak kalemleri görüntüleyin.</td>
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
                <div class="main-content-right sepet-panel" id="sepetPanel" style="display: none;">
                    <section class="card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                            <h3 style="margin: 0; color: #1e40af; font-size: 1.25rem; font-weight: 600;">🛒 Sepet</h3>
                            <button class="btn btn-secondary" onclick="toggleSepet()" style="padding: 0.5rem 1rem; font-size: 0.875rem;">✕ Kapat</button>
    </div>
                        <div id="sepetList"></div>
                        <div style="margin-top: 1.5rem; text-align: right; padding-top: 1.5rem; border-top: 1px solid #e5e7eb;">
                            <button class="btn btn-primary" onclick="saveRequest()">✓ Transfer Oluştur</button>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
let currentPage = 0;
let pageSize = 25;
let selectedItems = {};
let hasMore = false;
let selectedItemNames = [];
let selectedItemGroups = [];
let selectedBranches = [];
let selectedStockStatus = '';
let allItemNames = [];
let allItemGroups = [];
let allBranches = [];
let filteredItemNames = [];
let filteredItemGroups = [];
let filteredBranches = [];
let itemsData = [];

document.addEventListener('DOMContentLoaded', function() {
    loadItems();
});

function normalizeForSearch(text) {
    if (!text) return '';
    return text
        .replace(/İ/g, 'i').replace(/I/g, 'i').replace(/ı/g, 'i')
        .replace(/Ğ/g, 'g').replace(/ğ/g, 'g')
        .replace(/Ü/g, 'u').replace(/ü/g, 'u')
        .replace(/Ş/g, 's').replace(/ş/g, 's')
        .replace(/Ö/g, 'o').replace(/ö/g, 'o')
        .replace(/Ç/g, 'c').replace(/ç/g, 'c')
        .toLowerCase().trim();
}

function populateDropdowns() {
    const populate = (containerId, inputId, items, selected) => {
        const container = document.getElementById(containerId);
        const input = document.getElementById(inputId);
        if (!container) return;
        
        const searchText = input ? normalizeForSearch(input.value) : '';
        const filtered = searchText 
            ? items.filter(item => normalizeForSearch(item).includes(searchText))
            : items;
        
        container.innerHTML = filtered.map(item => {
            const isSelected = selected.includes(item);
            const escaped = item.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            const type = containerId.includes('itemName') ? 'itemName' : 
                        containerId.includes('itemGroup') ? 'itemGroup' : 'branch';
            return `<div class="multi-select-option ${isSelected ? 'selected' : ''}" data-value="${escaped}" onclick="selectOption('${type}', '${escaped}', '${escaped}')">${item}</div>`;
        }).join('');
    };
    
    populate('itemNameOptions', 'filterItemName', filteredItemNames, selectedItemNames);
    populate('itemGroupOptions', 'filterItemGroup', filteredItemGroups, selectedItemGroups);
    populate('branchOptions', 'filterBranch', filteredBranches, selectedBranches);
}

function openDropdownIfClosed(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    if (dropdown && !dropdown.classList.contains('show')) {
        toggleDropdown(type);
    }
}

function handleFilterInput(type, value) {
    openDropdownIfClosed(type);
    const tagsContainer = document.getElementById(type === 'itemName' ? 'itemNameTags' : 
                                                   type === 'itemGroup' ? 'itemGroupTags' : 'branchTags');
    if (tagsContainer) {
        tagsContainer.style.display = value.trim() !== '' ? 'none' : '';
    }
    populateDropdowns();
}

function toggleDropdown(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    const input = document.querySelector(`#filter${type.charAt(0).toUpperCase() + type.slice(1)}`).parentElement;
    const isOpen = dropdown.classList.contains('show');
    
    document.querySelectorAll('.multi-select-dropdown').forEach(d => d.classList.remove('show'));
    document.querySelectorAll('.multi-select-input').forEach(d => d.classList.remove('active'));
    
    if (!isOpen) {
        dropdown.classList.add('show');
        input.classList.add('active');
        if (['itemName', 'itemGroup', 'branch'].includes(type)) {
            loadFilterOptionsForType(type);
        }
    }
}

function loadFilterOptionsForType(type) {
    updateDropdownsFromTable();
    populateDropdowns();
    
    fetch('TransferlerSO.php?ajax=filter_options')
        .then(res => res.json())
        .then(data => {
            allItemNames = Array.from(new Set([...allItemNames, ...(data.itemNames || [])])).sort();
            allItemGroups = Array.from(new Set([...allItemGroups, ...(data.itemGroups || [])])).sort();
            allBranches = Array.from(new Set([...allBranches, ...(data.branches || [])])).sort();
            filteredItemNames = allItemNames;
            filteredItemGroups = allItemGroups;
            filteredBranches = allBranches;
            populateDropdowns();
        })
        .catch(err => {
            console.error('Filtre seçenekleri yüklenirken hata:', err);
            populateDropdowns();
        });
}

function updateDropdownsFromTable() {
    if (!itemsData || itemsData.length === 0) return;
    
    itemsData.forEach(item => {
        if (item.ItemCode && item.ItemName) {
            const itemDisplay = item.ItemCode + ' - ' + item.ItemName;
            if (!allItemNames.includes(itemDisplay)) allItemNames.push(itemDisplay);
        }
        if (item.ItemGroup && !allItemGroups.includes(item.ItemGroup)) {
            allItemGroups.push(item.ItemGroup);
        }
        if (item.FromWhsName && !allBranches.includes(item.FromWhsName)) {
            allBranches.push(item.FromWhsName);
        }
    });
    
    allItemNames.sort();
    allItemGroups.sort();
    allBranches.sort();
}

function selectOption(type, value, text) {
    if (type === 'stockStatus') {
        selectedStockStatus = value === '' ? '' : value.toLowerCase();
        updateFilterDisplay('stockStatus');
        currentPage = 0;
        loadItems();
        return;
    }
    
    const selectedArray = type === 'itemName' ? selectedItemNames : 
                         type === 'itemGroup' ? selectedItemGroups : selectedBranches;
    
    if (value === '') {
        selectedArray.length = 0;
    } else {
        const index = selectedArray.indexOf(value);
        if (index > -1) {
            selectedArray.splice(index, 1);
        } else {
            selectedArray.push(value);
        }
    }
    
    const input = document.getElementById(`filter${type.charAt(0).toUpperCase() + type.slice(1)}`);
    if (input) input.value = '';
    
    updateFilterDisplay(type);
    currentPage = 0;
    loadItems();
}

function updateFilterDisplay(type) {
    if (type === 'stockStatus') {
        const tagsContainer = document.getElementById('stockStatusTags');
        const input = document.getElementById('filterStockStatus');
        if (!input) return;
        
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
        const dropdown = document.getElementById('stockStatusDropdown');
        if (dropdown) {
            dropdown.querySelectorAll('.multi-select-option').forEach(opt => {
                const val = opt.getAttribute('data-value');
                opt.classList.toggle('selected', (selectedStockStatus === '' && val === '') || selectedStockStatus === val.toLowerCase());
            });
        }
        return;
    }
    
    const tagsContainer = document.getElementById(type === 'itemName' ? 'itemNameTags' : 
                                                   type === 'itemGroup' ? 'itemGroupTags' : 'branchTags');
    const input = document.getElementById(`filter${type.charAt(0).toUpperCase() + type.slice(1)}`);
    const selected = type === 'itemName' ? selectedItemNames : 
                    type === 'itemGroup' ? selectedItemGroups : selectedBranches;
    
    if (!tagsContainer || !input || input.value.trim() !== '') return;
    
    tagsContainer.style.display = '';
    tagsContainer.innerHTML = '';
    
    if (selected.length === 0) {
        input.placeholder = type === 'itemName' ? 'KALEM TANIMI' : type === 'itemGroup' ? 'KALEM GRUBU' : 'ŞUBE';
        input.value = '';
    } else {
        input.placeholder = '';
        input.value = '';
        selected.forEach(value => {
            const tag = document.createElement('span');
                tag.className = 'multi-select-tag';
            const escapedValue = value.replace(/'/g, "\\'");
            tag.innerHTML = `${value} <span class="remove" onclick="selectOption('${type}', '${escapedValue}', '${escapedValue}')">×</span>`;
                tagsContainer.appendChild(tag);
        });
    }
    
    const dropdown = document.getElementById(type + 'Dropdown');
    if (dropdown) {
        dropdown.querySelectorAll('.multi-select-option').forEach(opt => {
            opt.classList.toggle('selected', selected.includes(opt.getAttribute('data-value')));
        });
    }
}

function removeFilter(type, value) {
    const selectedArray = type === 'itemName' ? selectedItemNames : 
                         type === 'itemGroup' ? selectedItemGroups : selectedBranches;
    const index = selectedArray.indexOf(value);
    if (index > -1) selectedArray.splice(index, 1);
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
    
    let url = `TransferlerSO.php?ajax=items&skip=${skip}&top=${pageSize}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;
    if (selectedItemNames.length > 0) url += `&item_names=${encodeURIComponent(JSON.stringify(selectedItemNames))}`;
    if (selectedItemGroups.length > 0) url += `&item_groups=${encodeURIComponent(JSON.stringify(selectedItemGroups))}`;
    if (selectedBranches.length > 0) url += `&branches=${encodeURIComponent(JSON.stringify(selectedBranches))}`;
    if (selectedStockStatus) url += `&stock_status=${encodeURIComponent(selectedStockStatus)}`;
    
    fetch(url)
        .then(res => res.json())
        .then(data => {
            
            if (data.error) {
                console.error('ERROR:', data.error);
            }
            
            hasMore = data.hasMore || false;
            itemsData = data.data || [];
            
            updateDropdownsFromTable();
            renderItems(itemsData);
            updatePagination();
            
            if (itemsData.length === 0) {
                let msg = '';
                if (data.error) {
                    msg = `<tr><td colspan="8" style="text-align:center;color:#dc3545;padding:20px;">
                        <strong>⚠️ HATA: ${data.error}</strong>
                    </td></tr>`;
    } else {
                    msg = '<tr><td colspan="8" style="text-align:center;color:#888;padding:20px;">Kayıt bulunamadı.</td></tr>';
                }
                document.getElementById('itemsTableBody').innerHTML = msg;
            }
        })
        .catch(err => {
            console.error('Hata:', err);
            document.getElementById('itemsTableBody').innerHTML = 
                '<tr><td colspan="8" style="text-align:center;color:#dc3545;">Veri yüklenirken hata oluştu: ' + err.message + '</td></tr>';
        });
}

function renderItems(items) {
    const tbody = document.getElementById('itemsTableBody');
    
    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#888;">Kayıt bulunamadı.</td></tr>';
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
        const fromWhsCode = item.FromWhsCode || '';
        const itemKey = `${itemCode}_${fromWhsCode}`;
        const itemName = item.ItemName || item.ItemDescription || '';
        const itemGroup = item.ItemGroup || '-';
        const fromWhsName = item.FromWhsName || '-';
        const hasStock = item._hasStock || false;
        const stockQty = item.MainQty || item._stock || 0;
        const otherBranQty = item.OtherBranQty || 0;
        const uomCode = item.UomCode || item.UoMCode || '-';
        const baseQty = parseFloat(item.BaseQty || 1.0);
        const isInSepet = selectedItems.hasOwnProperty(itemKey);
        const sepetQty = isInSepet ? selectedItems[itemKey].quantity : 0;
        
        // Dönüşüm kolonu: BaseQty kullanarak hesaplama gösterimi
        let conversionText = '-';
        if (baseQty && baseQty !== 1 && baseQty > 0) {
            if (sepetQty > 0) {
                // Talep miktarı × BaseQty = AD karşılığı formatında göster
                const adKarşılığı = sepetQty * baseQty;
                conversionText = `${formatQuantity(sepetQty)}x${formatQuantity(baseQty)} = ${formatQuantity(adKarşılığı)} AD`;
            } else {
                // Talep miktarı yoksa sadece dönüşüm bilgisi göster
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
                <td>${fromWhsName}</td>
                <td class="stock-column" style="display: none;"><span class="stock-badge ${hasStock ? 'stock-yes' : 'stock-no'}">${hasStock ? 'Var' : 'Yok'}</span></td>
                <td class="stock-column" style="display: none;">${formatQuantity(stockQty)}</td>
                <td>${formatQuantity(otherBranQty)}</td>
                <td>
                    <div class="quantity-controls">
                        <button type="button" class="qty-btn" onclick="changeQuantity('${itemKey}', -1)">-</button>
                        <input type="number" 
                               id="qty_${itemKey}"
                               value="${sepetQty}" 
                               min="0" 
                               step="0.01"
                               class="qty-input"
                               onchange="updateQuantity('${itemKey}', this.value)"
                               oninput="updateQuantity('${itemKey}', this.value)">
                        <button type="button" class="qty-btn" onclick="changeQuantity('${itemKey}', 1)">+</button>
                    </div>
                    ${sepetQty > 0 ? `<div style="text-align: center; margin-top: 4px; font-size: 0.85rem; color: #6b7280; font-weight: 500;">${formatQuantity(sepetQty)} ${uomCode}${baseQty !== 1 && baseQty > 0 ? ` (${formatQuantity(sepetQty * baseQty)} AD)` : ''}</div>` : ''}
            </td>
                <td>${uomCode}</td>
                <td style="text-align: center; font-weight: 600; color: #3b82f6;">${conversionText}</td>
        </tr>
        `;
    }).join('');
}

function changeQuantity(itemKey, delta) {
    const input = document.getElementById('qty_' + itemKey);
    if (!input) return;
    
    let value = parseFloat(input.value) || 0;
    value += delta;
    if (value < 0) value = 0;
    input.value = value;
    updateQuantity(itemKey, value);
}

function updateQuantity(itemKey, quantity) {
    const qty = parseFloat(quantity) || 0;
    
    if (qty > 0) {
        // Sepete ekle veya güncelle
        if (!selectedItems[itemKey]) {
            // Item bilgilerini bul (tablodan veya data'dan)
            const input = document.getElementById('qty_' + itemKey);
            if (!input) return;
            const row = input.closest('tr');
            if (!row) return;
            
            const [itemCode, fromWhsCode] = itemKey.split('_');
            const itemName = row.cells[1].textContent;
            
            // BaseQty ve UomCode bilgilerini itemsData'dan bul
            let itemData = itemsData.find(i => i.ItemCode === itemCode && (i.FromWhsCode || '') === fromWhsCode);
            if (!itemData) {
                itemData = itemsData.find(i => i.ItemCode === itemCode);
            }
            
            const baseQty = itemData ? parseFloat(itemData.BaseQty || 1.0) : 1.0;
            const uomCode = itemData ? (itemData.UomCode || itemData.UoMCode || '') : '';
            const fromWhsName = itemData ? (itemData.FromWhsName || '') : (row.cells[3]?.textContent?.trim() || '');
            
            selectedItems[itemKey] = {
                itemCode: itemCode,
                itemName: itemName,
                quantity: qty,
                baseQty: baseQty,
                uomCode: uomCode,
                fromWhsName: fromWhsName,
                fromWhsCode: fromWhsCode
            };
    } else {
            selectedItems[itemKey].quantity = qty;
        }
    } else {
        // Sepetten çıkar
        if (selectedItems[itemKey]) {
            delete selectedItems[itemKey];
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
    
    list.innerHTML = Object.entries(selectedItems).map(([itemKey, item]) => {
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
                <div class="sepet-item-name">${item.itemCode} - ${item.itemName}${item.fromWhsName ? ' (' + item.fromWhsName + ')' : ''}</div>
                <div style="margin-bottom: 0.5rem; font-size: 0.9rem; color: #3b82f6; font-weight: 600;">${qtyDisplay}</div>
                ${conversionInfo}
                <div class="sepet-item-qty">
                    <button type="button" class="qty-btn" onclick="changeQuantity('${itemKey}', -1)">-</button>
                    <input type="number" 
                           value="${item.quantity}" 
                           min="0" 
                           step="0.01"
                           onchange="updateQuantity('${itemKey}', this.value)"
                           oninput="updateQuantity('${itemKey}', this.value)">
                    <button type="button" class="qty-btn" onclick="changeQuantity('${itemKey}', 1)">+</button>
                </div>
            </div>
            <button type="button" class="remove-sepet-btn" onclick="removeFromSepet('${itemKey}')">Kaldır</button>
        </div>
    `;
    }).join('');
}

function removeFromSepet(itemKey) {
    if (selectedItems[itemKey]) {
        delete selectedItems[itemKey];
        const input = document.getElementById('qty_' + itemKey);
        if (input) input.value = 0;
        updateSepet();
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
    
    if (!confirm('Transfer talebini oluşturmak istediğinize emin misiniz?')) {
        return;
    }
    
    fetch('TransferlerSO.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Transfer talebi başarıyla oluşturuldu!');
            window.location.href = 'Transferler.php';
        } else {
            alert('Hata: ' + (data.message || 'Bilinmeyen hata'));
        }
    })
    .catch(err => {
        console.error('Hata:', err);
        alert('Transfer talebi oluşturulurken hata oluştu!');
    });
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.multi-select-container')) {
        document.querySelectorAll('.multi-select-dropdown').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.multi-select-input').forEach(d => d.classList.remove('active'));
    }
});
</script>
</body>
</html>