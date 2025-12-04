<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece üretim kullanıcıları (RT veya CF) görebilsin (YE göremez)
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'RT' && $uAsOwnr !== 'CF') {
    header("Location: index.php");
    exit;
}

$absoluteEntry = $_GET['id'] ?? '';

if (empty($absoluteEntry)) {
    header("Location: UretimIsEmirleri.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

// Üretime Başla - AJAX PATCH request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_production') {
    header('Content-Type: application/json');
    
    $updatePayload = [
        'ProductionOrderStatus' => 'boposReleased'
    ];
    
    $result = $sap->patch("ProductionOrders({$absoluteEntry})", $updatePayload);
    
    if (($result['status'] ?? 0) == 200 || ($result['status'] ?? 0) == 204) {
        echo json_encode(['success' => true, 'message' => 'İş emri üretime başlatıldı!']);
    } else {
        $error = $result['response']['error']['message']['value'] ?? $result['response']['error']['message'] ?? 'Bilinmeyen hata';
        echo json_encode(['success' => false, 'message' => 'Hata: ' . $error]);
    }
    exit;
}

// Üretim Bitir - AJAX POST request (InventoryGenEntries + PATCH ProductionOrder)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finish_production') {
    header('Content-Type: application/json');
    
    $quantity = floatval($_POST['quantity'] ?? 0);
    $warehouseCode = trim($_POST['warehouseCode'] ?? '');
    
    if ($quantity <= 0) {
        echo json_encode(['success' => false, 'message' => 'Üretim miktarı 0\'dan büyük olmalıdır.']);
        exit;
    }
    
    if (empty($warehouseCode)) {
        echo json_encode(['success' => false, 'message' => 'Depo bilgisi bulunamadı.']);
        exit;
    }
    
    // 1. Adım: InventoryGenEntries POST
    // BaseEntry ve BaseType kullanıldığında SAP otomatik olarak ProductionOrder'dan item bilgisini alır
    // ItemCode eklememize gerek yok, çünkü BaseEntry zaten ProductionOrder'ı referans ediyor
    $inventoryPayload = [
        'DocumentLines' => [
            [
                'BaseEntry' => intval($absoluteEntry),
                'BaseType' => 202, // Production Order için standart tip
                'Quantity' => $quantity,
                'WarehouseCode' => $warehouseCode
            ]
        ]
    ];
    
    $inventoryResult = $sap->post('InventoryGenEntries', $inventoryPayload);
    
    if (($inventoryResult['status'] ?? 0) != 200 && ($inventoryResult['status'] ?? 0) != 201) {
        $error = $inventoryResult['response']['error']['message']['value'] ?? $inventoryResult['response']['error']['message'] ?? 'Bilinmeyen hata';
        echo json_encode(['success' => false, 'message' => 'Üretim kaydı oluşturulamadı: ' . $error]);
        exit;
    }
    
    // 2. Adım: Başarılı ise ProductionOrder status'unu boposClosed yap
    $updatePayload = [
        'ProductionOrderStatus' => 'boposClosed'
    ];
    
    $updateResult = $sap->patch("ProductionOrders({$absoluteEntry})", $updatePayload);
    
    if (($updateResult['status'] ?? 0) == 200 || ($updateResult['status'] ?? 0) == 204) {
        echo json_encode(['success' => true, 'message' => 'Üretim başarıyla tamamlandı ve iş emri kapatıldı!']);
    } else {
        // InventoryGenEntries başarılı ama status güncellenemedi
        $error = $updateResult['response']['error']['message']['value'] ?? $updateResult['response']['error']['message'] ?? 'Bilinmeyen hata';
        echo json_encode(['success' => false, 'message' => 'Üretim kaydı oluşturuldu ancak iş emri durumu güncellenemedi: ' . $error]);
    }
    exit;
}

// ProductionOrder detayını çek - ProductionOrderStatus, Warehouse ve ItemNo alanlarını da ekliyoruz
$productionOrderQuery = "ProductionOrders({$absoluteEntry})?\$select=AbsoluteEntry,ItemNo,ProductDescription,PlannedQuantity,InventoryUOM,ProductionOrderStatus,Warehouse";
$productionOrderData = $sap->get($productionOrderQuery);

$orderData = null;
$errorMsg = '';

// Transferler-Detay.php'deki gibi direkt response'u al
if (($productionOrderData['status'] ?? 0) == 200) {
    $orderData = $productionOrderData['response'] ?? null;
    
    // Eğer response yoksa, direkt data'yı kontrol et
    if (!$orderData && isset($productionOrderData['AbsoluteEntry'])) {
        $orderData = $productionOrderData;
    }
    
    if (!$orderData) {
        $errorMsg = 'İş emri verisi alınamadı. Response yapısı beklenmedik.';
    }
} else {
    $status = $productionOrderData['status'] ?? 'Bilinmiyor';
    $errorResponse = $productionOrderData['response'] ?? [];
    $error = $errorResponse['error']['message']['value'] ?? $errorResponse['error']['message'] ?? $productionOrderData['error']['message'] ?? 'Bilinmeyen hata';
    $errorMsg = "SAP hatası (Status: {$status}): {$error}";
}

