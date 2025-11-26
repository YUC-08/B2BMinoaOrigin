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
$lines = $_POST['lines'] ?? null; // POST ile gönderilen lines (şimdilik kullanılmıyor)

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
    $newStatus = '2'; // HAZIRLANIYOR
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

// Onaylama işlemi ise (STATUS = 2), ilk StockTransfer oluştur (gönderici depo -> sevkiyat depo)
if ($action === 'approve' && $newStatus === '2') {
    // InventoryTransferRequest bilgilerini çek
    // Önce header bilgilerini çek (FromWarehouse, ToWarehouse için)
    $headerQuery = "InventoryTransferRequests({$docEntry})?\$select=FromWarehouse,ToWarehouse,DocDate,DocNum,U_ASB2B_BRAN";
    $headerData = $sap->get($headerQuery);
    $headerInfo = $headerData['response'] ?? null;
    
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
    
    $fromWarehouse = $headerInfo['FromWarehouse'] ?? '';
    $toWarehouse = $headerInfo['ToWarehouse'] ?? ''; // Bu sevkiyat deposu olmalı
    $docDate = $headerInfo['DocDate'] ?? date('Y-m-d');
    $docNum = $headerInfo['DocNum'] ?? $docEntry;
    $aliciBranch = $headerInfo['U_ASB2B_BRAN'] ?? '';
    
    // Lines'ı ayrı çek
    $requestQuery = "InventoryTransferRequests({$docEntry})?\$expand=InventoryTransferRequestLines";
    $requestData = $sap->get($requestQuery);
    $requestInfo = $requestData['response'] ?? null;
    
    // FromWarehouse kontrolü
    if (empty($fromWarehouse)) {
        $errorMsg = 'Gönderen depo (FromWarehouse) bulunamadı!';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $errorMsg]);
            exit;
        } else {
            header("Location: Transferler.php?view=outgoing&error=" . urlencode($errorMsg));
            exit;
        }
    }
    
    // Alıcı şubenin sevkiyat deposunu bul
    // ToWarehouse zaten sevkiyat deposu olmalı (InventoryTransferRequest'te alıcı şube sevkiyat deposunu belirtir)
    // Alıcı şubenin sevkiyat deposunu bul (U_ASB2B_MAIN='2')
    $sevkiyatDepo = null;
    if (!empty($aliciBranch)) {
        $sevkiyatDepoFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$aliciBranch}' and U_ASB2B_MAIN eq '2'";
        $sevkiyatDepoQuery = "Warehouses?\$filter=" . urlencode($sevkiyatDepoFilter);
        $sevkiyatDepoData = $sap->get($sevkiyatDepoQuery);
        $sevkiyatDepolar = $sevkiyatDepoData['response']['value'] ?? [];
        $sevkiyatDepo = !empty($sevkiyatDepolar) ? $sevkiyatDepolar[0]['WarehouseCode'] : null;
    }
    
    // Eğer sevkiyat deposu bulunamazsa, request'teki ToWarehouse'u kullan
    if (empty($sevkiyatDepo)) {
        $sevkiyatDepo = $toWarehouse;
    }
    
    // Sevkiyat deposu kontrolü
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
    
    // InventoryTransferRequestLines'ı hazırla - çoklu deneme stratejisi (Transferler-TeslimAl.php'deki gibi)
    $requestLines = [];
    
    // Deneme 1: Expand ile InventoryTransferRequestLines
    $linesQuery1 = "InventoryTransferRequests({$docEntry})?\$expand=InventoryTransferRequestLines";
    $linesData1 = $sap->get($linesQuery1);
    if (($linesData1['status'] ?? 0) == 200) {
        $response1 = $linesData1['response'] ?? null;
        if ($response1 && isset($response1['InventoryTransferRequestLines']) && is_array($response1['InventoryTransferRequestLines'])) {
            $requestLines = $response1['InventoryTransferRequestLines'];
        }
    }
    
    // Deneme 2: Expand ile StockTransferLines (fallback)
    if (empty($requestLines)) {
        $linesQuery2 = "InventoryTransferRequests({$docEntry})?\$expand=StockTransferLines";
        $linesData2 = $sap->get($linesQuery2);
        if (($linesData2['status'] ?? 0) == 200) {
            $response2 = $linesData2['response'] ?? null;
            if ($response2 && isset($response2['StockTransferLines']) && is_array($response2['StockTransferLines'])) {
                $requestLines = $response2['StockTransferLines'];
            }
        }
    }
    
    // Deneme 3: Direct query ile InventoryTransferRequestLines
    if (empty($requestLines)) {
        $linesQuery3 = "InventoryTransferRequests({$docEntry})/InventoryTransferRequestLines";
        $linesData3 = $sap->get($linesQuery3);
        if (($linesData3['status'] ?? 0) == 200) {
            $response3 = $linesData3['response'] ?? null;
            if ($response3) {
                if (isset($response3['value']) && is_array($response3['value'])) {
                    $requestLines = $response3['value'];
                } elseif (is_array($response3) && !isset($response3['value'])) {
                    $requestLines = $response3;
                }
            }
        }
    }
    
    // Deneme 4: Direct query ile StockTransferLines (fallback)
    if (empty($requestLines)) {
        $linesQuery4 = "InventoryTransferRequests({$docEntry})/StockTransferLines";
        $linesData4 = $sap->get($linesQuery4);
        if (($linesData4['status'] ?? 0) == 200) {
            $response4 = $linesData4['response'] ?? null;
            if ($response4) {
                if (isset($response4['value']) && is_array($response4['value'])) {
                    $requestLines = $response4['value'];
                } elseif (is_array($response4) && !isset($response4['value']) && !isset($response4['@odata.context'])) {
                    $requestLines = $response4;
                } elseif (isset($response4['StockTransferLines'])) {
                    $stockTransferLines = $response4['StockTransferLines'];
                    if (is_array($stockTransferLines)) {
                        $requestLines = $stockTransferLines;
                    }
                }
            }
        }
    }
    
    $stockTransferLines = [];
    
    foreach ($requestLines as $line) {
        $itemCode = $line['ItemCode'] ?? '';
        $quantity = floatval($line['Quantity'] ?? 0);
        
        if (empty($itemCode) || $quantity <= 0) {
            continue;
        }
        
        // StockTransfer için: Line'lardaki FromWarehouseCode header'daki FromWarehouse ile aynı olmalı
        // TransferlerSO.php'deki gibi, line'larda FromWarehouseCode kullanılıyor ama header'daki FromWarehouse ile uyumlu olmalı
        $stockTransferLines[] = [
            'ItemCode' => $itemCode,
            'Quantity' => $quantity,
            'FromWarehouseCode' => $fromWarehouse, // Header'daki FromWarehouse (gönderen şube deposu)
            'WarehouseCode' => $sevkiyatDepo // Alıcı şubenin sevkiyat deposu
        ];
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
        'U_ASB2B_STATUS' => '2', // Hazırlanıyor
        'U_ASB2B_TYPE' => 'TRANSFER',
        'U_ASB2B_User' => $userName,
        'U_ASB2B_QutMaster' => (int)$docEntry,
        'DocumentReferences' => [
            [
                'RefDocEntr' => (int)$docEntry,
                'RefDocNum' => (int)$docNum,
                'RefObjType' => 'rot_InventoryTransferRequest'
            ]
        ],
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
            if (isset($error['details']) && is_array($error['details']) && !empty($error['details'])) {
                $detailMsgs = [];
                foreach ($error['details'] as $detail) {
                    if (is_array($detail) && isset($detail['message'])) {
                        $detailMsgs[] = $detail['message'];
                    } elseif (is_string($detail)) {
                        $detailMsgs[] = $detail;
                    }
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
    $stockTransferResponse = $stockTransferResult['response'] ?? [];
    $stockTransferDocEntry = is_array($stockTransferResponse) ? ($stockTransferResponse['DocEntry'] ?? null) : null;
    $stockTransferDocNum = is_array($stockTransferResponse) ? ($stockTransferResponse['DocNum'] ?? null) : null;
}

// PATCH request ile status güncelle
$updatePayload = [
    'U_ASB2B_STATUS' => $newStatus
];

$result = $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);

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