<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece MS (Muse) kullanıcıları giriş yapabilir
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'MS') {
    header("Location: index.php");
    exit;
}

// Statik etkinlik verileri
$etkinlikler = [
    [
        'id' => 1,
        'ad' => 'Yılbaşı Kutlaması',
        'baslangicTarihi' => '2024-12-31',
        'baslangicSaati' => '20:00',
        'bitisTarihi' => '2025-01-01',
        'bitisSaati' => '02:00',
        'kapasite' => 150,
        'yer' => 'Taksim Şube',
        'durum' => 'Planlandı'
    ],
    [
        'id' => 2,
        'ad' => 'Müzik Gecesi',
        'baslangicTarihi' => '2024-12-15',
        'baslangicSaati' => '19:30',
        'bitisTarihi' => '2024-12-15',
        'bitisSaati' => '23:00',
        'kapasite' => 80,
        'yer' => 'Kadıköy Şube',
        'durum' => 'Tamamlandı'
    ],
    [
        'id' => 3,
        'ad' => 'Workshop: Dijital Pazarlama',
        'baslangicTarihi' => '2024-12-20',
        'baslangicSaati' => '14:00',
        'bitisTarihi' => '2024-12-20',
        'bitisSaati' => '17:00',
        'kapasite' => 50,
        'yer' => 'Harici Lokasyon',
        'durum' => 'Planlandı'
    ],
    [
        'id' => 4,
        'ad' => 'Sanat Sergisi',
        'baslangicTarihi' => '2024-11-10',
        'baslangicSaati' => '10:00',
        'bitisTarihi' => '2024-11-20',
        'bitisSaati' => '18:00',
        'kapasite' => 200,
        'yer' => 'Taksim Şube',
        'durum' => 'İptal'
    ],
    [
        'id' => 5,
        'ad' => 'Konser: Yerli Sanatçılar',
        'baslangicTarihi' => '2024-12-25',
        'baslangicSaati' => '21:00',
        'bitisTarihi' => '2024-12-25',
        'bitisSaati' => '00:30',
        'kapasite' => 120,
        'yer' => 'Kadıköy Şube',
        'durum' => 'Planlandı'
    ]
];

