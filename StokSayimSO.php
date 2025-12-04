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
            
            // LineNumber'ı LineNum'a map et (SAP B1SL'de LineNumber kullanılıyor)
            foreach ($existingLines as &$line) {
                if (isset($line['LineNumber']) && !isset($line['LineNum'])) {
                    $line['LineNum'] = $line['LineNumber'];
                }
            }
            unset($line); // Reference'ı temizle
            
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
        echo json_encode([
            'success' => true, 
            'message' => 'Sayım başarıyla oluşturuldu', 
            'DocumentEntry' => $newDocumentEntry,
            'debug' => [
                'payload' => $payload,
                'response' => $response,
                'status' => $result['status'] ?? 'NO STATUS'
            ]
        ]);
    } else {
        $error = $result['response']['error']['message']['value'] ?? $result['response']['error']['message'] ?? 'Bilinmeyen hata';
        echo json_encode([
            'success' => false, 
            'message' => 'Sayım oluşturulamadı: ' . $error,
            'debug' => [
                'payload' => $payload,
                'response' => $result['response'] ?? null,
                'status' => $result['status'] ?? 'NO STATUS',
                'error' => $result['response']['error'] ?? null
            ]
        ]);
    }
    exit;
}

// PATCH: Sayım güncelle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    header('Content-Type: application/json');
    
    if (!$isUpdateMode) {
        echo json_encode(['success' => false, 'message' => 'DocumentEntry gerekli']);
        exit;
    }
    
    $lines = isset($_POST['lines']) ? json_decode($_POST['lines'], true) : [];
    
    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'En az bir kalem gereklidir']);
        exit;
    }
    
    // Mevcut satırları çek (güncelleme için)
    $countingQuery = "InventoryCountings({$documentEntry})";
    $countingData = $sap->get($countingQuery);
    $existingLinesMap = [];
    
    if (($countingData['status'] ?? 0) == 200) {
        $existingCounting = $countingData['response'] ?? $countingData;
        if (isset($existingCounting['InventoryCountingLines']) && is_array($existingCounting['InventoryCountingLines'])) {
            foreach ($existingCounting['InventoryCountingLines'] as $existingLine) {
                $lineNum = $existingLine['LineNumber'] ?? $existingLine['LineNum'] ?? null;
                if ($lineNum !== null) {
                    $existingLinesMap[$lineNum] = $existingLine;
                }
            }
        }
    }
    
    $payload = [
        'InventoryCountingLines' => []
    ];
    
    foreach ($lines as $line) {
        $lineData = [];
        
        // Mevcut satırlar için LineNum gönder (LineNumber'ı LineNum'a map et)
        if (isset($line['LineNum']) && $line['LineNum'] !== null && $line['LineNum'] !== '') {
            $lineData['LineNum'] = intval($line['LineNum']);
            // Mevcut satırlar için sadece CountedQuantity güncellenir
            $lineData['CountedQuantity'] = floatval($line['CountedQuantity'] ?? 0);
        } else {
            // Yeni satırlar için ItemCode ve WarehouseCode gönder
            $lineData['ItemCode'] = $line['ItemCode'] ?? '';
            $lineData['WarehouseCode'] = $line['WarehouseCode'] ?? '';
            // UoMEntry varsa gönder, yoksa UoMCode gönder
            if (isset($line['UoMEntry']) && $line['UoMEntry'] !== null && $line['UoMEntry'] !== '') {
                $lineData['UoMEntry'] = intval($line['UoMEntry']);
            } elseif (isset($line['UoMCode']) && $line['UoMCode'] !== null && $line['UoMCode'] !== '') {
                $lineData['UoMCode'] = $line['UoMCode'];
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => "Sayım güncellenemedi: Ürün '{$line['ItemCode']}' için birim bilgisi (UoMEntry veya UoMCode) bulunamadı.",
                    'debug' => [
                        'line' => $line,
                        'allLines' => $lines
                    ]
                ]);
                exit;
            }
            $lineData['CountedQuantity'] = floatval($line['CountedQuantity'] ?? 0);
        }
        
        $payload['InventoryCountingLines'][] = $lineData;
    }
    
    $result = $sap->patch("InventoryCountings({$documentEntry})", $payload);
    
    if (($result['status'] ?? 0) == 200 || ($result['status'] ?? 0) == 204) {
        echo json_encode(['success' => true, 'message' => 'Sayım başarıyla güncellendi', 'DocumentEntry' => $documentEntry]);
    } else {
        $error = $result['response']['error']['message']['value'] ?? $result['response']['error']['message'] ?? 'Bilinmeyen hata';
        echo json_encode(['success' => false, 'message' => 'Sayım güncellenemedi: ' . $error]);
    }
    exit;
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '';
    if (strpos($date, 'T') !== false) {
        return date('Y-m-d', strtotime(substr($date, 0, 10)));
    }
    return date('Y-m-d', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isUpdateMode ? 'Sayım Güncelle' : 'Yeni Stok Sayımı' ?> - MINOA</title>
    <link rel="stylesheet" href="styles.css">
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
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-size: 13px;
    color: #4b5563;
    margin-bottom: 4px;
    font-weight: 500;
}

