<?php
session_start();
if (!isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

$whsCode = $_SESSION["1000"] ?? '1000';
$userName = $_SESSION["UserName"] ?? 'manager';

$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

// ✅ AJAX filtre sorgusu
if ($isAjax) {
    header('Content-Type: application/json');

    $itemName = trim($_GET['item_name'] ?? '');
    $itemGroup = trim($_GET['item_group'] ?? '');
    $branch = trim($_GET['branch'] ?? '');
    $stockStatus = trim($_GET['stock_status'] ?? '');
    $query = "SQLQueries('OWTQ_T_NEW')/List?value1='TRANSFER'&value2='{$_SESSION["1000"]}'";

    if ($itemName !== '') $query .= "&value3='" . urlencode($itemName) . "'";
    if ($itemGroup !== '') $query .= "&value4='" . urlencode($itemGroup) . "'";
    if ($branch !== '') $query .= "&value5='" . urlencode($branch) . "'";
    if ($stockStatus !== '') $query .= "&value6='" . urlencode($stockStatus) . "'";

    $data = $sap->get($query);
    $rows = $data['response']['value'] ?? [];

    echo json_encode([
        'success' => true,
        'data' => $rows,
        'count' => count($rows)
    ]);
    exit;
}

// ✅ Transfer oluşturma
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_transfer'])) {
    header('Content-Type: application/json');
    $transferItems = json_decode($_POST['transfer_items'], true);
    $sessionID = $_SESSION["sapSession"]["SessionId"] ?? uniqid();
    $guid = uniqid('TRANSFER_', true);
    $results = [];
    $successCount = 0;
    $errorCount = 0;

    foreach ($transferItems as $item) {
        $postData = [
            'U_Type' => 'TRANSFER',
            'U_WhsCode' => $whsCode,
            'U_ItemCode' => $item['ItemCode'],
            'U_ItemName' => $item['ItemName'],
            'U_FromWhsCode' => $item['FromWhsCode'],
            'U_FromWhsName' => $item['FromWhsName'],
            'U_Quantity' => floatval($item['Quantity']),
            'U_UomCode' => $item['UomCode'],
            'U_Comments' => $item['Comments'] ?? '',
            'U_SessionID' => $sessionID,
            'U_GUID' => $guid,
            'U_User' => $userName
        ];

        $result = $sap->post('ASUDO_B2B_OWTQ', $postData);
        if ($result['status'] >= 200 && $result['status'] < 300) {
            $successCount++;
        } else {
            $errorCount++;
        }
    }

    echo json_encode([
        'success' => $errorCount === 0,
        'message' => "{$successCount} başarılı, {$errorCount} hatalı işlem."
    ]);
    exit;
}

// ✅ Varsayılan veri yükleme
$query = "SQLQueries('OWTQ_T_NEW')/List?value1='TRANSFER'&value2='{$whsCode}'";
$data = $sap->get($query);
$rows = $data['response']['value'] ?? [];

$warehousesQuery = "Warehouses?\$select=WarehouseCode,WarehouseName&\$filter=Inactive eq 'N'";
$warehousesData = $sap->get($warehousesQuery);
$warehouses = $warehousesData['response']['value'] ?? [];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Transfer Oluştur - CREMMAVERSE</title>
<link rel="stylesheet" href="navbar.css">
<link rel="stylesheet" href="styles.css">
<style>
:root {
    --primary: #1e3a8a;
    --primary-light: #eef2ff;
    --accent: #2563eb;
    --muted: #6b7280;
    --border: #e5e7eb;
    --bg: #f5f7fa;
}

/* Ana sayfa düzeni */
.app-container {
    display: flex;
    min-height: 100vh;
    background-color: var(--bg);
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

.btn-exit {
    border: none;
    border-radius: 999px;
    padding: 10px 18px;
    font-weight: 600;
    cursor: pointer;
    background: #fff;
    color: var(--accent);
    border: 1px solid var(--accent);
    transition: transform .15s ease, box-shadow .15s ease, background .15s ease, color .15s ease;
}

.btn-exit:hover {
    background: var(--accent);
    color: #fff;
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.25);
    transform: translateY(-1px);
}

/* Kart tasarımı */
.card {
    background: #ffffff;
    border-radius: 24px;
    padding: 24px 32px 32px 32px;
    box-shadow: 0 25px 50px rgba(15, 23, 42, 0.08);
    margin-top: 24px;
}

