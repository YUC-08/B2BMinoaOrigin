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

$docEntry = $_GET['docEntry'] ?? '';

if (empty($docEntry)) {
    header("Location: Sevkiyat.php");
    exit;
}

// InventoryTransferRequest'i çek
$docQuery = "InventoryTransferRequests({$docEntry})";
$docData = $sap->get($docQuery);
$requestData = $docData['response'] ?? null;

if (!$requestData) {
    die("Sevkiyat belgesi bulunamadı!");
}

// STATUS kontrolü: Sadece "Sevk edildi" (3) statüsündeki sevkiyatlar teslim alınabilir
$currentStatus = $requestData['U_ASB2B_STATUS'] ?? '0';
if ($currentStatus == '4') {
    $_SESSION['error_message'] = "Bu sevkiyat zaten tamamlanmış!";
    header("Location: Sevkiyat.php");
    exit;
}

if ($currentStatus != '3') {
    $_SESSION['error_message'] = "Bu sevkiyat henüz sevk edilmemiş!";
    header("Location: Sevkiyat.php");
    exit;
}

// Erişim kontrolü: Sadece alan şube teslim alabilir
$fromWarehouse = $requestData['FromWarehouse'] ?? '';
$toWarehouse = $requestData['ToWarehouse'] ?? '';

// Kullanıcının şubesine ait depoları bul
$userWarehouses = [];
$warehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and (U_ASB2B_MAIN eq '1' or U_ASB2B_MAIN eq '2')";
$warehouseQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($warehouseFilter);
$warehouseData = $sap->get($warehouseQuery);

if (($warehouseData['status'] ?? 0) == 200) {
    $warehouses = $warehouseData['response']['value'] ?? $warehouseData['value'] ?? [];
    foreach ($warehouses as $whs) {
        $whsCode = $whs['WarehouseCode'] ?? '';
        if (!empty($whsCode)) {
            $userWarehouses[] = $whsCode;
        }
    }
}

// Sadece alan şube (ToWarehouse) teslim alabilir
$isReceiver = in_array($toWarehouse, $userWarehouses);
if (!$isReceiver) {
    die("Bu sevkiyatı sadece alan şube teslim alabilir!");
}

// Lines'ı çek - çoklu deneme stratejisi
// ÖNEMLİ: SevkiyatSO.php'de InventoryTransferRequests oluştururken StockTransferLines gönderiyoruz
// Ayrıca StockTransfers da oluşturuluyor, bu yüzden satırları StockTransfers'dan çekmeliyiz
$lines = [];
$debugInfo = [];
$debugInfo['empty_itemcode_lines'] = [];

// Önce StockTransfers'dan satırları çek (U_ASB2B_QutMaster ile ilişkili)
$docEntryInt = (int)$docEntry;
$stockTransferQuery = "StockTransfers?\$filter=U_ASB2B_QutMaster%20eq%20{$docEntryInt}"
           . "&\$orderby=DocEntry%20asc"
           . "&\$top=1"
           . "&\$expand=StockTransferLines";
$stockTransferData = $sap->get($stockTransferQuery);
$debugInfo['stockTransferQuery'] = $stockTransferQuery;
$debugInfo['stockTransferData_status'] = $stockTransferData['status'] ?? 'NO STATUS';

if (($stockTransferData['status'] ?? 0) == 200) {
    $stockTransferList = $stockTransferData['response']['value'] ?? [];
    $stockTransferInfo = $stockTransferList[0] ?? null;
    $debugInfo['stockTransferInfo'] = $stockTransferInfo ? [
        'DocEntry' => $stockTransferInfo['DocEntry'] ?? null,
        'hasStockTransferLines' => isset($stockTransferInfo['StockTransferLines']),
        'StockTransferLines_count' => (isset($stockTransferInfo['StockTransferLines']) && is_array($stockTransferInfo['StockTransferLines'])) ? count((array)$stockTransferInfo['StockTransferLines']) : 0
    ] : null;
    
    if ($stockTransferInfo && isset($stockTransferInfo['StockTransferLines']) && is_array($stockTransferInfo['StockTransferLines'])) {
        $lines = $stockTransferInfo['StockTransferLines'];
        $debugInfo['lines_source'] = 'StockTransfers.StockTransferLines';
    }
}

$debugInfo['lines_count_after_stocktransfer'] = count($lines);

