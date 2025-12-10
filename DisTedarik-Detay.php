<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

$requestNo = $_GET['requestNo'] ?? '';
$orderNo = $_GET['orderNo'] ?? null;
$deliveryNoteDocEntry = $_GET['deliveryNote'] ?? null;


// POST: PurchaseOrders oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    header('Content-Type: application/json');
    
    $purchaseRequestDocEntry = intval($_POST['requestDocEntry'] ?? 0);
    $cardCode = trim($_POST['cardCode'] ?? '');
    $teslimatNo = trim($_POST['teslimatNo'] ?? '');
    $lines = json_decode($_POST['lines'] ?? '[]', true);
    
    if (empty($purchaseRequestDocEntry) || empty($cardCode) || empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'Eksik bilgi: Request DocEntry, CardCode ve en az bir kalem gereklidir']);
        exit;
    }
    
    // PurchaseRequest'i çek (CardCode ve DocumentLines için)
    $requestQuery = "PurchaseRequests({$purchaseRequestDocEntry})?\$expand=DocumentLines";
    $requestData = $sap->get($requestQuery);
    
    if (($requestData['status'] ?? 0) != 200) {
        echo json_encode(['success' => false, 'message' => 'PurchaseRequest bulunamadı!']);
        exit;
    }
    
    $requestResponse = $requestData['response'] ?? [];
    
    // CardCode'u PurchaseRequest'ten al (eğer POST'ta gönderilmemişse)
    if (empty($cardCode)) {
        $cardCode = $requestResponse['CardCode'] ?? '';
    }
    
    if (empty($cardCode)) {
        echo json_encode(['success' => false, 'message' => 'CardCode bulunamadı! PurchaseRequest\'te CardCode tanımlı olmalıdır.']);
        exit;
    }
    
    $requestLines = $requestResponse['DocumentLines'] ?? [];
    
    // DocumentLines oluştur
    $documentLines = [];
    foreach ($lines as $line) {
        $lineNum = intval($line['lineNum'] ?? 0);
        $quantity = floatval($line['quantity'] ?? 0);
        
        if ($quantity > 0 && $lineNum >= 0) {
            $documentLines[] = [
                'BaseType' => 1470000113, // PurchaseRequest için
                'BaseEntry' => $purchaseRequestDocEntry, // PurchaseRequest.DocEntry
                'BaseLine' => $lineNum, // PurchaseRequestLine.LineNum
                'Quantity' => $quantity // Kullanıcının girdiği PO miktarı
            ];
        }
    }
    
    if (empty($documentLines)) {
        echo json_encode(['success' => false, 'message' => 'Miktarı girilen kalem bulunamadı!']);
        exit;
    }
    
    // PurchaseOrders payload
    $payload = [
        'CardCode' => $cardCode, // PurchaseRequest'ten alınan CardCode
        'U_ASB2B_NumAtCard' => $teslimatNo, // Teslimat numarası
        'DocumentLines' => $documentLines
    ];
    
    $result = $sap->post('PurchaseOrders', $payload);
    
    if ($result['status'] == 200 || $result['status'] == 201) {
        $orderDocEntry = $result['response']['DocEntry'] ?? null;
        $orderDocNum = $result['response']['DocNum'] ?? null;
        
        // PurchaseRequest'i güncelle: U_ASB2B_ORNO ve U_ASB2B_STATUS
        if ($orderDocNum) {
            $updatePayload = [
                'U_ASB2B_ORNO' => (string)$orderDocNum,
                'U_ASB2B_STATUS' => '3' // Sevk edildi
            ];
            $updateResult = $sap->patch("PurchaseRequests({$purchaseRequestDocEntry})", $updatePayload);
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Sipariş başarıyla oluşturuldu!', 
            'orderDocEntry' => $orderDocEntry,
            'orderDocNum' => $orderDocNum
        ]);
    } else {
        $errorMsg = 'Sipariş oluşturulamadı: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        echo json_encode(['success' => false, 'message' => $errorMsg, 'response' => $result]);
    }
    exit;
}

$detailData = null;
$lines = [];
$isPurchaseOrder = !empty($orderNo);
$errorMsg = '';
$allOrdersForRequest = [];

$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? '';

// DEBUG: PurchaseDeliveryNotes detayı için
$debugInfo = [];

