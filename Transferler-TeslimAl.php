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

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// Depo bulma
function findWarehouse($sap, $uAsOwnr, $branch, $filterExpr) {
    $filter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and {$filterExpr}";
    $query  = "Warehouses?\$filter=" . urlencode($filter);
    $data   = $sap->get($query);
    $rows   = $data['response']['value'] ?? [];
    return !empty($rows) ? $rows[0]['WarehouseCode'] : null;
}

// StockTransfer Headerlarını bul
function findStockTransfersForRequest($sap, int $docEntryInt): array {
    $combinedList = []; // Tüm sonuçları burada toplayacağız

    // 1. Sorgu: BaseEntry ile bağlı olanlar (Genelde Teslimat/ST2 Belgeleri)
    $baseQuery = "StockTransfers?\$filter=BaseEntry%20eq%20{$docEntryInt}%20and%20BaseType%20eq%201250000001&\$orderby=DocEntry%20asc";
    $baseData  = $sap->get($baseQuery);
    if (($baseData['status'] ?? 0) == 200) {
        $rows = $baseData['response']['value'] ?? [];
        foreach ($rows as $r) {
            // DocEntry'yi anahtar yaparak çift kayıtları engelle
            if (isset($r['DocEntry'])) {
                $combinedList[$r['DocEntry']] = $r;
            }
        }
    }

    // 2. Sorgu: QutMaster ile bağlı olanlar (Genelde Sevkiyat/ST1 Belgeleri - Bizim bağlantıyı kopardıklarımız)
    // ŞART YOK! Her durumda bunu da ara ve listeye ekle.
    $qutQuery = "StockTransfers?\$filter=U_ASB2B_QutMaster%20eq%20{$docEntryInt}&\$orderby=DocEntry%20asc";
    $qutData  = $sap->get($qutQuery);
    if (($qutData['status'] ?? 0) == 200) {
        $rows = $qutData['response']['value'] ?? [];
        foreach ($rows as $r) {
            if (isset($r['DocEntry'])) {
                $combinedList[$r['DocEntry']] = $r;
            }
        }
    }

    // 3. Sorgu: Parent/Comments ile bağlı olanlar (Eski kayıtlar için yedek)
    // Bunu da her zaman ara
    $fallbackQuery = "StockTransfers?\$filter=Comments%20eq%20'{$docEntryInt}'%20or%20U_ASB2B_Parent%20eq%20'{$docEntryInt}'&\$orderby=DocEntry%20asc";
    $fallbackData  = $sap->get($fallbackQuery);
    if (($fallbackData['status'] ?? 0) == 200) {
        $rows = $fallbackData['response']['value'] ?? [];
        foreach ($rows as $r) {
            if (isset($r['DocEntry'])) {
                $combinedList[$r['DocEntry']] = $r;
            }
        }
    }

    // Hash map'ten düz diziye çevir ve döndür
    return array_values($combinedList);
}

// ST satırlarını çek
function getStockTransferLines($sap, int $stDocEntry): array {
    $stLinesQuery = "StockTransfers({$stDocEntry})/StockTransferLines";
    $stLinesData  = $sap->get($stLinesQuery);
    if (($stLinesData['status'] ?? 0) != 200) return [];
    $resp = $stLinesData['response'] ?? null;
    if (isset($resp['value']) && is_array($resp['value'])) return $resp['value'];
    if (is_array($resp) && !isset($resp['value'])) return $resp;
    return [];
}