// Eğer hala boşsa, InventoryTransferRequests'dan StockTransferLines ile dene
if (empty($lines)) {
    $linesQuery1 = "InventoryTransferRequests({$docEntry})?\$expand=StockTransferLines";
    $linesData1 = $sap->get($linesQuery1);
    $debugInfo['linesQuery1'] = $linesQuery1;
    $debugInfo['linesData1_status'] = $linesData1['status'] ?? 'NO STATUS';
    
    if (($linesData1['status'] ?? 0) == 200) {
        $response1 = $linesData1['response'] ?? null;
        if ($response1 && isset($response1['StockTransferLines']) && is_array($response1['StockTransferLines'])) {
            $lines = $response1['StockTransferLines'];
            $debugInfo['lines_source'] = 'InventoryTransferRequests.StockTransferLines (expand)';
        }
    }
}

$debugInfo['lines_count_after_query1'] = count($lines);

// Eğer hala boşsa, direkt StockTransferLines collection'ından çek
if (empty($lines)) {
    $linesQuery2 = "InventoryTransferRequests({$docEntry})/StockTransferLines";
    $linesData2 = $sap->get($linesQuery2);
    $debugInfo['linesQuery2'] = $linesQuery2;
    $debugInfo['linesData2_status'] = $linesData2['status'] ?? 'NO STATUS';
    
    if (($linesData2['status'] ?? 0) == 200) {
        $response2 = $linesData2['response'] ?? null;
        if ($response2) {
            if (isset($response2['value']) && is_array($response2['value'])) {
                $lines = $response2['value'];
                $debugInfo['lines_source'] = 'InventoryTransferRequests/StockTransferLines (direct)';
            } elseif (is_array($response2) && !isset($response2['value']) && !isset($response2['@odata.context'])) {
                $lines = $response2;
                $debugInfo['lines_source'] = 'InventoryTransferRequests/StockTransferLines (direct array)';
            } elseif (isset($response2['StockTransferLines'])) {
                $stockTransferLines = $response2['StockTransferLines'];
                if (is_array($stockTransferLines)) {
                    $lines = $stockTransferLines;
                    $debugInfo['lines_source'] = 'InventoryTransferRequests/StockTransferLines (nested)';
                }
            }
        }
    }
    $debugInfo['lines_count_after_query2'] = count($lines);
}

$debugInfo['final_lines_count'] = count($lines);
$debugInfo['sample_line'] = !empty($lines) ? $lines[0] : null;

// Sevk miktarını StockTransfers'dan çek (U_ASB2B_QutMaster ile ilişkili)
// Zaten yukarıda StockTransfers'ı çektik, aynı veriyi kullanabiliriz
$sevkMiktarMap = [];
$outgoingStockTransferInfo = $stockTransferInfo ?? null;

if ($outgoingStockTransferInfo && isset($outgoingStockTransferInfo['StockTransferLines'])) {
    $stLines = $outgoingStockTransferInfo['StockTransferLines'];
    $debugInfo['stLines_count'] = count($stLines);
    $debugInfo['stLines_sample'] = !empty($stLines) ? $stLines[0] : null;
    
    foreach ($stLines as $stLine) {
        $itemCode = $stLine['ItemCode'] ?? '';
        $qty = (float)($stLine['Quantity'] ?? 0);
        
        // Fire/Zayi'yi filtrele
        $lost = trim($stLine['U_ASB2B_LOST'] ?? '');
        $damaged = trim($stLine['U_ASB2B_Damaged'] ?? '');
        if (($lost !== '' && $lost !== '-') || ($damaged !== '' && $damaged !== '-')) {
            continue;
        }
        
        if ($itemCode === '' || $qty <= 0) {
            continue;
        }
        
        if (!isset($sevkMiktarMap[$itemCode])) {
            $sevkMiktarMap[$itemCode] = 0;
        }
        $sevkMiktarMap[$itemCode] += $qty;
    }
}

$debugInfo['sevkMiktarMap'] = $sevkMiktarMap;