$subeler = ['Taksim Şube', 'Kadıköy Şube', 'Harici Lokasyon'];
$durumlar = ['Planlandı', 'Tamamlandı', 'İptal'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etkinlikler - MINOA</title>
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
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.filter-group label {
    font-size: 13px;
    font-weight: 500;
    color: #374151;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.filter-buttons {
    display: flex;
    gap: 12px;
    margin-top: 8px;
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

.durum-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    display: inline-block;
}

.durum-planlandi {
    background: #dbeafe;
    color: #1e40af;
}

.durum-tamamlandi {
    background: #d1fae5;
    color: #065f46;
}

.durum-iptal {
    background: #fee2e2;
    color: #991b1b;
}

/* Pagination */
.pagination-section {
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #e5e7eb;
}

.pagination {
    display: flex;
    gap: 8px;
    align-items: center;
}

.pagination button {
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    background: white;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.pagination button:hover:not(:disabled) {
    background: #f0f9ff;
    border-color: #3b82f6;
    color: #3b82f6;
}

.pagination button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination button.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.total-records {
    font-size: 14px;
    color: #6b7280;
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

    .filter-row {
        grid-template-columns: 1fr;
    }

    .pagination-section {
        flex-direction: column;
        gap: 16px;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2>Etkinlikler</h2>
            <button class="btn btn-primary" onclick="window.location.href='MuseSO.php'">+ Yeni Etkinlik Oluştur</button>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <div class="filter-section">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Etkinlik Adı</label>
                            <input type="text" id="filterAd" placeholder="Etkinlik adı ara...">
                        </div>
                        <div class="filter-group">
                            <label>Başlangıç Tarihi</label>
                            <input type="date" id="filterBaslangic">
                        </div>
                        <div class="filter-group">
                            <label>Bitiş Tarihi</label>
                            <input type="date" id="filterBitis">
                        </div>
                        <div class="filter-group">
                            <label>Kapasite (Min)</label>
                            <input type="number" id="filterKapasiteMin" placeholder="Min">
                        </div>
                        <div class="filter-group">
                            <label>Kapasite (Max)</label>
                            <input type="number" id="filterKapasiteMax" placeholder="Max">
                        </div>
                        <div class="filter-group">
                            <label>Etkinlik Yeri</label>
                            <select id="filterYer">
                                <option value="">Tümü</option>
                                <?php foreach ($subeler as $sube): ?>
                                <option value="<?= htmlspecialchars($sube) ?>"><?= htmlspecialchars($sube) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="filter-buttons">
                        <button class="btn btn-primary" onclick="performSearch()">Ara</button>
                        <button class="btn btn-secondary" onclick="clearFilters()">Temizle</button>
                    </div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Etkinlik Adı</th>
                                <th>Başlangıç Tarihi - Saati</th>
                                <th>Bitiş Tarihi - Saati</th>
                                <th>Kapasite</th>
                                <th>Etkinlik Yeri / Şube</th>
                                <th>Durum</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php foreach ($etkinlikler as $etkinlik): ?>
                            <tr>
                                <td><?= htmlspecialchars($etkinlik['ad']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['baslangicTarihi']) ?> <?= htmlspecialchars($etkinlik['baslangicSaati']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['bitisTarihi']) ?> <?= htmlspecialchars($etkinlik['bitisSaati']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['kapasite']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['yer']) ?></td>
                                <td>
                                    <span class="durum-badge durum-<?= strtolower($etkinlik['durum']) ?>">
                                        <?= htmlspecialchars($etkinlik['durum']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="MuseDetay.php?id=<?= $etkinlik['id'] ?>" class="btn-view">Detay</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-section">
                    <div class="pagination">
                        <button onclick="changePage(1)" id="page1">1</button>
                        <button onclick="changePage(2)" id="page2">2</button>
                        <button onclick="changePage(3)" id="page3">3</button>
                    </div>
                    <div class="total-records">
                        Toplam <strong id="totalCount"><?= count($etkinlikler) ?></strong> kayıt
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let allEvents = <?= json_encode($etkinlikler, JSON_UNESCAPED_UNICODE) ?>;
        let filteredEvents = [...allEvents];
        let currentPage = 1;
        const itemsPerPage = 10;

        function performSearch() {
            const filterAd = document.getElementById('filterAd').value.toLowerCase().trim();
            const filterBaslangic = document.getElementById('filterBaslangic').value;
            const filterBitis = document.getElementById('filterBitis').value;
            const filterKapasiteMin = parseInt(document.getElementById('filterKapasiteMin').value) || 0;
            const filterKapasiteMax = parseInt(document.getElementById('filterKapasiteMax').value) || Infinity;
            const filterYer = document.getElementById('filterYer').value;

            filteredEvents = allEvents.filter(event => {
                const adMatch = !filterAd || event.ad.toLowerCase().includes(filterAd);
                const baslangicMatch = !filterBaslangic || event.baslangicTarihi >= filterBaslangic;
                const bitisMatch = !filterBitis || event.bitisTarihi <= filterBitis;
                const kapasiteMatch = event.kapasite >= filterKapasiteMin && event.kapasite <= filterKapasiteMax;
                const yerMatch = !filterYer || event.yer === filterYer;

                return adMatch && baslangicMatch && bitisMatch && kapasiteMatch && yerMatch;
            });

            currentPage = 1;
            renderTable();
            updatePagination();
        }

        function clearFilters() {
            document.getElementById('filterAd').value = '';
            document.getElementById('filterBaslangic').value = '';
            document.getElementById('filterBitis').value = '';
            document.getElementById('filterKapasiteMin').value = '';
            document.getElementById('filterKapasiteMax').value = '';
            document.getElementById('filterYer').value = '';
            
            filteredEvents = [...allEvents];
            currentPage = 1;
            renderTable();
            updatePagination();
        }

        function renderTable() {
            const tbody = document.getElementById('tableBody');
            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            const pageEvents = filteredEvents.slice(start, end);

            tbody.innerHTML = pageEvents.map(event => {
                const durumClass = 'durum-' + event.durum.toLowerCase();
                return `
                    <tr>
                        <td>${escapeHtml(event.ad)}</td>
                        <td>${escapeHtml(event.baslangicTarihi)} ${escapeHtml(event.baslangicSaati)}</td>
                        <td>${escapeHtml(event.bitisTarihi)} ${escapeHtml(event.bitisSaati)}</td>
                        <td>${escapeHtml(event.kapasite)}</td>
                        <td>${escapeHtml(event.yer)}</td>
                        <td><span class="durum-badge ${durumClass}">${escapeHtml(event.durum)}</span></td>
                        <td><a href="MuseDetay.php?id=${event.id}" class="btn-view">Detay</a></td>
                    </tr>
                `;
            }).join('');

            document.getElementById('totalCount').textContent = filteredEvents.length;
        }

        function updatePagination() {
            const totalPages = Math.ceil(filteredEvents.length / itemsPerPage);
            // Basit pagination - gerçek implementasyon için daha fazla sayfa butonu gerekebilir
            for (let i = 1; i <= 3; i++) {
                const btn = document.getElementById('page' + i);
                if (btn) {
                    btn.classList.toggle('active', i === currentPage);
                    btn.disabled = i > totalPages;
                }
            }
        }

        function changePage(page) {
            const totalPages = Math.ceil(filteredEvents.length / itemsPerPage);
            if (page >= 1 && page <= totalPages) {
                currentPage = page;
                renderTable();
                updatePagination();
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Enter tuşu ile arama
        document.getElementById('filterAd').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });

        // İlk render
        renderTable();
        updatePagination();
    </script>
</body>
</html>


if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece MS (Muse) kullanıcıları giriş yapabilir
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'MS') {
    header("Location: index.php");
    exit;
}

// Statik etkinlik verileri
$etkinlikler = [
    [
        'id' => 1,
        'ad' => 'Yılbaşı Kutlaması',
        'baslangicTarihi' => '2024-12-31',
        'baslangicSaati' => '20:00',
        'bitisTarihi' => '2025-01-01',
        'bitisSaati' => '02:00',
        'kapasite' => 150,
        'yer' => 'Taksim Şube',
        'durum' => 'Planlandı'
    ],
    [
        'id' => 2,
        'ad' => 'Müzik Gecesi',
        'baslangicTarihi' => '2024-12-15',
        'baslangicSaati' => '19:30',
        'bitisTarihi' => '2024-12-15',
        'bitisSaati' => '23:00',
        'kapasite' => 80,
        'yer' => 'Kadıköy Şube',
        'durum' => 'Tamamlandı'
    ],
    [
        'id' => 3,
        'ad' => 'Workshop: Dijital Pazarlama',
        'baslangicTarihi' => '2024-12-20',
        'baslangicSaati' => '14:00',
        'bitisTarihi' => '2024-12-20',
        'bitisSaati' => '17:00',
        'kapasite' => 50,
        'yer' => 'Harici Lokasyon',
        'durum' => 'Planlandı'
    ],
    [
        'id' => 4,
        'ad' => 'Sanat Sergisi',
        'baslangicTarihi' => '2024-11-10',
        'baslangicSaati' => '10:00',
        'bitisTarihi' => '2024-11-20',
        'bitisSaati' => '18:00',
        'kapasite' => 200,
        'yer' => 'Taksim Şube',
        'durum' => 'İptal'
    ],
    [
        'id' => 5,
        'ad' => 'Konser: Yerli Sanatçılar',
        'baslangicTarihi' => '2024-12-25',
        'baslangicSaati' => '21:00',
        'bitisTarihi' => '2024-12-25',
        'bitisSaati' => '00:30',
        'kapasite' => 120,
        'yer' => 'Kadıköy Şube',
        'durum' => 'Planlandı'
    ]
];

$subeler = ['Taksim Şube', 'Kadıköy Şube', 'Harici Lokasyon'];
$durumlar = ['Planlandı', 'Tamamlandı', 'İptal'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etkinlikler - MINOA</title>
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
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.filter-group label {
    font-size: 13px;
    font-weight: 500;
    color: #374151;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.filter-buttons {
    display: flex;
    gap: 12px;
    margin-top: 8px;
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

.durum-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    display: inline-block;
}

.durum-planlandi {
    background: #dbeafe;
    color: #1e40af;
}

.durum-tamamlandi {
    background: #d1fae5;
    color: #065f46;
}

.durum-iptal {
    background: #fee2e2;
    color: #991b1b;
}

/* Pagination */
.pagination-section {
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #e5e7eb;
}

.pagination {
    display: flex;
    gap: 8px;
    align-items: center;
}

.pagination button {
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    background: white;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.pagination button:hover:not(:disabled) {
    background: #f0f9ff;
    border-color: #3b82f6;
    color: #3b82f6;
}

.pagination button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination button.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.total-records {
    font-size: 14px;
    color: #6b7280;
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

    .filter-row {
        grid-template-columns: 1fr;
    }

    .pagination-section {
        flex-direction: column;
        gap: 16px;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2>Etkinlikler</h2>
            <button class="btn btn-primary" onclick="window.location.href='MuseSO.php'">+ Yeni Etkinlik Oluştur</button>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <div class="filter-section">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Etkinlik Adı</label>
                            <input type="text" id="filterAd" placeholder="Etkinlik adı ara...">
                        </div>
                        <div class="filter-group">
                            <label>Başlangıç Tarihi</label>
                            <input type="date" id="filterBaslangic">
                        </div>
                        <div class="filter-group">
                            <label>Bitiş Tarihi</label>
                            <input type="date" id="filterBitis">
                        </div>
                        <div class="filter-group">
                            <label>Kapasite (Min)</label>
                            <input type="number" id="filterKapasiteMin" placeholder="Min">
                        </div>
                        <div class="filter-group">
                            <label>Kapasite (Max)</label>
                            <input type="number" id="filterKapasiteMax" placeholder="Max">
                        </div>
                        <div class="filter-group">
                            <label>Etkinlik Yeri</label>
                            <select id="filterYer">
                                <option value="">Tümü</option>
                                <?php foreach ($subeler as $sube): ?>
                                <option value="<?= htmlspecialchars($sube) ?>"><?= htmlspecialchars($sube) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="filter-buttons">
                        <button class="btn btn-primary" onclick="performSearch()">Ara</button>
                        <button class="btn btn-secondary" onclick="clearFilters()">Temizle</button>
                    </div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Etkinlik Adı</th>
                                <th>Başlangıç Tarihi - Saati</th>
                                <th>Bitiş Tarihi - Saati</th>
                                <th>Kapasite</th>
                                <th>Etkinlik Yeri / Şube</th>
                                <th>Durum</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php foreach ($etkinlikler as $etkinlik): ?>
                            <tr>
                                <td><?= htmlspecialchars($etkinlik['ad']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['baslangicTarihi']) ?> <?= htmlspecialchars($etkinlik['baslangicSaati']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['bitisTarihi']) ?> <?= htmlspecialchars($etkinlik['bitisSaati']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['kapasite']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['yer']) ?></td>
                                <td>
                                    <span class="durum-badge durum-<?= strtolower($etkinlik['durum']) ?>">
                                        <?= htmlspecialchars($etkinlik['durum']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="MuseDetay.php?id=<?= $etkinlik['id'] ?>" class="btn-view">Detay</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-section">
                    <div class="pagination">
                        <button onclick="changePage(1)" id="page1">1</button>
                        <button onclick="changePage(2)" id="page2">2</button>
                        <button onclick="changePage(3)" id="page3">3</button>
                    </div>
                    <div class="total-records">
                        Toplam <strong id="totalCount"><?= count($etkinlikler) ?></strong> kayıt
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let allEvents = <?= json_encode($etkinlikler, JSON_UNESCAPED_UNICODE) ?>;
        let filteredEvents = [...allEvents];
        let currentPage = 1;
        const itemsPerPage = 10;

        function performSearch() {
            const filterAd = document.getElementById('filterAd').value.toLowerCase().trim();
            const filterBaslangic = document.getElementById('filterBaslangic').value;
            const filterBitis = document.getElementById('filterBitis').value;
            const filterKapasiteMin = parseInt(document.getElementById('filterKapasiteMin').value) || 0;
            const filterKapasiteMax = parseInt(document.getElementById('filterKapasiteMax').value) || Infinity;
            const filterYer = document.getElementById('filterYer').value;

            filteredEvents = allEvents.filter(event => {
                const adMatch = !filterAd || event.ad.toLowerCase().includes(filterAd);
                const baslangicMatch = !filterBaslangic || event.baslangicTarihi >= filterBaslangic;
                const bitisMatch = !filterBitis || event.bitisTarihi <= filterBitis;
                const kapasiteMatch = event.kapasite >= filterKapasiteMin && event.kapasite <= filterKapasiteMax;
                const yerMatch = !filterYer || event.yer === filterYer;

                return adMatch && baslangicMatch && bitisMatch && kapasiteMatch && yerMatch;
            });

            currentPage = 1;
            renderTable();
            updatePagination();
        }

        function clearFilters() {
            document.getElementById('filterAd').value = '';
            document.getElementById('filterBaslangic').value = '';
            document.getElementById('filterBitis').value = '';
            document.getElementById('filterKapasiteMin').value = '';
            document.getElementById('filterKapasiteMax').value = '';
            document.getElementById('filterYer').value = '';
            
            filteredEvents = [...allEvents];
            currentPage = 1;
            renderTable();
            updatePagination();
        }

        function renderTable() {
            const tbody = document.getElementById('tableBody');
            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            const pageEvents = filteredEvents.slice(start, end);

            tbody.innerHTML = pageEvents.map(event => {
                const durumClass = 'durum-' + event.durum.toLowerCase();
                return `
                    <tr>
                        <td>${escapeHtml(event.ad)}</td>
                        <td>${escapeHtml(event.baslangicTarihi)} ${escapeHtml(event.baslangicSaati)}</td>
                        <td>${escapeHtml(event.bitisTarihi)} ${escapeHtml(event.bitisSaati)}</td>
                        <td>${escapeHtml(event.kapasite)}</td>
                        <td>${escapeHtml(event.yer)}</td>
                        <td><span class="durum-badge ${durumClass}">${escapeHtml(event.durum)}</span></td>
                        <td><a href="MuseDetay.php?id=${event.id}" class="btn-view">Detay</a></td>
                    </tr>
                `;
            }).join('');

            document.getElementById('totalCount').textContent = filteredEvents.length;
        }

        function updatePagination() {
            const totalPages = Math.ceil(filteredEvents.length / itemsPerPage);
            // Basit pagination - gerçek implementasyon için daha fazla sayfa butonu gerekebilir
            for (let i = 1; i <= 3; i++) {
                const btn = document.getElementById('page' + i);
                if (btn) {
                    btn.classList.toggle('active', i === currentPage);
                    btn.disabled = i > totalPages;
                }
            }
        }

        function changePage(page) {
            const totalPages = Math.ceil(filteredEvents.length / itemsPerPage);
            if (page >= 1 && page <= totalPages) {
                currentPage = page;
                renderTable();
                updatePagination();
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Enter tuşu ile arama
        document.getElementById('filterAd').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });

        // İlk render
        renderTable();
        updatePagination();
    </script>
</body>
</html>


if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece MS (Muse) kullanıcıları giriş yapabilir
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'MS') {
    header("Location: index.php");
    exit;
}

// Statik etkinlik verileri
$etkinlikler = [
    [
        'id' => 1,
        'ad' => 'Yılbaşı Kutlaması',
        'baslangicTarihi' => '2024-12-31',
        'baslangicSaati' => '20:00',
        'bitisTarihi' => '2025-01-01',
        'bitisSaati' => '02:00',
        'kapasite' => 150,
        'yer' => 'Taksim Şube',
        'durum' => 'Planlandı'
    ],
    [
        'id' => 2,
        'ad' => 'Müzik Gecesi',
        'baslangicTarihi' => '2024-12-15',
        'baslangicSaati' => '19:30',
        'bitisTarihi' => '2024-12-15',
        'bitisSaati' => '23:00',
        'kapasite' => 80,
        'yer' => 'Kadıköy Şube',
        'durum' => 'Tamamlandı'
    ],
    [
        'id' => 3,
        'ad' => 'Workshop: Dijital Pazarlama',
        'baslangicTarihi' => '2024-12-20',
        'baslangicSaati' => '14:00',
        'bitisTarihi' => '2024-12-20',
        'bitisSaati' => '17:00',
        'kapasite' => 50,
        'yer' => 'Harici Lokasyon',
        'durum' => 'Planlandı'
    ],
    [
        'id' => 4,
        'ad' => 'Sanat Sergisi',
        'baslangicTarihi' => '2024-11-10',
        'baslangicSaati' => '10:00',
        'bitisTarihi' => '2024-11-20',
        'bitisSaati' => '18:00',
        'kapasite' => 200,
        'yer' => 'Taksim Şube',
        'durum' => 'İptal'
    ],
    [
        'id' => 5,
        'ad' => 'Konser: Yerli Sanatçılar',
        'baslangicTarihi' => '2024-12-25',
        'baslangicSaati' => '21:00',
        'bitisTarihi' => '2024-12-25',
        'bitisSaati' => '00:30',
        'kapasite' => 120,
        'yer' => 'Kadıköy Şube',
        'durum' => 'Planlandı'
    ]
];

$subeler = ['Taksim Şube', 'Kadıköy Şube', 'Harici Lokasyon'];
$durumlar = ['Planlandı', 'Tamamlandı', 'İptal'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etkinlikler - MINOA</title>
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
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 16px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.filter-group label {
    font-size: 13px;
    font-weight: 500;
    color: #374151;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.2s;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.filter-buttons {
    display: flex;
    gap: 12px;
    margin-top: 8px;
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

.durum-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    display: inline-block;
}

.durum-planlandi {
    background: #dbeafe;
    color: #1e40af;
}

.durum-tamamlandi {
    background: #d1fae5;
    color: #065f46;
}

.durum-iptal {
    background: #fee2e2;
    color: #991b1b;
}

/* Pagination */
.pagination-section {
    padding: 20px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #e5e7eb;
}

.pagination {
    display: flex;
    gap: 8px;
    align-items: center;
}

.pagination button {
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    background: white;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.pagination button:hover:not(:disabled) {
    background: #f0f9ff;
    border-color: #3b82f6;
    color: #3b82f6;
}

.pagination button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.pagination button.active {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.total-records {
    font-size: 14px;
    color: #6b7280;
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

    .filter-row {
        grid-template-columns: 1fr;
    }

    .pagination-section {
        flex-direction: column;
        gap: 16px;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2>Etkinlikler</h2>
            <button class="btn btn-primary" onclick="window.location.href='MuseSO.php'">+ Yeni Etkinlik Oluştur</button>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <div class="filter-section">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Etkinlik Adı</label>
                            <input type="text" id="filterAd" placeholder="Etkinlik adı ara...">
                        </div>
                        <div class="filter-group">
                            <label>Başlangıç Tarihi</label>
                            <input type="date" id="filterBaslangic">
                        </div>
                        <div class="filter-group">
                            <label>Bitiş Tarihi</label>
                            <input type="date" id="filterBitis">
                        </div>
                        <div class="filter-group">
                            <label>Kapasite (Min)</label>
                            <input type="number" id="filterKapasiteMin" placeholder="Min">
                        </div>
                        <div class="filter-group">
                            <label>Kapasite (Max)</label>
                            <input type="number" id="filterKapasiteMax" placeholder="Max">
                        </div>
                        <div class="filter-group">
                            <label>Etkinlik Yeri</label>
                            <select id="filterYer">
                                <option value="">Tümü</option>
                                <?php foreach ($subeler as $sube): ?>
                                <option value="<?= htmlspecialchars($sube) ?>"><?= htmlspecialchars($sube) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="filter-buttons">
                        <button class="btn btn-primary" onclick="performSearch()">Ara</button>
                        <button class="btn btn-secondary" onclick="clearFilters()">Temizle</button>
                    </div>
                </div>

                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Etkinlik Adı</th>
                                <th>Başlangıç Tarihi - Saati</th>
                                <th>Bitiş Tarihi - Saati</th>
                                <th>Kapasite</th>
                                <th>Etkinlik Yeri / Şube</th>
                                <th>Durum</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php foreach ($etkinlikler as $etkinlik): ?>
                            <tr>
                                <td><?= htmlspecialchars($etkinlik['ad']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['baslangicTarihi']) ?> <?= htmlspecialchars($etkinlik['baslangicSaati']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['bitisTarihi']) ?> <?= htmlspecialchars($etkinlik['bitisSaati']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['kapasite']) ?></td>
                                <td><?= htmlspecialchars($etkinlik['yer']) ?></td>
                                <td>
                                    <span class="durum-badge durum-<?= strtolower($etkinlik['durum']) ?>">
                                        <?= htmlspecialchars($etkinlik['durum']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="MuseDetay.php?id=<?= $etkinlik['id'] ?>" class="btn-view">Detay</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-section">
                    <div class="pagination">
                        <button onclick="changePage(1)" id="page1">1</button>
                        <button onclick="changePage(2)" id="page2">2</button>
                        <button onclick="changePage(3)" id="page3">3</button>
                    </div>
                    <div class="total-records">
                        Toplam <strong id="totalCount"><?= count($etkinlikler) ?></strong> kayıt
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let allEvents = <?= json_encode($etkinlikler, JSON_UNESCAPED_UNICODE) ?>;
        let filteredEvents = [...allEvents];
        let currentPage = 1;
        const itemsPerPage = 10;

        function performSearch() {
            const filterAd = document.getElementById('filterAd').value.toLowerCase().trim();
            const filterBaslangic = document.getElementById('filterBaslangic').value;
            const filterBitis = document.getElementById('filterBitis').value;
            const filterKapasiteMin = parseInt(document.getElementById('filterKapasiteMin').value) || 0;
            const filterKapasiteMax = parseInt(document.getElementById('filterKapasiteMax').value) || Infinity;
            const filterYer = document.getElementById('filterYer').value;

            filteredEvents = allEvents.filter(event => {
                const adMatch = !filterAd || event.ad.toLowerCase().includes(filterAd);
                const baslangicMatch = !filterBaslangic || event.baslangicTarihi >= filterBaslangic;
                const bitisMatch = !filterBitis || event.bitisTarihi <= filterBitis;
                const kapasiteMatch = event.kapasite >= filterKapasiteMin && event.kapasite <= filterKapasiteMax;
                const yerMatch = !filterYer || event.yer === filterYer;

                return adMatch && baslangicMatch && bitisMatch && kapasiteMatch && yerMatch;
            });

            currentPage = 1;
            renderTable();
            updatePagination();
        }

        function clearFilters() {
            document.getElementById('filterAd').value = '';
            document.getElementById('filterBaslangic').value = '';
            document.getElementById('filterBitis').value = '';
            document.getElementById('filterKapasiteMin').value = '';
            document.getElementById('filterKapasiteMax').value = '';
            document.getElementById('filterYer').value = '';
            
            filteredEvents = [...allEvents];
            currentPage = 1;
            renderTable();
            updatePagination();
        }

        function renderTable() {
            const tbody = document.getElementById('tableBody');
            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            const pageEvents = filteredEvents.slice(start, end);

            tbody.innerHTML = pageEvents.map(event => {
                const durumClass = 'durum-' + event.durum.toLowerCase();
                return `
                    <tr>
                        <td>${escapeHtml(event.ad)}</td>
                        <td>${escapeHtml(event.baslangicTarihi)} ${escapeHtml(event.baslangicSaati)}</td>
                        <td>${escapeHtml(event.bitisTarihi)} ${escapeHtml(event.bitisSaati)}</td>
                        <td>${escapeHtml(event.kapasite)}</td>
                        <td>${escapeHtml(event.yer)}</td>
                        <td><span class="durum-badge ${durumClass}">${escapeHtml(event.durum)}</span></td>
                        <td><a href="MuseDetay.php?id=${event.id}" class="btn-view">Detay</a></td>
                    </tr>
                `;
            }).join('');

            document.getElementById('totalCount').textContent = filteredEvents.length;
        }

        function updatePagination() {
            const totalPages = Math.ceil(filteredEvents.length / itemsPerPage);
            // Basit pagination - gerçek implementasyon için daha fazla sayfa butonu gerekebilir
            for (let i = 1; i <= 3; i++) {
                const btn = document.getElementById('page' + i);
                if (btn) {
                    btn.classList.toggle('active', i === currentPage);
                    btn.disabled = i > totalPages;
                }
            }
        }

        function changePage(page) {
            const totalPages = Math.ceil(filteredEvents.length / itemsPerPage);
            if (page >= 1 && page <= totalPages) {
                currentPage = page;
                renderTable();
                updatePagination();
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Enter tuşu ile arama
        document.getElementById('filterAd').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });

        // İlk render
        renderTable();
        updatePagination();
    </script>
</body>
</html>

