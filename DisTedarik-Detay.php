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

// RequestNo veya orderNo olmalı (Kayıt dışı modda sadece orderNo olabilir)
if (empty($requestNo) && empty($orderNo)) {
    header("Location: DisTedarik.php");
    exit;
}

$detailData = null;
$lines = [];
$isPurchaseOrder = !empty($orderNo);
$errorMsg = '';
$allOrdersForRequest = [];

$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? '';

// Eğer sadece orderNo varsa (RequestNo yok), direkt PurchaseOrder moduna geç
if (empty($requestNo) && !empty($orderNo)) {
    $isPurchaseOrder = true;
}

if ($isPurchaseOrder) {
    // Sipariş detayı
    $orderQuery = 'PurchaseOrders(' . intval($orderNo) . ')';
    $orderData = $sap->get($orderQuery);
    
    if (($orderData['status'] ?? 0) == 200 && isset($orderData['response'])) {
        $detailData = $orderData['response'];
        $orderDocEntry = $detailData['DocEntry'] ?? intval($orderNo);
        
        $canReceive = false;
        $orderStatus = null;
        // Durum bilgisini önce PurchaseOrders'dan al (Kayıt dışı modda view'de olmayabilir)
        $orderStatus = $detailData['U_ASB2B_STATUS'] ?? '3'; // Varsayılan: Sevk edildi
        $canReceive = isReceivableStatus($orderStatus);
        
        // View'den de kontrol et (varsa)
        if (!empty($uAsOwnr) && !empty($branch)) {
            $orderNoInt = intval($orderNo);
            $viewFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_ORNO eq {$orderNoInt}";
            $viewQuery = 'view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=' . urlencode($viewFilter);
            $viewData = $sap->get($viewQuery);
            $viewRows = $viewData['response']['value'] ?? [];
            
            if (!empty($viewRows)) {
                $orderStatus = $viewRows[0]['U_ASB2B_STATUS'] ?? $orderStatus;
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
            
            // Sipariş modundaysak, gelen satırların sipariş numarasını manuel set et
            foreach ($lines as &$l) {
                $l['_OrderNo'] = $orderNo;
            }
            unset($l); // Referansı temizle
        }
    } else {
        $errorMsg = "Sipariş detayları alınamadı!";
    }
} else {
    // Her satır için hangi sipariş numarasına ait olduğunu tutmak için
    // BaseEntry ve BaseLine ile eşleştirme yapacağız
    $lineToOrderMap = []; // "BaseEntry-BaseLine" => OrderNo mapping
    $allOrdersMap = []; // OrderNo => OrderInfo mapping
    
    if (!empty($uAsOwnr) && !empty($branch)) {
        $requestNoInt = intval($requestNo);
        $viewFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and RequestNo eq {$requestNoInt}";
        $viewQuery = 'view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=' . urlencode($viewFilter) . '&$orderby=' . urlencode('U_ASB2B_ORNO desc');
        $viewData = $sap->get($viewQuery);
        $viewRows = $viewData['response']['value'] ?? [];
        
        // Önce tüm sipariş numaralarını topla
        $uniqueOrderNos = [];
        foreach ($viewRows as $row) {
            $orderNoFromView = $row['U_ASB2B_ORNO'] ?? null;
            if (!empty($orderNoFromView) && $orderNoFromView !== null && $orderNoFromView !== '' && $orderNoFromView !== '-') {
                if (!in_array($orderNoFromView, $uniqueOrderNos)) {
                    $uniqueOrderNos[] = $orderNoFromView;
                }
            }
        }
        
        // Her sipariş için bilgileri çek ve satır eşleştirmesi yap
        foreach ($uniqueOrderNos as $orderNoItem) {
            $orderQuery = 'PurchaseOrders(' . intval($orderNoItem) . ')';
            $orderData = $sap->get($orderQuery);
            
            if (($orderData['status'] ?? 0) == 200 && isset($orderData['response'])) {
                $orderInfo = $orderData['response'];
                $orderDocEntry = $orderInfo['DocEntry'] ?? intval($orderNoItem);
                
                // Sipariş bilgisini kaydet
                $status = null;
                $canReceive = false;
                foreach ($viewRows as $row) {
                    if (($row['U_ASB2B_ORNO'] ?? null) == $orderNoItem) {
                        $status = $row['U_ASB2B_STATUS'] ?? null;
                        $canReceive = isReceivableStatus($status);
                        break;
                    }
                }
                
                if (!isset($allOrdersMap[$orderNoItem])) {
                    $allOrdersForRequest[] = [
                        'OrderNo' => $orderNoItem,
                        'OrderDate' => $orderInfo['DocDate'] ?? null,
                        'Status' => $status,
                        'StatusText' => getStatusText($status),
                        'CanReceive' => $canReceive
                    ];
                    $allOrdersMap[$orderNoItem] = [
                        'OrderNo' => $orderNoItem,
                        'OrderDate' => $orderInfo['DocDate'] ?? null,
                        'Status' => $status,
                        'StatusText' => getStatusText($status),
                        'CanReceive' => $canReceive
                    ];
                }
                
                // Sipariş satırlarını çek ve BaseEntry-BaseLine eşleştirmesi yap
                $orderLinesQuery = "PurchaseOrders({$orderDocEntry})/DocumentLines";
                $orderLinesData = $sap->get($orderLinesQuery);
                
                if (($orderLinesData['status'] ?? 0) == 200 && isset($orderLinesData['response'])) {
                    $orderLinesResp = $orderLinesData['response'];
                    $orderLines = [];
                    if (isset($orderLinesResp['value']) && is_array($orderLinesResp['value'])) {
                        $orderLines = $orderLinesResp['value'];
                    } elseif (isset($orderLinesResp['DocumentLines']) && is_array($orderLinesResp['DocumentLines'])) {
                        $orderLines = $orderLinesResp['DocumentLines'];
                    }
                    
                    // Her sipariş satırı için BaseEntry ve BaseLine ile eşleştirme yap
                    foreach ($orderLines as $orderLine) {
                        $baseEntry = $orderLine['BaseEntry'] ?? null;
                        $baseLine = $orderLine['BaseLine'] ?? null;
                        
                        // BaseEntry PurchaseRequest'in DocEntry'si olmalı
                        if (!empty($baseEntry) && $baseEntry == intval($requestNo)) {
                            $key = $baseEntry . '-' . $baseLine;
                            $lineToOrderMap[$key] = $orderNoItem;
                        }
                    }
                }
            }
        }
    }
    
    // Talep detaylarını çek (her zaman gerekli - header bilgileri için)
    $requestQuery = 'PurchaseRequests(' . intval($requestNo) . ')';
    $requestData = $sap->get($requestQuery);
    
    if (($requestData['status'] ?? 0) == 200 && isset($requestData['response'])) {
        $detailData = $requestData['response'];
    } else {
        $errorMsg = "Talep detayları alınamadı!";
    }
    
    // Eğer orderNo parametresi varsa, SADECE o siparişe ait satırları göster
    if (!empty($orderNo)) {
        $orderQuery = 'PurchaseOrders(' . intval($orderNo) . ')';
        $orderData = $sap->get($orderQuery);
        
        if (($orderData['status'] ?? 0) == 200 && isset($orderData['response'])) {
            $orderDetailData = $orderData['response'];
            $orderDocEntry = $orderDetailData['DocEntry'] ?? intval($orderNo);
            
            $linesQuery = "PurchaseOrders({$orderDocEntry})/DocumentLines";
            $linesData = $sap->get($linesQuery);
            
            if (($linesData['status'] ?? 0) == 200 && isset($linesData['response'])) {
                $resp = $linesData['response'];
                if (isset($resp['value']) && is_array($resp['value'])) {
                    $lines = $resp['value'];
                } elseif (isset($resp['DocumentLines']) && is_array($resp['DocumentLines'])) {
                    $lines = $resp['DocumentLines'];
                }
                
                // Sipariş modundaysak, gelen satırların sipariş numarasını manuel set et
                // Önce kapalı satırları filtrele, sonra _OrderNo set et
                $lines = array_filter($lines, function($l) {
                    // LineStatus kontrolü - kapalı satırları filtrele
                    return !isset($l['LineStatus']) || $l['LineStatus'] !== 'C';
                });
                
                // Her satıra sipariş numarasını ekle
                foreach ($lines as &$l) {
                    $l['_OrderNo'] = $orderNo;
                }
                unset($l); // Referansı temizle
            }
        }
    } else {
        // Eğer sipariş seçilmediyse, talep satırlarını göster ve her satır için sipariş numarasını ekle
        if (!empty($detailData)) {
            $requestDocEntry = $detailData['DocEntry'] ?? intval($requestNo);
            
            $linesQuery = "PurchaseRequests({$requestDocEntry})/DocumentLines";
            $linesData = $sap->get($linesQuery);
            
            if (($linesData['status'] ?? 0) == 200 && isset($linesData['response'])) {
                $resp = $linesData['response'];
                $tempLines = [];
                if (isset($resp['value']) && is_array($resp['value'])) {
                    $tempLines = $resp['value'];
                } elseif (isset($resp['DocumentLines']) && is_array($resp['DocumentLines'])) {
                    $tempLines = $resp['DocumentLines'];
                }
                
                // Her satır için sipariş numarasını ekle
                $requestDocEntry = $detailData['DocEntry'] ?? intval($requestNo);
                foreach ($tempLines as $lineIndex => $line) {
                    // LineNum 0-based veya 1-based olabilir, her iki durumu da kontrol et
                    $lineNum = $line['LineNum'] ?? null;
                    if ($lineNum === null) {
                        // LineNum yoksa, satır index'ini kullan (0-based)
                        $lineNum = $lineIndex;
                    }
                    $key = $requestDocEntry . '-' . $lineNum;
                    // Bu satırın hangi sipariş numarasına ait olduğunu bul
                    $line['_OrderNo'] = $lineToOrderMap[$key] ?? null;
                    $lines[] = $line;
                }
            }
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
    $statusMap = [
        '0' => 'Sipariş yok',
        '1' => 'Onay bekleniyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal edildi'
    ];
    return $statusMap[(string)$status] ?? 'Bilinmiyor';
}

function getStatusClass($status) {
    $classMap = [
        '0' => 'status-unknown',
        '1' => 'status-pending',
        '2' => 'status-processing',
        '3' => 'status-shipped',
        '4' => 'status-completed',
        '5' => 'status-cancelled'
    ];
    return $classMap[(string)$status] ?? 'status-unknown';
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
    width: 20%;
}

.data-table th:nth-child(4) {
    text-align: center;
}

.data-table td {
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    color: #374151;
    width: 20%;
}

.data-table td:nth-child(4) {
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
                    // Birden fazla sipariş varsa header'da buton gösterme, sipariş listesinde gösterilecek
                    // Sadece tek siparişli taleplerde header'da teslim al butonu göster
                    $hasSingleOrder = count($allOrdersForRequest) === 1;
                    
                    if ($hasSingleOrder && !empty($allOrdersForRequest)) {
                        $singleOrder = $allOrdersForRequest[0];
                        $singleOrderNo = $singleOrder['OrderNo'] ?? null;
                        $singleOrderStatus = $singleOrder['Status'] ?? null;
                        
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
                                <label>Sipariş No:</label>
                                <div class="detail-value">
                                    <?php
                                    $siparisNoDisplay = '-';
                                    if ($isPurchaseOrder) {
                                        $siparisNoDisplay = htmlspecialchars($orderDocEntry ?? $orderNo ?? '-');
                                    } elseif (!empty($allOrdersForRequest)) {
                                        // Birden fazla sipariş varsa, ilk sipariş numarasını göster
                                        // Tüm siparişler aşağıda listelenecek
                                        $firstOrder = $allOrdersForRequest[0];
                                        $siparisNoDisplay = htmlspecialchars($firstOrder['OrderNo'] ?? '-');
                                        if (count($allOrdersForRequest) > 1) {
                                            $siparisNoDisplay .= ' (' . count($allOrdersForRequest) . ' sipariş)';
                                        }
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
                                    <?php if ($isPurchaseOrder && isset($orderStatus)): ?>
                                        <span class="status-badge <?= getStatusClass($orderStatus) ?>"><?= getStatusText($orderStatus) ?></span>
                                    <?php else: ?>
                                        <span class="status-badge status-unknown">Bilinmiyor</span>
                                    <?php endif; ?>
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
                
                <?php if (!empty($allOrdersForRequest) && count($allOrdersForRequest) > 1): ?>
                <section class="card">
                    <div class="section-title">Siparişler</div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Sipariş No</th>
                                <th>Sipariş Tarihi</th>
                                <th>Durum</th>
                                <th>İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allOrdersForRequest as $orderItem): ?>
                                <?php
                                $orderNoItem = $orderItem['OrderNo'] ?? '';
                                $orderDateItem = $orderItem['OrderDate'] ?? '';
                                $orderStatusItem = $orderItem['Status'] ?? null;
                                $canReceiveItem = $orderItem['CanReceive'] ?? false;
                                $isSelectedOrder = (!empty($orderNo) && intval($orderNo) == intval($orderNoItem));
                                ?>
                                <tr style="<?= $isSelectedOrder ? 'background: #f0f9ff; border-left: 3px solid #3b82f6;' : '' ?>">
                                    <td>
                                        <a href="DisTedarik-Detay.php?requestNo=<?= urlencode($requestNo) ?>&orderNo=<?= urlencode($orderNoItem) ?>" style="color: #3b82f6; text-decoration: none; font-weight: 600;">
                                            <?= htmlspecialchars($orderNoItem) ?>
                                        </a>
                                    </td>
                                    <td><?= formatDate($orderDateItem) ?></td>
                                    <td>
                                        <span class="status-badge <?= getStatusClass($orderStatusItem) ?>">
                                            <?= getStatusText($orderStatusItem) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($canReceiveItem): ?>
                                            <a href="DisTedarik-TeslimAl.php?requestNo=<?= urlencode($requestNo) ?>&orderNos=<?= urlencode($orderNoItem) ?>">
                                                <button class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;">✓ Teslim Al</button>
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #9ca3af; font-size: 12px;">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
                <?php endif; ?>
                
                <section class="card">
                    <div class="section-title">
                        <?php if ($isPurchaseOrder || (!empty($orderNo) && !$isPurchaseOrder)): ?>
                            Sipariş Detayı
                        <?php else: ?>
                            Talep Detayı
                        <?php endif; ?>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Sipariş No</th>
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
                                    $lineOrderNo = $line['_OrderNo'] ?? null;
                                    
                                    // Eğer URL'den belirli bir sipariş numarası ile geldiysek (Sipariş Detayı Modu)
                                    if (!empty($orderNo)) {
                                        // Bu satırın verisi direkt PurchaseOrder'dan geldiyse _OrderNo genellikle boştur, manuel dolduralım
                                        if (empty($lineOrderNo)) {
                                            $lineOrderNo = $orderNo;
                                            $line['_OrderNo'] = $orderNo;
                                        }
                                        
                                        // KRİTİK KONTROL: Eğer listedeki satırın sipariş numarası, URL'deki sipariş numarasıyla eşleşmiyorsa GİZLE.
                                        // Ancak PurchaseOrder'dan çektiğimiz veride _OrderNo bazen set edilmemiş olabilir.
                                        // Eğer $isPurchaseOrder true ise, zaten sadece o siparişin satırları gelmiştir, filtreye gerek yok.
                                        // Eğer talep üzerinden geldiysek filtre şart.
                                        if (!$isPurchaseOrder && isset($line['_OrderNo']) && $line['_OrderNo'] != $orderNo) {
                                            continue; // Bu satırı atla
                                        }
                                    }
                                    
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
                                        <td>
                                            <?php if (!empty($lineOrderNo)): ?>
                                                <a href="DisTedarik-Detay.php?requestNo=<?= urlencode($requestNo) ?>&orderNo=<?= urlencode($lineOrderNo) ?>" style="color: #3b82f6; text-decoration: none; font-weight: 600;">
                                                    <?= htmlspecialchars($lineOrderNo) ?>
                                                </a>
                                            <?php else: ?>
                                                <span style="color: #9ca3af;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($itemCode) ?></td>
                                        <td><?= htmlspecialchars($line['ItemDescription'] ?? '-') ?></td>
                                        <td><?= $deliveredDisplay ?></td>
                                        <td><?= htmlspecialchars($line['VendorNum'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 2rem; color: #9ca3af;">Satır bulunamadı.</td>
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

