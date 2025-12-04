<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// Filtreler
$filterStatus = $_GET['status'] ?? '';
$filterStartDate = $_GET['start_date'] ?? '';
$filterEndDate = $_GET['end_date'] ?? '';

// View'den veri çek
$filter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}'";
if (!empty($filterStatus)) {
    $filter .= " and U_ASB2B_STATUS eq '{$filterStatus}'";
}

$query = 'view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=' . urlencode($filter) . '&$orderby=' . urlencode('RequestNo desc') . '&$top=1000';
$data = $sap->get($query);
$allRows = $data['response']['value'] ?? [];

$statusPriorityMap = [
    '4' => 5,
    '3' => 4,
    '2' => 3,
    '1' => 2,
    '5' => 1
];

function getStatusPriority($status) {
    global $statusPriorityMap;
    $key = trim((string)$status);
    return $statusPriorityMap[$key] ?? 0;
}

function normalizeDateForFilter($date) {
    if (empty($date)) return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
        return substr($date, 0, 10);
    }
    return date('Y-m-d', strtotime($date));
}

function extractDocDateFromRow($row) {
    return $row['DocDate'] ?? $row['RequriedDate'] ?? $row['RequiredDate'] ?? $row['RequestDate'] ?? '';
}

$groupedRows = [];
foreach ($allRows as $row) {
    $requestNo = $row['RequestNo'] ?? '';
    if (empty($requestNo)) continue;
    
    $status = isset($row['U_ASB2B_STATUS']) ? (string)$row['U_ASB2B_STATUS'] : null;
    $statusPriority = getStatusPriority($status);
    $docDateValue = extractDocDateFromRow($row);
    
    if (!isset($groupedRows[$requestNo])) {
        $groupedRows[$requestNo] = [
            'RequestNo' => $requestNo,
            'DocDate' => $docDateValue,
            'StatusValue' => $status,
            'StatusPriority' => $statusPriority,
            'Orders' => []
        ];
    } else {
        if (empty($groupedRows[$requestNo]['DocDate']) && !empty($docDateValue)) {
            $groupedRows[$requestNo]['DocDate'] = $docDateValue;
        }
        if ($statusPriority > ($groupedRows[$requestNo]['StatusPriority'] ?? 0) && $status !== null) {
            $groupedRows[$requestNo]['StatusValue'] = $status;
            $groupedRows[$requestNo]['StatusPriority'] = $statusPriority;
        }
    }
    
    $orderNo = trim($row['U_ASB2B_ORNO'] ?? '');
    $orderDateValue = $row['U_ASB2B_ORDT'] ?? '';
    
    if ($orderNo !== '' && $orderNo !== '-') {
        if (!isset($groupedRows[$requestNo]['Orders'][$orderNo])) {
            $groupedRows[$requestNo]['Orders'][$orderNo] = [
                'OrderNo' => $orderNo,
                'OrderDate' => $orderDateValue,
                'Status' => $status,
                'StatusPriority' => $statusPriority,
                'DocDate' => $groupedRows[$requestNo]['DocDate']
            ];
        } else {
            $orderEntry =& $groupedRows[$requestNo]['Orders'][$orderNo];
            if (empty($orderEntry['OrderDate']) && !empty($orderDateValue)) {
                $orderEntry['OrderDate'] = $orderDateValue;
            }
            if ($statusPriority > ($orderEntry['StatusPriority'] ?? 0) && $status !== null) {
                $orderEntry['Status'] = $status;
                $orderEntry['StatusPriority'] = $statusPriority;
            }
        }
    }
}

$displayRows = [];

if (!empty($groupedRows)) {
    uksort($groupedRows, function($a, $b) {
        return intval($b) <=> intval($a);
    });
    
    foreach ($groupedRows as $requestNo => $group) {
        if (!empty($group['Orders'])) {
            uksort($group['Orders'], function($a, $b) {
                return intval($a) <=> intval($b);
            });
            
            foreach ($group['Orders'] as $orderData) {
                $displayRows[] = [
                    'RequestNo' => $requestNo,
                    'OrderNo' => $orderData['OrderNo'],
                    'DocDate' => $orderData['DocDate'] ?? $group['DocDate'] ?? '',
                    'OrderDate' => $orderData['OrderDate'] ?? '',
                    'Status' => $orderData['Status'] ?? null,
                    'HasOrder' => true
                ];
            }
        } else {
            $displayRows[] = [
                'RequestNo' => $requestNo,
                'OrderNo' => '-',
                'DocDate' => $group['DocDate'] ?? '',
                'OrderDate' => '',
                'Status' => $group['StatusValue'] ?? null,
                'HasOrder' => false
            ];
        }
    }
}

