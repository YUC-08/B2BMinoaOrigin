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
$branch = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? $_SESSION["Branch"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

$docEntry = $_GET['docEntry'] ?? '';

if (empty($docEntry)) {
    header("Location: Transferler.php?view=incoming");
    exit;
}

// InventoryTransferRequest'i çek
$docQuery = "InventoryTransferRequests({$docEntry})";
$docData = $sap->get($docQuery);
$requestData = $docData['response'] ?? null;

if (!$requestData) {
    die("Transfer talebi bulunamadı!");
}

// STATUS kontrolü: Eğer zaten tamamlandı (4) ise teslim al işlemi yapılamaz
$currentStatus = $requestData['U_ASB2B_STATUS'] ?? '0';
if ($currentStatus == '4') {
    $_SESSION['error_message'] = "Bu transfer zaten tamamlanmış!";
    header("Location: Transferler.php?view=incoming");
    exit;
}

// Lines'ı çek - çoklu deneme stratejisi
$lines = [];
$linesQuery1 = "InventoryTransferRequests({$docEntry})?\$expand=InventoryTransferRequestLines";
$linesData1 = $sap->get($linesQuery1);
if (($linesData1['status'] ?? 0) == 200) {
    $response1 = $linesData1['response'] ?? null;
    if ($response1 && isset($response1['InventoryTransferRequestLines']) && is_array($response1['InventoryTransferRequestLines'])) {
        $lines = $response1['InventoryTransferRequestLines'];
    }
}

if (empty($lines)) {
    $linesQuery2 = "InventoryTransferRequests({$docEntry})?\$expand=StockTransferLines";
    $linesData2 = $sap->get($linesQuery2);
    if (($linesData2['status'] ?? 0) == 200) {
        $response2 = $linesData2['response'] ?? null;
        if ($response2 && isset($response2['StockTransferLines']) && is_array($response2['StockTransferLines'])) {
            $lines = $response2['StockTransferLines'];
        }
    }
}

if (empty($lines)) {
    $linesQuery3 = "InventoryTransferRequests({$docEntry})/InventoryTransferRequestLines";
    $linesData3 = $sap->get($linesQuery3);
    if (($linesData3['status'] ?? 0) == 200) {
        $response3 = $linesData3['response'] ?? null;
        if ($response3) {
            if (isset($response3['value']) && is_array($response3['value'])) {
                $lines = $response3['value'];
            } elseif (is_array($response3) && !isset($response3['value'])) {
                $lines = $response3;
            }
        }
    }
}

if (empty($lines)) {
    $linesQuery4 = "InventoryTransferRequests({$docEntry})/StockTransferLines";
    $linesData4 = $sap->get($linesQuery4);
    if (($linesData4['status'] ?? 0) == 200) {
        $response4 = $linesData4['response'] ?? null;
        if ($response4) {
            if (isset($response4['value']) && is_array($response4['value'])) {
                $lines = $response4['value'];
            } elseif (is_array($response4) && !isset($response4['value']) && !isset($response4['@odata.context'])) {
                $lines = $response4;
            } elseif (isset($response4['StockTransferLines'])) {
                $stockTransferLines = $response4['StockTransferLines'];
                if (is_array($stockTransferLines) && !isset($stockTransferLines[0]) && !empty($stockTransferLines)) {
                    $lines = array_values($stockTransferLines);
                } elseif (is_array($stockTransferLines)) {
                    $lines = $stockTransferLines;
                }
            }
        }
    }
}

$fromWarehouse = $requestData['FromWarehouse'] ?? '';
$toWarehouse = $requestData['ToWarehouse'] ?? ''; // Bu sevkiyat deposu

// Alıcı şubenin ana deposunu bul (U_ASB2B_MAIN='1')
$targetWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '1'";
$targetWarehouseQuery = "Warehouses?\$filter=" . urlencode($targetWarehouseFilter);
$targetWarehouseData = $sap->get($targetWarehouseQuery);
$targetWarehouses = $targetWarehouseData['response']['value'] ?? [];
$targetWarehouse = !empty($targetWarehouses) ? $targetWarehouses[0]['WarehouseCode'] : null;

if (empty($targetWarehouse)) {
    die("Hedef depo (U_ASB2B_MAIN=1) bulunamadı!");
}

// Sevkiyat deposunu kontrol et (request'teki ToWarehouse sevkiyat deposu olmalı)
// Eğer değilse, sevkiyat deposunu bul
$sevkiyatDepoFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '2'";
$sevkiyatDepoQuery = "Warehouses?\$filter=" . urlencode($sevkiyatDepoFilter);
$sevkiyatDepoData = $sap->get($sevkiyatDepoQuery);
$sevkiyatDepolar = $sevkiyatDepoData['response']['value'] ?? [];
$sevkiyatDepo = !empty($sevkiyatDepolar) ? $sevkiyatDepolar[0]['WarehouseCode'] : $toWarehouse;

// Fire & Zayi deposunu bul
$fireZayiWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '3'";
$fireZayiWarehouseQuery = "Warehouses?\$filter=" . urlencode($fireZayiWarehouseFilter);
$fireZayiWarehouseData = $sap->get($fireZayiWarehouseQuery);
$fireZayiWarehouses = $fireZayiWarehouseData['response']['value'] ?? [];
$fireZayiWarehouse = !empty($fireZayiWarehouses) ? $fireZayiWarehouses[0]['WarehouseCode'] : null;

if (empty($fireZayiWarehouse)) {
    $fireZayiWarehouseFilter2 = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_FIREZAYI eq 'Y'";
    $fireZayiWarehouseQuery2 = "Warehouses?\$filter=" . urlencode($fireZayiWarehouseFilter2);
    $fireZayiWarehouseData2 = $sap->get($fireZayiWarehouseQuery2);
    $fireZayiWarehouses2 = $fireZayiWarehouseData2['response']['value'] ?? [];
    $fireZayiWarehouse = !empty($fireZayiWarehouses2) ? $fireZayiWarehouses2[0]['WarehouseCode'] : null;
}

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
            echo json_encode(['success' => false, 'message' => 'Bu transfer zaten tamamlanmış!']);
            exit;
        }
        // Fresh data'yı kullan
        $requestData = $freshRequestInfo;
    }

    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'Transfer satırları bulunamadı!']);
        exit;
    }
    
    // POST işleminde sevk miktarını tekrar çek
    $sevkMiktarMap = [];
    $docEntryInt = (int)$docEntry;
    $sevkQuery = "StockTransfers?\$filter=U_ASB2B_QutMaster%20eq%20{$docEntryInt}"
               . "&\$orderby=DocEntry%20asc"
               . "&\$top=1";
    $sevkData = $sap->get($sevkQuery);
    $sevkList = $sevkData['response']['value'] ?? [];
    $outgoingStockTransferInfo = $sevkList[0] ?? null;
    
    if ($outgoingStockTransferInfo) {
        $stDocEntry = $outgoingStockTransferInfo['DocEntry'] ?? null;
        
        if ($stDocEntry) {
            // StockTransferLines'ı ayrı query ile çek
            $stLinesQuery = "StockTransfers({$stDocEntry})/StockTransferLines";
            $stLinesData = $sap->get($stLinesQuery);
            
            if (($stLinesData['status'] ?? 0) == 200) {
                $stLinesResponse = $stLinesData['response'] ?? null;
                $stLines = [];
                
                if (isset($stLinesResponse['value']) && is_array($stLinesResponse['value'])) {
                    $stLines = $stLinesResponse['value'];
                } elseif (is_array($stLinesResponse) && !isset($stLinesResponse['value'])) {
                    $stLines = $stLinesResponse;
                }
                
                foreach ($stLines as $stLine) {
                    $itemCode = $stLine['ItemCode'] ?? '';
                    $qty = (float)($stLine['Quantity'] ?? 0);
                    
                    if ($itemCode === '' || $qty <= 0) {
                        continue;
                    }
                    
                    // Fire/Zayi'yi filtrele
                    $lost = trim($stLine['U_ASB2B_LOST'] ?? '');
                    $damaged = trim($stLine['U_ASB2B_Damaged'] ?? '');
                    if (($lost !== '' && $lost !== '-') || ($damaged !== '' && $damaged !== '-')) {
                        continue;
                    }
                    
                    if (!isset($sevkMiktarMap[$itemCode])) {
                        $sevkMiktarMap[$itemCode] = 0;
                    }
                    $sevkMiktarMap[$itemCode] += $qty;
                }
            }
        }
    }
    
    // Eğer sevk miktarı bulunamazsa, talep miktarını kullan (eksik/fazla = 0 olacak)
    foreach ($lines as $line) {
        $itemCode = $line['ItemCode'] ?? '';
        if ($itemCode === '') continue;
        
        if (!isset($sevkMiktarMap[$itemCode]) || $sevkMiktarMap[$itemCode] == 0) {
            // Sevk miktarı bulunamadı, talep miktarını kullan
            $quantity = floatval($line['Quantity'] ?? 0);
            $sevkMiktarMap[$itemCode] = $quantity;
        }
    }

    $transferLines = [];
    $headerComments = [];

    foreach ($lines as $index => $line) {
        // Sevk miktarı (read-only) - StockTransferLines'dan
        $itemCode = $line['ItemCode'] ?? '';
        $sevkMiktari = $sevkMiktarMap[$itemCode] ?? 0;
        $baseQty = floatval($line['BaseQty'] ?? 1.0);
        // Normalize et (BaseQty'ye böl)
        $sevkMiktariNormalized = $baseQty > 0 ? ($sevkMiktari / $baseQty) : $sevkMiktari;
        
        // Eksik/Fazla miktar (cebirsel - negatif/pozitif olabilir)
        $eksikFazlaQty = floatval($_POST['eksik_fazla'][$index] ?? 0);
        
        // Kusurlu miktar (min 0)
        $kusurluQty = floatval($_POST['kusurlu'][$index] ?? 0);
        if ($kusurluQty < 0) $kusurluQty = 0;
        
        // Talep miktarı
        $talepMiktar = floatval($line['Quantity'] ?? 0);
        $talepMiktarNormalized = $baseQty > 0 ? ($talepMiktar / $baseQty) : $talepMiktar;
        
        // Fiziksel miktar = Talep + Eksik/Fazla (Sevk ile aynı olmalı)
        // Kullanıcı eksik/fazla değiştirdiğinde anlık güncellenir
        $fizikselMiktar = $talepMiktarNormalized + $eksikFazlaQty;
        if ($fizikselMiktar < 0) $fizikselMiktar = 0;
        
        // Kusurlu miktar fiziksel miktarı aşamaz
        if ($kusurluQty > $fizikselMiktar) {
            $kusurluQty = $fizikselMiktar;
        }
        
        $not = trim($_POST['not'][$index] ?? '');
        
        $itemCode = $line['ItemCode'] ?? '';
        $itemName = $line['ItemDescription'] ?? $itemCode;
        
        // NOT: Fire/Zayi belgesinin açıklamasında sadece kusurlu miktarlar görünecek
        // Eksik/Fazla miktarlar sevkiyat deposuna gidiyor, bu yüzden burada görünmemeli
        if ($kusurluQty > 0) {
            $commentParts = [];
            $commentParts[] = "Kusurlu: {$kusurluQty}";
            
            if (!empty($not)) {
                $commentParts[] = "Not: {$not}";
            }
            
            $headerComments[] = "{$itemCode} ({$itemName}): " . implode(", ", $commentParts);
        }
        
        // ÖNEMLİ: Normal transfer satırı = Sevk miktarı (kusurlu hariç)
        // Kusurlu miktar ayrı bir satır olarak Fire & Zayi deposuna gidecek
        // Normal transfer miktarı = Sevk miktarı
        $normalTransferMiktar = $sevkMiktariNormalized;
        if ($normalTransferMiktar < 0) {
            $normalTransferMiktar = 0;
        }
        
        // Normal transfer miktarı > 0 ise StockTransfer için hazırla
        if ($normalTransferMiktar > 0) {
            $baseQty = floatval($line['BaseQty'] ?? 1.0);
            $transferLines[] = [
                'ItemCode' => $itemCode,
                'Quantity' => $normalTransferMiktar * $baseQty, // BaseQty ile çarp (Sevk miktarı)
                'FromWarehouseCode' => $sevkiyatDepo, // Sevkiyat deposu (ikinci transfer: sevkiyat -> ana depo)
                'WarehouseCode' => $targetWarehouse // Ana depo (U_ASB2B_MAIN=1)
            ];
        }
        
        // Eksik/Fazla miktar varsa → Sevkiyat deposuna
        if ($eksikFazlaQty != 0) {
            $eksikFazlaMiktar = abs($eksikFazlaQty);
            $baseQty = floatval($line['BaseQty'] ?? 1.0);
            
            $eksikFazlaLine = [
                'ItemCode' => $itemCode,
                'Quantity' => $eksikFazlaMiktar * $baseQty,
                'FromWarehouseCode' => $targetWarehouse, // Ana depodan sevkiyat deposuna
                'WarehouseCode' => $sevkiyatDepo, // Sevkiyat deposu (U_ASB2B_MAIN='2')
            ];
            
            // Eksik ise (negatif) → Zayi
            if ($eksikFazlaQty < 0) {
                $eksikFazlaLine['U_ASB2B_LOST'] = '2'; // Zayi
                $eksikFazlaLine['U_ASB2B_Damaged'] = 'E'; // Eksik
            } else {
                // Fazla ise (pozitif) → Fire
                $eksikFazlaLine['U_ASB2B_LOST'] = '1'; // Fire
            }
            
            $eksikFazlaComments = [];
            if (!empty($not)) {
                $eksikFazlaComments[] = $not;
            }
            if ($eksikFazlaQty < 0) {
                $eksikFazlaComments[] = "Eksik: {$eksikFazlaMiktar} adet";
            } else {
                $eksikFazlaComments[] = "Fazla: {$eksikFazlaMiktar} adet";
            }
            $eksikFazlaComments[] = 'Sevkiyat Deposu';
            $eksikFazlaLine['U_ASB2B_Comments'] = implode(' | ', $eksikFazlaComments);
            
            $transferLines[] = $eksikFazlaLine;
        }
        
        // Kusurlu miktar varsa → Fire & Zayi deposuna
        if ($kusurluQty > 0) {
            if (empty($fireZayiWarehouse)) {
                echo json_encode(['success' => false, 'message' => 'Kusurlu miktar var ancak Fire & Zayi deposu bulunamadı! Lütfen sistem yöneticisine başvurun.']);
                exit;
            }
            
            $baseQty = floatval($line['BaseQty'] ?? 1.0);
            $fireZayiLine = [
                'ItemCode' => $itemCode,
                'Quantity' => $kusurluQty * $baseQty,
                'FromWarehouseCode' => $targetWarehouse, // Ana depodan Fire & Zayi deposuna
                'WarehouseCode' => $fireZayiWarehouse, // Fire & Zayi deposu (U_ASB2B_MAIN='3')
                'U_ASB2B_Damaged' => 'K' // Kusurlu
            ];
            
            $fireZayiComments = [];
            if (!empty($not)) {
                $fireZayiComments[] = $not;
            }
            $fireZayiComments[] = "Kusurlu: {$kusurluQty} adet";
            $fireZayiComments[] = 'Fire & Zayi';
            $fireZayiLine['U_ASB2B_Comments'] = implode(' | ', $fireZayiComments);
            
            $transferLines[] = $fireZayiLine;
        }
    }

    if (empty($transferLines)) {
        echo json_encode(['success' => false, 'message' => 'İşlenecek kalem bulunamadı! Lütfen en az bir kalem için teslim alın.']);
        exit;
    }

    $docDate = $requestData['DocDate'] ?? date('Y-m-d');
    $headerCommentsText = !empty($headerComments) ? implode(" | ", $headerComments) : '';

    // İkinci StockTransfer oluştur (sevkiyat depo -> ana depo)
    // FromWarehouse: Sevkiyat deposu
    // ToWarehouse: Ana depo (U_ASB2B_MAIN=1)
    // NOT: İlişki U_ASB2B_QutMaster ve depo yönü ile kuruluyor:
    // - İlk ST: U_ASB2B_QutMaster = requestDocEntry, ToWarehouse = sevkiyatDepo
    // - İkinci ST: U_ASB2B_QutMaster = requestDocEntry, FromWarehouse = sevkiyatDepo, ToWarehouse = anaDepo
    // Her iki StockTransfer de aynı InventoryTransferRequest'i DocumentReferences ile referans gösterir
    $docNum = $requestData['DocNum'] ?? $docEntry;
    
    // Header'da U_ASB2B_LOST set etmek için: Eğer herhangi bir satırda Fire/Zayi varsa
    $headerLost = null; // null = normal transfer, '1' = Fire, '2' = Zayi
    foreach ($transferLines as $line) {
        $lost = $line['U_ASB2B_LOST'] ?? null;
        $damaged = $line['U_ASB2B_Damaged'] ?? null;
        
        // Eğer satırda Fire/Zayi varsa, header'a da set et
        if ($lost == '1' || $lost == '2') {
            // Eğer daha önce Zayi bulunduysa ve şimdi Fire bulunuyorsa, Fire öncelikli
            // Eğer daha önce Fire bulunduysa ve şimdi Zayi bulunuyorsa, Fire kalır
            if ($headerLost === null || $headerLost == '2') {
                $headerLost = $lost;
            }
        } elseif ($damaged == 'K' || $damaged == 'E') {
            // Kusurlu veya Eksik varsa ama U_ASB2B_LOST yoksa, Zayi olarak işaretle
            if ($headerLost === null) {
                $headerLost = '2'; // Zayi
            }
        }
    }
    
    $stockTransferPayload = [
        'FromWarehouse' => $sevkiyatDepo, // Sevkiyat deposu (ikinci transfer)
        'ToWarehouse' => $targetWarehouse, // Ana depo (U_ASB2B_MAIN=1)
        'DocDate' => $docDate,
        'Comments' => $headerCommentsText,
        'U_ASB2B_BRAN' => $branch,
        'U_AS_OWNR' => $uAsOwnr,
        'U_ASB2B_STATUS' => '4', // Tamamlandı
        'U_ASB2B_TYPE' => 'TRANSFER',
        'U_ASB2B_User' => $_SESSION["UserName"] ?? '',
        'U_ASB2B_QutMaster' => (int)$docEntry, // InventoryTransferRequest DocEntry (ilişki bu alan ile kuruluyor)
        'DocumentReferences' => [
            [
                'RefDocEntr' => (int)$docEntry,
                'RefDocNum' => (int)$docNum,
                'RefObjType' => 'rot_InventoryTransferRequest'
            ]
        ],
        'StockTransferLines' => $transferLines
    ];
    
    // Eğer Fire/Zayi varsa header'a da ekle
    if ($headerLost !== null) {
        $stockTransferPayload['U_ASB2B_LOST'] = $headerLost;
    }

    $result = $sap->post('StockTransfers', $stockTransferPayload);

    if ($result['status'] == 200 || $result['status'] == 201) {
        // StockTransfer başarıyla oluşturuldu
        $stockTransferResponse = $result['response'] ?? [];
        
        // InventoryTransferRequest'i Close et
        $closeResult = $sap->post("InventoryTransferRequests({$docEntry})/Close", []);
        
        // STATUS'u her zaman 4'e güncelle (Close başarılı olsa bile)
        $updatePayload = [
            'U_ASB2B_STATUS' => '4'
        ];
        $updateResult = $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);
        
        // Close başarısız olsa bile STATUS güncellendi, devam et
        $_SESSION['success_message'] = "Transfer başarıyla teslim alındı!";
        header('Location: Transferler.php?view=incoming');
        exit;
    } else {
        $errorMsg = 'Teslim alma işlemi başarısız! HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        $_SESSION['error_message'] = $errorMsg;
        header('Location: Transferler.php?view=incoming');
        exit;
    }
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// Warehouse isimlerini çek
$warehouseNamesMap = [];
$fromWhsName = '';
$toWhsName = '';