// Her satır için BaseQty ve UoM bilgilerini çek
$processedLines = [];
foreach ($lines as $index => $line) {
    $itemCode = $line['ItemCode'] ?? '';
    if (empty($itemCode)) {
        $debugInfo['empty_itemcode_lines'][] = $index;
        continue;
    }
    
    // Item bilgilerini çek (ItemName dahil)
    $itemData = $sap->get("Items('{$itemCode}')?\$select=ItemName,UoMGroupEntry,InventoryUOM");
    $itemInfo = $itemData['response'] ?? [];
    $itemName = $itemInfo['ItemName'] ?? '';
    $uomGroupEntry = $itemInfo['UoMGroupEntry'] ?? -1;
    $inventoryUOM = $itemInfo['InventoryUOM'] ?? 'AD';
    
    // UoMCode'u al
    $uomCode = $line['UoMCode'] ?? $inventoryUOM;
    
    // BaseQty hesapla
    $baseQty = 1.0;
    if ($uomGroupEntry != -1) {
        $uomGroupData = $sap->get("UoMGroups({$uomGroupEntry})?\$select=UoMGroupDefinitionCollection");
        $uomGroupDefs = $uomGroupData['response']['UoMGroupDefinitionCollection'] ?? [];
        foreach ($uomGroupDefs as $def) {
            if (($def['UoMCode'] ?? '') === $uomCode) {
                $baseQty = floatval($def['BaseQuantity'] ?? 1.0);
                break;
            }
        }
    }
    
    // Sevk miktarı (StockTransfers'dan)
    $sevkMiktari = $sevkMiktarMap[$itemCode] ?? floatval($line['Quantity'] ?? 0);
    $sevkMiktariNormalized = $baseQty > 0 ? ($sevkMiktari / $baseQty) : $sevkMiktari;
    
    // Line'ı güncelle
    $line['ItemDescription'] = $itemName ?: ($line['ItemDescription'] ?? '');
    $line['_BaseQty'] = $baseQty;
    $line['_SevkQty'] = $sevkMiktariNormalized;
    $line['UoMCode'] = $uomCode;
    
    $processedLines[] = $line;
}

$lines = $processedLines;
$debugInfo['processed_lines_count'] = count($lines);
$debugInfo['sample_processed_line'] = !empty($lines) && is_array($lines) ? [
    'ItemCode' => $lines[0]['ItemCode'] ?? '',
    'ItemDescription' => $lines[0]['ItemDescription'] ?? '',
    'Quantity' => $lines[0]['Quantity'] ?? 0,
    '_SevkQty' => $lines[0]['_SevkQty'] ?? 0,
    'UoMCode' => $lines[0]['UoMCode'] ?? ''
] : null;

// ToWarehouse'un tipini (U_ASB2B_MAIN) bul - Bu kritik!
// Eğer ToWarehouse sevkiyat deposu ise (U_ASB2B_MAIN='2'), ikinci StockTransfer oluşturulacak
// Eğer ToWarehouse ana depo ise (U_ASB2B_MAIN='1' veya '0'), ikinci StockTransfer OLUŞTURULMAYACAK
$toWhsInfoData = $sap->get("Warehouses('{$toWarehouse}')?\$select=WarehouseCode,U_ASB2B_MAIN");
$toWhsInfo = $toWhsInfoData['response'] ?? [];
$toMainType = $toWhsInfo['U_ASB2B_MAIN'] ?? null; // '0', '1', '2', '3', '4'...

// Yalnızca ToWarehouse bir SEVK DEPOSU ise ikinci stok nakli yapılacak
$createSecondTransfer = ($toMainType === '2');

// Alan şubenin ana deposunu bul (U_ASB2B_MAIN='1') - Sadece sevkiyat deposu senaryosunda kullanılacak
$targetWarehouse = null;
if ($createSecondTransfer) {
    $targetWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '1'";
    $targetWarehouseQuery = "Warehouses?\$filter=" . urlencode($targetWarehouseFilter);
    $targetWarehouseData = $sap->get($targetWarehouseQuery);
    $targetWarehouses = $targetWarehouseData['response']['value'] ?? [];
    $targetWarehouse = !empty($targetWarehouses) ? $targetWarehouses[0]['WarehouseCode'] : null;
    
    if (empty($targetWarehouse)) {
        die("Hedef depo (U_ASB2B_MAIN=1) bulunamadı!");
    }
}

// Fire & Zayi deposunu bul (her iki senaryoda da kullanılabilir)
$fireZayiWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and (U_ASB2B_MAIN eq '3' or U_ASB2B_MAIN eq '4')";
$fireZayiWarehouseQuery = "Warehouses?\$filter=" . urlencode($fireZayiWarehouseFilter);
$fireZayiWarehouseData = $sap->get($fireZayiWarehouseQuery);
$fireZayiWarehouses = $fireZayiWarehouseData['response']['value'] ?? [];
$fireZayiWarehouse = !empty($fireZayiWarehouses) ? $fireZayiWarehouses[0]['WarehouseCode'] : null;

