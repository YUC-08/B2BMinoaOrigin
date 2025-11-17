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

if (empty($requestNo)) {
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

if ($isPurchaseOrder) {
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
    if (!empty($uAsOwnr) && !empty($branch)) {
        $requestNoInt = intval($requestNo);
        $viewFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and RequestNo eq {$requestNoInt}";
        $viewQuery = 'view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=' . urlencode($viewFilter) . '&$orderby=' . urlencode('U_ASB2B_ORNO desc');
        $viewData = $sap->get($viewQuery);
        $viewRows = $viewData['response']['value'] ?? [];
        
        foreach ($viewRows as $row) {
            $orderNoFromView = $row['U_ASB2B_ORNO'] ?? null;
            if (!empty($orderNoFromView) && $orderNoFromView !== null && $orderNoFromView !== '' && $orderNoFromView !== '-') {
                $status = $row['U_ASB2B_STATUS'] ?? null;
                $statusText = getStatusText($status);
                $canReceive = isReceivableStatus($status);
                
                $allOrdersForRequest[] = [
                    'OrderNo' => $orderNoFromView,
                    'OrderDate' => $row['U_ASB2B_ORDT'] ?? null,
                    'Status' => $status,
                    'StatusText' => $statusText,
                    'CanReceive' => $canReceive
                ];
            }
        }
    }
    
    $requestQuery = 'PurchaseRequests(' . intval($requestNo) . ')';
    $requestData = $sap->get($requestQuery);
    
    if (($requestData['status'] ?? 0) == 200 && isset($requestData['response'])) {
        $detailData = $requestData['response'];
        $requestDocEntry = $detailData['DocEntry'] ?? intval($requestNo);
        
        $linesQuery = "PurchaseRequests({$requestDocEntry})/DocumentLines";
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
        $errorMsg = "Talep detayları alınamadı!";
    }
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

.detail-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
}

.detail-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    margin-bottom: 0.25rem;
}

.detail-value {
    font-size: 1rem;
    color: #1f2937;
    font-weight: 500;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.data-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 500;
    text-align: center;
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

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-secondary:hover {
    background: #4b5563;
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
                <section class="card">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Talep No</div>
                            <div class="detail-value"><?= htmlspecialchars($requestNo) ?></div>
                        </div>
                        <?php if ($isPurchaseOrder): ?>
                            <div class="detail-item">
                                <div class="detail-label">Sipariş No</div>
                                <div class="detail-value"><?= htmlspecialchars($orderDocEntry ?? $orderNo) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Tedarikçi</div>
                                <div class="detail-value"><?= htmlspecialchars($detailData['CardName'] ?? '-') ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Sipariş Tarihi</div>
                                <div class="detail-value"><?= formatDate($detailData['DocDate'] ?? '') ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Durum</div>
                                <div class="detail-value">
                                    <?php if (isset($orderStatus)): ?>
                                        <span class="status-badge <?= getStatusClass($orderStatus) ?>"><?= getStatusText($orderStatus) ?></span>
                                    <?php else: ?>
                                        <span class="status-badge status-unknown">Bilinmiyor</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Teslimat Belge No</div>
                                <div class="detail-value"><?= htmlspecialchars($detailData['U_ASB2B_NumAtCard'] ?? '-') ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Tahmini Teslimat</div>
                                <div class="detail-value"><?= formatDate($detailData['DocDueDate'] ?? '') ?></div>
                            </div>
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Sipariş Notu</div>
                                <div class="detail-value"><?= htmlspecialchars($detailData['U_ASB2B_ORDSUM'] ?? '-') ?></div>
                            </div>
                        <?php else: ?>
                            <div class="detail-item">
                                <div class="detail-label">Talep Tarihi</div>
                                <div class="detail-value"><?= formatDate($detailData['DocDate'] ?? '') ?></div>
                            </div>
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Açıklama</div>
                                <div class="detail-value"><?= htmlspecialchars($detailData['Comments'] ?? '-') ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
                
                <section class="card">
                    <h3 style="margin-bottom: 1rem; color: #1e40af;"><?= $isPurchaseOrder ? 'Sipariş' : 'Talep' ?> Detayı (Satırlar)</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Kalem Numarası</th>
                                <th>Kalem Tanımı</th>
                                <th><?= $isPurchaseOrder ? 'Sipariş' : 'Talep' ?> Miktarı</th>
                                <th>Birim</th>
                                <?php if (!$isPurchaseOrder): ?>
                                    <th>Tedarikçi Kodu</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($lines)): ?>
                                <?php foreach ($lines as $lineIndex => $line): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($line['ItemCode'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($line['ItemDescription'] ?? '-') ?></td>
                                        <td><?= number_format(floatval($line['Quantity'] ?? 0), 2) ?></td>
                                        <td><?= htmlspecialchars($line['UoMCode'] ?? '-') ?></td>
                                        <?php if (!$isPurchaseOrder): ?>
                                            <td><?= htmlspecialchars($line['VendorNum'] ?? '-') ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?= $isPurchaseOrder ? '4' : '5' ?>" style="text-align: center; padding: 2rem; color: #9ca3af;">Satır bulunamadı.</td>
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
