<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece üretim kullanıcıları (RT veya CF) görebilsin (YE göremez)
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'RT' && $uAsOwnr !== 'CF') {
    header("Location: index.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

//////////////////////////////////////////////////////// ProductionOrders verilerini çek
$productionOrdersSelect = "AbsoluteEntry,ItemNo,ProductDescription,PlannedQuantity,InventoryUOM,ProductionOrderStatus";
$productionOrdersQuery = "ProductionOrders?\$select=" . urlencode($productionOrdersSelect) . "&\$orderby=" . urlencode("AbsoluteEntry desc");
$productionOrdersData = $sap->get($productionOrdersQuery);

// Status mapping fonksiyonu
function getStatusText($status) {
    $statusMap = [
        'boposPlanned' => 'Planlandı',
        'boposReleased' => 'Onaylandı',
        'boposClosed' => 'Kapalı',
        'boposCancelled' => 'İptal Edildi'
    ];
    return $statusMap[$status] ?? 'Bilinmiyor';
}

function getStatusClass($status) {
    $classMap = [
        'boposPlanned' => 'status-planlandi',
        'boposReleased' => 'status-onaylandi',
        'boposClosed' => 'status-kapali',
        'boposCancelled' => 'status-iptal'
    ];
    return $classMap[$status] ?? 'status-unknown';
}

$workOrders = [];
if (($productionOrdersData['status'] ?? 0) == 200) {
    $ordersList = $productionOrdersData['response']['value'] ?? $productionOrdersData['value'] ?? [];
    foreach ($ordersList as $order) {
        $status = $order['ProductionOrderStatus'] ?? 'boposPlanned';
        $workOrders[] = [
            'isEmriNo' => $order['AbsoluteEntry'] ?? '',
            'urunNo' => $order['ItemNo'] ?? '',
            'urunTanimi' => $order['ProductDescription'] ?? '',
            'birim' => $order['InventoryUOM'] ?? '',
            'planlananMiktar' => $order['PlannedQuantity'] ?? 0,
            'durum' => getStatusText($status),
            'durumKodu' => $status,
            'durumClass' => getStatusClass($status)
        ];
    }
}
//////////////////////////////////////////////////////// ProductionOrders verilerini çek 
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Üretim İş Emirleri Listesi - MINOA</title>
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

.btn-view {
    background: #f0f9ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-block;
}

.btn-view:hover {
    background: #dbeafe;
}

/* Table Styles */
.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

thead {
    background: #f8fafc;
}

thead th {
    padding: 16px 20px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    white-space: nowrap;
}

tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.15s ease;
}

tbody tr:hover {
    background: #f9fafb;
}

tbody td {
    padding: 16px 20px;
    color: #4b5563;
}

tbody td:first-child {
    font-weight: 500;
    color: #1e40af;
}

/* Durum rozetleri */
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 500;
}

.status-planlandi {
    background: #eff6ff;
    color: #1d4ed8;
}

.status-onaylandi {
    background: #dcfce7;
    color: #15803d;
}

.status-kapali {
    background: #fee2e2;
    color: #991b1b;
}

.status-iptal {
    background: #fee2e2;
    color: #991b1b;
}