// POST işlemi: StockTransfer oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'teslim_al') {
    header('Content-Type: application/json');

    // STATUS kontrolü: Fresh data çek ve kontrol et
    $freshRequestQuery = "InventoryTransferRequests({$docEntry})";
    $freshRequestData = $sap->get($freshRequestQuery);
    $freshRequestInfo = $freshRequestData['response'] ?? null;
    
    if ($freshRequestInfo) {
        $currentStatus = $freshRequestInfo['U_ASB2B_STATUS'] ?? '0';
        if ($currentStatus == '4') {
            echo json_encode(['success' => false, 'message' => 'Bu sevkiyat zaten tamamlanmış!']);
            exit;
        }
        if ($currentStatus != '3') {
            echo json_encode(['success' => false, 'message' => 'Bu sevkiyat henüz sevk edilmemiş!']);
            exit;
        }
        $requestData = $freshRequestInfo;
    }

    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'Sevkiyat satırları bulunamadı!']);
        exit;
    }

    $docDate = $requestData['DocDate'] ?? date('Y-m-d');
    $docNum = $requestData['DocNum'] ?? $docEntry;
    
    // Kusurlu miktarları topla
    $hasKusurlu = false;
    $kusurluLines = [];
    $headerComments = [];
    
    foreach ($lines as $index => $line) {
        $itemCode = $line['ItemCode'] ?? '';
        $itemName = $line['ItemDescription'] ?? $itemCode;
        $kusurluQty = floatval($_POST['kusurlu'][$index] ?? 0);
        if ($kusurluQty > 0) {
            $hasKusurlu = true;
            $not = trim($_POST['not'][$index] ?? '');
            $sevkMiktari = $sevkMiktarMap[$itemCode] ?? floatval($line['Quantity'] ?? 0);
            $baseQty = floatval($line['_BaseQty'] ?? 1.0);
            $sevkMiktariNormalized = $baseQty > 0 ? ($sevkMiktari / $baseQty) : $sevkMiktari;
            
            // Kusurlu miktar sevk miktarını aşamaz
            if ($kusurluQty > $sevkMiktariNormalized) {
                $kusurluQty = $sevkMiktariNormalized;
            }
            
            $commentParts = [];
            $commentParts[] = "Kusurlu: {$kusurluQty}";
            $commentParts[] = "Fiziksel: " . ($sevkMiktariNormalized - $kusurluQty);
            if (!empty($not)) {
                $commentParts[] = "Not: {$not}";
            }
            $headerComments[] = "{$itemCode} ({$itemName}): " . implode(", ", $commentParts);
            
            $kusurluLines[] = [
                'line' => $line,
                'index' => $index,
                'kusurluQty' => $kusurluQty,
                'not' => $not,
                'baseQty' => $baseQty
            ];
        } else {
            // Kusurlu yoksa sadece fiziksel miktarı ekle
            $sevkMiktari = $sevkMiktarMap[$itemCode] ?? floatval($line['Quantity'] ?? 0);
            $baseQty = floatval($line['_BaseQty'] ?? 1.0);
            $sevkMiktariNormalized = $baseQty > 0 ? ($sevkMiktari / $baseQty) : $sevkMiktari;
            $headerComments[] = "{$itemCode} ({$itemName}): Fiziksel: {$sevkMiktariNormalized}";
        }
    }
    
    $headerCommentsText = !empty($headerComments) ? implode(" | ", $headerComments) : '';

    // *** SENARYO 1: ToWarehouse SEVK DEPOSU ise (U_ASB2B_MAIN='2') ***
    // İkinci StockTransfer oluştur: Sevkiyat depo → Ana depo
    if ($createSecondTransfer) {
        $transferLines = [];
        
        foreach ($lines as $index => $line) {
            $itemCode = $line['ItemCode'] ?? '';
            $sevkMiktari = $sevkMiktarMap[$itemCode] ?? floatval($line['Quantity'] ?? 0);
            $baseQty = floatval($line['_BaseQty'] ?? 1.0);
            $sevkMiktariNormalized = $baseQty > 0 ? ($sevkMiktari / $baseQty) : $sevkMiktari;
            
            $kusurluQty = floatval($_POST['kusurlu'][$index] ?? 0);
            if ($kusurluQty < 0) $kusurluQty = 0;
            if ($kusurluQty > $sevkMiktariNormalized) {
                $kusurluQty = $sevkMiktariNormalized;
            }
            
            // Normal transfer miktarı = Sevk miktarı - Kusurlu miktar
            $normalTransferMiktar = $sevkMiktariNormalized - $kusurluQty;
            if ($normalTransferMiktar > 0) {
                $transferLines[] = [
                    'ItemCode' => $itemCode,
                    'Quantity' => $normalTransferMiktar * $baseQty,
                    'FromWarehouseCode' => $toWarehouse, // SEVK DEPOSU
                    'WarehouseCode' => $targetWarehouse  // ANA DEPO
                ];
            }
            
            // Kusurlu miktar varsa → SEVK DEPOSU → Fire/Zayi
            if ($kusurluQty > 0 && !empty($fireZayiWarehouse)) {
                $not = trim($_POST['not'][$index] ?? '');
                $fireZayiComments = [];
                if (!empty($not)) {
                    $fireZayiComments[] = $not;
                }
                $fireZayiComments[] = "Kusurlu: {$kusurluQty} adet";
                $fireZayiComments[] = 'Sevkiyat Teslim Alma';
                
                $transferLines[] = [
                    'ItemCode' => $itemCode,
                    'Quantity' => $kusurluQty * $baseQty,
                    'FromWarehouseCode' => $toWarehouse, // SEVK DEPOSU
                    'WarehouseCode' => $fireZayiWarehouse,
                    'U_ASB2B_Damaged' => 'K',
                    'U_ASB2B_Comments' => implode(' | ', $fireZayiComments)
                ];
            }
        }
        
        if (empty($transferLines)) {
            echo json_encode(['success' => false, 'message' => 'İşlenecek kalem bulunamadı! Lütfen en az bir kalem için teslim alın.']);
            exit;
        }
        
        $stockTransferPayload = [
            'FromWarehouse' => $toWarehouse,    // SEVK DEPOSU
            'ToWarehouse' => $targetWarehouse,  // ANA DEPO
            'DocDate' => $docDate,
            'Comments' => $headerCommentsText,
            'U_ASB2B_BRAN' => $branch,
            'U_AS_OWNR' => $uAsOwnr,
            'U_ASB2B_STATUS' => '4',
            'U_ASB2B_TYPE' => 'TRANSFER',
            'U_ASB2B_User' => $_SESSION["UserName"] ?? '',
            'U_ASB2B_QutMaster' => (int)$docEntry,
            'DocumentReferences' => [
                [
                    'RefDocEntr' => (int)$docEntry,
                    'RefDocNum' => (int)$docNum,
                    'RefObjType' => 'rot_InventoryTransferRequest'
                ]
            ],
            'StockTransferLines' => $transferLines
        ];
        
        $result = $sap->post('StockTransfers', $stockTransferPayload);
        
        if ($result['status'] == 200 || $result['status'] == 201) {
            $updatePayload = ['U_ASB2B_STATUS' => '4'];
            $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);
            
            echo json_encode([
                'success' => true,
                'message' => 'Sevkiyat teslim alındı (sevkiyat deposu → ana depo transferi oluşturuldu).',
                'redirect' => 'Sevkiyat.php'
            ]);
        } else {
            $errorMsg = 'Teslim alma işlemi başarısız! HTTP ' . ($result['status'] ?? 'NO STATUS');
            if (isset($result['response']['error'])) {
                $error = $result['response']['error'];
                $errorMsg .= ' - ' . ($error['message']['value'] ?? $error['message'] ?? json_encode($error));
            }
            echo json_encode(['success' => false, 'message' => $errorMsg]);
        }
        exit;
    }
    
    // *** SENARYO 2: ToWarehouse ANA DEPO ise (U_ASB2B_MAIN='1' veya '0') ***
    // İkinci StockTransfer OLUŞTURULMAYACAK
    // Sadece status = 4 yap
    // Eğer kusurlu varsa, sadece Fire/Zayi transferi oluştur
    
    if (!$hasKusurlu) {
        // Kusurlu yoksa sadece status güncelle
        $updatePayload = ['U_ASB2B_STATUS' => '4'];
        $updateResult = $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);
        
        echo json_encode([
            'success' => true,
            'message' => 'Sevkiyat teslim alındı (ek stok transferine gerek yok, doğrudan hedef depoya gönderilmiş).',
            'redirect' => 'Sevkiyat.php'
        ]);
        exit;
    }
    
    // Kusurlu miktar varsa → ANA DEPO → Fire/Zayi (tek bir StockTransfer)
    if (empty($fireZayiWarehouse)) {
        // Fire/Zayi deposu yoksa sadece status güncelle
        $updatePayload = ['U_ASB2B_STATUS' => '4'];
        $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);
        
        echo json_encode([
            'success' => true,
            'message' => 'Sevkiyat teslim alındı (kusurlu miktar var ancak Fire/Zayi deposu bulunamadı).',
            'redirect' => 'Sevkiyat.php'
        ]);
        exit;
    }
    
    $transferLines = [];
    foreach ($kusurluLines as $kusurluLine) {
        $line = $kusurluLine['line'];
        $itemCode = $line['ItemCode'] ?? '';
        $kusurluQty = $kusurluLine['kusurluQty'];
        $baseQty = $kusurluLine['baseQty'];
        $not = $kusurluLine['not'];
        
        $fireZayiComments = [];
        if (!empty($not)) {
            $fireZayiComments[] = $not;
        }
        $fireZayiComments[] = "Kusurlu: {$kusurluQty} adet";
        $fireZayiComments[] = 'Sevkiyat Teslim Alma';
        
        $transferLines[] = [
            'ItemCode' => $itemCode,
            'Quantity' => $kusurluQty * $baseQty,
            'FromWarehouseCode' => $toWarehouse, // ANA DEPO
            'WarehouseCode' => $fireZayiWarehouse,
            'U_ASB2B_Damaged' => 'K',
            'U_ASB2B_Comments' => implode(' | ', $fireZayiComments)
        ];
    }
    
    $stockTransferPayload = [
        'FromWarehouse' => $toWarehouse, // ANA DEPO
        'ToWarehouse' => $fireZayiWarehouse, // Fire/Zayi deposu
        'DocDate' => $docDate,
        'Comments' => $headerCommentsText,
        'U_ASB2B_BRAN' => $branch,
        'U_AS_OWNR' => $uAsOwnr,
        'U_ASB2B_STATUS' => '4',
        'U_ASB2B_TYPE' => 'TRANSFER',
        'U_ASB2B_User' => $_SESSION["UserName"] ?? '',
        'U_ASB2B_QutMaster' => (int)$docEntry,
        'DocumentReferences' => [
            [
                'RefDocEntr' => (int)$docEntry,
                'RefDocNum' => (int)$docNum,
                'RefObjType' => 'rot_InventoryTransferRequest'
            ]
        ],
        'StockTransferLines' => $transferLines
    ];
    
    $result = $sap->post('StockTransfers', $stockTransferPayload);
    
    if ($result['status'] == 200 || $result['status'] == 201) {
        $updatePayload = ['U_ASB2B_STATUS' => '4'];
        $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);
        
        echo json_encode([
            'success' => true,
            'message' => 'Sevkiyat teslim alındı (kusurlu miktar Fire/Zayi deposuna transfer edildi).',
            'redirect' => 'Sevkiyat.php'
        ]);
    } else {
        $errorMsg = 'Teslim alma işlemi başarısız! HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $error = $result['response']['error'];
            $errorMsg .= ' - ' . ($error['message']['value'] ?? $error['message'] ?? json_encode($error));
        }
        echo json_encode(['success' => false, 'message' => $errorMsg]);
    }
    exit;
}

