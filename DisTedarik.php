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
// Spec'e göre: Filtreleme direkt view'de yapılmalı
// GET /b1s/v2/view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '100'
$filter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}'";
$query = 'view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=' . urlencode($filter) . '&$orderby=' . urlencode('RequestNo desc') . '&$top=1000';

$data = $sap->get($query);
$allRows = $data['response']['value'] ?? [];

// ✅ Talep tarihlerini PurchaseRequest'ten çek (RequriedDate için)
// View'de RequriedDate olmayabilir, bu yüzden PurchaseRequest'ten çekiyoruz
// Performans için sadece görünen ilk sayfa kayıtları için çekiyoruz (max 50)
$requestDates = []; // RequestNo => RequriedDate mapping
if (!empty($allRows)) {
    // İlk 50 kayıt için RequriedDate çek (performans için)
    $maxRequests = min(50, count($allRows));
    for ($i = 0; $i < $maxRequests; $i++) {
        $row = $allRows[$i];
        $reqNo = $row['RequestNo'] ?? null;
        if ($reqNo) {
            $prQuery = "PurchaseRequests({$reqNo})?\$select=RequriedDate,DocDate";
            $prData = $sap->get($prQuery);
            if (($prData['status'] ?? 0) == 200 && isset($prData['response'])) {
                $requriedDate = $prData['response']['RequriedDate'] ?? $prData['response']['RequiredDate'] ?? null;
                // Eğer RequriedDate yoksa DocDate kullan
                if (empty($requriedDate)) {
                    $requriedDate = $prData['response']['DocDate'] ?? null;
                }
                $requestDates[$reqNo] = $requriedDate;
            }
        }
    }
}

// Debug bilgileri
$debugInfo = [];
$debugInfo['session_uAsOwnr'] = $uAsOwnr;
$debugInfo['session_branch'] = $branch;
$debugInfo['note'] = 'Filtreleme direkt view\'de yapılıyor (U_AS_OWNR ve U_ASB2B_BRAN)';
$debugInfo['filter'] = $filter;
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

