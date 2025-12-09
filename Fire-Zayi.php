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
$branch = $_SESSION["Branch2"]["Code"] ?? $_SESSION["WhsCode"] ?? '100'; // Şube kodu

if (empty($uAsOwnr)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// Tür mapping
function getTypeText($lost) {
    if ($lost == '1') return 'Fire';
    if ($lost == '2') return 'Zayi';
    return '-';
}

function getTypeClass($lost) {
    if ($lost == '1') return 'status-fire';
    if ($lost == '2') return 'status-zayi';
    return '';
}

// StockTransfers verilerini çek
// NOT: Hem 'TRANSFER' hem 'MAIN' tipindeki belgeleri kabul et
// NOT: Header'da U_ASB2B_LOST olan belgeleri getir
$select = "DocEntry,Series,DocDate,FromWarehouse,ToWarehouse,Printed,U_ASB2B_LOST,U_AS_OWNR,U_ASB2B_BRAN";
$filter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and (U_ASB2B_TYPE eq 'TRANSFER' or U_ASB2B_TYPE eq 'MAIN') and (U_ASB2B_LOST eq '1' or U_ASB2B_LOST eq '2')";
$orderBy = "DocEntry desc";

$query = "StockTransfers?\$select=" . urlencode($select) . "&\$filter=" . urlencode($filter) . "&\$orderby=" . urlencode($orderBy);

$transfersData = $sap->get($query);

$transfers = [];
if (($transfersData['status'] ?? 0) == 200) {
    if (isset($transfersData['response']['value'])) {
        $transfers = $transfersData['response']['value'];
    } elseif (isset($transfersData['value'])) {
        $transfers = $transfersData['value'];
    }
}

// Her transfer için doğru Fire/Zayi deposunu bul
foreach ($transfers as &$transfer) {
    $lost = $transfer['U_ASB2B_LOST'] ?? '';
    $transferBranch = $transfer['U_ASB2B_BRAN'] ?? $branch;
    
    $fireZayiWarehouse = '-';
    
    if ($lost == '1') {
        // Fire ise U_ASB2B_MAIN='3'
        $fireFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$transferBranch}' and U_ASB2B_MAIN eq '3'";
        $fireQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($fireFilter);
        $fireData = $sap->get($fireQuery);
        
        if (($fireData['status'] ?? 0) == 200) {
            $fireList = $fireData['response']['value'] ?? [];
            if (!empty($fireList)) {
                $fireZayiWarehouse = $fireList[0]['WarehouseCode'] ?? '-';
            }
        }
    } elseif ($lost == '2') {
        // Zayi ise U_ASB2B_MAIN='4'
        $zayiFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$transferBranch}' and U_ASB2B_MAIN eq '4'";
        $zayiQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($zayiFilter);
        $zayiData = $sap->get($zayiQuery);
        
        if (($zayiData['status'] ?? 0) == 200) {
            $zayiList = $zayiData['response']['value'] ?? [];
            if (!empty($zayiList)) {
                $fireZayiWarehouse = $zayiList[0]['WarehouseCode'] ?? '-';
            }
        }
    }
    
    // Giriş depo olarak Fire/Zayi deposunu set et
    $transfer['_ToWarehouse'] = $fireZayiWarehouse;
}
unset($transfer); // Reference'ı temizle
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fire ve Zayi Listesi - MINOA</title>
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
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            margin-bottom: 24px;
            overflow: visible;
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

        .filter-group input[type="text"],
        .filter-group input[type="date"],
        .filter-group select {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s ease;
            background: white;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .table-controls {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            margin-bottom: 0;
        }

        .show-entries {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #6b7280;
        }

        .entries-select {
            padding: 6px 10px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }

        .entries-select:focus {
            outline: none;
            border-color: #3b82f6;
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
        }

        .search-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn-new {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);
        }

        .btn-new:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
        }

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .data-table thead {
            background: #f8fafc;
        }

        .data-table th {
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #4b5563;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
            transition: background 0.15s;
        }

        .data-table tbody tr:hover {
            background: #f9fafb;
        }

        .data-table td {
            padding: 12px 16px;
            color: #374151;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-fire {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-zayi {
            background: #fef3c7;
            color: #92400e;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-secondary:hover {
            background: #4b5563;
        }

        .empty-message {
            text-align: center;
            padding: 3rem;
            color: #9ca3af;
            font-size: 14px;
        }

        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-top: 1px solid #e5e7eb;
        }

        .pagination-info {
            font-size: 14px;
            color: #6b7280;
        }

        .pagination-controls {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .pagination-btn {
            padding: 8px 12px;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .pagination-btn:hover:not(:disabled) {
            background: #f8fafc;
            border-color: #3b82f6;
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    <main class="main-content">
        <header class="page-header">
            <h2>Fire ve Zayi Listesi</h2>
            <button class="btn-new" onclick="window.location.href='Fire-ZayiSO.php'">
                <span>+</span>
                <span>Yeni Fire/Zayi Ekle</span>
            </button>
        </header>

        <div class="content-wrapper">
            <!-- Filtreler -->
            <div class="card">
                <div class="filter-section">
                    <div class="filter-group">
                        <label>Belge No</label>
                        <input type="text" id="filter-doc-entry" placeholder="Belge no girin...">
                    </div>
                    <div class="filter-group">
                        <label>Tarih</label>
                        <input type="date" id="filter-date">
                    </div>
                    <div class="filter-group">
                        <label>Tür</label>
                        <select id="filter-type">
                            <option value="">Hepsi</option>
                            <option value="1">Fire</option>
                            <option value="2">Zayi</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Tablo -->
            <div class="card">
                <!-- Tablo Kontrolleri -->
                <div class="table-controls">
                    <div class="show-entries">
                        <span>Sayfada</span>
                        <select class="entries-select" id="entries-per-page">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span>kayıt göster</span>
                    </div>
                </div>

                <!-- Tablo -->
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Belge No</th>
                                <th>Seri</th>
                                <th>Tarih</th>
                                <th>Çıkış Depo</th>
                                <th>Giriş Depo</th>
                                <th>Tür</th>
                                <th>Yazdırıldı</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody id="table-body">
                            <?php if (empty($transfers)): ?>
                            <tr>
                                <td colspan="8" class="empty-message">Fire/Zayi belgesi bulunamadı</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($transfers as $transfer): ?>
                            <tr>
                                <td><?= htmlspecialchars($transfer['DocEntry'] ?? '') ?></td>
                                <td><?= htmlspecialchars($transfer['Series'] ?? '-') ?></td>
                                <td><?= formatDate($transfer['DocDate'] ?? '') ?></td>
                                <td><?= htmlspecialchars($transfer['FromWarehouse'] ?? $transfer['FromWhs'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($transfer['_ToWarehouse'] ?? $transfer['ToWarehouse'] ?? $transfer['ToWhs'] ?? '-') ?></td>
                                <td>
                                    <span class="status-badge <?= getTypeClass($transfer['U_ASB2B_LOST'] ?? '') ?>">
                                        <?= getTypeText($transfer['U_ASB2B_LOST'] ?? '') ?>
                                    </span>
                                </td>
                                <td><?= ($transfer['Printed'] ?? 'N') == 'Y' ? 'Evet' : 'Hayır' ?></td>
                                <td>
                                    <a href="Fire-ZayiDetay.php?DocEntry=<?= $transfer['DocEntry'] ?? '' ?>" class="btn btn-primary">Detay</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Sayfalama -->
                <div class="pagination">
                    <div class="pagination-info">
                        <span id="pagination-info"><?= count($transfers) ?> kayıt gösteriliyor</span>
                    </div>
                    <div class="pagination-controls">
                        <button class="pagination-btn" id="prev-page" disabled>Önceki</button>
                        <span id="page-numbers">Sayfa 1</span>
                        <button class="pagination-btn" id="next-page" disabled>Sonraki</button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        let allData = <?= json_encode($transfers) ?>;
        let filteredData = [...allData];
        let currentPage = 1;
        let entriesPerPage = 25;

        // Filtreleme
        function applyFilters() {
            const docEntry = document.getElementById('filter-doc-entry').value.toLowerCase();
            const date = document.getElementById('filter-date').value;
            const type = document.getElementById('filter-type').value;

            filteredData = allData.filter(item => {
                // Belge No filtresi
                if (docEntry && !String(item.DocEntry || '').toLowerCase().includes(docEntry)) {
                    return false;
                }

                // Tarih filtresi
                if (date) {
                    const itemDate = item.DocDate ? item.DocDate.split('T')[0] : '';
                    if (itemDate !== date) return false;
                }

                // Tür filtresi
                if (type && item.U_ASB2B_LOST !== type) {
                    return false;
                }

                return true;
            });

            currentPage = 1;
            renderTable();
        }

        // Tablo render
        function renderTable() {
            const tbody = document.getElementById('table-body');
            const start = (currentPage - 1) * entriesPerPage;
            const end = start + entriesPerPage;
            const pageData = filteredData.slice(start, end);

            if (pageData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" class="empty-message">Kayıt bulunamadı</td></tr>';
            } else {
                tbody.innerHTML = pageData.map(item => {
                    const docDate = item.DocDate ? formatDate(item.DocDate) : '-';
                    const fromWhs = item.FromWarehouse || item.FromWhs || '-';
                    const toWhs = item._ToWarehouse || item.ToWarehouse || item.ToWhs || '-';
                    const lost = item.U_ASB2B_LOST || '';
                    const typeText = lost === '1' ? 'Fire' : lost === '2' ? 'Zayi' : '-';
                    const typeClass = lost === '1' ? 'status-fire' : lost === '2' ? 'status-zayi' : '';
                    const printed = (item.Printed || 'N') === 'Y' ? 'Evet' : 'Hayır';

                    return `
                        <tr>
                            <td>${item.DocEntry || ''}</td>
                            <td>${item.Series || '-'}</td>
                            <td>${docDate}</td>
                            <td>${fromWhs}</td>
                            <td>${toWhs}</td>
                            <td><span class="status-badge ${typeClass}">${typeText}</span></td>
                            <td>${printed}</td>
                            <td>
                                <a href="Fire-ZayiDetay.php?DocEntry=${item.DocEntry || ''}" class="btn btn-primary">Detay</a>
                            </td>
                        </tr>
                    `;
                }).join('');
            }

            renderPagination();
        }

        // Sayfalama render
        function renderPagination() {
            const totalPages = Math.ceil(filteredData.length / entriesPerPage);
            const start = (currentPage - 1) * entriesPerPage + 1;
            const end = Math.min(currentPage * entriesPerPage, filteredData.length);

            document.getElementById('pagination-info').textContent = 
                `${start}-${end} / ${filteredData.length} kayıt gösteriliyor`;

            document.getElementById('prev-page').disabled = currentPage === 1;
            document.getElementById('next-page').disabled = currentPage === totalPages || totalPages === 0;

            const pageNumbers = document.getElementById('page-numbers');
            if (totalPages <= 1) {
                pageNumbers.textContent = 'Sayfa 1';
            } else {
                pageNumbers.textContent = `Sayfa ${currentPage} / ${totalPages}`;
            }
        }

        // Sayfa değiştir
        function goToPage(page) {
            const totalPages = Math.ceil(filteredData.length / entriesPerPage);
            if (page < 1 || page > totalPages) return;
            currentPage = page;
            renderTable();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Tarih formatlama
        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleDateString('tr-TR');
        }

        // Event listeners
        document.getElementById('filter-doc-entry').addEventListener('input', applyFilters);
        document.getElementById('filter-date').addEventListener('change', applyFilters);
        document.getElementById('filter-type').addEventListener('change', applyFilters);
        document.getElementById('entries-per-page').addEventListener('change', function() {
            entriesPerPage = parseInt(this.value);
            currentPage = 1;
            renderTable();
        });

        document.getElementById('prev-page').addEventListener('click', () => {
            if (currentPage > 1) goToPage(currentPage - 1);
        });

        document.getElementById('next-page').addEventListener('click', () => {
            const totalPages = Math.ceil(filteredData.length / entriesPerPage);
            if (currentPage < totalPages) goToPage(currentPage + 1);
        });

        // Sayfa yüklendiğinde
        document.addEventListener('DOMContentLoaded', function() {
            // Scroll pozisyonunu sıfırla (navbar kaymasını önle)
            window.scrollTo(0, 0);
            renderTable();
        });
    </script>
</body>
</html>