if (!empty($fromWarehouse)) {
    $whsQuery = "Warehouses('{$fromWarehouse}')?\$select=WarehouseName";
    $whsData = $sap->get($whsQuery);
    if (($whsData['status'] ?? 0) == 200) {
        $fromWhsName = $whsData['response']['WarehouseName'] ?? '';
    }
}

if (!empty($toWarehouse)) {
    $whsQuery2 = "Warehouses('{$toWarehouse}')?\$select=WarehouseName";
    $whsData2 = $sap->get($whsQuery2);
    if (($whsData2['status'] ?? 0) == 200) {
        $toWhsName = $whsData2['response']['WarehouseName'] ?? '';
    }
}

$docDate = formatDate($requestData['DocDate'] ?? '');
$dueDate = formatDate($requestData['DueDate'] ?? '');
$numAtCard = htmlspecialchars($requestData['U_ASB2B_NumAtCard'] ?? '-');
$status = $requestData['U_ASB2B_STATUS'] ?? '';

// Sevk miktarını çek (İlk StockTransfer'den)
$sevkMiktarMap = [];
$docEntryInt = (int)$docEntry;
$sevkQuery = "StockTransfers?\$filter=U_ASB2B_QutMaster%20eq%20{$docEntryInt}"
           . "&\$orderby=DocEntry%20asc"
           . "&\$top=1";