.content-wrapper {
    max-width: 1400px;
    margin: 0 auto;
    width: 100%;
}

/* Filtre bölümü */
.filter-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    font-weight: 700;
    color: var(--muted);
    margin-bottom: 6px;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.filter-input, .filter-select {
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 14px;
    background: var(--primary-light);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}

.filter-input:focus, .filter-select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.25);
    background: #fff;
}

/* Multi-select stilleri */
.multi-select-container {
    position: relative;
}

.multi-select-input {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    min-height: 40px;
    cursor: pointer;
    background: white;
}

.multi-select-tag {
    background: var(--accent);
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.multi-select-tag .remove {
    cursor: pointer;
    font-weight: bold;
}

.multi-select-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ddd;
    border-radius: 6px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}

.multi-select-option {
    padding: 10px 12px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
}

.multi-select-option:hover {
    background: #f5f5f5;
}

.multi-select-option.selected {
    background: #e3f2fd;
    color: #1976d2;
}

/* Tablo kontrolleri */
.table-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding: 16px 20px;
    background: #ffffff;
    border-radius: 16px;
    border: 1px solid var(--border);
}

.show-entries {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #666;
    font-size: 14px;
}

.entries-select {
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.search-box {
    display: flex;
    align-items: center;
    gap: 10px;
}

.search-input {
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 14px;
    width: 260px;
}

.search-btn {
    background: var(--accent);
    color: white;
    border: none;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
}

/* Tablo stilleri */
.data-table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.data-table th {
    background: var(--primary);
    color: #ffffff;
    font-weight: 600;
    padding: 15px 12px;
    text-align: left;
    border-bottom: 2px solid #e0e0e0;
    position: relative;
    cursor: pointer;
    user-select: none;
}

.data-table th:hover {
    background: #e9ecef;
}

.data-table th .sort-icon {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 12px;
    color: #666;
}

.data-table td {
    padding: 14px 16px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.data-table tbody tr {
    transition: background 0.2s;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

/* Expandable row styles */
.expandable-row {
    cursor: pointer;
}

.expandable-row:hover {
    background: #e3f2fd !important;
}

.expandable-content {
    display: none;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
}

.expandable-content td {
    padding: 20px;
    background: #f8f9fa;
}

.expand-icon {
    margin-right: 8px;
    transition: transform 0.3s;
}

.expand-icon.expanded {
    transform: rotate(90deg);
}

/* Stok durumu */
.stock-status-var {
    color: #4caf50;
    font-weight: 600;
}

.stock-status-yok {
    color: #f44336;
    font-weight: 600;
}

/* Miktar kontrolleri */
.quantity-control {
    display: flex;
    align-items: center;
    gap: 8px;
}

.quantity-btn {
    width: 32px;
    height: 32px;
    border: none;
    background: #2c2c2c;
    color: #fff;
    border-radius: 4px;
    cursor: pointer;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.quantity-btn:hover {
    background: #3c3c3c;
}

.quantity-input {
    width: 60px;
    height: 32px;
    text-align: center;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.note-input {
    width: 100%;
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

/* Sayfalama */
.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 20px 0;
    gap: 6px;
}

.page-btn {
    background: #f5f5f5;
    border: 1px solid #ccc;
    padding: 8px 12px;
    cursor: pointer;
    border-radius: 6px;
    transition: all 0.2s;
    font-size: 14px;
    min-width: 40px;
    text-align: center;
}

.page-btn:hover {
    background: #e0e0e0;
}

.page-btn.active {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
}

.page-btn:disabled {
    background: #f5f5f5;
    color: #ccc;
    cursor: not-allowed;
}

/* Tablo alt bilgi */
.table-footer {
    text-align: center;
    padding: 15px;
    color: #666;
    font-size: 14px;
    background: #f8f9fa;
    border-top: 1px solid #e0e0e0;
}

/* Submit butonu */
.submit-btn {
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 15px 30px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    z-index: 1000;
    box-shadow: 0 4px 12px rgba(255, 87, 34, 0.3);
    transition: all 0.3s;
}

.submit-btn:hover:not(:disabled) {
    background: #1d4ed8;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(255, 87, 34, 0.4);
}

.submit-btn:disabled {
    background: #ccc;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* Responsive */
@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 10px;
    }
    
    .filter-section {
        grid-template-columns: 1fr;
    }
    
    .table-controls {
        flex-direction: column;
        gap: 15px;
    }
    
    .data-table {
        font-size: 12px;
    }
    
    .data-table th,
    .data-table td {
        padding: 8px 6px;
    }
}
</style>
</head>
<body>
<div class="app-container">
    <?php include 'navbar.php'; ?>
    <main class="main-content">
        <header class="page-header">
            <h2>Transfer Oluştur</h2>
            <button class="btn-exit" onclick="window.location.href='Transferler.php'">← Transferler</button>
        </header>

        <div class="content-wrapper">
        <div class="card">
            <div class="filter-section">
                <div class="filter-group">
                    <label>Kalem Tanımı</label>
                    <div class="multi-select-container">
                        <div class="multi-select-input" onclick="toggleDropdown('itemName')">
                            <div id="itemNameTags"></div>
                            <input type="text" id="filterItemName" class="filter-input" placeholder="Seçiniz..." readonly>
                        </div>
                        <div class="multi-select-dropdown" id="itemNameDropdown">
                            <div class="multi-select-option" data-value="" onclick="selectOption('itemName', '', 'Tümü')">Tümü</div>
                        </div>
                    </div>
                </div>
                <div class="filter-group">
                    <label>Kalem Grubu</label>
                    <div class="multi-select-container">
                        <div class="multi-select-input" onclick="toggleDropdown('itemGroup')">
                            <div id="itemGroupTags"></div>
                            <input type="text" id="filterItemGroup" class="filter-input" placeholder="Seçiniz..." readonly>
                        </div>
                        <div class="multi-select-dropdown" id="itemGroupDropdown">
                            <div class="multi-select-option" data-value="" onclick="selectOption('itemGroup', '', 'Tümü')">Tümü</div>
                        </div>
                    </div>
                </div>
                <div class="filter-group">
                    <label>Şube</label>
                    <div class="multi-select-container">
                        <div class="multi-select-input" onclick="toggleDropdown('branch')">
                            <div id="branchTags"></div>
                            <input type="text" id="filterBranch" class="filter-input" placeholder="Seçiniz..." readonly>
                        </div>
                        <div class="multi-select-dropdown" id="branchDropdown">
                            <div class="multi-select-option" data-value="" onclick="selectOption('branch', '', 'Tümü')">Tümü</div>
                            <?php foreach ($warehouses as $whs): ?>
                            <div class="multi-select-option" data-value="<?= htmlspecialchars($whs['WarehouseCode']) ?>" onclick="selectOption('branch', '<?= htmlspecialchars($whs['WarehouseCode']) ?>', '<?= htmlspecialchars($whs['WarehouseName']) ?>')"><?= htmlspecialchars($whs['WarehouseName']) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="filter-group">
                    <label>Stok Durumu</label>
                    <div class="multi-select-container">
                        <div class="multi-select-input" onclick="toggleDropdown('stockStatus')">
                            <div id="stockStatusTags"></div>
                            <input type="text" id="filterStockStatus" class="filter-input" placeholder="Seçiniz..." readonly>
                        </div>
                        <div class="multi-select-dropdown" id="stockStatusDropdown">
                            <div class="multi-select-option" data-value="" onclick="selectOption('stockStatus', '', 'Tümü')">Tümü</div>
                            <div class="multi-select-option" data-value="Var" onclick="selectOption('stockStatus', 'Var', 'Var')">Var</div>
                            <div class="multi-select-option" data-value="Yok" onclick="selectOption('stockStatus', 'Yok', 'Yok')">Yok</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-controls">
                <div class="show-entries">
                    <span>Sayfada</span>
                    <select id="pageSize" class="entries-select">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="75">75</option>
                        <option value="100">100</option>
                    </select>
                    <span>kayıt göster</span>
                </div>
                <div class="search-box">
                    <input type="text" id="searchBox" class="search-input" placeholder="Ara...">
                </div>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th onclick="sortTable('ItemCode')">Kalem Kodu <span class="sort-icon">◊</span></th>
                        <th onclick="sortTable('ItemName')">Kalem Tanımı <span class="sort-icon">◊</span></th>
                        <th onclick="sortTable('ItemGroup')">Kalem Grubu <span class="sort-icon">◊</span></th>
                        <th onclick="sortTable('FromWhsName')">Şube Adı <span class="sort-icon">◊</span></th>
                        <th onclick="sortTable('OnHand')">Stokta <span class="sort-icon">◊</span></th>
                        <th onclick="sortTable('OnHand')">Stok Miktarı <span class="sort-icon">◊</span></th>
                        <th onclick="sortTable('MinQty')">Gerekli Miktar <span class="sort-icon">◊</span></th>
                        <th>Sipariş Miktarı</th>
                        <th onclick="sortTable('UomCode')">Ölçü Birimi <span class="sort-icon">◊</span></th>
                        <th>Not</th>
                    </tr>
                </thead>
                <tbody id="tableBody"></tbody>
            </table>

            <div class="table-footer">
                <span id="recordCount"></span>
            </div>
            <div id="pagination" class="pagination"></div>
        </div><!-- /.card -->
        </div><!-- /.content-wrapper -->
        <button class="submit-btn" id="submitBtn" disabled>Transfer Oluştur</button>
    </main>
</div>

<script>
let allData = <?= json_encode($rows) ?>;
let filteredData = [...allData];
let currentPage = 1;
let sortColumn = '';
let sortDirection = 'asc';
let selectedFilters = {
    itemName: [],
    itemGroup: [],
    branch: [],
    stockStatus: []
};

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    initializeMultiSelect();
    populateFilterOptions();
    document.getElementById('pageSize').addEventListener('change', () => { currentPage = 1; renderTable(); });
    document.getElementById('searchBox').addEventListener('input', () => { currentPage = 1; applyFilters(); });
    renderTable();
});

// Multi-select functionality
function initializeMultiSelect() {
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.multi-select-container')) {
            closeAllDropdowns();
        }
    });
}