// Depo isimlerini al
$fromWhsName = '';
$fromWhsQuery = "Warehouses('{$fromWarehouse}')?\$select=WarehouseName";
$fromWhsData = $sap->get($fromWhsQuery);
if (($fromWhsData['status'] ?? 0) == 200) {
    $fromWhsInfo = $fromWhsData['response'] ?? [];
    $fromWhsName = $fromWhsInfo['WarehouseName'] ?? '';
}

$docDate = $requestData['DocDate'] ?? date('Y-m-d');
$dueDate = $requestData['DueDate'] ?? $docDate;
if (strpos($docDate, 'T') !== false) {
    $docDate = date('d.m.Y', strtotime(substr($docDate, 0, 10)));
}
if (strpos($dueDate, 'T') !== false) {
    $dueDate = date('d.m.Y', strtotime(substr($dueDate, 0, 10)));
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teslim Al - Sevkiyat</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f3f4f6;
            color: #2c3e50;
            line-height: 1.6;
        }

        .main-content {
            margin-left: 280px;
            padding: 2rem;
            min-height: 100vh;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-header h2 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e40af;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-danger {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .data-table thead {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .data-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table th:nth-child(3),
        .data-table th:nth-child(4),
        .data-table th:nth-child(5),
        .data-table th:nth-child(6) {
            text-align: center;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
            transition: background-color 0.2s;
        }

        .data-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .data-table td {
            padding: 1rem;
            font-size: 0.95rem;
        }

        .data-table td:nth-child(3),
        .data-table td:nth-child(4),
        .data-table td:nth-child(5),
        .data-table td:nth-child(6) {
            text-align: center;
        }

        .quantity-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            justify-content: center;
        }

        .qty-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #3b82f6;
            background: white;
            color: #3b82f6;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            min-width: 40px;
            transition: all 0.2s;
        }

        .qty-btn:hover {
            background: #3b82f6;
            color: white;
            transform: scale(1.05);
        }

        .qty-input {
            width: 100px;
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
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .qty-input[readonly] {
            background-color: #f3f4f6;
            color: #6b7280;
        }


        .form-actions {
            margin-top: 2rem;
            text-align: right;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<main class="main-content">
    <header class="page-header">
        <h2>Teslim Al - Sevkiyat No: <?= htmlspecialchars($docEntry) ?></h2>
        <button class="btn btn-secondary" onclick="window.location.href='Sevkiyat.php'">← Geri Dön</button>
    </header>

    <!-- Debug Panel -->
    <div class="card" style="background: #fef3c7; border: 2px solid #f59e0b; margin-bottom: 2rem;">
        <h3 style="margin-bottom: 1rem; color: #92400e;">🔍 Debug Bilgileri</h3>
        <div style="background: white; padding: 1rem; border-radius: 8px; font-family: monospace; font-size: 12px; max-height: 400px; overflow-y: auto;">
            <pre><?= htmlspecialchars(json_encode($debugInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
    </div>

    <?php if (empty($lines)): ?>
        <div class="card">
            <p style="color: #ef4444; font-weight: 600; margin-bottom: 1rem;">⚠️ Sevkiyat satırları bulunamadı!</p>
            <p style="color: #6b7280; font-size: 0.875rem;">Debug bilgileri yukarıda gösterilmektedir.</p>
        </div>
    <?php else: ?>

        <!-- Sevkiyat bilgi kartı -->
        <div class="card">
            <h3 style="margin-bottom: 1rem; color: #1e40af;">Sevkiyat Bilgileri</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Sevkiyat No</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($docEntry) ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Gönderen Şube</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($fromWarehouse) ?><?= !empty($fromWhsName) ? ' / ' . htmlspecialchars($fromWhsName) : '' ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Sevkiyat Tarihi</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= $docDate ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Planlanan Tarih</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= $dueDate ?></div>
                </div>
            </div>
        </div>

        <form method="POST" action="" onsubmit="return validateForm()">
            <input type="hidden" name="action" value="teslim_al">

            <div class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kalem Kodu</th>
                            <th>Kalem Tanımı</th>
                            <th>Sevk Miktarı</th>
                            <th>Kusurlu Miktar</th>
                            <th>Fiziksel</th>
                            <th>Not</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lines as $index => $line):
                        $itemCode = $line['ItemCode'] ?? '';
                        $itemName = $line['ItemDescription'] ?? '-';
                        $baseQty = floatval($line['_BaseQty'] ?? 1.0);
                        $sevkQty = floatval($line['_SevkQty'] ?? 0);
                        $uomCode = $line['UoMCode'] ?? 'AD';
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($itemCode) ?></td>
                            <td><?= htmlspecialchars($itemName) ?></td>
                            <td>
                                <div style="display: flex; align-items: center; justify-content: center; gap: 4px;">
                                    <input type="number"
                                           id="sevk_<?= $index ?>"
                                           value="<?= htmlspecialchars($sevkQty) ?>"
                                           readonly
                                           step="0.01"
                                           class="qty-input">
                                    <span style="font-size: 0.875rem; color: #6b7280; font-weight: 500;"><?= htmlspecialchars($uomCode) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="quantity-controls" style="display: flex; align-items: center; gap: 4px;">
                                    <button type="button" class="qty-btn" onclick="changeKusurlu(<?= $index ?>, -1)">-</button>
                                    <input type="number"
                                           name="kusurlu[<?= $index ?>]"
                                           id="kusurlu_<?= $index ?>"
                                           value="0"
                                           min="0"
                                           step="0.01"
                                           class="qty-input"
                                           onchange="calculatePhysical(<?= $index ?>)"
                                           oninput="calculatePhysical(<?= $index ?>)">
                                    <button type="button" class="qty-btn" onclick="changeKusurlu(<?= $index ?>, 1)">+</button>
                                    <span style="font-size: 0.875rem; color: #6b7280; font-weight: 500;"><?= htmlspecialchars($uomCode) ?></span>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; justify-content: center; gap: 4px;">
                                    <input type="text"
                                           id="fiziksel_<?= $index ?>"
                                           value="<?= htmlspecialchars($sevkQty) ?>"
                                           readonly
                                           class="qty-input">
                                    <span style="font-size: 0.875rem; color: #6b7280; font-weight: 500;"><?= htmlspecialchars($uomCode) ?></span>
                                </div>
                            </td>
                            <td>
                                <input type="text"
                                       name="not[<?= $index ?>]"
                                       placeholder="Not"
                                       style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px;">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='Sevkiyat.php'">İptal</button>
                    <button type="submit" class="btn btn-primary">
                        ✓ Teslim Al / Onayla
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const eksikFazlaInputs = document.querySelectorAll('input[name^="eksik_fazla"]');
    eksikFazlaInputs.forEach(function(input) {
        const index = input.id.replace('eksik_', '');
        updateEksikFazlaColor(input);
        calculatePhysical(parseInt(index));
    });
    
    // İlk yüklemede fiziksel miktarları hesapla (sevk miktarı üzerinden)
    const sevkInputs = document.querySelectorAll('input[id^="sevk_"]');
    sevkInputs.forEach(function(sevkInput) {
        const index = sevkInput.id.replace('sevk_', '');
        calculatePhysical(parseInt(index));
    });
});

function changeKusurlu(index, delta) {
    const input = document.getElementById('kusurlu_' + index);
    if (!input) return;
    
    const sevkInput = document.getElementById('sevk_' + index);
    if (!sevkInput) return;
    
    const sevk = parseFloat(sevkInput.value) || 0;
    // Fiziksel = Sevk (eksik/fazla yok)
    const fizikselMiktar = Math.max(0, sevk);
    
    let value = parseFloat(input.value) || 0;
    value += delta;
    if (value < 0) value = 0;
    if (value > fizikselMiktar) value = fizikselMiktar;
    input.value = value;
    calculatePhysical(index);
}

function calculatePhysical(index) {
    const sevkInput = document.getElementById('sevk_' + index);
    const kusurluInput = document.getElementById('kusurlu_' + index);
    const fizikselInput = document.getElementById('fiziksel_' + index);
    
    if (!sevkInput || !fizikselInput) return;
    
    const sevk = parseFloat(sevkInput.value) || 0;
    const kusurlu = parseFloat(kusurluInput?.value || 0) || 0;
    
    // Fiziksel = Sevk (eksik/fazla yok, direkt sevk miktarı)
    const fizikselMiktar = Math.max(0, sevk);
    
    // Kusurlu miktar kontrolü
    if (kusurluInput && kusurlu > fizikselMiktar) {
        kusurluInput.value = fizikselMiktar.toFixed(2);
    }
    
    fizikselInput.value = fizikselMiktar.toFixed(2);
}

function validateForm() {
    const form = document.querySelector('form');
    const formData = new FormData(form);
    
    // AJAX ile gönder
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message || 'Sevkiyat başarıyla teslim alındı!');
            if (data.redirect) {
                window.location.href = data.redirect;
            } else {
                window.location.href = 'Sevkiyat.php';
            }
        } else {
            alert(data.message || 'Teslim alma işlemi başarısız!');
        }
    })
    .catch(err => {
        console.error('Hata:', err);
        alert('Bir bağlantı hatası oluştu');
    });
    
    return false; // Form submit'i engelle
}
</script>
</body>
</html>