$sevkData = $sap->get($sevkQuery);
$sevkList = $sevkData['response']['value'] ?? [];
$outgoingStockTransferInfo = $sevkList[0] ?? null;

if ($outgoingStockTransferInfo) {
    $stDocEntry = $outgoingStockTransferInfo['DocEntry'] ?? null;
    
    if ($stDocEntry) {
        // StockTransferLines'ı ayrı query ile çek
        $stLinesQuery = "StockTransfers({$stDocEntry})/StockTransferLines";
        $stLinesData = $sap->get($stLinesQuery);
        
        if (($stLinesData['status'] ?? 0) == 200) {
            $stLinesResponse = $stLinesData['response'] ?? null;
            $stLines = [];
            
            if (isset($stLinesResponse['value']) && is_array($stLinesResponse['value'])) {
                $stLines = $stLinesResponse['value'];
            } elseif (is_array($stLinesResponse) && !isset($stLinesResponse['value'])) {
                $stLines = $stLinesResponse;
            }
            
            foreach ($stLines as $stLine) {
                $itemCode = $stLine['ItemCode'] ?? '';
                $qty = (float)($stLine['Quantity'] ?? 0);
                
                if ($itemCode === '' || $qty <= 0) {
                    continue;
                }
                
                // Fire/Zayi'yi filtrele
                $lost = trim($stLine['U_ASB2B_LOST'] ?? '');
                $damaged = trim($stLine['U_ASB2B_Damaged'] ?? '');
                if (($lost !== '' && $lost !== '-') || ($damaged !== '' && $damaged !== '-')) {
                    continue;
                }
                
                if (!isset($sevkMiktarMap[$itemCode])) {
                    $sevkMiktarMap[$itemCode] = 0;
                }
                $sevkMiktarMap[$itemCode] += $qty;
            }
        }
    }
}