function toggleDropdown(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    const isOpen = dropdown.style.display === 'block';
    
    closeAllDropdowns();
    if (!isOpen) {
        dropdown.style.display = 'block';
    }
}

function closeAllDropdowns() {
    document.querySelectorAll('.multi-select-dropdown').forEach(dropdown => {
        dropdown.style.display = 'none';
    });
}

function selectOption(type, value, text) {
    if (value === '') {
        // Clear all selections
        selectedFilters[type] = [];
    } else {
        // Toggle selection
        const index = selectedFilters[type].indexOf(value);
        if (index > -1) {
            selectedFilters[type].splice(index, 1);
        } else {
            selectedFilters[type].push(value);
        }
    }
    
    updateFilterDisplay(type);
    applyFilters();
}

function updateFilterDisplay(type) {
    const tagsContainer = document.getElementById(type + 'Tags');
    const input = document.getElementById('filter' + type.charAt(0).toUpperCase() + type.slice(1));
    
    tagsContainer.innerHTML = '';
    
    if (selectedFilters[type].length === 0) {
        input.placeholder = 'Seçiniz...';
        input.value = '';
    } else {
        selectedFilters[type].forEach(value => {
            const option = document.querySelector(`#${type}Dropdown .multi-select-option[data-value="${value}"]`);
            if (option) {
                const tag = document.createElement('div');
                tag.className = 'multi-select-tag';
                tag.innerHTML = `${option.textContent} <span class="remove" onclick="removeTag('${type}', '${value}')">×</span>`;
                tagsContainer.appendChild(tag);
            }
        });
        input.value = selectedFilters[type].length + ' seçenek';
    }
}

