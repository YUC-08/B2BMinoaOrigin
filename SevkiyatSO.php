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

// Çıkış Depo listesi (U_ASB2B_BRAN eq '100' or U_ASB2B_FATH eq 'Y')
$fromWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and (U_ASB2B_BRAN eq '{$branch}' or U_ASB2B_FATH eq 'Y')";
$fromWarehouseQuery = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=" . urlencode($fromWarehouseFilter) . "&\$orderby=WarehouseCode";
$fromWarehouseData = $sap->get($fromWarehouseQuery);
$fromWarehouses = [];
if (($fromWarehouseData['status'] ?? 0) == 200) {
    if (isset($fromWarehouseData['response']['value'])) {
        $fromWarehouses = $fromWarehouseData['response']['value'];
    } elseif (isset($fromWarehouseData['value'])) {
        $fromWarehouses = $fromWarehouseData['value'];
    }
}

// Gideceği Depo listesi (U_ASB2B_MAIN eq '1')
$toWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_MAIN eq '2'";
$toWarehouseQuery = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=" . urlencode($toWarehouseFilter) . "&\$orderby=WarehouseCode";
$toWarehouseData = $sap->get($toWarehouseQuery);
$toWarehouses = [];
if (($toWarehouseData['status'] ?? 0) == 200) {
    if (isset($toWarehouseData['response']['value'])) {
        $toWarehouses = $toWarehouseData['response']['value'];
    } elseif (isset($toWarehouseData['value'])) {
        $toWarehouses = $toWarehouseData['value'];
    }
}

