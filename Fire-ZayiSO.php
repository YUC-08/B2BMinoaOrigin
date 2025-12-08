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

// Çıkış Depo listesi (Ana depo - U_ASB2B_MAIN eq '1' or '2')
$fromWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and (U_ASB2B_MAIN eq '1' or U_ASB2B_MAIN eq '2')";
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

// AJAX: Gideceği Depo listesi getir (Fire veya Zayi'ye göre)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'targetWarehouses') {
    header('Content-Type: application/json');
    
    $lostType = trim($_GET['lostType'] ?? '');
    if (empty($lostType) || ($lostType !== '1' && $lostType !== '2')) {
        echo json_encode(['data' => [], 'error' => 'Geçersiz tür']);
        exit;
    }
    
    // Fire (1) için MAIN=3, Zayi (2) için MAIN=4
    $mainValue = $lostType === '1' ? '3' : '4';
    
    // Önce sadece U_AS_OWNR ile filtrele (daha esnek - branch değeri farklı olabilir)
    // Çıkış deposundan branch bilgisini çıkarabiliriz
    $targetWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}'";
    $targetWarehouseQuery = "Warehouses?\$select=WarehouseCode,WarehouseName,U_AS_OWNR,U_ASB2B_BRAN,U_ASB2B_MAIN&\$filter=" . urlencode($targetWarehouseFilter) . "&\$orderby=WarehouseCode";
    
    $targetWarehouseData = $sap->get($targetWarehouseQuery);
    
    $targetWarehouses = [];
    $errorMsg = null;
    $debugInfo = [
        'query' => $targetWarehouseQuery,
        'filter' => $targetWarehouseFilter,
        'mainValue' => $mainValue,
        'lostType' => $lostType,
        'uAsOwnr' => $uAsOwnr,
        'branch' => $branch,
        'status' => $targetWarehouseData['status'] ?? 'NO STATUS'
    ];
    
    if (($targetWarehouseData['status'] ?? 0) == 200) {
        // Response'u farklı formatlardan parse et
        $allWarehouses = [];
        if (isset($targetWarehouseData['response']['value']) && is_array($targetWarehouseData['response']['value'])) {
            $allWarehouses = $targetWarehouseData['response']['value'];
        } elseif (isset($targetWarehouseData['value']) && is_array($targetWarehouseData['value'])) {
            $allWarehouses = $targetWarehouseData['value'];
        } elseif (isset($targetWarehouseData['response']) && is_array($targetWarehouseData['response'])) {
            $allWarehouses = $targetWarehouseData['response'];
        }
        
        // Çıkış deposundan branch bilgisini çıkar (örn: 100-KT-0 -> 100)
        $fromWarehouseCode = $_GET['fromWarehouse'] ?? '';
        $extractedBranch = '';
        if (!empty($fromWarehouseCode)) {
            // WarehouseCode formatı: "100-KT-0" veya "200-KT-0" -> ilk kısım branch
            $parts = explode('-', $fromWarehouseCode);
            if (!empty($parts[0])) {
                $extractedBranch = $parts[0];
            }
        }
        
        // PHP tarafında MAIN ve branch değerine göre filtrele
        foreach ($allWarehouses as $whs) {
            $whsMain = $whs['U_ASB2B_MAIN'] ?? '';
            $whsBranch = $whs['U_ASB2B_BRAN'] ?? '';
            $whsCode = $whs['WarehouseCode'] ?? '';
            
            // MAIN değerini string veya integer olarak karşılaştır
            $mainMatch = ($whsMain == $mainValue || $whsMain === $mainValue || (string)$whsMain === (string)$mainValue);
            
            // Branch kontrolü: Hem session'dan gelen branch hem de warehouse code'dan çıkarılan branch ile karşılaştır
            $branchMatch = false;
            if (!empty($extractedBranch)) {
                // WarehouseCode'dan çıkarılan branch ile eşleş
                $whsParts = explode('-', $whsCode);
                $whsCodeBranch = !empty($whsParts[0]) ? $whsParts[0] : '';
                $branchMatch = ($whsCodeBranch === $extractedBranch);
            } else {
                // Session'dan gelen branch ile eşleş
                $branchMatch = ($whsBranch == $branch || $whsBranch === $branch || (string)$whsBranch === (string)$branch);
            }
            
            if ($mainMatch && $branchMatch) {
                // Sadece WarehouseCode ve WarehouseName döndür
                $targetWarehouses[] = [
                    'WarehouseCode' => $whs['WarehouseCode'] ?? '',
                    'WarehouseName' => $whs['WarehouseName'] ?? ''
                ];
            }
        }
        
        $debugInfo['allWarehousesCount'] = count($allWarehouses);
        $debugInfo['filteredCount'] = count($targetWarehouses);
        $debugInfo['extractedBranch'] = $extractedBranch;
        $debugInfo['fromWarehouseCode'] = $fromWarehouseCode;
        $debugInfo['sampleAll'] = !empty($allWarehouses) ? $allWarehouses[0] : null;
        $debugInfo['sampleFiltered'] = !empty($targetWarehouses) ? $targetWarehouses[0] : null;
        // İlk 10 depoyu debug için gönder (tümü çok fazla olabilir)
        $debugInfo['sampleWarehouses'] = array_slice($allWarehouses, 0, 10);
    } else {
        // Hata durumu
        $errorMsg = 'HTTP ' . ($targetWarehouseData['status'] ?? 'NO STATUS');
        if (isset($targetWarehouseData['response']['error'])) {
            $error = $targetWarehouseData['response']['error'];
            $errorMsg .= ' - ' . ($error['message']['value'] ?? $error['message'] ?? json_encode($error));
            $debugInfo['error'] = $error;
        }
        $debugInfo['rawResponse'] = $targetWarehouseData['response'] ?? null;
    }
    
    if ($errorMsg) {
        echo json_encode([
            'data' => [],
            'count' => 0,
            'error' => $errorMsg,
            'debug' => $debugInfo
        ]);
    } else {
        echo json_encode([
            'data' => $targetWarehouses,
            'count' => count($targetWarehouses),
            'mainValue' => $mainValue,
            'debug' => $debugInfo
        ]);
    }
    exit;
}

