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
$branch = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// PurchaseRequestList sorgusu
// NOT: View'den gelen verilerde U_AS_OWNR ve U_ASB2B_BRAN null olabiliyor
// Bu yüzden filtreleme yapmadan tüm kayıtları çekiyoruz
// URL encoding: boşluk ve özel karakterler encode edilmeli
$query = 'view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$orderby=' . urlencode('RequestNo desc') . '&$top=100';

$data = $sap->get($query);
$allRows = $data['response']['value'] ?? [];

// PHP tarafında null değerleri handle et (eğer gerekirse filtreleme yapılabilir)
// Şimdilik tüm kayıtları gösteriyoruz çünkü view'de bu alanlar null geliyor

// Debug bilgileri
$debugInfo = [];
$debugInfo['session_uAsOwnr'] = $uAsOwnr;
$debugInfo['session_branch'] = $branch;
$debugInfo['note'] = 'Filter kaldırıldı - View\'de U_AS_OWNR ve U_ASB2B_BRAN null geliyor';
$debugInfo['query'] = $query;
$debugInfo['http_status'] = $data['status'] ?? 'NO STATUS';
$debugInfo['response_keys'] = isset($data['response']) ? array_keys($data['response']) : [];
$debugInfo['has_value'] = isset($data['response']['value']);
$debugInfo['row_count'] = count($allRows);
$debugInfo['error'] = $data['error'] ?? null;
$debugInfo['response_error'] = $data['response']['error'] ?? null;

// Status mapping
function getStatusText($status) {
    $statusMap = [
        '1' => 'Onay bekleniyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal edildi'
    ];
    return $statusMap[$status] ?? 'Bilinmiyor';
}

function getStatusClass($status) {
    $classMap = [
        '1' => 'status-pending',
        '2' => 'status-processing',
        '3' => 'status-shipped',
        '4' => 'status-completed',
        '5' => 'status-cancelled'
    ];
    return $classMap[$status] ?? 'status-unknown';
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
    <title>Dis Tedarik Siparişleri - MINOA</title>
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
    margin-bottom: 0;
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
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
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
    margin-right: 0.5rem;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-view {
    background: #e0e7ff;
    color: #4338ca;
}

.btn-view:hover {
    background: #c7d2fe;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Dis Tedarik Siparişleri</h2>
            <button class="btn btn-primary" onclick="window.location.href='DisTedarikSO.php'">+ Yeni Talep Oluştur</button>
        </header>

        <div class="content-wrapper">
            <?php if (empty($allRows) || $debugInfo['http_status'] != 200): ?>
                <div class="card" style="background: #fef3c7; border: 2px solid #f59e0b; margin-bottom: 1.5rem;">
                    <h3 style="color: #92400e; margin-bottom: 1rem;">🔍 Debug Bilgileri</h3>
                    <div style="font-family: monospace; font-size: 0.85rem; color: #78350f;">
                        <p><strong>Session U_AS_OWNR:</strong> <?= htmlspecialchars($debugInfo['session_uAsOwnr']) ?></p>
                        <p><strong>Session Branch:</strong> <?= htmlspecialchars($debugInfo['session_branch']) ?></p>
                        <?php if (isset($debugInfo['note'])): ?>
                            <p><strong>Not:</strong> <?= htmlspecialchars($debugInfo['note']) ?></p>
                        <?php endif; ?>
                        <p><strong>Query URL:</strong> <?= htmlspecialchars($debugInfo['query']) ?></p>
                        <p><strong>HTTP Status:</strong> <?= htmlspecialchars($debugInfo['http_status']) ?></p>
                        <p><strong>Response Keys:</strong> <?= htmlspecialchars(implode(', ', $debugInfo['response_keys'])) ?></p>
                        <p><strong>Has 'value' key:</strong> <?= $debugInfo['has_value'] ? 'Evet' : 'Hayır' ?></p>
                        <p><strong>Row Count:</strong> <?= $debugInfo['row_count'] ?></p>
                        <?php if ($debugInfo['error']): ?>
                            <p style="color: #dc2626;"><strong>Error:</strong> <?= htmlspecialchars(json_encode($debugInfo['error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></p>
                        <?php endif; ?>
                        <?php if ($debugInfo['response_error']): ?>
                            <p style="color: #dc2626;"><strong>Response Error:</strong> <?= htmlspecialchars(json_encode($debugInfo['response_error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></p>
                        <?php endif; ?>
                        <?php if (isset($data['response']) && !isset($data['response']['value'])): ?>
                            <p style="color: #dc2626;"><strong>Full Response:</strong> <pre style="background: white; padding: 1rem; border-radius: 6px; overflow-x: auto;"><?= htmlspecialchars(json_encode($data['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <section class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Talep No</th>
                            <th>Sipariş No</th>
                            <th>Talep Tarihi</th>
                            <th>Sipariş Tarihi</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($allRows)): ?>
                            <?php foreach ($allRows as $row): 
                                $status = $row['U_ASB2B_STATUS'] ?? null;
                                // Status null ise "Bilinmiyor" göster
                                if ($status === null) {
                                    $statusText = 'Bilinmiyor';
                                    $statusClass = 'status-unknown';
                                } else {
                                    $statusText = getStatusText($status);
                                    $statusClass = getStatusClass($status);
                                }
                                $requestNo = $row['RequestNo'] ?? '';
                                $orderNo = $row['U_ASB2B_ORNO'] ?? null;
                                $docDate = formatDate($row['DocDate'] ?? '');
                                $orderDate = formatDate($row['OrderDate'] ?? '');
                                // Status null veya '2' veya '3' ise Teslim Al aktif olabilir (null durumunda da göster)
                                $canReceive = ($status === null || $status === '2' || $status === '3');
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($requestNo) ?></td>
                                    <td><?= $orderNo ? htmlspecialchars($orderNo) : '-' ?></td>
                                    <td><?= $docDate ?></td>
                                    <td><?= $orderDate !== '-' ? $orderDate : '-' ?></td>
                                    <td><span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                                    <td>
                                        <a href="DisTedarik-Detay.php?requestNo=<?= urlencode($requestNo) ?><?= $orderNo ? '&orderNo=' . urlencode($orderNo) : '' ?>&status=<?= urlencode($status) ?>">
                                            <button class="btn btn-view">👁️ Detay</button>
                                        </a>
                                        <?php if ($canReceive): ?>
                                            <a href="DisTedarik-TeslimAl.php?requestNo=<?= urlencode($requestNo) ?><?= $orderNo ? '&orderNo=' . urlencode($orderNo) : '' ?>">
                                                <button class="btn btn-primary">✓ Teslim Al</button>
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-primary" disabled>✓ Teslim Al</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center;padding:2rem;color:#9ca3af;">Kayıt bulunamadı.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>
</body>
</html>
