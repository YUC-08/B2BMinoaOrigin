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
    
    // Debug bilgisini response'a ekle
    $debugInfo['warehouseProperty'] = $warehouseProperty;
    $debugInfo['filter'] = $filter;
    $debugInfo['query'] = $itemsQuery;
    $debugInfo['itemsCount'] = count($items);
    
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
                if (!empty($itemDetail['UoMGroupEntry'])) {
                    // Alternatif query: direkt collection path
                    $uomGroupQuery = "UoMGroups({$itemDetail['UoMGroupEntry']})?\$expand=UoMGroupDefinitionCollection";
                    $uomGroupData = $sap->get($uomGroupQuery);
                    if (($uomGroupData['status'] ?? 0) == 200) {
                        $uomGroup = $uomGroupData['response'] ?? $uomGroupData;
                        $uomList = [];
                        
                        // Farklı response yapılarını kontrol et
                        $collection = [];
                        if (isset($uomGroup['UoMGroupDefinitionCollection']) && is_array($uomGroup['UoMGroupDefinitionCollection'])) {
                            $collection = $uomGroup['UoMGroupDefinitionCollection'];
                        } elseif (isset($uomGroup['value']) && is_array($uomGroup['value'])) {
                            $collection = $uomGroup['value'];
                        } elseif (is_array($uomGroup)) {
                            $collection = $uomGroup;
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
                        
                        // Eğer UoMList hala boşsa, BaseUoM'u kullan (ana birim)
                        if (empty($uomList) && isset($uomGroup['BaseUoM'])) {
                            $baseUoM = $uomGroup['BaseUoM'];
                            // BaseUoM'dan UoMCode'u bulmak için UoMCodes endpoint'ini kullan
                            // Şimdilik BaseUoM'u direkt kullan
                            $uomList[] = [
                                'UoMEntry' => $baseUoM,
                                'UoMCode' => $itemDetail['InventoryUOM'] ?? '',
                                'BaseQty' => 1
                            ];
                        }
                        
                        $item['UoMList'] = $uomList;
                        
                        // Eğer UoMList boşsa ve InventoryUOM varsa, InventoryUOM'un UoMEntry'sini bul
                        if (empty($uomList) && !empty($itemDetail['InventoryUOM'])) {
                            $inventoryUOM = $itemDetail['InventoryUOM'];
                            // UoMGroupDefinitionCollection'dan InventoryUOM ile eşleşeni bul
                            if (isset($uomGroup['UoMGroupDefinitionCollection'])) {
                                foreach ($uomGroup['UoMGroupDefinitionCollection'] as $uomDef) {
                                    $uomCode = $uomDef['UoMCode'] ?? '';
                                    $uomEntry = $uomDef['UoMEntry'] ?? '';
                                    // InventoryUOM ile eşleşen veya herhangi bir geçerli UoMEntry bul
                                    if (!empty($uomEntry) && ($uomCode === $inventoryUOM || empty($uomList))) {
                                        $item['UoMList'] = [[
                                            'UoMEntry' => $uomEntry,
                                            'UoMCode' => $uomCode ?: $inventoryUOM,
                                            'BaseQty' => $uomDef['BaseQty'] ?? 1
                                        ]];
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
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
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            color: #1f2937;
        }

        .main-content {
            width: 100%;
            min-height: 100vh;
            padding: 0;
        }

        .page-header {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
            color: white;
            padding: 24px 2rem;
            box-shadow: 0 4px 20px rgba(30, 64, 175, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
            height: auto;
            min-height: 80px;
        }

        .page-header h2 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .content-wrapper {
            padding: 32px;
            max-width: 1600px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            margin-bottom: 24px;
            overflow: hidden;
            transition: box-shadow 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 2px solid #f3f4f6;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }

        .card-header h3 {
            color: #1e40af;
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-body {
            padding: 24px;
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

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            box-shadow: 0 4px 16px rgba(59, 130, 246, 0.4);
            transform: translateY(-2px);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: white;
            color: #3b82f6;
            border: 2px solid #3b82f6;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.1);
        }

        .btn-secondary:hover {
            background: #f0f9ff;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
            transform: translateY(-2px);
        }

        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            box-shadow: 0 4px 16px rgba(16, 185, 129, 0.4);
            transform: translateY(-2px);
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
            padding: 6px 12px;
            font-size: 12px;
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
                <div class="card-body">
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
                <div class="card-body">
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
                                    <th>Sepete Ekle</th>
                                    <th>Dönüşüm</th>
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
                <div class="card-body">
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
                                    <th>Dönüşüm</th>
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
                                                   oninput="updateCartConversionForExisting(this)">
                                            <button type="button" class="qty-btn" onclick="changeExistingCartQuantity(this.parentElement.querySelector('.qty-input'), 1)">+</button>
                                        </div>
                                    </td>
                                    <td class="conversion-cell">-</td>
                                    <td style="text-align: center;">
                                        <button class="btn btn-danger btn-small" onclick="removeFromCart(this)">Sil</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="<?= $isUpdateMode ? '8' : '7' ?>" class="empty-message">Sepet boş - Ürün seçiniz</td>
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
                    
                    if (data.error) {
                        tbody.innerHTML = '<tr><td colspan="6" class="empty-message" style="color: #ef4444;">Hata: ' + data.error + '</td></tr>';
                        return;
                    }
                    
                    if (data.data && data.data.length > 0) {
                        data.data.forEach(item => {
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
                            
                            let conversionText = '-';
                            if (defaultBaseQty && defaultBaseQty !== 1 && defaultBaseQty > 0) {
                                conversionText = `1x${formatQuantity(defaultBaseQty)} = ${formatQuantity(defaultBaseQty)} AD`;
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
                                    <button class="btn btn-success btn-small" style="margin-top: 8px; width: 100%;" onclick="addToCart(this)">Ekle</button>
                                </td>
                                <td class="conversion-cell">${conversionText}</td>
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
                tbody.innerHTML = `<tr><td colspan="${isUpdateMode ? '8' : '7'}" class="empty-message">Sepet boş - Ürün seçiniz</td></tr>`;
                return;
            }
            
            cart.forEach((item, index) => {
                const row = document.createElement('tr');
                if (isUpdateMode && item.LineNumber !== null && item.LineNumber !== undefined) {
                    row.setAttribute('data-line-number', item.LineNumber);
                }
                row.setAttribute('data-item-code', item.ItemCode);
                
                const qty = parseFloat(item.CountedQuantity) || 0;
                const baseQty = parseFloat(item.BaseQty || 1.0);
                
                let conversionText = '-';
                if (baseQty !== 1 && baseQty > 0) {
                    const adKarşılığı = qty * baseQty;
                    conversionText = `${formatQuantity(qty)}x${formatQuantity(baseQty)} = ${formatQuantity(adKarşılığı)} AD`;
                }
                
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
                                   onchange="updateCartQuantity(this, ${index})" 
                                   oninput="updateCartConversion(this, ${index})">
                            <button type="button" class="qty-btn" onclick="changeCartQuantity(${index}, 1)">+</button>
                        </div>
                    </td>
                    <td class="conversion-cell">${conversionText}</td>
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
                const line = {
                    ItemCode: item.ItemCode,
                    WarehouseCode: item.WarehouseCode,
                    CountedQuantity: item.CountedQuantity
                };
                
                if (isUpdateMode && item.LineNumber !== null && item.LineNumber !== undefined) {
                    line.LineNumber = item.LineNumber;
                } else if (!isUpdateMode || item.LineNumber === null || item.LineNumber === undefined) {
                    if (item.UoMEntry) {
                        line.UoMEntry = item.UoMEntry;
                    } else if (item.UoMCode) {
                        line.UoMCode = item.UoMCode;
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