// AJAX: Ürün listesi getir (ASB2B_InventoryWhsItem_B1SLQuery)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'items') {
    header('Content-Type: application/json');
    
    $warehouseCode = trim($_GET['warehouseCode'] ?? '');
    if (empty($warehouseCode)) {
        echo json_encode(['data' => [], 'count' => 0, 'error' => 'Depo seçilmedi']);
        exit;
    }
    
    $skip = intval($_GET['skip'] ?? 0);
    $top = intval($_GET['top'] ?? 25);
    $search = trim($_GET['search'] ?? '');
    
    // ASB2B_InventoryWhsItem_B1SLQuery view'ini kullan
    // View expose kontrolü
    $viewCheckQuery = "view.svc/ASB2B_InventoryWhsItem_B1SLQuery?\$top=1";
    $viewCheck = $sap->get($viewCheckQuery);
    $viewCheckError = $viewCheck['response']['error'] ?? null;
    
    // View expose edilmemişse (806 hatası), expose et
    if (isset($viewCheckError['code']) && $viewCheckError['code'] === '806') {
        $exposeResult = $sap->post("SQLViews('ASB2B_InventoryWhsItem_B1SLQuery')/Expose", []);
        $exposeStatus = $exposeResult['status'] ?? 'NO STATUS';
        
        if ($exposeStatus != 200 && $exposeStatus != 201 && $exposeStatus != 204) {
            echo json_encode([
                'data' => [],
                'count' => 0,
                'error' => 'View expose edilemedi!'
            ]);
            exit;
        }
        
        sleep(1);
    }
    
    // Önce view'den bir örnek kayıt çekip property'leri görelim
    $sampleQuery = "view.svc/ASB2B_InventoryWhsItem_B1SLQuery?\$top=1";
    $sampleData = $sap->get($sampleQuery);
    $sampleItem = null;
    
    if (($sampleData['status'] ?? 0) == 200) {
        if (isset($sampleData['response']['value']) && !empty($sampleData['response']['value'])) {
            $sampleItem = $sampleData['response']['value'][0];
        } elseif (isset($sampleData['value']) && !empty($sampleData['value'])) {
            $sampleItem = $sampleData['value'][0];
        }
    }
    
    // WarehouseCode yerine doğru property adını bul
    $warehouseProperty = null;
    if ($sampleItem) {
        $possibleNames = ['WarehouseCode', 'WhsCode', 'Warehouse', 'WarehouseName', 'WhsName'];
        foreach ($possibleNames as $name) {
            if (isset($sampleItem[$name])) {
                $warehouseProperty = $name;
                break;
            }
        }
    }
    
    if (!$warehouseProperty) {
        echo json_encode([
            'data' => [],
            'count' => 0,
            'error' => 'Warehouse property bulunamadı!'
        ]);
        exit;
    }
    
    // Doğru property ile filtreleme
    $warehouseCodeEscaped = str_replace("'", "''", $warehouseCode);
    $filter = "{$warehouseProperty} eq '{$warehouseCodeEscaped}'";
    
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
                
                // UoM listesini çek - expand kullanmadan, direkt collection path ile
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