if (!empty($filterStartDate) || !empty($filterEndDate)) {
    $filteredRows = [];
    foreach ($displayRows as $row) {
        $requestDateForFilter = normalizeDateForFilter($row['DocDate'] ?? '');
        $showRow = true;
        
        if (!empty($filterStartDate)) {
            if (empty($requestDateForFilter) || $requestDateForFilter < $filterStartDate) {
                $showRow = false;
            }
        }
        
        if ($showRow && !empty($filterEndDate)) {
            if (empty($requestDateForFilter) || $requestDateForFilter > $filterEndDate) {
                $showRow = false;
            }
        }
        
        if ($showRow) {
            $filteredRows[] = $row;
        }
    }
    $displayRows = $filteredRows;
}

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

function buildSearchData(...$parts) {
    $textParts = [];
    foreach ($parts as $part) {
        if (!empty($part) && $part !== '-') {
            $textParts[] = $part;
        }
    }
    $text = implode(' ', $textParts);
    return mb_strtolower($text ?? '', 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dış Tedarik - MINOA</title>
    <link rel="stylesheet" href="styles.css">
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

/* Main content - adjusted for sidebar */
.main-content {
    width: 100%;
    background: whitesmoke;
    padding: 0;
    min-height: 100vh;
}

.sidebar.expanded ~ .main-content {
    margin-left: 260px;
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
    padding: 32px;
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

.alert {
    padding: 16px 20px;
    border-radius: 8px;
    margin-bottom: 24px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 14px;
    line-height: 1.5;
    position: relative;
}

.alert-success {
    background: #d1fae5;
    border: 1px solid #10b981;
    color: #065f46;
}

.alert-warning {
    background: #fef3c7;
    border: 1px solid #fbbf24;
    color: #92400e;
}

.alert-danger {
    background: #fee2e2;
    border: 1px solid #ef4444;
    color: #991b1b;
}

.alert ul {
    margin-top: 0.5rem;
    margin-left: 1.5rem;
}

.alert li {
    margin-top: 0.25rem;
}

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

/* Table Controls */
.table-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #6b7280;
}

.entries-select {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.entries-select:hover {
    border-color: #3b82f6;
}

.entries-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.search-box {
    display: flex;
    gap: 8px;
    align-items: center;
}

.search-input {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    min-width: 220px;
    transition: border-color 0.2s;
}

.search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.table-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #6b7280;
}

.entries-select {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.entries-select:hover {
    border-color: #3b82f6;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
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
}

.data-table td {
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    color: #374151;
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
    background: #bfdbfe;
    color: #1e3a8a;
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

.btn-secondary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-icon {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-view {
    background: #f0f9ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}

.btn-view:hover {
    background: #dbeafe;
}

.btn-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #10b981;
}

.btn-success:hover {
    background: #a7f3d0;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 12px;
    padding: 24px;
    font-size: 14px;
    color: #6b7280;
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 60px;
    }
    
    .sidebar.expanded ~ .main-content {
        margin-left: 220px;
    }
    
    .content-wrapper {
        padding: 20px;
    }
    
    .page-header {
        padding: 16px 20px;
        flex-direction: column;
        gap: 12px;
        align-items: flex-start;
    }
    
    .filter-section {
        grid-template-columns: 1fr;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Dış Tedarik Talepleri</h2>
            <button class="btn btn-primary" onclick="window.location.href='DisTedarikSO.php'">+ Yeni Talep Oluştur</button>
        </header>

        <div class="content-wrapper">
            <?php
            // Session'dan bildirim mesajlarını göster ve temizle
            if (isset($_SESSION['success_message'])) {
                echo '<div class="alert alert-success" id="successAlert">✅ ' . htmlspecialchars($_SESSION['success_message']) . '</div>';
                unset($_SESSION['success_message']);
            }
            if (isset($_SESSION['warning_message'])) {
                echo '<div class="alert alert-warning" id="warningAlert">⚠️ ' . htmlspecialchars($_SESSION['warning_message']);
                if (isset($_SESSION['error_details']) && is_array($_SESSION['error_details'])) {
                    echo '<ul style="margin-top: 0.5rem; margin-left: 1.5rem;">';
                    foreach ($_SESSION['error_details'] as $error) {
                        echo '<li>' . htmlspecialchars($error) . '</li>';
                    }
                    echo '</ul>';
                    unset($_SESSION['error_details']);
                }
                echo '</div>';
                unset($_SESSION['warning_message']);
            }
            if (isset($_SESSION['error_message'])) {
                echo '<div class="alert alert-danger" id="errorAlert">❌ ' . htmlspecialchars($_SESSION['error_message']);
                if (isset($_SESSION['error_details']) && is_array($_SESSION['error_details'])) {
                    echo '<ul style="margin-top: 0.5rem; margin-left: 1.5rem;">';
                    foreach ($_SESSION['error_details'] as $error) {
                        echo '<li>' . htmlspecialchars($error) . '</li>';
                    }
                    echo '</ul>';
                    unset($_SESSION['error_details']);
                }
                echo '</div>';
                unset($_SESSION['error_message']);
            }
            // Eski GET parametresi desteği (geriye dönük uyumluluk)
            if (isset($_GET['msg']) && $_GET['msg'] === 'teslim_alindi') {
                echo '<div class="alert alert-success">✅ Teslim alma işlemi başarıyla tamamlandı!</div>';
            }
            ?>

            <section class="card">
                <div class="filter-section">
                    <div class="filter-group">
                        <label>Durum</label>
                        <div class="single-select-container">
                            <div class="single-select-input" onclick="toggleDropdown('status')">
                                <input type="text" id="filterStatus" value="<?= $filterStatus ? getStatusText($filterStatus) : 'Tümü' ?>" placeholder="Seçiniz..." readonly>
                                <span class="dropdown-arrow">▼</span>
                            </div>
                            <div class="single-select-dropdown" id="statusDropdown">
                                <div class="single-select-option <?= empty($filterStatus) ? 'selected' : '' ?>" data-value="" onclick="selectStatus('')">Tümü</div>
                                <div class="single-select-option <?= $filterStatus === '1' ? 'selected' : '' ?>" data-value="1" onclick="selectStatus('1')">Onay bekleniyor</div>
                                <div class="single-select-option <?= $filterStatus === '2' ? 'selected' : '' ?>" data-value="2" onclick="selectStatus('2')">Hazırlanıyor</div>
                                <div class="single-select-option <?= $filterStatus === '3' ? 'selected' : '' ?>" data-value="3" onclick="selectStatus('3')">Sevk edildi</div>
                                <div class="single-select-option <?= $filterStatus === '4' ? 'selected' : '' ?>" data-value="4" onclick="selectStatus('4')">Tamamlandı</div>
                                <div class="single-select-option <?= $filterStatus === '5' ? 'selected' : '' ?>" data-value="5" onclick="selectStatus('5')">İptal edildi</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label>Başlangıç Tarihi</label>
                        <input type="date" id="start-date" value="<?= htmlspecialchars($filterStartDate) ?>" onblur="applyFilters()">
                    </div>
                    
                    <div class="filter-group">
                        <label>Bitiş Tarihi</label>
                        <input type="date" id="end-date" value="<?= htmlspecialchars($filterEndDate) ?>" onblur="applyFilters()">
                    </div>
                </div>
            </section>
            
            <section class="card">
                <div class="table-controls">
                    <div class="show-entries">
                        Sayfada 
                        <select class="entries-select" id="entriesPerPage" onchange="applyFilters()">
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="75">75</option>
                        </select>
                        kayıt göster
                    </div>
                    <div class="search-box">
                        <input type="text" class="search-input" id="tableSearch" placeholder="Ara..." onkeyup="if(event.key==='Enter') performSearch()">
                        <button class="btn btn-secondary" onclick="performSearch()">🔍</button>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Talep No</th>
                            <th>Sipariş No</th>
                            <th>Talep Tarihi</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody id="requestTableBody">
                        <?php if (!empty($displayRows)): ?>
                            <?php foreach ($displayRows as $rowData): ?>
                                <?php
                                    $requestNo = $rowData['RequestNo'];
                                    $orderNoDisplay = $rowData['OrderNo'] ?? '-';
                                    $docDateValue = $rowData['DocDate'] ?? '';
                                    $orderDateValue = $rowData['OrderDate'] ?? '';
                                    $statusValue = $rowData['Status'] ?? null;
                                    $statusText = $statusValue !== null ? getStatusText($statusValue) : 'Bilinmiyor';
                                    $statusClass = $statusValue !== null ? getStatusClass($statusValue) : 'status-unknown';
                                    $hasOrder = !empty($rowData['HasOrder']);
                                    $docDate = !empty($docDateValue) ? formatDate($docDateValue) : '-';
                                    $orderDate = !empty($orderDateValue) ? formatDate($orderDateValue) : '-';
                                    $detailUrl = 'DisTedarik-Detay.php?requestNo=' . urlencode($requestNo);
                                    if ($hasOrder && $orderNoDisplay !== '-' && $orderNoDisplay !== '') {
                                        $detailUrl .= '&orderNo=' . urlencode($orderNoDisplay);
                                    }
                                ?>
                                <?php
                                    $searchData = buildSearchData($requestNo, $orderNoDisplay, $docDate, $orderDate, $statusText);
                                ?>
                                <tr data-row data-search="<?= htmlspecialchars($searchData, ENT_QUOTES, 'UTF-8') ?>">
                                    <td><?= htmlspecialchars($requestNo) ?></td>
                                    <td><?= htmlspecialchars($orderNoDisplay) ?></td>
                                    <td><?= $docDate ?></td>
                                    <td><span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                                    <td>
                                        <a href="<?= $detailUrl ?>">
                                            <button class="btn-icon btn-view">👁️ Detay</button>
                                        </a>
                                        <?php if ($hasOrder && isReceivableStatus($statusValue)): ?>
                                            <a href="DisTedarik-TeslimAl.php?requestNo=<?= urlencode($requestNo) ?>&orderNos=<?= urlencode($orderNoDisplay) ?>">
                                                <button class="btn-icon btn-primary">✓ Teslim Al</button>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center;padding:2rem;color:#9ca3af;">Kayıt bulunamadı.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>
    
    <script>
        // Bildirimlerin otomatik kapanması
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s ease-out';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 500);
                }, 5000); // 5 saniye sonra kapan
            });
        });
    </script>
    <script>
