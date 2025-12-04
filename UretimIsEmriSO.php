<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece üretim kullanıcıları (RT veya CF) görebilsin (YE göremez)
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'RT' && $uAsOwnr !== 'CF') {
    header("Location: index.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

// Kullanıcı bilgileri
// U_ASB2B_BRAN muhtemelen Branch2["Code"] ile eşleşiyor (örn: '100')
$branchCode = $_SESSION["Branch2"]["Code"] ?? $_SESSION["WhsCode"] ?? $_SESSION["Branch2"]["Name"] ?? '';
$branch = $branchCode; // Aynı değeri kullan

// Warehouse bilgisini al (kullanıcının şubesine göre)
// Not: Depo otomatik gelir - MAIN=1 (ana depo) öncelikli, bulunamazsa MAIN=2 (sevkiyat deposu) denenir
$warehouseCode = '';
$debugInfo = [
    'uAsOwnr' => $uAsOwnr,
    'branchCode' => $branchCode,
    'branch' => $branch,
    'session_WhsCode' => $_SESSION["WhsCode"] ?? 'YOK',
    'session_Branch2' => $_SESSION["Branch2"] ?? 'YOK',
    'warehouse_main1' => [],
    'warehouse_main2' => [],
    'warehouse_all_cf' => [],
    'warehouse_all_branch200' => [],
    'warehouse_found' => false
];

// Debug: CF ile ilgili tüm warehouse'ları çek
$allCFWarehousesFilter = "U_AS_OWNR eq '{$uAsOwnr}'";
$allCFWarehousesQuery = "Warehouses?\$select=WarehouseCode,U_ASB2B_BRAN,U_ASB2B_MAIN&\$filter=" . urlencode($allCFWarehousesFilter) . "&\$top=50";
$allCFWarehousesData = $sap->get($allCFWarehousesQuery);
if (($allCFWarehousesData['status'] ?? 0) == 200) {
    $debugInfo['warehouse_all_cf'] = $allCFWarehousesData['response']['value'] ?? $allCFWarehousesData['value'] ?? [];
}

// Debug: Branch 200 ile ilgili tüm warehouse'ları çek (string ve integer olarak dene)
$allBranch200Filter1 = "U_ASB2B_BRAN eq '{$branchCode}'";
$allBranch200Query1 = "Warehouses?\$select=WarehouseCode,U_AS_OWNR,U_ASB2B_BRAN,U_ASB2B_MAIN&\$filter=" . urlencode($allBranch200Filter1) . "&\$top=50";
$allBranch200Data1 = $sap->get($allBranch200Query1);
if (($allBranch200Data1['status'] ?? 0) == 200) {
    $debugInfo['warehouse_all_branch200'] = $allBranch200Data1['response']['value'] ?? $allBranch200Data1['value'] ?? [];
}

if (!empty($branchCode)) {
    // ÇÖZÜM A: U_AS_OWNR filtresini kaldır, sadece branch ve MAIN ile filtrele
    // Çünkü branch'e göre depo bulmak yeterli, U_AS_OWNR zaten branch ile ilişkili
    $warehouseFilter = "U_ASB2B_BRAN eq '{$branchCode}' and U_ASB2B_MAIN eq '1'";
    $warehouseQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($warehouseFilter);
    $warehouseData = $sap->get($warehouseQuery);
    
    $debugInfo['warehouse_main1'] = [
        'filter' => $warehouseFilter,
        'query' => $warehouseQuery,
        'status' => $warehouseData['status'] ?? 'NO STATUS',
        'response' => $warehouseData['response'] ?? 'NO RESPONSE'
    ];
    
    if (($warehouseData['status'] ?? 0) == 200) {
        $warehouses = $warehouseData['response']['value'] ?? $warehouseData['value'] ?? [];
        $debugInfo['warehouse_main1']['warehouses'] = $warehouses;
        if (!empty($warehouses) && isset($warehouses[0]['WarehouseCode'])) {
            $warehouseCode = $warehouses[0]['WarehouseCode'];
            $debugInfo['warehouse_found'] = true;
            $debugInfo['warehouse_code'] = $warehouseCode;
        }
    }
    
    // MAIN=1 bulunamazsa MAIN=2 (sevkiyat deposu) dene
    if (empty($warehouseCode)) {
        $warehouseFilter2 = "U_ASB2B_BRAN eq '{$branchCode}' and U_ASB2B_MAIN eq '2'";
        $warehouseQuery2 = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($warehouseFilter2);
        $warehouseData2 = $sap->get($warehouseQuery2);
        
        $debugInfo['warehouse_main2'] = [
            'filter' => $warehouseFilter2,
            'query' => $warehouseQuery2,
            'status' => $warehouseData2['status'] ?? 'NO STATUS',
            'response' => $warehouseData2['response'] ?? 'NO RESPONSE'
        ];
        
        if (($warehouseData2['status'] ?? 0) == 200) {
            $warehouses2 = $warehouseData2['response']['value'] ?? $warehouseData2['value'] ?? [];
            $debugInfo['warehouse_main2']['warehouses'] = $warehouses2;
            if (!empty($warehouses2) && isset($warehouses2[0]['WarehouseCode'])) {
                $warehouseCode = $warehouses2[0]['WarehouseCode'];
                $debugInfo['warehouse_found'] = true;
                $debugInfo['warehouse_code'] = $warehouseCode;
            }
        }
    }
    
    // Eğer hala bulunamadıysa, integer olarak da dene
    if (empty($warehouseCode) && is_numeric($branchCode)) {
        $branchCodeInt = intval($branchCode);
        $warehouseFilterInt = "U_ASB2B_BRAN eq {$branchCodeInt} and U_ASB2B_MAIN eq 1";
        $warehouseQueryInt = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($warehouseFilterInt);
        $warehouseDataInt = $sap->get($warehouseQueryInt);
        
        $debugInfo['warehouse_main1_int'] = [
            'filter' => $warehouseFilterInt,
            'query' => $warehouseQueryInt,
            'status' => $warehouseDataInt['status'] ?? 'NO STATUS',
            'response' => $warehouseDataInt['response'] ?? 'NO RESPONSE'
        ];
        
        if (($warehouseDataInt['status'] ?? 0) == 200) {
            $warehousesInt = $warehouseDataInt['response']['value'] ?? $warehouseDataInt['value'] ?? [];
            $debugInfo['warehouse_main1_int']['warehouses'] = $warehousesInt;
            if (!empty($warehousesInt) && isset($warehousesInt[0]['WarehouseCode'])) {
                $warehouseCode = $warehousesInt[0]['WarehouseCode'];
                $debugInfo['warehouse_found'] = true;
                $debugInfo['warehouse_code'] = $warehouseCode;
            }
        }
    }
}

// AJAX: Items arama (Mamül/Yarı Mamül - Sadece ItemsGroupCode 100 veya 101)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'search_items') {
    header('Content-Type: application/json');
    
    $search = trim($_GET['search'] ?? '');
    
    // Debug bilgileri
    $debugInfo = [
        'search' => $search,
        'search_escaped' => '',
        'filter' => '',
        'query' => '',
        'status' => '',
        'response' => null,
        'items_count' => 0,
        'error' => null
    ];
    
    if (empty($search)) {
        echo json_encode(['data' => [], 'count' => 0, 'debug' => $debugInfo]);
        exit;
    }
    
    // Items endpoint'inden arama yap - SADECE MAMÜL VE YARI MAMÜL (ItemsGroupCode 100 veya 101)
    $searchEscaped = str_replace("'", "''", $search);
    $debugInfo['search_escaped'] = $searchEscaped;
    
    // OData filter syntax: ItemsGroupCode filtresi + arama filtresi
    // ItemsGroupCode eq 100 or ItemsGroupCode eq 101 = Sadece mamül ve yarı mamül
    $filter = "(ItemsGroupCode eq 100 or ItemsGroupCode eq 101) and (contains(ItemCode, '{$searchEscaped}') or contains(ItemName, '{$searchEscaped}'))";
    $debugInfo['filter'] = $filter;
    
    // Query string'i parça parça oluştur - Türkçe karakterler için rawurlencode kullan
    $queryParams = [
        '$select' => 'ItemCode,ItemName,InventoryUOM',
        '$filter' => $filter,
        '$top' => '20',
        '$orderby' => 'ItemName asc'
    ];
    
    // Query string'i oluştur
    $queryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
    $itemsQuery = "Items?" . $queryString;
    $debugInfo['query'] = $itemsQuery;
    
    $itemsData = $sap->get($itemsQuery);
    $items = [];
    
    $debugInfo['status'] = $itemsData['status'] ?? 'NO STATUS';
    
    if (($itemsData['status'] ?? 0) == 200) {
        $itemsList = $itemsData['response']['value'] ?? $itemsData['value'] ?? [];
        $debugInfo['items_count'] = count($itemsList);
        
        foreach ($itemsList as $item) {
            $items[] = [
                'ItemCode' => $item['ItemCode'] ?? '',
                'ItemName' => $item['ItemName'] ?? '',
                'InventoryUOM' => $item['InventoryUOM'] ?? ''
            ];
        }
    } else {
        // Hata durumunda debug bilgisi
        $debugInfo['error'] = $itemsData['response']['error'] ?? 'NO ERROR';
        error_log('[URETIMISEMRISO] Items arama hatası: Status=' . ($itemsData['status'] ?? 'NO STATUS') . ', Error=' . json_encode($itemsData['response']['error'] ?? 'NO ERROR'));
    }
    
    echo json_encode(['data' => $items, 'count' => count($items), 'debug' => $debugInfo], JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX: Reçete detaylarını getir (ASB2B_ProducTreeDetail_B1SLQuery)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'get_recipe') {
    header('Content-Type: application/json');
    
    $itemCode = trim($_GET['itemCode'] ?? '');
    if (empty($itemCode)) {
        echo json_encode(['success' => false, 'message' => 'ItemCode gerekli']);
        exit;
    }
    
    // Reçete detaylarını getir
    $recipeFilter = "Father eq '{$itemCode}'";
    $recipeQuery = "view.svc/ASB2B_ProducTreeDetail_B1SLQuery?\$filter=" . urlencode($recipeFilter);
    $recipeData = $sap->get($recipeQuery);
    
    $materials = [];
    $debugInfo = [
        'itemCode' => $itemCode,
        'recipeQuery' => $recipeQuery,
        'recipeStatus' => $recipeData['status'] ?? 'NO STATUS',
        'recipeResponseKeys' => [],
        'recipeListCount' => 0,
        'recipeListSample' => null,
        'materialsDebug' => []
    ];
    
    if (($recipeData['status'] ?? 0) == 200) {
        $recipeResponse = $recipeData['response'] ?? $recipeData;
        $recipeList = $recipeResponse['value'] ?? $recipeResponse ?? [];
        
        // Eğer value yoksa ama response indexed array ise
        if (empty($recipeList) && is_array($recipeResponse) && isset($recipeResponse[0])) {
            $recipeList = $recipeResponse;
        }
        
        $debugInfo['recipeResponseKeys'] = is_array($recipeResponse) ? array_keys($recipeResponse) : [];
        $debugInfo['recipeListCount'] = is_array($recipeList) ? count($recipeList) : 0;
        $debugInfo['recipeListSample'] = is_array($recipeList) && isset($recipeList[0]) ? $recipeList[0] : null;
        
        foreach ($recipeList as $idx => $line) {
            // BaseQty: 1 birim mamül için gerekli malzeme miktarı
            $baseQty = floatval($line['BaseQty'] ?? $line['Quantity'] ?? 0);
            
            $materialDebug = [
                'index' => $idx,
                'ItemCode' => $line['ItemCode'] ?? $line['Code'] ?? 'N/A',
                'ItemName' => $line['ItemName'] ?? 'N/A',
                'BaseQty_raw' => $line['BaseQty'] ?? 'N/A',
                'Quantity_raw' => $line['Quantity'] ?? 'N/A',
                'BaseQty_parsed' => $baseQty,
                'InvntryUom' => $line['InvntryUom'] ?? 'N/A',
                'ALL_KEYS' => is_array($line) ? array_keys($line) : []
            ];
            $debugInfo['materialsDebug'][] = $materialDebug;
            
            $materials[] = [
                'ItemCode' => $line['ItemCode'] ?? $line['Code'] ?? '',
                'ItemName' => $line['ItemName'] ?? '',
                'BaseQty' => $baseQty, // 1 birim için malzeme miktarı
                'InvntryUom' => $line['InvntryUom'] ?? ''
            ];
        }
    } else {
        $debugInfo['recipeError'] = $recipeData['response']['error'] ?? $recipeData['error'] ?? 'NO ERROR';
    }
    
    echo json_encode(['success' => true, 'materials' => $materials, 'debug' => $debugInfo], JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX: ProductionOrder oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_production_order') {
    header('Content-Type: application/json');
    
    $itemNo = trim($_POST['itemNo'] ?? '');
    $plannedQuantity = floatval($_POST['plannedQuantity'] ?? 0);
    $warehouse = trim($_POST['warehouse'] ?? '');
    $materials = json_decode($_POST['materials'] ?? '[]', true); // Kullanıcının değiştirdiği malzeme listesi
    
    if (empty($itemNo) || $plannedQuantity <= 0 || empty($warehouse)) {
        echo json_encode(['success' => false, 'message' => 'Eksik bilgiler: ItemNo, PlannedQuantity ve Warehouse gerekli']);
        exit;
    }
    
    // 1. Adım: ProductionOrder'ı oluştur (ProductionOrderLines olmadan)
    $payload = [
        'ItemNo' => $itemNo,
        'ProductionOrderStatus' => 'boposPlanned', // SAP: Yeni order'lar sadece "Planlandı" durumunda oluşturulabilir
        'PlannedQuantity' => $plannedQuantity,
        'Warehouse' => $warehouse
    ];
    
    $result = $sap->post('ProductionOrders', $payload);
    
    if (($result['status'] ?? 0) == 200 || ($result['status'] ?? 0) == 201) {
        $responseData = $result['response'] ?? [];
        $absoluteEntry = $responseData['AbsoluteEntry'] ?? '';
        
        // 2. Adım: ProductionOrderLines'ı güncelle (kullanıcının değiştirdiği BaseQty değerleriyle)
        // Hem BaseQuantity hem PlannedQuantity güncellenmeli
        // PlannedQuantity = BaseQuantity × Header PlannedQuantity
        if (!empty($materials) && is_array($materials) && !empty($absoluteEntry)) {
            // Önce mevcut ProductionOrderLines'ı çek
            $linesQuery = "ProductionOrders({$absoluteEntry})/ProductionOrderLines";
            $linesData = $sap->get($linesQuery);
            
            if (($linesData['status'] ?? 0) == 200) {
                // Response yapısını kontrol et - farklı formatlar olabilir
                $responseLines = $linesData['response'] ?? $linesData;
                
                // Önce value array'ini kontrol et (OData standard format)
                if (isset($responseLines['value']) && is_array($responseLines['value']) && !empty($responseLines['value'])) {
                    $existingLines = $responseLines['value'];
                }
                // Eğer value yoksa ama ProductionOrderLines key'i varsa (detay sayfasındaki gibi)
                elseif (isset($responseLines['ProductionOrderLines']) && is_array($responseLines['ProductionOrderLines']) && !empty($responseLines['ProductionOrderLines'])) {
                    $existingLines = $responseLines['ProductionOrderLines'];
                }
                // Eğer value boşsa ama response indexed array ise
                elseif (is_array($responseLines) && isset($responseLines[0]) && is_array($responseLines[0])) {
                    $existingLines = $responseLines;
                }
                else {
                    $existingLines = [];
                }
                
                error_log("[URETIMISEMRISO] Response yapısı kontrolü:");
                error_log("[URETIMISEMRISO]   - has_value: " . (isset($responseLines['value']) ? 'YES' : 'NO'));
                error_log("[URETIMISEMRISO]   - has_ProductionOrderLines: " . (isset($responseLines['ProductionOrderLines']) ? 'YES' : 'NO'));
                error_log("[URETIMISEMRISO]   - is_indexed_array: " . (is_array($responseLines) && isset($responseLines[0]) ? 'YES' : 'NO'));
                error_log("[URETIMISEMRISO]   - existingLines count: " . count($existingLines));
                
                // Header'daki planlanan mamul miktarı
                $headerPlannedQuantity = $plannedQuantity;
                
                // Debug: Mevcut satırları log'la
                error_log("[URETIMISEMRISO] Mevcut ProductionOrderLines sayısı: " . count($existingLines));
                foreach ($existingLines as $idx => $line) {
                    error_log("[URETIMISEMRISO] Line {$idx}: ItemNo=" . ($line['ItemNo'] ?? 'N/A') . ", LineNumber=" . ($line['LineNumber'] ?? 'N/A') . ", LineNum=" . ($line['LineNum'] ?? 'N/A') . ", BaseQuantity=" . ($line['BaseQuantity'] ?? 'N/A') . ", PlannedQuantity=" . ($line['PlannedQuantity'] ?? 'N/A'));
                }
                
                // Her malzeme için ProductionOrderLine'ı güncelle
                foreach ($materials as $material) {
                    $itemCode = trim($material['itemCode'] ?? '');
                    $baseQty = floatval($material['baseQty'] ?? 0);
                    
                    if (empty($itemCode) || $baseQty <= 0) {
                        continue;
                    }
                    
                    // Mevcut satırlarda bu ItemCode'u bul
                    $found = false;
                    foreach ($existingLines as $line) {
                        $lineItemCode = $line['ItemNo'] ?? $line['ItemCode'] ?? '';
                        // Debug'dan görüldüğü gibi: LineNumber kullanılıyor (LineNum değil!)
                        $lineNum = $line['LineNumber'] ?? $line['LineNum'] ?? null;
                        
                        if ($lineItemCode === $itemCode && $lineNum !== null) {
                            // ProductionOrderLine'ı PATCH ile güncelle
                            // Toplam satır miktarı = BaseQty × İş Emri Planlanan Miktarı
                            $lineUpdatePayload = [
                                'BaseQuantity' => $baseQty, // 1 birim için
                                'PlannedQuantity' => $baseQty * $headerPlannedQuantity // toplam miktar
                            ];
                            
                            // PATCH URL: LineNumber kullan
                            // SAP B1SL'de LineNumber 0-indexed olabilir, ama PATCH için direkt LineNumber değerini kullan
                            $patchUrl = "ProductionOrders({$absoluteEntry})/ProductionOrderLines({$lineNum})";
                            error_log("[URETIMISEMRISO] 🔄 PATCH İşlemi Başlıyor:");
                            error_log("[URETIMISEMRISO]   - ItemCode: {$itemCode}");
                            error_log("[URETIMISEMRISO]   - LineNumber: {$lineNum}");
                            error_log("[URETIMISEMRISO]   - Mevcut BaseQuantity: " . ($line['BaseQuantity'] ?? 'N/A'));
                            error_log("[URETIMISEMRISO]   - Yeni BaseQuantity: {$baseQty}");
                            error_log("[URETIMISEMRISO]   - Mevcut PlannedQuantity: " . ($line['PlannedQuantity'] ?? 'N/A'));
                            error_log("[URETIMISEMRISO]   - Yeni PlannedQuantity: " . ($baseQty * $headerPlannedQuantity));
                            error_log("[URETIMISEMRISO]   - PATCH URL: {$patchUrl}");
                            error_log("[URETIMISEMRISO]   - Payload: " . json_encode($lineUpdatePayload));
                            
                            $lineUpdateResult = $sap->patch($patchUrl, $lineUpdatePayload);
                            
                            error_log("[URETIMISEMRISO]   - PATCH Response Status: " . ($lineUpdateResult['status'] ?? 'NO STATUS'));
                            if (isset($lineUpdateResult['response'])) {
                                error_log("[URETIMISEMRISO]   - PATCH Response: " . json_encode($lineUpdateResult['response']));
                            }
                            
                            // Hata olsa bile devam et (log'la)
                            if (($lineUpdateResult['status'] ?? 0) != 200 && ($lineUpdateResult['status'] ?? 0) != 204) {
                                $errorMsg = json_encode($lineUpdateResult['response']['error'] ?? $lineUpdateResult['error'] ?? 'NO ERROR');
                                error_log("[URETIMISEMRISO] ❌ ProductionOrderLine güncelleme hatası (LineNumber: {$lineNum}, ItemCode: {$itemCode}): Status=" . ($lineUpdateResult['status'] ?? 'NO STATUS') . ", Error=" . $errorMsg);
                                error_log("[URETIMISEMRISO] Full Error Response: " . json_encode($lineUpdateResult));
                            } else {
                                error_log("[URETIMISEMRISO] ✅ ProductionOrderLine başarıyla güncellendi (LineNumber: {$lineNum}, ItemCode: {$itemCode}, BaseQty: {$baseQty}, PlannedQty: " . ($baseQty * $headerPlannedQuantity) . ")");
                                
                                // PATCH sonrası doğrulama: Güncellenmiş değeri tekrar çek
                                $verifyQuery = "ProductionOrders({$absoluteEntry})/ProductionOrderLines({$lineNum})?\$select=BaseQuantity,PlannedQuantity";
                                $verifyData = $sap->get($verifyQuery);
                                if (($verifyData['status'] ?? 0) == 200) {
                                    $verifyLine = $verifyData['response'] ?? $verifyData;
                                    $verifiedBaseQty = $verifyLine['BaseQuantity'] ?? 'N/A';
                                    $verifiedPlannedQty = $verifyLine['PlannedQuantity'] ?? 'N/A';
                                    error_log("[URETIMISEMRISO] 🔍 Doğrulama - Güncellenmiş BaseQuantity: {$verifiedBaseQty}, PlannedQuantity: {$verifiedPlannedQty}");
                                    
                                    if (abs(floatval($verifiedBaseQty) - $baseQty) > 0.01) {
                                        error_log("[URETIMISEMRISO] ⚠️ UYARI: BaseQuantity güncellenmedi! Beklenen: {$baseQty}, Gelen: {$verifiedBaseQty}");
                                    }
                                }
                            }
                            $found = true;
                            break; // Bulundu, diğer satırlara bakma
                        }
                    }
                    
                    if (!$found) {
                        error_log("[URETIMISEMRISO] ❌ ItemCode bulunamadı: {$itemCode}. Mevcut ItemCode'lar: " . implode(', ', array_map(function($l) { return $l['ItemNo'] ?? $l['ItemCode'] ?? 'N/A'; }, $existingLines)));
                    }
                }
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'İş emri başarıyla oluşturuldu!',
            'absoluteEntry' => $absoluteEntry
        ]);
    } else {
        $errorMsg = 'İş emri oluşturulamadı: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        echo json_encode(['success' => false, 'message' => $errorMsg]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Üretim İş Emri - MINOA</title>
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
    text-decoration: none;
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

.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #f0f9ff;
}

.btn-danger {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fecaca;
    padding: 6px 12px;
    font-size: 12px;
}

.btn-danger:hover {
    background: #fecaca;
}

.btn-edit {
    background: #dbeafe;
    color: #1e40af;
    border: 1px solid #bfdbfe;
    padding: 6px 12px;
    font-size: 12px;
    margin-right: 4px;
}

.btn-edit:hover {
    background: #bfdbfe;
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
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group input[type="text"],
.form-group input[type="number"],
.form-group input[type="date"],
.form-group input[type="time"] {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: white;
    width: 100%;
    min-height: 42px;
    box-sizing: border-box;
}

.form-group input[type="text"]:focus,
.form-group input[type="number"]:focus,
.form-group input[type="date"]:focus,
.form-group input[type="time"]:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group input[readonly] {
    background: #f3f4f6;
    cursor: not-allowed;
}

.form-group label {
    font-size: 13px;
    color: #4b5563;
    margin-bottom: 4px;
    font-weight: 500;
}

.materials-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.materials-header h3 {
    color: #1e40af;
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
}

.materials-info {
    color: #6b7280;
    font-size: 13px;
    margin-bottom: 20px;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

thead {
    background: #f8fafc;
}

thead th {
    padding: 12px 14px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    white-space: nowrap;
}

tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.15s ease;
}

tbody tr:hover {
    background: #f9fafb;
}

tbody td {
    padding: 10px 14px;
    color: #4b5563;
}

tbody td input[type="number"],
tbody td input[type="text"] {
    padding: 6px 10px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 13px;
    width: 100%;
    min-width: 80px;
}

tbody td input[type="number"]:focus,
tbody td input[type="text"]:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #9ca3af;
    font-size: 14px;
}

/* Mamül Arama Alanı */
.product-search-section {
    padding: 20px;
    background: #f8fafc;
    border-radius: 8px;
    margin-bottom: 24px;
}

.product-search-form {
    display: flex;
    gap: 12px;
    align-items: flex-end;
}

.search-box {
    display: flex;
    flex-direction: column;
    flex: 1;
    position: relative;
}

.search-input {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    width: 100%;
    transition: border-color 0.2s;
    min-height: 42px;
}

.search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    max-height: 300px;
    overflow-y: auto;
    z-index: 1000;
    margin-top: 4px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    display: none;
}

.search-results.show {
    display: block;
}

.search-result-item {
    padding: 12px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.15s;
}

.search-result-item:hover {
    background: #f8fafc;
}

.search-result-item:last-child {
    border-bottom: none;
}

.search-result-code {
    font-weight: 600;
    color: #1e40af;
    font-size: 13px;
}

.search-result-name {
    color: #6b7280;
    font-size: 12px;
    margin-top: 2px;
}

.unit-display {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    background: #f3f4f6;
    color: #6b7280;
    min-width: 120px;
    min-height: 42px;
    display: flex;
    align-items: center;
    font-weight: 500;
}

.quantity-input-group {
    display: flex;
    gap: 8px;
    align-items: center;
}

.quantity-input {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    width: 150px;
    min-height: 42px;
}

.quantity-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

@media (max-width: 1200px) {
    .form-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header {
        padding: 16px 1rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        height: auto;
    }

    .content-wrapper {
        padding: 16px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .product-search-form {
        flex-direction: column;
        align-items: stretch;
    }

    .search-box {
        width: 100%;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h2>Yeni Üretim İş Emri</h2>
            <div style="display: flex; gap: 12px; align-items: center;">
                <button class="btn btn-secondary" onclick="window.location.href='UretimIsEmirleri.php'">← Geri Dön</button>
                <button type="button" class="btn btn-primary" id="saveBtn" onclick="saveProductionOrder()">Kaydet</button>
            </div>
        </div>

        <div class="content-wrapper">
            <!-- İş Emri Bilgileri -->
            <div class="card">
                <div class="card-header">
                    <h3>İş Emri Bilgileri</h3>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>İş Emri Numarası</label>
                            <input type="text" id="isEmriNo" value="" readonly placeholder="Otomatik oluşturulacak">
                        </div>
                        <div class="form-group">
                            <label>Planlanan Tarih</label>
                            <input type="date" id="planlananTarih" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mamül Arama -->
            <div class="card">
                <div class="card-body">
                    <div class="product-search-section">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Mamül / Yarı Mamül Arama</label>
                        <div class="product-search-form">
                            <div class="search-box">
                                <input type="text" id="productSearch" class="search-input" placeholder="Mamül kodu veya adı yazın..." autocomplete="off">
                                <div id="searchResults" class="search-results"></div>
                            </div>
                            <div class="quantity-input-group">
                                <label style="font-size: 12px; color: #6b7280; margin-bottom: 4px; display: block;">Üretim Miktarı</label>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input type="number" id="productQuantity" class="quantity-input" min="0" step="0.01" placeholder="Miktar" required>
                                    <div class="unit-display" id="productUnit" style="min-width: 80px; text-align: center;">-</div>
                                </div>
                                <small style="font-size: 11px; color: #9ca3af; margin-top: 4px; display: block;">Mamülün birimi ile üretilecek miktar</small>
                            </div>
                            <button type="button" class="btn btn-primary" onclick="loadRecipe()">Reçeteyi Yükle</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- İş Emri Kalemleri (Reçete Çözümü) -->
            <div class="card">
                <div class="card-header">
                    <div class="materials-header">
                        <h3>İş Emri Kalemleri (Reçete Çözümü)</h3>
                    </div>
                </div>
                <div class="card-body">
                    <p class="materials-info">
                        Planlanan miktara göre kullanılacak malzemeler. Miktarları isterseniz satır bazında güncelleyebilirsiniz.
                    </p>

                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Sıra</th>
                                    <th>Malzeme Kodu</th>
                                    <th>Malzeme Adı</th>
                                    <th>Miktar</th>
                                    <th>Planlanan Miktar (Toplam)</th>
                                    <th>Birim</th>
                                    <th>Depo</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="materialsTableBody">
                                <tr class="empty-state-row">
                                    <td colspan="8" class="empty-state">
                                        Lütfen üstten ürün seçip planlanan miktarı girin
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Arama Debug Panel -->
            <div class="card" style="margin-top: 24px; background: #f8fafc; border: 2px solid #e5e7eb;">
                <div class="card-header">
                    <h3 style="color: #dc2626;">🔍 Arama Debug Bilgileri</h3>
                </div>
                <div class="card-body" id="searchDebugPanel">
                    <p style="color: #9ca3af; text-align: center; padding: 20px;">Arama yapıldığında debug bilgileri burada görünecek</p>
                </div>
            </div>

            <!-- Reçete Debug Panel -->
            <div class="card" style="margin-top: 24px; background: #fef3c7; border: 2px solid #fbbf24;">
                <div class="card-header">
                    <h3 style="color: #92400e;">🔍 Reçete Debug Bilgileri (Miktar Bilgileri)</h3>
                </div>
                <div class="card-body" id="recipeDebugPanel">
                    <p style="color: #9ca3af; text-align: center; padding: 20px;">Reçete yüklendiğinde miktar bilgileri burada görünecek</p>
                </div>
            </div>

            <!-- Debug Panel -->
            <div class="card" style="margin-top: 24px; background: #f8fafc; border: 2px solid #e5e7eb;">
                <div class="card-header">
                    <h3 style="color: #dc2626;">🔍 Debug Bilgileri</h3>
                </div>
                <div class="card-body">
                    <div style="background: white; padding: 16px; border-radius: 8px; font-family: monospace; font-size: 12px; overflow-x: auto;">
                        <h4 style="color: #1e40af; margin-bottom: 12px;">Session Bilgileri:</h4>
                        <pre style="background: #f3f4f6; padding: 12px; border-radius: 6px; margin-bottom: 16px;"><?= htmlspecialchars(print_r([
                            'U_AS_OWNR' => $uAsOwnr,
                            'BranchCode (kullanılan)' => $branchCode,
                            'Branch (kullanılan)' => $branch,
                            'WhsCode (session)' => $_SESSION["WhsCode"] ?? 'YOK',
                            'Branch2 (session)' => $_SESSION["Branch2"] ?? 'YOK'
                        ], true)) ?></pre>

                        <h4 style="color: #1e40af; margin-bottom: 12px;">Warehouse Sorguları:</h4>
                        <div style="margin-bottom: 16px;">
                            <strong style="color: #dc2626;">MAIN=1 (Ana Depo - String):</strong>
                            <pre style="background: #f3f4f6; padding: 12px; border-radius: 6px; margin-top: 8px; max-height: 200px; overflow-y: auto;"><?= htmlspecialchars(print_r($debugInfo['warehouse_main1'], true)) ?></pre>
                        </div>
                        <?php if (isset($debugInfo['warehouse_main1_int'])): ?>
                        <div style="margin-bottom: 16px;">
                            <strong style="color: #dc2626;">MAIN=1 (Ana Depo - Integer):</strong>
                            <pre style="background: #f3f4f6; padding: 12px; border-radius: 6px; margin-top: 8px; max-height: 200px; overflow-y: auto;"><?= htmlspecialchars(print_r($debugInfo['warehouse_main1_int'], true)) ?></pre>
                        </div>
                        <?php endif; ?>
                        <div style="margin-bottom: 16px;">
                            <strong style="color: #dc2626;">MAIN=2 (Sevkiyat Deposu):</strong>
                            <pre style="background: #f3f4f6; padding: 12px; border-radius: 6px; margin-top: 8px; max-height: 200px; overflow-y: auto;"><?= htmlspecialchars(print_r($debugInfo['warehouse_main2'], true)) ?></pre>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <strong style="color: #dc2626;">Tüm CF Warehouse'ları (U_AS_OWNR='CF'):</strong>
                            <pre style="background: #f3f4f6; padding: 12px; border-radius: 6px; margin-top: 8px; max-height: 200px; overflow-y: auto;"><?= htmlspecialchars(print_r($debugInfo['warehouse_all_cf'], true)) ?></pre>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <strong style="color: #dc2626;">Tüm Branch 200 Warehouse'ları (U_ASB2B_BRAN='200'):</strong>
                            <pre style="background: #f3f4f6; padding: 12px; border-radius: 6px; margin-top: 8px; max-height: 200px; overflow-y: auto;"><?= htmlspecialchars(print_r($debugInfo['warehouse_all_branch200'], true)) ?></pre>
                        </div>

                        <h4 style="color: #1e40af; margin-bottom: 12px;">Sonuç:</h4>
                        <div style="background: <?= $debugInfo['warehouse_found'] ? '#dcfce7' : '#fee2e2' ?>; padding: 12px; border-radius: 6px;">
                            <strong>Warehouse Bulundu:</strong> <?= $debugInfo['warehouse_found'] ? '✅ EVET' : '❌ HAYIR' ?><br>
                            <strong>Warehouse Code:</strong> <?= htmlspecialchars($warehouseCode ?: 'BULUNAMADI') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let selectedProduct = null;
        let recipeMaterials = [];
        let warehouseCode = '<?= htmlspecialchars($warehouseCode) ?>';

        // Mamül arama
        const productSearch = document.getElementById('productSearch');
        const searchResults = document.getElementById('searchResults');
        let searchTimeout = null;

        productSearch.addEventListener('input', function() {
            const searchTerm = this.value.trim();
            
            clearTimeout(searchTimeout);
            
            if (searchTerm.length < 2) {
                searchResults.classList.remove('show');
                return;
            }
            
            searchTimeout = setTimeout(() => {
                searchProducts(searchTerm);
            }, 300);
        });

        // Dışarı tıklandığında sonuçları kapat
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-box')) {
                searchResults.classList.remove('show');
            }
        });

        function searchProducts(searchTerm) {
            fetch(`?ajax=search_items&search=${encodeURIComponent(searchTerm)}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    // Debug bilgilerini göster
                    if (data.debug) {
                        updateSearchDebug(data.debug);
                    }
                    displaySearchResults(data.data || []);
                })
                .catch(error => {
                    console.error('Arama hatası:', error);
                    searchResults.innerHTML = '<div class="search-result-item" style="color: #ef4444; text-align: center; padding: 20px;">Arama sırasında bir hata oluştu</div>';
                    searchResults.classList.add('show');
                });
        }

        function updateSearchDebug(debug) {
            const debugPanel = document.getElementById('searchDebugPanel');
            if (debugPanel) {
                debugPanel.innerHTML = `
                    <h4 style="color: #1e40af; margin-bottom: 12px;">🔍 Arama Debug Bilgileri:</h4>
                    <div style="background: white; padding: 16px; border-radius: 8px; font-family: monospace; font-size: 12px; overflow-x: auto;">
                        <div style="margin-bottom: 8px;"><strong>Search:</strong> ${debug.search || 'N/A'}</div>
                        <div style="margin-bottom: 8px;"><strong>Search Escaped:</strong> ${debug.search_escaped || 'N/A'}</div>
                        <div style="margin-bottom: 8px;"><strong>Filter:</strong> <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; word-break: break-all;">${debug.filter || 'N/A'}</pre></div>
                        <div style="margin-bottom: 8px;"><strong>Query:</strong> <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; word-break: break-all;">${debug.query || 'N/A'}</pre></div>
                        <div style="margin-bottom: 8px;"><strong>Status:</strong> <span style="color: ${debug.status == 200 ? '#16a34a' : '#ef4444'}">${debug.status || 'N/A'}</span></div>
                        <div style="margin-bottom: 8px;"><strong>Items Count:</strong> ${debug.items_count || 0}</div>
                        ${debug.error ? `<div style="margin-bottom: 8px; color: #ef4444;"><strong>Error:</strong> <pre style="background: #fee2e2; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap;">${JSON.stringify(debug.error, null, 2)}</pre></div>` : ''}
                        ${debug.response ? `<div style="margin-bottom: 8px;"><strong>Response Sample:</strong> <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; max-height: 200px; overflow-y: auto;">${JSON.stringify(debug.response, null, 2).substring(0, 500)}</pre></div>` : ''}
                    </div>
                `;
            }
        }

        function displaySearchResults(items) {
            searchResults.innerHTML = '';
            
            if (items.length === 0) {
                searchResults.innerHTML = '<div class="search-result-item" style="color: #9ca3af; text-align: center; padding: 20px;">Sonuç bulunamadı</div>';
                searchResults.classList.add('show');
                return;
            }
            
            items.forEach(item => {
                const div = document.createElement('div');
                div.className = 'search-result-item';
                div.innerHTML = `
                    <div class="search-result-code">${item.ItemCode}</div>
                    <div class="search-result-name">${item.ItemName}</div>
                `;
                div.addEventListener('click', () => {
                    selectProduct(item);
                });
                searchResults.appendChild(div);
            });
            
            searchResults.classList.add('show');
        }

        function selectProduct(product) {
            selectedProduct = product;
            productSearch.value = `${product.ItemCode} - ${product.ItemName}`;
            document.getElementById('productUnit').textContent = product.InventoryUOM || '-';
            searchResults.classList.remove('show');
        }

        // Reçeteyi yükle
        function loadRecipe() {
            if (!selectedProduct) {
                alert('Lütfen önce bir mamül seçin.');
                return;
            }
            
            const quantity = parseFloat(document.getElementById('productQuantity').value);
            if (!quantity || quantity <= 0) {
                alert('Lütfen geçerli bir miktar girin.');
                return;
            }
            
            fetch(`?ajax=get_recipe&itemCode=${encodeURIComponent(selectedProduct.ItemCode)}`)
                .then(response => response.json())
                .then(data => {
                    // Debug bilgilerini sayfada göster
                    const recipeDebugPanel = document.getElementById('recipeDebugPanel');
                    if (data.debug && recipeDebugPanel) {
                        const debug = data.debug;
                        recipeDebugPanel.innerHTML = `
                            <div style="background: white; padding: 16px; border-radius: 8px; font-family: monospace; font-size: 12px; overflow-x: auto;">
                                <h4 style="color: #92400e; margin-bottom: 12px;">🔍 Reçete Debug Bilgileri:</h4>
                                <div style="margin-bottom: 8px;"><strong>ItemCode:</strong> ${debug.itemCode || 'N/A'}</div>
                                <div style="margin-bottom: 8px;"><strong>Recipe Query:</strong> <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; word-break: break-all;">${debug.recipeQuery || 'N/A'}</pre></div>
                                <div style="margin-bottom: 8px;"><strong>Recipe Status:</strong> <span style="color: ${debug.recipeStatus == 200 ? '#16a34a' : '#ef4444'}">${debug.recipeStatus || 'N/A'}</span></div>
                                <div style="margin-bottom: 8px;"><strong>Recipe List Count:</strong> ${debug.recipeListCount || 0}</div>
                                ${debug.recipeListSample ? `
                                <div style="margin-bottom: 8px;"><strong>Recipe List Sample (İlk Satır - Tüm Alanlar):</strong>
                                    <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; max-height: 200px; overflow-y: auto;">${JSON.stringify(debug.recipeListSample, null, 2)}</pre>
                                </div>
                                ` : ''}
                                <div style="margin-bottom: 8px;"><strong>Materials Debug (Her Malzeme İçin - BaseQty Nereden Geldi?):</strong>
                                    <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; max-height: 400px; overflow-y: auto;">${JSON.stringify(debug.materialsDebug || [], null, 2)}</pre>
                                </div>
                                <div style="margin-bottom: 8px;"><strong>Materials (İşlenmiş - Frontend'e Gönderilen):</strong>
                                    <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; max-height: 300px; overflow-y: auto;">${JSON.stringify(data.materials || [], null, 2)}</pre>
                                </div>
                            </div>
                        `;
                    } else if (recipeDebugPanel) {
                        recipeDebugPanel.innerHTML = '<p style="color: #ef4444; text-align: center; padding: 20px;">Debug bilgisi bulunamadı</p>';
                    }
                    
                    // Console'da da göster
                    if (data.debug) {
                        console.log('🔍 Reçete Debug Bilgileri:', data.debug);
                        console.log('📦 Materials:', data.materials);
                    }
                    
                    if (data.success && data.materials) {
                        recipeMaterials = data.materials;
                        renderMaterialsTable(quantity);
                    } else {
                        alert('Reçete bulunamadı veya bu ürün için reçete tanımlı değil.');
                    }
                })
                .catch(error => {
                    console.error('Reçete yükleme hatası:', error);
                    alert('Reçete yüklenirken bir hata oluştu.');
                });
        }

        function renderMaterialsTable(toplamMiktar) {
            const tbody = document.getElementById('materialsTableBody');
            tbody.innerHTML = '';
            
            if (recipeMaterials.length === 0) {
                tbody.innerHTML = '<tr class="empty-state-row"><td colspan="8" class="empty-state">Reçete bulunamadı</td></tr>';
                return;
            }
            
            recipeMaterials.forEach((material, index) => {
                // BaseQty: 1 birim mamül için gerekli malzeme miktarı (kullanıcı bunu değiştirebilir)
                const baseQty = material.BaseQty || material.Quantity || 0;
                // Planlanan Miktar (Toplam) = Miktar (BaseQty) × Üretim Miktarı (kullanıcının girdiği miktar)
                const planlananMiktar = baseQty * toplamMiktar;
                
                // Debug: Her malzeme için değerleri log'la
                const materialDebugInfo = {
                    ItemCode: material.ItemCode,
                    ItemName: material.ItemName,
                    BaseQty_from_API: material.BaseQty,
                    BaseQty_used: baseQty,
                    toplamMiktar: toplamMiktar,
                    planlananMiktar: planlananMiktar
                };
                console.log(`📊 Material ${index + 1}:`, materialDebugInfo);
                
                // Debug panelini güncelle
                const recipeDebugPanel = document.getElementById('recipeDebugPanel');
                if (recipeDebugPanel && index === 0) {
                    // İlk malzeme için debug panelini güncelle
                    const existingContent = recipeDebugPanel.innerHTML;
                    if (existingContent && !existingContent.includes('Frontend Material Debug')) {
                        recipeDebugPanel.innerHTML += `
                            <div style="background: white; padding: 16px; border-radius: 8px; font-family: monospace; font-size: 12px; margin-top: 16px; border-top: 2px solid #fbbf24;">
                                <h4 style="color: #92400e; margin-bottom: 12px;">📊 Frontend Material Debug (İlk Malzeme):</h4>
                                <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; white-space: pre-wrap;">${JSON.stringify(materialDebugInfo, null, 2)}</pre>
                            </div>
                        `;
                    }
                }
                
                const tr = document.createElement('tr');
                tr.dataset.itemCode = material.ItemCode;
                tr.dataset.index = index;
                tr.innerHTML = `
                    <td>${index + 1}</td>
                    <td>${material.ItemCode}</td>
                    <td>${material.ItemName}</td>
                    <td>
                        <input type="number" 
                               class="base-qty-input" 
                               value="${baseQty.toFixed(2)}" 
                               min="0" 
                               step="0.01"
                               data-index="${index}"
                               style="width: 100px; padding: 6px 10px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 13px;">
                    </td>
                    <td>
                        <input type="number" 
                               class="planlanan-miktar-input" 
                               value="${planlananMiktar.toFixed(2)}" 
                               min="0" 
                               step="0.01"
                               readonly
                               data-index="${index}"
                               style="width: 120px; background: #f3f4f6; cursor: not-allowed; padding: 6px 10px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 13px;">
                    </td>
                    <td>${material.InvntryUom || '-'}</td>
                    <td>${warehouseCode || '-'}</td>
                    <td>
                        <button type="button" class="btn btn-edit" onclick="editMaterial(${index})">✏️ Düzenle</button>
                        <button type="button" class="btn btn-danger" onclick="removeMaterial(${index})">🗑️ Sil</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
            
            // Miktar (BaseQty) değiştiğinde Planlanan Miktar'ı güncelle
            tbody.querySelectorAll('.base-qty-input').forEach(input => {
                input.addEventListener('input', function() {
                    const rowIndex = parseInt(this.dataset.index);
                    const baseQty = parseFloat(this.value) || 0;
                    const productQuantity = parseFloat(document.getElementById('productQuantity').value) || 0;
                    const planlananMiktar = baseQty * productQuantity;
                    
                    const row = this.closest('tr');
                    const planlananMiktarInput = row.querySelector('.planlanan-miktar-input');
                    if (planlananMiktarInput) {
                        planlananMiktarInput.value = planlananMiktar.toFixed(2);
                    }
                });
            });
        }

        // Miktar değiştiğinde tabloyu güncelle (productQuantity input'u değiştiğinde)
        document.getElementById('productQuantity').addEventListener('input', function() {
            const quantity = parseFloat(this.value) || 0;
            if (quantity > 0 && recipeMaterials.length > 0) {
                updateMaterialQuantities(quantity);
            }
        });

        function updateMaterialQuantities(toplamMiktar) {
            const rows = document.querySelectorAll('#materialsTableBody tr');
            rows.forEach(row => {
                // Miktar (BaseQty) input'unu al - kullanıcı bunu değiştirebilir
                const baseQtyInput = row.querySelector('.base-qty-input');
                if (baseQtyInput) {
                    const baseQty = parseFloat(baseQtyInput.value) || 0;
                    // Planlanan Miktar (Toplam) = Miktar (BaseQty) × Üretim Miktarı
                    const planlananMiktar = baseQty * toplamMiktar;
                    const planlananMiktarInput = row.querySelector('.planlanan-miktar-input');
                    if (planlananMiktarInput) {
                        planlananMiktarInput.value = planlananMiktar.toFixed(2);
                    }
                }
            });
        }

        function editMaterial(index) {
            alert('Düzenleme özelliği yakında eklenecek.');
        }

        function removeMaterial(index) {
            if (confirm('Bu malzemeyi silmek istediğinizden emin misiniz?')) {
                const rows = document.querySelectorAll('#materialsTableBody tr');
                if (rows[index]) {
                    rows[index].remove();
                    recipeMaterials.splice(index, 1);
                    updateRowNumbers();
                    
                    if (recipeMaterials.length === 0) {
                        const tbody = document.getElementById('materialsTableBody');
                        tbody.innerHTML = '<tr class="empty-state-row"><td colspan="7" class="empty-state">Lütfen üstten ürün seçip planlanan miktarı girin</td></tr>';
                    }
                }
            }
        }

        function updateRowNumbers() {
            const rows = document.querySelectorAll('#materialsTableBody tr:not(.empty-state-row)');
            rows.forEach((row, index) => {
                row.querySelector('td:first-child').textContent = index + 1;
                const editBtn = row.querySelector('button.btn-edit');
                const deleteBtn = row.querySelector('button.btn-danger');
                if (editBtn) editBtn.setAttribute('onclick', `editMaterial(${index})`);
                if (deleteBtn) deleteBtn.setAttribute('onclick', `removeMaterial(${index})`);
            });
        }

        // İş emrini kaydet
        function saveProductionOrder() {
            if (!selectedProduct) {
                alert('Lütfen önce bir mamül seçin.');
                return;
            }
            
            const quantity = parseFloat(document.getElementById('productQuantity').value);
            if (!quantity || quantity <= 0) {
                alert('Lütfen geçerli bir miktar girin.');
                return;
            }
            
            const toplamMiktar = quantity; // Miktar productQuantity'den alınıyor
            
            if (recipeMaterials.length === 0) {
                alert('Lütfen reçeteyi yükleyin.');
                return;
            }
            
            if (!warehouseCode) {
                alert('Depo bilgisi bulunamadı. Lütfen sistem yöneticisi ile iletişime geçin.');
                return;
            }
            
            const saveBtn = document.getElementById('saveBtn');
            const originalText = saveBtn.textContent;
            saveBtn.disabled = true;
            saveBtn.textContent = 'Kaydediliyor...';
            
            // Kullanıcının değiştirdiği malzeme listesini topla
            const materials = [];
            const rows = document.querySelectorAll('#materialsTableBody tr:not(.empty-state-row)');
            rows.forEach(row => {
                const itemCode = row.dataset.itemCode;
                const baseQtyInput = row.querySelector('.base-qty-input');
                if (itemCode && baseQtyInput) {
                    const baseQty = parseFloat(baseQtyInput.value) || 0;
                    if (baseQty > 0) {
                        materials.push({
                            itemCode: itemCode,
                            baseQty: baseQty
                        });
                    }
                }
            });
            
            const formData = new FormData();
            formData.append('action', 'create_production_order');
            formData.append('itemNo', selectedProduct.ItemCode);
            formData.append('plannedQuantity', quantity);
            formData.append('warehouse', warehouseCode);
            formData.append('materials', JSON.stringify(materials));
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    window.location.href = 'UretimIsEmirleri.php';
                } else {
                    alert('❌ ' + data.message);
                    saveBtn.disabled = false;
                    saveBtn.textContent = originalText;
                }
            })
            .catch(error => {
                console.error('Kaydetme hatası:', error);
                alert('❌ Bir hata oluştu. Lütfen tekrar deneyin.');
                saveBtn.disabled = false;
                saveBtn.textContent = originalText;
            });
        }
    </script>
</body>
</html>