function removeTag(type, value) {
    const index = selectedFilters[type].indexOf(value);
    if (index > -1) {
        selectedFilters[type].splice(index, 1);
        updateFilterDisplay(type);
        applyFilters();
    }
}

function populateFilterOptions() {
    // Populate item names
    const itemNames = [...new Set(allData.map(item => item.ItemName).filter(Boolean))];
    const itemNameDropdown = document.getElementById('itemNameDropdown');
    itemNames.forEach(name => {
        const option = document.createElement('div');
        option.className = 'multi-select-option';
        option.setAttribute('data-value', name);
        option.textContent = name;
        option.onclick = () => selectOption('itemName', name, name);
        itemNameDropdown.appendChild(option);
    });

    // Populate item groups
    const itemGroups = [...new Set(allData.map(item => item.ItemGroup).filter(Boolean))];
    const itemGroupDropdown = document.getElementById('itemGroupDropdown');
    itemGroups.forEach(group => {
        const option = document.createElement('div');
        option.className = 'multi-select-option';
        option.setAttribute('data-value', group);
        option.textContent = group;
        option.onclick = () => selectOption('itemGroup', group, group);
        itemGroupDropdown.appendChild(option);
    });
}

function applyFilters() {
    const search = document.getElementById('searchBox').value.toLowerCase();

    filteredData = allData.filter(item => {
        // Multi-select filters
        if (selectedFilters.itemName.length > 0 && !selectedFilters.itemName.includes(item.ItemName)) return false;
        if (selectedFilters.itemGroup.length > 0 && !selectedFilters.itemGroup.includes(item.ItemGroup)) return false;
        if (selectedFilters.branch.length > 0 && !selectedFilters.branch.includes(item.FromWhsCode)) return false;
        if (selectedFilters.stockStatus.length > 0) {
            const hasStock = (item.OnHand || 0) > 0;
            if (selectedFilters.stockStatus.includes('Var') && !hasStock) return false;
            if (selectedFilters.stockStatus.includes('Yok') && hasStock) return false;
        }
        
        // Search filter
        if (search && !JSON.stringify(item).toLowerCase().includes(search)) return false;
        
        return true;
    });
    
    currentPage = 1;
    renderTable();
}

