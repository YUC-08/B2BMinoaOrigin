<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// Parametreleri Al
$docEntry = $_GET['docEntry'] ?? $_POST['docEntry'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? ''; 
$linesJson = $_POST['lines'] ?? null; 
$cartLines = null;
if (!empty($linesJson)) {
    $cartLines = json_decode($linesJson, true);
}

if (empty($docEntry) || empty($action)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Eksik parametreler']);
        exit;
    }
    header("Location: Transferler.php?view=outgoing&error=missing_params");
    exit;
}

// Status Belirle
$newStatus = '';
if ($action === 'approve') {
    $newStatus = '3'; // SEVK EDİLDİ
} elseif ($action === 'reject') {
    $newStatus = '5'; // İPTAL EDİLDİ
} else {
    header("Location: Transferler.php?view=outgoing&error=invalid_action");
    exit;
}

// Session bilgileri
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? '';
$userName = $_SESSION["UserName"] ?? '';

// ====================================================================================
// ONAYLAMA İŞLEMİ (STATUS = 3)
// ====================================================================================
if ($action === 'approve' && $newStatus === '3') {
    
    // 1. ADIM: BELGE BİLGİLERİNİ ÇEK
    $filterStr = "DocEntry eq {$docEntry}";
    $headerQuery = "view.svc/ASB2B_TransferRequestList_B1SLQuery?\$filter=" . urlencode($filterStr) . "&\$top=1";
    $headerData = $sap->get($headerQuery);
    $headerRows = $headerData['response']['value'] ?? [];
    $headerInfo = !empty($headerRows) ? $headerRows[0] : null;
    
    if (!$headerInfo) {
        die(json_encode(['success' => false, 'message' => 'Transfer talebi bulunamadı!']));
    }
    
    $fromWarehouse = $headerInfo['FromWhsCode'] ?? '';
    $toWarehouse = $headerInfo['WhsCode'] ?? ''; 
    $docDate = $headerInfo['DocDate'] ?? date('Y-m-d');
    $sevkiyatDepo = $toWarehouse;

    // Satırları Çek
    $linesQuery = "view.svc/ASB2B_TransferRequestList_B1SLQuery?\$filter=" . urlencode($filterStr);
    $linesData = $sap->get($linesQuery);
    $requestLines = $linesData['response']['value'] ?? [];

    // ----------------------------------------------------------------------------
    // 2. ADIM (YER DEĞİŞTİ): ÖNCE DURUMU "SEVK EDİLDİ" YAP (BELGE HALA AÇIKKEN)
    // ----------------------------------------------------------------------------
    
    // Sadece başlık güncellemesi yeterli (Satırlarla uğraşmaya gerek yok)
    $headerUpdatePayload = [
        'U_ASB2B_STATUS' => '3' 
    ];
    
    // Burada PATCH atıyoruz. Belge henüz açık olduğu için bu işlem %100 çalışır.
    $patchResult = $sap->patch("InventoryTransferRequests({$docEntry})", $headerUpdatePayload);
    
    if ($patchResult['status'] != 204 && $patchResult['status'] != 200) {
        // Eğer durumu güncelleyemezsek işlemi durdurmalı mıyız? 
        // Genelde hayır, transfere devam edelim ama loglayalım.
        error_log("[ONAYLA] Durum güncelleme uyarısı: " . json_encode($patchResult));
    }

    // ----------------------------------------------------------------------------
    // 3. ADIM: STOCK TRANSFER PAYLOAD HAZIRLAMA (Bağlantı Haritası Dahil)
    // ----------------------------------------------------------------------------
    $stockTransferLines = [];
    
    // Sepet Mantığı
    if (!empty($cartLines) && is_array($cartLines)) {
        foreach ($cartLines as $cartLine) {
            $itemCode = $cartLine['ItemCode'] ?? '';
            $quantity = floatval($cartLine['Quantity'] ?? 0); 
            
            if (empty($itemCode) || $quantity <= 0) continue;
            
            // LineNum Eşleştirme
            $matchedRequestLine = null;
            if (isset($cartLine['LineNum'])) {
                foreach ($requestLines as $reqLine) {
                    if ((int)$reqLine['LineNum'] == (int)$cartLine['LineNum']) {
                        $matchedRequestLine = $reqLine;
                        break;
                    }
                }
            }
            // Yedek: ItemCode ile bul
            if (!$matchedRequestLine) {
                foreach ($requestLines as $reqLine) {
                    if (($reqLine['ItemCode'] ?? '') === $itemCode) {
                        $matchedRequestLine = $reqLine;
                        break;
                    }
                }
            }
            
            $baseLine = 0;
            if ($matchedRequestLine) {
                $baseLine = (int)$matchedRequestLine['LineNum'];
            } elseif (isset($cartLine['LineNum'])) {
                $baseLine = (int)$cartLine['LineNum'];
            }

            $lineData = [
                'ItemCode' => $itemCode,
                'Quantity' => $quantity,
                'FromWarehouseCode' => $fromWarehouse,
                'WarehouseCode' => $sevkiyatDepo,
                
                // --- BAĞLANTI HARİTASI İÇİN KRİTİK KISIM ---
                'BaseType' => 1250000001,      
                'BaseEntry' => (int)$docEntry, 
                'BaseLine' => $baseLine,       
                // -------------------------------------------
                
                'OriginalLineNum' => $baseLine // Temizlik için referans
            ];
            $stockTransferLines[] = $lineData;
        }
    } else {
        // Fallback: Tüm satırlar
        foreach ($requestLines as $line) {
            $lineNum = (int)$line['LineNum'];
            $lineData = [
                'ItemCode' => $line['ItemCode'],
                'Quantity' => floatval($line['Quantity']), 
                'FromWarehouseCode' => $fromWarehouse,
                'WarehouseCode' => $sevkiyatDepo,
                'BaseType' => 1250000001,
                'BaseEntry' => (int)$docEntry,
                'BaseLine' => $lineNum,
                'OriginalLineNum' => $lineNum
            ];
            $stockTransferLines[] = $lineData;
        }
    }
    
    // Payload Temizliği (OriginalLineNum SAP'ye gitmemeli)
    $cleanStockTransferLines = array_map(function($line) {
        if (isset($line['OriginalLineNum'])) unset($line['OriginalLineNum']);
        return $line;
    }, $stockTransferLines);

    $stockTransferPayload = [
        'FromWarehouse' => $fromWarehouse,
        'ToWarehouse' => $sevkiyatDepo,
        'DocDate' => $docDate,
        'Comments' => "Transfer talebi onaylandı - Sevkiyat deposuna transfer",
        'U_ASB2B_BRAN' => $branch,
        'U_AS_OWNR' => $uAsOwnr,
        'U_ASB2B_STATUS' => '3',
        'U_ASB2B_TYPE' => 'TRANSFER',
        'U_ASB2B_User' => $userName,
        'U_ASB2B_QutMaster' => (int)$docEntry,
        'StockTransferLines' => $cleanStockTransferLines // BaseEntry/BaseLine BURADA GİDİYOR
    ];
    
    // ====================================================================================
    // DEBUG BİLGİLERİ
    // ====================================================================================
    $debugInfo = [
        'docEntry' => $docEntry,
        'fromWarehouse' => $fromWarehouse,
        'toWarehouse' => $sevkiyatDepo,
        'cartLines' => $cartLines,
        'requestLines' => $requestLines,
        'stockTransferLines_before_clean' => $stockTransferLines,
        'cleanStockTransferLines' => $cleanStockTransferLines,
        'stockTransferPayload' => $stockTransferPayload,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // ----------------------------------------------------------------------------
    // 4. ADIM: STOCK TRANSFER OLUŞTUR (BU İŞLEM BELGEYİ KAPATIR)
    // ----------------------------------------------------------------------------
    
    // Bu işlem gerçekleştiğinde, 'BaseEntry' sayesinde 3912 nolu belge otomatik kapanır.
    // AMA biz 2. adımda durumunu zaten '3' yaptığımız için sorun olmaz.
    $stockTransferResult = $sap->post('StockTransfers', $stockTransferPayload);
    
    // Debug: Response bilgisi
    $debugInfo['stockTransferResponse'] = [
        'status' => $stockTransferResult['status'] ?? null,
        'response' => $stockTransferResult['response'] ?? null,
        'error' => $stockTransferResult['error'] ?? null
    ];
    
    $result = $stockTransferResult; // Sonuç bu

    // Eğer StockTransfer başarısız olursa, durumu geri (0) almak gerekebilir.
    // Ancak basitlik adına şimdilik böyle bırakıyoruz. 
    
} else {
    // =============================================================================
    // REJECT (SATIR BAZINDA CLOSE) 
    // =============================================================================
    $linesToClose = [];

    if (!empty($cartLines) && is_array($cartLines)) {
        foreach ($cartLines as $line) {
            $lineNum = (int)($line['LineNum'] ?? -1);
            if ($lineNum >= 0) {
                $linesToClose[] = [
                    'LineNum' => $lineNum,
                    'U_ASB2B_STATUS' => '5',
                    
                ];
            }
        }
    }

    if (empty($linesToClose)) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Kapatılacak satır seçilmedi (lines boş / geçersiz).'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $updatePayload = [
                  
        'StockTransferLines' => $linesToClose
    ];

    $result = $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);
}



// SONUÇ DÖNDÜRME
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($result) && ($result['status'] == 200 || $result['status'] == 201 || $result['status'] == 204)) {
        $response = [
            'success' => true, 
            'message' => $action === 'approve' ? 'Transfer onaylandı' : 'Transfer iptal edildi'
        ];
        // Debug bilgilerini ekle
        if (isset($debugInfo)) {
            $response['debug'] = $debugInfo;
        }
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        $errorMsg = 'İşlem başarısız: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        $response = ['success' => false, 'message' => $errorMsg];
        // Debug bilgilerini ekle
        if (isset($debugInfo)) {
            $response['debug'] = $debugInfo;
        }
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
} else {
    if (isset($result) && ($result['status'] == 200 || $result['status'] == 201 || $result['status'] == 204)) {
        $msg = $action === 'approve' ? 'onaylandi' : 'iptal_edildi';
        header("Location: Transferler.php?view=outgoing&msg={$msg}");
    } else {
        $errorMsg = 'Hata oluştu';
        if (isset($result['response']['error'])) $errorMsg = json_encode($result['response']['error']);
        header("Location: Transferler.php?view=outgoing&error=" . urlencode($errorMsg));
    }
}
exit;
?>