// Her line için BaseQty ve normalize edilmiş miktarları hesapla
foreach ($lines as &$line) {
    $baseQty = floatval($line['BaseQty'] ?? 1.0);
    $quantity = floatval($line['Quantity'] ?? 0);
    $itemCode = $line['ItemCode'] ?? '';
    
    $line['_BaseQty'] = $baseQty;
    $line['_RequestedQty'] = $baseQty > 0 ? ($quantity / $baseQty) : $quantity;
    
    // Sevk miktarını normalize et (BaseQty'ye böl)
    // Eğer sevk miktarı bulunamazsa (StockTransfer belgesi yoksa veya SAP tarafından manuel onaylandıysa),
    // sevk miktarı = talep miktarı olarak varsayılır (eksik/fazla = 0)
    $sevkQty = $sevkMiktarMap[$itemCode] ?? null;
    if ($sevkQty === null || $sevkQty == 0) {
        // Sevk miktarı bulunamadı, talep miktarını kullan (eksik/fazla = 0 olacak)
        $sevkQty = $quantity;
    }
    $line['_SevkQty'] = $baseQty > 0 ? ($sevkQty / $baseQty) : $sevkQty;
}
unset($line);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Teslim Al - MINOA</title>
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
}

.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
}

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #eff6ff;
    transform: translateY(-2px);
}

