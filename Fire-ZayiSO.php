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

// Fire ve Zayi depolarını çek (sayfa yüklendiğinde JavaScript'e aktarmak için)
$fireWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '3'";
$fireWarehouseQuery = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=" . urlencode($fireWarehouseFilter);
$fireWarehouseData = $sap->get($fireWarehouseQuery);
$fireWarehouse = null;
$fireWarehouseName = null;
if (($fireWarehouseData['status'] ?? 0) == 200) {
    $fireList = $fireWarehouseData['response']['value'] ?? [];
    if (!empty($fireList)) {
        $fireWarehouse = $fireList[0]['WarehouseCode'] ?? null;
        $fireWarehouseName = $fireList[0]['WarehouseName'] ?? null;
    }
}

// Zayi deposu (artık BRAN dolu olduğu için fire ile aynı mantık)
$zayiWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '4'";
$zayiWarehouseQuery  = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=" . urlencode($zayiWarehouseFilter);
$zayiWarehouseData   = $sap->get($zayiWarehouseQuery);

$zayiWarehouse = null;
$zayiWarehouseName = null;

if (($zayiWarehouseData['status'] ?? 0) == 200) {
    $zayiList = $zayiWarehouseData['response']['value'] ?? [];
    if (!empty($zayiList)) {
        $zayiWarehouse     = $zayiList[0]['WarehouseCode'] ?? null;
        $zayiWarehouseName = $zayiList[0]['WarehouseName'] ?? null;
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
    $docDate = trim($_POST['docDate'] ?? date('Y-m-d'));
    $comments = trim($_POST['comments'] ?? '');
    $lines = isset($_POST['lines']) ? json_decode($_POST['lines'], true) : [];
    
    if (empty($fromWarehouse) || empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'Eksik bilgi: Çıkış depo ve en az bir kalem gereklidir']);
        exit;
    }
    
    // Fire ve Zayi depolarını bul
    $fireWarehouse = null;
    $zayiWarehouse = null;
    
    // Fire deposu (U_ASB2B_MAIN='3')
    $fireFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '3'";
    $fireQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($fireFilter);
    $fireData = $sap->get($fireQuery);
    if (($fireData['status'] ?? 0) == 200) {
        $fireList = $fireData['response']['value'] ?? [];
        if (!empty($fireList)) {
            $fireWarehouse = $fireList[0]['WarehouseCode'] ?? null;
        }
    }
    
    // Zayi deposu (U_ASB2B_MAIN='4')
    $zayiFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '4'";
    $zayiQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($zayiFilter);
    $zayiData = $sap->get($zayiQuery);
    if (($zayiData['status'] ?? 0) == 200) {
        $zayiList = $zayiData['response']['value'] ?? [];
        if (!empty($zayiList)) {
            $zayiWarehouse = $zayiList[0]['WarehouseCode'] ?? null;
        }
    }
    
    // Fire ve Zayi item'larını ayır
    $fireLines = [];
    $zayiLines = [];
    $hasFire = false;
    $hasZayi = false;
    $missingWarehouses = [];
    
    // ItemName bilgilerini saklamak için
    $itemNames = [];
    
    foreach ($lines as $line) {
        $itemCode = $line['ItemCode'] ?? '';
        $itemName = $line['ItemName'] ?? '';
        $type = $line['Type'] ?? '';
        $quantity = floatval($line['Quantity'] ?? 0);
        $uomEntry = $line['UoMEntry'] ?? null;
        $uomCode = $line['UoMCode'] ?? '';
        
        if (empty($itemCode) || $quantity === 0) {
            continue;
        }
        
        // ItemName'i sakla
        if (!empty($itemName)) {
            $itemNames[$itemCode] = $itemName;
        }
        
        $lineData = [
            'ItemCode' => $itemCode,
            'Quantity' => $quantity,
            'FromWarehouseCode' => $fromWarehouse,
            'UoMEntry' => !empty($uomEntry) ? intval($uomEntry) : null,
            'UoMCode' => $uomCode
        ];
        
        if (!empty($uomEntry)) {
            $lineData['UoMEntry'] = intval($uomEntry);
        }
        if (!empty($uomCode)) {
            $lineData['UoMCode'] = $uomCode;
        }
        
        if ($type === 'fire') {
            if (empty($fireWarehouse)) {
                $missingWarehouses[] = 'fire';
            } else {
                $lineData['WarehouseCode'] = $fireWarehouse;
                $lineData['U_ASB2B_LOST'] = '1'; // Fire
                $fireLines[] = $lineData;
                $hasFire = true;
            }
        } elseif ($type === 'zayi') {
            if (empty($zayiWarehouse)) {
                $missingWarehouses[] = 'zayi';
            } else {
                $lineData['WarehouseCode'] = $zayiWarehouse;
                $lineData['U_ASB2B_LOST'] = '2'; // Zayi
                $zayiLines[] = $lineData;
                $hasZayi = true;
            }
        }
    }
    
    // Eksik depo kontrolü
    if (in_array('fire', $missingWarehouses) && in_array('zayi', $missingWarehouses)) {
        echo json_encode(['success' => false, 'message' => 'Bu şubenin fire deposu ve zayi deposu bulunamadı.']);
        exit;
    } elseif (in_array('fire', $missingWarehouses)) {
        echo json_encode(['success' => false, 'message' => 'Bu şubenin fire deposu bulunamadı.']);
        exit;
    } elseif (in_array('zayi', $missingWarehouses)) {
        echo json_encode(['success' => false, 'message' => 'Bu şubenin zayi deposu bulunamadı.']);
        exit;
    }
    
    if (empty($fireLines) && empty($zayiLines)) {
        echo json_encode(['success' => false, 'message' => 'Geçerli satır bulunamadı. Fire veya Zayi miktarı giriniz.']);
        exit;
    }
    
    $createdDocs = [];
    $errors = [];
    
    // Fire belgesi oluştur
    if (!empty($fireLines)) {
        // Açıklama oluştur: "X adet eksik fire" formatında
        $fireComments = [];
        foreach ($fireLines as $line) {
            $itemCode = $line['ItemCode'] ?? '';
            $qty = $line['Quantity'] ?? 0;
            $itemName = $itemNames[$itemCode] ?? '';
            if (!empty($itemName)) {
                $fireComments[] = "{$itemCode} ({$itemName}): {$qty} adet eksik fire";
            } else {
                $fireComments[] = "{$itemCode}: {$qty} adet eksik fire";
            }
        }
        $fireCommentsText = '[TRANSFER] ' . implode(' | ', $fireComments);
        if (!empty($comments)) {
            $fireCommentsText = $comments . ' ' . $fireCommentsText;
        }
        
        $firePayload = [
            'U_ASB2B_TYPE' => 'TRANSFER',
            'U_ASB2B_LOST' => '1', // Fire
            'U_AS_OWNR' => $uAsOwnr,
            'U_ASB2B_BRAN' => $branch,
            'DocDate' => $docDate,
            'FromWarehouse' => $fromWarehouse,
            'ToWarehouse' => $fireWarehouse,
            'StockTransferLines' => $fireLines,
            'Comments' => $fireCommentsText
        ];
        
        $fireResult = $sap->post('StockTransfers', $firePayload);
        
        if (($fireResult['status'] ?? 0) == 200 || ($fireResult['status'] ?? 0) == 201) {
            $fireDocEntry = $fireResult['response']['DocEntry'] ?? null;
            $createdDocs[] = ['type' => 'fire', 'docEntry' => $fireDocEntry];
        } else {
            $errorMsg = 'Fire belgesi oluşturulamadı: HTTP ' . ($fireResult['status'] ?? 'NO STATUS');
            if (isset($fireResult['response']['error'])) {
                $error = $fireResult['response']['error'];
                $errorMsg .= ' - ' . ($error['message']['value'] ?? $error['message'] ?? json_encode($error));
            }
            $errors[] = $errorMsg;
        }
    }
    
    // Zayi belgesi oluştur
    if (!empty($zayiLines)) {
        // Açıklama oluştur: "X adet eksik zayi" formatında
        $zayiComments = [];
        foreach ($zayiLines as $line) {
            $itemCode = $line['ItemCode'] ?? '';
            $qty = $line['Quantity'] ?? 0;
            $itemName = $itemNames[$itemCode] ?? '';
            if (!empty($itemName)) {
                $zayiComments[] = "{$itemCode} ({$itemName}): {$qty} adet eksik zayi";
            } else {
                $zayiComments[] = "{$itemCode}: {$qty} adet eksik zayi";
            }
        }
        $zayiCommentsText = '[TRANSFER] ' . implode(' | ', $zayiComments);
        if (!empty($comments)) {
            $zayiCommentsText = $comments . ' ' . $zayiCommentsText;
        }
        
        $zayiPayload = [
            'U_ASB2B_TYPE' => 'TRANSFER',
            'U_ASB2B_LOST' => '2', // Zayi
            'U_AS_OWNR' => $uAsOwnr,
            'U_ASB2B_BRAN' => $branch,
            'DocDate' => $docDate,
            'FromWarehouse' => $fromWarehouse,
            'ToWarehouse' => $zayiWarehouse,
            'StockTransferLines' => $zayiLines,
            'Comments' => $zayiCommentsText
        ];
        
        $zayiResult = $sap->post('StockTransfers', $zayiPayload);
        
        if (($zayiResult['status'] ?? 0) == 200 || ($zayiResult['status'] ?? 0) == 201) {
            $zayiDocEntry = $zayiResult['response']['DocEntry'] ?? null;
            $createdDocs[] = ['type' => 'zayi', 'docEntry' => $zayiDocEntry];
        } else {
            $errorMsg = 'Zayi belgesi oluşturulamadı: HTTP ' . ($zayiResult['status'] ?? 'NO STATUS');
            if (isset($zayiResult['response']['error'])) {
                $error = $zayiResult['response']['error'];
                $errorMsg .= ' - ' . ($error['message']['value'] ?? $error['message'] ?? json_encode($error));
            }
            $errors[] = $errorMsg;
        }
    }
    
    // Sonuç döndür
    if (!empty($createdDocs)) {
        $messages = [];
        foreach ($createdDocs as $doc) {
            $typeName = $doc['type'] === 'fire' ? 'Fire' : 'Zayi';
            $messages[] = "{$typeName} belgesi oluşturuldu (DocEntry: {$doc['docEntry']})";
        }
        
        $firstDocEntry = $createdDocs[0]['docEntry'];
        
        if (!empty($errors)) {
            echo json_encode([
                'success' => true,
                'message' => implode('. ', $messages) . '. Hatalar: ' . implode('; ', $errors),
                'docEntry' => $firstDocEntry,
                'createdDocs' => $createdDocs
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => implode('. ', $messages),
                'docEntry' => $firstDocEntry,
                'createdDocs' => $createdDocs
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Hiçbir belge oluşturulamadı. ' . implode('; ', $errors)
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

        /* Modern Single Select Dropdown (AnaDepoSO gibi) */
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

        /* Modern Toggle Switch for Fire/Zayi */
        .toggle-group {
            display: flex;
            gap: 0;
            background: #f3f4f6;
            border-radius: 8px;
            padding: 4px;
            width: 100%;
        }

        .toggle-option {
            flex: 1;
            position: relative;
        }

        .toggle-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-option label {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            color: #6b7280;
            text-align: center;
            min-height: 44px;
            box-sizing: border-box;
        }

        .toggle-option input[type="radio"]:checked + label {
            background: white;
            color: #1e40af;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .toggle-option input[type="radio"]:checked + label.fire {
            color: #dc2626;
        }

        .toggle-option input[type="radio"]:checked + label.zayi {
            color: #d97706;
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

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
        }

        .pagination button {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination #pageInfo {
            color: #4b5563;
            font-size: 0.9rem;
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

        /* Sepet Button Styles */
        .page-header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

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

        /* Layout Container */
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
            display: none;
            flex-direction: column;
            overflow-y: auto;
            max-height: calc(100vh - 120px);
        }

        .main-layout-container.sepet-open .main-content-right.sepet-panel {
            display: flex;
        }

        .main-content-right.sepet-panel .card {
            margin: 0;
        }

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

        /* Sepet Item Styles */
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

        .sepet-item-details {
            font-size: 0.85rem;
            color: #6b7280;
            margin-bottom: 4px;
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

        .empty-message {
            text-align: center;
            padding: 40px;
            color: #6b7280;
            font-style: italic;
        }

        .card {
            margin-bottom: 2rem;
        }

        .card:last-child {
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    <main class="main-content">
        <header class="page-header">
            <h2>Yeni Fire/Zayi Ekle</h2>
            <div class="page-header-actions">
                <button class="btn btn-primary sepet-btn" id="sepetToggleBtn" onclick="toggleSepet()" style="position: relative;">
                    🛒 Sepet
                    <span class="sepet-badge" id="sepetBadge" style="display: none;">0</span>
                </button>
                <button class="btn btn-secondary" onclick="window.location.href='Fire-Zayi.php'">← Geri Dön</button>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="main-layout-container" id="mainLayoutContainer">
                <div class="main-content-left">
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
                                   id="productSearch" 
                                   placeholder="Ürün kodu veya adına göre ara..." 
                                   onkeyup="if(event.key==='Enter') loadProducts()">
                            <button class="btn btn-secondary" onclick="loadProducts()">🔍</button>
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>Depo</th>
                                    <th>Fire Miktarı</th>
                                    <th>Zayi Miktarı</th>
                                    <th>Birim</th>
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

                    <div class="pagination">
                        <button class="btn btn-secondary" id="prevBtn" onclick="changePage(-1)" disabled>← Önceki</button>
                        <span id="pageInfo">Sayfa 1</span>
                        <button class="btn btn-secondary" id="nextBtn" onclick="changePage(1)" disabled>Sonraki →</button>
                    </div>
                </div>
            </section>

                </div>

                <!-- Sepet Panel (Sağ Taraf) -->
                <div class="main-content-right sepet-panel" id="sepetPanel">
                    <div class="card">
                        <div class="card-header">
                            <h3>Sepet</h3>
                        </div>
                        <div class="card-body">
                            <div id="cartTableBody">
                                <div class="empty-message">Sepet boş - Ürün seçiniz</div>
                            </div>
                            <!-- Butonlar Sepet Altında -->
                            <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; padding-top: 24px; border-top: 2px solid #f3f4f6;">
                                <button type="button" class="btn btn-secondary" onclick="window.location.href='Fire-Zayi.php'">İptal</button>
                                <button type="button" class="btn btn-primary" id="saveBtn" disabled onclick="saveFireZayi()">Kaydet</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Fire ve Zayi depo bilgileri (PHP'den aktarılan)
        const fireWarehouse = <?= json_encode($fireWarehouse) ?>;
        const fireWarehouseName = <?= json_encode($fireWarehouseName) ?>;
        const zayiWarehouse = <?= json_encode($zayiWarehouse) ?>;
        const zayiWarehouseName = <?= json_encode($zayiWarehouseName) ?>;
        
        // Çıkış depo bilgilerini saklamak için
        let fromWarehouseName = '';
        
        // Debug: Depo bilgilerini kontrol et
        console.log('Fire Warehouse:', fireWarehouse, fireWarehouseName);
        console.log('Zayi Warehouse:', zayiWarehouse, zayiWarehouseName);
        
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
                // Depo adını sakla
                if (text.includes(' - ')) {
                    fromWarehouseName = text.split(' - ').slice(1).join(' - ');
                } else {
                    fromWarehouseName = '';
                }
                handleFromWarehouseChange(value);
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
        const saveBtn = document.getElementById('saveBtn');
        const form = document.getElementById('fireZayiForm');

        function handleFromWarehouseChange(value) {
            if (value) {
                document.getElementById('productListSection').style.display = 'block';
                loadProducts();
            } else {
                document.getElementById('productListSection').style.display = 'none';
            }
            updateSaveButton();
        }

        // Form validasyonu - Kaydet butonunu aktif/pasif yap
        function updateSaveButton() {
            const fromWarehouse = fromWarehouseInput.value;
            
            if (fromWarehouse && cart.length > 0) {
                saveBtn.disabled = false;
            } else {
                saveBtn.disabled = true;
            }
        }

        let productList = [];
        let cart = [];
        let currentPage = 0;
        let hasMore = false;
        let pageSize = 25;

        // Sayfa boyutunu güncelle
        function updatePageSize() {
            pageSize = parseInt(document.getElementById('entriesPerPage').value) || 25;
            currentPage = 0;
            loadProducts();
        }

        // Sayfa değiştir
        function changePage(delta) {
            if (delta > 0 && hasMore) {
                currentPage++;
                loadProducts();
            } else if (delta < 0 && currentPage > 0) {
                currentPage--;
                loadProducts();
            }
        }

        // Pagination bilgisini güncelle
        function updatePagination() {
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const pageInfo = document.getElementById('pageInfo');
            
            prevBtn.disabled = currentPage === 0;
            nextBtn.disabled = !hasMore;
            pageInfo.textContent = `Sayfa ${currentPage + 1}`;
        }

        // Ürün listesini yükle
        function loadProducts() {
            const warehouseCode = fromWarehouseInput.value;
            if (!warehouseCode) {
                const tbody = document.getElementById('productTableBody');
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #6b7280;">Önce çıkış deposunu seçiniz</td></tr>';
                return;
            }

            const tbody = document.getElementById('productTableBody');
            tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px;">Yükleniyor...</td></tr>';

            const search = document.getElementById('productSearch').value.trim();
            const skip = currentPage * pageSize;

            const params = new URLSearchParams({
                ajax: 'items',
                warehouseCode: warehouseCode,
                top: pageSize,
                skip: skip
            });

            if (search) {
                params.append('search', search);
            }

            fetch(`?${params.toString()}`)
                .then(res => res.json())
                .then(data => {
                    productList = data.data || [];
                    hasMore = (data.count || 0) >= pageSize;
                    renderProductTable();
                    updatePagination();
                })
                .catch(err => {
                    console.error('Hata:', err);
                    tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #dc2626;">Yükleme hatası</td></tr>';
                });
        }

        // Ürün tablosunu render et
        function renderProductTable() {
            const tbody = document.getElementById('productTableBody');

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

                // Fire ve Zayi miktar input'ları her zaman aktif
                return `
                    <tr data-item-code="${itemCode}" data-item-index="${index}">
                        <td><strong>${itemCode}</strong></td>
                        <td>${itemName}</td>
                        <td>${whsCode}</td>
                        <td>
                            <div class="quantity-controls">
                                <button type="button" class="qty-btn" onclick="changeItemQuantity('${itemCode}', 'fire', -1)">−</button>
                                <input type="number" 
                                       class="qty-input" 
                                       id="fireQty-${itemCode}" 
                                       data-item-code="${itemCode}"
                                       data-type="fire"
                                       min="0"
                                       step="0.01" 
                                       value="0"
                                       oninput="if(this.value < 0) this.value = 0;"
                                       onchange="updateItemQuantity('${itemCode}', 'fire', this.value)">
                                <button type="button" class="qty-btn" onclick="changeItemQuantity('${itemCode}', 'fire', 1)">+</button>
                            </div>
                        </td>
                        <td>
                            <div class="quantity-controls">
                                <button type="button" class="qty-btn" onclick="changeItemQuantity('${itemCode}', 'zayi', -1)">−</button>
                                <input type="number" 
                                       class="qty-input" 
                                       id="zayiQty-${itemCode}" 
                                       data-item-code="${itemCode}"
                                       data-type="zayi"
                                       min="0"
                                       step="0.01" 
                                       value="0"
                                       oninput="if(this.value < 0) this.value = 0;"
                                       onchange="updateItemQuantity('${itemCode}', 'zayi', this.value)">
                                <button type="button" class="qty-btn" onclick="changeItemQuantity('${itemCode}', 'zayi', 1)">+</button>
                            </div>
                        </td>
                        <td>${uomHtml}</td>
                        <td>
                            <button class="btn btn-primary btn-small" 
                                    onclick="addToCart('${itemCode}', ${index})">
                                Sepete Ekle
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }


        // Miktar değiştir (+ ve - butonları)
        function changeItemQuantity(itemCode, type, delta) {
            const input = document.getElementById(`${type}Qty-${itemCode}`);
            if (!input || input.disabled) return;
            
            let value = parseFloat(input.value) || 0;
            value += delta;
            if (value < 0) value = 0;
            // Tam sayı ise tam sayı olarak göster, değilse virgüllü göster
            input.value = value % 1 === 0 ? value.toString() : value.toFixed(2);
            updateItemQuantity(itemCode, type, value);
        }

        // Miktar güncelle
        function updateItemQuantity(itemCode, type, value) {
            // Negatif değer kontrolü
            const input = document.getElementById(`${type}Qty-${itemCode}`);
            if (input) {
                let numValue = parseFloat(value) || 0;
                if (numValue < 0) {
                    numValue = 0;
                }
                // Tam sayı ise tam sayı olarak göster, değilse virgüllü göster
                input.value = numValue % 1 === 0 ? numValue.toString() : numValue.toFixed(2);
            }
        }

        // Ürün arama
        function searchProducts() {
            currentPage = 0;
            loadProducts();
        }

        // Sepete ekle
        function addToCart(itemCode, index) {
            const item = productList[index];
            if (!item || item.ItemCode !== itemCode) return;

            const fireQty = parseFloat(document.getElementById(`fireQty-${itemCode}`)?.value || 0);
            const zayiQty = parseFloat(document.getElementById(`zayiQty-${itemCode}`)?.value || 0);
            
            // Negatif değer kontrolü
            if (fireQty < 0 || zayiQty < 0) {
                alert('Negatif değer giremezsiniz. Lütfen 0 veya pozitif bir değer giriniz.');
                return;
            }
            
            // Fire veya Zayi miktarından en az biri 0'dan büyük olmalı
            if (fireQty <= 0 && zayiQty <= 0) {
                alert('Lütfen Fire veya Zayi miktarı giriniz');
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

            // Fire ve Zayi'yi ayrı cart item'ları olarak ekle
            // Eğer Fire miktarı varsa (pozitif veya negatif), Fire için ayrı bir item oluştur
            if (fireQty !== 0) {
                const fireCartItem = {
                    ItemCode: item.ItemCode,
                    ItemName: item.ItemName,
                    FromWarehouse: fromWarehouseInput.value,
                    UoMEntry: uomEntry,
                    UoMCode: uomCode,
                    BaseQty: baseQty,
                    Type: 'fire',
                    Quantity: fireQty,
                    UnitPrice: 0
                };
                
                // Aynı ürün ve tip sepette var mı kontrol et
                const existingFireIndex = cart.findIndex(c => 
                    c.ItemCode === itemCode && 
                    c.UoMCode === uomCode && 
                    c.Type === 'fire'
                );
                
                if (existingFireIndex >= 0) {
                    cart[existingFireIndex] = fireCartItem;
                } else {
                    cart.push(fireCartItem);
                }
            }
            
            // Eğer Zayi miktarı varsa (pozitif veya negatif), Zayi için ayrı bir item oluştur
            if (zayiQty !== 0) {
                const zayiCartItem = {
                    ItemCode: item.ItemCode,
                    ItemName: item.ItemName,
                    FromWarehouse: fromWarehouseInput.value,
                    UoMEntry: uomEntry,
                    UoMCode: uomCode,
                    BaseQty: baseQty,
                    Type: 'zayi',
                    Quantity: zayiQty,
                    UnitPrice: 0
                };
                
                // Aynı ürün ve tip sepette var mı kontrol et
                const existingZayiIndex = cart.findIndex(c => 
                    c.ItemCode === itemCode && 
                    c.UoMCode === uomCode && 
                    c.Type === 'zayi'
                );
                
                if (existingZayiIndex >= 0) {
                    cart[existingZayiIndex] = zayiCartItem;
                } else {
                    cart.push(zayiCartItem);
                }
            }

            // Input'ları sıfırla
            const fireInput = document.getElementById(`fireQty-${itemCode}`);
            const zayiInput = document.getElementById(`zayiQty-${itemCode}`);
            if (fireInput) fireInput.value = '0';
            if (zayiInput) zayiInput.value = '0';

            updateCartTable();
            updateSaveButton();
            // Sepeti otomatik aç
            if (cart.length > 0) {
                const panel = document.getElementById('sepetPanel');
                const container = document.getElementById('mainLayoutContainer');
                if (panel.style.display === 'none' || !container.classList.contains('sepet-open')) {
                    panel.style.display = 'flex';
                    container.classList.add('sepet-open');
                }
            }
        }

        // Sepeti render et (Card-based yapı - Fire ve Zayi grupları ile)
        function updateCartTable() {
            const cartBody = document.getElementById('cartTableBody');
            
            if (cart.length === 0) {
                cartBody.innerHTML = '<div class="empty-message">Sepet boş - Ürün seçiniz</div>';
                updateCartBadge();
                return;
            }

            cartBody.innerHTML = '';

            // Fire ve Zayi item'larını ayır
            const fireItems = cart.filter(item => item.Type === 'fire');
            const zayiItems = cart.filter(item => item.Type === 'zayi');

            // FIRE başlığı ve item'ları
            if (fireItems.length > 0) {
                const fireHeader = document.createElement('div');
                fireHeader.className = 'sepet-group-header';
                fireHeader.style.cssText = 'font-size: 1.1rem; font-weight: 600; color: #dc2626; margin: 1rem 0 0.5rem 0; padding-bottom: 0.5rem; border-bottom: 2px solid #dc2626;';
                fireHeader.textContent = '🔥 FIRE';
                cartBody.appendChild(fireHeader);

                fireItems.forEach((item, fireIndex) => {
                    const originalIndex = cart.indexOf(item);
                    const qty = parseFloat(item.Quantity || 0);
                    const qtyText = `${qty.toFixed(2)} adet eksik fire`;
                    
                    // Çıkış depo bilgisini oluştur
                    const fromWarehouseDisplay = fromWarehouseName 
                        ? `${item.FromWarehouse} - ${fromWarehouseName}`
                        : item.FromWarehouse;
                    
                    // Gideceği depo bilgisini oluştur
                    const targetWarehouseDisplay = fireWarehouseName 
                        ? `${fireWarehouse} - ${fireWarehouseName}`
                        : fireWarehouse;

                    const sepetItem = document.createElement('div');
                    sepetItem.className = 'sepet-item';
                    sepetItem.setAttribute('data-item-code', item.ItemCode);
                    sepetItem.setAttribute('data-type', 'fire');
                    
                    sepetItem.innerHTML = `
                        <div class="sepet-item-info">
                            <div class="sepet-item-name">${item.ItemCode} - ${item.ItemName}</div>
                            <div class="sepet-item-details">Çıkış: ${fromWarehouseDisplay}</div>
                            <div class="sepet-item-details">Gideceği: Fire: ${targetWarehouseDisplay}</div>
                            <div class="sepet-item-details">Birim: ${item.UoMCode || 'AD'}</div>
                            <div class="sepet-item-qty">
                                <label style="font-size: 0.85rem; color: #6b7280; min-width: 60px;">Fire:</label>
                                <button type="button" class="qty-btn" onclick="changeCartQuantity(${originalIndex}, 'fire', -1)">−</button>
                                <input type="number" 
                                       class="qty-input" 
                                       value="${qty === 0 ? '0' : (qty % 1 === 0 ? qty.toString() : qty.toFixed(2))}"
                                       min="0"
                                       step="0.01"
                                       oninput="if(this.value < 0) this.value = 0;"
                                       onchange="updateCartQuantity(${originalIndex}, 'fire', this.value)">
                                <button type="button" class="qty-btn" onclick="changeCartQuantity(${originalIndex}, 'fire', 1)">+</button>
                                <span style="margin-left: 8px; font-size: 0.85rem; color: #dc2626; font-weight: 500;">${qtyText}</span>
                            </div>
                        </div>
                        <button class="remove-sepet-btn" onclick="removeFromCart(${originalIndex})">Kaldır</button>
                    `;
                    cartBody.appendChild(sepetItem);
                });
            }

            // ZAYI başlığı ve item'ları
            if (zayiItems.length > 0) {
                const zayiHeader = document.createElement('div');
                zayiHeader.className = 'sepet-group-header';
                zayiHeader.style.cssText = 'font-size: 1.1rem; font-weight: 600; color: #f59e0b; margin: 1rem 0 0.5rem 0; padding-bottom: 0.5rem; border-bottom: 2px solid #f59e0b;';
                zayiHeader.textContent = '⚠️ ZAYI';
                cartBody.appendChild(zayiHeader);

                zayiItems.forEach((item, zayiIndex) => {
                    const originalIndex = cart.indexOf(item);
                    const qty = parseFloat(item.Quantity || 0);
                    const qtyText = `${qty.toFixed(2)} adet eksik zayi`;
                    
                    // Çıkış depo bilgisini oluştur
                    const fromWarehouseDisplay = fromWarehouseName 
                        ? `${item.FromWarehouse} - ${fromWarehouseName}`
                        : item.FromWarehouse;
                    
                    // Gideceği depo bilgisini oluştur
                    const targetWarehouseDisplay = zayiWarehouseName 
                        ? `${zayiWarehouse} - ${zayiWarehouseName}`
                        : zayiWarehouse;

                    const sepetItem = document.createElement('div');
                    sepetItem.className = 'sepet-item';
                    sepetItem.setAttribute('data-item-code', item.ItemCode);
                    sepetItem.setAttribute('data-type', 'zayi');
                    
                    sepetItem.innerHTML = `
                        <div class="sepet-item-info">
                            <div class="sepet-item-name">${item.ItemCode} - ${item.ItemName}</div>
                            <div class="sepet-item-details">Çıkış: ${fromWarehouseDisplay}</div>
                            <div class="sepet-item-details">Gideceği: Zayi: ${targetWarehouseDisplay}</div>
                            <div class="sepet-item-details">Birim: ${item.UoMCode || 'AD'}</div>
                            <div class="sepet-item-qty">
                                <label style="font-size: 0.85rem; color: #6b7280; min-width: 60px;">Zayi:</label>
                                <button type="button" class="qty-btn" onclick="changeCartQuantity(${originalIndex}, 'zayi', -1)">−</button>
                                <input type="number" 
                                       class="qty-input" 
                                       value="${qty === 0 ? '0' : (qty % 1 === 0 ? qty.toString() : qty.toFixed(2))}"
                                       min="0"
                                       step="0.01"
                                       oninput="if(this.value < 0) this.value = 0;"
                                       onchange="updateCartQuantity(${originalIndex}, 'zayi', this.value)">
                                <button type="button" class="qty-btn" onclick="changeCartQuantity(${originalIndex}, 'zayi', 1)">+</button>
                                <span style="margin-left: 8px; font-size: 0.85rem; color: #f59e0b; font-weight: 500;">${qtyText}</span>
                            </div>
                        </div>
                        <button class="remove-sepet-btn" onclick="removeFromCart(${originalIndex})">Kaldır</button>
                    `;
                    cartBody.appendChild(sepetItem);
                });
            }
            
            updateCartBadge();
        }

        // Sepette miktar değiştir
        function changeCartQuantity(index, type, delta) {
            if (index >= 0 && index < cart.length) {
                const item = cart[index];
                if (item.Type === type) {
                    let newQty = parseFloat(item.Quantity || 0) + delta;
                    if (newQty < 0) newQty = 0;
                    item.Quantity = newQty;
                    updateCartTable();
                    updateSaveButton();
                }
            }
        }

        // Sepette miktar güncelle
        function updateCartQuantity(index, type, value) {
            if (index >= 0 && index < cart.length) {
                const item = cart[index];
                if (item.Type === type) {
                    let qty = parseFloat(value) || 0;
                    if (qty < 0) {
                        qty = 0;
                        // Input'u da güncelle
                        const input = event?.target;
                        if (input) input.value = '0';
                    }
                    item.Quantity = qty;
                    updateCartTable();
                    updateSaveButton();
                }
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

        // Fire/Zayi belgesi kaydet
        function saveFireZayi() {
            if (cart.length === 0) {
                alert('Lütfen sepete en az bir ürün ekleyiniz');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('fromWarehouse', fromWarehouseInput.value);
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
                    // Her zaman liste sayfasına yönlendir
                    window.location.href = 'Fire-Zayi.php';
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
        }


        // Sepet panel toggle
        function toggleSepet() {
            const panel = document.getElementById('sepetPanel');
            const container = document.getElementById('mainLayoutContainer');
            
            if (panel.style.display === 'none' || !container.classList.contains('sepet-open')) {
                panel.style.display = 'flex';
                container.classList.add('sepet-open');
            } else {
                panel.style.display = 'none';
                container.classList.remove('sepet-open');
            }
        }

        // Sepet badge güncelle
        function updateCartBadge() {
            const badge = document.getElementById('sepetBadge');
            const count = cart.length;
            badge.textContent = count;
            badge.style.display = count > 0 ? 'flex' : 'none';
        }

        // Sayfa yüklendiğinde scroll pozisyonunu sıfırla (navbar kaymasını önle)
        document.addEventListener('DOMContentLoaded', function() {
            window.scrollTo(0, 0);
            updateCartBadge();
            
            // Çıkış depo seçiliyse adını set et
            const fromWarehouseInputText = document.getElementById('fromWarehouseInputText');
            if (fromWarehouseInputText && fromWarehouseInputText.value && fromWarehouseInputText.value !== 'Depo seçiniz') {
                const optionText = fromWarehouseInputText.value;
                if (optionText.includes(' - ')) {
                    fromWarehouseName = optionText.split(' - ').slice(1).join(' - ');
                }
            }
        });
    </script>
</body>
</html>