function sortTable(column) {
    if (sortColumn === column) {
        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        sortColumn = column;
        sortDirection = 'asc';
    }
    
    filteredData.sort((a, b) => {
        let aVal = a[column] || '';
        let bVal = b[column] || '';
        
        if (typeof aVal === 'string') {
            aVal = aVal.toLowerCase();
            bVal = bVal.toLowerCase();
        }
        
        if (sortDirection === 'asc') {
            return aVal > bVal ? 1 : -1;
        } else {
            return aVal < bVal ? 1 : -1;
        }
    });
    
    renderTable();
}

function renderTable() {
    const pageSize = parseInt(document.getElementById('pageSize').value);
    const tbody = document.getElementById('tableBody');
    const total = filteredData.length;
    const totalPages = Math.ceil(total / pageSize);
    const start = (currentPage - 1) * pageSize;
    const end = start + pageSize;
    const data = filteredData.slice(start, end);

    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:40px;">Eşleşen kayıt bulunamadı</td></tr>';
        document.getElementById('recordCount').textContent = `Kayıt yok (${allData.length} kayıt içerisinden bulunan)`;
        document.getElementById('pagination').innerHTML = "";
        return;
    }

    tbody.innerHTML = data.map((item, index) => `
        <tr class="expandable-row" onclick="toggleRow(${index})" data-item='${JSON.stringify(item).replace(/'/g,"&#39;")}'>
            <td><span class="expand-icon">▶</span>${item.ItemCode||''}</td>
            <td>${item.ItemName||''}</td>
            <td>${item.ItemGroup||''}</td>
            <td>${item.FromWhsName||''}</td>
            <td><span class="stock-status-${(item.OnHand||0)>0?'var':'yok'}">${(item.OnHand||0)>0?'Var':'Yok'}</span></td>
            <td>${parseFloat(item.OnHand||0).toFixed(2)}</td>
            <td>${parseFloat(item.MinQty||0).toFixed(2)}</td>
            <td><div class="quantity-control">
                <button class="quantity-btn" onclick="event.stopPropagation(); decreaseQuantity(this)">−</button>
                <input type="number" class="quantity-input" value="0" min="0" onchange="updateSelectedCount()" onclick="event.stopPropagation();">
                <button class="quantity-btn" onclick="event.stopPropagation(); increaseQuantity(this)">+</button>
            </div></td>
            <td>${item.UomCode||'PK'}</td>
            <td><input type="text" class="note-input" placeholder="Not ekle..." onclick="event.stopPropagation();"></td>
        </tr>
        <tr class="expandable-content" id="expand-${index}" style="display: none;">
            <td colspan="10">
                <div style="padding: 20px; background: #f8f9fa; border-radius: 8px;">
                    <h4>Detay Bilgiler</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                        <div><strong>Kalem Kodu:</strong> ${item.ItemCode||''}</div>
                        <div><strong>Kalem Tanımı:</strong> ${item.ItemName||''}</div>
                        <div><strong>Kalem Grubu:</strong> ${item.ItemGroup||''}</div>
                        <div><strong>Şube Adı:</strong> ${item.FromWhsName||''}</div>
                        <div><strong>Stok Miktarı:</strong> ${parseFloat(item.OnHand||0).toFixed(2)}</div>
                        <div><strong>Gerekli Miktar:</strong> ${parseFloat(item.MinQty||0).toFixed(2)}</div>
                        <div><strong>Ölçü Birimi:</strong> ${item.UomCode||'PK'}</div>
                        <div><strong>Stok Durumu:</strong> <span class="stock-status-${(item.OnHand||0)>0?'var':'yok'}">${(item.OnHand||0)>0?'Var':'Yok'}</span></div>
                    </div>
                </div>
            </td>
        </tr>
    `).join('');

    document.getElementById('recordCount').textContent =
        `Toplam ${total} kayıttan ${start + 1}-${Math.min(end, total)} arası gösteriliyor`;

    renderPagination(totalPages);
    updateSelectedCount();
}

