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
                } else {
                    // If UoMGroupEntry is -1 or empty, it means the base unit is the only one.
                    // We still need to represent the base unit if it has an entry/code.
                    if (!empty($itemDetail['InventoryUOM'])) {
                         $uomList[] = [
                            'UoMEntry' => -1, // Special value for base unit
                            'UoMCode' => $itemDetail['InventoryUOM'],
                            'BaseQty' => 1.0
                        ];
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
        if ($uomEntry !== null && $uomEntry !== '-1' && $uomEntry !== '') { // Check for null, '-1' and empty string
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
        'U_ASB2B_STATUS' => '3', // Direkt "Sevk edildi" - onay beklenmez
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
        'U_ASB2B_STATUS' => '3', // Sevk edildi
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
        
        /* Added header actions container for cart button */
        .page-header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        /* Removed complex animation styles, using simple slideIn like AnadepoSo */
        /* Added sepet button styles */
        .sepet-btn {
            position: relative;
        }

        .sepet-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #dc2626;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            border: 2px solid white;
        }

        /* Simplified layout container matching AnadepoSo */
        .main-layout-container {
            display: flex;
            gap: 24px;
        }

        .main-content-left {
            flex: 1;
            min-width: 0;
        }

        .main-layout-container.sepet-open .main-content-left {
            flex: 0 0 calc(100% - 444px);
        }

        .main-content-right.sepet-panel {
            flex: 0 0 420px;
            min-width: 400px;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            max-height: calc(100vh - 120px);
        }

        .main-content-right.sepet-panel .card {
            margin: 0;
        }

        /* Simple slideIn animation like AnadepoSo */
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

        /* Added sepet item styles */
        .sepet-item {
            padding: 16px;
            background: #f9fafb;
            border-radius: 8px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            border: 1px solid #e5e7eb;
        }

        .sepet-item-info {
            flex: 1;
        }

        .sepet-item-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .sepet-item-qty {
            display: flex;
            gap: 6px;
            align-items: center;
            margin-top: 8px;
        }

        .sepet-item-qty input {
            width: 80px;
            padding: 6px 10px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            text-align: center;
            font-size: 0.9rem;
        }

        .remove-sepet-btn {
            padding: 6px 12px;
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .remove-sepet-btn:hover {
            background: #fecaca;
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
            margin-bottom: 2rem;
        }

        .card:last-child {
            margin-bottom: 0;
        }

        .card-header {
            padding: 0;
            margin-bottom: 1rem;
        }

        .card-header h3 {
            color: #1e40af;
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0;
        }

        .card-body {
            padding: 0;
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
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
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

        .data-table-wrapper {
            overflow-x: auto;
            border-radius: 8px;
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
            padding: 14px 12px;
            text-align: center;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
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
            padding: 14px 12px;
            color: #374151;
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .empty-message {
            text-align: center !important;
            padding: 3rem 1rem !important;
            color: #9ca3af;
            font-style: italic;
        }

        .quantity-controls {
            display: flex;
            gap: 6px;
            align-items: center;
            justify-content: center;
            width: 100%;
        }

        .qty-btn {
            width: 32px;
            height: 32px;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.1rem;
            font-weight: 600;
            color: #374151;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qty-btn:hover {
            border-color: #3b82f6;
            color: #3b82f6;
        }

        .qty-input {
            width: 80px;
            padding: 6px 10px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            text-align: center;
            font-size: 0.9rem;
            transition: border-color 0.2s;
        }

        .qty-input:focus {
            outline: none;
            border-color: #3b82f6;
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
            padding: 0.625rem 1.25rem;
            color: #374151;
            font-weight: 500;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        .btn-danger {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .btn-danger:hover {
            background: #fecaca;
        }

    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

    <div class="main-content">
        <header class="page-header">
            <h2>Yeni Sevkiyat Oluştur</h2>
            <!-- Added cart button in header -->
            <div class="page-header-actions">
                <button class="btn btn-primary sepet-btn" id="sepetToggleBtn" onclick="toggleSepet()" style="position: relative;">
                    🛒 Sepet
                    <span class="sepet-badge" id="sepetBadge" style="display: none;">0</span>
                </button>
                <button class="btn btn-secondary" onclick="window.location.href='Sevkiyat.php'">← Geri Dön</button>
            </div>
        </header>

        <div class="content-wrapper">
            <!-- Wrapped main content in layout container -->
            <div class="main-layout-container" id="mainLayoutContainer">
                <div class="main-content-left">
                    <!-- Bilgi Kartı -->
                    <?php if (empty($fromWarehouses) || empty($toWarehouses)): ?>
                        <div class="alert alert-warning">
                            <strong>⚠ Uyarı:</strong> Çıkış veya gideceği depo bulunamadı. Lütfen depo ayarlarını kontrol edin.
                        </div>
                    <?php endif; ?>

                    <!-- Bilgi Formu -->
                    <section class="card">
                        <div class="card-header">
                            <h3>Sevkiyat Bilgileri</h3>
                        </div>
                        <div class="card-body">
                            <form id="sevkiyatForm">
                                <div class="form-grid">
                                    <!-- Çıkış Depo (Seçilebilir) -->
                                    <div class="form-group">
                                        <label class="form-label required" for="fromWarehouseInput">Çıkış Depo</label>
                                        <div class="single-select-container">
                                            <div class="single-select-input" id="fromWarehouseDisplay" onclick="toggleFromWhsDropdown()">
                                                <input type="text" 
                                                       id="fromWarehouseInput" 
                                                       placeholder="Depo seçiniz" 
                                                       readonly
                                                       value="">
                                                <span class="dropdown-arrow">▼</span>
                                            </div>
                                            <div class="single-select-dropdown" id="fromWarehouseDropdown">
                                                <?php foreach ($fromWarehouses as $whs): ?>
                                                    <div class="single-select-option" 
                                                         data-value="<?= htmlspecialchars($whs['WarehouseCode']) ?>"
                                                         data-name="<?= htmlspecialchars($whs['WarehouseName']) ?>"
                                                         onclick="selectFromWarehouse('<?= htmlspecialchars($whs['WarehouseCode']) ?>', '<?= htmlspecialchars($whs['WarehouseName']) ?>')">
                                                        <?= htmlspecialchars($whs['WarehouseCode']) ?> - <?= htmlspecialchars($whs['WarehouseName']) ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <!-- Hidden input for form submission if needed, but managed by JS -->
                                        <input type="hidden" id="fromWarehouseSelected" value="">
                                    </div>

                                    <!-- Gideceği Depo (Seçilebilir) -->
                                    <div class="form-group">
                                        <label class="form-label required" for="toWarehouseInput">Gideceği Depo</label>
                                        <div class="single-select-container">
                                            <div class="single-select-input" id="toWarehouseDisplay" onclick="toggleToWhsDropdown()">
                                                <input type="text" 
                                                       id="toWarehouseInput" 
                                                       placeholder="Depo seçiniz" 
                                                       readonly
                                                       value="">
                                                <span class="dropdown-arrow">▼</span>
                                            </div>
                                            <div class="single-select-dropdown" id="toWarehouseDropdown">
                                                <?php foreach ($toWarehouses as $whs): ?>
                                                    <div class="single-select-option" 
                                                         data-value="<?= htmlspecialchars($whs['WarehouseCode']) ?>"
                                                         data-name="<?= htmlspecialchars($whs['WarehouseName']) ?>"
                                                         onclick="selectToWarehouse('<?= htmlspecialchars($whs['WarehouseCode']) ?>', '<?= htmlspecialchars($whs['WarehouseName']) ?>')">
                                                        <?= htmlspecialchars($whs['WarehouseCode']) ?> - <?= htmlspecialchars($whs['WarehouseName']) ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                         <!-- Hidden input for form submission if needed, but managed by JS -->
                                        <input type="hidden" id="toWarehouseSelected" value="">
                                    </div>

                                    <!-- Tarih -->
                                    <div class="form-group">
                                        <label class="form-label" for="docDate">Tarih</label>
                                        <input type="date" 
                                               id="docDate" 
                                               class="form-input"
                                               value="<?= date('Y-m-d') ?>">
                                    </div>

                                    <!-- Açıklama -->
                                    <div class="form-group full-width">
                                        <label class="form-label" for="comments">Açıklama</label>
                                        <textarea id="comments" 
                                                  class="form-input" 
                                                  rows="3" 
                                                  placeholder="Sevkiyat ile ilgili notlar..."></textarea>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </section>

                    <!-- Ürün Listesi -->
                    <section class="card">
                        <div class="card-header">
                            <h3>Ürün Listesi</h3>
                        </div>
                        <div class="card-body">
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
                                    <input type="text" 
                                           class="search-input" 
                                           id="tableSearch" 
                                           placeholder="Ara..." 
                                           onkeyup="if(event.key==='Enter') loadItems()">
                                    <button class="btn btn-secondary" onclick="loadItems()">🔍</button>
                                </div>
                            </div>

                            <div class="data-table-wrapper">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th style="text-align: center;">Ürün Kodu</th>
                                            <th style="text-align: center;">Ürün Adı</th>
                                            <th style="text-align: center;">Depo</th>
                                            <th style="text-align: center;">Miktar</th>
                                            <th style="text-align: center;">Birim</th>
                                            <th style="text-align: center;">İŞLEM</th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsTableBody">
                                        <tr>
                                            <td colspan="6" class="empty-message">Lütfen önce çıkış deposu seçin</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="pagination">
                                <button class="btn btn-secondary" id="prevBtn" onclick="changePage(-1)" disabled>← Önceki</button>
                                <span id="pageInfo">Sayfa 1</span>
                                <button class="btn btn-secondary" id="nextBtn" onclick="changePage(1)" disabled>Sonraki →</button>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- Added cart panel on the right side -->
                <div class="main-content-right sepet-panel" id="sepetPanel" style="display: none;">
                    <section class="card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                            <h3 style="margin: 0; color: #1e40af; font-size: 1.25rem; font-weight: 600;">🛒 Sepet</h3>
                            <button class="btn btn-secondary" onclick="toggleSepet()" style="padding: 0.5rem 1rem; font-size: 0.875rem;">✕ Kapat</button>
                        </div>
                        <div id="sepetList"></div>
                        <div style="margin-top: 1.5rem; text-align: right; padding-top: 1.5rem; border-top: 1px solid #e5e7eb;">
                            <button class="btn btn-primary" onclick="saveSevkiyat()">✓ Sevkiyat Oluştur</button>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </div>

    <script>
        let fromWarehouseSelected = '';
        let fromWarehouseName = '';
        let toWarehouseSelected = '';
        let toWarehouseName = '';
        
        let cart = {}; // ItemCode -> {ItemCode, ItemName, FromWarehouse, ToWarehouse, Quantity, UoMEntry, UoMCode, BaseQty}
        let productList = [];
        let currentPage = 0;
        let hasMore = false;
        let pageSize = 25;

        // Dropdown toggle functions
        function toggleFromWhsDropdown() {
            const display = document.getElementById('fromWarehouseDisplay');
            const dropdown = document.getElementById('fromWarehouseDropdown');
            const isActive = display.classList.contains('active');
            
            // Close other dropdowns
            document.getElementById('toWarehouseDisplay').classList.remove('active');
            document.getElementById('toWarehouseDropdown').classList.remove('show');
            
            if (isActive) {
                display.classList.remove('active');
                dropdown.classList.remove('show');
            } else {
                display.classList.add('active');
                dropdown.classList.add('show');
            }
        }

        function toggleToWhsDropdown() {
            const display = document.getElementById('toWarehouseDisplay');
            const dropdown = document.getElementById('toWarehouseDropdown');
            const isActive = display.classList.contains('active');
            
            // Close other dropdowns
            document.getElementById('fromWarehouseDisplay').classList.remove('active');
            document.getElementById('fromWarehouseDropdown').classList.remove('show');
            
            if (isActive) {
                display.classList.remove('active');
                dropdown.classList.remove('show');
            } else {
                display.classList.add('active');
                dropdown.classList.add('show');
            }
        }

        function selectFromWarehouse(code, name) {
            fromWarehouseSelected = code;
            fromWarehouseName = name;
            document.getElementById('fromWarehouseInput').value = code + ' - ' + name;
            document.getElementById('fromWarehouseDisplay').classList.remove('active');
            document.getElementById('fromWarehouseDropdown').classList.remove('show');
            
            // Update hidden input
            document.getElementById('fromWarehouseSelected').value = code;
            
            // Ürün listesini yenile
            if (toWarehouseSelected) {
                loadItems();
            }
        }

        function selectToWarehouse(code, name) {
            toWarehouseSelected = code;
            toWarehouseName = name;
            document.getElementById('toWarehouseInput').value = code + ' - ' + name;
            document.getElementById('toWarehouseDisplay').classList.remove('active');
            document.getElementById('toWarehouseDropdown').classList.remove('show');

            // Update hidden input
            document.getElementById('toWarehouseSelected').value = code;
            
            // Ürün listesindeki tüm miktar alanlarını sıfırla
            productList.forEach(item => {
                const qtyInput = document.getElementById('qty_' + item.ItemCode);
                if (qtyInput) {
                    qtyInput.value = '0';
                }
            });
            
            // Ürün listesini yenile
            if (fromWarehouseSelected) {
                loadItems();
            }
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.single-select-container')) {
                document.querySelectorAll('.single-select-input').forEach(el => el.classList.remove('active'));
                document.querySelectorAll('.single-select-dropdown').forEach(el => el.classList.remove('show'));
            }
        });

        // Sayfa boyutunu güncelle
        function updatePageSize() {
            pageSize = parseInt(document.getElementById('entriesPerPage').value) || 25;
            currentPage = 0;
            loadItems();
        }

        // Ürünleri yükle
        function loadItems() {
            if (!fromWarehouseSelected) {
                const tbody = document.getElementById('itemsTableBody');
                tbody.innerHTML = '<tr><td colspan="6" class="empty-message">Lütfen önce çıkış deposu seçin</td></tr>';
                return;
            }

            const search = document.getElementById('tableSearch').value.trim();
            const skip = currentPage * pageSize;

            // Construct URL correctly
            const url = `SevkiyatSO.php?ajax=items&warehouseCode=${encodeURIComponent(fromWarehouseSelected)}&search=${encodeURIComponent(search)}&top=${pageSize}&skip=${skip}`;

            fetch(url)
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        alert('Hata: ' + data.error);
                        return;
                    }

                    productList = data.data || [];
                    hasMore = (data.count || 0) >= pageSize;
                    renderItems();
                    updatePagination();
                })
                .catch(err => {
                    console.error('Ürün listesi hatası:', err);
                    alert('Ürün listesi yüklenirken hata oluştu');
                });
        }

        // Ürünleri render et
        function renderItems() {
            const tbody = document.getElementById('itemsTableBody');
            
            if (productList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="empty-message">Ürün bulunamadı</td></tr>'; 
                return;
            }

            tbody.innerHTML = productList.map(item => {
                const itemCode = item.ItemCode || '';
                const itemName = item.ItemName || '';
                const onHand = parseFloat(item.OnHand || 0);
                const inventoryUOM = item.InventoryUOM || 'AD';
                const uomList = item.UoMList || [];
                
                // Sepetteki miktar - composite key ile kontrol et
                const uomEntry = '-1'; // Base unit
                const cartKey = `${itemCode}|${fromWarehouseSelected}|${toWarehouseSelected}|${uomEntry}`;
                const cartItem = cart[cartKey] || null;
                let cartQty = 0;
                // Eğer sepette bu ürün varsa ve aynı depo kombinasyonuna aitse göster
                if (cartItem) {
                    cartQty = parseFloat(cartItem.Quantity || 0);
                }


                return `
                    <tr>
                        <td style="text-align: center;"><strong>${itemCode}</strong></td>
                        <td style="text-align: center;">${itemName}</td>
                        <td style="text-align: center;">${fromWarehouseName || fromWarehouseSelected || '-'}</td>
                        <td style="text-align: center;">
                            <div class="quantity-controls">
                                <button type="button" class="qty-btn" onclick="changeQuantity('${itemCode}', -1)">−</button>
                                <input type="number" 
                                       id="qty_${itemCode}" 
                                       class="qty-input" 
                                       value="${cartQty === 0 ? '0' : (cartQty % 1 === 0 ? cartQty.toString() : cartQty.toFixed(2))}"
                                       min="0" 
                                       step="0.01"
                                       onchange="updateQuantity('${itemCode}', this.value)">
                                <button type="button" class="qty-btn" onclick="changeQuantity('${itemCode}', 1)">+</button>
                            </div>
                        </td>
                        <td style="text-align: center;">
                            AD
                        </td>
                        <td style="text-align: center;">
                            <button class="btn btn-primary btn-small" onclick="addToCart('${itemCode}')">Ekle</button>
                        </td>
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
            // Tam sayı ise tam sayı olarak göster, değilse virgüllü göster
            input.value = value % 1 === 0 ? value.toString() : value.toFixed(2);
        }

        function updateQuantity(itemCode, value) {
            const input = document.getElementById('qty_' + itemCode);
            if (!input) return;
            
            let qty = parseFloat(value) || 0;
            if (qty < 0) qty = 0;
            // Tam sayı ise tam sayı olarak göster, değilse virgüllü göster
            input.value = qty % 1 === 0 ? qty.toString() : qty.toFixed(2);
        }

        function addToCart(itemCode) {
            const item = productList.find(p => p.ItemCode === itemCode);
            if (!item) return;

            const qtyInput = document.getElementById('qty_' + itemCode);
            const quantity = parseFloat(qtyInput.value) || 0;
            
            if (quantity <= 0) {
                alert('Lütfen geçerli bir miktar giriniz.');
                return;
            }

            if (!fromWarehouseSelected || !toWarehouseSelected) {
                alert('Lütfen hem çıkış hem de hedef depoyu seçin.');
                return;
            }

            // Birim bilgisi - sadece InventoryUOM kullanılacak
            let uomEntry = '-1'; // Base unit
            let uomCode = item.InventoryUOM || 'AD';
            let baseQty = 1.0;

            // Composite key oluştur: itemCode|fromWhs|toWhs|uomEntry
            const cartKey = `${itemCode}|${fromWarehouseSelected}|${toWarehouseSelected}|${uomEntry}`;

            // Sepete ekle
            cart[cartKey] = {
                ItemCode: itemCode,
                ItemName: item.ItemName || '',
                FromWarehouse: fromWarehouseSelected,
                ToWarehouse: toWarehouseSelected,
                Quantity: quantity,
                UoMEntry: uomEntry,
                UoMCode: uomCode,
                BaseQty: baseQty,
                _key: cartKey
            };

            // Miktarı sıfırla ve ürün listesindeki değeri güncelle
            qtyInput.value = '0.00';

            updateSepet();
        }

        function toggleSepet() {
            const panel = document.getElementById('sepetPanel');
            const container = document.getElementById('mainLayoutContainer');
            const isOpen = panel.style.display !== 'none';
            
            if (isOpen) {
                panel.style.display = 'none';
                container.classList.remove('sepet-open');
            } else {
                panel.style.display = 'flex'; // Changed to flex to make it display correctly as a column
                container.classList.add('sepet-open');
                updateSepet(); // Update cart display when opening
            }
        }

        function updateSepet() {
            const list = document.getElementById('sepetList');
            const badge = document.getElementById('sepetBadge');
            const itemCount = Object.keys(cart).length;
            
            // Miktar formatı: 10.00 → 10, 10,50 → 10,5
            function formatQuantity(qty) {
                const num = parseFloat(qty);
                if (isNaN(num)) return '0';
                if (num % 1 === 0) {
                    return num.toString();
                }
                // Use comma for decimal separator
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
            
            // Ürünleri depo bazında grupla
            const groupedByWarehouse = {};
            Object.values(cart).forEach(item => {
                const warehouseKey = `${item.FromWarehouse} → ${item.ToWarehouse}`;
                if (!groupedByWarehouse[warehouseKey]) {
                    groupedByWarehouse[warehouseKey] = [];
                }
                groupedByWarehouse[warehouseKey].push(item);
            });
            
            // Her depo grubu için HTML oluştur
            let html = '';
            Object.keys(groupedByWarehouse).forEach(warehouseKey => {
                const items = groupedByWarehouse[warehouseKey];
                const [fromWhs, toWhs] = warehouseKey.split(' → ');
                
                // Depo başlığı
                html += `
                    <div style="margin-bottom: 1rem; padding-bottom: 0.75rem; border-bottom: 2px solid #e5e7eb;">
                        <div style="font-weight: 600; color: #1e40af; font-size: 0.95rem; margin-bottom: 0.75rem;">
                            ${warehouseKey}
                        </div>
                `;
                
                // Bu depoya ait ürünler
                items.forEach(item => {
                    const qty = parseFloat(item.Quantity) || 0;
                    const baseQty = parseFloat(item.BaseQty || 1.0);
                    const uomCode = item.UoMCode || 'AD';
                    
                    // Miktar + birim gösterimi
                    let qtyDisplay = `${formatQuantity(qty)} ${uomCode}`;
                    
                    // Eğer çevrimli ise (BaseQty !== 1), AD karşılığını da göster
                    let conversionInfo = '';
                    if (baseQty !== 1 && baseQty > 0) {
                        const adKarşılığı = qty * baseQty;
                        qtyDisplay += ` <span style="font-size: 0.85rem; color: #6b7280; font-weight: normal;">(${formatQuantity(adKarşılığı)} AD)</span>`;
                        conversionInfo = `<div style="font-size: 0.8rem; color: #3b82f6; margin-top: 4px;">Dönüşüm: ${formatQuantity(qty)}x${formatQuantity(baseQty)} = ${formatQuantity(adKarşılığı)} AD</div>`;
                    }
                    
                    html += `
                        <div class="sepet-item" style="margin-bottom: 0.75rem;">
                            <div class="sepet-item-info">
                                <div class="sepet-item-name">${item.ItemCode} - ${item.ItemName}</div>
                                <div style="margin-bottom: 0.5rem; font-size: 0.9rem; color: #3b82f6; font-weight: 600;">${qtyDisplay}</div>
                                ${conversionInfo}
                            </div>
                            <button type="button" class="remove-sepet-btn" onclick="removeFromCart('${item._key}')">Kaldır</button>
                        </div>
                    `;
                });
                
                html += '</div>';
            });
            
            list.innerHTML = html;
        }

        function removeFromCart(cartKey) {
            if (cart[cartKey]) {
                const item = cart[cartKey];
                delete cart[cartKey];
                // Reset the quantity input in the product list for this specific item
                const input = document.getElementById('qty_' + item.ItemCode);
                if (input) {
                    // Sadece bu depo kombinasyonu için miktarı kontrol et
                    const currentKey = `${item.ItemCode}|${fromWarehouseSelected}|${toWarehouseSelected}|${item.UoMEntry}`;
                    if (cartKey === currentKey) {
                        input.value = '0';
                    }
                }
                updateSepet();
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
            document.getElementById('nextBtn').disabled = !hasMore; // Assuming 'hasMore' is set correctly in loadItems
        }

        function saveSevkiyat() {
            // Filter out items with zero quantity and convert cart object to array
            const itemsToSave = Object.values(cart).filter(item => parseFloat(item.Quantity || 0) > 0);
            
            if (itemsToSave.length === 0) {
                alert('Lütfen sepete en az bir ürün ekleyin!');
                return;
            }

            // Ürünleri depo bazında grupla
            const groupedByWarehouse = {};
            itemsToSave.forEach(item => {
                const warehouseKey = `${item.FromWarehouse} → ${item.ToWarehouse}`;
                if (!groupedByWarehouse[warehouseKey]) {
                    groupedByWarehouse[warehouseKey] = {
                        fromWarehouse: item.FromWarehouse,
                        toWarehouse: item.ToWarehouse,
                        items: []
                    };
                }
                groupedByWarehouse[warehouseKey].items.push(item);
            });

            const warehouseGroups = Object.values(groupedByWarehouse);
            
            if (warehouseGroups.length === 0) {
                alert('Lütfen sepete en az bir ürün ekleyin!');
                return;
            }

            if (!confirm(`${warehouseGroups.length} farklı depo için sevkiyat oluşturulacak. Devam etmek istediğinize emin misiniz?`)) {
                return;
            }

            // Disable save button to prevent multiple submissions
            const saveButton = document.querySelector('#sepetPanel button.btn-primary');
            saveButton.disabled = true;
            saveButton.textContent = 'Kaydediliyor...';

            // Her depo grubu için sevkiyat oluştur
            const promises = warehouseGroups.map(group => {
                const formData = new FormData();
                formData.append('action', 'create');
                formData.append('fromWarehouse', group.fromWarehouse);
                formData.append('toWarehouse', group.toWarehouse);
                formData.append('docDate', document.getElementById('docDate').value);
                formData.append('comments', document.getElementById('comments').value);
                formData.append('items', JSON.stringify(group.items));

                return fetch('SevkiyatSO.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => {
                    if (!res.ok) {
                        throw new Error(`HTTP error! status: ${res.status}`);
                    }
                    return res.json();
                });
            });

            // Tüm sevkiyatları bekle
            Promise.all(promises)
            .then(results => {
                const successCount = results.filter(r => r.success).length;
                const failCount = results.filter(r => !r.success).length;
                
                if (failCount === 0) {
                    alert(`${successCount} sevkiyat başarıyla oluşturuldu!`);
                    window.location.href = 'Sevkiyat.php';
                } else {
                    alert(`${successCount} sevkiyat başarılı, ${failCount} sevkiyat başarısız oldu.`);
                    saveButton.disabled = false;
                    saveButton.textContent = '✓ Sevkiyat Oluştur';
                }
            })
            .catch(err => {
                console.error('Sevkiyat kaydetme hatası:', err);
                alert('Sevkiyat kaydedilirken hata oluştu: ' + err.message);
                saveButton.disabled = false;
                saveButton.textContent = '✓ Sevkiyat Oluştur';
            });
        }

        // Initial setup when the DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Set initial values for hidden inputs if they exist (though JS manages selected values)
            document.getElementById('fromWarehouseSelected').value = fromWarehouseSelected;
            document.getElementById('toWarehouseSelected').value = toWarehouseSelected;
            
            // Initial check for warehouse selection to enable/disable pagination and load items
            if (fromWarehouseSelected && toWarehouseSelected) {
                loadItems();
            } else {
                // If warehouses are not selected, show the prompt in the table
                const tbody = document.getElementById('itemsTableBody');
                tbody.innerHTML = '<tr><td colspan="6" class="empty-message">Lütfen önce çıkış ve gideceği depo seçin</td></tr>';
            }
        });
    </script>
</body>
</html>