// PurchaseDeliveryNotes detayı (U_AS2B2_NORE = 1)
if ($isDeliveryNote && !empty($deliveryNoteDocEntry)) {
    $debugInfo['step'] = 'PurchaseDeliveryNotes Detay';
    $debugInfo['deliveryNoteDocEntry'] = $deliveryNoteDocEntry;
    $debugInfo['isDeliveryNote'] = $isDeliveryNote;
    
    // PurchaseDeliveryNotes detayını çek
    $dnQuery = "PurchaseDeliveryNotes({$deliveryNoteDocEntry})?\$select=DocEntry,DocNum,DocDate,U_ASB2B_NumAtCard,U_ASB2B_BRAN,U_AS_OWNR,U_AS2B2_NORE,U_ASB2B_STATUS,CardCode";
    $debugInfo['query'] = $dnQuery;
    $dnData = $sap->get($dnQuery);
    
    $debugInfo['response_status'] = $dnData['status'] ?? 'NO STATUS';
    $debugInfo['has_response'] = isset($dnData['response']);
    $debugInfo['response_keys'] = isset($dnData['response']) ? array_keys($dnData['response']) : [];
    
    if (($dnData['status'] ?? 0) == 200 && isset($dnData['response'])) {
        $detailData = $dnData['response'];
        $dnU_AS2B2_NORE = $detailData['U_AS2B2_NORE'] ?? null;
        $debugInfo['U_AS2B2_NORE'] = $dnU_AS2B2_NORE;
        $debugInfo['detailData_keys'] = array_keys($detailData);
        
        // U_AS2B2_NORE = 1 ise kayıt dışı mal (veya direkt PurchaseDeliveryNotes ise)
        // PurchaseDeliveryNotes detayına tıklandığında her durumda göster
        if ($dnU_AS2B2_NORE === '1' || $dnU_AS2B2_NORE === 1 || empty($dnU_AS2B2_NORE)) { // U_AS2B2_NORE = 1 veya boş ise göster
            // DocumentLines çek
            $dnLinesQuery = "PurchaseDeliveryNotes({$deliveryNoteDocEntry})/DocumentLines";
            $debugInfo['lines_query'] = $dnLinesQuery;
            $dnLinesData = $sap->get($dnLinesQuery);
            
            $debugInfo['lines_response_status'] = $dnLinesData['status'] ?? 'NO STATUS';
            $debugInfo['lines_has_response'] = isset($dnLinesData['response']);
            
            if (($dnLinesData['status'] ?? 0) == 200 && isset($dnLinesData['response'])) {
                $dnResp = $dnLinesData['response'];
                $debugInfo['lines_response_keys'] = array_keys($dnResp);
                
                if (isset($dnResp['value']) && is_array($dnResp['value'])) {
                    $lines = $dnResp['value'];
                    $debugInfo['lines_source'] = 'value';
                    $debugInfo['lines_count'] = count($lines);
                } elseif (isset($dnResp['DocumentLines']) && is_array($dnResp['DocumentLines'])) {
                    $lines = $dnResp['DocumentLines'];
                    $debugInfo['lines_source'] = 'DocumentLines';
                    $debugInfo['lines_count'] = count($lines);
                } else {
                    $debugInfo['lines_error'] = 'Lines formatı beklenmiyor';
                }
            } else {
                $debugInfo['lines_error'] = 'Lines çekilemedi: HTTP ' . ($dnLinesData['status'] ?? 'NO STATUS');
                if (isset($dnLinesData['response']['error'])) {
                    $debugInfo['lines_error_detail'] = $dnLinesData['response']['error'];
                }
            }
        } else {
            $errorMsg = "Bu belge kayıt dışı mal değil!";
            $debugInfo['error'] = 'U_AS2B2_NORE kontrolü başarısız: ' . var_export($dnU_AS2B2_NORE, true);
        }
    } else {
        $errorMsg = "İrsaliye detayları alınamadı! HTTP " . ($dnData['status'] ?? 'NO STATUS');
        if (isset($dnData['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($dnData['response']['error']);
            $debugInfo['error_detail'] = $dnData['response']['error'];
        }
        $debugInfo['error'] = 'PurchaseDeliveryNotes detayı çekilemedi';
    }
} elseif ($isPurchaseOrder) {
    $debugInfo['step'] = 'PurchaseOrder Detay';
    $debugInfo['orderNo'] = $orderNo;
    // Sipariş detayı
    $orderQuery = 'PurchaseOrders(' . intval($orderNo) . ')';
    $orderData = $sap->get($orderQuery);
    
    if (($orderData['status'] ?? 0) == 200 && isset($orderData['response'])) {
        $detailData = $orderData['response'];
        $orderDocEntry = $detailData['DocEntry'] ?? intval($orderNo);
        
        $canReceive = false;
        $orderStatus = null;
        if (!empty($uAsOwnr) && !empty($branch)) {
            $orderNoInt = intval($orderNo);
            $viewFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_ORNO eq {$orderNoInt}";
            $viewQuery = 'view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=' . urlencode($viewFilter);
            $viewData = $sap->get($viewQuery);
            $viewRows = $viewData['response']['value'] ?? [];
            
            if (!empty($viewRows)) {
                $orderStatus = $viewRows[0]['U_ASB2B_STATUS'] ?? null;
                // Status null veya boş ise default olarak '1' (Onay bekleniyor) yap
                if (empty($orderStatus) || $orderStatus === 'null' || $orderStatus === '') {
                    $orderStatus = '1';
                }
                $canReceive = isReceivableStatus($orderStatus);
            }
        }
        
        $linesQuery = "PurchaseOrders({$orderDocEntry})/DocumentLines";
        $linesData = $sap->get($linesQuery);
        
        if (($linesData['status'] ?? 0) == 200 && isset($linesData['response'])) {
            $resp = $linesData['response'];
            if (isset($resp['value']) && is_array($resp['value'])) {
                $lines = $resp['value'];
            } elseif (isset($resp['DocumentLines']) && is_array($resp['DocumentLines'])) {
                $lines = $resp['DocumentLines'];
            }
        }
    } else {
        $errorMsg = "Sipariş detayları alınamadı!";
    }
} else {
    // PurchaseRequest detayı
    if (!empty($requestNo)) {
        // View'den tüm kayıtları çek (OrderNo olan ve olmayan)
        $itemsWithOrderNo = []; // OrderNo'ya sahip ItemCode'lar [OrderNo => [ItemCode1, ItemCode2, ...]]
        $allOrderNos = []; // Tüm OrderNo'lar (sipariş numaraları)
        
        if (!empty($uAsOwnr) && !empty($branch)) {
            $requestNoInt = intval($requestNo);
        $viewFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and RequestNo eq {$requestNoInt}";
        $viewQuery = 'view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=' . urlencode($viewFilter) . '&$orderby=' . urlencode('U_ASB2B_ORNO desc');
        $viewData = $sap->get($viewQuery);
        $viewRows = $viewData['response']['value'] ?? [];
        
        foreach ($viewRows as $row) {
            $status = $row['U_ASB2B_STATUS'] ?? null;
            // Status null veya boş ise default olarak '1' (Onay bekleniyor) yap
            if (empty($status) || $status === 'null' || $status === '') {
                $status = '1';
            }
            
            $orderNoFromView = $row['U_ASB2B_ORNO'] ?? null;
            $statusText = getStatusText($status);
            $canReceive = isReceivableStatus($status);
            
            // OrderNo varsa listeye ekle
            if (!empty($orderNoFromView) && $orderNoFromView !== null && $orderNoFromView !== '' && $orderNoFromView !== '-') {
                $orderNoInt = intval($orderNoFromView);
                if (!in_array($orderNoInt, $allOrderNos)) {
                    $allOrderNos[] = $orderNoInt;
                }
            }
            
            // Status "Onay bekleniyor" (1) ise OrderNo boş gösterilmeli
            // Tüm kayıtları ekle, ama status "1" ise OrderNo'yu boş bırak
            $allOrdersForRequest[] = [
                'OrderNo' => ($status === '1') ? null : ($orderNoFromView ?? null), // Status "1" ise OrderNo null
                'OrderDate' => $row['U_ASB2B_ORDT'] ?? null,
                'Status' => $status,
                'StatusText' => $statusText,
                'CanReceive' => $canReceive
            ];
        }
    }
    
    // PurchaseOrders'ten OrderNo'ya sahip ItemCode'ları çek
    foreach ($allOrderNos as $orderNoInt) {
        $orderQuery = 'PurchaseOrders(' . $orderNoInt . ')';
        $orderData = $sap->get($orderQuery);
        if (($orderData['status'] ?? 0) == 200 && isset($orderData['response'])) {
            $orderDocEntry = $orderData['response']['DocEntry'] ?? $orderNoInt;
            $orderLinesQuery = "PurchaseOrders({$orderDocEntry})/DocumentLines";
            $orderLinesData = $sap->get($orderLinesQuery);
            if (($orderLinesData['status'] ?? 0) == 200 && isset($orderLinesData['response'])) {
                $orderResp = $orderLinesData['response'];
                $orderLines = [];
                if (isset($orderResp['value']) && is_array($orderResp['value'])) {
                    $orderLines = $orderResp['value'];
                } elseif (isset($orderResp['DocumentLines']) && is_array($orderResp['DocumentLines'])) {
                    $orderLines = $orderResp['DocumentLines'];
                }
                // Bu OrderNo'ya ait ItemCode'ları topla
                foreach ($orderLines as $orderLine) {
                    $itemCode = $orderLine['ItemCode'] ?? '';
                    if (!empty($itemCode)) {
                        if (!isset($itemsWithOrderNo[$orderNoInt])) {
                            $itemsWithOrderNo[$orderNoInt] = [];
                        }
                        if (!in_array($itemCode, $itemsWithOrderNo[$orderNoInt])) {
                            $itemsWithOrderNo[$orderNoInt][] = $itemCode;
                        }
                    }
                }
            }
        }
    }
    
    $requestQuery = 'PurchaseRequests(' . intval($requestNo) . ')';
    $debugInfo['request_query'] = $requestQuery;
    $requestData = $sap->get($requestQuery);
    
    $debugInfo['request_response_status'] = $requestData['status'] ?? 'NO STATUS';
    $debugInfo['request_has_response'] = isset($requestData['response']);
    
    // Eğer PurchaseRequest bulunamadıysa (404), PurchaseDeliveryNotes'a bak (U_AS2B2_NORE = 1 olanlar)
    if (($requestData['status'] ?? 0) == 404) {
        $debugInfo['step'] = 'PurchaseRequest bulunamadı (404), PurchaseDeliveryNotes kontrol ediliyor';
        $deliveryNoteDocEntry = intval($requestNo);
        $dnQuery = "PurchaseDeliveryNotes({$deliveryNoteDocEntry})?\$select=DocEntry,DocNum,DocDate,U_ASB2B_NumAtCard,U_ASB2B_BRAN,U_AS_OWNR,U_AS2B2_NORE,U_ASB2B_STATUS,CardCode";
        $debugInfo['delivery_note_query'] = $dnQuery;
        $dnData = $sap->get($dnQuery);
        
        $debugInfo['delivery_note_response_status'] = $dnData['status'] ?? 'NO STATUS';
        $debugInfo['delivery_note_has_response'] = isset($dnData['response']);
        
        if (($dnData['status'] ?? 0) == 200 && isset($dnData['response'])) {
            $detailData = $dnData['response'];
            $dnU_AS2B2_NORE = $detailData['U_AS2B2_NORE'] ?? null;
            $debugInfo['U_AS2B2_NORE'] = $dnU_AS2B2_NORE;
            
            // U_AS2B2_NORE = 1 ise kayıt dışı mal
            if ($dnU_AS2B2_NORE === '1' || $dnU_AS2B2_NORE === 1) {
                // DocumentLines çek
                $dnLinesQuery = "PurchaseDeliveryNotes({$deliveryNoteDocEntry})/DocumentLines";
                $debugInfo['lines_query'] = $dnLinesQuery;
                $dnLinesData = $sap->get($dnLinesQuery);
                
                $debugInfo['lines_response_status'] = $dnLinesData['status'] ?? 'NO STATUS';
                $debugInfo['lines_has_response'] = isset($dnLinesData['response']);
                
                if (($dnLinesData['status'] ?? 0) == 200 && isset($dnLinesData['response'])) {
                    $dnResp = $dnLinesData['response'];
                    if (isset($dnResp['value']) && is_array($dnResp['value'])) {
                        $lines = $dnResp['value'];
                        $debugInfo['lines_source'] = 'value';
                        $debugInfo['lines_count'] = count($lines);
                    } elseif (isset($dnResp['DocumentLines']) && is_array($dnResp['DocumentLines'])) {
                        $lines = $dnResp['DocumentLines'];
                        $debugInfo['lines_source'] = 'DocumentLines';
                        $debugInfo['lines_count'] = count($lines);
                    }
                }
            } else {
                $errorMsg = "Bu belge kayıt dışı mal değil (U_AS2B2_NORE != 1)!";
                $debugInfo['error'] = 'U_AS2B2_NORE kontrolü başarısız: ' . var_export($dnU_AS2B2_NORE, true);
            }
        } else {
            $errorMsg = "PurchaseRequest ve PurchaseDeliveryNotes bulunamadı!";
            $debugInfo['error'] = 'PurchaseDeliveryNotes da bulunamadı: HTTP ' . ($dnData['status'] ?? 'NO STATUS');
        }
    } elseif (($requestData['status'] ?? 0) == 200 && isset($requestData['response'])) {
        $detailData = $requestData['response'];
        $requestDocEntry = $detailData['DocEntry'] ?? intval($requestNo);
        $debugInfo['requestDocEntry'] = $requestDocEntry;
        $debugInfo['detailData_keys'] = array_keys($detailData);
        
        $linesQuery = "PurchaseRequests({$requestDocEntry})/DocumentLines";
        $debugInfo['lines_query'] = $linesQuery;
        $linesData = $sap->get($linesQuery);
        
        $debugInfo['lines_response_status'] = $linesData['status'] ?? 'NO STATUS';
        $debugInfo['lines_has_response'] = isset($linesData['response']);
        
        if (($linesData['status'] ?? 0) == 200 && isset($linesData['response'])) {
            $resp = $linesData['response'];
            $allLines = [];
            if (isset($resp['value']) && is_array($resp['value'])) {
                $allLines = $resp['value'];
            } elseif (isset($resp['DocumentLines']) && is_array($resp['DocumentLines'])) {
                $allLines = $resp['DocumentLines'];
            }
            
            // OrderNo parametresi yoksa, sadece OrderNo'ya sahip olmayan kalemleri göster
            // OrderNo parametresi varsa, o OrderNo'ya ait kalemleri göster
            if (empty($orderNo)) {
                // OrderNo yok → Sadece OrderNo'ya sahip olmayan kalemleri göster
                // Tüm OrderNo'lara ait ItemCode'ları topla
                $allItemsWithOrderNo = [];
                foreach ($itemsWithOrderNo as $orderItemCodes) {
                    $allItemsWithOrderNo = array_merge($allItemsWithOrderNo, $orderItemCodes);
                }
                $allItemsWithOrderNo = array_unique($allItemsWithOrderNo);
                
                // PurchaseRequests'ten sadece OrderNo'ya sahip olmayan kalemleri filtrele
                $lines = [];
                foreach ($allLines as $line) {
                    $itemCode = $line['ItemCode'] ?? '';
                    if (!empty($itemCode) && !in_array($itemCode, $allItemsWithOrderNo)) {
                        $lines[] = $line;
                    }
                }
            } else {
                // OrderNo var → O OrderNo'ya ait kalemleri göster
                $orderNoInt = intval($orderNo);
                $orderItemCodes = $itemsWithOrderNo[$orderNoInt] ?? [];
                $lines = [];
                foreach ($allLines as $line) {
                    $itemCode = $line['ItemCode'] ?? '';
                    if (!empty($itemCode) && in_array($itemCode, $orderItemCodes)) {
                        $lines[] = $line;
                    }
                }
            }
        }
    } else {
        $errorMsg = "Talep detayları alınamadı!";
    }
    }
}

// Miktar formatı: 10.00 → 10, 10.5 → 10,5, 10.25 → 10,25
function formatQuantity($qty) {
    $num = floatval($qty);
    if ($num == 0) return '0';
    // Tam sayı ise küsurat gösterme
    if ($num == floor($num)) {
        return (string)intval($num);
    }
    // Küsurat varsa virgül ile göster
    return str_replace('.', ',', rtrim(rtrim(sprintf('%.2f', $num), '0'), ','));
}

function isReceivableStatus($status) {
    $s = trim((string)$status);
    return in_array($s, ['2', '3'], true);
}

function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// Alıcı Şube bilgisini çek
// Önce detailData'dan kontrol et, yoksa session'dan branch bilgisiyle çek
$toWarehouse = $detailData['ToWarehouse'] ?? '';
$aliciSube = $detailData['U_ASWHST'] ?? ''; // Alıcı Şube adı
$toWarehouseName = '';

// Eğer detailData'da ToWarehouse yoksa, session'dan branch bilgisiyle çek (DisTedarikSO.php'deki gibi)
if (empty($toWarehouse) && !empty($uAsOwnr) && !empty($branch)) {
    $toWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '2'";
    $toWarehouseQuery = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=" . urlencode($toWarehouseFilter);
    $toWarehouseData = $sap->get($toWarehouseQuery);
    $toWarehouses = $toWarehouseData['response']['value'] ?? [];
    if (!empty($toWarehouses)) {
        $toWarehouse = $toWarehouses[0]['WarehouseCode'] ?? '';
        $toWarehouseName = $toWarehouses[0]['WarehouseName'] ?? '';
    }
}

// Eğer hala WarehouseName yoksa, ayrı bir query ile çek
if (!empty($toWarehouse) && empty($toWarehouseName)) {
    $toWhsQuery = "Warehouses('{$toWarehouse}')?\$select=WarehouseCode,WarehouseName";
    $toWhsData = $sap->get($toWhsQuery);
    $toWarehouseName = $toWhsData['response']['WarehouseName'] ?? '';
}

// Alıcı Şube formatı: 200-KT-1 / Kadıköy Rıhtım Depo
$aliciSubeDisplay = $toWarehouse;
if (!empty($aliciSube)) {
    $aliciSubeDisplay = $toWarehouse . ' / ' . $aliciSube;
} elseif (!empty($toWarehouseName)) {
    $aliciSubeDisplay = $toWarehouse . ' / ' . $toWarehouseName;
} elseif (empty($toWarehouse)) {
    $aliciSubeDisplay = '-';
}

function getStatusText($status) {
    // Status null veya boş ise default olarak '1' (Onay bekleniyor) yap
    if (empty($status) || $status === 'null' || $status === '') {
        $status = '1';
    }
    $statusMap = [
        '0' => 'Sipariş yok',
        '1' => 'Onay bekleniyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal edildi'
    ];
    return $statusMap[(string)$status] ?? 'Onay bekleniyor';
}

function getStatusClass($status) {
    // Status null veya boş ise default olarak '1' (Onay bekleniyor) yap
    if (empty($status) || $status === 'null' || $status === '') {
        $status = '1';
    }
    $classMap = [
        '0' => 'status-unknown',
        '1' => 'status-pending',
        '2' => 'status-processing',
        '3' => 'status-shipped',
        '4' => 'status-completed',
        '5' => 'status-cancelled'
    ];
    return $classMap[(string)$status] ?? 'status-pending';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dış Tedarik Detay - MINOA</title>
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
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 24px;
}

.detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e5e7eb;
}

.detail-title h3 {
    font-size: 1.5rem;
    color: #2c3e50;
    font-weight: 400;
}

.detail-title h3 strong {
    font-weight: 600;
    color: #3b82f6;
}

.detail-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    padding: 24px;
    margin-bottom: 24px;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 2rem;
}