let selectedStatus = '<?= htmlspecialchars($filterStatus) ?>';

function toggleDropdown(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    const input = document.querySelector(`#filter${type.charAt(0).toUpperCase() + type.slice(1)}`).parentElement;
    const isOpen = dropdown.classList.contains('show');
    
    document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
    document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
    
    if (!isOpen) {
        dropdown.classList.add('show');
        input.classList.add('active');
    }
}

function selectStatus(value) {
    selectedStatus = value;
    const statusText = document.querySelector(`#statusDropdown .single-select-option[data-value="${value}"]`).textContent;
    document.getElementById('filterStatus').value = statusText;
    document.querySelectorAll('#statusDropdown .single-select-option').forEach(opt => opt.classList.remove('selected'));
    document.querySelector(`#statusDropdown .single-select-option[data-value="${value}"]`).classList.add('selected');
    applyFilters();
}

function applyFilters() {
    const status = selectedStatus;
    const startDate = document.getElementById('start-date').value;
    const endDate = document.getElementById('end-date').value;
    const entriesPerPage = document.getElementById('entriesPerPage')?.value || '25';
    
    const params = new URLSearchParams();
    if (status) params.append('status', status);
    if (startDate) params.append('start_date', startDate);
    if (endDate) params.append('end_date', endDate);
    if (entriesPerPage) params.append('entries', entriesPerPage);
    
    window.location.href = 'DisTedarik.php' + (params.toString() ? '?' + params.toString() : '');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.single-select-container')) {
        document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
    }
});
    </script>
    <script>