// ST1 belgelerinden sevk miktarını topla
function buildSevkMiktarMapFromSt1($sap, int $docEntryInt, string $sevkiyatDepo): array {
    $sevkMiktarMap = [];
    $stList = findStockTransfersForRequest($sap, $docEntryInt);

    foreach ($stList as $stInfo) {
        $stToWarehouse = $stInfo['ToWarehouse'] ?? '';
        // Depo kodlarını normalize et (boşluk vs temizle)
        if (trim($stToWarehouse) !== trim($sevkiyatDepo)) continue; 

        $stDocEntry = $stInfo['DocEntry'] ?? null;
        if (!$stDocEntry) continue;

        $stLines = getStockTransferLines($sap, (int)$stDocEntry);
        foreach ($stLines as $stLine) {
            $itemCode = $stLine['ItemCode'] ?? '';
            $qty      = (float)($stLine['Quantity'] ?? 0);
            if ($itemCode === '' || $qty <= 0) continue;

            $lost    = trim($stLine['U_ASB2B_LOST']    ?? '');
            $damaged = trim($stLine['U_ASB2B_Damaged'] ?? '');
            if (($lost !== '' && $lost !== '-') || ($damaged !== '' && $damaged !== '-')) continue;

            if (!isset($sevkMiktarMap[$itemCode])) $sevkMiktarMap[$itemCode] = 0;
            $sevkMiktarMap[$itemCode] += $qty;
        }
    }
    return $sevkMiktarMap;
}

/* ------------------------
 * 1) HEADER + SATIRLAR
 * ---------------------- */
$lines       = [];
$requestData = null;

$viewFilter = "DocEntry eq {$docEntry}";
$viewQuery  = "view.svc/ASB2B_TransferRequestList_B1SLQuery?\$filter=" . urlencode($viewFilter);
$viewData   = $sap->get($viewQuery);
$viewRows   = $viewData['response']['value'] ?? [];

if (!empty($viewRows)) {
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
            'BaseQty'         => 1.0,
            'UoMCode'         => $row['UoMCode'] ?? 'AD',
            'LineNum'         => $row['LineNum'] ?? null,
            // --- KRİTİK: Satır Durumunu Al ---
            'U_ASB2B_STATUS'  => $row['U_ASB2B_STATUS'] ?? '0' 
        ];
    }
} else {
    // Fallback: View yoksa standart tablodan çek (Burada U_ASB2B_STATUS satırda olmayabilir, header'dan gelir)
    $headerQuery = "InventoryTransferRequests({$docEntry})?\$select=DocEntry,DocDate,DocDueDate,U_ASB2B_NumAtCard,U_ASB2B_STATUS,FromWarehouse,ToWarehouse";
    $headerData = $sap->get($headerQuery);
    $requestData = $headerData['response'] ?? null;
    if (!$requestData) die("Transfer talebi bulunamadı!");

    $linesQuery = "InventoryTransferRequests({$docEntry})/InventoryTransferRequestLines";
    $linesData = $sap->get($linesQuery);
    $linesResponse = $linesData['response'] ?? null;
    $rawLines = (isset($linesResponse['value']) && is_array($linesResponse['value'])) ? $linesResponse['value'] : ($linesResponse ?? []);
    
    foreach ($rawLines as $rl) {
        $lines[] = array_merge($rl, ['U_ASB2B_STATUS' => $requestData['U_ASB2B_STATUS'] ?? '0']);
    }
}

// STATUS kontrolü
$initialDbStatus = $requestData['U_ASB2B_STATUS'] ?? '0';
if ($initialDbStatus == '4') {
    $_SESSION['error_message'] = "Bu transfer zaten tamamlanmış!";
    header("Location: Transferler.php?view=incoming");
    exit;
}

$fromWarehouse = $requestData['FromWarehouse'] ?? '';
$toWarehouse   = $requestData['ToWarehouse']   ?? '';
$allLines = $lines;

// Filtreleme
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
 * 2) DEPOLAR
 * ---------------------- */
$sevkiyatDepo = $toWarehouse;
$targetWarehouse = str_replace('-1', '-0', $sevkiyatDepo);

if ($targetWarehouse === $sevkiyatDepo || empty($targetWarehouse)) {
    $targetWarehouse = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_MAIN eq '1'");
    if (empty($targetWarehouse)) die("Hedef depo (Ana Depo) bulunamadı! Sevkiyat Depo: {$sevkiyatDepo}");
    $sevkiyatDepoCheck = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_MAIN eq '2'");
    if (!empty($sevkiyatDepoCheck)) $sevkiyatDepo = $sevkiyatDepoCheck;
}

$fireZayiWarehouse = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_MAIN eq '3'");
if (empty($fireZayiWarehouse)) {
    $fireZayiWarehouse = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_FIREZAYI eq 'Y'");
}