// ProductionOrderLines'ı ProductionOrders({AbsEntry})/ProductionOrderLines'tan çek
// Custom view yerine direkt ProductionOrderLines kullanıyoruz (BOM'dan değil, order bazlı)
$orderLines = [];
$linesErrorMsg = '';
$linesDebugInfo = [
    'absoluteEntry' => $absoluteEntry,
    'query' => '',
    'total_received' => 0,
    'filtered_count' => 0
];

if (!empty($absoluteEntry)) {
    // ProductionOrderLines'ı çek - önce $expand ile deneyelim
    // Eğer bu çalışmazsa, direkt ProductionOrderLines endpoint'ini deneyelim
    $productionOrderLinesQuery1 = "ProductionOrders({$absoluteEntry})?\$expand=ProductionOrderLines";
    $linesDebugInfo['query_expand'] = $productionOrderLinesQuery1;
    $productionOrderLinesData1 = $sap->get($productionOrderLinesQuery1);
    
    $linesList = [];
    $productionOrderLinesData = null;
    
    // $expand ile deneme
    if (($productionOrderLinesData1['status'] ?? 0) == 200) {
        $orderDataWithLines = $productionOrderLinesData1['response'] ?? $productionOrderLinesData1;
        $linesList = $orderDataWithLines['ProductionOrderLines'] ?? [];
        $linesDebugInfo['method'] = 'expand';
        $linesDebugInfo['query'] = $productionOrderLinesQuery1;
        $productionOrderLinesData = $productionOrderLinesData1;
    }
    
    // Eğer $expand ile boş geldiyse, direkt endpoint'i dene
    if (empty($linesList)) {
        $productionOrderLinesQuery2 = "ProductionOrders({$absoluteEntry})/ProductionOrderLines";
        $linesDebugInfo['query_direct'] = $productionOrderLinesQuery2;
        $productionOrderLinesData2 = $sap->get($productionOrderLinesQuery2);
        
        if (($productionOrderLinesData2['status'] ?? 0) == 200) {
            // Response yapısını kontrol et - UretimIsEmriSO.php'deki gibi value array'ini kontrol et
            $response2 = $productionOrderLinesData2['response'] ?? $productionOrderLinesData2;
            
            // Önce value array'ini kontrol et (OData standard format)
            if (isset($response2['value']) && is_array($response2['value']) && !empty($response2['value'])) {
                $linesList = $response2['value'];
            }
            // Eğer value boş array ise ama response'un kendisi indexed array ise (0, 1, 2...)
            elseif (is_array($response2) && isset($response2[0]) && is_array($response2[0]) && isset($response2[0]['DocumentAbsoluteEntry'])) {
                // Response'un kendisi indexed array ise (value wrapper yok, direkt array)
                $linesList = $response2;
            }
            // Eğer response içinde ProductionOrderLines key'i varsa
            elseif (isset($response2['ProductionOrderLines']) && is_array($response2['ProductionOrderLines']) && !empty($response2['ProductionOrderLines'])) {
                $linesList = $response2['ProductionOrderLines'];
            }
            // Eğer value yoksa, direkt response'un kendisi indexed array olabilir
            elseif (is_array($response2) && isset($response2[0]) && is_array($response2[0]) && !isset($response2['@odata.context']) && !isset($response2['status'])) {
                // Response'un kendisi indexed array ise (value wrapper yok)
                $linesList = $response2;
            }
            // Son çare: response'un kendisini array olarak kontrol et
            else {
                $linesList = [];
            }
            
            $linesDebugInfo['method'] = 'direct';
            $linesDebugInfo['query'] = $productionOrderLinesQuery2;
            $productionOrderLinesData = $productionOrderLinesData2;
            $linesDebugInfo['response_structure'] = [
                'has_value' => isset($response2['value']),
                'value_count' => isset($response2['value']) && is_array($response2['value']) ? count($response2['value']) : 0,
                'has_ProductionOrderLines' => isset($response2['ProductionOrderLines']),
                'is_array' => is_array($response2),
                'is_indexed_array' => is_array($response2) && isset($response2[0]),
                'first_element_keys' => is_array($response2) && isset($response2[0]) ? array_keys($response2[0]) : [],
                'response_keys' => is_array($response2) ? array_keys($response2) : [],
                'linesList_count_after_parse' => is_array($linesList) ? count($linesList) : 0
            ];
        } else {
            $linesStatus = $productionOrderLinesData2['status'] ?? 'Bilinmiyor';
            $linesErrorResponse = $productionOrderLinesData2['response'] ?? [];
            $linesError = $linesErrorResponse['error']['message']['value'] ?? $linesErrorResponse['error']['message'] ?? $productionOrderLinesData2['error']['message'] ?? 'Bilinmeyen hata';
            $linesErrorMsg = "ProductionOrderLines hatası (Status: {$linesStatus}): {$linesError}";
        }
    }

    // Debug: linesList'in durumunu kontrol et
    $linesDebugInfo['linesList_before_processing'] = [
        'is_array' => is_array($linesList),
        'count' => is_array($linesList) ? count($linesList) : 0,
        'empty' => empty($linesList),
        'first_element_keys' => is_array($linesList) && isset($linesList[0]) ? array_keys($linesList[0]) : []
    ];
    
    // Eğer linesList hala boşsa ama debug'da veri varsa, response yapısını tekrar kontrol et
    // Debug panelindeki $linesListForDebug parse mantığını kullan (satır 650-638)
    if (empty($linesList) && isset($productionOrderLinesData)) {
        $responseForDebug = $productionOrderLinesData['response'] ?? $productionOrderLinesData;
        
        // Debug panelindeki parse mantığını kullan (tam olarak aynı mantık)
        if (isset($responseForDebug['ProductionOrderLines']) && is_array($responseForDebug['ProductionOrderLines']) && !empty($responseForDebug['ProductionOrderLines'])) {
            $linesList = $responseForDebug['ProductionOrderLines'];
            $linesDebugInfo['note'] = "Response yapısı ProductionOrderLines key'inden parse edildi (fallback).";
        } elseif (!empty($responseForDebug['value']) && is_array($responseForDebug['value']) && !empty($responseForDebug['value'])) {
            $linesList = $responseForDebug['value'];
            $linesDebugInfo['note'] = "Response yapısı value array'inden parse edildi (fallback).";
        } elseif (!empty($responseForDebug) && is_array($responseForDebug) && isset($responseForDebug[0]) && is_array($responseForDebug[0])) {
            // Eğer response direkt indexed array ise (DocumentAbsoluteEntry veya ItemNo ile kontrol et)
            if (isset($responseForDebug[0]['DocumentAbsoluteEntry']) || isset($responseForDebug[0]['ItemNo'])) {
                $linesList = $responseForDebug;
                $linesDebugInfo['note'] = "Response yapısı indexed array olarak parse edildi (fallback).";
            }
        } elseif (!empty($productionOrderLinesData['value']) && is_array($productionOrderLinesData['value']) && !empty($productionOrderLinesData['value'])) {
            // Eğer response'un kendisi value içeriyorsa
            $linesList = $productionOrderLinesData['value'];
            $linesDebugInfo['note'] = "Response yapısı productionOrderLinesData['value']'den parse edildi (fallback).";
        }
    }
    
    if (is_array($linesList) && !empty($linesList)) {
        $totalLines = count($linesList);
        $linesDebugInfo['total_received'] = $totalLines;
        
        // Tüm ItemCode'ları topla, tek seferde Items endpoint'inden birim bilgilerini çek
        $itemCodesForUom = [];
        foreach ($linesList as $line) {
            if (is_array($line)) {
                $itemCode = $line['ItemNo'] ?? $line['ItemCode'] ?? '';
                if (!empty($itemCode)) {
                    $itemCodesForUom[] = $itemCode;
                }
            }
        }
        
        // Items endpoint'inden birim bilgilerini toplu çek
        $uomMap = [];
        if (!empty($itemCodesForUom)) {
            $uniqueItemCodes = array_unique($itemCodesForUom);
            foreach ($uniqueItemCodes as $itemCode) {
                $itemQuery = "Items('{$itemCode}')?\$select=ItemCode,InventoryUOM";
                $itemData = $sap->get($itemQuery);
                if (($itemData['status'] ?? 0) == 200) {
                    $itemInfo = $itemData['response'] ?? $itemData;
                    $uomMap[$itemCode] = $itemInfo['InventoryUOM'] ?? '';
                }
            }
        }
        
        $siraCounter = 0;
        foreach ($linesList as $index => $line) {
            if (!is_array($line)) {
                continue; // Geçersiz satır
            }
            
            $siraCounter++;
            
            // ProductionOrderLines'tan direkt alanlar
            // Debug'dan görüldüğü gibi: ItemNo, BaseQuantity, PlannedQuantity, ItemName alanları var
            $itemCode = $line['ItemNo'] ?? $line['ItemCode'] ?? '';
            $itemName = $line['ItemName'] ?? $line['ItemDescription'] ?? '';
            $baseQty = floatval($line['BaseQuantity'] ?? $line['BaseQty'] ?? 0); // Miktar: 1 birim için
            $plannedQty = floatval($line['PlannedQuantity'] ?? $line['PlannedQty'] ?? 0); // Planlanan Miktar (Toplam)
            
            // Birim bilgisi - Önce ProductionOrderLines'tan, yoksa Items endpoint'inden çekilen map'ten
            $uom = $line['InventoryUOM'] ?? '';
            if (empty($uom)) {
                // Items endpoint'inden çekilen map'ten al
                $uom = $uomMap[$itemCode] ?? '';
            }
            
            if (!empty($itemCode)) {
                $orderLines[] = [
                    'sira' => $siraCounter,
                    'itemCode' => $itemCode,
                    'itemName' => $itemName,
                    'baseQty' => $baseQty, // Miktar = BaseQuantity
                    'planlananMiktarToplam' => $plannedQty, // Planlanan Miktar (Toplam) = PlannedQuantity
                    'uom' => $uom
                ];
            }
        }
        $linesDebugInfo['filtered_count'] = count($orderLines);
    } elseif (empty($linesList) && ($productionOrderLinesData['status'] ?? 0) == 200) {
        // Response 200 ama boş - ProductionOrderLines oluşturulmamış olabilir
        $linesErrorMsg = "ProductionOrderLines bulunamadı. ProductionOrder oluşturulduğunda otomatik olarak ProductionOrderLines oluşturulmamış olabilir.";
        $linesDebugInfo['note'] = "Response 200 ama ProductionOrderLines boş. ProductionOrder oluşturulurken ProductionOrderLines otomatik oluşturulmamış olabilir.";
    }
} else {
    $linesErrorMsg = "AbsoluteEntry bulunamadı, kalemler çekilemedi.";
}