// Genel arama fonksiyonu - tüm kolonlarda serbest text search
function performSearch() {
    const searchInput = document.getElementById('tableSearch');
    const searchTerm = searchInput.value.toLowerCase().trim();
    const tableBody = document.querySelector('.data-table tbody');
    const rows = tableBody.querySelectorAll('tr[data-row]');
    
    if (!tableBody || !rows) return;
    
    let visibleCount = 0;
    
    rows.forEach(row => {
        // Tüm hücrelerde ara (Talep No, Sipariş No, Talep Tarihi, Durum)
        const cells = row.querySelectorAll('td');
        let found = false;
        
        if (searchTerm === '') {
            // Arama boşsa tüm satırları göster
            row.style.display = '';
            visibleCount++;
        } else {
            cells.forEach(cell => {
                const cellText = cell.textContent.toLowerCase();
                if (cellText.includes(searchTerm)) {
                    found = true;
                }
            });
            
            if (found) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        }
    });
    
    // Eğer hiç sonuç yoksa mesaj göster
    let noResultsRow = tableBody.querySelector('.no-results-message');
    if (searchTerm !== '' && visibleCount === 0) {
        if (!noResultsRow) {
            noResultsRow = document.createElement('tr');
            noResultsRow.className = 'no-results-message';
            noResultsRow.innerHTML = '<td colspan="5" style="text-align: center; padding: 20px; color: #888;">Sonuç bulunamadı.</td>';
            tableBody.appendChild(noResultsRow);
        }
        noResultsRow.style.display = '';
    } else {
        if (noResultsRow) {
            noResultsRow.style.display = 'none';
        }
    }
}

// Arama input'una real-time arama ekle
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('tableSearch');
    if (searchInput) {
        // Her tuş vuruşunda arama yap (debounce olmadan)
        searchInput.addEventListener('input', function() {
            performSearch();
        });
    }
});
    </script>
</body>
</html>