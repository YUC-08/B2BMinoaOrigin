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
$status = $_GET['status'] ?? '';

if (empty($requestNo)) {
    header("Location: DisTedarik.php");
    exit;
}

$detailData = null;
$lines = [];
$isPurchaseOrder = !empty($orderNo);
$errorMsg = '';

$debugInfo = [];

if ($isPurchaseOrder) {
   
    $orderQuery = 'PurchaseOrders(' . intval($orderNo) . ')';
    $orderData = $sap->get($orderQuery);
    
    $debugInfo['query'] = $orderQuery;
    $debugInfo['http_status'] = $orderData['status'] ?? 'NO STATUS';
    $debugInfo['has_response'] = isset($orderData['response']);
    $debugInfo['error'] = $orderData['error'] ?? null;
    $debugInfo['response_error'] = $orderData['response']['error'] ?? null;
    
    if (($orderData['status'] ?? 0) == 200 && isset($orderData['response'])) {
        $detailData = $orderData['response'];
        
        // ✅ ÖNEMLİ: DocEntry ile çek (DocNum değil!)
        $orderDocEntry = $detailData['DocEntry'] ?? intval($orderNo);
        
        
        $linesQuery = "PurchaseOrders({$orderDocEntry})/DocumentLines";
        $linesData = $sap->get($linesQuery);
        
        $debugInfo['lines_query'] = $linesQuery;
        $debugInfo['lines_http_status'] = $linesData['status'] ?? 'NO STATUS';
        
        if (($linesData['status'] ?? 0) == 200 && isset($linesData['response'])) {
            $resp = $linesData['response'];
            
            // ✅ Robust parsing: Farklı response formatlarını destekle
            if (isset($resp['value']) && is_array($resp['value'])) {
                $lines = $resp['value'];
            } elseif (isset($resp['DocumentLines']) && is_array($resp['DocumentLines'])) {
                $lines = $resp['DocumentLines'];
            } elseif (is_array($resp) && !isset($resp['@odata.context'])) {
                // Direct array
                $lines = $resp;
            } elseif (isset($resp['DocumentLines@odata.navigationLink'])) {
                // Navigation link
                $navLink = $resp['DocumentLines@odata.navigationLink'];
                $navRes = $sap->get($navLink);
                if (($navRes['status'] ?? 0) == 200 && isset($navRes['response'])) {
                    if (isset($navRes['response']['value']) && is_array($navRes['response']['value'])) {
                        $lines = $navRes['response']['value'];
                    } elseif (isset($navRes['response']['DocumentLines']) && is_array($navRes['response']['DocumentLines'])) {
                        $lines = $navRes['response']['DocumentLines'];
                    }
                }
            }
        }
    } else {
        $errorMsg = "Sipariş detayları alınamadı! HTTP " . ($orderData['status'] ?? 'NO STATUS');
        if (isset($orderData['response']['error'])) {
            $errorMsg .= " - " . json_encode($orderData['response']['error']);
        }
    }
} else {
    // Senaryo B: Sipariş No boşsa (Talep Detay)
    
    $requestQuery = 'PurchaseRequests(' . intval($requestNo) . ')';
    $requestData = $sap->get($requestQuery);
    
    $debugInfo['query'] = $requestQuery;
    $debugInfo['http_status'] = $requestData['status'] ?? 'NO STATUS';
    $debugInfo['has_response'] = isset($requestData['response']);
    $debugInfo['error'] = $requestData['error'] ?? null;
    $debugInfo['response_error'] = $requestData['response']['error'] ?? null;
    
    if (($requestData['status'] ?? 0) == 200 && isset($requestData['response'])) {
        $detailData = $requestData['response'];
        
        // ✅ ÖNEMLİ: DocEntry ile çek
        $requestDocEntry = $detailData['DocEntry'] ?? intval($requestNo);
        
        // Spec'e göre: GET /b1s/v2/PurchaseRequests(53)/DocumentLines (NOT PurchaseRequestLines!)
        $linesQuery = "PurchaseRequests({$requestDocEntry})/DocumentLines";
        $linesData = $sap->get($linesQuery);
        
        $debugInfo['lines_query'] = $linesQuery;
        $debugInfo['lines_http_status'] = $linesData['status'] ?? 'NO STATUS';
        
        if (($linesData['status'] ?? 0) == 200 && isset($linesData['response'])) {
            $resp = $linesData['response'];
            
            // ✅ Robust parsing: Farklı response formatlarını destekle
            if (isset($resp['value']) && is_array($resp['value'])) {
                $lines = $resp['value'];
            } elseif (isset($resp['DocumentLines']) && is_array($resp['DocumentLines'])) {
                $lines = $resp['DocumentLines'];
            } elseif (is_array($resp) && !isset($resp['@odata.context'])) {
                // Direct array
                $lines = $resp;
            } elseif (isset($resp['DocumentLines@odata.navigationLink'])) {
                // Navigation link
                $navLink = $resp['DocumentLines@odata.navigationLink'];
                $navRes = $sap->get($navLink);
                if (($navRes['status'] ?? 0) == 200 && isset($navRes['response'])) {
                    if (isset($navRes['response']['value']) && is_array($navRes['response']['value'])) {
                        $lines = $navRes['response']['value'];
                    } elseif (isset($navRes['response']['DocumentLines']) && is_array($navRes['response']['DocumentLines'])) {
                        $lines = $navRes['response']['DocumentLines'];
                    }
                }
            }
        }
    } else {
        $errorMsg = "Talep detayları alınamadı! HTTP " . ($requestData['status'] ?? 'NO STATUS');
        if (isset($requestData['response']['error'])) {
            $errorMsg .= " - " . json_encode($requestData['response']['error']);
        }
    }
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dis Tedarik Detay - MINOA</title>
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
            <h2>Dis Tedarik Detay</h2>
            <div class="header-actions">
                <?php if ($isPurchaseOrder): ?>
                    <a href="DisTedarik-TeslimAl.php?requestNo=<?= urlencode($requestNo) ?>&orderNo=<?= urlencode($orderNo) ?>">
                        <button class="btn btn-primary">✓ Teslim Al</button>
                    </a>
                <?php endif; ?>
                <button class="btn btn-secondary" onclick="window.location.href='DisTedarik.php'">← Geri Dön</button>
            </div>
        </header>

        <div class="content-wrapper">
            <?php if ($errorMsg || !$detailData): ?>
                <?php if ($errorMsg): ?>
                    <div class="card" style="background: #fee2e2; border: 2px solid #ef4444; margin-bottom: 1.5rem;">
                        <h3 style="color: #991b1b; margin-bottom: 1rem;">❌ Hata</h3>
                        <p style="color: #991b1b; font-weight: 600;"><?= htmlspecialchars($errorMsg) ?></p>
                    </div>
                <?php else: ?>
                    <div class="card" style="background: #fee2e2; border: 2px solid #ef4444; margin-bottom: 1.5rem;">
                        <h3 style="color: #991b1b; margin-bottom: 1rem;">❌ Hata</h3>
                        <p style="color: #991b1b; font-weight: 600;">Detay bilgileri alınamadı!</p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($debugInfo)): ?>
                    <div class="card" style="background: #fef3c7; border: 2px solid #f59e0b; margin-bottom: 1.5rem;">
                        <h3 style="color: #92400e; margin-bottom: 1rem;">🔍 Debug Bilgileri</h3>
                        <div style="font-family: monospace; font-size: 0.85rem; color: #78350f;">
                            <p><strong>Request No:</strong> <?= htmlspecialchars($requestNo) ?></p>
                            <p><strong>Order No:</strong> <?= htmlspecialchars($orderNo ?? 'N/A') ?></p>
                            <p><strong>Is Purchase Order:</strong> <?= $isPurchaseOrder ? 'Evet' : 'Hayır' ?></p>
                            <p><strong>Query:</strong> <?= htmlspecialchars($debugInfo['query'] ?? 'N/A') ?></p>
                            <p><strong>HTTP Status:</strong> <?= htmlspecialchars($debugInfo['http_status'] ?? 'N/A') ?></p>
                            <p><strong>Has Response:</strong> <?= $debugInfo['has_response'] ? 'Evet' : 'Hayır' ?></p>
                            <?php if (isset($debugInfo['lines_query'])): ?>
                                <p><strong>Lines Query:</strong> <?= htmlspecialchars($debugInfo['lines_query']) ?></p>
                                <p><strong>Lines HTTP Status:</strong> <?= htmlspecialchars($debugInfo['lines_http_status'] ?? 'N/A') ?></p>
                            <?php endif; ?>
                            <?php if ($debugInfo['error']): ?>
                                <p style="color: #dc2626;"><strong>Error:</strong> <?= htmlspecialchars(json_encode($debugInfo['error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></p>
                            <?php endif; ?>
                            <?php if ($debugInfo['response_error']): ?>
                                <p style="color: #dc2626;"><strong>Response Error:</strong> <?= htmlspecialchars(json_encode($debugInfo['response_error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
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
                                <div class="detail-value"><?= htmlspecialchars($orderNo) ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Sipariş Tarihi</div>
                                <div class="detail-value"><?= formatDate($detailData['DocDate'] ?? '') ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Tahmini Teslimat</div>
                                <div class="detail-value"><?= formatDate($detailData['DocDueDate'] ?? '') ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Tedarikçi</div>
                                <div class="detail-value"><?= htmlspecialchars($detailData['CardName'] ?? '-') ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Teslimat Belge No</div>
                                <div class="detail-value"><?= htmlspecialchars($detailData['U_ASB2B_NumAtCard'] ?? '-') ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Sipariş Notu</div>
                                <div class="detail-value"><?= htmlspecialchars($detailData['U_ASB2B_ORDSUM'] ?? '-') ?></div>
                            </div>
                        <?php else: ?>
                            <!-- Senaryo B: Talep Detayı -->
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
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($lines)): ?>
                                <?php foreach ($lines as $line): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($line['ItemCode'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($line['ItemDescription'] ?? '-') ?></td>
                                        <td><?= number_format(floatval($line['Quantity'] ?? 0), 2) ?></td>
                                        <td><?= htmlspecialchars($line['UoMCode'] ?? '-') ?></td>
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
            <?php else: ?>
                <section class="card">
                    <p style="color: #ef4444;">Detay bilgileri alınamadı!</p>
                </section>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