// Status mapping - ProductionOrderStatus enum değerleri
function getStatusText($status) {
    $statusMap = [
        'boposPlanned' => 'Planlandı',
        'boposReleased' => 'Onaylandı',
        'boposClosed' => 'Kapalı',
        'boposCancelled' => 'İptal Edildi'
    ];
    return $statusMap[$status] ?? 'Bilinmiyor';
}

function getStatusClass($status) {
    $classMap = [
        'boposPlanned' => 'status-planlandi',
        'boposReleased' => 'status-uretimde', // Onaylandı = Üretimde olarak göster
        'boposClosed' => 'status-tamamlandi', // Kapalı = Tamamlandı
        'boposCancelled' => 'status-iptal'
    ];
    return $classMap[$status] ?? 'status-unknown';
}

function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y H:i', strtotime($date));
    }
    return date('d.m.Y H:i', strtotime($date));
}

// ProductionOrderStatus alanını al
$status = $orderData['ProductionOrderStatus'] ?? 'boposPlanned'; // Varsayılan: Planlandı
$statusText = getStatusText($status);
$statusClass = getStatusClass($status);

// Debug bilgileri
$debugInfo = [
    'absoluteEntry' => $absoluteEntry,
    'query' => $productionOrderQuery,
    'response_status' => $productionOrderData['status'] ?? 'N/A',
    'has_response' => isset($productionOrderData['response']),
    'has_orderData' => !empty($orderData),
    'orderData_keys' => $orderData ? array_keys($orderData) : [],
    'raw_response' => $productionOrderData,
    'itemCode' => $orderData['ItemNo'] ?? 'N/A',
    'lines_debug' => $linesDebugInfo,
    'lines_query' => $linesDebugInfo['query'] ?? 'N/A',
    'lines_status' => $productionOrderLinesData['status'] ?? 'N/A',
    'lines_count' => count($orderLines),
    'lines_error_msg' => $linesErrorMsg,
    'lines_response_sample' => isset($linesList) && !empty($linesList) ? $linesList[0] : []
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İş Emri Detayı - MINOA</title> 
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
    padding: 24px;
    border-bottom: 1px solid #e5e7eb;
}