.form-group input[type="date"],
.form-group input[type="text"],
.form-group input[type="number"],
.form-group select {
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

.form-group input[type="date"]:focus,
.form-group input[type="text"]:focus,
.form-group input[type="number"]:focus,
.form-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group input[readonly],
.form-group select[readonly] {
    background: #f3f4f6;
    cursor: not-allowed;
    color: #374151;
}

.table-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.search-box {
    display: flex;
    gap: 8px;
    align-items: center;
}

.search-input {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    min-width: 220px;
    transition: border-color 0.2s;
}

.search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.data-table thead {
    background: #f8fafc;
}

.data-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    color: #4b5563;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.data-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.15s;
}

.data-table tbody tr:hover {
    background: #f9fafb;
}

.data-table td {
    padding: 12px 16px;
    color: #374151;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
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

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #f0f9ff;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

.btn-small {
    padding: 6px 12px;
    font-size: 12px;
}

.input-small {
    padding: 6px 10px;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    font-size: 13px;
    width: 80px;
}

.cart-table {
    margin-top: 24px;
}

.cart-table th {
    background: #f8fafc;
}

.empty-message {
    text-align: center;
    padding: 3rem;
    color: #9ca3af;
    font-size: 14px;
}

.action-buttons {
    display: flex;
    gap: 12px;
    margin-top: 24px;
    justify-content: flex-end;
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
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2><?= $isUpdateMode ? "Sayım Güncelle – DocEntry: {$documentEntry}" : "Yeni Stok Sayımı" ?></h2>
            <div style="display: flex; gap: 12px;">
                <button class="btn btn-secondary" onclick="window.location.href='Stok.php'">İptal / Geri Dön</button>
                <button class="btn btn-primary" onclick="saveCounting()"><?= $isUpdateMode ? 'Sayımı Güncelle' : 'Kaydet' ?></button>
            </div>
        </header>

        <div class="content-wrapper">
            <!-- Debug Panel -->
            <section class="card" id="debugPanel" style="background: #fef3c7; border: 2px solid #f59e0b; margin-bottom: 24px;">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="color: #92400e; margin: 0;">🔍 Debug Bilgileri</h3>
                    <button class="btn btn-secondary btn-small" onclick="clearDebug()">Temizle</button>
                </div>
                <div class="card-body">
                    <pre id="debugContent" style="background: white; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 12px; max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word; margin: 0;"><?php 
if ($isUpdateMode) {
    echo "=== GÜNCELLE MODU DEBUG ===\n";
    echo "DocumentEntry: {$documentEntry}\n";
    echo "Counting Query: InventoryCountings({$documentEntry})\n";
    echo "Lines Query: InventoryCountings({$documentEntry})/InventoryCountingLines\n";
    echo "Counting Response Status: " . (isset($countingData) ? ($countingData['status'] ?? 'NO STATUS') : 'NOT FETCHED') . "\n";
    echo "Lines Response Status: " . (isset($linesData) ? ($linesData['status'] ?? 'NO STATUS') : 'NOT FETCHED') . "\n";
    echo "\n=== EXISTING COUNTING (Header) ===\n";
    echo json_encode($existingCounting, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    echo "\n=== EXISTING LINES ===\n";
    echo "Count: " . count($existingLines) . "\n";
    if (!empty($existingLines)) {
        echo "First Line Sample:\n";
        echo json_encode($existingLines[0] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\nAll Lines:\n";
    echo json_encode($existingLines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    echo "\n=== EXISTING WAREHOUSE ===\n";
    echo $existingWarehouse . "\n";
    if (isset($linesData)) {
        echo "\n=== LINES RAW RESPONSE ===\n";
        echo json_encode($linesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
} else {
    echo "Yeni Sayım Modu\n";
}
?></pre>
                </div>
            </section>

            <!-- Üst Bilgiler -->
            <section class="card">
                <div class="card-header">
                    <h3>Üst Bilgiler</h3>
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
                            <label>Açıklama</label>
                            <input type="text" id="remarks" value="<?= $isUpdateMode ? htmlspecialchars($existingCounting['Remarks'] ?? '') : '' ?>" placeholder="Açıklama (opsiyonel)">
                        </div>
                    </div>
                </div>
            </section>

            <!-- Ürün Listesi -->
            <section class="card" id="itemsCard" style="display: none;">
                <div class="card-header">
                    <h3>Ürün Listesi</h3>
                </div>
                <div class="card-body">
                    <div class="table-controls">
                        <div class="search-box">
                            <input type="text" class="search-input" id="itemSearch" placeholder="Ara..." onkeyup="if(event.key==='Enter') loadItems()">
                            <button class="btn btn-secondary" onclick="loadItems()">🔍</button>
                        </div>
                    </div>

                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>Depo</th>
                                    <th>Birim(ler)</th>
                                    <th>Sepete Ekle</th>
                                </tr>
                            </thead>
                            <tbody id="itemsTableBody">
                                <tr>
                                    <td colspan="5" class="empty-message">Depo seçiniz</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Sepet -->
            <section class="card">
                <div class="card-header">
                    <h3>Sepet</h3>
                </div>
                <div class="card-body">
                    <div style="overflow-x: auto;">
                        <table class="data-table cart-table">
                            <thead>
                                <tr>
                                    <?php if ($isUpdateMode): ?>
                                    <th>LineNum</th>
                                    <?php endif; ?>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>Depo</th>
                                    <th>Birim</th>
                                    <th>Sayılan Miktar</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="cartTableBody">
                                <?php if ($isUpdateMode && !empty($existingLines)): ?>
                                <?php foreach ($existingLines as $line): ?>
                                <tr data-line-num="<?= $line['LineNum'] ?? '' ?>" data-item-code="<?= htmlspecialchars($line['ItemCode'] ?? '') ?>">
                                    <td><?= $line['LineNum'] ?? '' ?></td>
                                    <td><?= htmlspecialchars($line['ItemCode'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['ItemDescription'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['WarehouseCode'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['UoMCode'] ?? '') ?></td>
                                    <td>
                                        <input type="number" class="input-small" value="<?= htmlspecialchars($line['CountedQuantity'] ?? 0) ?>" step="0.01" min="0" onchange="updateCartQuantity(this)">
                                    </td>
                                    <td>
                                        <button class="btn btn-danger btn-small" onclick="removeFromCart(this)">Sil</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="<?= $isUpdateMode ? '7' : '6' ?>" class="empty-message">Sepet boş</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script>
const isUpdateMode = <?= $isUpdateMode ? 'true' : 'false' ?>;
const documentEntry = <?= $documentEntry ?: 'null' ?>;
let cart = <?= json_encode($isUpdateMode ? array_map(function($line) {
    return [
        'LineNum' => $line['LineNum'] ?? null,
        'ItemCode' => $line['ItemCode'] ?? '',
        'ItemName' => $line['ItemDescription'] ?? '',
        'WarehouseCode' => $line['WarehouseCode'] ?? '',
        'UoMCode' => $line['UoMCode'] ?? '',
        'UoMEntry' => $line['UoMEntry'] ?? null,
        'CountedQuantity' => $line['CountedQuantity'] ?? 0
    ];
}, $existingLines) : []) ?>;

function loadItems() {
    const warehouseCode = document.getElementById('warehouseCode').value;
    if (!warehouseCode) {
        document.getElementById('itemsCard').style.display = 'none';
        return;
    }
    
    document.getElementById('itemsCard').style.display = 'block';
    const search = document.getElementById('itemSearch').value;
    const tbody = document.getElementById('itemsTableBody');
    tbody.innerHTML = '<tr><td colspan="5" class="empty-message">Yükleniyor...</td></tr>';
    
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
            console.log('Items Response:', data);
            updateDebug('Items Response', data);
            tbody.innerHTML = '';
            
            if (data.error) {
                tbody.innerHTML = '<tr><td colspan="5" class="empty-message" style="color: #ef4444;">Hata: ' + data.error + '</td></tr>';
                return;
            }
            
            if (data.data && data.data.length > 0) {
                data.data.forEach(item => {
                    const row = document.createElement('tr');
                    const uomList = item.UoMList || [];
                    const hasMultipleUoM = uomList.length > 1;
                    
                    // WarehouseCode'u doğru al (view'den gelen WhsCode)
                    const warehouseCode = item.WhsCode || item.WarehouseCode || '';
                    
                    // View'den gelen UomCode (teknik kod, örn: "AD") - SAP'nin istediği kod
                    const technicalUomCode = item.UomCode || '';
                    // InventoryUOM (kullanıcı görünen, örn: "Adet") - sadece label
                    const displayUom = item.InventoryUOM || '';
                    
                    let uomSelect = '';
                    if (hasMultipleUoM) {
                        uomSelect = '<select class="input-small" data-item-code="' + (item.ItemCode || '') + '">';
                        uomList.forEach(uom => {
                            uomSelect += '<option value="' + uom.UoMEntry + '" data-uom-code="' + (uom.UoMCode || '') + '">' + (uom.UoMCode || '') + '</option>';
                        });
                        uomSelect += '</select>';
                    } else {
                        // Tek birim varsa: data-uom-code="AD" (teknik kod), gösterilen: "Adet" (label)
                        uomSelect = '<span data-uom-code="' + technicalUomCode + '">' + displayUom + '</span>';
                    }
                    
                    row.innerHTML = `
                        <td>${item.ItemCode || ''}</td>
                        <td>${item.ItemName || ''}</td>
                        <td>${warehouseCode}</td>
                        <td>${uomSelect}</td>
                        <td>
                            <input type="number" class="input-small" value="1" step="0.01" min="0" data-item-code="${item.ItemCode || ''}">
                            <button class="btn btn-primary btn-small" onclick="addToCart(this)">Sepete Ekle</button>
                        </td>
                    `;
                    // Item data'sını row'a ekle (UoMEntry için)
                    row.setAttribute('data-item-code', item.ItemCode || '');
                    row.setAttribute('data-item-data', JSON.stringify(item));
                    tbody.appendChild(row);
                });
            } else {
                tbody.innerHTML = '<tr><td colspan="5" class="empty-message">Ürün bulunamadı</td></tr>';
            }
        })
        .catch(err => {
            console.error('Error:', err);
            tbody.innerHTML = '<tr><td colspan="5" class="empty-message" style="color: #ef4444;">Bir hata oluştu: ' + err.message + '</td></tr>';
        });
}

function addToCart(btn) {
    const row = btn.closest('tr');
    const itemCode = row.getAttribute('data-item-code');
    const itemName = row.cells[1].textContent.trim();
    const warehouseCode = row.cells[2].textContent.trim();
    const quantityInput = row.querySelector('input[type="number"]');
    const quantity = parseFloat(quantityInput.value) || 0;
    
    let uomCode = '';
    let uomEntry = null;
    const uomCell = row.cells[3];
    const uomSelect = uomCell.querySelector('select');
    if (uomSelect) {
        const selectedOption = uomSelect.options[uomSelect.selectedIndex];
        uomCode = selectedOption.getAttribute('data-uom-code') || '';
        uomEntry = parseInt(selectedOption.value);
    } else {
        // Tek birim varsa, span'dan data-uom-code'u al (teknik kod)
        const uomSpan = uomCell.querySelector('span[data-uom-code]');
        if (uomSpan) {
            uomCode = uomSpan.getAttribute('data-uom-code') || ''; // Teknik kod (örn: "AD")
            // UoMEntry opsiyonel - varsa al
            const uomEntryAttr = uomSpan.getAttribute('data-uom-entry');
            if (uomEntryAttr && uomEntryAttr !== '') {
                uomEntry = parseInt(uomEntryAttr);
            }
        } else {
            uomCode = uomCell.textContent.trim();
        }
    }
    
    // UoMCode yoksa hata ver
    if (!uomCode) {
        alert('Birim bilgisi (UoMCode) bulunamadı. Lütfen sayfayı yenileyin veya farklı bir ürün seçin.');
        console.error('UoMCode bulunamadı:', { itemCode, row });
        return;
    }
    
    // WarehouseCode boşsa hata ver
    if (!warehouseCode) {
        alert('Depo bilgisi bulunamadı. Lütfen sayfayı yenileyin.');
        console.error('WarehouseCode bulunamadı:', { itemCode, row });
        return;
    }
    
    // Sepete ekle
    const cartItem = {
        ItemCode: itemCode,
        ItemName: itemName,
        WarehouseCode: warehouseCode,
        UoMCode: uomCode,
        UoMEntry: uomEntry,
        CountedQuantity: quantity
    };
    
    updateDebug('Sepete Eklendi', cartItem);
    cart.push(cartItem);
    updateCartTable();
}

function updateCartTable() {
    const tbody = document.getElementById('cartTableBody');
    tbody.innerHTML = '';
    
    if (cart.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${isUpdateMode ? '7' : '6'}" class="empty-message">Sepet boş</td></tr>`;
        return;
    }
    
    cart.forEach((item, index) => {
        const row = document.createElement('tr');
        if (isUpdateMode && item.LineNum !== null && item.LineNum !== undefined) {
            row.setAttribute('data-line-num', item.LineNum);
        }
        row.setAttribute('data-item-code', item.ItemCode);
        
        let html = '';
        if (isUpdateMode) {
            html += `<td>${item.LineNum !== null && item.LineNum !== undefined ? item.LineNum : '-'}</td>`;
        }
        html += `
            <td>${item.ItemCode}</td>
            <td>${item.ItemName}</td>
            <td>${item.WarehouseCode}</td>
            <td>${item.UoMCode}</td>
            <td>
                <input type="number" class="input-small" value="${item.CountedQuantity}" step="0.01" min="0" onchange="updateCartQuantity(this, ${index})">
            </td>
            <td>
                <button class="btn btn-danger btn-small" onclick="removeFromCart(this, ${index})">Sil</button>
            </td>
        `;
        row.innerHTML = html;
        tbody.appendChild(row);
    });
}

function updateCartQuantity(input, index) {
    if (index !== undefined) {
        cart[index].CountedQuantity = parseFloat(input.value) || 0;
    } else {
        const row = input.closest('tr');
        const itemCode = row.getAttribute('data-item-code');
        const item = cart.find(c => c.ItemCode === itemCode);
        if (item) {
            item.CountedQuantity = parseFloat(input.value) || 0;
        }
    }
}

function removeFromCart(btn, index) {
    if (index !== undefined) {
        cart.splice(index, 1);
    } else {
        const row = btn.closest('tr');
        const itemCode = row.getAttribute('data-item-code');
        cart = cart.filter(c => c.ItemCode !== itemCode);
    }
    updateCartTable();
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
    
    // Cart'ı formatla
    const lines = cart.map(item => {
        const line = {
            ItemCode: item.ItemCode,
            WarehouseCode: item.WarehouseCode,
            CountedQuantity: item.CountedQuantity
        };
        
        if (isUpdateMode && item.LineNum !== null && item.LineNum !== undefined) {
            line.LineNum = item.LineNum;
        } else if (!isUpdateMode || item.LineNum === null || item.LineNum === undefined) {
            if (item.UoMEntry) {
                line.UoMEntry = item.UoMEntry;
            } else if (item.UoMCode) {
                line.UoMCode = item.UoMCode;
            }
        }
        
        return line;
    });
    
    formData.append('lines', JSON.stringify(lines));
    
    // Debug: Gönderilen verileri göster
    const debugData = {
        action: isUpdateMode ? 'update' : 'create',
        warehouseCode: warehouseCode,
        countDate: countDate,
        remarks: remarks,
        lines: lines,
        cart: cart
    };
    updateDebug('Gönderilen Veriler', debugData);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        // Debug: Response'u göster
        updateDebug('Response', data);
        
        if (data.success) {
            const docEntry = data.DocumentEntry || documentEntry;
            window.location.href = 'StokSayimDetay.php?DocumentEntry=' + docEntry;
        } else {
            alert('Hata: ' + data.message);
        }
    })
    .catch(err => {
        console.error('Error:', err);
        updateDebug('Fetch Error', {error: err.message, stack: err.stack});
        alert('Bir hata oluştu');
    });
}

function updateDebug(title, data) {
    const debugContent = document.getElementById('debugContent');
    if (debugContent) {
        const timestamp = new Date().toLocaleTimeString('tr-TR');
        const debugText = `[${timestamp}] ${title}:\n${JSON.stringify(data, null, 2)}\n\n${debugContent.textContent}`;
        debugContent.textContent = debugText;
    }
}

function clearDebug() {
    const debugContent = document.getElementById('debugContent');
    if (debugContent) {
        debugContent.textContent = 'Debug bilgileri burada görünecek...';
    }
}

// Güncelle modunda depo seçilmişse ürünleri yükle
<?php if ($isUpdateMode && !empty($existingWarehouse)): ?>
document.addEventListener('DOMContentLoaded', function() {
    loadItems();
});
<?php endif; ?>
    </script>
</body>
</html>
