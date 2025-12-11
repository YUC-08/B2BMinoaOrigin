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
$branch  = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? $_SESSION["Branch"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

$docEntry       = $_GET['docEntry']  ?? '';
$filterItemCode = $_GET['itemCode'] ?? '';
$filterLineNum  = $_GET['lineNum']  ?? '';
$isFiltered     = (!empty($filterItemCode) || $filterLineNum !== '');

if (empty($docEntry)) {
    header("Location: Transferler.php?view=incoming");
    exit;
}

/**
 * Yardımcı: Tarihi dd.mm.YYYY formatına çevir
 */
function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

/**
 * Yardımcı: İlgili owner/branch için depo bul (U_ASB2B_MAIN veya U_ASB2B_FIREZAYI gibi)
 */
function findWarehouse($sap, $uAsOwnr, $branch, $filterExpr) {
    $filter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and {$filterExpr}";
    $query  = "Warehouses?\$filter=" . urlencode($filter);
    $data   = $sap->get($query);
    $rows   = $data['response']['value'] ?? [];
    return !empty($rows) ? $rows[0]['WarehouseCode'] : null;
}

/**
 * Yardımcı: Bu request’e bağlı tüm StockTransfer header’larını bul
 * Önce BaseEntry+BaseType, sonra U_ASB2B_QutMaster, en son Comments/U_ASB2B_Parent
 */
function findStockTransfersForRequest($sap, int $docEntryInt): array {
    $stList = [];

    // 1) BaseEntry + BaseType (InventoryTransferRequest = 1250000001)
    $baseQuery = "StockTransfers?\$filter=BaseEntry%20eq%20{$docEntryInt}%20and%20BaseType%20eq%201250000001"
               . "&\$orderby=DocEntry%20asc";
    $baseData  = $sap->get($baseQuery);
    if (($baseData['status'] ?? 0) == 200) {
        $stList = $baseData['response']['value'] ?? [];
    }

    // 2) U_ASB2B_QutMaster ile
    if (empty($stList)) {
        $qutQuery = "StockTransfers?\$filter=U_ASB2B_QutMaster%20eq%20{$docEntryInt}"
                  . "&\$orderby=DocEntry%20asc";
        $qutData  = $sap->get($qutQuery);
        if (($qutData['status'] ?? 0) == 200) {
            $stList = $qutData['response']['value'] ?? [];
        }
    }

    // 3) Comments veya U_ASB2B_Parent ile fallback
    if (empty($stList)) {
        $fallbackQuery = "StockTransfers?\$filter=Comments%20eq%20'{$docEntryInt}'%20or%20U_ASB2B_Parent%20eq%20'{$docEntryInt}'"
                       . "&\$orderby=DocEntry%20asc";
        $fallbackData  = $sap->get($fallbackQuery);
        if (($fallbackData['status'] ?? 0) == 200) {
            $stList = $fallbackData['response']['value'] ?? [];
        }
    }

    return $stList;
}

/**
 * Yardımcı: Bir ST DocEntry için satırları çek
 */
function getStockTransferLines($sap, int $stDocEntry): array {
    $stLinesQuery = "StockTransfers({$stDocEntry})/StockTransferLines";
    $stLinesData  = $sap->get($stLinesQuery);
    if (($stLinesData['status'] ?? 0) != 200) {
        return [];
    }

    $resp = $stLinesData['response'] ?? null;
    if (isset($resp['value']) && is_array($resp['value'])) {
        return $resp['value'];
    }
    if (is_array($resp) && !isset($resp['value'])) {
        return $resp;
    }
    return [];
}

/**
 * Yardımcı: ST1 (sevkiyat) belgelerinden item bazlı sevk miktarlarını topla
 * ST1: ToWarehouse = sevkiyat deposu
 */
function buildSevkMiktarMapFromSt1($sap, int $docEntryInt, string $sevkiyatDepo): array {
    $sevkMiktarMap = [];
    $stList        = findStockTransfersForRequest($sap, $docEntryInt);

    foreach ($stList as $stInfo) {
        $stToWarehouse = $stInfo['ToWarehouse'] ?? '';
        if ($stToWarehouse !== $sevkiyatDepo) {
            continue; // ST1 değil
        }

        $stDocEntry = $stInfo['DocEntry'] ?? null;
        if (!$stDocEntry) {
            continue;
        }

        $stLines = getStockTransferLines($sap, (int)$stDocEntry);
        foreach ($stLines as $stLine) {
            $itemCode = $stLine['ItemCode'] ?? '';
            $qty      = (float)($stLine['Quantity'] ?? 0);
            if ($itemCode === '' || $qty <= 0) {
                continue;
            }

            // Fire/Zayi satırlarını sevk hesabına katma
            $lost    = trim($stLine['U_ASB2B_LOST']    ?? '');
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

    return $sevkMiktarMap;
}

/* ------------------------
 * 1) HEADER + SATIRLAR (VIEW ÖNCELİKLİ)
 * ---------------------- */

$lines       = [];
$requestData = null;

// Önce yeni view'dan çek: ASB2B_TransferRequestList_B1SLQuery
$viewFilter = "DocEntry eq {$docEntry}";
$viewQuery  = "view.svc/ASB2B_TransferRequestList_B1SLQuery?\$filter=" . urlencode($viewFilter);
$viewData   = $sap->get($viewQuery);
$viewRows   = $viewData['response']['value'] ?? [];

if (!empty($viewRows)) {
    // Header bilgisi (ilk satırdan)
    $headerRow = $viewRows[0];

    $requestData = [
        'DocEntry'           => $docEntry,
        'DocDate'            => $headerRow['DocDate']      ?? null,
        'DueDate'            => $headerRow['DocDueDate']   ?? null,
        'U_ASB2B_NumAtCard'  => $headerRow['U_ASB2B_NumAtCard'] ?? null,
        'U_ASB2B_STATUS'     => $headerRow['U_ASB2B_STATUS']    ?? '0',
        'FromWarehouse'      => $headerRow['FromWhsCode']  ?? '',
        'ToWarehouse'        => $headerRow['WhsCode']      ?? '',
    ];

    foreach ($viewRows as $row) {
        $lines[] = [
            'ItemCode'        => $row['ItemCode'] ?? '',
            'ItemDescription' => $row['Dscription'] ?? ($row['ItemCode'] ?? ''),
            'Quantity'        => $row['Quantity'] ?? 0,
            'BaseQty'         => 1.0,            // View'da yoksa 1 kabul ediyoruz
            'UoMCode'         => $row['UoMCode'] ?? 'AD',
            'LineNum'         => $row['LineNum'] ?? null,
        ];
    }
} else {
    // View boş dönerse: Header ve Lines'ı ayrı çek (expand kullanmadan)
    $headerQuery = "InventoryTransferRequests({$docEntry})?\$select=DocEntry,DocDate,DocDueDate,U_ASB2B_NumAtCard,U_ASB2B_STATUS,FromWarehouse,ToWarehouse";
    $headerData = $sap->get($headerQuery);
    $requestData = $headerData['response'] ?? null;

    if (!$requestData) {
        die("Transfer talebi bulunamadı!");
    }

    // Lines'ı ayrı çek
    $linesQuery = "InventoryTransferRequests({$docEntry})/InventoryTransferRequestLines";
    $linesData = $sap->get($linesQuery);
    $linesResponse = $linesData['response'] ?? null;
    
    if (isset($linesResponse['value']) && is_array($linesResponse['value'])) {
        $lines = $linesResponse['value'];
    } elseif (is_array($linesResponse) && !isset($linesResponse['value'])) {
        $lines = $linesResponse;
    } else {
        $lines = [];
    }
}

// STATUS kontrolü: Tamamlandı ise teslim al yapılamaz
$currentStatus = $requestData['U_ASB2B_STATUS'] ?? '0';
if ($currentStatus == '4') {
    $_SESSION['error_message'] = "Bu transfer zaten tamamlanmış!";
    header("Location: Transferler.php?view=incoming");
    exit;
}

// From/To warehouse bilgileri
$fromWarehouse = $requestData['FromWarehouse'] ?? '';
$toWarehouse   = $requestData['ToWarehouse']   ?? ''; // Sevkiyat deposu (ST1 ToWarehouse)

// Tüm satırları sakla (başlık statüsü hesaplaması için)
$allLines = $lines;

// Eğer itemCode veya lineNum parametresi geldiyse sadece o satırı göster
if ((!empty($filterItemCode) || $filterLineNum !== '') && !empty($lines)) {
    $lines = array_values(array_filter($lines, function($line) use ($filterItemCode, $filterLineNum) {
        $itemCode = $line['ItemCode'] ?? '';
        $lineNum  = isset($line['LineNum']) ? (string)$line['LineNum'] : '';
        $matchItem = !empty($filterItemCode) ? ($itemCode === $filterItemCode) : true;
        $matchLine = ($filterLineNum !== '') ? ($lineNum === (string)$filterLineNum) : true;
        return $matchItem && $matchLine;
    }));
}

/* ------------------------
 * 2) HEDEF / SEVK / FIRE-ZAYİ DEPOLARINI BUL
 * ---------------------- */

// DİNAMİK MANTIK: ToWarehouse'daki "-1"i "-0" ile değiştirerek ana depoyu bul
// Örnek: 100-KT-1 (Sevkiyat) -> 100-KT-0 (Ana Depo)
// Bu mantık her şube için çalışır (100, 200, 300, vs.)
$sevkiyatDepo = $toWarehouse; // Mal şu an burada (Sevkiyat -1)
$targetWarehouse = str_replace('-1', '-0', $sevkiyatDepo); // Hedef: Ana Depo (-0)

// Eğer string değişimi çalışmadıysa (örneğin farklı format), fallback olarak findWarehouse kullan
if ($targetWarehouse === $sevkiyatDepo || empty($targetWarehouse)) {
    // Fallback: Eski mantık (U_ASB2B_MAIN ile arama)
    $targetWarehouse = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_MAIN eq '1'");
    if (empty($targetWarehouse)) {
        die("Hedef depo (Ana Depo) bulunamadı! Sevkiyat Depo: {$sevkiyatDepo}");
    }
    
    // Sevkiyat deposunu da kontrol et
    $sevkiyatDepoCheck = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_MAIN eq '2'");
    if (!empty($sevkiyatDepoCheck)) {
        $sevkiyatDepo = $sevkiyatDepoCheck;
    }
}

// Fire & Zayi deposu (önce MAIN = '3', yoksa U_ASB2B_FIREZAYI = 'Y')
$fireZayiWarehouse = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_MAIN eq '3'");
if (empty($fireZayiWarehouse)) {
    $fireZayiWarehouse = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_FIREZAYI eq 'Y'");
}

/* ------------------------
 * 3) POST: TESLİM AL İŞLEMİ
 * ---------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'teslim_al') {
    header('Content-Type: application/json');

    // STATUS kontrolü: fresh data çek
    $freshRequestQuery = "InventoryTransferRequests({$docEntry})";
    $freshRequestData  = $sap->get($freshRequestQuery);
    $freshRequestInfo  = $freshRequestData['response'] ?? null;

    if ($freshRequestInfo) {
        $currentStatus = $freshRequestInfo['U_ASB2B_STATUS'] ?? '0';
        if ($currentStatus == '4') {
            echo json_encode(['success' => false, 'message' => 'Bu transfer zaten tamamlanmış!']);
            exit;
        }
        // POST tarafında en güncel requestData'yı kullan
        $requestData = $freshRequestInfo;
    }

    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'Transfer satırları bulunamadı!']);
        exit;
    }

    // ST1 belgelerinden sevk miktarını tekrar hesapla
    $docEntryInt  = (int)$docEntry;
    $sevkMiktarMap = buildSevkMiktarMapFromSt1($sap, $docEntryInt, $sevkiyatDepo);

    // Sevk bulunamazsa talep miktarı = sevk varsay
    foreach ($lines as $line) {
        $itemCode = $line['ItemCode'] ?? '';
        if ($itemCode === '') continue;

        if (!isset($sevkMiktarMap[$itemCode]) || $sevkMiktarMap[$itemCode] == 0) {
            $quantity = floatval($line['Quantity'] ?? 0);
            $sevkMiktarMap[$itemCode] = $quantity;
        }
    }

    $transferLines = [];
    $headerComments = [];

    foreach ($lines as $index => $line) {
        $itemCode = $line['ItemCode'] ?? '';
        if ($itemCode === '') continue;

        $baseQty  = floatval($line['BaseQty'] ?? 1.0);
        $talepRaw = floatval($line['Quantity'] ?? 0);

        $talepMiktar          = $baseQty > 0 ? ($talepRaw / $baseQty) : $talepRaw;
        $sevkMiktariRaw       = $sevkMiktarMap[$itemCode] ?? 0;
        $sevkMiktari          = $baseQty > 0 ? ($sevkMiktariRaw / $baseQty) : $sevkMiktariRaw;
        $eksikFazlaQty        = floatval($_POST['eksik_fazla'][$index] ?? 0);
        $kusurluQty           = max(0.0, floatval($_POST['kusurlu'][$index] ?? 0));
        $fizikselMiktar       = max(0.0, $talepMiktar + $eksikFazlaQty);
        if ($kusurluQty > $fizikselMiktar) {
            $kusurluQty = $fizikselMiktar;
        }

        $not       = trim($_POST['not'][$index] ?? '');
        $itemName  = $line['ItemDescription'] ?? $itemCode;

        // Fire/Zayi header comment
        if ($kusurluQty > 0) {
            $commentParts   = ["Kusurlu: {$kusurluQty}"];
            if (!empty($not)) $commentParts[] = "Not: {$not}";
            $headerComments[] = "{$itemCode} ({$itemName}): " . implode(", ", $commentParts);
        }

       // Normal transfer satırı (sevkiyat -> ana depo)
       $normalTransferMiktar = max(0.0, $sevkMiktari);
       if ($normalTransferMiktar > 0) {
           $transferLines[] = [
               'ItemCode'         => $itemCode,
               'Quantity'         => $normalTransferMiktar * $baseQty,
               'FromWarehouseCode'=> $sevkiyatDepo,
               'WarehouseCode'    => $targetWarehouse,
               
               // --- BAĞLANTI HARİTASI İÇİN EKLENDİ ---
               'BaseType'         => 1250000001,           // Stok Nakli Talebi ObjType
               'BaseEntry'        => (int)$docEntry,       // Hangi talep?
               'BaseLine'         => (int)($line['LineNum'] ?? 0) // Hangi satır?
               // --------------------------------------
           ];
       }

        // Eksik/Fazla için sevkiyat depo hareketi
        if ($eksikFazlaQty != 0) {
            $eksikFazlaMiktar = abs($eksikFazlaQty);
            $eksikFazlaLine   = [
                'ItemCode'         => $itemCode,
                'Quantity'         => $eksikFazlaMiktar * $baseQty,
                'FromWarehouseCode'=> $targetWarehouse,
                'WarehouseCode'    => $sevkiyatDepo,
            ];

            if ($eksikFazlaQty < 0) {
                // Eksik → Zayi
                $eksikFazlaLine['U_ASB2B_LOST']    = '2';
                $eksikFazlaLine['U_ASB2B_Damaged'] = 'E';
            } else {
                // Fazla → Fire
                $eksikFazlaLine['U_ASB2B_LOST'] = '1';
            }

            $eksikFazlaComments = [];
            if (!empty($not)) {
                $eksikFazlaComments[] = $not;
            }
            $eksikFazlaComments[] = ($eksikFazlaQty < 0)
                ? "Eksik: {$eksikFazlaMiktar} adet"
                : "Fazla: {$eksikFazlaMiktar} adet";
            $eksikFazlaComments[] = 'Sevkiyat Deposu';
            $eksikFazlaLine['U_ASB2B_Comments'] = implode(' | ', $eksikFazlaComments);

            $transferLines[] = $eksikFazlaLine;
        }

        // Kusurlu → Fire & Zayi deposu
        if ($kusurluQty > 0) {
            if (empty($fireZayiWarehouse)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Kusurlu miktar var ancak Fire & Zayi deposu bulunamadı! Lütfen sistem yöneticisine başvurun.'
                ]);
                exit;
            }

            $fireZayiLine = [
                'ItemCode'         => $itemCode,
                'Quantity'         => $kusurluQty * $baseQty,
                'FromWarehouseCode'=> $targetWarehouse,
                'WarehouseCode'    => $fireZayiWarehouse,
                'U_ASB2B_Damaged'  => 'K',
            ];

            $fireComments = [];
            if (!empty($not)) {
                $fireComments[] = $not;
            }
            $fireComments[] = "Kusurlu: {$kusurluQty} adet";
            $fireComments[] = 'Fire & Zayi';
            $fireZayiLine['U_ASB2B_Comments'] = implode(' | ', $fireComments);

            $transferLines[] = $fireZayiLine;
        }
    }

    if (empty($transferLines)) {
        echo json_encode(['success' => false, 'message' => 'İşlenecek kalem bulunamadı! Lütfen en az bir kalem için teslim alın.']);
        exit;
    }

    $docDate           = $requestData['DocDate'] ?? date('Y-m-d');
    $headerCommentsText = !empty($headerComments) ? implode(" | ", $headerComments) : '';
    $docNum            = $requestData['DocNum'] ?? $docEntry;

    // Header Fire/Zayi flag
    $headerLost = null;
    foreach ($transferLines as $line) {
        $lost    = $line['U_ASB2B_LOST']    ?? null;
        $damaged = $line['U_ASB2B_Damaged'] ?? null;

        if ($lost == '1' || $lost == '2') {
            if ($headerLost === null || $headerLost == '2') {
                $headerLost = $lost;
            }
        } elseif ($damaged == 'K' || $damaged == 'E') {
            if ($headerLost === null) {
                $headerLost = '2';
            }
        }
    }

    // --- ÖNEMLİ: STATUS GÜNCELLEMESİ POST'TAN ÖNCE YAPILMALI ---
    // BaseEntry/BaseLine ile SAP belgeyi otomatik kapatıyor, bu yüzden
    // status güncellemesini belge kapanmadan önce yapmalıyız.
    
    // 1. SEVK MİKTARLARI (ST1): Sayfa başında hesaplanan
    $allSevkMiktarMap = $sevkMiktarMap;
    
    // 2. TESLİMAT MİKTARLARI (ST2): Şu an teslim alacağımız miktarlar
    $allTeslimatMiktarMap = [];
    if (!empty($transferLines)) {
        foreach ($transferLines as $tLine) {
            $tItem = $tLine['ItemCode'] ?? '';
            $tQty = (float)($tLine['Quantity'] ?? 0);
            
            // Fire/Zayi satırlarını atla
            $lost = trim($tLine['U_ASB2B_LOST'] ?? '');
            $damaged = trim($tLine['U_ASB2B_Damaged'] ?? '');
            if (($lost !== '' && $lost !== '-') || ($damaged !== '' && $damaged !== '-')) {
                continue;
            }
            
            if ($tItem !== '' && $tQty > 0) {
                if (!isset($allTeslimatMiktarMap[$tItem])) {
                    $allTeslimatMiktarMap[$tItem] = 0;
                }
                $allTeslimatMiktarMap[$tItem] += $tQty;
            }
        }
    }
    
    // 3. SATIR STATÜLERİNİ HESAPLA
    $lineStatuses = [];
    foreach ($allLines as $line) {
        $itemCode = $line['ItemCode'] ?? '';
        if ($itemCode === '') continue;

        $baseQty        = floatval($line['BaseQty'] ?? 1.0);
        $requestedRaw   = floatval($line['Quantity'] ?? 0);
        $requestedQty   = $baseQty > 0 ? ($requestedRaw / $baseQty) : $requestedRaw;

        $shippedRaw     = $allSevkMiktarMap[$itemCode]     ?? 0;
        $deliveredRaw   = $allTeslimatMiktarMap[$itemCode] ?? 0;
        
        $shippedQty     = $baseQty > 0 ? ($shippedRaw   / $baseQty) : $shippedRaw;
        $deliveredQty   = $baseQty > 0 ? ($deliveredRaw / $baseQty) : $deliveredRaw;

        $lineStatus = '1';
        if ($shippedQty == 0 && $deliveredQty == 0) {
            $lineStatus = '1';
        } elseif ($shippedQty > 0 && $deliveredQty == 0) {
            $lineStatus = '3';
        } elseif ($shippedQty > 0 && $deliveredQty > 0 && $deliveredQty < $shippedQty) {
            $lineStatus = '3';
        } elseif ($deliveredQty >= $shippedQty && $shippedQty > 0) {
            $lineStatus = '4';
        } elseif ($shippedQty > 0 && $deliveredQty >= $shippedQty) {
            $lineStatus = '4';
        }
        
        $lineStatuses[] = $lineStatus;
    }

    // 4. BAŞLIK STATÜSÜNÜ HESAPLA
    $headerStatus = '1';
    if (!empty($lineStatuses)) {
        $allStatus1 = true;
        $hasStatus3 = false;
        $allStatus4 = true;

        foreach ($lineStatuses as $st) {
            if ($st != '1') $allStatus1 = false;
            if ($st == '3') $hasStatus3 = true;
            if ($st != '4') $allStatus4 = false;
        }

        if ($allStatus1)       $headerStatus = '1';
        elseif ($allStatus4)   $headerStatus = '4';
        elseif ($hasStatus3)   $headerStatus = '3';
    }

    // 5. SATIR GÜNCELLEME HAZIRLA
    $linesToUpdate = [];
    foreach ($allLines as $line) {
        $lineNum = $line['LineNum'] ?? 0;
        $itemCode = $line['ItemCode'] ?? '';
        
        if ($headerStatus == '4') {
            $thisLineStatus = '4';
        } else {
            $baseQty = floatval($line['BaseQty'] ?? 1.0);
            $shippedRaw = $allSevkMiktarMap[$itemCode] ?? 0;
            $deliveredRaw = $allTeslimatMiktarMap[$itemCode] ?? 0;
            $shippedQty = $baseQty > 0 ? ($shippedRaw / $baseQty) : $shippedRaw;
            $deliveredQty = $baseQty > 0 ? ($deliveredRaw / $baseQty) : $deliveredRaw;
            
            if ($deliveredRaw >= $shippedRaw && $shippedRaw > 0) {
                $thisLineStatus = '4';
            } else {
                $thisLineStatus = '3';
            }
        }
        
        $linesToUpdate[] = [
            'LineNum' => $lineNum,
            'U_ASB2B_STATUS' => $thisLineStatus
        ];
    }

    // 6. STATUS GÜNCELLEMESİNİ YAP (POST'TAN ÖNCE - BELGE AÇIKKEN)
    $updatePayload = [
        'U_ASB2B_STATUS' => $headerStatus,
        'StockTransferLines' => $linesToUpdate
    ];
    
    $updateResult = $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);
    
    // Status güncelleme başarısız olursa logla
    if ($updateResult['status'] != 200 && $updateResult['status'] != 204) {
        error_log("[TRANSFER-TESLIMAL] Status güncellenemedi (POST öncesi). DocEntry: {$docEntry}, Status: " . ($updateResult['status'] ?? 'NO STATUS') . ", Error: " . json_encode($updateResult['response']['error'] ?? []));
    }
    
    // 7. STOCK TRANSFER OLUŞTUR (POST - SAP BELGEYİ KAPATACAK)
    $stockTransferPayload = [
        'FromWarehouse'   => $sevkiyatDepo,
        'ToWarehouse'     => $targetWarehouse,
        'DocDate'         => $docDate,
        'Comments'        => $headerCommentsText,
        'U_ASB2B_BRAN'    => $branch,
        'U_AS_OWNR'       => $uAsOwnr,
        'U_ASB2B_STATUS'  => '4',
        'U_ASB2B_TYPE'    => 'TRANSFER',
        'U_ASB2B_User'    => $_SESSION["UserName"] ?? '',
        'U_ASB2B_QutMaster'=> (int)$docEntry,
        'StockTransferLines' => $transferLines,
    ];
    if ($headerLost !== null) {
        $stockTransferPayload['U_ASB2B_LOST'] = $headerLost;
    }

    $result = $sap->post('StockTransfers', $stockTransferPayload);

    if ($result['status'] == 200 || $result['status'] == 201) {
        // StockTransfer başarıyla oluşturuldu
        // Status güncellemesi zaten POST'tan önce yapıldı
        // SAP belgeyi kapattı, ama status zaten "Tamamlandı" olarak işaretlendi
        
        // Tamamlandıysa Close (Eğer daha önce kapatılmadıysa)
        if ($headerStatus == '4') {
            // SAP zaten BaseEntry/BaseLine ile belgeyi kapatmış olabilir
            // Ama yine de Close çağrısını yapalım (idempotent işlem)
            $closeResult = $sap->post("InventoryTransferRequests({$docEntry})/Close", []);
            if ($closeResult['status'] != 200 && $closeResult['status'] != 204) {
                // Belge zaten kapalı olabilir, bu normal
                error_log("[TRANSFER-TESLIMAL] Close işlemi başarısız (muhtemelen zaten kapalı). DocEntry: {$docEntry}");
            }
        }

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

// Ekranda kullanılacak tarih/numara/depo isimleri
$docDate   = formatDate($requestData['DocDate'] ?? '');
$dueDate   = formatDate($requestData['DueDate'] ?? '');
$numAtCard = htmlspecialchars($requestData['U_ASB2B_NumAtCard'] ?? '-');
$status    = $requestData['U_ASB2B_STATUS'] ?? '';

$fromWhsName = '';
$toWhsName   = '';

// Depo isimleri
if (!empty($fromWarehouse)) {
    $whsData = $sap->get("Warehouses('{$fromWarehouse}')?\$select=WarehouseName");
    if (($whsData['status'] ?? 0) == 200) {
        $fromWhsName = $whsData['response']['WarehouseName'] ?? '';
    }
}
if (!empty($toWarehouse)) {
    $whsData2 = $sap->get("Warehouses('{$toWarehouse}')?\$select=WarehouseName");
    if (($whsData2['status'] ?? 0) == 200) {
        $toWhsName = $whsData2['response']['WarehouseName'] ?? '';
    }
}

/* ------------------------
 * 4) EKRAN ÖNCESİ: SEVK MİKTAR Haritası (ST1) + normalize alanlar
 * ---------------------- */

$sevkMiktarMap = buildSevkMiktarMapFromSt1($sap, (int)$docEntry, $toWarehouse ?: $sevkiyatDepo);

foreach ($lines as &$line) {
    $baseQty  = floatval($line['BaseQty']   ?? 1.0);
    $quantity = floatval($line['Quantity']  ?? 0);
    $itemCode = $line['ItemCode']          ?? '';

    $line['_BaseQty']      = $baseQty;
    $line['_RequestedQty'] = $baseQty > 0 ? ($quantity / $baseQty) : $quantity;

    $sevkQtyRaw = $sevkMiktarMap[$itemCode] ?? null;
    if ($sevkQtyRaw === null || $sevkQtyRaw == 0) {
        $sevkQtyRaw = $quantity; // Sevk yoksa talep = sevk
    }
    $line['_SevkQty'] = $baseQty > 0 ? ($sevkQtyRaw / $baseQty) : $sevkQtyRaw;
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