.card-header h3 {
    color: #1e40af;
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 20px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.info-label {
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    font-size: 16px;
    color: #1f2937;
    font-weight: 500;
}

.card-body {
    padding: 24px;
}

.card-body h4 {
    color: #1e40af;
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 20px;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

thead {
    background: #f8fafc;
}

thead th {
    padding: 16px 20px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    white-space: nowrap;
}

tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.15s ease;
}

tbody tr:hover {
    background: #f9fafb;
}

tbody td {
    padding: 16px 20px;
    color: #4b5563;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
}

.status-planlandi {
    background: #eff6ff;
    color: #1d4ed8;
}

.status-uretimde {
    background: #fef3c7;
    color: #b45309;
}

.status-tamamlandi {
    background: #dcfce7;
    color: #15803d;
}

.status-iptal {
    background: #fee2e2;
    color: #991b1b;
}

.btn {
    padding: 0.625rem 1.25rem;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #f0f9ff;
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

.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
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

    .info-grid {
        grid-template-columns: 1fr;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2>İş Emri Detayı</h2>
            <div style="display: flex; gap: 12px; align-items: center;">
                <?php if ($status === 'boposPlanned'): ?>
                <button id="startProductionBtn" class="btn btn-primary" onclick="startProduction()">
                    🚀 Üretime Başla
                </button>
                <?php endif; ?>
                <?php if ($status === 'boposReleased'): ?>
                <button id="finishProductionBtn" class="btn btn-primary" onclick="openFinishProductionModal()">
                    ✅ Üretim Bitir
                </button>
                <?php endif; ?>
                <a href="UretimIsEmirleri.php" class="btn btn-secondary">← Geri</a>
            </div>
        </div>

        <div class="content-wrapper">
            <?php if (!$orderData): ?>
                <div class="card">
                    <div class="card-body">
                        <p style="color: #ef4444; font-weight: 600;">İş emri bulunamadı!</p>
                        <?php if (!empty($errorMsg)): ?>
                            <p style="color: #991b1b; margin-top: 10px; font-size: 14px;"><?= htmlspecialchars($errorMsg) ?></p>
                        <?php endif; ?>
                        <p style="color: #6b7280; margin-top: 10px; font-size: 13px;">İş Emri No: <?= htmlspecialchars($absoluteEntry) ?></p>
                    </div>
                </div>
            <?php else: ?>
                <!-- İş Emri Bilgileri -->
                <div class="card">
                    <div class="card-header">
                        <h3>İş Emri Bilgileri</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-label">İş Emri Numarası</span>
                                <span class="info-value"><?= htmlspecialchars($orderData['AbsoluteEntry'] ?? '') ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Ürün Numarası</span>
                                <span class="info-value"><?= htmlspecialchars($orderData['ItemNo'] ?? '') ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Ürün Tanımı</span>
                                <span class="info-value"><?= htmlspecialchars($orderData['ProductDescription'] ?? '') ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Planlanan Miktar</span>
                                <span class="info-value"><?= number_format($orderData['PlannedQuantity'] ?? 0, 2, ',', '.') ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Birim</span>
                                <span class="info-value"><?= htmlspecialchars($orderData['InventoryUOM'] ?? '') ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Durum</span>
                                <span class="info-value">
                                    <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- İş Emri Kalemleri -->
                <div class="card">
                    <div class="card-body">
                        <h4>İş Emri Kalemleri</h4>
                        <?php if (!empty($linesErrorMsg)): ?>
                            <p style="color: #ef4444; padding: 20px; background: #fee2e2; border-radius: 8px; margin-bottom: 16px;">
                                <strong>Hata:</strong> <?= htmlspecialchars($linesErrorMsg) ?>
                            </p>
                        <?php endif; ?>
                        
                        <!-- Debug Bilgileri (ProductionOrderLines) -->
                        <div style="background: #fef3c7; border: 2px solid #fbbf24; border-radius: 8px; padding: 16px; margin-bottom: 20px;">
                            <h4 style="color: #92400e; margin-bottom: 12px;">🔍 ProductionOrderLines Debug Bilgileri:</h4>
                            <div style="background: white; padding: 12px; border-radius: 6px; font-family: monospace; font-size: 12px; overflow-x: auto;">
                                <div style="margin-bottom: 8px;"><strong>AbsoluteEntry:</strong> <?= htmlspecialchars($absoluteEntry ?? 'N/A') ?></div>
                                <div style="margin-bottom: 8px;"><strong>Method:</strong> <?= htmlspecialchars($linesDebugInfo['method'] ?? 'N/A') ?></div>
                                <div style="margin-bottom: 8px;"><strong>Query (Expand):</strong> <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; word-break: break-all;"><?= htmlspecialchars($linesDebugInfo['query_expand'] ?? 'N/A') ?></pre></div>
                                <div style="margin-bottom: 8px;"><strong>Query (Direct):</strong> <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; word-break: break-all;"><?= htmlspecialchars($linesDebugInfo['query_direct'] ?? 'N/A') ?></pre></div>
                                <div style="margin-bottom: 8px;"><strong>Query (Used):</strong> <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; word-break: break-all;"><?= htmlspecialchars($linesDebugInfo['query'] ?? 'N/A') ?></pre></div>
                                <div style="margin-bottom: 8px;"><strong>Response Status:</strong> <span style="color: <?= ($productionOrderLinesData['status'] ?? 0) == 200 ? '#16a34a' : '#ef4444' ?>"><?= htmlspecialchars($productionOrderLinesData['status'] ?? 'N/A') ?></span></div>
                                <?php if (!empty($linesDebugInfo['note'])): ?>
                                <div style="margin-bottom: 8px; color: #ef4444;"><strong>Note:</strong> <?= htmlspecialchars($linesDebugInfo['note']) ?></div>
                                <?php endif; ?>
                                <div style="margin-bottom: 8px;"><strong>Toplam Gelen Satır:</strong> <?= htmlspecialchars($linesDebugInfo['total_received'] ?? 0) ?></div>
                                <div style="margin-bottom: 8px;"><strong>Filtrelenmiş Satır:</strong> <?= htmlspecialchars($linesDebugInfo['filtered_count'] ?? 0) ?></div>
                                <div style="margin-bottom: 8px;"><strong>OrderLines Count:</strong> <?= count($orderLines) ?></div>
                                <?php if (!empty($linesDebugInfo['linesList_before_processing'])): ?>
                                <div style="margin-bottom: 8px;"><strong>LinesList Before Processing:</strong>
                                    <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap;"><?= htmlspecialchars(print_r($linesDebugInfo['linesList_before_processing'], true)) ?></pre>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($linesDebugInfo['response_structure'])): ?>
                                <div style="margin-bottom: 8px;"><strong>Response Structure:</strong>
                                    <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap;"><?= htmlspecialchars(print_r($linesDebugInfo['response_structure'], true)) ?></pre>
                                </div>
                                <?php endif; ?>
                                
                                <?php 
                                // Response'dan linesList'i al - hem expand hem direct method için
                                $linesListForDebug = [];
                                if (isset($productionOrderLinesData['response']['ProductionOrderLines']) && is_array($productionOrderLinesData['response']['ProductionOrderLines'])) {
                                    $linesListForDebug = $productionOrderLinesData['response']['ProductionOrderLines'];
                                } elseif (!empty($productionOrderLinesData['response']['value'])) {
                                    $linesListForDebug = $productionOrderLinesData['response']['value'];
                                } elseif (!empty($productionOrderLinesData['value'])) {
                                    $linesListForDebug = $productionOrderLinesData['value'];
                                } elseif (!empty($productionOrderLinesData['ProductionOrderLines'])) {
                                    $linesListForDebug = $productionOrderLinesData['ProductionOrderLines'];
                                }
                                ?>
                                <?php if (!empty($linesListForDebug)): ?>
                                    <?php $linesList = $linesListForDebug; ?>
                                    <div style="margin-bottom: 8px;"><strong>Response Sample (İlk Satır):</strong> 
                                        <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; max-height: 200px; overflow-y: auto;"><?= htmlspecialchars(print_r($linesList[0] ?? [], true)) ?></pre>
                                    </div>
                                    <div style="margin-bottom: 8px;"><strong>Tüm Satırlar (Özet):</strong>
                                        <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; max-height: 200px; overflow-y: auto;"><?php
                                        $summary = [];
                                        foreach ($linesList as $idx => $line) {
                                            $summary[] = [
                                                'LineNum' => $line['LineNum'] ?? $line['LineNumber'] ?? 'N/A',
                                                'ItemNo' => $line['ItemNo'] ?? $line['ItemCode'] ?? 'N/A',
                                                'ItemDescription' => $line['ItemDescription'] ?? $line['ItemName'] ?? 'N/A',
                                                'BaseQuantity' => $line['BaseQuantity'] ?? $line['BaseQty'] ?? 'N/A',
                                                'PlannedQuantity' => $line['PlannedQuantity'] ?? $line['PlannedQty'] ?? 'N/A',
                                                'InventoryUOM' => $line['InventoryUOM'] ?? $line['UomCode'] ?? 'N/A',
                                                'ALL_KEYS' => array_keys($line) // Tüm alanları göster
                                            ];
                                        }
                                        echo htmlspecialchars(print_r($summary, true));
                                        ?></pre>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-bottom: 8px; color: #ef4444;"><strong>Response:</strong> Boş veya hata var</div>
                                    <div style="margin-bottom: 8px;"><strong>Full Response (Raw - Tüm Yapı):</strong> 
                                        <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; max-height: 400px; overflow-y: auto;"><?= htmlspecialchars(print_r($productionOrderLinesData ?? [], true)) ?></pre>
                                    </div>
                                    <?php if (isset($productionOrderLinesData['response']['error'])): ?>
                                        <div style="margin-bottom: 8px; color: #ef4444;"><strong>Error:</strong> 
                                            <pre style="background: #fee2e2; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap;"><?= htmlspecialchars(json_encode($productionOrderLinesData['response']['error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <div style="margin-bottom: 8px;"><strong>OrderLines Array (İşlenmiş):</strong>
                                    <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; max-height: 200px; overflow-y: auto;"><?= htmlspecialchars(print_r($orderLines, true)) ?></pre>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (empty($orderLines)): ?>
                            <p style="color: #ef4444; text-align: center; padding: 40px; background: #fee2e2; border-radius: 8px;">
                                <strong>❌ Kalem bulunamadı</strong><br>
                                <small style="color: #991b1b;">Yukarıdaki debug bilgilerini kontrol edin.</small>
                            </p>
                        <?php else: ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Sıra</th>
                                            <th>Malzeme Kodu</th>
                                            <th>Malzeme Adı</th>
                                            <th>Miktar</th>
                                            <th>Planlanan Miktar (Toplam)</th>
                                            <th>Birim</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orderLines as $line): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($line['sira']) ?></td>
                                            <td><?= htmlspecialchars($line['itemCode']) ?></td>
                                            <td><?= htmlspecialchars($line['itemName']) ?></td>
                                            <td><?= number_format($line['baseQty'] ?? 0, 2, ',', '.') ?></td>
                                            <td><?= number_format($line['planlananMiktarToplam'] ?? 0, 2, ',', '.') ?></td>
                                            <td><?= htmlspecialchars($line['uom']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Debug Panel -->
            <div class="card" style="background: #fef3c7; border: 2px solid #f59e0b; margin-top: 24px;">
                <div class="card-header">
                    <h3 style="color: #92400e;">🔍 Debug Bilgileri</h3>
                </div>
                <div class="card-body">
                    <div style="background: white; padding: 16px; border-radius: 8px; font-family: monospace; font-size: 12px; overflow-x: auto;">
                        <div style="margin-bottom: 12px;">
                            <strong>AbsoluteEntry:</strong> <?= htmlspecialchars($debugInfo['absoluteEntry']) ?>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <strong>Query:</strong> <?= htmlspecialchars($debugInfo['query']) ?>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <strong>Response Status:</strong> <?= htmlspecialchars($debugInfo['response_status']) ?>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <strong>Has Response:</strong> <?= $debugInfo['has_response'] ? 'Evet' : 'Hayır' ?>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <strong>Has OrderData:</strong> <?= $debugInfo['has_orderData'] ? 'Evet' : 'Hayır' ?>
                        </div>
                        <?php if ($debugInfo['has_orderData']): ?>
                        <div style="margin-bottom: 12px;">
                            <strong>OrderData Keys:</strong> 
                            <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px;"><?= htmlspecialchars(print_r($debugInfo['orderData_keys'], true)) ?></pre>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <strong>OrderData (Full):</strong>
                            <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; max-height: 300px; overflow-y: auto;"><?= htmlspecialchars(print_r($orderData, true)) ?></pre>
                        </div>
                        <?php endif; ?>
                        <div style="margin-bottom: 12px;">
                            <strong>Raw Response:</strong>
                            <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; max-height: 400px; overflow-y: auto;"><?= htmlspecialchars(print_r($debugInfo['raw_response'], true)) ?></pre>
                        </div>
                        
                        <!-- ProductionOrderLines Debug Info -->
                        <div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid #f59e0b;">
                            <h4 style="color: #92400e; margin-bottom: 16px;">🔍 ProductionOrderLines Debug</h4>
                            
                            <div style="margin-bottom: 12px;">
                                <strong>AbsoluteEntry:</strong> 
                                <span style="background: #fef3c7; padding: 4px 8px; border-radius: 4px; font-weight: 600;">
                                    <?= htmlspecialchars($debugInfo['lines_debug']['absoluteEntry'] ?? 'N/A') ?>
                                </span>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <strong>Query:</strong> 
                                <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; word-break: break-all;"><?= htmlspecialchars($debugInfo['lines_query'] ?? 'N/A') ?></pre>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <strong>Response Status:</strong> 
                                <span style="color: <?= ($debugInfo['lines_status'] ?? 0) == 200 ? '#16a34a' : '#ef4444' ?>">
                                    <?= htmlspecialchars($debugInfo['lines_status'] ?? 'N/A') ?>
                                </span>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <strong>Toplam Gelen Satır:</strong> <?= htmlspecialchars($debugInfo['lines_debug']['total_received'] ?? 0) ?>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <strong>Filtrelenmiş Satır:</strong> <?= htmlspecialchars($debugInfo['lines_debug']['filtered_count'] ?? 0) ?>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <strong>OrderLines Count:</strong> <?= htmlspecialchars($debugInfo['lines_count'] ?? 0) ?>
                            </div>
                            <?php if (!empty($debugInfo['lines_error_msg'])): ?>
                            <div style="margin-bottom: 12px; color: #ef4444;">
                                <strong>Error:</strong> <?= htmlspecialchars($debugInfo['lines_error_msg']) ?>
                            </div>
                            <?php endif; ?>
                            
                            <div style="margin-bottom: 12px;">
                                <strong>Filter (Raw):</strong> 
                                <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px;"><?= htmlspecialchars($debugInfo['lines_debug']['filter_raw'] ?? 'N/A') ?></pre>
                            </div>
                            
                            <div style="margin-bottom: 12px;">
                                <strong>Filter (URL Encoded):</strong> 
                                <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; word-break: break-all;"><?= htmlspecialchars($debugInfo['lines_debug']['filter_encoded'] ?? 'N/A') ?></pre>
                            </div>
                            
                            <div style="margin-bottom: 12px;">
                                <strong>Full Query:</strong> 
                                <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; word-break: break-all;"><?= htmlspecialchars($debugInfo['lines_debug']['query'] ?? 'N/A') ?></pre>
                            </div>
                            
                            <div style="margin-bottom: 12px;">
                                <strong>Response Status:</strong> 
                                <span style="background: <?= ($debugInfo['lines_status'] ?? 0) == 200 ? '#dcfce7' : '#fee2e2' ?>; padding: 4px 8px; border-radius: 4px; font-weight: 600;">
                                    <?= htmlspecialchars($debugInfo['lines_status'] ?? 'N/A') ?>
                                </span>
                            </div>
                            
                            <div style="margin-bottom: 12px;">
                                <strong>Toplam Gelen Satır:</strong> 
                                <span style="background: #fef3c7; padding: 4px 8px; border-radius: 4px; font-weight: 600;">
                                    <?= htmlspecialchars($debugInfo['lines_debug']['total_received'] ?? 0) ?>
                                </span>
                            </div>
                            
                            <div style="margin-bottom: 12px;">
                                <strong>Filtrelenmiş Satır (ProductCode='<?= htmlspecialchars($debugInfo['lines_debug']['itemCode'] ?? '') ?>'):</strong> 
                                <span style="background: #dcfce7; padding: 4px 8px; border-radius: 4px; font-weight: 600;">
                                    <?= htmlspecialchars($debugInfo['lines_debug']['filtered_count'] ?? 0) ?>
                                </span>
                            </div>
                            
                            <?php if (!empty($debugInfo['lines_debug']['unique_productCodes'])): ?>
                            <div style="margin-bottom: 12px;">
                                <strong>Benzersiz ProductCode Değerleri (Tüm Satırlarda):</strong>
                                <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px;"><?= htmlspecialchars(print_r($debugInfo['lines_debug']['unique_productCodes'], true)) ?></pre>
                                <p style="font-size: 11px; color: #6b7280; margin-top: 4px;">
                                    Eğer birden fazla ProductCode varsa, SAP filtresi çalışmıyor demektir.
                                </p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($debugInfo['lines_debug']['productCode_counts'])): ?>
                            <div style="margin-bottom: 12px;">
                                <strong>ProductCode Sayıları (Her ProductCode'dan Kaç Satır Var):</strong>
                                <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px;"><?= htmlspecialchars(print_r($debugInfo['lines_debug']['productCode_counts'], true)) ?></pre>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($debugInfo['lines_debug']['sample_productCodes'])): ?>
                            <div style="margin-bottom: 12px;">
                                <strong>Örnek ProductCode Değerleri (İlk 10 Satır):</strong>
                                <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px;"><?= htmlspecialchars(print_r($debugInfo['lines_debug']['sample_productCodes'], true)) ?></pre>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($debugInfo['lines_error_msg'])): ?>
                            <div style="margin-bottom: 12px; padding: 12px; background: #fee2e2; border-radius: 6px; border-left: 4px solid #ef4444;">
                                <strong style="color: #991b1b;">⚠️ Uyarı:</strong>
                                <span style="color: #991b1b;"><?= htmlspecialchars($debugInfo['lines_error_msg']) ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($debugInfo['lines_response_sample'])): ?>
                            <div style="margin-bottom: 12px;">
                                <strong>Response Sample (İlk Satır - Tüm Alanlar):</strong>
                                <pre style="background: #f3f4f6; padding: 8px; border-radius: 4px; margin-top: 4px; max-height: 300px; overflow-y: auto;"><?= htmlspecialchars(print_r($debugInfo['lines_response_sample'], true)) ?></pre>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function startProduction() {
            if (!confirm('İş emrini üretime başlatmak istediğinizden emin misiniz?')) {
                return;
            }

            const btn = document.getElementById('startProductionBtn');
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'İşleniyor...';

            const formData = new FormData();
            formData.append('action', 'start_production');

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    // Sayfayı yenile
                    window.location.reload();
                } else {
                    alert('❌ ' + data.message);
                    btn.disabled = false;
                    btn.textContent = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('❌ Bir hata oluştu. Lütfen tekrar deneyin.');
                btn.disabled = false;
                btn.textContent = originalText;
            });
        }

        // Üretim Bitir Modal
        function openFinishProductionModal() {
            const plannedQuantity = <?= floatval($orderData['PlannedQuantity'] ?? 0) ?>;
            const warehouseCode = '<?= htmlspecialchars($orderData['Warehouse'] ?? '') ?>';
            const absoluteEntry = <?= intval($absoluteEntry) ?>;
            
            if (!warehouseCode) {
                alert('❌ Depo bilgisi bulunamadı. Lütfen sistem yöneticisi ile iletişime geçin.');
                return;
            }
            
            const quantity = prompt(`Üretim Miktarı:\n\nPlanlanan Miktar: ${plannedQuantity}\n\nÜretilecek miktarı girin:`, plannedQuantity);
            
            if (quantity === null) {
                return; // Kullanıcı iptal etti
            }
            
            const productionQuantity = parseFloat(quantity);
            if (isNaN(productionQuantity) || productionQuantity <= 0) {
                alert('❌ Geçerli bir miktar giriniz.');
                return;
            }
            
            if (!confirm(`Üretim miktarı: ${productionQuantity}\n\nÜretimi bitirmek istediğinizden emin misiniz?`)) {
                return;
            }
            
            finishProduction(productionQuantity, warehouseCode);
        }

        function finishProduction(quantity, warehouseCode) {
            const btn = document.getElementById('finishProductionBtn');
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'İşleniyor...';

            const formData = new FormData();
            formData.append('action', 'finish_production');
            formData.append('quantity', quantity);
            formData.append('warehouseCode', warehouseCode);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ ' + data.message);
                    // Sayfayı yenile
                    window.location.reload();
                } else {
                    alert('❌ ' + data.message);
                    btn.disabled = false;
                    btn.textContent = originalText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('❌ Bir hata oluştu. Lütfen tekrar deneyin.');
                btn.disabled = false;
                btn.textContent = originalText;
            });
        }
    </script>
</body>
</html>