// POST: Fire/Zayi belgesi oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    header('Content-Type: application/json');
    
    $fromWarehouse = trim($_POST['fromWarehouse'] ?? '');
    $toWarehouse = trim($_POST['toWarehouse'] ?? '');
    $lostType = trim($_POST['lostType'] ?? '');
    $docDate = trim($_POST['docDate'] ?? date('Y-m-d'));
    $comments = trim($_POST['comments'] ?? '');
    $lines = isset($_POST['lines']) ? json_decode($_POST['lines'], true) : [];
    
    if (empty($fromWarehouse) || empty($toWarehouse) || empty($lostType) || empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'Eksik bilgi: Depo, tür ve en az bir kalem gereklidir']);
        exit;
    }
    
    if ($lostType !== '1' && $lostType !== '2') {
        echo json_encode(['success' => false, 'message' => 'Geçersiz tür (Fire: 1, Zayi: 2)']);
        exit;
    }
    
    // StockTransferLines oluştur
    $stockTransferLines = [];
    foreach ($lines as $line) {
        $itemCode = $line['ItemCode'] ?? '';
        $fireQty = floatval($line['FireQty'] ?? 0);
        $zayiQty = floatval($line['ZayiQty'] ?? 0);
        $unitPrice = floatval($line['UnitPrice'] ?? 0);
        $uomEntry = $line['UoMEntry'] ?? null;
        $uomCode = $line['UoMCode'] ?? '';
        
        // Belge türüne göre miktar seç
        $quantity = $lostType === '1' ? $fireQty : $zayiQty;
        
        if (empty($itemCode) || $quantity <= 0) {
            continue;
        }
        
        $lineData = [
            'ItemCode' => $itemCode,
            'Quantity' => $quantity,
            'FromWarehouseCode' => $fromWarehouse,
            'WarehouseCode' => $toWarehouse
        ];
        
        // UoM bilgisi
        if (!empty($uomEntry)) {
            $lineData['UoMEntry'] = intval($uomEntry);
        }
        if (!empty($uomCode)) {
            $lineData['UoMCode'] = $uomCode;
        }
        
        // Birim fiyat (opsiyonel)
        if ($unitPrice > 0) {
            $lineData['UnitPrice'] = $unitPrice;
        }
        
        $stockTransferLines[] = $lineData;
    }
    
    if (empty($stockTransferLines)) {
        echo json_encode(['success' => false, 'message' => 'Geçerli satır bulunamadı']);
        exit;
    }
    
    // StockTransfers payload
    $payload = [
        'U_ASB2B_TYPE' => 'TRANSFER',
        'U_ASB2B_LOST' => $lostType,
        'U_AS_OWNR' => $uAsOwnr,
        'U_ASB2B_BRAN' => $branch,
        'DocDate' => $docDate,
        'FromWarehouse' => $fromWarehouse,
        'ToWarehouse' => $toWarehouse,
        'StockTransferLines' => $stockTransferLines
    ];
    
    if (!empty($comments)) {
        $payload['Comments'] = $comments;
    }
    
    $result = $sap->post('StockTransfers', $payload);
    
    if (($result['status'] ?? 0) == 200 || ($result['status'] ?? 0) == 201) {
        $docEntry = $result['response']['DocEntry'] ?? null;
        echo json_encode([
            'success' => true, 
            'message' => 'Fire/Zayi belgesi başarıyla oluşturuldu!',
            'docEntry' => $docEntry
        ]);
    } else {
        $errorMsg = 'Belge oluşturulamadı: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $error = $result['response']['error'];
            $errorMsg .= ' - ' . ($error['message']['value'] ?? $error['message'] ?? json_encode($error));
        }
        echo json_encode([
            'success' => false, 
            'message' => $errorMsg,
            'debug' => $result
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
    <title>Yeni Fire/Zayi - MINOA</title>
    <?php include 'navbar.php'; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            color: #111827;
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

        .form-input,
        .form-select {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            background: white;
            color: #111827;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-input:disabled,
        .form-select:disabled {
            background: #f3f4f6;
            cursor: not-allowed;
            color: #6b7280;
        }

        .radio-group {
            display: flex;
            gap: 24px;
            align-items: center;
            padding: 12px 0;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .radio-option input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #3b82f6;
        }

        .radio-option label {
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            margin: 0;
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

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
        }

        .info-message {
            padding: 12px 16px;
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            border-radius: 6px;
            font-size: 14px;
            color: #1e40af;
            margin-bottom: 16px;
        }

        .error-message {
            padding: 12px 16px;
            background: #fef2f2;
            border-left: 4px solid #dc2626;
            border-radius: 6px;
            font-size: 14px;
            color: #991b1b;
            margin-bottom: 16px;
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

        .data-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
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

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover:not(:disabled) {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .quantity-group {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .quantity-group label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
            min-width: 50px;
        }

        .cart-section {
            margin-top: 24px;
        }

        .text-right {
            text-align: right;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }
    </style>
</head>
<body>
    <main class="main-content">
        <header class="page-header">
            <h2>Yeni Fire/Zayi Ekle</h2>
            <button class="btn btn-secondary" onclick="window.location.href='Fire-Zayi.php'">← Geri Dön</button>
        </header>

        <div class="content-wrapper">
            <section class="card">
                <div class="card-header">
                    <h3>Üst Bilgiler</h3>
                </div>
                <div class="card-body">
                    <form id="fireZayiForm">
                        <div class="form-grid">
                            <!-- Çıkış Depo -->
                            <div class="form-group">
                                <label class="form-label required" for="fromWarehouse">Çıkış Depo</label>
                                <select class="form-select" id="fromWarehouse" name="fromWarehouse" required>
                                    <option value="">Depo seçiniz</option>
                                    <?php foreach ($fromWarehouses as $whs): ?>
                                    <option value="<?= htmlspecialchars($whs['WarehouseCode']) ?>">
                                        <?= htmlspecialchars($whs['WarehouseCode']) ?> - <?= htmlspecialchars($whs['WarehouseName'] ?? '') ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Fire/Zayi Türü -->
                            <div class="form-group">
                                <label class="form-label required">Fire/Zayi Türü</label>
                                <div class="radio-group">
                                    <div class="radio-option">
                                        <input type="radio" id="lostTypeFire" name="lostType" value="1" required>
                                        <label for="lostTypeFire">Fire</label>
                                    </div>
                                    <div class="radio-option">
                                        <input type="radio" id="lostTypeZayi" name="lostType" value="2" required>
                                        <label for="lostTypeZayi">Zayi</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Gideceği Depo -->
                            <div class="form-group">
                                <label class="form-label required" for="toWarehouse">Gideceği Depo</label>
                                <select class="form-select" id="toWarehouse" name="toWarehouse" required disabled>
                                    <option value="">Önce Fire/Zayi türünü seçiniz</option>
                                </select>
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

                        <div class="action-buttons">
                            <button type="button" class="btn btn-secondary" onclick="window.location.href='Fire-Zayi.php'">İptal</button>
                            <button type="submit" class="btn btn-primary" id="saveBtn" disabled>Kaydet</button>
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
                                    <th>Fire Miktarı</th>
                                    <th>Zayi Miktarı</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="productTableBody">
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #6b7280;">
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
                                    <th class="text-right">Fire Miktar</th>
                                    <th class="text-right">Zayi Miktar</th>
                                    <th class="text-right">Birim Fiyat</th>
                                    <th class="text-right">Satır Toplamı</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="cartTableBody">
                                <tr>
                                    <td colspan="10" class="empty-message">Sepet boş</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script>
        const fromWarehouseSelect = document.getElementById('fromWarehouse');
        const lostTypeRadios = document.querySelectorAll('input[name="lostType"]');
        const toWarehouseSelect = document.getElementById('toWarehouse');
        const saveBtn = document.getElementById('saveBtn');
        const form = document.getElementById('fireZayiForm');

        // Sayfa yüklendiğinde seçili Fire/Zayi türü varsa depo listesini yükle
        const initialLostType = document.querySelector('input[name="lostType"]:checked')?.value;
        if (initialLostType) {
            loadTargetWarehouses(initialLostType);
        }

        // Fire/Zayi türü değiştiğinde gideceği depo listesini güncelle
        lostTypeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                const lostType = this.value;
                if (lostType) {
                    loadTargetWarehouses(lostType);
                } else {
                    toWarehouseSelect.innerHTML = '<option value="">Önce Fire/Zayi türünü seçiniz</option>';
                    toWarehouseSelect.disabled = true;
                    updateSaveButton();
                }
            });
        });

        // Gideceği depo listesini yükle
        function loadTargetWarehouses(lostType) {
            toWarehouseSelect.disabled = true;
            toWarehouseSelect.innerHTML = '<option value="">Yükleniyor...</option>';

            const fromWarehouse = fromWarehouseSelect.value;
            const url = `?ajax=targetWarehouses&lostType=${lostType}${fromWarehouse ? '&fromWarehouse=' + encodeURIComponent(fromWarehouse) : ''}`;
            
            fetch(url)
                .then(res => res.json())
                .then(data => {
                    toWarehouseSelect.innerHTML = '<option value="">Depo seçiniz</option>';
                    
                    if (data.error) {
                        console.error('Depo yükleme hatası:', data.error, data.debug);
                        toWarehouseSelect.innerHTML = `<option value="">Hata: ${data.error}</option>`;
                        toWarehouseSelect.disabled = false;
                        updateSaveButton();
                        return;
                    }

                    if (data.data && Array.isArray(data.data) && data.data.length > 0) {
                        data.data.forEach(whs => {
                            const option = document.createElement('option');
                            option.value = whs.WarehouseCode || whs.WarehouseCode;
                            const whsName = whs.WarehouseName || '';
                            option.textContent = whsName ? `${whs.WarehouseCode} - ${whsName}` : whs.WarehouseCode;
                            toWarehouseSelect.appendChild(option);
                        });
                        
                        // Eğer tek depo varsa otomatik seç
                        if (data.data.length === 1) {
                            toWarehouseSelect.value = data.data[0].WarehouseCode;
                        }
                    } else {
                        console.warn('Depo bulunamadı:', data.debug);
                        toWarehouseSelect.innerHTML = '<option value="">Depo bulunamadı</option>';
                    }
                    
                    toWarehouseSelect.disabled = false;
                    updateSaveButton();
                })
                .catch(err => {
                    console.error('Fetch hatası:', err);
                    toWarehouseSelect.innerHTML = '<option value="">Yükleme hatası</option>';
                    toWarehouseSelect.disabled = false;
                    updateSaveButton();
                });
        }

        // Form validasyonu - Kaydet butonunu aktif/pasif yap
        function updateSaveButton() {
            const fromWarehouse = fromWarehouseSelect.value;
            const lostType = document.querySelector('input[name="lostType"]:checked')?.value;
            const toWarehouse = toWarehouseSelect.value;
            
            if (fromWarehouse && lostType && toWarehouse && !toWarehouseSelect.disabled && cart.length > 0) {
                saveBtn.disabled = false;
            } else {
                saveBtn.disabled = true;
            }
        }

        // Form alanları değiştiğinde kontrol et
        fromWarehouseSelect.addEventListener('change', updateSaveButton);
        toWarehouseSelect.addEventListener('change', updateSaveButton);
        lostTypeRadios.forEach(radio => {
            radio.addEventListener('change', updateSaveButton);
        });

        let productList = [];
        let searchTimeout = null;
        let cart = [];

        // Çıkış depo seçildiğinde ürün listesini göster
        fromWarehouseSelect.addEventListener('change', function() {
            if (this.value) {
                document.getElementById('productListSection').style.display = 'block';
                loadProducts();
            } else {
                document.getElementById('productListSection').style.display = 'none';
            }
        });

        // Ürün listesini yükle
        function loadProducts(search = '') {
            const warehouseCode = fromWarehouseSelect.value;
            if (!warehouseCode) return;

            const tbody = document.getElementById('productTableBody');
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px;">Yükleniyor...</td></tr>';

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
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #dc2626;">Yükleme hatası</td></tr>';
                });
        }

        // Ürün tablosunu render et
        function renderProductTable() {
            const tbody = document.getElementById('productTableBody');
            const lostType = document.querySelector('input[name="lostType"]:checked')?.value;

            if (productList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #6b7280;">Ürün bulunamadı</td></tr>';
                return;
            }

            tbody.innerHTML = productList.map((item, index) => {
                const itemCode = item.ItemCode || '';
                const itemName = item.ItemName || '';
                const whsCode = item.WhsCode || item.WarehouseCode || '';
                const uomList = item.UoMList || [];
                const inventoryUOM = item.InventoryUOM || '';
                
                // UoM seçimi: Eğer tek birim varsa direkt göster, çoklu ise combobox
                let uomHtml = '';
                if (uomList.length > 1) {
                    const options = uomList.map(uom => 
                        `<option value="${uom.UoMEntry}" data-code="${uom.UoMCode}" data-baseqty="${uom.BaseQty}">${uom.UoMCode}</option>`
                    ).join('');
                    uomHtml = `<select class="uom-select" id="uom-${index}" data-item-index="${index}">${options}</select>`;
                } else if (uomList.length === 1) {
                    uomHtml = `<span>${uomList[0].UoMCode}</span>`;
                } else {
                    uomHtml = `<span>${inventoryUOM || 'AD'}</span>`;
                }

                // Fire ve Zayi miktar input'ları - belge türüne göre disabled
                const fireDisabled = lostType !== '1' ? 'disabled' : '';
                const zayiDisabled = lostType !== '2' ? 'disabled' : '';

                return `
                    <tr data-item-code="${itemCode}" data-item-index="${index}">
                        <td><strong>${itemCode}</strong></td>
                        <td>${itemName}</td>
                        <td>${whsCode}</td>
                        <td>${uomHtml}</td>
                        <td>
                            <div class="quantity-controls">
                                <button type="button" class="qty-btn" onclick="changeItemQuantity('${itemCode}', 'fire', -1)" ${fireDisabled}>−</button>
                                <input type="number" 
                                       class="qty-input" 
                                       id="fireQty-${itemCode}" 
                                       data-item-code="${itemCode}"
                                       data-type="fire"
                                       min="0" 
                                       step="0.01" 
                                       value="0"
                                       ${fireDisabled}
                                       onchange="updateItemQuantity('${itemCode}', 'fire', this.value)">
                                <button type="button" class="qty-btn" onclick="changeItemQuantity('${itemCode}', 'fire', 1)" ${fireDisabled}>+</button>
                            </div>
                        </td>
                        <td>
                            <div class="quantity-controls">
                                <button type="button" class="qty-btn" onclick="changeItemQuantity('${itemCode}', 'zayi', -1)" ${zayiDisabled}>−</button>
                                <input type="number" 
                                       class="qty-input" 
                                       id="zayiQty-${itemCode}" 
                                       data-item-code="${itemCode}"
                                       data-type="zayi"
                                       min="0" 
                                       step="0.01" 
                                       value="0"
                                       ${zayiDisabled}
                                       onchange="updateItemQuantity('${itemCode}', 'zayi', this.value)">
                                <button type="button" class="qty-btn" onclick="changeItemQuantity('${itemCode}', 'zayi', 1)" ${zayiDisabled}>+</button>
                            </div>
                        </td>
                        <td>
                            <button class="btn btn-success btn-small" 
                                    onclick="addToCart('${itemCode}', ${index})">
                                Sepete Ekle
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // Fire/Zayi türü değiştiğinde miktar input'larını güncelle
        lostTypeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                const lostType = this.value;
                // Tüm miktar input'larını ve butonlarını güncelle
                document.querySelectorAll('.qty-input').forEach(input => {
                    if (input.dataset.type === 'fire') {
                        input.disabled = lostType !== '1';
                        if (lostType !== '1') input.value = '0';
                        // Butonları da disable et
                        const controls = input.closest('.quantity-controls');
                        if (controls) {
                            controls.querySelectorAll('.qty-btn').forEach(btn => {
                                btn.disabled = lostType !== '1';
                            });
                        }
                    } else if (input.dataset.type === 'zayi') {
                        input.disabled = lostType !== '2';
                        if (lostType !== '2') input.value = '0';
                        // Butonları da disable et
                        const controls = input.closest('.quantity-controls');
                        if (controls) {
                            controls.querySelectorAll('.qty-btn').forEach(btn => {
                                btn.disabled = lostType !== '2';
                            });
                        }
                    }
                });
                renderProductTable();
            });
        });

        // Miktar değiştir (+ ve - butonları)
        function changeItemQuantity(itemCode, type, delta) {
            const input = document.getElementById(`${type}Qty-${itemCode}`);
            if (!input || input.disabled) return;
            
            let value = parseFloat(input.value) || 0;
            value += delta;
            if (value < 0) value = 0;
            input.value = value;
            updateItemQuantity(itemCode, type, value);
        }

        // Miktar güncelle
        function updateItemQuantity(itemCode, type, value) {
            // Sadece değeri güncelle, sepete ekleme yapma
        }

        // Ürün arama
        function searchProducts() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                const search = document.getElementById('productSearch').value.trim();
                loadProducts(search);
            }, 300);
        }

        // Sepete ekle
        function addToCart(itemCode, index) {
            const item = productList[index];
            if (!item || item.ItemCode !== itemCode) return;

            const lostType = document.querySelector('input[name="lostType"]:checked')?.value;
            const fireQty = parseFloat(document.getElementById(`fireQty-${itemCode}`)?.value || 0);
            const zayiQty = parseFloat(document.getElementById(`zayiQty-${itemCode}`)?.value || 0);
            
            // Belge türüne göre miktar seç
            const quantity = lostType === '1' ? fireQty : zayiQty;

            if (quantity <= 0) {
                alert('Lütfen miktar giriniz');
                return;
            }
            
            // UoM bilgisi
            const row = document.querySelector(`tr[data-item-code="${itemCode}"]`);
            const uomCell = row ? row.cells[3] : null;
            let uomEntry = null;
            let uomCode = '';
            let baseQty = 1;
            
            if (uomCell) {
                const uomSelect = uomCell.querySelector('select');
                if (uomSelect) {
                    const selectedOption = uomSelect.options[uomSelect.selectedIndex];
                    uomEntry = selectedOption.value;
                    uomCode = selectedOption.getAttribute('data-code') || '';
                    baseQty = parseFloat(selectedOption.getAttribute('data-baseqty') || 1);
                } else {
                    const uomSpan = uomCell.querySelector('span');
                    if (uomSpan) {
                        uomCode = uomSpan.textContent.trim();
                    }
                }
            }
            
            if (!uomCode && item.UoMList && item.UoMList.length === 1) {
                uomEntry = item.UoMList[0].UoMEntry;
                uomCode = item.UoMList[0].UoMCode;
                baseQty = parseFloat(item.UoMList[0].BaseQty || 1);
            } else if (!uomCode) {
                uomCode = item.InventoryUOM || 'AD';
            }

            // Aynı ürün sepette var mı kontrol et
            const existingIndex = cart.findIndex(c => c.ItemCode === itemCode && c.UoMCode === uomCode);
            
            const cartItem = {
                ItemCode: item.ItemCode,
                ItemName: item.ItemName,
                FromWarehouse: fromWarehouseSelect.value,
                ToWarehouse: toWarehouseSelect.value,
                UoMEntry: uomEntry,
                UoMCode: uomCode,
                BaseQty: baseQty,
                FireQty: fireQty,
                ZayiQty: zayiQty,
                UnitPrice: 0
            };

            if (existingIndex >= 0) {
                // Mevcut satırı güncelle
                cart[existingIndex] = cartItem;
            } else {
                // Yeni satır ekle
                cart.push(cartItem);
            }

            // Input'ları sıfırla
            const fireInput = document.getElementById(`fireQty-${itemCode}`);
            const zayiInput = document.getElementById(`zayiQty-${itemCode}`);
            if (fireInput) fireInput.value = '0';
            if (zayiInput) zayiInput.value = '0';

            updateCartTable();
            updateSaveButton();
        }

        // Sepeti render et (StokSayimSO.php mantığı)
        function updateCartTable() {
            const tbody = document.getElementById('cartTableBody');
            const lostType = document.querySelector('input[name="lostType"]:checked')?.value;
            
            if (cart.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="empty-message">Sepet boş - Ürün seçiniz</td></tr>';
                document.getElementById('cartSection').style.display = 'none';
                return;
            }

            document.getElementById('cartSection').style.display = 'block';
            tbody.innerHTML = '';

            cart.forEach((item, index) => {
                const row = document.createElement('tr');
                row.setAttribute('data-item-code', item.ItemCode);
                
                const fireQty = parseFloat(item.FireQty || 0);
                const zayiQty = parseFloat(item.ZayiQty || 0);
                const unitPrice = parseFloat(item.UnitPrice || 0);
                const quantity = lostType === '1' ? fireQty : zayiQty;
                const lineTotal = quantity * unitPrice;

                const fireDisabled = lostType !== '1' ? 'disabled' : '';
                const zayiDisabled = lostType !== '2' ? 'disabled' : '';

                row.innerHTML = `
                    <td><strong>${item.ItemCode}</strong></td>
                    <td>${item.ItemName}</td>
                    <td>${item.FromWarehouse}</td>
                    <td>${item.ToWarehouse}</td>
                    <td>${item.UoMCode}</td>
                    <td>
                        <div class="quantity-controls">
                            <button type="button" class="qty-btn" onclick="changeCartQuantity(${index}, 'fire', -1)" ${fireDisabled}>−</button>
                            <input type="number" 
                                   class="qty-input" 
                                   value="${fireQty.toFixed(2)}"
                                   min="0" 
                                   step="0.01"
                                   onchange="updateCartQuantity(${index}, 'fire', this.value)"
                                   ${fireDisabled}>
                            <button type="button" class="qty-btn" onclick="changeCartQuantity(${index}, 'fire', 1)" ${fireDisabled}>+</button>
                        </div>
                    </td>
                    <td>
                        <div class="quantity-controls">
                            <button type="button" class="qty-btn" onclick="changeCartQuantity(${index}, 'zayi', -1)" ${zayiDisabled}>−</button>
                            <input type="number" 
                                   class="qty-input" 
                                   value="${zayiQty.toFixed(2)}"
                                   min="0" 
                                   step="0.01"
                                   onchange="updateCartQuantity(${index}, 'zayi', this.value)"
                                   ${zayiDisabled}>
                            <button type="button" class="qty-btn" onclick="changeCartQuantity(${index}, 'zayi', 1)" ${zayiDisabled}>+</button>
                        </div>
                    </td>
                    <td>
                        <input type="number" 
                               class="qty-input" 
                               value="${unitPrice.toFixed(2)}"
                               min="0" 
                               step="0.01"
                               onchange="updateCartQuantity(${index}, 'UnitPrice', this.value)"
                               placeholder="0.00">
                    </td>
                    <td class="text-right"><strong>${lineTotal.toFixed(2)} ₺</strong></td>
                    <td style="text-align: center;">
                        <button class="btn btn-danger btn-small" onclick="removeFromCart(${index})">Sil</button>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }

        // Sepette miktar değiştir
        function changeCartQuantity(index, type, delta) {
            if (index >= 0 && index < cart.length) {
                if (type === 'fire') {
                    cart[index].FireQty = Math.max(0, parseFloat(cart[index].FireQty || 0) + delta);
                } else if (type === 'zayi') {
                    cart[index].ZayiQty = Math.max(0, parseFloat(cart[index].ZayiQty || 0) + delta);
                }
                updateCartTable();
            }
        }

        // Sepette miktar güncelle
        function updateCartQuantity(index, field, value) {
            if (index >= 0 && index < cart.length) {
                if (field === 'fire' || field === 'zayi') {
                    cart[index][field === 'fire' ? 'FireQty' : 'ZayiQty'] = parseFloat(value) || 0;
                } else {
                    cart[index][field] = parseFloat(value) || 0;
                }
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

        // Form submit - Fire/Zayi belgesi oluştur
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (cart.length === 0) {
                alert('Lütfen sepete en az bir ürün ekleyiniz');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('fromWarehouse', fromWarehouseSelect.value);
            formData.append('toWarehouse', toWarehouseSelect.value);
            formData.append('lostType', document.querySelector('input[name="lostType"]:checked')?.value);
            formData.append('docDate', document.getElementById('docDate').value);
            formData.append('comments', document.getElementById('comments').value);
            formData.append('lines', JSON.stringify(cart));

            saveBtn.disabled = true;
            saveBtn.textContent = 'Kaydediliyor...';

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    if (data.docEntry) {
                        window.location.href = `Fire-ZayiDetay.php?DocEntry=${data.docEntry}`;
                    } else {
                        window.location.href = 'Fire-Zayi.php';
                    }
                } else {
                    alert('Hata: ' + data.message);
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Kaydet';
                }
            })
            .catch(err => {
                console.error('Hata:', err);
                alert('Bir hata oluştu. Lütfen tekrar deneyin.');
                saveBtn.disabled = false;
                saveBtn.textContent = 'Kaydet';
            });
        });

        // Fire/Zayi türü değiştiğinde sepeti de güncelle
        lostTypeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                updateCartTable();
            });
        });
    </script>
</body>
</html>