// AJAX: Ürün listesi getir
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'items') {
    header('Content-Type: application/json');
    
    $warehouseCode = trim($_GET['warehouseCode'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $top = intval($_GET['top'] ?? 100);
    $skip = intval($_GET['skip'] ?? 0);
    
    if (empty($warehouseCode)) {
        echo json_encode(['data' => [], 'count' => 0, 'error' => 'Depo seçilmedi']);
        exit;
    }
    
    // ASB2B_InventoryWhsItem_B1SLQuery view'den ürünleri çek
    $warehouseCodeEscaped = str_replace("'", "''", $warehouseCode);
    $filter = "WhsCode eq '{$warehouseCodeEscaped}'";
    
    if (!empty($search)) {
        $searchEscaped = str_replace("'", "''", $search);
        $filter .= " and (contains(ItemCode, '{$searchEscaped}') or contains(ItemName, '{$searchEscaped}'))";
    }
    
    $itemsQuery = "view.svc/ASB2B_InventoryWhsItem_B1SLQuery?\$filter=" . urlencode($filter) . "&\$orderby=ItemCode&\$top={$top}&\$skip={$skip}";
    $itemsData = $sap->get($itemsQuery);
    
    $items = [];
    $errorMsg = null;
    
    if (($itemsData['status'] ?? 0) == 200) {
        if (isset($itemsData['response']['value'])) {
            $items = $itemsData['response']['value'];
        } elseif (isset($itemsData['value'])) {
            $items = $itemsData['value'];
        }
    } else {
        $errorMsg = $itemsData['response']['error']['message']['value'] ?? $itemsData['response']['error']['message'] ?? 'Bilinmeyen hata';
    }
    
    if ($errorMsg) {
        echo json_encode([
            'data' => [],
            'count' => 0,
            'error' => $errorMsg
        ]);
        exit;
    }
    
    // Her item için UoM bilgilerini çek
    foreach ($items as &$item) {
        $itemCode = $item['ItemCode'] ?? '';
        if (!empty($itemCode)) {
            $itemDetailQuery = "Items('{$itemCode}')?\$select=ItemCode,InventoryUOM,UoMGroupEntry";
            $itemDetailData = $sap->get($itemDetailQuery);
            if (($itemDetailData['status'] ?? 0) == 200) {
                $itemDetail = $itemDetailData['response'] ?? $itemDetailData;
                $item['InventoryUOM'] = $itemDetail['InventoryUOM'] ?? '';
                $item['UoMGroupEntry'] = $itemDetail['UoMGroupEntry'] ?? '';
                
                // UoM listesini çek
                if (!empty($itemDetail['UoMGroupEntry']) && $itemDetail['UoMGroupEntry'] != -1) {
                    $uomGroupCollectionQuery = "UoMGroups({$itemDetail['UoMGroupEntry']})/UoMGroupDefinitionCollection";
                    $uomGroupData = $sap->get($uomGroupCollectionQuery);
                    $uomList = [];
                    
                    if (($uomGroupData['status'] ?? 0) == 200) {
                        $collection = [];
                        if (isset($uomGroupData['response']['value']) && is_array($uomGroupData['response']['value'])) {
                            $collection = $uomGroupData['response']['value'];
                        } elseif (isset($uomGroupData['value']) && is_array($uomGroupData['value'])) {
                            $collection = $uomGroupData['value'];
                        } elseif (isset($uomGroupData['response']) && is_array($uomGroupData['response'])) {
                            $collection = $uomGroupData['response'];
                        }
                        
                        if (!empty($collection)) {
                            foreach ($collection as $uomDef) {
                                $uomEntry = $uomDef['UoMEntry'] ?? $uomDef['AlternateUoM'] ?? null;
                                $uomCode = $uomDef['UoMCode'] ?? '';
                                $baseQty = $uomDef['BaseQty'] ?? $uomDef['BaseQuantity'] ?? 1;
                                
                                if (!empty($uomEntry)) {
                                    $uomList[] = [
                                        'UoMEntry' => $uomEntry,
                                        'UoMCode' => $uomCode,
                                        'BaseQty' => $baseQty
                                    ];
                                }
                            }
                        }
                    }
                    
                    $item['UoMList'] = $uomList;
                }
            }
        }
    }
    
    echo json_encode([
        'data' => $items, 
        'count' => count($items)
    ]);
    exit;
}

// POST: Tek taraflı sevkiyat oluştur (StockTransfers - direkt sevk edildi)
// Bu modül: Gönderen şube tek başına sevkiyat yaratır, alan şube önceden onay vermez
// Statü direkt "Sevk edildi" (3) olarak başlar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    header('Content-Type: application/json');
    
    $fromWarehouse = trim($_POST['fromWarehouse'] ?? '');
    $toWarehouse = trim($_POST['toWarehouse'] ?? '');
    $docDate = trim($_POST['docDate'] ?? date('Y-m-d'));
    $comments = trim($_POST['comments'] ?? '');
    $items = json_decode($_POST['items'] ?? '[]', true);
    
    if (empty($fromWarehouse) || empty($toWarehouse)) {
        echo json_encode(['success' => false, 'message' => 'Depo bilgileri eksik!']);
        exit;
    }
    
    if (empty($items) || !is_array($items)) {
        echo json_encode(['success' => false, 'message' => 'Lütfen en az bir kalem seçin!']);
        exit;
    }
    
    // StockTransferLines oluştur
    $stockTransferLines = [];
    foreach ($items as $item) {
        $itemCode = $item['ItemCode'] ?? '';
        $quantity = floatval($item['Quantity'] ?? 0);
        $uomEntry = $item['UoMEntry'] ?? null;
        $uomCode = $item['UoMCode'] ?? '';
        
        if (empty($itemCode) || $quantity <= 0) {
            continue;
        }
        
        // BaseQty mantığı: Kullanıcının girdiği miktar × BaseQty = SAP'ye giden miktar
        $baseQty = floatval($item['BaseQty'] ?? 1.0);
        $sapQuantity = $quantity * $baseQty;
        
        $line = [
            'ItemCode' => $itemCode,
            'Quantity' => $sapQuantity,
            'FromWarehouseCode' => $fromWarehouse,
            'WarehouseCode' => $toWarehouse
        ];
        
        // UoM bilgisi varsa ekle
        if (!empty($uomEntry) && $uomEntry != -1) {
            $line['UoMEntry'] = intval($uomEntry);
        }
        if (!empty($uomCode)) {
            $line['UoMCode'] = $uomCode;
        }
        
        $stockTransferLines[] = $line;
    }
    
    if (empty($stockTransferLines)) {
        echo json_encode(['success' => false, 'message' => 'Geçerli kalem bulunamadı!']);
        exit;
    }
    
    // Önce InventoryTransferRequests oluştur (kayıt için)
    // Bu kayıt hem gönderen hem alan şube tarafından görülebilir
    $requestPayload = [
        'DocDate' => $docDate,
        'FromWarehouse' => $fromWarehouse,
        'ToWarehouse' => $toWarehouse,
        'Comments' => !empty($comments) ? $comments : 'Tek taraflı sevkiyat',
        'U_ASB2B_BRAN' => $branch,
        'U_AS_OWNR' => $uAsOwnr,
        'U_ASB2B_STATUS' => '2', // Direkt "Sevk edildi" - onay beklenmez
        'U_ASB2B_TYPE' => 'SEVKIYAT',
        'U_ASB2B_User' => $_SESSION["UserName"] ?? '',
        'StockTransferLines' => $stockTransferLines
    ];
    
    $requestResult = $sap->post('InventoryTransferRequests', $requestPayload);
    
    if (($requestResult['status'] ?? 0) != 200 && ($requestResult['status'] ?? 0) != 201) {
        $errorMsg = 'Sevkiyat kaydı oluşturulamadı: HTTP ' . ($requestResult['status'] ?? 'NO STATUS');
        if (isset($requestResult['response']['error'])) {
            $error = $requestResult['response']['error'];
            $errorMsg .= ' - ' . ($error['message']['value'] ?? $error['message'] ?? json_encode($error));
        }
        echo json_encode([
            'success' => false,
            'message' => $errorMsg,
            'debug' => $requestResult
        ]);
        exit;
    }
    
    $requestDocEntry = $requestResult['response']['DocEntry'] ?? null;
    $requestDocNum = $requestResult['response']['DocNum'] ?? $requestDocEntry;
    
    // Şimdi StockTransfers oluştur (fiziksel sevkiyat)
    $stockTransferPayload = [
        'FromWarehouse' => $fromWarehouse,
        'ToWarehouse' => $toWarehouse,
        'DocDate' => $docDate,
        'Comments' => !empty($comments) ? $comments : 'Tek taraflı sevkiyat',
        'U_ASB2B_BRAN' => $branch,
        'U_AS_OWNR' => $uAsOwnr,
        'U_ASB2B_STATUS' => '2', // Sevk edildi
        'U_ASB2B_TYPE' => 'SEVKIYAT',
        'U_ASB2B_User' => $_SESSION["UserName"] ?? '',
        'U_ASB2B_QutMaster' => (int)$requestDocEntry, // InventoryTransferRequest ile ilişki
        'DocumentReferences' => [
            [
                'RefDocEntr' => (int)$requestDocEntry,
                'RefDocNum' => (int)$requestDocNum,
                'RefObjType' => 'rot_InventoryTransferRequest'
            ]
        ],
        'StockTransferLines' => $stockTransferLines
    ];
    
    // Debug: Payload'ı logla
    error_log('[SEVKIYAT] StockTransfer Payload: ' . json_encode($stockTransferPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // StockTransfers oluştur
    $result = $sap->post('StockTransfers', $stockTransferPayload);
    
    // Debug: Response'u logla
    error_log('[SEVKIYAT] StockTransfer Response Status: ' . ($result['status'] ?? 'NO STATUS'));
    error_log('[SEVKIYAT] StockTransfer Response: ' . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    if (($result['status'] ?? 0) == 200 || ($result['status'] ?? 0) == 201) {
        $stockTransferDocEntry = $result['response']['DocEntry'] ?? null;
        
        echo json_encode([
            'success' => true,
            'message' => 'Sevkiyat başarıyla oluşturuldu ve sevk edildi!',
            'requestDocEntry' => $requestDocEntry,
            'stockTransferDocEntry' => $stockTransferDocEntry
        ]);
    } else {
        // StockTransfer oluşturulamadı ama request oluştu, request'i silmek yerine hata mesajı ver
        $errorMsg = 'StockTransfer oluşturulamadı: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $error = $result['response']['error'];
            $errorMsg .= ' - ' . ($error['message']['value'] ?? $error['message'] ?? json_encode($error));
        }
        echo json_encode([
            'success' => false,
            'message' => $errorMsg . ' (Sevkiyat kaydı oluşturuldu: ' . $requestDocEntry . ')',
            'debug' => [
                'status' => $result['status'] ?? 'NO STATUS',
                'response' => $result['response'] ?? null,
                'payload' => $stockTransferPayload
            ]
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Sevkiyat Oluştur - MINOA</title>
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
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            margin-bottom: 24px;
            overflow: visible;
        }

        .card-header {
            padding: 20px 24px 0 24px;
        }

        .card-header h3 {
            color: #1e40af;
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0;
        }

        .card-body {
            padding: 16px 24px 24px 24px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-size: 13px;
            color: #1e3a8a;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-label.required::after {
            content: ' *';
            color: #dc2626;
        }

        .form-input {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            background: white;
            color: #111827;
            width: 100%;
            min-height: 44px;
            box-sizing: border-box;
        }

        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-input:disabled {
            background: #f3f4f6;
            cursor: not-allowed;
            color: #6b7280;
        }

        /* Modern Single Select Dropdown */
        .single-select-container {
            position: relative;
            width: 100%;
        }

        .single-select-input {
            display: flex;
            align-items: center;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            min-height: 44px;
            transition: all 0.2s ease;
        }

        .single-select-input:hover {
            border-color: #3b82f6;
        }

        .single-select-input.active {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .single-select-input.disabled {
            background: #f3f4f6;
            cursor: not-allowed;
            color: #6b7280;
        }

        .single-select-input input {
            border: none;
            outline: none;
            flex: 1;
            background: transparent;
            cursor: pointer;
            font-size: 14px;
            color: #111827;
            pointer-events: none;
        }

        .single-select-input.disabled input {
            color: #6b7280;
        }

        .dropdown-arrow {
            transition: transform 0.2s;
            color: #6b7280;
            font-size: 12px;
            margin-left: 8px;
        }

        .single-select-input.active .dropdown-arrow {
            transform: rotate(180deg);
        }

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
            padding: 12px 16px;
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

        .btn {
            padding: 12px 24px;
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

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            transform: translateY(-1px);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: white;
            color: #3b82f6;
            border: 2px solid #3b82f6;
        }

        .btn-secondary:hover {
            background: #f0f9ff;
        }

        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .search-box {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .search-input {
            padding: 10px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            min-width: 260px;
            transition: all 0.25s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .data-table thead {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }

        .data-table th {
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
        }

        .data-table tbody tr {
            transition: background-color 0.2s ease;
        }

        .data-table tbody tr:hover {
            background-color: #f9fafb;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .qty-btn {
            width: 32px;
            height: 32px;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            color: #1e40af;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qty-btn:hover {
            background: #1e40af;
            color: white;
            border-color: #1e40af;
            transform: scale(1.05);
        }

        .qty-input {
            width: 70px;
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            transition: all 0.2s ease;
        }

        .qty-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: #fafbfc;
        }

        .qty-input:disabled {
            background: #f3f4f6;
            cursor: not-allowed;
            color: #9ca3af;
        }

        .uom-select {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            min-width: 100px;
        }

        .uom-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
        }

        .empty-message {
            text-align: center;
            padding: 3rem;
            color: #9ca3af;
            font-size: 14px;
        }

        .cart-section {
            display: none;
        }

        .cart-section.show {
            display: block;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    <main class="main-content">
        <header class="page-header">
            <h2>Yeni Sevkiyat Oluştur</h2>
            <button class="btn btn-secondary" onclick="window.location.href='Sevkiyat.php'">← Geri Dön</button>
        </header>

        <div class="content-wrapper">
            <section class="card">
                <div class="card-header">
                    <h3>Üst Bilgiler</h3>
                </div>
                <div class="card-body">
                    <form id="sevkiyatForm">
                        <div class="form-grid">
                            <!-- Çıkış Depo -->
                            <div class="form-group">
                                <label class="form-label required" for="fromWarehouse">Çıkış Depo</label>
                                <div class="single-select-container">
                                    <div class="single-select-input" id="fromWarehouseInput" onclick="toggleDropdown('fromWarehouse')">
                                        <input type="text" id="fromWarehouseInputText" value="Depo seçiniz" readonly>
                                        <span class="dropdown-arrow">▼</span>
                                    </div>
                                    <div class="single-select-dropdown" id="fromWarehouseDropdown">
                                        <div class="single-select-option" data-value="" onclick="selectWarehouse('fromWarehouse', '', 'Depo seçiniz')">Depo seçiniz</div>
                                        <?php foreach ($fromWarehouses as $whs): ?>
                                        <div class="single-select-option" data-value="<?= htmlspecialchars($whs['WarehouseCode']) ?>" onclick="selectWarehouse('fromWarehouse', '<?= htmlspecialchars($whs['WarehouseCode']) ?>', '<?= htmlspecialchars($whs['WarehouseCode']) ?> - <?= htmlspecialchars($whs['WarehouseName'] ?? '') ?>')">
                                            <?= htmlspecialchars($whs['WarehouseCode']) ?> - <?= htmlspecialchars($whs['WarehouseName'] ?? '') ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <input type="hidden" id="fromWarehouse" name="fromWarehouse" required>
                            </div>

                            <!-- Gideceği Depo -->
                            <div class="form-group">
                                <label class="form-label required" for="toWarehouse">Gideceği Depo</label>
                                <div class="single-select-container">
                                    <div class="single-select-input" id="toWarehouseInput" onclick="toggleDropdown('toWarehouse')">
                                        <input type="text" id="toWarehouseInputText" value="Depo seçiniz" readonly>
                                        <span class="dropdown-arrow">▼</span>
                                    </div>
                                    <div class="single-select-dropdown" id="toWarehouseDropdown">
                                        <div class="single-select-option" data-value="" onclick="selectWarehouse('toWarehouse', '', 'Depo seçiniz')">Depo seçiniz</div>
                                        <?php foreach ($toWarehouses as $whs): ?>
                                        <div class="single-select-option" data-value="<?= htmlspecialchars($whs['WarehouseCode']) ?>" onclick="selectWarehouse('toWarehouse', '<?= htmlspecialchars($whs['WarehouseCode']) ?>', '<?= htmlspecialchars($whs['WarehouseCode']) ?> - <?= htmlspecialchars($whs['WarehouseName'] ?? '') ?>')">
                                            <?= htmlspecialchars($whs['WarehouseCode']) ?> - <?= htmlspecialchars($whs['WarehouseName'] ?? '') ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <input type="hidden" id="toWarehouse" name="toWarehouse" required>
                            </div>

                            <!-- Tarih -->
                            <div class="form-group">
                                <label class="form-label" for="docDate">Tarih</label>
                                <input type="date" class="form-input" id="docDate" name="docDate" value="<?= date('Y-m-d') ?>">
                            </div>

                            <!-- Açıklama -->
                            <div class="form-group full-width">
                                <label class="form-label" for="comments">Açıklama</label>
                                <textarea class="form-input" id="comments" name="comments" rows="3" placeholder="Opsiyonel açıklama..."></textarea>
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Ürün Listesi -->
            <section class="card" id="productListSection" style="display: none;">
                <div class="card-header">
                    <h3>Ürün Listesi</h3>
                </div>
                <div class="card-body">
                    <div class="table-controls">
                        <div class="search-box">
                            <input type="text" id="productSearch" class="search-input" placeholder="Ürün kodu veya adı ile ara..." oninput="searchProducts()">
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>Depo</th>
                                    <th>Birim</th>
                                    <th>Miktar</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="productTableBody">
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;">
                                        Önce çıkış deposunu seçiniz
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Sepet -->
            <section class="card cart-section" id="cartSection" style="display: none;">
                <div class="card-header">
                    <h3>Sepet</h3>
                </div>
                <div class="card-body">
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>Çıkış Depo</th>
                                    <th>Hedef Depo</th>
                                    <th>Birim</th>
                                    <th class="text-right">Miktar</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="cartTableBody">
                                <tr>
                                    <td colspan="7" class="empty-message">Sepet boş</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- Butonlar Sepet Altında -->
                    <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; padding-top: 24px; border-top: 2px solid #f3f4f6;">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='Sevkiyat.php'">İptal</button>
                        <button type="button" class="btn btn-primary" id="saveBtn" disabled onclick="saveSevkiyat()">Kaydet</button>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script>
        // Single Select Functions
        function toggleDropdown(id) {
            const dropdown = document.getElementById(id + 'Dropdown');
            const input = document.getElementById(id + 'Input');
            const isActive = dropdown.classList.contains('show');
            
            // Tüm dropdown'ları kapat
            document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
            document.querySelectorAll('.single-select-input').forEach(i => i.classList.remove('active'));
            
            // Eğer disabled değilse aç/kapat
            if (!input.classList.contains('disabled')) {
                if (!isActive) {
                    dropdown.classList.add('show');
                    input.classList.add('active');
                }
            }
        }

        function selectWarehouse(id, value, text) {
            const input = document.getElementById(id + 'Input');
            const inputText = document.getElementById(id + 'InputText');
            const hiddenInput = document.getElementById(id);
            const dropdown = document.getElementById(id + 'Dropdown');
            
            inputText.value = text;
            hiddenInput.value = value;
            dropdown.classList.remove('show');
            input.classList.remove('active');
            
            // Seçili option'ı işaretle
            dropdown.querySelectorAll('.single-select-option').forEach(opt => {
                opt.classList.remove('selected');
                if (opt.dataset.value === value) {
                    opt.classList.add('selected');
                }
            });
            
            // Event trigger
            if (id === 'fromWarehouse') {
                handleFromWarehouseChange(value);
            } else if (id === 'toWarehouse') {
                updateSaveButton();
            }
        }

        // Dışarı tıklanınca dropdown'ları kapat
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.single-select-container')) {
                document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
                document.querySelectorAll('.single-select-input').forEach(i => i.classList.remove('active'));
            }
        });

        const fromWarehouseInput = document.getElementById('fromWarehouse');
        const toWarehouseInput = document.getElementById('toWarehouse');
        const saveBtn = document.getElementById('saveBtn');
        const form = document.getElementById('sevkiyatForm');

        let productList = [];
        let searchTimeout = null;
        let cart = [];

        // Çıkış depo seçildiğinde ürün listesini göster
        function handleFromWarehouseChange(value) {
            if (value) {
                document.getElementById('productListSection').style.display = 'block';
                loadProducts();
            } else {
                document.getElementById('productListSection').style.display = 'none';
            }
            updateSaveButton();
        }

        // Ürün listesini yükle
        function loadProducts(search = '') {
            const warehouseCode = fromWarehouseInput.value;
            if (!warehouseCode) return;

            const tbody = document.getElementById('productTableBody');
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 40px;">Yükleniyor...</td></tr>';

            const params = new URLSearchParams({
                ajax: 'items',
                warehouseCode: warehouseCode,
                top: 100,
                skip: 0
            });

            if (search) {
                params.append('search', search);
            }

            fetch(`?${params.toString()}`)
                .then(res => res.json())
                .then(data => {
                    productList = data.data || [];
                    renderProductTable();
                })
                .catch(err => {
                    console.error('Hata:', err);
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 40px; color: #dc2626;">Yükleme hatası</td></tr>';
                });
        }

        // Ürün arama
        function searchProducts() {
            const search = document.getElementById('productSearch').value;
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                loadProducts(search);
            }, 300);
        }

        // Ürün tablosunu render et
        function renderProductTable() {
            const tbody = document.getElementById('productTableBody');

            if (productList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 40px; color: #6b7280;">Ürün bulunamadı</td></tr>';
                return;
            }

            tbody.innerHTML = productList.map((item, index) => {
                const itemCode = item.ItemCode || '';
                const itemName = item.ItemName || '';
                const whsCode = item.WhsCode || item.WarehouseCode || '';
                const uomList = item.UoMList || [];
                const inventoryUOM = item.InventoryUOM || '';
                const uomGroupEntry = item.UoMGroupEntry || -1;

                // Birim seçimi
                let uomSelectHTML = '';
                if (uomGroupEntry == -1 || uomList.length === 0) {
                    // Manuel birim veya liste yok
                    uomSelectHTML = `<span>${inventoryUOM || 'AD'}</span>`;
                } else {
                    // Birim dropdown
                    uomSelectHTML = `<select class="uom-select" id="uom_${itemCode}" onchange="updateUoM('${itemCode}', this.value)">
                        ${uomList.map(uom => `<option value="${uom.UoMEntry}" data-code="${uom.UoMCode}" data-baseqty="${uom.BaseQty}">${uom.UoMCode}</option>`).join('')}
                    </select>`;
                }

                return `
                    <tr>
                        <td><strong>${itemCode}</strong></td>
                        <td>${itemName}</td>
                        <td>${whsCode}</td>
                        <td>${uomSelectHTML}</td>
                        <td>
                            <div class="quantity-controls">
                                <button type="button" class="qty-btn" onclick="changeQuantity('${itemCode}', -1)">−</button>
                                <input type="number" 
                                       id="qty_${itemCode}"
                                       value="0" 
                                       min="0" 
                                       step="0.01"
                                       class="qty-input"
                                       onchange="updateQuantity('${itemCode}', this.value)"
                                       oninput="updateQuantity('${itemCode}', this.value)">
                                <button type="button" class="qty-btn" onclick="changeQuantity('${itemCode}', 1)">+</button>
                            </div>
                        </td>
                        <td>
                            <button class="btn btn-primary btn-small" onclick="addToCart('${itemCode}')">Ekle</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // Miktar değiştir
        function changeQuantity(itemCode, delta) {
            const input = document.getElementById('qty_' + itemCode);
            if (!input) return;
            
            let value = parseFloat(input.value) || 0;
            value += delta;
            if (value < 0) value = 0;
            input.value = value;
            updateQuantity(itemCode, value);
        }

        // Miktar güncelle
        function updateQuantity(itemCode, quantity) {
            // Sadece validasyon için kullanılabilir
        }

        // Birim güncelle
        function updateUoM(itemCode, uomEntry) {
            // Birim değiştiğinde yapılacak işlemler
        }

        // Sepete ekle
        function addToCart(itemCode) {
            const item = productList.find(p => p.ItemCode === itemCode);
            if (!item) return;

            const qtyInput = document.getElementById('qty_' + itemCode);
            const quantity = parseFloat(qtyInput.value) || 0;
            
            if (quantity <= 0) {
                alert('Lütfen miktar giriniz');
                return;
            }

            // Birim bilgisi
            let uomEntry = null;
            let uomCode = item.InventoryUOM || 'AD';
            let baseQty = 1;

            const uomSelect = document.getElementById('uom_' + itemCode);
            if (uomSelect) {
                const selectedOption = uomSelect.options[uomSelect.selectedIndex];
                uomEntry = selectedOption.value;
                uomCode = selectedOption.dataset.code || uomCode;
                baseQty = parseFloat(selectedOption.dataset.baseqty) || 1;
            }

            const cartItem = {
                ItemCode: itemCode,
                ItemName: item.ItemName || '',
                FromWarehouse: fromWarehouseInput.value,
                ToWarehouse: toWarehouseInput.value,
                Quantity: quantity,
                UoMEntry: uomEntry,
                UoMCode: uomCode,
                BaseQty: baseQty
            };

            // Sepette var mı kontrol et
            const existingIndex = cart.findIndex(c => c.ItemCode === itemCode && c.FromWarehouse === fromWarehouseInput.value);
            
            if (existingIndex >= 0) {
                // Mevcut satırı güncelle
                cart[existingIndex] = cartItem;
            } else {
                // Yeni satır ekle
                cart.push(cartItem);
            }

            // Miktarı sıfırla
            qtyInput.value = 0;

            updateCartTable();
            updateSaveButton();
        }

        // Sepeti render et
        function updateCartTable() {
            const tbody = document.getElementById('cartTableBody');
            
            if (cart.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="empty-message">Sepet boş - Ürün seçiniz</td></tr>';
                document.getElementById('cartSection').style.display = 'none';
                return;
            }

            document.getElementById('cartSection').style.display = 'block';
            tbody.innerHTML = '';

            cart.forEach((item, index) => {
                const row = document.createElement('tr');
                row.setAttribute('data-item-code', item.ItemCode);

                row.innerHTML = `
                    <td><strong>${item.ItemCode}</strong></td>
                    <td>${item.ItemName}</td>
                    <td>${item.FromWarehouse}</td>
                    <td>${item.ToWarehouse}</td>
                    <td>${item.UoMCode || 'AD'}</td>
                    <td class="text-right">
                        <div class="quantity-controls">
                            <button type="button" class="qty-btn" onclick="changeCartQuantity(${index}, -1)">−</button>
                            <input type="number" 
                                   class="qty-input" 
                                   value="${item.Quantity.toFixed(2)}"
                                   min="0" 
                                   step="0.01"
                                   onchange="updateCartQuantity(${index}, this.value)">
                            <button type="button" class="qty-btn" onclick="changeCartQuantity(${index}, 1)">+</button>
                        </div>
                    </td>
                    <td style="text-align: center;">
                        <button class="btn btn-danger btn-small" onclick="removeFromCart(${index})">Sil</button>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        // Sepette miktar değiştir
        function changeCartQuantity(index, delta) {
            if (index >= 0 && index < cart.length) {
                let quantity = parseFloat(cart[index].Quantity || 0);
                quantity += delta;
                if (quantity < 0) quantity = 0;
                cart[index].Quantity = quantity;
                updateCartTable();
                updateSaveButton();
            }
        }

        // Sepette miktar güncelle
        function updateCartQuantity(index, value) {
            if (index >= 0 && index < cart.length) {
                cart[index].Quantity = parseFloat(value) || 0;
                updateCartTable();
                updateSaveButton();
            }
        }

        // Sepetten ürün sil
        function removeFromCart(index) {
            if (index >= 0 && index < cart.length) {
                cart.splice(index, 1);
                updateCartTable();
                updateSaveButton();
            }
        }

        // Form validasyonu - Kaydet butonunu aktif/pasif yap
        function updateSaveButton() {
            const fromWarehouse = fromWarehouseInput.value;
            const toWarehouse = toWarehouseInput.value;
            
            if (fromWarehouse && toWarehouse && cart.length > 0) {
                saveBtn.disabled = false;
            } else {
                saveBtn.disabled = true;
            }
        }

        // Sevkiyat belgesi kaydet
        function saveSevkiyat() {
            if (cart.length === 0) {
                alert('Lütfen sepete en az bir ürün ekleyiniz');
                return;
            }

            const fromWarehouse = fromWarehouseInput.value;
            const toWarehouse = toWarehouseInput.value;
            const docDate = document.getElementById('docDate').value;
            const comments = document.getElementById('comments').value;

            if (!fromWarehouse || !toWarehouse) {
                alert('Lütfen depo bilgilerini seçiniz');
                return;
            }

            // Debug: Cart ve form verilerini logla
            console.log('Cart:', cart);
            console.log('FromWarehouse:', fromWarehouse);
            console.log('ToWarehouse:', toWarehouse);
            console.log('DocDate:', docDate);

            if (!confirm('Sevkiyat belgesi oluşturulsun mu?')) {
                return;
            }

            // Loading göster
            saveBtn.disabled = true;
            const originalText = saveBtn.innerText;
            saveBtn.innerText = 'Kaydediliyor...';

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('fromWarehouse', fromWarehouse);
            formData.append('toWarehouse', toWarehouse);
            formData.append('docDate', docDate);
            formData.append('comments', comments);
            formData.append('items', JSON.stringify(cart));

            console.log('Sending request...');
            console.log('Items JSON:', JSON.stringify(cart));

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => {
                console.log('Response status:', res.status);
                return res.text().then(text => {
                    console.log('Response text:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        return { success: false, message: 'Sunucu yanıtı parse edilemedi: ' + text.substring(0, 200) };
                    }
                });
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    alert(data.message);
                    window.location.href = 'Sevkiyat.php';
                } else {
                    const errorMsg = data.message || 'Bir hata oluştu';
                    if (data.debug) {
                        console.error('Debug info:', data.debug);
                        alert(errorMsg + '\n\nDetaylar için konsolu kontrol edin.');
                    } else {
                        alert(errorMsg);
                    }
                    saveBtn.disabled = false;
                    saveBtn.innerText = originalText;
                }
            })
            .catch(err => {
                console.error('Fetch error:', err);
                alert('Bir bağlantı hatası oluştu: ' + err.message);
                saveBtn.disabled = false;
                saveBtn.innerText = originalText;
            });
        }

        // Sayfa yüklendiğinde scroll pozisyonunu sıfırla
        document.addEventListener('DOMContentLoaded', function() {
            window.scrollTo(0, 0);
        });
    </script>
</body>
</html>

