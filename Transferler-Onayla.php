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
                // BaseType/BaseEntry/BaseLine kaldırıldı (kapanmayı engellemek için)
                'OriginalLineNum' => $baseLine // Satır güncellemesi için saklanıyor
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
                // BaseType/BaseEntry/BaseLine kaldırıldı (kapanmayı engellemek için)
                'OriginalLineNum' => $lineNum // Satır güncellemesi için saklanıyor
            ];
            
            $stockTransferLines[] = $lineData;
        }
    }
    
    // İlk StockTransfer oluştur (gönderici depo -> sevkiyat depo)
    if (empty($stockTransferLines)) {
        $errorMsg = 'Transfer satırları bulunamadı veya geçersiz!';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        } else {
            header("Location: Transferler.php?view=outgoing&error=" . urlencode($errorMsg));
            exit;
        }
    }
    
    // --- DÜZELTME BAŞLANGICI ---
    // SAP'ye gönderilecek "Temiz" listeyi hazırla.
    // 'OriginalLineNum' alanını SAP kabul etmez (Hata -1000 sebebi).
    // Bu yüzden SAP'ye gönderirken bu alanı siliyoruz.
    $cleanStockTransferLines = array_map(function($line) {
        if (isset($line['OriginalLineNum'])) {
            unset($line['OriginalLineNum']); 
        }
        return $line;
    }, $stockTransferLines);

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
        'StockTransferLines' => $cleanStockTransferLines // DİKKAT: Temizlenmiş liste kullanılıyor
    ];
    // --- DÜZELTME BİTİŞİ ---
    
    // DEBUG: Payload'ı logla
    error_log("[TRANSFER-ONAYLA] StockTransfer Payload: " . json_encode($stockTransferPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    $stockTransferResult = $sap->post('StockTransfers', $stockTransferPayload);
    
    // DEBUG: StockTransfer sonucu
    error_log("[TRANSFER-ONAYLA] StockTransfer Result - Status: " . ($stockTransferResult['status'] ?? 'NO STATUS') . ", Response: " . json_encode($stockTransferResult['response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
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
    
    // 1. Önce Başlığı Güncelle
    $headerUpdatePayload = [
        'U_ASB2B_STATUS' => '3'
    ];
    
    // DEBUG: Header update payload
    error_log("[TRANSFER-ONAYLA] Header Update Payload: " . json_encode($headerUpdatePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    $headerResult = $sap->patch("InventoryTransferRequests({$docEntry})", $headerUpdatePayload);
    
    // DEBUG: Header update sonucu
    error_log("[TRANSFER-ONAYLA] Header Update Result - Status: " . ($headerResult['status'] ?? 'NO STATUS') . ", Response: " . json_encode($headerResult['response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    // 2. Satırları Güncellemek İçin Veriyi Hazırla
    $requestLinesToUpdate = [];
    
    if (!empty($stockTransferLines)) {
        foreach ($stockTransferLines as $stLine) {
            // OriginalLineNum kullan (BaseLine yorum satırında olduğu için)
            if (isset($stLine['OriginalLineNum'])) {
                $lineNum = (int)$stLine['OriginalLineNum'];
                $requestLinesToUpdate[] = [
                    'LineNum' => $lineNum,
                    'U_ASB2B_STATUS' => '3' // SATIR DURUMU: Sevk Edildi
                ];
            } elseif (isset($stLine['BaseLine'])) {
                // Fallback: Eğer BaseLine varsa (eski mantık)
                $requestLinesToUpdate[] = [
                    'LineNum' => (int)$stLine['BaseLine'],
                    'U_ASB2B_STATUS' => '3'
                ];
            }
        }
    }
    
    // 3. Satırları Güncelle (Doğru Key İle: StockTransferLines)
    $result = $headerResult; // Varsayılan olarak header sonucunu kullan
    
    if (!empty($requestLinesToUpdate)) {
        $linesUpdatePayload = [
            'StockTransferLines' => $requestLinesToUpdate // DÜZELTİLEN KISIM: InventoryTransferRequestLines -> StockTransferLines
        ];
        
        // DEBUG: Lines update payload
        error_log("[TRANSFER-ONAYLA] Lines Update Payload: " . json_encode($linesUpdatePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        error_log("[TRANSFER-ONAYLA] RequestLinesToUpdate Count: " . count($requestLinesToUpdate));
        error_log("[TRANSFER-ONAYLA] StockTransferLines Count: " . count($stockTransferLines));
        
        $lineResult = $sap->patch("InventoryTransferRequests({$docEntry})", $linesUpdatePayload);
        
        // DEBUG: Lines update sonucu
        error_log("[TRANSFER-ONAYLA] Lines Update Result - Status: " . ($lineResult['status'] ?? 'NO STATUS') . ", Response: " . json_encode($lineResult['response'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        // Satır güncellemesi başarısız olursa logla
        if ($lineResult['status'] != 200 && $lineResult['status'] != 204) {
            $errorDetails = [
                'DocEntry' => $docEntry,
                'HTTP_Status' => $lineResult['status'] ?? 'NO STATUS',
                'Error' => $lineResult['response']['error'] ?? null,
                'FullResponse' => $lineResult['response'] ?? null,
                'LinesUpdatePayload' => $linesUpdatePayload,
                'RequestLinesToUpdate' => $requestLinesToUpdate
            ];
            error_log("[TRANSFER-ONAYLA] Satır güncellenemedi. Detaylar: " . json_encode($errorDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
        $result = $lineResult; // Satır güncellemesi sonucunu kullan
    }
    
    // Genel sonuç: Header ve satır güncellemelerinden birisi başarısız olursa hata döndür
    if ($headerResult['status'] != 200 && $headerResult['status'] != 204) {
        $result = $headerResult; // Header hatası öncelikli
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
    
    // Debug bilgilerini hazırla
    $debugInfo = [
        'docEntry' => $docEntry,
        'action' => $action,
        'updateStatus' => $result['status'] ?? 'NO STATUS'
    ];
    
    if ($action === 'approve' && isset($stockTransferResult)) {
        $debugInfo['stockTransferStatus'] = $stockTransferResult['status'] ?? 'N/A';
        $debugInfo['headerUpdateStatus'] = isset($headerResult) ? ($headerResult['status'] ?? 'N/A') : 'N/A';
        $debugInfo['linesUpdateStatus'] = isset($lineResult) ? ($lineResult['status'] ?? 'N/A') : 'N/A';
        $debugInfo['requestLinesToUpdate'] = $requestLinesToUpdate ?? [];
        $debugInfo['headerUpdatePayload'] = isset($headerUpdatePayload) ? $headerUpdatePayload : null;
        $debugInfo['linesUpdatePayload'] = isset($linesUpdatePayload) ? $linesUpdatePayload : null;
        $debugInfo['stockTransferLines'] = isset($stockTransferLines) ? count($stockTransferLines) : 0;
    }
    
    if ($result['status'] == 200 || $result['status'] == 204) {
        echo json_encode([
            'success' => true, 
            'message' => $action === 'approve' ? 'Transfer onaylandı' : 'Transfer iptal edildi',
            'debug' => $debugInfo
        ]);
    } else {
        $errorMsg = 'Durum güncellenemedi: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        
        $debugInfo['updateError'] = $result['response']['error'] ?? null;
        $debugInfo['updateResponse'] = $result['response'] ?? null;
        if (isset($headerResult) && ($headerResult['status'] != 200 && $headerResult['status'] != 204)) {
            $debugInfo['headerUpdateError'] = $headerResult['response']['error'] ?? null;
        }
        if (isset($lineResult) && ($lineResult['status'] != 200 && $lineResult['status'] != 204)) {
            $debugInfo['linesUpdateError'] = $lineResult['response']['error'] ?? null;
        }
        
        echo json_encode([
            'success' => false, 
            'message' => $errorMsg,
            'debug' => $debugInfo
        ]);
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