/* ------------------------
 * 3) POST: TESLİM AL İŞLEMİ
 * ---------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'teslim_al') {
    header('Content-Type: application/json');

    // Taze veri
    $freshRequestData  = $sap->get("InventoryTransferRequests({$docEntry})");
    $freshInfo  = $freshRequestData['response'] ?? null;
    $initialDbStatus = $freshInfo['U_ASB2B_STATUS'] ?? '0';
    if ($initialDbStatus == '4') {
        echo json_encode(['success' => false, 'message' => 'Bu transfer zaten tamamlanmış!']);
        exit;
    }

    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'Transfer satırları bulunamadı!']);
        exit;
    }

    // --- SEVK HESAPLAMA ---
    $docEntryInt  = (int)$docEntry;
    $allStList    = findStockTransfersForRequest($sap, $docEntryInt);
    
    $sevkMiktarMap = [];
    $prevTeslimatMap = [];

    foreach ($allStList as $stInfo) {
        $stFrom = $stInfo['FromWarehouse'] ?? '';
        $stTo   = $stInfo['ToWarehouse']   ?? '';
        $stDoc  = $stInfo['DocEntry']      ?? null;
        if (!$stDoc) continue;

        $isST1 = ($stTo === $sevkiyatDepo); 
        $isST2 = ($stFrom === $sevkiyatDepo && $stTo === $targetWarehouse); 

        if (!$isST1 && !$isST2) continue;

        $stLines = getStockTransferLines($sap, (int)$stDoc);
        foreach ($stLines as $stLine) {
            $itemCode = $stLine['ItemCode'] ?? '';
            $qty      = (float)($stLine['Quantity'] ?? 0);
            if ($itemCode === '' || $qty <= 0) continue;

            $lost    = trim($stLine['U_ASB2B_LOST']    ?? '');
            $damaged = trim($stLine['U_ASB2B_Damaged'] ?? '');
            if (($lost !== '' && $lost !== '-') || ($damaged !== '' && $damaged !== '-')) continue;

            if ($isST1) {
                if (!isset($sevkMiktarMap[$itemCode])) $sevkMiktarMap[$itemCode] = 0;
                $sevkMiktarMap[$itemCode] += $qty;
            } elseif ($isST2) {
                if (!isset($prevTeslimatMap[$itemCode])) $prevTeslimatMap[$itemCode] = 0;
                $prevTeslimatMap[$itemCode] += $qty;
            }
        }
    }

    // --- AKILLI FALLBACK: Stok Nakli Bulunamadıysa Satır Durumuna Bak ---
    foreach ($lines as $line) {
        $itemCode = $line['ItemCode'] ?? '';
        if ($itemCode === '') continue;
        
        $currentSevk = $sevkMiktarMap[$itemCode] ?? 0;
        
        // Eğer sevk 0 ise ama Satır Durumu '3' (Sevk Edildi) ise -> Sevk var kabul et
        // Bu durum ST belgesi UDF ile bulunamadığında hayat kurtarır
        if ($currentSevk == 0) {
            $lineStatus = $line['U_ASB2B_STATUS'] ?? '0';
            // DÜZELTME: Durum 3 (Sevk) VEYA 4 (Tamamlandı) ise sevk var say
            if ($lineStatus == '3' || $lineStatus == '4') {
                $sevkMiktarMap[$itemCode] = floatval($line['Quantity'] ?? 0);
            }
        }
    }

    // --- PAYLOAD HAZIRLIĞI ---
    $transferLines = [];
    $headerComments = [];
    $currentDeliveryMap = [];

    foreach ($lines as $index => $line) {
        $itemCode = $line['ItemCode'] ?? '';
        if ($itemCode === '') continue;

        $baseQty  = floatval($line['BaseQty'] ?? 1.0);
        $sevkRaw  = $sevkMiktarMap[$itemCode] ?? 0;
        $sevkMiktari = $baseQty > 0 ? ($sevkRaw / $baseQty) : $sevkRaw;
        
        $talepRaw = floatval($line['Quantity'] ?? 0);
        $talepMiktar = $baseQty > 0 ? ($talepRaw / $baseQty) : $talepRaw;
        
        $eksikFazlaQty = floatval($_POST['eksik_fazla'][$index] ?? 0);
        $kusurluQty    = max(0.0, floatval($_POST['kusurlu'][$index] ?? 0));
        $not           = trim($_POST['not'][$index] ?? '');
        $itemName      = $line['ItemDescription'] ?? $itemCode;

        if ($kusurluQty > 0) {
            $commentParts   = ["Kusurlu: {$kusurluQty}"];
            if (!empty($not)) $commentParts[] = "Not: {$not}";
            $headerComments[] = "{$itemCode} ({$itemName}): " . implode(", ", $commentParts);
        }

        // 1. NORMAL TRANSFER
        $normalTransferMiktar = max(0.0, $sevkMiktari);
        
        if ($normalTransferMiktar > 0) {
            $qtyToSend = $normalTransferMiktar * $baseQty;
            $transferLines[] = [
                'ItemCode'         => $itemCode,
                'Quantity'         => $qtyToSend,
                'FromWarehouseCode'=> $sevkiyatDepo,
                'WarehouseCode'    => $targetWarehouse,
                
                // 🔥 BAĞLANTI HARİTASI 🔥
                'BaseType'         => 1250000001,
                'BaseEntry'        => (int)$docEntry,
                'BaseLine'         => (int)($line['LineNum'] ?? 0)
            ];
            
            if (!isset($currentDeliveryMap[$itemCode])) $currentDeliveryMap[$itemCode] = 0;
            $currentDeliveryMap[$itemCode] += $qtyToSend;
        }

        /* 2. Eksik/Fazla
        if ($eksikFazlaQty != 0) {
            $eksikFazlaMiktar = abs($eksikFazlaQty);
            $eksikFazlaLine   = [
                'ItemCode'         => $itemCode,
                'Quantity'         => $eksikFazlaMiktar * $baseQty,
                'FromWarehouseCode'=> $targetWarehouse,
                'WarehouseCode'    => $sevkiyatDepo,
            ];
            if ($eksikFazlaQty < 0) {
                $eksikFazlaLine['U_ASB2B_LOST']    = '2';
                $eksikFazlaLine['U_ASB2B_Damaged'] = 'E';
            } else {
                $eksikFazlaLine['U_ASB2B_LOST'] = '1';
            }
            $eksikFazlaLine['U_ASB2B_Comments'] = ($eksikFazlaQty < 0 ? "Eksik" : "Fazla") . ": {$eksikFazlaMiktar} | {$not}";
            $transferLines[] = $eksikFazlaLine;
        }*/

        // 3. Kusurlu
        if ($kusurluQty > 0) {
            if (empty($fireZayiWarehouse)) {
                echo json_encode(['success' => false, 'message' => 'Fire & Zayi deposu bulunamadı!']);
                exit;
            }
            $fireZayiLine = [
                'ItemCode'         => $itemCode,
                'Quantity'         => $kusurluQty * $baseQty,
                'FromWarehouseCode'=> $targetWarehouse,
                'WarehouseCode'    => $fireZayiWarehouse,
                'U_ASB2B_Damaged'  => 'K',
                'U_ASB2B_Comments' => "Kusurlu: {$kusurluQty} | {$not}"
            ];
            $transferLines[] = $fireZayiLine;
        }
    }

    if (empty($transferLines)) {
        echo json_encode(['success' => false, 'message' => 'İşlenecek miktar yok. (Sevk edilmiş ürün bulunamadı)']);
        exit;
    }

    // --- STATÜ HESAPLAMA ---
    
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
    
    $linesToUpdate = [];
    $lineStatuses = [];
    
    foreach ($allLines as $line) {
        $itemCode = $line['ItemCode'] ?? '';
        $lineNum  = $line['LineNum'];
        if ($itemCode === '') continue;

        // ÖNEMLİ: Eğer bu satır zaten tamamlanmışsa (status 4), onu olduğu gibi koru
        // Çünkü daha önce tamamlanmış satırlar için ST belgeleri bulunamayabilir
        $currentLineStatus = $line['U_ASB2B_STATUS'] ?? '0';
        if ($currentLineStatus == '4') {
            // Bu satır zaten tamamlanmış, status'ünü koru
            $lnStat = '4';
            $lineStatuses[] = $lnStat;
            $linesToUpdate[] = [
                'LineNum' => $lineNum,
                'U_ASB2B_STATUS' => $lnStat
            ];
            continue; // Bu satır için hesaplama yapma, bir sonrakine geç
        }

        $baseQty = floatval($line['BaseQty'] ?? 1.0);
        
        $sevkRaw = $sevkMiktarMap[$itemCode] ?? 0;
        
        $prevRaw = $prevTeslimatMap[$itemCode] ?? 0;
        
        // DÜZELTME: Eğer ST belgesi bulunamadıysa (prevRaw=0) ama satır zaten '4' ise,
        // demek ki bu ürün daha önce tam teslim alınmış. Miktarı manuel doldur.
        if ($prevRaw == 0 && $currentLineStatus == '4') {
             $prevRaw = floatval($line['Quantity'] ?? 0);
        }

        $currRaw = $currentDeliveryMap[$itemCode] ?? 0;
        $totalDeliveredRaw = $prevRaw + $currRaw;

        $sevkQty = $baseQty > 0 ? ($sevkRaw / $baseQty) : $sevkRaw;
        $delivQty = $baseQty > 0 ? ($totalDeliveredRaw / $baseQty) : $totalDeliveredRaw;

        if ($delivQty >= $sevkQty && $sevkQty > 0) {
            $lnStat = '4'; // Tamamlandı
        } elseif ($sevkQty > 0) {
            $lnStat = '3'; // Hala eksik var
        } else {
            $lnStat = '1';
        }
        
        $lineStatuses[] = $lnStat;
        $linesToUpdate[] = [
            'LineNum' => $lineNum,
            'U_ASB2B_STATUS' => $lnStat
        ];
    }

    $headerStatus = '1';
    if (in_array('4', $lineStatuses) && !in_array('3', $lineStatuses) && !in_array('1', $lineStatuses)) {
        $headerStatus = '4';
    } elseif (in_array('3', $lineStatuses) || in_array('4', $lineStatuses)) {
        $headerStatus = '3';
    }

    // 1. DURUMU GÜNCELLE
    $updatePayload = [
        'U_ASB2B_STATUS' => $headerStatus,
        'StockTransferLines' => $linesToUpdate
    ];
    $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);

    // 2. TRANSFERİ YAP
    $stockTransferPayload = [
        'FromWarehouse'   => $sevkiyatDepo,
        'ToWarehouse'     => $targetWarehouse,
        'DocDate'         => $freshInfo['DocDate'] ?? date('Y-m-d'),
        'Comments'        => !empty($headerComments) ? implode(" | ", $headerComments) : '',
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
        if ($headerStatus == '4') {
            $sap->post("InventoryTransferRequests({$docEntry})/Close", []);
        }
        $_SESSION['success_message'] = "Transfer başarıyla teslim alındı!";
        header('Location: Transferler.php?view=incoming');
        exit;
    } else {
        // Rollback Header Status
        $sap->patch("InventoryTransferRequests({$docEntry})", ['U_ASB2B_STATUS' => $initialDbStatus]);

        $errorMsg = 'Teslim alma başarısız! HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        $_SESSION['error_message'] = $errorMsg;
        header('Location: Transferler.php?view=incoming');
        exit;
    }
}

