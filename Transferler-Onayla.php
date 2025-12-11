<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// GET veya POST'tan parametreleri al
$docEntry = $_GET['docEntry'] ?? $_POST['docEntry'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? ''; // 'approve' veya 'reject'
$linesJson = $_POST['lines'] ?? null; // POST ile gönderilen lines (sepetteki "Gönderilecek" miktarları)
$cartLines = null;
if (!empty($linesJson)) {
    $cartLines = json_decode($linesJson, true);
}

if (empty($docEntry) || empty($action)) {
    // JSON response döndür (AJAX istekleri için)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Eksik parametreler']);
        exit;
    }
    header("Location: Transferler.php?view=outgoing&error=missing_params");
    exit;
}

// Action'a göre status belirle
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

// Onaylama işlemi ise (STATUS = 3), ilk StockTransfer oluştur (gönderici depo -> sevkiyat depo)
if ($action === 'approve' && $newStatus === '3') {
    // ====================================================================================
    // 1. ADIM: BELGE HEADER BİLGİLERİ - ASB2B_TransferRequestList_B1SLQuery
    // ====================================================================================
    $filterStr = "DocEntry eq {$docEntry}";
    $headerQuery = "view.svc/ASB2B_TransferRequestList_B1SLQuery?\$filter=" . urlencode($filterStr) . "&\$top=1";
    $headerData = $sap->get($headerQuery);
    $headerRows = $headerData['response']['value'] ?? [];
    $headerInfo = !empty($headerRows) ? $headerRows[0] : null;
    
    if (!$headerInfo) {
        $errorMsg = 'Transfer talebi bulunamadı!';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        } else {
            header("Location: Transferler.php?view=outgoing&error=" . urlencode($errorMsg));
            exit;
        }
    }
    
    // View'dan gelen bilgiler
    $fromWarehouse = $headerInfo['FromWhsCode'] ?? '';
    $toWarehouse = $headerInfo['WhsCode'] ?? ''; // Bu sevkiyat deposu olmalı
    $docDate = $headerInfo['DocDate'] ?? date('Y-m-d');
    $aliciBranch = $headerInfo['U_ASB2B_BRAN'] ?? $branch;
    
    // FromWarehouse kontrolü
    if (empty($fromWarehouse)) {
        $errorMsg = 'Gönderen depo (FromWhsCode) bulunamadı!';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        } else {
            header("Location: Transferler.php?view=outgoing&error=" . urlencode($errorMsg));
            exit;
        }
    }
    
    // Sevkiyat deposu: View'dan gelen WhsCode kullanılacak (zaten sevkiyat deposu)
    $sevkiyatDepo = $toWarehouse;
    
    if (empty($sevkiyatDepo)) {
        $errorMsg = 'Alıcı şube sevkiyat deposu bulunamadı!';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        } else {
            header("Location: Transferler.php?view=outgoing&error=" . urlencode($errorMsg));
            exit;
        }
    }
    
    // ====================================================================================
    // 2. ADIM: SATIR LİSTESİ - ASB2B_TransferRequestList_B1SLQuery (Tüm satırlar)
    // ====================================================================================
    $linesQuery = "view.svc/ASB2B_TransferRequestList_B1SLQuery?\$filter=" . urlencode($filterStr);
    $linesData = $sap->get($linesQuery);
    $requestLines = $linesData['response']['value'] ?? [];
    
    if (empty($requestLines)) {
        $errorMsg = 'Transfer satırları bulunamadı!';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        } else {
            header("Location: Transferler.php?view=outgoing&error=" . urlencode($errorMsg));
            exit;
        }
    }
    
    // ====================================================================================
    // 3. ADIM: STOCK TRANSFER LİNES HAZIRLAMA
    // ====================================================================================
    $stockTransferLines = [];
    
    // Eğer sepetteki lines gönderilmişse, onları kullan (kullanıcının seçtiği "Gönderilecek" miktarları)
    if (!empty($cartLines) && is_array($cartLines)) {
        // Sepetteki lines'ı kullan (Quantity zaten sentQty * baseQty olarak hesaplanmış)
        foreach ($cartLines as $cartLine) {
            $itemCode = $cartLine['ItemCode'] ?? '';
            $cartLineNum = $cartLine['LineNum'] ?? null;
            $quantity = floatval($cartLine['Quantity'] ?? 0);
            
            if (empty($itemCode) || $quantity <= 0) {
                continue;
            }
            
            // RequestLines'dan eşleşen line'ı bul (LineNum için)
            $matchedRequestLine = null;
            if ($cartLineNum !== null) {
                foreach ($requestLines as $reqLine) {
                    if (($reqLine['LineNum'] ?? null) == $cartLineNum && ($reqLine['ItemCode'] ?? '') === $itemCode) {
                        $matchedRequestLine = $reqLine;
                        break;
                    }
                }
            }
            // LineNum ile bulunamazsa ItemCode ile bul
            if (!$matchedRequestLine) {
                foreach ($requestLines as $reqLine) {
                    if (($reqLine['ItemCode'] ?? '') === $itemCode) {
                        $matchedRequestLine = $reqLine;
                        break;
                    }
                }
            }
            
            // BaseLine: Talebin LineNum'ı
            $baseLine = 0;
            if ($matchedRequestLine && isset($matchedRequestLine['LineNum'])) {
                $baseLine = (int)$matchedRequestLine['LineNum'];
            } elseif ($cartLineNum !== null) {
                $baseLine = (int)$cartLineNum;
            }
            
            $lineData = [
                'ItemCode' => $itemCode,
                'Quantity' => $quantity, // Sepetteki "Gönderilecek" miktar × baseQty
                'FromWarehouseCode' => $fromWarehouse,
                'WarehouseCode' => $sevkiyatDepo,
                'BaseType' => 1250000001, // Statik - InventoryTransferRequest için
                'BaseEntry' => (int)$docEntry, // Talep doc entry
                'BaseLine' => $baseLine // Talebin LineNum'ı
            ];
            
            $stockTransferLines[] = $lineData;
        }
    } else {
        // Sepetteki lines yoksa, view'dan gelen tüm satırları kullan (fallback)
        foreach ($requestLines as $line) {
            $itemCode = $line['ItemCode'] ?? '';
            $quantity = floatval($line['Quantity'] ?? 0);
            $lineNum = isset($line['LineNum']) ? (int)$line['LineNum'] : 0;
            
            if (empty($itemCode) || $quantity <= 0) {
                continue;
            }
            
            $lineData = [
                'ItemCode' => $itemCode,
                'Quantity' => $quantity, // Talep edilen miktar (fallback)
                'FromWarehouseCode' => $fromWarehouse,
                'WarehouseCode' => $sevkiyatDepo,
                'BaseType' => 1250000001, // Statik - InventoryTransferRequest için
                'BaseEntry' => (int)$docEntry, // Talep doc entry
                'BaseLine' => $lineNum // Talebin LineNum'ı
            ];
            
            $stockTransferLines[] = $lineData;
        }
    }
    
    // İlk StockTransfer oluştur (gönderici depo -> sevkiyat depo)
    if (empty($stockTransferLines)) {
        $errorMsg = 'Transfer satırları bulunamadı veya geçersiz!';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false, 
                'message' => $errorMsg
            ]);
            exit;
        } else {
            header("Location: Transferler.php?view=outgoing&error=" . urlencode($errorMsg));
            exit;
        }
    }
    
    $stockTransferPayload = [
        'FromWarehouse' => $fromWarehouse,
        'ToWarehouse' => $sevkiyatDepo,
        'DocDate' => $docDate,
        'Comments' => "Transfer talebi onaylandı - Sevkiyat deposuna transfer",
        'U_ASB2B_BRAN' => $branch,
        'U_AS_OWNR' => $uAsOwnr,
        'U_ASB2B_STATUS' => '3', // Sevk edildi
        'U_ASB2B_TYPE' => 'TRANSFER',
        'U_ASB2B_User' => $userName,
        'U_ASB2B_QutMaster' => (int)$docEntry,
        'StockTransferLines' => $stockTransferLines
    ];
    
    $stockTransferResult = $sap->post('StockTransfers', $stockTransferPayload);
    
    // StockTransfer oluşturma hatası varsa logla ve hata mesajı göster
    if ($stockTransferResult['status'] != 200 && $stockTransferResult['status'] != 201) {
        $errorMsg = 'StockTransfer oluşturulamadı: HTTP ' . ($stockTransferResult['status'] ?? 'NO STATUS');
        
        // Detaylı hata mesajı oluştur
        if (isset($stockTransferResult['response']['error'])) {
            $error = $stockTransferResult['response']['error'];
            if (isset($error['message'])) {
                $errorMsg .= ' - ' . $error['message'];
            } else {
                $errorMsg .= ' - ' . json_encode($error);
            }
            
            // Code ve details varsa ekle
            if (isset($error['code'])) {
                $errorMsg .= ' (Kod: ' . $error['code'] . ')';
            }
            if (is_array($error) && isset($error['details'])) {
                $detailMsgs = [];
                if (is_array($error['details']) && !empty($error['details'])) {
                    foreach ($error['details'] as $detail) {
                        if (is_array($detail) && isset($detail['message'])) {
                            $detailMsgs[] = $detail['message'];
                        } elseif (is_string($detail)) {
                            $detailMsgs[] = $detail;
                        }
                    }
                } elseif (is_string($error['details'])) {
                    $detailMsgs[] = $error['details'];
                }
                if (!empty($detailMsgs)) {
                    $errorMsg .= ' - Detaylar: ' . implode(', ', $detailMsgs);
                }
            }
        } else {
            // Response'un kendisini ekle
            $errorMsg .= ' - Response: ' . json_encode($stockTransferResult['response'] ?? []);
        }
        
        // AJAX ise JSON döndür
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false, 
                'message' => $errorMsg, 
                'docEntry' => $docEntry,
                'debug' => $stockTransferResult
            ]);
            exit;
        } else {
            header("Location: Transferler.php?view=outgoing&error=" . urlencode($errorMsg));
            exit;
        }
    }
    
    // StockTransfer başarıyla oluşturuldu
    // Status'u '3' (Sevk Edildi) olarak güncelle
    $newStatus = '3';
    
    // PATCH request ile status güncelle (StockTransfer başarılı olduktan sonra)
    $updatePayload = [
        'U_ASB2B_STATUS' => $newStatus
    ];
    
    $result = $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);
    
    // Status güncelleme başarısız olursa logla ama işlemi durdurma
    if ($result['status'] != 200 && $result['status'] != 204) {
        error_log("[TRANSFER-ONAYLA] Status güncellenemedi. DocEntry: {$docEntry}, Status: " . ($result['status'] ?? 'NO STATUS') . ", Error: " . json_encode($result['response']['error'] ?? []));
    }
} else {
    // Reject durumunda status güncelle
    $updatePayload = [
        'U_ASB2B_STATUS' => $newStatus
    ];
    
    $result = $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);
}

// AJAX isteği ise JSON döndür
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if ($result['status'] == 200 || $result['status'] == 204) {
        echo json_encode(['success' => true, 'message' => $action === 'approve' ? 'Transfer onaylandı' : 'Transfer iptal edildi']);
    } else {
        $errorMsg = 'Durum güncellenemedi: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        echo json_encode(['success' => false, 'message' => $errorMsg]);
    }
} else {
    // Normal GET isteği ise redirect yap
    if ($result['status'] == 200 || $result['status'] == 204) {
        $successMsg = $action === 'approve' ? 'onaylandi' : 'iptal_edildi';
        header("Location: Transferler.php?view=outgoing&msg={$successMsg}");
    } else {
        $errorMsg = 'Durum güncellenemedi: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        header("Location: Transferler.php?view=outgoing&error=" . urlencode($errorMsg));
    }
}
exit;
?>
