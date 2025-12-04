<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fire ve Zayi - MINOA</title>
    <?php include 'navbar.php'; ?>
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
            color: #1e3a8a;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
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

        .filter-group input[type="text"]:hover,
        .filter-group input[type="date"]:hover,
        .filter-group select:hover {
            border-color: #3b82f6;
        }

        .filter-group input[type="text"]:focus,
        .filter-group input[type="date"]:focus,
        .filter-group select:focus {
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
            width: 250px;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn-new {
            background: #dc2626;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-new:hover {
            background: #b91c1c;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8fafc;
        }

        th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: #1e3a8a;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
            white-space: nowrap;
        }

        th.sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
            padding-right: 24px;
        }

        th.sortable:hover {
            background: #f1f5f9;
        }

        th.sortable::after {
            content: '◆';
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 8px;
            color: #9ca3af;
        }

        td {
            padding: 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
            color: #374151;
        }

        tbody tr:hover {
            background: #f9fafb;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-fire {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-zayi {
            background: #fef3c7;
            color: #92400e;
        }

        .status-kusurlu {
            background: #dbeafe;
            color: #1e40af;
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

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state-text {
            font-size: 16px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <main class="main-content">
        <div class="page-header">
            <h2>Fire ve Zayi</h2>
            <button class="btn-new" onclick="window.location.href='Fire-ZayiSO.php'">
                <span>+</span>
                <span>Yeni Fire/Zayi Kaydı</span>
            </button>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <div class="filter-section">
                    <div class="filter-group">
                        <label for="filter-status">Durum</label>
                        <select id="filter-status">
                            <option value="">Tümü</option>
                            <option value="fire">Fire</option>
                            <option value="zayi">Zayi</option>
                            <option value="kusurlu">Kusurlu</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="filter-item-code">Kalem Kodu</label>
                        <input type="text" id="filter-item-code" placeholder="Kalem kodu girin...">
                    </div>
                    <div class="filter-group">
                        <label for="filter-start-date">Başlangıç Tarihi</label>
                        <input type="date" id="filter-start-date" placeholder="gg.aa.yyyy">
                    </div>
                    <div class="filter-group">
                        <label for="filter-end-date">Bitiş Tarihi</label>
                        <input type="date" id="filter-end-date" placeholder="gg.aa.yyyy">
                    </div>
                </div>

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
                    <div class="search-box">
                        <label for="table-search">Ara:</label>
                        <input type="text" id="table-search" class="search-input" placeholder="Arama yapın...">
                    </div>
                </div>

                <div class="table-container">
                    <table id="fire-zayi-table">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="belge-no">Belge No</th>
                                <th class="sortable" data-sort="tarih">İşlem Tarihi</th>
                                <th class="sortable" data-sort="kalem-kodu">Kalem Kodu</th>
                                <th class="sortable" data-sort="kalem-tanim">Kalem Tanımı</th>
                                <th class="sortable" data-sort="miktar">Miktar</th>
                                <th class="sortable" data-sort="olcu-birimi">Ölçü Birimi</th>
                                <th class="sortable" data-sort="durum">Durum</th>
                                <th>Açıklama</th>
                                <th>Görsel</th>
                            </tr>
                        </thead>
                        <tbody id="table-body">
                            <!-- Veriler JavaScript ile yüklenecek -->
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <div class="pagination-info">
                        <span id="pagination-info">0 kayıt gösteriliyor</span>
                    </div>
                    <div class="pagination-controls">
                        <button class="pagination-btn" id="prev-page" disabled>Önceki</button>
                        <span id="page-numbers"></span>
                        <button class="pagination-btn" id="next-page" disabled>Sonraki</button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Örnek veri (gerçek uygulamada API'den gelecek)
        let allData = [];
        let filteredData = [];
        let currentPage = 1;
        let entriesPerPage = 25;
        let currentSort = { column: null, direction: 'asc' };

        // Filtreleme ve arama
        function applyFilters() {
            const status = document.getElementById('filter-status').value;
            const itemCode = document.getElementById('filter-item-code').value.toLowerCase();
            const startDate = document.getElementById('filter-start-date').value;
            const endDate = document.getElementById('filter-end-date').value;
            const search = document.getElementById('table-search').value.toLowerCase();

            filteredData = allData.filter(item => {
                // Durum filtresi
                if (status && item.durum !== status) return false;

                // Kalem kodu filtresi
                if (itemCode && !item.kalemKodu.toLowerCase().includes(itemCode)) return false;

                // Tarih filtresi
                if (startDate && item.tarih < startDate) return false;
                if (endDate && item.tarih > endDate) return false;

                // Genel arama
                if (search) {
                    const searchable = `${item.belgeNo} ${item.kalemKodu} ${item.kalemTanim} ${item.aciklama}`.toLowerCase();
                    if (!searchable.includes(search)) return false;
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
                tbody.innerHTML = `
                    <tr>
                        <td colspan="9" class="empty-state">
                            <div class="empty-state-icon">📋</div>
                            <div class="empty-state-text">Kayıt bulunamadı</div>
                        </td>
                    </tr>
                `;
            } else {
                tbody.innerHTML = pageData.map(item => `
                    <tr>
                        <td>${item.belgeNo}</td>
                        <td>${formatDate(item.tarih)}</td>
                        <td>${item.kalemKodu}</td>
                        <td>${item.kalemTanim}</td>
                        <td>${formatNumber(item.miktar)}</td>
                        <td>${item.olcuBirimi}</td>
                        <td><span class="status-badge status-${item.durum}">${getStatusText(item.durum)}</span></td>
                        <td>${item.aciklama || '-'}</td>
                        <td>${item.gorsel ? `<img src="${item.gorsel}" alt="Görsel" style="max-width: 50px; max-height: 50px; border-radius: 4px;">` : '-'}</td>
                    </tr>
                `).join('');
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

            // Sayfa numaraları
            const pageNumbers = document.getElementById('page-numbers');
            if (totalPages <= 1) {
                pageNumbers.innerHTML = '';
            } else {
                let html = '';
                const maxVisible = 5;
                let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
                let endPage = Math.min(totalPages, startPage + maxVisible - 1);

                if (endPage - startPage < maxVisible - 1) {
                    startPage = Math.max(1, endPage - maxVisible + 1);
                }

                if (startPage > 1) {
                    html += `<button class="pagination-btn" onclick="goToPage(1)">1</button>`;
                    if (startPage > 2) html += `<span>...</span>`;
                }

                for (let i = startPage; i <= endPage; i++) {
                    html += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="goToPage(${i})" ${i === currentPage ? 'disabled' : ''}>${i}</button>`;
                }

                if (endPage < totalPages) {
                    if (endPage < totalPages - 1) html += `<span>...</span>`;
                    html += `<button class="pagination-btn" onclick="goToPage(${totalPages})">${totalPages}</button>`;
                }

                pageNumbers.innerHTML = html;
            }
        }

        // Sayfa değiştir
        function goToPage(page) {
            currentPage = page;
            renderTable();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Sıralama
        function sortTable(column) {
            if (currentSort.column === column) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = column;
                currentSort.direction = 'asc';
            }

            filteredData.sort((a, b) => {
                let aVal = a[column];
                let bVal = b[column];

                if (column === 'tarih') {
                    aVal = new Date(aVal);
                    bVal = new Date(bVal);
                } else if (column === 'miktar' || column === 'belge-no') {
                    aVal = parseFloat(aVal) || 0;
                    bVal = parseFloat(bVal) || 0;
                } else {
                    aVal = String(aVal || '').toLowerCase();
                    bVal = String(bVal || '').toLowerCase();
                }

                if (aVal < bVal) return currentSort.direction === 'asc' ? -1 : 1;
                if (aVal > bVal) return currentSort.direction === 'asc' ? 1 : -1;
                return 0;
            });

            renderTable();
        }

        // Yardımcı fonksiyonlar
        function formatDate(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleDateString('tr-TR');
        }

        function formatNumber(num) {
            return new Intl.NumberFormat('tr-TR').format(num);
        }

        function getStatusText(status) {
            const statusMap = {
                'fire': 'Fire',
                'zayi': 'Zayi',
                'kusurlu': 'Kusurlu'
            };
            return statusMap[status] || status;
        }

        // Event listeners
        document.getElementById('filter-status').addEventListener('change', applyFilters);
        document.getElementById('filter-item-code').addEventListener('input', applyFilters);
        document.getElementById('filter-start-date').addEventListener('change', applyFilters);
        document.getElementById('filter-end-date').addEventListener('change', applyFilters);
        document.getElementById('table-search').addEventListener('input', applyFilters);
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

        // Sıralama için click event
        document.querySelectorAll('th.sortable').forEach(th => {
            th.addEventListener('click', () => {
                sortTable(th.dataset.sort);
            });
        });

        // Sayfa yüklendiğinde
        document.addEventListener('DOMContentLoaded', function() {
            // Örnek veri (gerçek uygulamada API'den gelecek)
            allData = [
                {
                    belgeNo: 9909,
                    tarih: '2025-11-24',
                    kalemKodu: '20064',
                    kalemTanim: '-',
                    miktar: 2333,
                    olcuBirimi: 'GR',
                    durum: 'fire',
                    aciklama: 'SKT',
                    gorsel: null
                },
                {
                    belgeNo: 9908,
                    tarih: '2025-11-24',
                    kalemKodu: '10133',
                    kalemTanim: '-',
                    miktar: 100,
                    olcuBirimi: 'GR',
                    durum: 'fire',
                    aciklama: '',
                    gorsel: null
                }
            ];

            filteredData = [...allData];
            renderTable();
        });
    </script>
</body>
</html>





