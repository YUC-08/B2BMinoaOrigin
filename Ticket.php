<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket - MINOA</title>
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

        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            padding: 24px 24px 0 24px;
            border-bottom: 2px solid #e5e7eb;
        }

        .tab {
            padding: 12px 24px;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            bottom: -2px;
        }

        .tab:hover {
            color: #1e40af;
        }

        .tab.active {
            color: #1e40af;
            border-bottom-color: #1e40af;
        }

        .tab-count {
            margin-left: 8px;
            padding: 2px 8px;
            background: #e5e7eb;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .tab.active .tab-count {
            background: #1e40af;
            color: white;
        }

        .filter-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            flex-wrap: wrap;
            gap: 16px;
        }

        .filter-left {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .filter-right {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
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

        .toggle-switch {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #6b7280;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: 0.3s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.3s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: #3b82f6;
        }

        input:checked + .slider:before {
            transform: translateX(20px);
        }

        .filter-select {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 150px;
        }

        .filter-select:hover {
            border-color: #3b82f6;
        }

        .filter-select:focus {
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

        .priority-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-high {
            background: #fee2e2;
            color: #991b1b;
        }

        .priority-medium {
            background: #fef3c7;
            color: #92400e;
        }

        .priority-low {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-open {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-reviewing {
            background: #fef3c7;
            color: #92400e;
        }

        .status-closed {
            background: #fee2e2;
            color: #991b1b;
        }

        .table-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .btn-detail {
            padding: 6px 12px;
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-detail:hover {
            background: #bfdbfe;
        }

        .btn-delete {
            padding: 6px 10px;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-delete:hover {
            background: #fecaca;
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

        .pagination-btn.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
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
            <h2>Ticket</h2>
            <button class="btn-new" onclick="window.location.href='TicketSO.php'">
                <span>+</span>
                <span>Yeni Ticket Oluştur</span>
            </button>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <div class="tabs">
                    <button class="tab active" data-tab="all" onclick="switchTab('all')">
                        Tümü <span class="tab-count">8</span>
                    </button>
                    <button class="tab" data-tab="incoming" onclick="switchTab('incoming')">
                        Gelen <span class="tab-count">3</span>
                    </button>
                    <button class="tab" data-tab="outgoing" onclick="switchTab('outgoing')">
                        Giden <span class="tab-count">5</span>
                    </button>
                </div>

                <div class="filter-section">
                    <div class="filter-left">
                        <div class="show-entries">
                            <span>Sayfada</span>
                            <select class="entries-select" id="entries-per-page">
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                            <span>kayıt göster</span>
                        </div>
                        <div class="toggle-switch">
                            <label class="switch">
                                <input type="checkbox" id="unread-only">
                                <span class="slider"></span>
                            </label>
                            <span>Okunmamış Ticketlar</span>
                        </div>
                    </div>
                    <div class="filter-right">
                        <select class="filter-select" id="filter-priority">
                            <option value="">Öncelik Seç...</option>
                            <option value="high">Yüksek</option>
                            <option value="medium">Orta</option>
                            <option value="low">Düşük</option>
                        </select>
                        <select class="filter-select" id="filter-status">
                            <option value="">Durum Seç...</option>
                            <option value="open">Açık</option>
                            <option value="reviewing">İnceleniyor</option>
                            <option value="closed">Kapalı</option>
                        </select>
                        <div class="search-box">
                            <label for="table-search">Ara:</label>
                            <input type="text" id="table-search" class="search-input" placeholder="Arama yapın...">
                        </div>
                    </div>
                </div>

                <div class="table-container">
                    <table id="ticket-table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)">
                                </th>
                                <th class="sortable" data-sort="no">No</th>
                                <th class="sortable" data-sort="tarih">Tarih</th>
                                <th class="sortable" data-sort="sube">Şube</th>
                                <th class="sortable" data-sort="birim">Birim</th>
                                <th class="sortable" data-sort="oncelik">Öncelik</th>
                                <th class="sortable" data-sort="durum">Durum</th>
                                <th class="sortable" data-sort="konu">Konu</th>
                                <th>İşlem</th>
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
        let currentTab = 'all';
        let currentSort = { column: null, direction: 'asc' };

        // Tab değiştir
        function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`[data-tab="${tab}"]`).classList.add('active');
            applyFilters();
        }

        // Filtreleme ve arama
        function applyFilters() {
            const unreadOnly = document.getElementById('unread-only').checked;
            const priority = document.getElementById('filter-priority').value;
            const status = document.getElementById('filter-status').value;
            const search = document.getElementById('table-search').value.toLowerCase();

            filteredData = allData.filter(item => {
                // Tab filtresi
                if (currentTab === 'incoming' && item.type !== 'incoming') return false;
                if (currentTab === 'outgoing' && item.type !== 'outgoing') return false;

                // Okunmamış filtresi
                if (unreadOnly && item.okundu) return false;

                // Öncelik filtresi
                if (priority && item.oncelik !== priority) return false;

                // Durum filtresi
                if (status && item.durum !== status) return false;

                // Genel arama
                if (search) {
                    const searchable = `${item.no} ${item.sube || ''} ${item.birim || ''} ${item.konu}`.toLowerCase();
                    if (!searchable.includes(search)) return false;
                }

                return true;
            });

            currentPage = 1;
            renderTable();
            updateTabCounts();
        }

        // Tab sayılarını güncelle
        function updateTabCounts() {
            const allCount = allData.length;
            const incomingCount = allData.filter(item => item.type === 'incoming').length;
            const outgoingCount = allData.filter(item => item.type === 'outgoing').length;

            document.querySelector('[data-tab="all"] .tab-count').textContent = allCount;
            document.querySelector('[data-tab="incoming"] .tab-count').textContent = incomingCount;
            document.querySelector('[data-tab="outgoing"] .tab-count').textContent = outgoingCount;
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
                            <div class="empty-state-icon">🎫</div>
                            <div class="empty-state-text">Kayıt bulunamadı</div>
                        </td>
                    </tr>
                `;
            } else {
                tbody.innerHTML = pageData.map(item => `
                    <tr>
                        <td>
                            <input type="checkbox" class="row-checkbox" data-id="${item.no}">
                        </td>
                        <td>${item.no}</td>
                        <td>${formatDateTime(item.tarih)}</td>
                        <td>${item.sube || '-'}</td>
                        <td>${item.birim || '-'}</td>
                        <td><span class="priority-badge priority-${item.oncelik}">${getPriorityText(item.oncelik)}</span></td>
                        <td><span class="status-badge status-${item.durum}">${getStatusText(item.durum)}</span></td>
                        <td>${item.konu}</td>
                        <td>
                            <div class="table-actions">
                                <button class="btn-detail" onclick="viewDetail(${item.no})">Detay</button>
                                <button class="btn-delete" onclick="deleteTicket(${item.no})" title="Sil">
                                    🗑️
                                </button>
                            </div>
                        </td>
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
                `${filteredData.length} kayıttan ${start} - ${end} arası gösteriliyor`;

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
                } else if (column === 'no') {
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

        // Tümünü seç/seçimi kaldır
        function toggleSelectAll(checkbox) {
            const rowCheckboxes = document.querySelectorAll('.row-checkbox');
            rowCheckboxes.forEach(cb => cb.checked = checkbox.checked);
        }

        // Detay görüntüle
        function viewDetail(ticketNo) {
            window.location.href = `Ticket-Detay.php?no=${ticketNo}`;
        }

        // Ticket sil
        function deleteTicket(ticketNo) {
            if (confirm(`Ticket #${ticketNo} silmek istediğinize emin misiniz?`)) {
                // Gerçek uygulamada API çağrısı yapılacak
                console.log('Ticket siliniyor:', ticketNo);
                alert('Ticket silindi (Demo)');
            }
        }

        // Yardımcı fonksiyonlar
        function formatDateTime(dateStr) {
            if (!dateStr) return '-';
            const date = new Date(dateStr);
            return date.toLocaleDateString('tr-TR') + ' ' + date.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
        }

        function getPriorityText(priority) {
            const priorityMap = {
                'high': 'Yüksek',
                'medium': 'Orta',
                'low': 'Düşük'
            };
            return priorityMap[priority] || priority;
        }

        function getStatusText(status) {
            const statusMap = {
                'open': 'Açık',
                'reviewing': 'İnceleniyor',
                'closed': 'Kapalı'
            };
            return statusMap[status] || status;
        }

        // Event listeners
        document.getElementById('unread-only').addEventListener('change', applyFilters);
        document.getElementById('filter-priority').addEventListener('change', applyFilters);
        document.getElementById('filter-status').addEventListener('change', applyFilters);
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
                { no: 64, tarih: '2025-11-14T03:12:00', sube: 'Suadiye', birim: '', oncelik: 'high', durum: 'open', konu: 'TEST2', type: 'incoming', okundu: false },
                { no: 63, tarih: '2025-11-14T03:04:00', sube: 'Suadiye', birim: '', oncelik: 'medium', durum: 'open', konu: 'TEST', type: 'incoming', okundu: false },
                { no: 62, tarih: '2025-11-13T01:06:00', sube: '', birim: 'IT', oncelik: 'medium', durum: 'open', konu: 'TEST', type: 'outgoing', okundu: false },
                { no: 61, tarih: '2025-11-13T11:38:00', sube: '', birim: 'Genel', oncelik: 'low', durum: 'open', konu: 'sistemde ne srun var', type: 'outgoing', okundu: false },
                { no: 44, tarih: '2025-11-13T11:32:00', sube: '', birim: 'IT', oncelik: 'low', durum: 'open', konu: 'orion ekranlarımda sorun var.', type: 'outgoing', okundu: false },
                { no: 40, tarih: '2025-11-10T23:55:00', sube: '', birim: 'IT', oncelik: 'high', durum: 'reviewing', konu: 'Development TEST', type: 'outgoing', okundu: false },
                { no: 39, tarih: '2025-11-06T15:56:00', sube: '', birim: 'IT', oncelik: 'medium', durum: 'closed', konu: 'şube mesaj özgür', type: 'outgoing', okundu: true },
                { no: 37, tarih: '2025-11-06T15:55:00', sube: '', birim: 'Satınalma', oncelik: 'medium', durum: 'open', konu: 'şube mesaj', type: 'outgoing', okundu: false }
            ];

            filteredData = [...allData];
            renderTable();
            updateTabCounts();
        });
    </script>
</body>
</html>