function toggleRow(index) {
    const expandIcon = document.querySelector(`tr[onclick="toggleRow(${index})"] .expand-icon`);
    const expandContent = document.getElementById(`expand-${index}`);
    
    if (expandContent.style.display === 'none') {
        expandContent.style.display = 'table-row';
        expandIcon.classList.add('expanded');
    } else {
        expandContent.style.display = 'none';
        expandIcon.classList.remove('expanded');
    }
}

function renderPagination(totalPages) {
    const pag = document.getElementById('pagination');
    pag.innerHTML = '';
    if (totalPages <= 1) return;
    
    let html = '';
    const maxVisiblePages = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
    
    if (endPage - startPage + 1 < maxVisiblePages) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }

    // Previous button
    if (currentPage > 1) {
        html += `<button class="page-btn" onclick="changePage(${currentPage - 1})">‹</button>`;
    } else {
        html += `<button class="page-btn" disabled>‹</button>`;
    }

    // First page
    if (startPage > 1) {
        html += `<button class="page-btn" onclick="changePage(1)">1</button>`;
        if (startPage > 2) {
            html += `<span style="padding: 8px;">...</span>`;
        }
    }

    // Page numbers
    for (let i = startPage; i <= endPage; i++) {
        if (i === currentPage) {
            html += `<button class="page-btn active">${i}</button>`;
        } else {
            html += `<button class="page-btn" onclick="changePage(${i})">${i}</button>`;
        }
    }

    // Last page
    if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
            html += `<span style="padding: 8px;">...</span>`;
        }
        html += `<button class="page-btn" onclick="changePage(${totalPages})">${totalPages}</button>`;
    }

    // Next button
    if (currentPage < totalPages) {
        html += `<button class="page-btn" onclick="changePage(${currentPage + 1})">›</button>`;
    } else {
        html += `<button class="page-btn" disabled>›</button>`;
    }

    pag.innerHTML = html;
}

function changePage(page) {
    currentPage = page;
    renderTable();
}

function increaseQuantity(btn) {
    const input = btn.previousElementSibling;
    input.value = parseInt(input.value || 0) + 1;
    updateSelectedCount();
}

function decreaseQuantity(btn) {
    const input = btn.nextElementSibling;
    if (parseInt(input.value) > 0) {
        input.value--;
        updateSelectedCount();
    }
}

function updateSelectedCount() {
    let count = 0;
    document.querySelectorAll('.quantity-input').forEach(input => {
        if (parseInt(input.value) > 0) count++;
    });
    document.getElementById('submitBtn').disabled = count === 0;
}

// Submit transfer function
document.getElementById('submitBtn').addEventListener('click', function() {
    const transferItems = [];
    document.querySelectorAll('tr[data-item]').forEach(row => {
        const quantityInput = row.querySelector('.quantity-input');
        const noteInput = row.querySelector('.note-input');
        if (parseInt(quantityInput.value) > 0) {
            const item = JSON.parse(row.getAttribute('data-item').replace(/&#39;/g, "'"));
            transferItems.push({
                ItemCode: item.ItemCode,
                ItemName: item.ItemName,
                FromWhsCode: item.FromWhsCode,
                FromWhsName: item.FromWhsName,
                Quantity: quantityInput.value,
                UomCode: item.UomCode,
                Comments: noteInput.value
            });
        }
    });

    if (transferItems.length === 0) return;

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `create_transfer=1&transfer_items=${encodeURIComponent(JSON.stringify(transferItems))}`
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message);
        if (data.success) {
            location.reload();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Transfer oluşturulurken hata oluştu.');
    });
});
</script>
</body>
</html>