// 4) EKRAN ÖNCESİ VERİ HAZIRLIĞI
$docEntryInt  = (int)$docEntry;
$sevkMiktarMap = buildSevkMiktarMapFromSt1($sap, $docEntryInt, $toWarehouse ?: $sevkiyatDepo);

foreach ($lines as &$line) {
    $baseQty  = floatval($line['BaseQty']   ?? 1.0);
    $quantity = floatval($line['Quantity']  ?? 0);
    $itemCode = $line['ItemCode']          ?? '';

    $line['_BaseQty']      = $baseQty;
    $line['_RequestedQty'] = $baseQty > 0 ? ($quantity / $baseQty) : $quantity;

    $sevkQtyRaw = $sevkMiktarMap[$itemCode] ?? null;
    
    // --- AKILLI FALLBACK (EKRAN İÇİN DE) ---
    if ($sevkQtyRaw === null || $sevkQtyRaw == 0) {
        // ST bulunamadı, satır durumuna bak
        $lineStatus = $line['U_ASB2B_STATUS'] ?? '0';
        if ($lineStatus == '3') {
            $sevkQtyRaw = $quantity; // Sevk edildi görünüyor, miktar var varsay
        } else {
            $sevkQtyRaw = 0;
        }
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f5f7fa; color: #2c3e50; line-height: 1.6; }
        .main-content { width: 100%; background: whitesmoke; padding: 0; min-height: 100vh; }
        .page-header { background: white; padding: 20px 2rem; border-radius: 0 0 0 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; margin: 0; position: sticky; top: 0; z-index: 100; height: 80px; box-sizing: border-box; }
        .page-header h2 { color: #1e40af; font-size: 1.75rem; font-weight: 600; }
        .btn { padding: 0.75rem 1.5rem; border: none; border-radius: 8px; font-size: 0.95rem; font-weight: 500; cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-block; }
        .btn-primary { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; }
        .btn-primary:hover { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4); }
        .btn-secondary { background: white; color: #3b82f6; border: 2px solid #3b82f6; }
        .btn-secondary:hover { background: #eff6ff; transform: translateY(-2px); }
        .content-wrapper { padding: 24px 32px; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 2rem; margin: 24px 32px 2rem 32px; }
        .data-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .data-table thead { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; }
        .data-table th { padding: 1rem; text-align: left; font-weight: 600; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .data-table th:nth-child(n+3) { text-align: center; }
        .data-table tbody tr { border-bottom: 1px solid #e5e7eb; transition: background-color 0.2s; }
        .data-table tbody tr:hover { background-color: #f8fafc; }
        .data-table td { padding: 1rem; font-size: 0.95rem; }
        .data-table td:nth-child(n+3) { text-align: center; }
        .quantity-controls { display: flex; gap: 0.5rem; align-items: center; justify-content: center; }
        .qty-btn { padding: 0.5rem 1rem; border: 2px solid #3b82f6; background: white; color: #3b82f6; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 1rem; min-width: 40px; transition: all 0.2s; }
        .qty-btn:hover { background: #3b82f6; color: white; transform: scale(1.05); }
        .qty-input { width: 100px; text-align: center; padding: 0.5rem; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 0.95rem; transition: border-color 0.2s; }
        .qty-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .qty-input[readonly] { background-color: #f3f4f6; color: #6b7280; }
        input[name^="eksik_fazla"] { font-weight: 500; }
        .eksik-fazla-negatif { color: #dc2626 !important; }
        .eksik-fazla-pozitif { color: #16a34a !important; }
        .eksik-fazla-sifir { color: #6b7280 !important; }
        .form-actions { margin-top: 2rem; text-align: right; display: flex; gap: 1rem; justify-content: flex-end; }
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
        <div class="card">
            <h3 style="margin-bottom: 1rem; color: #1e40af;">Transfer Bilgileri</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                <div><div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; margin-bottom: 0.25rem;">Transfer No</div><div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($docEntry) ?></div></div>
                <div><div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; margin-bottom: 0.25rem;">Gönderen Şube</div><div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($fromWarehouse) ?><?= !empty($fromWhsName) ? ' / ' . htmlspecialchars($fromWhsName) : '' ?></div></div>
                <div><div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; margin-bottom: 0.25rem;">Transfer Tarihi</div><div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= $docDate ?></div></div>
                <div><div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; margin-bottom: 0.25rem;">Vade Tarihi</div><div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= $dueDate ?></div></div>
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
                            <th>Talep</th>
                            <th>Sevk</th>
                            <th>Eksik/Fazla</th>
                            <th>Kusurlu</th>
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
                        $eksikFazlaOtomatik = $sevkQty - $requestedQty;
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($itemCode) ?></td>
                            <td><?= htmlspecialchars($itemName) ?></td>
                            <td><div style="display:flex;justify-content:center;gap:4px"><input type="number" id="talep_<?= $index ?>" value="<?= htmlspecialchars($requestedQty) ?>" readonly step="0.01" class="qty-input"><span><?= $uomCode ?></span></div></td>
                            <td><div style="display:flex;justify-content:center;gap:4px"><input type="number" id="sevk_<?= $index ?>" value="<?= htmlspecialchars($sevkQty) ?>" readonly step="0.01" class="qty-input"><span><?= $uomCode ?></span></div></td>
                            <td><div class="quantity-controls"><button type="button" class="qty-btn" onclick="changeEksikFazla(<?= $index ?>, -1)">-</button><input type="number" name="eksik_fazla[<?= $index ?>]" id="eksik_<?= $index ?>" value="<?= htmlspecialchars($eksikFazlaOtomatik) ?>" step="0.01" class="qty-input" onchange="calculatePhysical(<?= $index ?>)" oninput="updateEksikFazlaColor(this); calculatePhysical(<?= $index ?>)"><button type="button" class="qty-btn" onclick="changeEksikFazla(<?= $index ?>, 1)">+</button></div></td>
                            <td><div class="quantity-controls"><button type="button" class="qty-btn" onclick="changeKusurlu(<?= $index ?>, -1)">-</button><input type="number" name="kusurlu[<?= $index ?>]" id="kusurlu_<?= $index ?>" value="0" min="0" step="0.01" class="qty-input" onchange="calculatePhysical(<?= $index ?>)" oninput="calculatePhysical(<?= $index ?>)"><button type="button" class="qty-btn" onclick="changeKusurlu(<?= $index ?>, 1)">+</button></div></td>
                            <td><div style="display:flex;justify-content:center;gap:4px"><input type="text" id="fiziksel_<?= $index ?>" value="0" readonly class="qty-input"><span><?= $uomCode ?></span></div></td>
                            <td><input type="text" name="not[<?= $index ?>]" placeholder="Not" style="width:100%;padding:0.5rem;border:1px solid #d1d5db;border-radius:6px"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=incoming'">İptal</button>
                    <button type="submit" class="btn btn-primary">✓ Teslim Al / Onayla</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</main>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const eksikFazlaInputs = document.querySelectorAll('input[name^="eksik_fazla"]');
    eksikFazlaInputs.forEach(input => {
        const index = input.id.replace('eksik_', '');
        updateEksikFazlaColor(input);
        calculatePhysical(parseInt(index));
    });
    const sevkInputs = document.querySelectorAll('input[id^="sevk_"]');
    sevkInputs.forEach(sevkInput => {
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
    if (value < 0) input.classList.add('eksik-fazla-negatif');
    else if (value > 0) input.classList.add('eksik-fazla-pozitif');
    else input.classList.add('eksik-fazla-sifir');
}
function changeKusurlu(index, delta) {
    const input = document.getElementById('kusurlu_' + index);
    if (!input) return;
    const talepInput = document.getElementById('talep_' + index);
    const eksikFazlaInput = document.getElementById('eksik_' + index);
    if (!talepInput || !eksikFazlaInput) return;
    const talep = parseFloat(talepInput.value) || 0;
    const eksikFazla = parseFloat(eksikFazlaInput.value) || 0;
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
    let fiziksel = talep + eksikFazla;
    if (fiziksel < 0) fiziksel = 0;
    if (kusurlu > fiziksel) {
        kusurlu = fiziksel;
        kusurluInput.value = kusurlu;
    }
    let formattedValue;
    if (fiziksel == Math.floor(fiziksel)) formattedValue = Math.floor(fiziksel).toString();
    else formattedValue = fiziksel.toFixed(2).replace('.', ',').replace(/0+$/, '').replace(/,$/, '');
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
        const fiziksel = talep + eksikFazla;
        if (fiziksel > 0) hasQuantity = true;
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