/* Filter Section - Custom Dropdown */
.filter-section {
    display: flex;
    gap: 16px;
    align-items: center;
    flex-wrap: wrap;
    padding: 20px;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group label {
    font-weight: 600;
    color: #374151;
    font-size: 14px;
}

.single-select-container {
    position: relative;
    min-width: 180px;
}

.single-select-input {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    background: white;
    cursor: pointer;
    transition: all 0.2s ease;
    min-height: 38px;
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
    color: #374151;
    padding: 0;
}

.dropdown-arrow {
    transition: transform 0.2s;
    color: #6b7280;
    font-size: 10px;
    margin-left: 8px;
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
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    z-index: 9999;
    max-height: 200px;
    overflow-y: auto;
    display: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    margin-top: 2px;
}

.single-select-dropdown.show {
    display: block;
}

.single-select-option {
    padding: 8px 12px;
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

/* Pagination */
.pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-top: 1px solid #e5e7eb;
}

.pagination-info {
    color: #6b7280;
    font-size: 14px;
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
    color: #374151;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-block;
}

.pagination-btn:hover:not(.disabled):not(.active) {
    background: #f3f4f6;
    border-color: #3b82f6;
    color: #3b82f6;
}

.pagination-btn.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.pagination-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header {
        padding: 16px 1rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        height: auto;
    }

    .content-wrapper {
        padding: 16px;
    }

    .table-controls {
        flex-direction: column;
        gap: 16px;
        align-items: stretch;
    }

    .search-box {
        width: 100%;
    }

    .search-input {
        width: 100%;
        min-width: auto;
    }

    .pagination {
        flex-direction: column;
        gap: 16px;
        align-items: center;
    }

    .pagination-info {
        text-align: center;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2>Üretim İş Emirleri Listesi</h2>
            <button class="btn btn-primary" onclick="window.location.href='UretimIsEmriSO.php'">+ Yeni Üretim İş Emri Oluştur</button>
        </div>

        <div class="content-wrapper">
            <!-- Status Filtresi -->
            <div class="card" style="margin-bottom: 24px;">
                <div class="filter-section">
                    <div class="single-select-container">
                        <div class="single-select-input" onclick="toggleDropdown('status')">
                            <input type="text" id="filterStatus" value="" placeholder="Durum Filtresi" readonly>
                            <span class="dropdown-arrow">▼</span>
                        </div>
                        <div class="single-select-dropdown" id="statusDropdown">
                            <div class="single-select-option selected" data-value="" onclick="selectStatus('')">Tümü</div>
                            <div class="single-select-option" data-value="boposPlanned" onclick="selectStatus('boposPlanned')">Planlandı</div>
                            <div class="single-select-option" data-value="boposReleased" onclick="selectStatus('boposReleased')">Onaylandı</div>
                            <div class="single-select-option" data-value="boposClosed" onclick="selectStatus('boposClosed')">Kapalı</div>
                            <div class="single-select-option" data-value="boposCancelled" onclick="selectStatus('boposCancelled')">İptal Edildi</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="table-controls">
                    <div class="show-entries">
                        <span>Sayfada</span>
                        <select class="entries-select" id="entriesPerPage">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span>kayıt göster</span>
                    </div>
                    <div class="search-box">
                        <input type="text" class="search-input" id="searchInput" placeholder="Ara..." onkeypress="if(event.key==='Enter') performSearch()">
                        <button class="btn btn-secondary" onclick="performSearch()">🔍</button>
                    </div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>İş Emri No</th>
                                <th>Ürün Numarası</th>
                                <th>Ürün Tanımı</th>
                                <th>Planlanan Miktar</th>
                                <th>Birim</th>
                                <th>Durum</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php if (empty($workOrders)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    İş emri bulunamadı
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($workOrders as $order): ?>
                                <tr>
                                    <td><?= htmlspecialchars($order['isEmriNo']) ?></td>
                                    <td><?= htmlspecialchars($order['urunNo']) ?></td>
                                    <td><?= htmlspecialchars($order['urunTanimi']) ?></td>
                                    <td><?= number_format($order['planlananMiktar'], 2, ',', '.') ?></td>
                                    <td><?= htmlspecialchars($order['birim']) ?></td>
                                    <td>
                                        <span class="status-badge <?= htmlspecialchars($order['durumClass']) ?>" data-status="<?= htmlspecialchars($order['durumKodu']) ?>">
                                            <?= htmlspecialchars($order['durum']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="UretimIsEmriDetay.php?id=<?= htmlspecialchars($order['isEmriNo']) ?>" class="btn-view">Detay</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="pagination" id="paginationContainer" style="display: none;">
                    <div class="pagination-info" id="paginationInfo">
                        Toplam <span id="totalRecords">0</span> kayıttan <span id="showingFrom">0</span>-<span id="showingTo">0</span> arası gösteriliyor
                    </div>
                    <div class="pagination-controls" id="paginationControls">
                        <!-- Pagination butonları buraya JavaScript ile eklenecek -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentPage = 1;
        let entriesPerPage = 25;
        let allRows = [];
        let filteredRows = [];

        // Sayfa yüklendiğinde
        document.addEventListener('DOMContentLoaded', function() {
            initializeTable();
        });

        function initializeTable() {
            allRows = Array.from(document.querySelectorAll('#tableBody tr'));
            filteredRows = allRows;
            entriesPerPage = parseInt(document.getElementById('entriesPerPage').value) || 25;
            currentPage = 1;
            updateTable();
        }

        function updateTable() {
            // Önce tüm satırları göster
            allRows.forEach(row => {
                row.style.display = '';
            });

            // Arama filtresi uygula
            const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
            if (searchTerm) {
                filteredRows = allRows.filter(row => {
                    const text = row.textContent.toLowerCase();
                    return text.includes(searchTerm);
                });
            } else {
                filteredRows = allRows;
            }

            // Status filtresi uygula
            if (selectedStatus) {
                filteredRows = filteredRows.filter(row => {
                    const statusBadge = row.querySelector('.status-badge[data-status]');
                    if (statusBadge) {
                        const rowStatus = statusBadge.getAttribute('data-status');
                        return rowStatus === selectedStatus;
                    }
                    return false;
                });
            }

            // Sayfalama uygula
            const startIndex = (currentPage - 1) * entriesPerPage;
            const endIndex = startIndex + entriesPerPage;
            const paginatedRows = filteredRows.slice(startIndex, endIndex);

            // Tüm satırları gizle
            allRows.forEach(row => {
                row.style.display = 'none';
            });

            // Sadece mevcut sayfadaki satırları göster
            paginatedRows.forEach(row => {
                row.style.display = '';
            });

            // Pagination bilgilerini güncelle
            updatePagination();
        }

        function updatePagination() {
            const totalRecords = filteredRows.length;
            const totalPages = Math.ceil(totalRecords / entriesPerPage);
            const showingFrom = totalRecords === 0 ? 0 : (currentPage - 1) * entriesPerPage + 1;
            const showingTo = Math.min(currentPage * entriesPerPage, totalRecords);

            // Bilgi metnini güncelle
            document.getElementById('totalRecords').textContent = totalRecords;
            document.getElementById('showingFrom').textContent = showingFrom;
            document.getElementById('showingTo').textContent = showingTo;

            // Pagination container'ı göster/gizle
            const paginationContainer = document.getElementById('paginationContainer');
            if (totalRecords > 0) {
                paginationContainer.style.display = 'flex';
            } else {
                paginationContainer.style.display = 'none';
            }

            // Pagination butonlarını oluştur
            const paginationControls = document.getElementById('paginationControls');
            paginationControls.innerHTML = '';

            if (totalPages <= 1) {
                return;
            }

            // Önceki sayfa butonu
            const prevBtn = document.createElement('button');
            prevBtn.className = 'pagination-btn' + (currentPage === 1 ? ' disabled' : '');
            prevBtn.textContent = '← Önceki';
            prevBtn.onclick = () => {
                if (currentPage > 1) {
                    currentPage--;
                    updateTable();
                }
            };
            paginationControls.appendChild(prevBtn);

            // Sayfa numaraları
            const maxVisiblePages = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
            let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);

            if (endPage - startPage < maxVisiblePages - 1) {
                startPage = Math.max(1, endPage - maxVisiblePages + 1);
            }

            // İlk sayfa
            if (startPage > 1) {
                const firstBtn = document.createElement('button');
                firstBtn.className = 'pagination-btn';
                firstBtn.textContent = '1';
                firstBtn.onclick = () => {
                    currentPage = 1;
                    updateTable();
                };
                paginationControls.appendChild(firstBtn);

                if (startPage > 2) {
                    const ellipsis = document.createElement('span');
                    ellipsis.textContent = '...';
                    ellipsis.style.padding = '8px 4px';
                    ellipsis.style.color = '#6b7280';
                    paginationControls.appendChild(ellipsis);
                }
            }

            // Sayfa numaraları
            for (let i = startPage; i <= endPage; i++) {
                const pageBtn = document.createElement('button');
                pageBtn.className = 'pagination-btn' + (i === currentPage ? ' active' : '');
                pageBtn.textContent = i;
                pageBtn.onclick = () => {
                    currentPage = i;
                    updateTable();
                };
                paginationControls.appendChild(pageBtn);
            }

            // Son sayfa
            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    const ellipsis = document.createElement('span');
                    ellipsis.textContent = '...';
                    ellipsis.style.padding = '8px 4px';
                    ellipsis.style.color = '#6b7280';
                    paginationControls.appendChild(ellipsis);
                }

                const lastBtn = document.createElement('button');
                lastBtn.className = 'pagination-btn';
                lastBtn.textContent = totalPages;
                lastBtn.onclick = () => {
                    currentPage = totalPages;
                    updateTable();
                };
                paginationControls.appendChild(lastBtn);
            }

            // Sonraki sayfa butonu
            const nextBtn = document.createElement('button');
            nextBtn.className = 'pagination-btn' + (currentPage === totalPages ? ' disabled' : '');
            nextBtn.textContent = 'Sonraki →';
            nextBtn.onclick = () => {
                if (currentPage < totalPages) {
                    currentPage++;
                    updateTable();
                }
            };
            paginationControls.appendChild(nextBtn);
        }

        function performSearch() {
            currentPage = 1;
            updateTable();
        }

        document.getElementById('searchInput').addEventListener('input', function() {
            performSearch();
        });

        document.getElementById('entriesPerPage').addEventListener('change', function() {
            entriesPerPage = parseInt(this.value) || 25;
            currentPage = 1;
            updateTable();
        });

        let selectedStatus = '';

        function toggleDropdown(type) {
            const dropdown = document.getElementById(type + 'Dropdown');
            const input = document.querySelector(`#filter${type.charAt(0).toUpperCase() + type.slice(1)}`).parentElement;
            const isOpen = dropdown.classList.contains('show');
            
            // Close all dropdowns
            document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
            document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
            
            if (!isOpen) {
                dropdown.classList.add('show');
                input.classList.add('active');
            }
        }

        function selectStatus(value) {
            selectedStatus = value;
            const filterInput = document.getElementById('filterStatus');
            if (value) {
                const statusText = document.querySelector(`#statusDropdown .single-select-option[data-value="${value}"]`).textContent;
                filterInput.value = statusText;
            } else {
                filterInput.value = '';
            }
            document.querySelectorAll('#statusDropdown .single-select-option').forEach(opt => opt.classList.remove('selected'));
            document.querySelector(`#statusDropdown .single-select-option[data-value="${value}"]`).classList.add('selected');
            currentPage = 1;
            updateTable();
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
