<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

// Session'dan U_AS_OWNR bilgisini al
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';

if (empty($uAsOwnr)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// Status mapping - SAP B1SL'de InventoryCountings için DocumentStatus değerleri
function getStatusText($status) {
    $statusMap = [
        'bost_Open' => 'Açık',
        'bost_Close' => 'Kapalı',
        'cdsOpen' => 'Açık',      // Counting Document Status Open
        'cdsClosed' => 'Kapalı',  // Counting Document Status Closed
        'cds_Open' => 'Açık',
        'cds_Closed' => 'Kapalı',
        'Open' => 'Açık',
        'Closed' => 'Kapalı'
    ];
    return $statusMap[$status] ?? ($status ?: 'Bilinmiyor');
}

function getStatusClass($status) {
    $classMap = [
        'bost_Open' => 'status-processing',
        'bost_Close' => 'status-completed',
        'cdsOpen' => 'status-processing',
        'cdsClosed' => 'status-completed',
        'cds_Open' => 'status-processing',
        'cds_Closed' => 'status-completed',
        'Open' => 'status-processing',
        'Closed' => 'status-completed'
    ];
    // Açık olanlar için processing, kapalı olanlar için completed
    if (stripos($status, 'open') !== false || stripos($status, 'açık') !== false) {
        return 'status-processing';
    }
    if (stripos($status, 'close') !== false || stripos($status, 'kapalı') !== false) {
        return 'status-completed';
    }
    return $classMap[$status] ?? 'status-unknown';
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// InventoryCountings verilerini çek
$inventoryCountingsSelect = "DocumentEntry,CountDate,Remarks,DocumentStatus,U_AS_OWNR";
$inventoryCountingsFilter = "U_AS_OWNR eq '{$uAsOwnr}'";
$inventoryCountingsOrderBy = "DocumentEntry desc";
$inventoryCountingsQuery = "InventoryCountings?\$select=" . urlencode($inventoryCountingsSelect) . "&\$filter=" . urlencode($inventoryCountingsFilter) . "&\$orderby=" . urlencode($inventoryCountingsOrderBy);

$inventoryCountingsData = $sap->get($inventoryCountingsQuery);

// DEBUG: Filtre olmadan son 5 sayımı çek (yeni oluşturulanları görmek için)
$allCountingsQuery = "InventoryCountings?\$select=DocumentEntry,CountDate,Remarks,DocumentStatus,U_AS_OWNR&\$orderby=DocumentEntry desc&\$top=5";
$allCountingsData = $sap->get($allCountingsQuery);
$allCountings = [];
if (($allCountingsData['status'] ?? 0) == 200) {
    if (isset($allCountingsData['response']['value'])) {
        $allCountings = $allCountingsData['response']['value'];
    } elseif (isset($allCountingsData['value'])) {
        $allCountings = $allCountingsData['value'];
    }
}

$inventoryCountings = [];
$debugInfo = [
    'uAsOwnr' => $uAsOwnr,
    'query' => $inventoryCountingsQuery,
    'status' => $inventoryCountingsData['status'] ?? 'NO STATUS',
    'response_structure' => 'unknown',
    'count' => 0,
    'raw_response' => null,
    'all_countings_check' => [
        'query' => $allCountingsQuery,
        'status' => $allCountingsData['status'] ?? 'NO STATUS',
        'count' => count($allCountings),
        'records' => $allCountings,
        'u_as_ownr_values' => array_map(function($c) {
            return [
                'DocumentEntry' => $c['DocumentEntry'] ?? 'N/A',
                'U_AS_OWNR' => $c['U_AS_OWNR'] ?? 'NULL veya YOK'
            ];
        }, $allCountings)
    ]
];

if (($inventoryCountingsData['status'] ?? 0) == 200) {
    if (isset($inventoryCountingsData['response']['value'])) {
        $inventoryCountings = $inventoryCountingsData['response']['value'];
        $debugInfo['response_structure'] = 'response.value';
    } elseif (isset($inventoryCountingsData['value'])) {
        $inventoryCountings = $inventoryCountingsData['value'];
        $debugInfo['response_structure'] = 'value';
    } elseif (isset($inventoryCountingsData['response']) && is_array($inventoryCountingsData['response'])) {
        $inventoryCountings = $inventoryCountingsData['response'];
        $debugInfo['response_structure'] = 'response (direct array)';
    }
    
    $debugInfo['count'] = count($inventoryCountings);
    $debugInfo['raw_response'] = $inventoryCountingsData['response'] ?? $inventoryCountingsData;
    
    // İlk 2 kaydın özetini göster
    if (!empty($inventoryCountings)) {
        $debugInfo['sample_records'] = array_slice($inventoryCountings, 0, 2);
    }
} else {
    $debugInfo['error'] = $inventoryCountingsData['response']['error'] ?? $inventoryCountingsData['error'] ?? 'Bilinmeyen hata';
    $debugInfo['raw_response'] = $inventoryCountingsData;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Sayım Listesi - MINOA</title>
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

.btn-icon {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-block;
}

.btn-view {
    background: #f0f9ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}

.btn-view:hover {
    background: #dbeafe;
}

.btn-update {
    background: #3b82f6;
    color: white;
    border: 1px solid #2563eb;
}

.btn-update:hover {
    background: #2563eb;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

/* Table Styles */
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

/* Status Badges */
.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-processing {
    background: #d1fae5;
    color: #065f46;
}

.status-completed {
    background: #f3f4f6;
    color: #6b7280;
}

.status-unknown {
    background: #f3f4f6;
    color: #6b7280;
}

.action-buttons {
    display: flex;
    gap: 8px;
    align-items: center;
}

@media (max-width: 768px) {
    .content-wrapper {
        padding: 16px 20px;
    }
    
    .table-controls {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
    }
    
    .data-table {
        font-size: 12px;
    }
    
    .data-table th,
    .data-table td {
        padding: 12px 10px;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 4px;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Stok Sayım Listesi</h2>
            <button class="btn btn-primary" onclick="window.location.href='StokSayimSO.php'">+ Yeni Sayım Oluştur</button>
        </header>

        <div class="content-wrapper">
            <section class="card">
                <div class="table-controls">
                    <div class="show-entries">
                        Sayfada 
                        <select class="entries-select" id="entriesPerPage">
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        kayıt göster
                    </div>
                    <div class="search-box">
                        <input type="text" class="search-input" id="tableSearch" placeholder="Ara..." onkeyup="if(event.key==='Enter') performSearch()">
                        <button class="btn btn-secondary" onclick="performSearch()">🔍</button>
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Doküman No</th>
                                <th>Sayım Tarihi</th>
                                <th>Açıklama</th>
                                <th>Durum</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php if (empty($inventoryCountings)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px; color: #6b7280;">
                                    Henüz stok sayımı bulunmamaktadır.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($inventoryCountings as $counting): ?>
                            <tr>
                                <td><?= htmlspecialchars($counting['DocumentEntry'] ?? '') ?></td>
                                <td><?= formatDate($counting['CountDate'] ?? '') ?></td>
                                <td><?= htmlspecialchars($counting['Remarks'] ?? '') ?></td>
                                <td>
                                    <?php 
                                    $status = $counting['DocumentStatus'] ?? '';
                                    $statusText = getStatusText($status);
                                    $statusClass = getStatusClass($status);
                                    ?>
                                    <span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="StokSayimDetay.php?DocumentEntry=<?= $counting['DocumentEntry'] ?>" class="btn-icon btn-view">Detay</a>
                                        <?php 
                                        // Açık olanlar için Güncelle butonu göster
                                        $isOpen = in_array($status, ['bost_Open', 'cdsOpen', 'cds_Open', 'Open']) || 
                                                  (stripos($status, 'open') !== false);
                                        if ($isOpen): 
                                        ?>
                                        <a href="StokSayimSO.php?DocumentEntry=<?= $counting['DocumentEntry'] ?>&continue=1" class="btn-icon btn-update">Güncelle</a>
                                        <?php else: ?>
                                        <span style="color: #9ca3af;">-</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            
            <!-- Debug Panel -->
            <section class="card" style="margin-top: 24px;">
                <div style="padding: 20px; background: #f8f9fa; border-radius: 8px;">
                    <h3 style="margin-bottom: 16px; color: #1e40af;">🔍 Debug Bilgileri</h3>
                    <div style="background: white; padding: 16px; border-radius: 6px; border: 1px solid #e5e7eb;">
                        <pre style="margin: 0; font-size: 12px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word;"><?= htmlspecialchars(json_encode($debugInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                    </div>
                </div>
            </section>
        </div>
    </main>

    <script>
        function performSearch() {
            const searchTerm = document.getElementById('tableSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#tableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        }

        document.getElementById('tableSearch').addEventListener('input', function(e) {
            performSearch();
        });
    </script>
</body>
</html>