.content-wrapper {
    padding: 24px 32px;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    padding: 2rem;
    margin: 24px 32px 2rem 32px;
}

.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin: 24px 32px 1.5rem 32px;
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

.data-table th:nth-child(4),
.data-table th:nth-child(5),
.data-table th:nth-child(6),
.data-table th:nth-child(7),
.data-table th:nth-child(8) {
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

.data-table td:nth-child(4),
.data-table td:nth-child(5),
.data-table td:nth-child(6),
.data-table td:nth-child(7),
.data-table td:nth-child(8) {
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

input[name^="eksik_fazla"] {
    font-weight: 500;
}

.eksik-fazla-negatif {
    color: #dc2626 !important;
}

.eksik-fazla-pozitif {
    color: #16a34a !important;
}

.eksik-fazla-sifir {
    color: #6b7280 !important;
}

.notes-textarea {
    width: 100%;
    min-width: 150px;
    padding: 0.5rem;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 0.875rem;
    resize: vertical;
    font-family: inherit;
    transition: border-color 0.2s;
}

.notes-textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #374151;
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
        <h2>Teslim Al - Transfer No: <?= htmlspecialchars($docEntry) ?></h2>
        <button class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=incoming'">← Geri Dön</button>
    </header>

    <?php if (empty($lines)): ?>
        <div class="card">
            <p style="color: #ef4444; font-weight: 600; margin-bottom: 1rem;">⚠️ Transfer satırları bulunamadı!</p>
        </div>
    <?php else: ?>

        <!-- Transfer bilgi kartı -->
        <div class="card">
            <h3 style="margin-bottom: 1rem; color: #1e40af;">Transfer Bilgileri</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Transfer No</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($docEntry) ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Gönderen Şube</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($fromWarehouse) ?><?= !empty($fromWhsName) ? ' / ' . htmlspecialchars($fromWhsName) : '' ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Transfer Tarihi</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= $docDate ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Vade Tarihi</div>
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
                            <th>Talep Miktarı</th>
                            <th>Sevk Miktarı</th>
                            <th>Eksik/Fazla Miktar</th>
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
                        $requestedQty = floatval($line['_RequestedQty'] ?? 0);
                        $sevkQty = floatval($line['_SevkQty'] ?? 0);
                        $uomCode = $line['UoMCode'] ?? 'AD';
                        
                        // Eksik/Fazla miktarını otomatik hesapla
                        // Talep > Sevk ise → Eksik (negatif)
                        // Talep < Sevk ise → Fazla (pozitif)
                        // Talep = Sevk ise → 0
                        $eksikFazlaOtomatik = $sevkQty - $requestedQty;
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($itemCode) ?></td>
                            <td><?= htmlspecialchars($itemName) ?></td>
                            <td>
                                <div style="display: flex; align-items: center; justify-content: center; gap: 4px;">
                                    <input type="number"
                                           id="talep_<?= $index ?>"
                                           value="<?= htmlspecialchars($requestedQty) ?>"
                                           readonly
                                           step="0.01"
                                           class="qty-input">
                                    <span style="font-size: 0.875rem; color: #6b7280; font-weight: 500;"><?= htmlspecialchars($uomCode) ?></span>
                                </div>
                            </td>
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
                                    <button type="button" class="qty-btn" onclick="changeEksikFazla(<?= $index ?>, -1)">-</button>
                                    <input type="number"
                                           name="eksik_fazla[<?= $index ?>]"
                                           id="eksik_<?= $index ?>"
                                           value="<?= htmlspecialchars($eksikFazlaOtomatik) ?>"
                                           step="0.01"
                                           class="qty-input"
                                           onchange="calculatePhysical(<?= $index ?>)"
                                           oninput="updateEksikFazlaColor(this); calculatePhysical(<?= $index ?>)">
                                    <button type="button" class="qty-btn" onclick="changeEksikFazla(<?= $index ?>, 1)">+</button>
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
                                           value="0"
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
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=incoming'">İptal</button>
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

function changeEksikFazla(index, delta) {
    const input = document.getElementById('eksik_' + index);
    if (!input) return;
    
    let value = parseFloat(input.value) || 0;
    value += delta;
    input.value = value;
    updateEksikFazlaColor(input);
    calculatePhysical(index);
}

function updateEksikFazlaColor(input) {
    if (!input) return;
    const value = parseFloat(input.value) || 0;
    input.classList.remove('eksik-fazla-negatif', 'eksik-fazla-pozitif', 'eksik-fazla-sifir');
    
    if (value < 0) {
        input.classList.add('eksik-fazla-negatif');
    } else if (value > 0) {
        input.classList.add('eksik-fazla-pozitif');
    } else {
        input.classList.add('eksik-fazla-sifir');
    }
}

function changeKusurlu(index, delta) {
    const input = document.getElementById('kusurlu_' + index);
    if (!input) return;
    
    const talepInput = document.getElementById('talep_' + index);
    const eksikFazlaInput = document.getElementById('eksik_' + index);
    if (!talepInput || !eksikFazlaInput) return;
    
    const talep = parseFloat(talepInput.value) || 0;
    const eksikFazla = parseFloat(eksikFazlaInput.value) || 0;
    // Fiziksel = Talep + Eksik/Fazla
    const fizikselMiktar = Math.max(0, talep + eksikFazla);
    
    let value = parseFloat(input.value) || 0;
    value += delta;
    if (value < 0) value = 0;
    if (value > fizikselMiktar) value = fizikselMiktar;
    input.value = value;
    calculatePhysical(index);
}

function calculatePhysical(index) {
    const talepInput = document.getElementById('talep_' + index);
    const sevkInput = document.getElementById('sevk_' + index);
    const eksikFazlaInput = document.getElementById('eksik_' + index);
    const kusurluInput = document.getElementById('kusurlu_' + index);
    const fizikselInput = document.getElementById('fiziksel_' + index);
    
    if (!talepInput || !sevkInput || !eksikFazlaInput || !kusurluInput || !fizikselInput) return;
    
    const talep = parseFloat(talepInput.value) || 0;
    const sevk = parseFloat(sevkInput.value) || 0;
    const eksikFazla = parseFloat(eksikFazlaInput.value) || 0;
    let kusurlu = parseFloat(kusurluInput.value) || 0;
    
    // Fiziksel = Talep + Eksik/Fazla (Sevk ile aynı olmalı)
    // Kullanıcı eksik/fazla değiştirdiğinde anlık güncellenir
    let fiziksel = talep + eksikFazla;
    if (fiziksel < 0) fiziksel = 0;
    
    if (kusurlu > fiziksel) {
        kusurlu = fiziksel;
        kusurluInput.value = kusurlu;
    }
    
    let formattedValue;
    if (fiziksel == Math.floor(fiziksel)) {
        formattedValue = Math.floor(fiziksel).toString();
    } else {
        formattedValue = fiziksel.toFixed(2).replace('.', ',').replace(/0+$/, '').replace(/,$/, '');
    }
    
    fizikselInput.value = formattedValue;
}

function validateForm() {
    let hasQuantity = false;
    const eksikFazlaInputs = document.querySelectorAll('input[name^="eksik_fazla"]');
    const sevkInputs = document.querySelectorAll('input[id^="sevk_"]');
    
    sevkInputs.forEach((sevkInput, index) => {
        const talepInput = document.getElementById('talep_' + index);
        const eksikFazla = parseFloat(eksikFazlaInputs[index].value) || 0;
        const talep = parseFloat(talepInput?.value || 0) || 0;
        // Fiziksel = Talep + Eksik/Fazla
        const fiziksel = talep + eksikFazla;
        
        if (fiziksel > 0) {
            hasQuantity = true;
        }
    });
    
    if (!hasQuantity) {
        alert('Lütfen en az bir kalem için teslim alın!');
        return false;
    }
    
    return true;
}
</script>
</body>
</html>