/* Filter Section */
.filter-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    padding: 24px;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-group label {
    font-weight: 600;
    color: #1e3a8a;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Single Select Dropdown */
.single-select-container {
    position: relative;
    width: 100%;
}

.single-select-input {
    display: flex;
    align-items: center;
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    min-height: 42px;
    transition: all 0.2s ease;
}

.single-select-input:hover {
    border-color: #3b82f6;
}

.single-select-input.active {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.single-select-input input {
    border: none;
    outline: none;
    flex: 1;
    background: transparent;
    cursor: pointer;
    font-size: 14px;
    color: #2c3e50;
}

.dropdown-arrow {
    transition: transform 0.2s;
    color: #6b7280;
    font-size: 12px;
}

.single-select-input.active .dropdown-arrow {
    transform: rotate(180deg);
}

/* Increased z-index to 9999 to ensure dropdown appears above all elements */
.single-select-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #3b82f6;
    border-top: none;
    border-radius: 0 0 8px 8px;
    max-height: 240px;
    overflow-y: auto;
    z-index: 9999;
    display: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    margin-top: -2px;
}

.single-select-dropdown.show {
    display: block;
}

.single-select-option {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    transition: background 0.15s ease;
}

.single-select-option:hover {
    background: #f8fafc;
}

.single-select-option.selected {
    background: #3b82f6;
    color: white;
    font-weight: 500;
}

.single-select-option:last-child {
    border-bottom: none;
}

/* Date Input */
.filter-group input[type="date"] {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: white;
}

.filter-group input[type="date"]:hover {
    border-color: #3b82f6;
}

.filter-group input[type="date"]:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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
            <?php
            // Başarı/Hata mesajlarını göster
            $successMsg = '';
            $errorMsg = '';
            
            if (isset($_GET['msg'])) {
                if ($_GET['msg'] === 'teslim_alindi') {
                    $successMsg = '✅ Teslim alma işlemi başarıyla tamamlandı!';
                    if (isset($_GET['status_warning']) && $_GET['status_warning'] == '1') {
                        $errorMsg = '⚠️ Teslim alma başarılı ama durum güncellenemedi, lütfen manuel kontrol edin.';
                        if (isset($_GET['error']) && !empty($_GET['error'])) {
                            $errorMsg .= '<br><small style="color: #6b7280;">' . htmlspecialchars(urldecode($_GET['error'])) . '</small>';
                        }
                    }
                } elseif ($_GET['msg'] === 'ok') {
                    $successMsg = '✅ İşlem başarıyla tamamlandı!';
                }
            }
            ?>
            
        <?php if (!empty($successMsg)): ?>
            <div class="card" style="background: #dcfce7; border: 2px solid #16a34a; margin-bottom: 1.5rem;">
                <p style="color: #166534; font-weight: 600; margin: 0;"><?= htmlspecialchars($successMsg) ?></p>
                <?php if (!empty($pdnInfo)): ?>
                    <?= $pdnInfo ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
            
            <?php if (!empty($errorMsg)): ?>
                <div class="card" style="background: #fee2e2; border: 2px solid #dc2626; margin-bottom: 1.5rem;">
                    <p style="color: #991b1b; font-weight: 600; margin: 0;"><?= $errorMsg ?></p>
                </div>
            <?php endif; ?>
            
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
            
            <!-- ✅ Filtre Bölümü -->
            <div class="filter-section">
                <div class="filter-group">
                    <label for="statusFilter">SİPARİŞ DURUMU</label>
                    <select id="statusFilter" onchange="filterTable()">
                        <option value="">Tümü</option>
                        <option value="1">Onay bekleniyor</option>
                        <option value="2">Hazırlanıyor</option>
                        <option value="3">Sevk edildi</option>
                        <option value="4">Tamamlandı</option>
                        <option value="5">İptal edildi</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="startDate">BAŞLANGIÇ TARİHİ</label>
                    <div class="date-input-wrapper">
                        <input type="date" id="startDate" onchange="filterTable()" placeholder="gg.aa.yyyy">
                    </div>
                </div>
                
                <div class="filter-group">
                    <label for="endDate">BİTİŞ TARİHİ</label>
                    <div class="date-input-wrapper">
                        <input type="date" id="endDate" onchange="filterTable()" placeholder="gg.aa.yyyy">
                    </div>
                </div>
            </div>
            
            <section class="card">
                <table class="data-table" id="ordersTable">
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
                                
                                // ✅ Talep Tarihi: PurchaseRequest.RequriedDate (kullanıcının talep ettiği teslimat tarihi)
                                // Önce $requestDates mapping'inden al, yoksa view'den DocDate kullan
                                $requestDate = $requestDates[$requestNo] ?? $row['RequriedDate'] ?? $row['RequiredDate'] ?? $row['RequestDate'] ?? $row['DocDate'] ?? '';
                                $docDate = !empty($requestDate) ? formatDate($requestDate) : '-';
                                
                                // ✅ Sipariş Tarihi: PurchaseOrder.DocDate (U_ASB2B_ORDT) - boş olabilir
                                $orderDateValue = $row['U_ASB2B_ORDT'] ?? null;
                                $orderDate = (!empty($orderDateValue) && $orderDateValue !== null && $orderDateValue !== '') ? formatDate($orderDateValue) : '-';
                                
                                // Data attribute'ları ekle (filtreleme için)
                                $statusValue = $status ?? '';
                                // Tarih formatını ISO (YYYY-MM-DD) yap (JavaScript için)
                                $requestDateForFilter = '';
                                if (!empty($requestDate)) {
                                    // Eğer tarih zaten ISO formatındaysa direkt kullan
                                    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $requestDate)) {
                                        $requestDateForFilter = substr($requestDate, 0, 10);
                                    } else {
                                        // Değilse formatla
                                        $requestDateForFilter = date('Y-m-d', strtotime($requestDate));
                                    }
                                }
                                
                                // Spec'e göre: Teslim Al aktif olması için:
                                // - Status = 2 (Hazırlanıyor) veya 3 (Sevk edildi) OLMALI
                                // - VE OrderNo dolu OLMALI
                                $canReceive = false;
                                if (!empty($orderNo) && $orderNo !== null && $orderNo !== '' && $orderNo !== '-') {
                                    if ($status === '2' || $status === '3' || $status === 2 || $status === 3) {
                                        $canReceive = true;
                                    }
                                }
                            ?>
                                <tr data-status="<?= htmlspecialchars($statusValue) ?>" 
                                    data-request-date="<?= htmlspecialchars($requestDateForFilter) ?>">
                                    <td><?= htmlspecialchars($requestNo) ?></td>
                                    <td><?= $orderNo ? htmlspecialchars($orderNo) : '-' ?></td>
                                    <td><?= $docDate ?></td>
                                    <td><?= $orderDate !== '-' ? $orderDate : '-' ?></td>
                                    <td><span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                                    <td>
                                        <a href="DisTedarik-Detay.php?requestNo=<?= urlencode($requestNo) ?><?= $orderNo ? '&orderNo=' . urlencode($orderNo) : '' ?><?= $status !== null ? '&status=' . urlencode($status) : '' ?>">
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
    
    <script>
    // ✅ Filtreleme Fonksiyonu (Client-side)
    function filterTable() {
        const statusFilter = document.getElementById('statusFilter').value;
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        
        const table = document.getElementById('ordersTable');
        const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
        
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            const rowStatus = row.getAttribute('data-status') || '';
            const rowRequestDate = row.getAttribute('data-request-date') || '';
            
            let showRow = true;
            
            // Status filtresi
            if (statusFilter && rowStatus !== statusFilter) {
                showRow = false;
            }
            
            // Tarih filtresi
            if (showRow && (startDate || endDate)) {
                if (rowRequestDate) {
                    const requestDate = new Date(rowRequestDate);
                    
                    if (startDate) {
                        const start = new Date(startDate);
                        if (requestDate < start) {
                            showRow = false;
                        }
                    }
                    
                    if (showRow && endDate) {
                        const end = new Date(endDate);
                        end.setHours(23, 59, 59, 999); // Günün sonuna kadar
                        if (requestDate > end) {
                            showRow = false;
                        }
                    }
                } else {
                    // Tarih yoksa ve filtre varsa gizle
                    if (startDate || endDate) {
                        showRow = false;
                    }
                }
            }
            
            row.style.display = showRow ? '' : 'none';
        }
    }
    </script>
</body>
</html>