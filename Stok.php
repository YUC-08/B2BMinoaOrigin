<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Filtreler (GET parametrelerinden)
$filterStatus = $_GET['status'] ?? '';
$filterStartDate = $_GET['start_date'] ?? '';
$filterEndDate = $_GET['end_date'] ?? '';

// Status mapping
function getStatusText($status) {
    $statusMap = [
        '1' => 'Beklemede',
        '2' => 'Tamamlandı',
        '3' => 'İptal Edildi'
    ];
    return $statusMap[$status] ?? 'Tüm Durumlar';
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '';
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
    <title>Stok Sayımlarım - MINOA</title>
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

@media (max-width: 768px) {
    .content-wrapper {
        padding: 16px 20px;
    }
    
    .filter-section {
        grid-template-columns: 1fr;
    }
    
    .table-controls {
        flex-direction: column;
        align-items: stretch;
    }
    
    .data-table {
        font-size: 12px;
    }
    
    .data-table th,
    .data-table td {
        padding: 12px 10px;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Stok Sayımlarım</h2>
            <button class="btn btn-primary" onclick="window.location.href='StokSayimSO.php'">+ Yeni Sayım Oluştur</button>
        </header>

        <div class="content-wrapper">
            <section class="card">
                <div class="filter-section">
                    <div class="filter-group">
                        <label>Talep Durumu</label>
                        <div class="single-select-container">
                            <div class="single-select-input" onclick="toggleDropdown('status')">
                                <input type="text" id="filterStatus" value="<?= $filterStatus ? getStatusText($filterStatus) : 'Tümü' ?>" placeholder="Seçiniz..." readonly>
                                <span class="dropdown-arrow">▼</span>
                            </div>
                            <div class="single-select-dropdown" id="statusDropdown">
                                <div class="single-select-option <?= empty($filterStatus) ? 'selected' : '' ?>" data-value="" onclick="selectStatus('')">Tümü</div>
                                <div class="single-select-option <?= $filterStatus === '1' ? 'selected' : '' ?>" data-value="1" onclick="selectStatus('1')">Beklemede</div>
                                <div class="single-select-option <?= $filterStatus === '2' ? 'selected' : '' ?>" data-value="2" onclick="selectStatus('2')">Tamamlandı</div>
                                <div class="single-select-option <?= $filterStatus === '3' ? 'selected' : '' ?>" data-value="3" onclick="selectStatus('3')">İptal Edildi</div>
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
                        <input type="text" class="search-input" id="tableSearch" placeholder="Ara..." onkeyup="if(event.key==='Enter') applyFilters()">
                        <button class="btn btn-secondary" onclick="applyFilters()">🔍</button>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Şube Kodu</th>
                            <th>Sayım No</th>
                            <th>Sayım Tarihi</th>
                            <th>Giriş Tarihi</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>100</td>
                            <td>1</td>
                            <td>01.12.2025</td>
                            <td>01.12.2025</td>
                            <td><span class="status-badge status-processing">Beklemede</span></td>
                            <td>
                                <button class="btn-icon btn-view">👁️ Detay</button>
                                <button class="btn-icon btn-view">✏️ Düzenle</button>
                            </td>
                        </tr>
                        <tr>
                            <td>100</td>
                            <td>2</td>
                            <td>28.11.2025</td>
                            <td>28.11.2025</td>
                            <td><span class="status-badge status-completed">Tamamlandı</span></td>
                            <td>
                                <button class="btn-icon btn-view">👁️ Detay</button>
                            </td>
                        </tr>
                        <tr>
                            <td>100</td>
                            <td>3</td>
                            <td>15.11.2025</td>
                            <td>16.11.2025</td>
                            <td><span class="status-badge status-pending">Beklemede</span></td>
                            <td>
                                <button class="btn-icon btn-view">👁️ Detay</button>
                                <button class="btn-icon btn-view">✏️ Düzenle</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </main>

    <script>
function toggleDropdown(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    const input = dropdown.parentElement.querySelector('.single-select-input');
    const isOpen = dropdown.classList.contains('show');
    
    document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
    document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
    
    if (!isOpen) {
        dropdown.classList.add('show');
        input.classList.add('active');
    }
}

function selectStatus(value) {
    const params = new URLSearchParams(window.location.search);
    if (value === '') {
        params.delete('status');
    } else {
        params.set('status', value);
    }
    window.location.href = 'Stok.php?' + params.toString();
}

function applyFilters() {
    const params = new URLSearchParams(window.location.search);
    
    const status = document.getElementById('filterStatus').getAttribute('data-value') || '';
    if (status) {
        params.set('status', status);
    } else {
        params.delete('status');
    }
    
    const startDate = document.getElementById('start-date').value;
    if (startDate) {
        params.set('start_date', startDate);
    } else {
        params.delete('start_date');
    }
    
    const endDate = document.getElementById('end-date').value;
    if (endDate) {
        params.set('end_date', endDate);
    } else {
        params.delete('end_date');
    }
    
    const search = document.getElementById('tableSearch').value.trim();
    if (search) {
        params.set('search', search);
    } else {
        params.delete('search');
    }
    
    const entries = document.getElementById('entriesPerPage').value;
    if (entries) {
        params.set('entries', entries);
    }
    
    window.location.href = 'Stok.php?' + params.toString();
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.single-select-container')) {
        document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
    }
});
    </script>
</body>
</html>