.detail-column {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.detail-item label {
    font-size: 13px;
    color: #1e3a8a;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    font-size: 15px;
    color: #2c3e50;
    font-weight: 500;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    color: #1e3a8a;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e5e7eb;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    table-layout: fixed;
}

.data-table thead {
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
}

.data-table th {
    padding: 16px 20px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    color: #1e3a8a;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
    width: 25%;
}

.data-table th:nth-child(3) {
    text-align: center;
}

.data-table td {
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    color: #374151;
    width: 25%;
}

.data-table td:nth-child(3) {
    text-align: center;
}

.data-table tbody tr {
    transition: background 0.15s ease;
}

.data-table tbody tr:hover {
    background: #f8fafc;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-processing {
    background: #dbeafe;
    color: #1e40af;
}

.status-shipped {
    background: #e0e7ff;
    color: #4338ca;
}

.status-completed {
    background: #d1fae5;
    color: #065f46;
}

.status-cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.status-unknown {
    background: #f3f4f6;
    color: #6b7280;
}

.btn {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
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

.header-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Dış Tedarik Detay</h2>
            <div class="header-actions">
                <?php if ($isPurchaseOrder): ?>
                    <?php if ($canReceive): ?>
                        <a href="DisTedarik-TeslimAl.php?requestNo=<?= urlencode($requestNo) ?>&orderNos=<?= urlencode($orderNo) ?>">
                            <button class="btn btn-primary">✓ Teslim Al</button>
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <?php
                    // Sadece tek siparişli taleplerde header'da teslim al butonu göster
                    $hasSingleOrder = count($allOrdersForRequest) === 1;
                    
                    if ($hasSingleOrder && !empty($allOrdersForRequest)) {
                        $singleOrder = $allOrdersForRequest[0];
                        $singleOrderNo = $singleOrder['OrderNo'] ?? null;
                        $singleOrderStatus = $singleOrder['Status'] ?? null;
                        // Status null veya boş ise default olarak '1' (Onay bekleniyor) yap
                        if (empty($singleOrderStatus) || $singleOrderStatus === 'null' || $singleOrderStatus === '') {
                            $singleOrderStatus = '1';
                        }
                        
                        if (!empty($singleOrderNo) && isReceivableStatus($singleOrderStatus)) {
                            // Tek sipariş için orderNos parametresi kullan (geriye dönük uyumluluk için orderNo da destekleniyor)
                    ?>
                        <a href="DisTedarik-TeslimAl.php?requestNo=<?= urlencode($requestNo) ?>&orderNos=<?= urlencode($singleOrderNo) ?>">
                            <button class="btn btn-primary">✓ Teslim Al</button>
                        </a>
                    <?php
                        }
                    }
                    ?>
                <?php endif; ?>
                <button class="btn btn-secondary" onclick="window.location.href='DisTedarik.php'">← Geri Dön</button>
            </div>
        </header>

        <div class="content-wrapper">
            <?php if ($errorMsg || !$detailData): ?>
                <div class="card" style="background: #fee2e2; border: 2px solid #ef4444;">
                    <p style="color: #991b1b; font-weight: 600;"><?= htmlspecialchars($errorMsg ?: 'Detay bilgileri alınamadı!') ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($debugInfo)): ?>
                <div class="card" style="background: #f0f9ff; border: 2px solid #0ea5e9; margin-bottom: 1.5rem;">
                    <h3 style="color: #0369a1; margin-bottom: 1rem; font-size: 1.1rem; padding: 0.5rem;">🔍 DEBUG BİLGİLERİ</h3>
                    <div style="background: white; padding: 1rem; border-radius: 0.5rem; font-family: 'Courier New', monospace; font-size: 0.85rem; margin: 0 0.5rem 0.5rem 0.5rem;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <tbody>
                                <?php foreach ($debugInfo as $key => $value): ?>
                                    <tr style="border-bottom: 1px solid #e5e7eb;">
                                        <td style="padding: 0.5rem; font-weight: 600; color: #0369a1; width: 200px; vertical-align: top;">
                                            <?= htmlspecialchars($key) ?>:
                                        </td>
                                        <td style="padding: 0.5rem; color: #1f2937; word-break: break-all;">
                                            <?php if (is_array($value) || is_object($value)): ?>
                                                <pre style="margin: 0; white-space: pre-wrap; background: #f9fafb; padding: 0.5rem; border-radius: 0.25rem; max-height: 300px; overflow-y: auto;"><?= htmlspecialchars(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                                            <?php else: ?>
                                                <?= htmlspecialchars(var_export($value, true)) ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($detailData): ?>
                <div class="detail-header">
                    <div class="detail-title">
                        <h3>Dış Tedarik Talebi: <strong><?= htmlspecialchars($requestNo) ?></strong></h3>
                    </div>
                </div>

                <div class="detail-card">
                    <div class="detail-grid">
                        <!-- Sol Sütun -->
                        <div class="detail-column">
                            <div class="detail-item">
                                <label>Talep No:</label>
                                <div class="detail-value"><?= htmlspecialchars($requestNo) ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Talep Tarihi:</label>
                                <div class="detail-value"><?= formatDate($detailData['DocDate'] ?? '') ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Teslimat Belge No:</label>
                                <div class="detail-value"><?= htmlspecialchars($detailData['U_ASB2B_NumAtCard'] ?? '-') ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Talep Özeti:</label>
                                <div class="detail-value"><?= htmlspecialchars($detailData['U_ASB2B_ORDSUM'] ?? '-') ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Talep Notu:</label>
                                <div class="detail-value"><?= htmlspecialchars($detailData['Comments'] ?? '-') ?></div>
                            </div>
                        </div>
                        
                        <!-- Sağ Sütun -->
                        <div class="detail-column">
                            <div class="detail-item">
                                <label>Tedarik No:</label>
                                <div class="detail-value">
                                    <?php
                                    $siparisNoDisplay = '-';
                                    if ($isPurchaseOrder) {
                                        // OrderNo parametresi varsa (Sevk edildi satırına tıklanmış)
                                        $siparisNoDisplay = htmlspecialchars($orderDocEntry ?? $orderNo ?? '-');
                                    } elseif (empty($orderNo)) {
                                        // OrderNo parametresi yoksa (Onay bekleniyor satırına tıklanmış) → Tedarik No boş
                                        $siparisNoDisplay = '-';
                                    } elseif (!empty($allOrdersForRequest)) {
                                        // OrderNo parametresi varsa ama $isPurchaseOrder false ise (nadir durum)
                                        // Belirtilen OrderNo'yu göster
                                        $siparisNoDisplay = htmlspecialchars($orderNo);
                                    }
                                    echo $siparisNoDisplay;
                                    ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <label>Tahmini Teslimat Tarihi:</label>
                                <div class="detail-value"><?= formatDate($detailData['DocDueDate'] ?? '') ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Talep Durumu:</label>
                                <div class="detail-value">
                                    <?php 
                                    if ($isPurchaseOrder) {
                                        // Status null veya boş ise default olarak '1' (Onay bekleniyor) yap
                                        $displayStatus = $orderStatus ?? '1';
                                        if (empty($displayStatus) || $displayStatus === 'null' || $displayStatus === '') {
                                            $displayStatus = '1';
                                        }
                                    ?>
                                        <span class="status-badge <?= getStatusClass($displayStatus) ?>"><?= getStatusText($displayStatus) ?></span>
                                    <?php 
                                    } else { 
                                    ?>
                                        <span class="status-badge status-pending">Onay bekleniyor</span>
                                    <?php 
                                    } 
                                    ?>
                                </div>
                            </div>
                          
                            <div class="detail-item">
                                <label>Alıcı Şube:</label>
                                <div class="detail-value"><?= htmlspecialchars($aliciSubeDisplay ?? '-') ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Teslimat Tarihi:</label>
                                <div class="detail-value">-</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <section class="card">
                    <div class="section-title">Talep Detayı</div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Kalem Numarası</th>
                                <th>Kalem Tanımı</th>
                                <th>Teslimat Miktarı</th>
                                <th>Tedarikçi Kodu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($lines)): ?>
                                <?php foreach ($lines as $lineIndex => $line): ?>
                                    <?php
                                    $itemCode = $line['ItemCode'] ?? '';
                                    $uomCode = $line['UoMCode'] ?? 'AD';
                                    $quantity = (float)($line['Quantity'] ?? 0);
                                    
                                    // Teslimat Miktarı: Şimdilik talep miktarını göster (teslim al işlemi yapıldıysa güncellenecek)
                                    $delivered = $quantity; // TODO: Teslim al işleminden gelen miktarı hesapla
                                    
                                    // Teslimat Miktarı formatı: "1 AD" (0 ise sadece "0")
                                    $deliveredFormatted = formatQuantity($delivered);
                                    if ($delivered > 0) {
                                        $deliveredDisplay = $deliveredFormatted . ' ' . htmlspecialchars($uomCode);
                                    } else {
                                        $deliveredDisplay = '0';
                                    }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($itemCode) ?></td>
                                        <td><?= htmlspecialchars($line['ItemDescription'] ?? '-') ?></td>
                                        <td><?= $deliveredDisplay ?></td>
                                        <td><?= htmlspecialchars($line['VendorNum'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 2rem; color: #9ca3af;">Satır bulunamadı.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>