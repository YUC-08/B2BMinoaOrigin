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
// Branch bilgisini doğru şekilde al (Branch2["Code"] veya WhsCode)
$branch = $_SESSION["Branch2"]["Code"] ?? $_SESSION["WhsCode"] ?? $_SESSION["Branch2"]["Name"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// Mod kontrolü: Güncelle modu mu?
$documentEntry = isset($_GET['DocumentEntry']) ? intval($_GET['DocumentEntry']) : null;
$isUpdateMode = !empty($documentEntry);
$continue = isset($_GET['continue']) && $_GET['continue'] == '1';

// Güncelle modunda: Mevcut sayım belgesini çek
$existingCounting = null;
$existingLines = [];
$existingWarehouse = '';

if ($isUpdateMode) {
    // Header'ı çek (expand çalışmıyor ama header response'unda InventoryCountingLines var)
    $countingQuery = "InventoryCountings({$documentEntry})";
    $countingData = $sap->get($countingQuery);
    
    if (($countingData['status'] ?? 0) == 200) {
        $existingCounting = $countingData['response'] ?? $countingData;
        
        // Header response'undan InventoryCountingLines'ı al
        if (isset($existingCounting['InventoryCountingLines']) && is_array($existingCounting['InventoryCountingLines'])) {
            $existingLines = $existingCounting['InventoryCountingLines'];
            
            // LineNumber'ı koru (SAP B1SL'de LineNumber kullanılıyor, LineNum değil)
            // LineNum eklemeye gerek yok, direkt LineNumber kullanacağız
            
            // İlk satırdan WarehouseCode'u al
            if (!empty($existingLines) && isset($existingLines[0]['WarehouseCode'])) {
                $existingWarehouse = $existingLines[0]['WarehouseCode'];
            }
        }
        
        // Eğer hala boşsa, direkt collection path'i dene
        if (empty($existingLines)) {
            $linesQuery = "InventoryCountings({$documentEntry})/InventoryCountingLines";
            $linesData = $sap->get($linesQuery);
            
            if (($linesData['status'] ?? 0) == 200) {
                $linesResponse = $linesData['response'] ?? $linesData;
                
                // Farklı response yapılarını kontrol et
                if (isset($linesResponse['value']) && is_array($linesResponse['value'])) {
                    $existingLines = $linesResponse['value'];
                } elseif (isset($linesResponse['InventoryCountingLines']) && is_array($linesResponse['InventoryCountingLines'])) {
                    $existingLines = $linesResponse['InventoryCountingLines'];
                } elseif (is_array($linesResponse)) {
                    $existingLines = $linesResponse;
                }
                
                // İlk satırdan WarehouseCode'u al
                if (!empty($existingLines) && isset($existingLines[0]['WarehouseCode'])) {
                    $existingWarehouse = $existingLines[0]['WarehouseCode'];
                }
            }
        }
    }
}

// Warehouses listesi (Depo combobox için) - U_AS_OWNR ve U_ASB2B_BRAN ile filtrele
$warehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}'";
$warehouseQuery = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=" . urlencode($warehouseFilter) . "&\$orderby=WarehouseCode";
$warehouseData = $sap->get($warehouseQuery);
$warehouses = [];
if (($warehouseData['status'] ?? 0) == 200) {
    if (isset($warehouseData['response']['value'])) {
        $warehouses = $warehouseData['response']['value'];
    } elseif (isset($warehouseData['value'])) {
        $warehouses = $warehouseData['value'];
    }
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
    $viewCheckStatus = $viewCheck['status'] ?? 'NO STATUS';
    $viewCheckError = $viewCheck['response']['error'] ?? null;
    $isViewExposed = true;
    $exposeAttempted = false;
    
    // View expose edilmemişse (806 hatası), expose et
    if (isset($viewCheckError['code']) && $viewCheckError['code'] === '806') {
        $isViewExposed = false;
        $exposeAttempted = true;
        $exposeResult = $sap->post("SQLViews('ASB2B_InventoryWhsItem_B1SLQuery')/Expose", []);
        $exposeStatus = $exposeResult['status'] ?? 'NO STATUS';
        
        // Expose başarısızsa hata döndür
        if ($exposeStatus != 200 && $exposeStatus != 201 && $exposeStatus != 204) {
            echo json_encode([
                'data' => [],
                'count' => 0,
                'error' => 'View expose edilemedi!',
                'debug' => [
                    'exposeStatus' => $exposeStatus,
                    'exposeError' => $exposeResult['response']['error'] ?? null
                ]
            ]);
            exit;
        }
        
        // Expose başarılı, kısa bir bekleme sonrası tekrar kontrol et
        sleep(1);
        $viewCheck2 = $sap->get($viewCheckQuery);
        if (isset($viewCheck2['response']['error']['code']) && $viewCheck2['response']['error']['code'] === '806') {
            echo json_encode([
                'data' => [],
                'count' => 0,
                'error' => 'View expose edildi ancak hala erişilemiyor!'
            ]);
            exit;
        }
    }
    
    // Önce view'den bir örnek kayıt çekip property'leri görelim
    $sampleQuery = "view.svc/ASB2B_InventoryWhsItem_B1SLQuery?\$top=1";
    $sampleData = $sap->get($sampleQuery);
    $sampleItem = null;
    $availableProperties = [];
    
    if (($sampleData['status'] ?? 0) == 200) {
        if (isset($sampleData['response']['value']) && !empty($sampleData['response']['value'])) {
            $sampleItem = $sampleData['response']['value'][0];
            $availableProperties = is_array($sampleItem) ? array_keys($sampleItem) : [];
        } elseif (isset($sampleData['value']) && !empty($sampleData['value'])) {
            $sampleItem = $sampleData['value'][0];
            $availableProperties = is_array($sampleItem) ? array_keys($sampleItem) : [];
        }
    }
    
    // Debug: View'deki mevcut property'leri göster
    $debugInfo = [
        'warehouseCode' => $warehouseCode,
        'viewCheckStatus' => $viewCheckStatus,
        'isViewExposed' => $isViewExposed,
        'exposeAttempted' => $exposeAttempted,
        'sampleItem' => $sampleItem,
        'availableProperties' => $availableProperties,
        'sampleQuery' => $sampleQuery
    ];
    
    // WarehouseCode yerine doğru property adını bul
    // Muhtemelen WhsCode, Warehouse, veya başka bir isim olabilir
    $warehouseProperty = null;
    if ($sampleItem) {
        // Olası property isimlerini kontrol et
        $possibleNames = ['WarehouseCode', 'WhsCode', 'Warehouse', 'WarehouseName', 'WhsName'];
        foreach ($possibleNames as $name) {
            if (isset($sampleItem[$name])) {
                $warehouseProperty = $name;
                break;
            }
        }
    }
    
    // Eğer property bulunamazsa, tüm property'leri göster
    if (!$warehouseProperty) {
        echo json_encode([
            'data' => [],
            'count' => 0,
            'error' => 'Warehouse property bulunamadı!',
            'debug' => $debugInfo
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
        // Hata durumunda mesaj al
        $errorMsg = $itemsData['response']['error']['message']['value'] ?? $itemsData['response']['error']['message'] ?? 'Bilinmeyen hata';
        $debugInfo['query'] = $itemsQuery;
        $debugInfo['responseStatus'] = $itemsData['status'] ?? 'NO STATUS';
        $debugInfo['responseError'] = $itemsData['response']['error'] ?? null;
    }
    
    // Hata varsa döndür
    if ($errorMsg) {
        echo json_encode([
            'data' => [],
            'count' => 0,
            'error' => $errorMsg,
            'debug' => $debugInfo
        ]);
        exit;
    }
    
    // Debug bilgisini response'a ekle - DETAYLI
    $debugInfo['warehouseProperty'] = $warehouseProperty;
    $debugInfo['filter'] = $filter;
    $debugInfo['query'] = $itemsQuery;
    $debugInfo['queryEncoded'] = urlencode($filter);
    $debugInfo['itemsCount'] = count($items);
    $debugInfo['skip'] = $skip;
    $debugInfo['top'] = $top;
    $debugInfo['search'] = $search;
    $debugInfo['responseStatus'] = $itemsData['status'] ?? 'NO STATUS';
    
    // ItemCode'ları listele (90231 ve 90232 kontrolü için)
    $itemCodes = [];
    $itemCodesDetailed = [];
    foreach ($items as $item) {
        $code = $item['ItemCode'] ?? '';
        if (!empty($code)) {
            $itemCodes[] = $code;
            $itemCodesDetailed[] = [
                'ItemCode' => $code,
                'ItemName' => $item['ItemName'] ?? '',
                'Warehouse' => $item[$warehouseProperty] ?? '',
                'OnHand' => $item['OnHand'] ?? '',
                'IsCommited' => $item['IsCommited'] ?? '',
                'OnOrder' => $item['OnOrder'] ?? ''
            ];
        }
    }
    $debugInfo['itemCodes'] = $itemCodes;
    $debugInfo['itemCodesDetailed'] = $itemCodesDetailed;
    $debugInfo['has90231'] = in_array('90231', $itemCodes);
    $debugInfo['has90232'] = in_array('90232', $itemCodes);
    $debugInfo['count90231'] = count(array_filter($itemCodes, function($c) { return $c === '90231'; }));
    $debugInfo['rawResponse'] = $itemsData['response'] ?? null;
    
    // Duplicate'leri temizle (ItemCode bazında)
    $seenItemCodes = [];
    $uniqueItems = [];
    foreach ($items as $item) {
        $itemCode = $item['ItemCode'] ?? '';
        if (!empty($itemCode) && !isset($seenItemCodes[$itemCode])) {
            $seenItemCodes[$itemCode] = true;
            $uniqueItems[] = $item;
        }
    }
    $items = $uniqueItems;
    $debugInfo['itemsAfterDedup'] = count($items);
    $debugInfo['duplicatesRemoved'] = count($itemsData['response']['value'] ?? []) - count($items);
    
    // Her item için UoM bilgilerini çek
    $processedItems = [];
    $debugInfo['uomErrors'] = [];
    foreach ($items as $item) {
        $itemCode = $item['ItemCode'] ?? '';
        if (empty($itemCode)) {
            // ItemCode boşsa bile ekle
            $processedItems[] = $item;
            $debugInfo['uomErrors'][] = [
                'ItemCode' => '',
                'error' => 'ItemCode boş',
                'status' => 'N/A'
            ];
            continue;
        }
        
        $itemDetailQuery = "Items('{$itemCode}')?\$select=ItemCode,InventoryUOM,UoMGroupEntry";
        $itemDetailData = $sap->get($itemDetailQuery);
        
        // Debug: Her item için sorgu sonucunu logla
        $debugInfo['itemDetailQueries'][] = [
            'ItemCode' => $itemCode,
            'query' => $itemDetailQuery,
            'status' => $itemDetailData['status'] ?? 'NO STATUS',
            'hasResponse' => !empty($itemDetailData['response']),
            'error' => $itemDetailData['response']['error'] ?? null,
            'response' => $itemDetailData['response'] ?? null
        ];
        
        if (($itemDetailData['status'] ?? 0) == 200) {
            $itemDetail = $itemDetailData['response'] ?? $itemDetailData;
            $item['InventoryUOM'] = $itemDetail['InventoryUOM'] ?? '';
            $item['UoMGroupEntry'] = $itemDetail['UoMGroupEntry'] ?? '';
            
            // UoM listesini çek
            if (!empty($itemDetail['UoMGroupEntry'])) {
                // Direkt collection path kullan (expand yok)
                $uomGroupQuery = "UoMGroups({$itemDetail['UoMGroupEntry']})/UoMGroupDefinitionCollection";
                $uomGroupData = $sap->get($uomGroupQuery);
                if (($uomGroupData['status'] ?? 0) == 200) {
                    $uomGroupResponse = $uomGroupData['response'] ?? $uomGroupData;
                    $uomList = [];
                    
                    // Farklı response yapılarını kontrol et
                    $collection = [];
                    if (isset($uomGroupResponse['value']) && is_array($uomGroupResponse['value'])) {
                        $collection = $uomGroupResponse['value'];
                    } elseif (isset($uomGroupResponse['UoMGroupDefinitionCollection']) && is_array($uomGroupResponse['UoMGroupDefinitionCollection'])) {
                        $collection = $uomGroupResponse['UoMGroupDefinitionCollection'];
                    } elseif (is_array($uomGroupResponse) && !isset($uomGroupResponse['error'])) {
                        $collection = $uomGroupResponse;
                    }
                    
                    if (!empty($collection)) {
                        foreach ($collection as $uomDef) {
                            // Farklı property adlarını kontrol et
                            $uomEntry = $uomDef['UoMEntry'] ?? $uomDef['AlternateUoM'] ?? $uomDef['UoMEntry'] ?? '';
                            $uomCode = $uomDef['UoMCode'] ?? $uomDef['UoMCode'] ?? '';
                            $baseQty = $uomDef['BaseQty'] ?? $uomDef['BaseQuantity'] ?? 1;
                            
                            // UoMEntry boş değilse ekle
                            if (!empty($uomEntry)) {
                                $uomList[] = [
                                    'UoMEntry' => $uomEntry,
                                    'UoMCode' => $uomCode,
                                    'BaseQty' => $baseQty
                                ];
                            }
                        }
                    }
                    
                    // Eğer UoMList boşsa ve InventoryUOM varsa, InventoryUOM'u kullan
                    if (empty($uomList) && !empty($itemDetail['InventoryUOM'])) {
                        $inventoryUOM = $itemDetail['InventoryUOM'];
                        // Collection'dan InventoryUOM ile eşleşeni bul
                        if (!empty($collection)) {
                            foreach ($collection as $uomDef) {
                                $uomCode = $uomDef['UoMCode'] ?? '';
                                $uomEntry = $uomDef['UoMEntry'] ?? '';
                                // InventoryUOM ile eşleşen veya herhangi bir geçerli UoMEntry bul
                                if (!empty($uomEntry) && ($uomCode === $inventoryUOM || empty($uomList))) {
                                    $uomList[] = [
                                        'UoMEntry' => $uomEntry,
                                        'UoMCode' => $uomCode ?: $inventoryUOM,
                                        'BaseQty' => $uomDef['BaseQty'] ?? 1
                                    ];
                                    break;
                                }
                            }
                        }
                    }
                    
                    $item['UoMList'] = $uomList;
                }
            }
            
            // Item'ı processedItems'e ekle (başarılı olsun olmasın)
            $processedItems[] = $item;
        } else {
            // UoM bilgisi çekilemedi ama item'ı ekle
            $processedItems[] = $item;
            $errorDetails = [
                'ItemCode' => $itemCode,
                'error' => 'Items() sorgusu başarısız',
                'status' => $itemDetailData['status'] ?? 'NO STATUS',
                'query' => $itemDetailQuery,
                'response' => $itemDetailData['response'] ?? null,
                'errorMessage' => isset($itemDetailData['response']['error']['message']['value']) 
                    ? $itemDetailData['response']['error']['message']['value'] 
                    : (isset($itemDetailData['response']['error']['message']) 
                        ? $itemDetailData['response']['error']['message'] 
                        : 'Bilinmeyen hata')
            ];
            $debugInfo['uomErrors'][] = $errorDetails;
            
            // Özel log: 90232 için detaylı bilgi
            if ($itemCode === '90232') {
                error_log("=== 90232 UoM SORGUSU BAŞARISIZ ===");
                error_log("Query: " . $itemDetailQuery);
                error_log("Status: " . ($itemDetailData['status'] ?? 'NULL'));
                error_log("Response: " . json_encode($itemDetailData['response'] ?? []));
                error_log("Error: " . json_encode($itemDetailData['response']['error'] ?? []));
            }
        }
    }
    
    // Processed items'ı kullan
    $items = $processedItems;
    $debugInfo['itemsAfterUoM'] = count($items);
    
    // Debug: UoMList'leri kontrol et
    $debugInfo['uomListCheck'] = [];
    foreach ($items as $item) {
        $debugInfo['uomListCheck'][] = [
            'ItemCode' => $item['ItemCode'] ?? '',
            'UoMGroupEntry' => $item['UoMGroupEntry'] ?? '',
            'InventoryUOM' => $item['InventoryUOM'] ?? '',
            'UoMList' => $item['UoMList'] ?? [],
            'UoMListCount' => count($item['UoMList'] ?? [])
        ];
    }
    
    echo json_encode([
        'data' => $items, 
        'count' => count($items),
        'debug' => $debugInfo
    ]);
    exit;
}

// POST: Yeni sayım oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    header('Content-Type: application/json');
    
    $warehouseCode = trim($_POST['warehouseCode'] ?? '');
    $countDate = trim($_POST['countDate'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $lines = isset($_POST['lines']) ? json_decode($_POST['lines'], true) : [];
    
    if (empty($warehouseCode) || empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'Depo ve en az bir kalem gereklidir']);
        exit;
    }
    
    // SAP B1SL'de InventoryCountings header'da WarehouseCode property'si YOK
    // Warehouse bilgisi sadece satırlarda (InventoryCountingLines) olur
    $payload = [
        'CountDate' => $countDate ?: date('Y-m-d'),
        'U_AS_OWNR' => $uAsOwnr, // Ana listeye düşmesi için U_AS_OWNR set edilmeli
        'InventoryCountingLines' => []
    ];
    
    if (!empty($remarks)) {
        $payload['Remarks'] = $remarks;
    }
    
    foreach ($lines as $line) {
        $lineData = [
            'ItemCode' => $line['ItemCode'] ?? '',
            'WarehouseCode' => $warehouseCode,
            'CountedQuantity' => floatval($line['CountedQuantity'] ?? 0)
        ];
        
        $hasUoMEntry = isset($line['UoMEntry']) && $line['UoMEntry'] !== '' && $line['UoMEntry'] !== null;
        $hasUoMCode = isset($line['UoMCode']) && $line['UoMCode'] !== '' && $line['UoMCode'] !== null;
        
        // Ne UoMEntry ne de UoMCode varsa: hata
        if (!$hasUoMEntry && !$hasUoMCode) {
            echo json_encode([
                'success' => false,
                'message' => "Sayım oluşturulamadı: Ürün '{$line['ItemCode']}' için birim bilgisi (UoMEntry veya UoMCode) bulunamadı.",
                'debug' => [
                    'line' => $line,
                    'allLines' => $lines
                ]
            ]);
            exit;
        }
        
        if ($hasUoMEntry) {
            $lineData['UoMEntry'] = intval($line['UoMEntry']);
        } else {
            // SAP tarafında AD, KT gibi kodlarla zaten çalışabiliyor
            $lineData['UoMCode'] = $line['UoMCode'];
        }
        
        $payload['InventoryCountingLines'][] = $lineData;
    }
    
    $result = $sap->post('InventoryCountings', $payload);
    
    if (($result['status'] ?? 0) == 200 || ($result['status'] ?? 0) == 201) {
        $response = $result['response'] ?? $result;
        $newDocumentEntry = $response['DocumentEntry'] ?? null;
        
        // POST'ta U_AS_OWNR bazen kabul edilmiyor, bu yüzden PATCH ile set ediyoruz
        if ($newDocumentEntry && !empty($uAsOwnr)) {
            $patchPayload = [
                'U_AS_OWNR' => $uAsOwnr
            ];
            $patchResult = $sap->patch("InventoryCountings({$newDocumentEntry})", $patchPayload);
            
            // PATCH başarısız olsa bile sayım oluşturuldu, sadece U_AS_OWNR set edilemedi
            if (($patchResult['status'] ?? 0) != 200 && ($patchResult['status'] ?? 0) != 204) {
                error_log("U_AS_OWNR set edilemedi (DocumentEntry: {$newDocumentEntry}): " . json_encode($patchResult));
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Sayım başarıyla oluşturuldu', 
            'DocumentEntry' => $newDocumentEntry,
            'debug' => [
                'payload' => $payload,
                'response' => $response,
                'status' => $result['status'] ?? 'NO STATUS',
                'u_as_ownr_patch' => isset($patchResult) ? [
                    'status' => $patchResult['status'] ?? 'NO STATUS',
                    'response' => $patchResult['response'] ?? $patchResult
                ] : 'PATCH yapılmadı'
            ]
        ]);
    } else {
        $error = $result['response']['error']['message']['value'] ?? $result['response']['error']['message'] ?? 'Bilinmeyen hata';
        $errorCode = $result['response']['error']['code'] ?? '';
        
        // Daha açıklayıcı hata mesajları
        if (strpos($error, 'already been added to another open document') !== false) {
            $error = "Bu ürün aynı depoda başka bir açık sayım belgesinde bulunuyor. Önce o sayım belgesini kapatın veya o belgeden bu ürünü kaldırın. Hata: " . $error;
        }
        
        echo json_encode([
            'success' => false, 
            'message' => 'Sayım oluşturulamadı: ' . $error,
            'debug' => [
                'payload' => $payload,
                'responseStatus' => $result['status'] ?? 'NO STATUS',
                'responseError' => $result['response']['error'] ?? null,
                'errorCode' => $errorCode
            ]
        ]);
    }
    exit;
}

// PATCH: Sayımı güncelle
if ($_SERVER['REQUEST_METHOD'] === 'PATCH' && isset($_POST['action']) && $_POST['action'] === 'update') {
    header('Content-Type: application/json');
    
    $warehouseCode = trim($_POST['warehouseCode'] ?? '');
    $countDate = trim($_POST['countDate'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $lines = isset($_POST['lines']) ? json_decode($_POST['lines'], true) : [];
    
    if (empty($warehouseCode) || empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'Depo ve en az bir kalem gereklidir']);
        exit;
    }
    
    // SAP B1SL Update Path'i: InventoryCountingLines'ı güncelle
    $successCount = 0;
    $failureCount = 0;
    $errors = [];
    
    foreach ($lines as $line) {
        $lineNumber = $line['LineNumber'] ?? null;
        if ($lineNumber === null) {
            // Yeni satır: POST ile ekle
            $addPayload = [
                'ItemCode' => $line['ItemCode'] ?? '',
                'WarehouseCode' => $warehouseCode,
                'CountedQuantity' => floatval($line['CountedQuantity'] ?? 0)
            ];
            
            $hasUoMEntry = isset($line['UoMEntry']) && $line['UoMEntry'] !== '' && $line['UoMEntry'] !== null;
            $hasUoMCode = isset($line['UoMCode']) && $line['UoMCode'] !== '' && $line['UoMCode'] !== null;
            
            if ($hasUoMEntry) {
                $addPayload['UoMEntry'] = intval($line['UoMEntry']);
            } else {
                $addPayload['UoMCode'] = $line['UoMCode'];
            }
            
            $addResult = $sap->post("InventoryCountings({$documentEntry})/InventoryCountingLines", $addPayload);
            
            if (($addResult['status'] ?? 0) == 200 || ($addResult['status'] ?? 0) == 201) {
                $successCount++;
            } else {
                $failureCount++;
                $errors[] = "Satır eklenirken hata (ItemCode: {$line['ItemCode']}): " . ($addResult['response']['error']['message']['value'] ?? 'Bilinmeyen hata');
            }
        } else {
            // Mevcut satırı güncelle
            $updatePayload = [
                'CountedQuantity' => floatval($line['CountedQuantity'] ?? 0)
            ];
            
            $patchResult = $sap->patch("InventoryCountings({$documentEntry})/InventoryCountingLines({$lineNumber})", $updatePayload);
            
            if (($patchResult['status'] ?? 0) == 200 || ($patchResult['status'] ?? 0) == 204) {
                $successCount++;
            } else {
                $failureCount++;
                $errors[] = "Satır güncellenirken hata (LineNumber: {$lineNumber}): " . ($patchResult['response']['error']['message']['value'] ?? 'Bilinmeyen hata');
            }
        }
    }
    
    if ($failureCount === 0) {
        echo json_encode([
            'success' => true, 
            'message' => "Sayım başarıyla güncellendi ({$successCount} satır)",
            'debug' => [
                'successCount' => $successCount
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => "Sayım kısmen güncellendi ({$successCount} başarılı, {$failureCount} başarısız)",
            'errors' => $errors,
            'debug' => [
                'successCount' => $successCount,
                'failureCount' => $failureCount
            ]
        ]);
    }
    exit;
}

// Helper function: Date format
function formatDate($dateString) {
    if (empty($dateString)) return '';
    $date = new DateTime($dateString);
    return $date->format('Y-m-d');
}
?>

<!-- HTML Template (Bu bölüm StokSayimDetay.php'nin sonunda yer alır) -->

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isUpdateMode ? 'Sayım Güncelle' : 'Yeni Stok Sayımı' ?> - MINOA</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Modern ve clean tasarım: renkler, spacing, ve arayüz iyileştirmeleri */
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

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
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

        .card-header {
            padding: 0;
            margin-bottom: 1rem;
        }

        .card-header h3 {
            color: #1e40af;
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0;
        }

        .card-body {
            padding: 0;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 13px;
            color: #475569;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input[type="date"],
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group select {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.25s ease;
            background: white;
            width: 100%;
            min-height: 44px;
        }

        .form-group input[type="date"]:focus,
        .form-group input[type="text"]:focus,
        .form-group input[type="number"]:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
            background: #fafbfc;
        }

        .form-group input[readonly],
        .form-group select[readonly] {
            background: #f9fafb;
            cursor: not-allowed;
            color: #6b7280;
            border-color: #e5e7eb;
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
            background: #fafbfc;
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
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
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
            color: #374151;
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

        .btn-success {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            box-shadow: 0 4px 16px rgba(239, 68, 68, 0.4);
            transform: translateY(-2px);
        }

        .btn-small {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        

        .input-small {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 13px;
            width: 90px;
            font-weight: 500;
        }

        .input-small:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .cart-table {
            margin-top: 0;
        }

        .empty-message {
            text-align: center;
            padding: 3rem;
            color: #9ca3af;
            font-size: 14px;
            font-weight: 500;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 32px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .conversion-cell {
            text-align: center;
            font-weight: 600;
            color: #3b82f6;
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
                padding: 16px 1.5rem;
            }

            .page-header h2 {
                font-size: 1.5rem;
            }

            .header-actions {
                width: 100%;
                flex-direction: column;
            }

            .header-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .content-wrapper {
                padding: 16px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .search-input {
                min-width: 100%;
            }

            .table-controls {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box {
                flex-direction: column;
            }

            .search-box .btn {
                width: 100%;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table th,
            .data-table td {
                padding: 10px 12px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2><?= $isUpdateMode ? "Sayım Güncelle – Doc#" . htmlspecialchars($documentEntry) : "Yeni Stok Sayımı" ?></h2>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="window.location.href='Stok.php'">İptal / Geri Dön</button>
                <button class="btn btn-primary" onclick="saveCounting()"><?= $isUpdateMode ? 'Sayımı Güncelle' : 'Kaydet' ?></button>
            </div>
        </header>

        <div class="content-wrapper">
            <!-- Form Kartı -->
            <section class="card">
                <div class="card-header">
                    <h3>📋 Sayım Bilgileri</h3>
                </div>
                <div class="card-body" style="margin-top: 1rem;">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Depo *</label>
                            <select id="warehouseCode" <?= $isUpdateMode ? 'readonly' : '' ?> onchange="loadItems()">
                                <option value="">Depo seçiniz</option>
                                <?php foreach ($warehouses as $whs): ?>
                                <option value="<?= htmlspecialchars($whs['WarehouseCode']) ?>" <?= ($isUpdateMode && $existingWarehouse === $whs['WarehouseCode']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($whs['WarehouseCode'] . ' - ' . ($whs['WarehouseName'] ?? '')) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Tarih</label>
                            <input type="date" id="countDate" value="<?= $isUpdateMode && isset($existingCounting['CountDate']) ? formatDate($existingCounting['CountDate']) : date('Y-m-d') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Açıklama (Opsiyonel)</label>
                            <input type="text" id="remarks" value="<?= $isUpdateMode ? htmlspecialchars($existingCounting['Remarks'] ?? '') : '' ?>" placeholder="Sayım hakkında not ekleyin...">
                        </div>
                    </div>
                </div>
            </section>

            <!-- Ürün Listesi Kartı -->
            <section class="card" id="itemsCard" style="display: none;">
                <div class="card-header">
                    <h3>🔍 Ürün Listesi</h3>
                </div>
                <div class="card-body" style="margin-top: 1rem;">
                    <div class="table-controls">
                        <div class="search-box">
                            <input type="text" class="search-input" id="itemSearch" placeholder="Ürün kodu veya adına göre ara..." onkeyup="if(event.key==='Enter') loadItems()">
                            <button class="btn btn-secondary btn-small" onclick="loadItems()">Ara</button>
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
                            <tbody id="itemsTableBody">
                                <tr>
                                    <td colspan="6" class="empty-message">Depo seçiniz</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Sepet Kartı -->
            <section class="card">
                <div class="card-header">
                    <h3>🛒 Sayım Sepeti</h3>
                </div>
                <div class="card-body" style="margin-top: 1rem;">
                    <div style="overflow-x: auto;">
                        <table class="data-table cart-table">
                            <thead>
                                <tr>
                                    <?php if ($isUpdateMode): ?>
                                    <th>Satır No</th>
                                    <?php endif; ?>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>Depo</th>
                                    <th>Birim</th>
                                    <th>Sayılan Miktar</th>
                                    <th style="text-align: center;">İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="cartTableBody">
                                <?php if ($isUpdateMode && !empty($existingLines)): ?>
                                <?php foreach ($existingLines as $line): ?>
                                <tr data-line-num="<?= $line['LineNum'] ?? '' ?>" data-item-code="<?= htmlspecialchars($line['ItemCode'] ?? '') ?>">
                                    <td><?= $line['LineNum'] ?? '-' ?></td>
                                    <td><strong><?= htmlspecialchars($line['ItemCode'] ?? '') ?></strong></td>
                                    <td><?= htmlspecialchars($line['ItemDescription'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['WarehouseCode'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['UoMCode'] ?? '') ?></td>
                                    <td>
                                        <div class="quantity-controls">
                                            <button type="button" class="qty-btn" onclick="changeExistingCartQuantity(this.parentElement.querySelector('.qty-input'), -1)">−</button>
                                            <input type="number" 
                                                   class="qty-input" 
                                                   value="<?= htmlspecialchars($line['CountedQuantity'] ?? 0) ?>" 
                                                   step="0.01" 
                                                   min="0" 
                                                   onchange="updateCartQuantity(this)" 
                                            <button type="button" class="qty-btn" onclick="changeExistingCartQuantity(this.parentElement.querySelector('.qty-input'), 1)">+</button>
                                        </div>
                                    </td>
                                    <td style="text-align: center;">
                                        <button class="btn btn-danger btn-small" onclick="removeFromCart(this)">Sil</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="<?= $isUpdateMode ? '7' : '6' ?>" class="empty-message">Sepet boş - Ürün seçiniz</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button class="btn btn-secondary" onclick="window.location.href='Stok.php'">İptal</button>
                <button class="btn btn-primary" onclick="saveCounting()"><?= $isUpdateMode ? 'Sayımı Güncelle' : 'Kaydet' ?></button>
            </div>
        </div>
    </main>

    <script>
        const isUpdateMode = <?= $isUpdateMode ? 'true' : 'false' ?>;
        const documentEntry = <?= $documentEntry ?: 'null' ?>;
        let cart = [];

        function formatQuantity(qty) {
            const num = parseFloat(qty);
            if (isNaN(num)) return '0';
            if (num % 1 === 0) {
                return num.toString();
            }
            return num.toString().replace('.', ',');
        }

        function showDebugInfo(debug, fullData) {
            // Debug paneli oluştur veya güncelle
            let debugPanel = document.getElementById('debugPanel');
            if (!debugPanel) {
                debugPanel = document.createElement('div');
                debugPanel.id = 'debugPanel';
                debugPanel.style.cssText = 'position: fixed; bottom: 20px; right: 20px; width: 700px; max-height: 80vh; background: #fff; border: 3px solid #3b82f6; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 10000; overflow: hidden; display: none;';
                document.body.appendChild(debugPanel);
            }
            
            const header = document.createElement('div');
            header.style.cssText = 'background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 16px; font-weight: 700; display: flex; justify-content: space-between; align-items: center; cursor: pointer; font-size: 16px;';
            header.innerHTML = `
                <span>🔍 DEBUG BİLGİLERİ (Stok Sayım)</span>
                <button onclick="document.getElementById('debugPanel').style.display='none'" style="background: transparent; border: none; color: white; font-size: 20px; cursor: pointer; font-weight: bold;">✕</button>
            `;
            
            const content = document.createElement('div');
            content.style.cssText = 'padding: 20px; max-height: calc(80vh - 70px); overflow-y: auto; font-family: "Courier New", monospace; font-size: 12px; background: #f8fafc;';
            
            let html = '<div style="margin-bottom: 20px;">';
            
            // Temel Bilgiler
            html += '<div style="margin-bottom: 16px; padding: 12px; background: #e0f2fe; border-radius: 8px; border-left: 4px solid #0369a1;">';
            html += '<div style="font-weight: 700; margin-bottom: 8px; color: #0369a1; font-size: 14px;">📋 TEMEL BİLGİLER:</div>';
            html += `<div style="margin-bottom: 4px;"><strong>Depo Kodu:</strong> <span style="background: #fff; padding: 2px 8px; border-radius: 4px;">${debug.warehouseCode || 'N/A'}</span></div>`;
            html += `<div style="margin-bottom: 4px;"><strong>Warehouse Property:</strong> <span style="background: #fff; padding: 2px 8px; border-radius: 4px;">${debug.warehouseProperty || 'N/A'}</span></div>`;
            html += `<div style="margin-bottom: 4px;"><strong>Toplam Ürün (İlk):</strong> <span style="background: #fff; padding: 2px 8px; border-radius: 4px;">${debug.itemsCount || 0}</span></div>`;
            if (debug.duplicatesRemoved !== undefined) {
                html += `<div style="margin-bottom: 4px;"><strong>Backend Duplicate Temizlendi:</strong> <span style="background: ${debug.duplicatesRemoved > 0 ? '#fee2e2' : '#d1fae5'}; padding: 2px 8px; border-radius: 4px; color: ${debug.duplicatesRemoved > 0 ? '#dc2626' : '#059669'}; font-weight: 700;">${debug.duplicatesRemoved || 0}</span></div>`;
                html += `<div style="margin-bottom: 4px;"><strong>Backend Duplicate Temizleme Sonrası:</strong> <span style="background: #fff; padding: 2px 8px; border-radius: 4px;">${debug.itemsAfterDedup || 0}</span></div>`;
            }
            if (debug.itemsAfterUoM !== undefined) {
                html += `<div style="margin-bottom: 4px;"><strong>UoM İşleme Sonrası:</strong> <span style="background: ${debug.itemsAfterUoM === debug.itemsAfterDedup ? '#d1fae5' : '#fee2e2'}; padding: 2px 8px; border-radius: 4px; color: ${debug.itemsAfterUoM === debug.itemsAfterDedup ? '#059669' : '#dc2626'}; font-weight: 700;">${debug.itemsAfterUoM || 0}</span></div>`;
            }
            if (debug.frontendDuplicatesRemoved !== undefined) {
                html += `<div style="margin-bottom: 4px;"><strong>Frontend Duplicate Temizlendi:</strong> <span style="background: ${debug.frontendDuplicatesRemoved > 0 ? '#fee2e2' : '#d1fae5'}; padding: 2px 8px; border-radius: 4px; color: ${debug.frontendDuplicatesRemoved > 0 ? '#dc2626' : '#059669'}; font-weight: 700;">${debug.frontendDuplicatesRemoved || 0}</span></div>`;
                html += `<div style="margin-bottom: 4px;"><strong>Frontend Unique Count:</strong> <span style="background: #fff; padding: 2px 8px; border-radius: 4px;">${debug.frontendUniqueCount || 0}</span></div>`;
            }
            html += `<div style="margin-bottom: 4px;"><strong>Skip:</strong> ${debug.skip || 0} | <strong>Top:</strong> ${debug.top || 25}</div>`;
            html += `<div style="margin-bottom: 4px;"><strong>Arama:</strong> "${debug.search || ''}"</div>`;
            html += `<div style="margin-bottom: 4px;"><strong>Response Status:</strong> <span style="background: ${debug.responseStatus === 200 ? '#d1fae5' : '#fee2e2'}; padding: 2px 8px; border-radius: 4px;">${debug.responseStatus || 'N/A'}</span></div>`;
            if (debug.uomErrors && debug.uomErrors.length > 0) {
                html += `<div style="margin-top: 8px; padding: 8px; background: #fee2e2; border-radius: 4px; color: #dc2626;"><strong>⚠️ UoM Hataları:</strong> ${debug.uomErrors.length} adet</div>`;
            }
            html += '</div>';
            
            // 90231 ve 90232 Kontrolü
            html += '<div style="margin-bottom: 16px; padding: 12px; background: ' + (debug.has90231 && debug.count90231 > 1 ? '#fee2e2' : debug.has90232 ? '#d1fae5' : '#fef3c7') + '; border-radius: 8px; border-left: 4px solid ' + (debug.has90231 && debug.count90231 > 1 ? '#dc2626' : debug.has90232 ? '#059669' : '#d97706') + ';">';
            html += '<div style="font-weight: 700; margin-bottom: 8px; color: ' + (debug.has90231 && debug.count90231 > 1 ? '#dc2626' : debug.has90232 ? '#059669' : '#d97706') + '; font-size: 14px;">🔍 90231/90232 KONTROLÜ:</div>';
            html += `<div style="margin-bottom: 4px;"><strong>90231 Var mı?:</strong> <span style="background: #fff; padding: 2px 8px; border-radius: 4px; color: ${debug.has90231 ? '#dc2626' : '#059669'}; font-weight: 700;">${debug.has90231 ? '✅ EVET' : '❌ HAYIR'}</span></div>`;
            html += `<div style="margin-bottom: 4px;"><strong>90231 Sayısı:</strong> <span style="background: #fff; padding: 2px 8px; border-radius: 4px; color: ${debug.count90231 > 1 ? '#dc2626' : '#059669'}; font-weight: 700;">${debug.count90231 || 0}</span> ${debug.count90231 > 1 ? '⚠️ İKİ KEZ VAR!' : ''}</div>`;
            html += `<div style="margin-bottom: 4px;"><strong>90232 Var mı?:</strong> <span style="background: #fff; padding: 2px 8px; border-radius: 4px; color: ${debug.has90232 ? '#059669' : '#dc2626'}; font-weight: 700;">${debug.has90232 ? '✅ EVET' : '❌ HAYIR'}</span></div>`;
            html += '</div>';
            
            // Filtre ve Query
            html += '<div style="margin-bottom: 16px; padding: 12px; background: #f3e8ff; border-radius: 8px; border-left: 4px solid #7c3aed;">';
            html += '<div style="font-weight: 700; margin-bottom: 8px; color: #7c3aed; font-size: 14px;">🔗 SORGULAR:</div>';
            html += `<div style="margin-bottom: 8px;"><strong>Filter:</strong> <code style="background: #fff; padding: 4px 8px; border-radius: 4px; display: block; margin-top: 4px; word-break: break-all;">${debug.filter || 'N/A'}</code></div>`;
            html += `<div style="margin-bottom: 8px;"><strong>Query:</strong> <code style="background: #fff; padding: 4px 8px; border-radius: 4px; display: block; margin-top: 4px; word-break: break-all;">${debug.query || 'N/A'}</code></div>`;
            html += '</div>';
            
            // ItemCode Listesi
            if (debug.itemCodes && debug.itemCodes.length > 0) {
                html += '<div style="margin-bottom: 16px; padding: 12px; background: #ecfdf5; border-radius: 8px; border-left: 4px solid #10b981;">';
                html += '<div style="font-weight: 700; margin-bottom: 8px; color: #059669; font-size: 14px;">📦 GELEN ÜRÜN KODLARI (Toplam: ' + debug.itemCodes.length + '):</div>';
                html += '<div style="background: #fff; padding: 8px; border-radius: 4px; max-height: 200px; overflow-y: auto;">';
                debug.itemCodes.forEach((code, idx) => {
                    const is90231 = code === '90231';
                    const is90232 = code === '90232';
                    html += `<div style="padding: 4px; margin-bottom: 2px; background: ${is90231 ? '#fee2e2' : is90232 ? '#d1fae5' : '#f3f4f6'}; border-radius: 4px; ${is90231 || is90232 ? 'font-weight: 700;' : ''}">${idx + 1}. ${code}${is90231 ? ' ⚠️' : ''}${is90232 ? ' ✅' : ''}</div>`;
                });
                html += '</div>';
                html += '</div>';
            }
            
            // Detaylı ItemCode Bilgileri
            if (debug.itemCodesDetailed && debug.itemCodesDetailed.length > 0) {
                html += '<details style="margin-bottom: 16px; padding: 12px; background: #fff7ed; border-radius: 8px; border-left: 4px solid #f59e0b;">';
                html += '<summary style="font-weight: 700; color: #d97706; font-size: 14px; cursor: pointer; margin-bottom: 8px;">📊 DETAYLI ÜRÜN BİLGİLERİ (Tıkla)</summary>';
                html += '<div style="background: #fff; padding: 8px; border-radius: 4px; max-height: 300px; overflow-y: auto;">';
                html += '<pre style="margin: 0; font-size: 11px; white-space: pre-wrap;">' + JSON.stringify(debug.itemCodesDetailed, null, 2) + '</pre>';
                html += '</div>';
                html += '</details>';
            }
            
            // ItemDetail Queries (Her item için sorgu sonuçları)
            if (debug.itemDetailQueries && debug.itemDetailQueries.length > 0) {
                html += '<details style="margin-bottom: 16px; padding: 12px; background: #fef3c7; border-radius: 8px; border-left: 4px solid #d97706;">';
                html += '<summary style="font-weight: 700; color: #d97706; font-size: 14px; cursor: pointer; margin-bottom: 8px;">🔍 HER İTEM İÇİN Items() SORGUSU SONUÇLARI (Tıkla)</summary>';
                html += '<div style="background: #fff; padding: 8px; border-radius: 4px; max-height: 400px; overflow-y: auto; margin-top: 8px;">';
                debug.itemDetailQueries.forEach((query, idx) => {
                    const isSuccess = query.status === 200;
                    html += `<div style="margin-bottom: 8px; padding: 8px; background: ${isSuccess ? '#d1fae5' : '#fee2e2'}; border-radius: 4px; border-left: 4px solid ${isSuccess ? '#059669' : '#dc2626'}">`;
                    html += `<div style="font-weight: 700; margin-bottom: 4px; color: ${isSuccess ? '#059669' : '#dc2626'}">${idx + 1}. ItemCode: ${query.ItemCode || 'N/A'}</div>`;
                    html += `<div style="margin-bottom: 2px;"><strong>Query:</strong> <code style="background: #fff; padding: 2px 6px; border-radius: 3px; font-size: 10px;">${query.query || 'N/A'}</code></div>`;
                    html += `<div style="margin-bottom: 2px;"><strong>Status:</strong> <span style="background: ${isSuccess ? '#86efac' : '#fecaca'}; padding: 2px 6px; border-radius: 3px;">${query.status || 'N/A'}</span></div>`;
                    if (query.error) {
                        html += `<div style="margin-bottom: 2px; color: #dc2626;"><strong>Error:</strong> ${JSON.stringify(query.error)}</div>`;
                    }
                    if (query.errorMessage) {
                        html += `<div style="margin-bottom: 2px; color: #dc2626;"><strong>Error Message:</strong> ${query.errorMessage}</div>`;
                    }
                    html += '</div>';
                });
                html += '</div>';
                html += '</details>';
            }
            
            // UoM Errors
            if (debug.uomErrors && debug.uomErrors.length > 0) {
                html += '<div style="margin-bottom: 16px; padding: 12px; background: #fee2e2; border-radius: 8px; border-left: 4px solid #dc2626;">';
                html += '<div style="font-weight: 700; margin-bottom: 8px; color: #dc2626; font-size: 14px;">⚠️ UoM İŞLEME HATALARI:</div>';
                debug.uomErrors.forEach((err, idx) => {
                    html += `<div style="margin-bottom: 6px; padding: 6px; background: #fff; border-radius: 4px;">`;
                    html += `<div><strong>ItemCode:</strong> ${err.ItemCode || 'N/A'}</div>`;
                    html += `<div><strong>Hata:</strong> ${err.error || 'N/A'}</div>`;
                    html += `<div><strong>Status:</strong> ${err.status || 'N/A'}</div>`;
                    if (err.query) {
                        html += `<div><strong>Query:</strong> <code style="background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-size: 10px;">${err.query}</code></div>`;
                    }
                    if (err.errorMessage) {
                        html += `<div style="color: #dc2626;"><strong>Error Message:</strong> ${err.errorMessage}</div>`;
                    }
                    html += '</div>';
                });
                html += '</div>';
            }
            
            // Raw Response
            if (debug.rawResponse) {
                html += '<details style="margin-bottom: 16px; padding: 12px; background: #fef2f2; border-radius: 8px; border-left: 4px solid #ef4444;">';
                html += '<summary style="font-weight: 700; color: #dc2626; font-size: 14px; cursor: pointer; margin-bottom: 8px;">📥 RAW RESPONSE (Tıkla)</summary>';
                html += '<div style="background: #fff; padding: 8px; border-radius: 4px; max-height: 300px; overflow-y: auto;">';
                html += '<pre style="margin: 0; font-size: 10px; white-space: pre-wrap; word-break: break-all;">' + JSON.stringify(debug.rawResponse, null, 2) + '</pre>';
                html += '</div>';
                html += '</details>';
            }
            
            // Full Debug Object
            html += '<details style="margin-bottom: 16px;">';
            html += '<summary style="font-weight: 700; color: #6b7280; font-size: 13px; cursor: pointer;">🔧 TÜM DEBUG OBJESİ (Tıkla)</summary>';
            html += '<div style="background: #fff; padding: 8px; border-radius: 4px; margin-top: 8px; max-height: 400px; overflow-y: auto;">';
            html += '<pre style="margin: 0; font-size: 10px; white-space: pre-wrap;">' + JSON.stringify(debug, null, 2) + '</pre>';
            html += '</div>';
            html += '</details>';
            
            html += '</div>';
            
            content.innerHTML = html;
            
            // Panel içeriğini güncelle
            debugPanel.innerHTML = '';
            debugPanel.appendChild(header);
            debugPanel.appendChild(content);
            debugPanel.style.display = 'block';
            
            // Header'a tıklanınca aç/kapat
            header.onclick = function(e) {
                if (e.target.tagName !== 'BUTTON') {
                    content.style.display = content.style.display === 'none' ? 'block' : 'none';
                }
            };
        }

        function loadItems() {
            const warehouseCode = document.getElementById('warehouseCode').value;
            if (!warehouseCode) {
                document.getElementById('itemsCard').style.display = 'none';
                return;
            }
            
            document.getElementById('itemsCard').style.display = 'block';
            const search = document.getElementById('itemSearch').value;
            const tbody = document.getElementById('itemsTableBody');
            tbody.innerHTML = '<tr><td colspan="6" class="empty-message">Yükleniyor...</td></tr>';
            
            const params = new URLSearchParams({
                ajax: 'items',
                warehouseCode: warehouseCode,
                search: search,
                top: 25,
                skip: 0
            });
            
            fetch('?' + params.toString())
                .then(res => res.json())
                .then(data => {
                    tbody.innerHTML = '';
                    
                    // Debug bilgilerini göster
                    showDebugInfo(data.debug || {}, data);
                    
                    if (data.error) {
                        tbody.innerHTML = '<tr><td colspan="6" class="empty-message" style="color: #ef4444;">Hata: ' + data.error + '</td></tr>';
                        return;
                    }
                    
                    if (data.data && data.data.length > 0) {
                        // Frontend'de duplicate kontrolü
                        const seenItemCodes = new Set();
                        const uniqueItems = [];
                        
                        data.data.forEach(item => {
                            const itemCode = item.ItemCode || '';
                            if (itemCode && !seenItemCodes.has(itemCode)) {
                                seenItemCodes.add(itemCode);
                                uniqueItems.push(item);
                            } else if (itemCode && seenItemCodes.has(itemCode)) {
                                console.warn('Duplicate item detected:', itemCode);
                            }
                        });
                        
                        // Debug bilgisi güncelle
                        if (data.debug) {
                            data.debug.frontendDuplicatesRemoved = data.data.length - uniqueItems.length;
                            data.debug.frontendUniqueCount = uniqueItems.length;
                            showDebugInfo(data.debug, data);
                        }
                        
                        // Debug: Frontend'de gelen item'ları logla
                        console.log('Frontend - Gelen item sayısı:', data.data.length);
                        console.log('Frontend - Unique item sayısı:', uniqueItems.length);
                        console.log('Frontend - ItemCodes:', uniqueItems.map(i => i.ItemCode));
                        
                        uniqueItems.forEach((item, idx) => {
                            // Debug: Her item için log
                            if (item.ItemCode === '90232') {
                                console.log('90232 bulundu! Index:', idx, 'Item:', item);
                            }
                            
                            const row = document.createElement('tr');
                            const uomList = item.UoMList || [];
                            const hasMultipleUoM = uomList.length > 1;
                            
                            const warehouseCode = item.WhsCode || item.WarehouseCode || '';
                            const technicalUomCode = item.UomCode || '';
                            const displayUom = item.InventoryUOM || '';
                            
                            let defaultBaseQty = 1;
                            if (uomList.length > 0) {
                                const defaultUom = uomList.find(u => u.UoMCode === technicalUomCode) || uomList[0];
                                defaultBaseQty = parseFloat(defaultUom.BaseQty || 1.0);
                            }
                            
                            let uomSelect = '';
                            if (hasMultipleUoM) {
                                uomSelect = '<select class="input-small" data-item-code="' + (item.ItemCode || '') + '" onchange="updateConversion(this)">';
                                uomList.forEach(uom => {
                                    const baseQty = parseFloat(uom.BaseQty || 1.0);
                                    const selected = (uom.UoMCode === technicalUomCode) ? ' selected' : '';
                                    uomSelect += '<option value="' + uom.UoMEntry + '" data-uom-code="' + (uom.UoMCode || '') + '" data-base-qty="' + baseQty + '"' + selected + '>' + (uom.UoMCode || '') + '</option>';
                                });
                                uomSelect += '</select>';
                            } else {
                                uomSelect = '<span data-uom-code="' + technicalUomCode + '" data-base-qty="' + defaultBaseQty + '">' + (technicalUomCode || displayUom) + '</span>';
                            }
                            
                            row.innerHTML = `
                                <td><strong>${item.ItemCode || ''}</strong></td>
                                <td>${item.ItemName || ''}</td>
                                <td>${warehouseCode}</td>
                                <td>${uomSelect}</td>
                                <td>
                                    <div class="quantity-controls">
                                        <button type="button" class="qty-btn" onclick="changeItemQuantity('${item.ItemCode || ''}', -1)">−</button>
                                        <input type="number" 
                                               id="qty_${item.ItemCode || ''}"
                                               class="qty-input" 
                                               value="0" 
                                               step="0.01" 
                                               min="0" 
                                               data-item-code="${item.ItemCode || ''}" 
                                               onchange="updateItemQuantity('${item.ItemCode || ''}', this.value)"
                                               oninput="updateItemQuantity('${item.ItemCode || ''}', this.value)">
                                        <button type="button" class="qty-btn" onclick="changeItemQuantity('${item.ItemCode || ''}', 1)">+</button>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <button class="btn btn-primary btn-small" onclick="addToCart(this)">Ekle</button>
                                </td>
                            `;
                            row.setAttribute('data-item-code', item.ItemCode || '');
                            row.setAttribute('data-item-data', JSON.stringify(item));
                            tbody.appendChild(row);
                        });
                    } else {
                        tbody.innerHTML = '<tr><td colspan="6" class="empty-message">Ürün bulunamadı</td></tr>';
                    }
                })
                .catch(err => {
                    tbody.innerHTML = '<tr><td colspan="6" class="empty-message" style="color: #ef4444;">Bir hata oluştu: ' + err.message + '</td></tr>';
                });
        }

        function updateConversion(select) {
            const row = select.closest('tr');
            const selectedOption = select.options[select.selectedIndex];
            const baseQty = parseFloat(selectedOption.getAttribute('data-base-qty') || 1);
            const conversionCell = row.querySelector('.conversion-cell');
            
            let conversionText = '-';
            if (baseQty && baseQty !== 1 && baseQty > 0) {
                conversionText = `1x${formatQuantity(baseQty)} = ${formatQuantity(baseQty)} AD`;
            }
            conversionCell.textContent = conversionText;
        }

        function changeItemQuantity(itemCode, delta) {
            const input = document.getElementById('qty_' + itemCode);
            if (input) {
                input.value = Math.max(0, parseFloat(input.value) + delta);
            }
        }

        function updateItemQuantity(itemCode, value) {
            const input = document.getElementById('qty_' + itemCode);
            if (input) {
                input.value = Math.max(0, parseFloat(value));
            }
        }

        function addToCart(btn) {
            const row = btn.closest('tr');
            const itemCode = row.getAttribute('data-item-code');
            const itemName = row.cells[1].textContent.trim();
            const warehouseCode = row.cells[2].textContent.trim();
            const quantityInput = row.querySelector('input[type="number"]');
            const quantity = parseFloat(quantityInput.value) || 0;
            
            if (quantity <= 0) {
                alert('Lütfen geçerli bir miktar girin');
                return;
            }
            
            let uomCode = '';
            let uomEntry = null;
            const uomCell = row.cells[3];
            const uomSelect = uomCell.querySelector('select');
            if (uomSelect) {
                const selectedOption = uomSelect.options[uomSelect.selectedIndex];
                uomCode = selectedOption.getAttribute('data-uom-code') || '';
                uomEntry = parseInt(selectedOption.value);
            } else {
                const uomSpan = uomCell.querySelector('span[data-uom-code]');
                if (uomSpan) {
                    uomCode = uomSpan.getAttribute('data-uom-code') || '';
                }
            }
            
            if (!uomCode) {
                alert('Birim bilgisi bulunamadı');
                return;
            }
            
            let baseQty = 1;
            if (uomSelect) {
                const selectedOption = uomSelect.options[uomSelect.selectedIndex];
                baseQty = parseFloat(selectedOption.getAttribute('data-base-qty') || 1);
            } else {
                const uomSpan = uomCell.querySelector('span[data-base-qty]');
                if (uomSpan) {
                    baseQty = parseFloat(uomSpan.getAttribute('data-base-qty') || 1);
                }
            }
            
            cart.push({
                ItemCode: itemCode,
                ItemName: itemName,
                WarehouseCode: warehouseCode,
                UoMCode: uomCode,
                UoMEntry: uomEntry,
                CountedQuantity: quantity,
                BaseQty: baseQty
            });
            
            updateCartTable();
            quantityInput.value = '0';
        }

        function updateCartTable() {
            const tbody = document.getElementById('cartTableBody');
            tbody.innerHTML = '';
            
            if (cart.length === 0) {
                tbody.innerHTML = `<tr><td colspan="${isUpdateMode ? '7' : '6'}" class="empty-message">Sepet boş - Ürün seçiniz</td></tr>`;
                return;
            }
            
            cart.forEach((item, index) => {
                const row = document.createElement('tr');
                if (isUpdateMode && item.LineNumber !== null && item.LineNumber !== undefined) {
                    row.setAttribute('data-line-number', item.LineNumber);
                }
                row.setAttribute('data-item-code', item.ItemCode);
                
                const qty = parseFloat(item.CountedQuantity) || 0;
                
                let html = '';
                if (isUpdateMode) {
                    html += `<td>${item.LineNumber !== null && item.LineNumber !== undefined ? item.LineNumber : '-'}</td>`;
                }
                html += `
                    <td><strong>${item.ItemCode}</strong></td>
                    <td>${item.ItemName}</td>
                    <td>${item.WarehouseCode}</td>
                    <td>${item.UoMCode}</td>
                    <td>
                        <div class="quantity-controls">
                            <button type="button" class="qty-btn" onclick="changeCartQuantity(${index}, -1)">−</button>
                            <input type="number" 
                                   class="qty-input" 
                                   value="${qty}" 
                                   step="0.01" 
                                   min="0" 
                                   onchange="updateCartQuantity(this, ${index})">
                            <button type="button" class="qty-btn" onclick="changeCartQuantity(${index}, 1)">+</button>
                        </div>
                    </td>
                    <td style="text-align: center;">
                        <button class="btn btn-danger btn-small" onclick="removeFromCart(${index})">Sil</button>
                    </td>
                `;
                row.innerHTML = html;
                tbody.appendChild(row);
            });
        }

        function changeCartQuantity(index, delta) {
            if (index >= 0 && index < cart.length) {
                cart[index].CountedQuantity = Math.max(0, parseFloat(cart[index].CountedQuantity) + delta);
                updateCartTable();
            }
        }

        function updateCartQuantity(input, index) {
            if (index >= 0 && index < cart.length) {
                cart[index].CountedQuantity = parseFloat(input.value) || 0;
            }
        }

        function updateCartConversion(input, index) {
            // Just re-render for visual update
            updateCartTable();
        }

        function removeFromCart(index) {
            if (index >= 0 && index < cart.length) {
                cart.splice(index, 1);
                updateCartTable();
            }
        }

        function saveCounting() {
            const warehouseCode = document.getElementById('warehouseCode').value;
            const countDate = document.getElementById('countDate').value;
            const remarks = document.getElementById('remarks').value;
            
            if (!warehouseCode) {
                alert('Depo seçilmelidir');
                return;
            }
            
            if (cart.length === 0) {
                alert('Sepete en az bir ürün eklenmelidir');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', isUpdateMode ? 'update' : 'create');
            formData.append('warehouseCode', warehouseCode);
            formData.append('countDate', countDate);
            formData.append('remarks', remarks);
            
            const lines = cart.map(item => {
                // KT birimindeki ürünler Ad olarak sayılmalı (BaseQty ile çarp)
                const baseQty = parseFloat(item.BaseQty || 1.0);
                let countedQty = parseFloat(item.CountedQuantity) || 0;
                
                // Eğer birim KT ise ve BaseQty > 1 ise, miktarı Ad'a dönüştür
                if (item.UoMCode === 'KT' && baseQty > 1) {
                    countedQty = countedQty * baseQty;
                }
                
                const line = {
                    ItemCode: item.ItemCode,
                    WarehouseCode: item.WarehouseCode,
                    CountedQuantity: countedQty
                };
                
                if (isUpdateMode && item.LineNumber !== null && item.LineNumber !== undefined) {
                    line.LineNumber = item.LineNumber;
                } else if (!isUpdateMode || item.LineNumber === null || item.LineNumber === undefined) {
                    // KT birimindeki ürünler Ad olarak kaydedilmeli
                    if (item.UoMCode === 'KT' && baseQty > 1) {
                        // Ad birimini kullan (UoMEntry veya UoMCode olarak)
                        // BaseUoM'u bulmak için UoMCode'u 'AD' olarak ayarla
                        line.UoMCode = 'AD';
                    } else {
                        if (item.UoMEntry) {
                            line.UoMEntry = item.UoMEntry;
                        } else if (item.UoMCode) {
                            line.UoMCode = item.UoMCode;
                        }
                    }
                }
                
                return line;
            });
            
            formData.append('lines', JSON.stringify(lines));
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    if (!isUpdateMode && data.DocumentEntry) {
                        window.location.href = 'Stok.php';
                    } else {
                        window.location.href = 'Stok.php';
                    }
                } else {
                    alert('Hata: ' + data.message);
                    if (data.errors) {
                        console.error('Errors:', data.errors);
                    }
                }
            })
            .catch(err => alert('Bir hata oluştu: ' + err.message));
        }

        // Initialize cart for update mode
        if (isUpdateMode) {
            const cartRows = document.querySelectorAll('#cartTableBody tr[data-item-code]');
            cartRows.forEach(row => {
                if (row.cells.length > 1 && !row.querySelector('.empty-message')) {
                    const itemCode = row.getAttribute('data-item-code');
                    const itemName = row.cells[isUpdateMode ? 2 : 1].textContent.trim();
                    const warehouse = row.cells[isUpdateMode ? 3 : 2].textContent.trim();
                    const uom = row.cells[isUpdateMode ? 4 : 3].textContent.trim();
                    const quantity = parseFloat(row.querySelector('.qty-input').value || 0);
                    
                    cart.push({
                        ItemCode: itemCode,
                        ItemName: itemName,
                        WarehouseCode: warehouse,
                        UoMCode: uom,
                        CountedQuantity: quantity,
                        BaseQty: 1
                    });
                }
            });
        }
    </script>
</body>
</html>
