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
$branch = $_SESSION["Branch2"]["Code"] ?? $_SESSION["WhsCode"] ?? $_SESSION["Branch2"]["Name"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// Kullanıcının şubesine ait depoları bul (FromWarehouse veya ToWarehouse olarak kullanılabilir)
// Ana depo (U_ASB2B_MAIN='1') ve sevkiyat deposu (U_ASB2B_MAIN='2')
$userWarehouses = [];
$warehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and (U_ASB2B_MAIN eq '1' or U_ASB2B_MAIN eq '2')";
$warehouseQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($warehouseFilter);
$warehouseData = $sap->get($warehouseQuery);

if (($warehouseData['status'] ?? 0) == 200) {
    $warehouses = $warehouseData['response']['value'] ?? $warehouseData['value'] ?? [];
    foreach ($warehouses as $whs) {
        $whsCode = $whs['WarehouseCode'] ?? '';
        if (!empty($whsCode)) {
            $userWarehouses[] = $whsCode;
        }
    }
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// Durum mapping
function getStatusText($status) {
    $statusMap = [
        '1' => 'Onay bekleniyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal edildi'
    ];
    return $statusMap[$status] ?? '-';
}

function getStatusClass($status) {
    $classMap = [
        '1' => 'status-warning',
        '2' => 'status-info',
        '3' => 'status-primary',
        '4' => 'status-success',
        '5' => 'status-danger'
    ];
    return $classMap[$status] ?? '';
}

// InventoryTransferRequests verilerini çek
// Tek taraflı sevkiyat: Sadece gönderen (FromWarehouse) veya alan (ToWarehouse) şube görebilir
// U_ASB2B_TYPE = 'TRANSFER' ve U_ASB2B_STATUS = '3' (Sevk edildi) veya '4' (Tamamlandı) olanlar
$select = "DocEntry,FromWarehouse,ToWarehouse,DocDate,DueDate,U_ASB2B_NumAtCard,U_ASB2B_STATUS";
$orderBy = "DocEntry desc";

// Filtreleme: FromWarehouse veya ToWarehouse kullanıcının şubesine ait depolardan biri olmalı
// U_ASB2B_TYPE = 'TRANSFER' olanlar (sevkiyat kayıtları)
if (!empty($userWarehouses)) {
    // OData filter: FromWarehouse veya ToWarehouse kullanıcının depolarından biri olmalı
    $warehouseFilterParts = [];
    foreach ($userWarehouses as $whsCode) {
        $warehouseFilterParts[] = "FromWarehouse eq '{$whsCode}' or ToWarehouse eq '{$whsCode}'";
    }
    $warehouseFilterStr = '(' . implode(' or ', $warehouseFilterParts) . ')';
    $filter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_TYPE eq 'TRANSFER' and {$warehouseFilterStr}";
} else {
    // Fallback: Sadece U_ASB2B_BRAN ve TYPE ile filtrele
    $filter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_TYPE eq 'TRANSFER'";
}

$query = "InventoryTransferRequests?\$select=" . urlencode($select) . "&\$filter=" . urlencode($filter) . "&\$orderby=" . urlencode($orderBy);

$transfersData = $sap->get($query);

$transfers = [];
if (($transfersData['status'] ?? 0) == 200) {
    if (isset($transfersData['response']['value'])) {
        $transfers = $transfersData['response']['value'];
    } elseif (isset($transfersData['value'])) {
        $transfers = $transfersData['value'];
    }
}

// PHP tarafında da filtreleme yap (ekstra güvenlik için)
if (!empty($userWarehouses) && !empty($transfers)) {
    $filteredTransfers = [];
    foreach ($transfers as $transfer) {
        $fromWhs = $transfer['FromWarehouse'] ?? '';
        $toWhs = $transfer['ToWarehouse'] ?? '';
        // FromWarehouse veya ToWarehouse kullanıcının depolarından biri olmalı
        if (in_array($fromWhs, $userWarehouses) || in_array($toWhs, $userWarehouses)) {
            $filteredTransfers[] = $transfer;
        }
    }
    $transfers = $filteredTransfers;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sevkiyat Listesi - MINOA</title>
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
            padding: 10px 14px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
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

        .entries-select:focus {
            outline: none;
            border-color: #3b82f6;
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

        .status-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .status-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-primary {
            background: #bfdbfe;
            color: #1e3a8a;
        }

        .status-success {
            background: #d1fae5;
            color: #065f46;
        }

        .status-danger {
            background: #fee2e2;
            color: #991b1b;
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
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        
        /* Single Select Dropdown Styles */
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
            margin-left: 8px;
        }
        
        .single-select-input.active .dropdown-arrow {
            transform: rotate(180deg);
        }
        
        .single-select-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            z-index: 9999;
            max-height: 200px;
            overflow-y: auto;
            display: none;
        }
        
        .single-select-dropdown.show {
            display: block;
        }
        
        .single-select-option {
            padding: 10px 14px;
            cursor: pointer;
            transition: background 0.15s ease;
            font-size: 14px;
            color: #374151;
        }
        
        .single-select-option:hover {
            background: #f3f4f6;
        }
        
        .single-select-option.selected {
            background: #eff6ff;
            color: #1e40af;
            font-weight: 600;
        }

        .btn-new {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
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
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .btn-new:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
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
            <h2>Sevkiyat Listesi</h2>
            <button class="btn-new" onclick="window.location.href='SevkiyatSO.php'">
                <span>+</span>
                <span>Yeni Sevkiyat Oluştur</span>
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
                        <label>Başlangıç Tarihi</label>
                        <input type="date" id="filter-start-date">
                    </div>
                    <div class="filter-group">
                        <label>Bitiş Tarihi</label>
                        <input type="date" id="filter-end-date">
                    </div>
                    <div class="filter-group">
                        <label>Durum</label>
                        <div class="single-select-container">
                            <div class="single-select-input" onclick="toggleDropdown('status')">
                                <input type="text" id="filter-status-display" value="Hepsi" placeholder="Seçiniz..." readonly>
                                <span class="dropdown-arrow">▼</span>
                            </div>
                            <div class="single-select-dropdown" id="statusDropdown">
                                <div class="single-select-option selected" data-value="" onclick="selectStatus('')">Hepsi</div>
                                <div class="single-select-option" data-value="1" onclick="selectStatus('1')">Onay bekleniyor</div>
                                <div class="single-select-option" data-value="2" onclick="selectStatus('2')">Hazırlanıyor</div>
                                <div class="single-select-option" data-value="3" onclick="selectStatus('3')">Sevk edildi</div>
                                <div class="single-select-option" data-value="4" onclick="selectStatus('4')">Tamamlandı</div>
                                <div class="single-select-option" data-value="5" onclick="selectStatus('5')">İptal edildi</div>
                            </div>
                        </div>
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
                                <th>Sevkiyat No</th>
                                <th>Gönderen Depo</th>
                                <th>Alan Depo</th>
                                <th>Talep Tarihi</th>
                                <th>Sevk / Planlanan Tarih</th>
                                <th>Referans / Belge No</th>
                                <th>Durum</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody id="table-body">
                            <?php if (empty($transfers)): ?>
                            <tr>
                                <td colspan="8" class="empty-message">Sevkiyat belgesi bulunamadı</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($transfers as $transfer): 
                                $transferStatus = $transfer['U_ASB2B_STATUS'] ?? '';
                                $transferToWarehouse = $transfer['ToWarehouse'] ?? '';
                                // Alan şube kontrolü: ToWarehouse kullanıcının depolarından biri mi?
                                $canReceiveInList = in_array($transferToWarehouse, $userWarehouses) && ($transferStatus == '3');
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($transfer['DocEntry'] ?? '') ?></td>
                                <td><?= htmlspecialchars($transfer['FromWarehouse'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($transfer['ToWarehouse'] ?? '-') ?></td>
                                <td><?= formatDate($transfer['DocDate'] ?? '') ?></td>
                                <td><?= formatDate($transfer['DueDate'] ?? '') ?></td>
                                <td><?= htmlspecialchars($transfer['U_ASB2B_NumAtCard'] ?? '-') ?></td>
                                <td>
                                    <span class="status-badge <?= getStatusClass($transferStatus) ?>">
                                        <?= getStatusText($transferStatus) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <a href="SevkiyatDetay.php?DocEntry=<?= $transfer['DocEntry'] ?? '' ?>" class="btn btn-primary">Detay</a>
                                        <?php if ($canReceiveInList): ?>
                                        <a href="Sevkiyat-TeslimAl.php?docEntry=<?= $transfer['DocEntry'] ?? '' ?>" class="btn btn-primary">Teslim Al</a>
                                        <?php endif; ?>
                                    </div>
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
        let userWarehouses = <?= json_encode($userWarehouses) ?>;

        // Durum seçimi için global değişken
        let selectedStatus = '';
        
        // Dropdown toggle
        function toggleDropdown(type) {
            const dropdown = document.getElementById(type + 'Dropdown');
            const input = document.querySelector(`#filter-${type}-display`).parentElement;
            const isOpen = dropdown.classList.contains('show');
            
            // Close all dropdowns
            document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
            document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
            
            if (!isOpen) {
                dropdown.classList.add('show');
                input.classList.add('active');
            }
        }
        
        // Durum seçimi
        function selectStatus(value) {
            selectedStatus = value;
            const statusText = document.querySelector(`#statusDropdown .single-select-option[data-value="${value}"]`).textContent;
            document.getElementById('filter-status-display').value = statusText;
            document.querySelectorAll('#statusDropdown .single-select-option').forEach(opt => opt.classList.remove('selected'));
            document.querySelector(`#statusDropdown .single-select-option[data-value="${value}"]`).classList.add('selected');
            applyFilters();
        }
        
        // Filtreleme
        function applyFilters() {
            const docEntry = document.getElementById('filter-doc-entry').value.toLowerCase();
            const startDate = document.getElementById('filter-start-date').value;
            const endDate = document.getElementById('filter-end-date').value;
            const status = selectedStatus;

            filteredData = allData.filter(item => {
                // Belge No filtresi
                if (docEntry && !String(item.DocEntry || '').toLowerCase().includes(docEntry)) {
                    return false;
                }

                // Tarih filtresi (başlangıç)
                if (startDate) {
                    const itemDate = item.DocDate ? item.DocDate.split('T')[0] : '';
                    if (itemDate < startDate) return false;
                }
                
                // Tarih filtresi (bitiş)
                if (endDate) {
                    const itemDate = item.DocDate ? item.DocDate.split('T')[0] : '';
                    if (itemDate > endDate) return false;
                }

                // Durum filtresi
                if (status && item.U_ASB2B_STATUS !== status) {
                    return false;
                }

                return true;
            });

            currentPage = 1;
            renderTable();
        }
        
        // Dışarı tıklandığında dropdown'ları kapat
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.single-select-container')) {
                document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
                document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
            }
        });

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
                    const dueDate = item.DueDate ? formatDate(item.DueDate) : '-';
                    const status = item.U_ASB2B_STATUS || '';
                    const statusText = getStatusText(status);
                    const statusClass = getStatusClass(status);

                    return `
                        <tr>
                            <td>${item.DocEntry || ''}</td>
                            <td>${item.FromWarehouse || '-'}</td>
                            <td>${item.ToWarehouse || '-'}</td>
                            <td>${docDate}</td>
                            <td>${dueDate}</td>
                            <td>${item.U_ASB2B_NumAtCard || '-'}</td>
                            <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <a href="SevkiyatDetay.php?DocEntry=${item.DocEntry || ''}" class="btn btn-primary">Detay</a>
                                    ${(item.ToWarehouse && userWarehouses.includes(item.ToWarehouse) && item.U_ASB2B_STATUS === '3') 
                                        ? `<a href="Sevkiyat-TeslimAl.php?docEntry=${item.DocEntry || ''}" class="btn btn-primary">Teslim Al</a>` 
                                        : ''}
                                </div>
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

        // Durum mapping
        function getStatusText(status) {
            const statusMap = {
                '1': 'Onay bekleniyor',
                '2': 'Hazırlanıyor',
                '3': 'Sevk edildi',
                '4': 'Tamamlandı',
                '5': 'İptal edildi'
            };
            return statusMap[status] || '-';
        }

        function getStatusClass(status) {
            const classMap = {
                '1': 'status-warning',
                '2': 'status-info',
                '3': 'status-primary',
                '4': 'status-success',
                '5': 'status-danger'
            };
            return classMap[status] || '';
        }

        // Event listeners
        document.getElementById('filter-doc-entry').addEventListener('input', applyFilters);
        document.getElementById('filter-start-date').addEventListener('change', applyFilters);
        document.getElementById('filter-end-date').addEventListener('change', applyFilters);
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
            window.scrollTo(0, 0);
            renderTable();
        });
    </script>
</body>
</html>

