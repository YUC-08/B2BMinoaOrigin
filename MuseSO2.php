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

// Adım 1'den gelen veriler (şimdilik statik, sonra session'dan gelecek)
$etkinlikAdi = $_GET['etkinlikAdi'] ?? 'Yılbaşı Kutlaması';
$etkinlikTarihi = $_GET['baslangicTarihi'] ?? '2024-12-31';
$secilenSube = $_GET['sube'] ?? 'Taksim Şube';

// Statik veriler
$kitapeviUrunleri = [
    ['kod' => 'KIT001', 'isbn' => '978-1234567890', 'ad' => 'Sapiens', 'yazar' => 'Yuval Noah Harari', 'tur' => 'Tarih', 'stok' => 25, 'birim' => 'Adet'],
    ['kod' => 'KIT002', 'isbn' => '978-0987654321', 'ad' => 'Homo Deus', 'yazar' => 'Yuval Noah Harari', 'tur' => 'Tarih', 'stok' => 15, 'birim' => 'Adet'],
    ['kod' => 'KIT003', 'isbn' => '978-1122334455', 'ad' => 'Dune', 'yazar' => 'Frank Herbert', 'tur' => 'Bilim Kurgu', 'stok' => 30, 'birim' => 'Adet'],
    ['kod' => 'KIT004', 'isbn' => '978-5566778899', 'ad' => '1984', 'yazar' => 'George Orwell', 'tur' => 'Distopya', 'stok' => 20, 'birim' => 'Adet'],
    ['kod' => 'KIT005', 'isbn' => '978-9988776655', 'ad' => 'Suç ve Ceza', 'yazar' => 'Fyodor Dostoyevski', 'tur' => 'Klasik', 'stok' => 18, 'birim' => 'Adet']
];

$kafeUrunleri = [
    ['kod' => 'KAF001', 'ad' => 'Espresso', 'kategori' => 'Sıcak İçecek', 'stok' => 100, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF002', 'ad' => 'Cappuccino', 'kategori' => 'Sıcak İçecek', 'stok' => 80, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF003', 'ad' => 'Latte', 'kategori' => 'Sıcak İçecek', 'stok' => 75, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF004', 'ad' => 'Soğuk Kahve', 'kategori' => 'Soğuk İçecek', 'stok' => 60, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF005', 'ad' => 'Cheesecake', 'kategori' => 'Tatlı', 'stok' => 40, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF006', 'ad' => 'Brownie', 'kategori' => 'Tatlı', 'stok' => 35, 'birim' => 'Porsiyon']
];

$restoranUrunleri = [
    ['kod' => 'RES001', 'ad' => 'Izgara Tavuk', 'menuGrubu' => 'Ana Yemek', 'stok' => 50, 'birim' => 'Porsiyon'],
    ['kod' => 'RES002', 'ad' => 'Mantı', 'menuGrubu' => 'Ana Yemek', 'stok' => 45, 'birim' => 'Porsiyon'],
    ['kod' => 'RES003', 'ad' => 'Humus', 'menuGrubu' => 'Meze', 'stok' => 30, 'birim' => 'Porsiyon'],
    ['kod' => 'RES004', 'ad' => 'Cacık', 'menuGrubu' => 'Meze', 'stok' => 25, 'birim' => 'Porsiyon'],
    ['kod' => 'RES005', 'ad' => 'Çoban Salata', 'menuGrubu' => 'Salata', 'stok' => 40, 'birim' => 'Porsiyon'],
    ['kod' => 'RES006', 'ad' => 'Mevsim Salata', 'menuGrubu' => 'Salata', 'stok' => 35, 'birim' => 'Porsiyon']
];

$kategoriler = ['Kitapevi', 'Kafe', 'Restoran'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etkinlik İçeriğini Oluştur - MINOA</title>
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

.page-header-info {
    font-size: 13px;
    color: #6b7280;
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.page-header-info span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.page-header-content {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}

.main-layout {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 24px;
}

.left-panel {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.right-panel {
    position: sticky;
    top: 100px;
    height: fit-content;
    max-height: calc(100vh - 120px);
    overflow-y: auto;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible;
}

/* Category Tabs */
.category-tabs {
    display: flex;
    gap: 12px;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
    flex-wrap: wrap;
}

.category-tab {
    padding: 12px 24px;
    border: 2px solid #e5e7eb;
    background: white;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    color: #6b7280;
}

.category-tab:hover {
    border-color: #3b82f6;
    color: #3b82f6;
}

.category-tab.active {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border-color: #3b82f6;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

/* Filter Section */
.filter-section {
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 12px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 500;
    color: #374151;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 13px;
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

/* Table */
.table-container {
    overflow-x: auto;
    max-height: 600px;
    overflow-y: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

thead {
    background: #f8fafc;
    position: sticky;
    top: 0;
    z-index: 10;
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

.quantity-input {
    width: 80px;
    padding: 6px 8px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 13px;
    text-align: center;
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

.btn-small {
    padding: 6px 12px;
    font-size: 12px;
}

.btn-back {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-back:hover {
    background: #f0f9ff;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

/* Sepet Panel */
.sepet-panel {
    padding: 24px;
}

.sepet-panel h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e40af;
    margin-bottom: 12px;
}

.sepet-event-info {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #e5e7eb;
}

.sepet-table-container {
    max-height: 400px;
    overflow-y: auto;
    margin-bottom: 16px;
}

.sepet-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.sepet-table thead {
    background: #f8fafc;
    position: sticky;
    top: 0;
    z-index: 10;
}

.sepet-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    font-size: 12px;
    white-space: nowrap;
}

.sepet-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #e5e7eb;
    color: #4b5563;
    vertical-align: middle;
}

.sepet-table tbody tr:hover {
    background: #f9fafb;
}

.sepet-kaynak {
    font-size: 11px;
    font-weight: 500;
    padding: 4px 8px;
    border-radius: 4px;
    display: inline-block;
}

.sepet-kaynak-kitapevi {
    background: #dbeafe;
    color: #1e40af;
}

.sepet-kaynak-kafe {
    background: #fef3c7;
    color: #92400e;
}

.sepet-kaynak-restoran {
    background: #d1fae5;
    color: #065f46;
}


.sepet-not-input {
    width: 100%;
    padding: 4px 6px;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    font-size: 11px;
    font-family: inherit;
}

.sepet-not-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
}

.sepet-remove-btn {
    background: #fee2e2;
    color: #dc2626;
    border: none;
    border-radius: 4px;
    padding: 6px 10px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
}

.sepet-remove-btn:hover {
    background: #fecaca;
}

.sepet-empty {
    text-align: center;
    padding: 40px 20px;
    color: #9ca3af;
    font-size: 14px;
}

.sepet-summary {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 2px solid #e5e7eb;
}

.sepet-summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 13px;
    color: #6b7280;
}

.sepet-summary-row.total {
    font-size: 15px;
    font-weight: 600;
    color: #1e40af;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #e5e7eb;
}

.sepet-actions {
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.sepet-actions .btn {
    width: 100%;
    justify-content: center;
}


.hidden {
    display: none;
}

/* Responsive */
@media (max-width: 1200px) {
    .main-layout {
        grid-template-columns: 1fr;
    }

    .right-panel {
        position: static;
        max-height: none;
    }
}

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

    .category-tabs {
        flex-direction: column;
    }

    .category-tab {
        width: 100%;
    }

    .filter-row {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h2>Etkinlik İçeriğini Oluştur</h2>
                <div class="page-header-info">
                    <span><strong>Etkinlik:</strong> <?= htmlspecialchars($etkinlikAdi) ?></span>
                    <span><strong>Tarih:</strong> <?= htmlspecialchars($etkinlikTarihi) ?></span>
                    <span><strong>Şube:</strong> <?= htmlspecialchars($secilenSube) ?></span>
                </div>
            </div>
            <a href="MuseSO.php" class="btn btn-secondary">← Geri</a>
        </div>

        <div class="content-wrapper">
            <div class="main-layout">
                <!-- Sol Panel -->
                <div class="left-panel">
                    <!-- Kategori Butonları -->
                    <div class="card">
                        <div class="category-tabs">
                            <button class="category-tab active" onclick="switchCategory('kitapevi')">Kitapevi</button>
                            <button class="category-tab" onclick="switchCategory('kafe')">Kafe</button>
                            <button class="category-tab" onclick="switchCategory('restoran')">Restoran</button>
                        </div>

                        <!-- Kitapevi Tab -->
                        <div id="tab-kitapevi" class="tab-content">
                            <div class="filter-section">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label>Kitap Adı</label>
                                        <input type="text" id="kitapAdi" placeholder="Kitap adı ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Yazar</label>
                                        <input type="text" id="yazar" placeholder="Yazar ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Tür</label>
                                        <input type="text" id="tur" placeholder="Tür ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>ISBN</label>
                                        <input type="text" id="isbn" placeholder="ISBN ara...">
                                    </div>
                                </div>
                                <div class="filter-buttons">
                                    <button class="btn btn-primary btn-small" onclick="searchKitapevi()">Ara</button>
                                    <button class="btn btn-secondary btn-small" onclick="clearKitapeviFilters()">Temizle</button>
                                </div>
                            </div>

                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ürün Kodu / ISBN</th>
                                            <th>Kitap Adı</th>
                                            <th>Yazar</th>
                                            <th>Tür</th>
                                            <th>Stok</th>
                                            <th>Birim</th>
                                            <th>Etkinlik Miktarı</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="kitapeviTableBody">
                                        <?php foreach ($kitapeviUrunleri as $urun): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($urun['kod']) ?><br><small style="color: #9ca3af;"><?= htmlspecialchars($urun['isbn']) ?></small></td>
                                            <td><?= htmlspecialchars($urun['ad']) ?></td>
                                            <td><?= htmlspecialchars($urun['yazar']) ?></td>
                                            <td><?= htmlspecialchars($urun['tur']) ?></td>
                                            <td><?= htmlspecialchars($urun['stok']) ?></td>
                                            <td><?= htmlspecialchars($urun['birim']) ?></td>
                                            <td><input type="number" class="quantity-input" id="qty-<?= $urun['kod'] ?>" min="1" value="1"></td>
                                            <td><button class="btn btn-primary btn-small" onclick="addToCart('kitapevi', '<?= htmlspecialchars($urun['kod'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['ad'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['yazar'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['birim'], ENT_QUOTES) ?>')">Sepete Ekle</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Kafe Tab -->
                        <div id="tab-kafe" class="tab-content hidden">
                            <div class="filter-section">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label>Ürün Adı</label>
                                        <input type="text" id="kafeUrunAdi" placeholder="Ürün adı ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Kategori</label>
                                        <select id="kafeKategori">
                                            <option value="">Tümü</option>
                                            <option value="Sıcak İçecek">Sıcak İçecek</option>
                                            <option value="Soğuk İçecek">Soğuk İçecek</option>
                                            <option value="Tatlı">Tatlı</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="filter-buttons">
                                    <button class="btn btn-primary btn-small" onclick="searchKafe()">Ara</button>
                                    <button class="btn btn-secondary btn-small" onclick="clearKafeFilters()">Temizle</button>
                                </div>
                            </div>

                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ürün Kodu</th>
                                            <th>Ürün Adı</th>
                                            <th>Kategori</th>
                                            <th>Birim</th>
                                            <th>Stok</th>
                                            <th>Etkinlik Miktarı</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="kafeTableBody">
                                        <?php foreach ($kafeUrunleri as $urun): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($urun['kod']) ?></td>
                                            <td><?= htmlspecialchars($urun['ad']) ?></td>
                                            <td><?= htmlspecialchars($urun['kategori']) ?></td>
                                            <td><?= htmlspecialchars($urun['birim']) ?></td>
                                            <td><?= htmlspecialchars($urun['stok']) ?></td>
                                            <td><input type="number" class="quantity-input" id="qty-<?= $urun['kod'] ?>" min="1" value="1"></td>
                                            <td><button class="btn btn-primary btn-small" onclick="addToCart('kafe', '<?= htmlspecialchars($urun['kod'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['ad'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['kategori'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['birim'], ENT_QUOTES) ?>')">Sepete Ekle</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Restoran Tab -->
                        <div id="tab-restoran" class="tab-content hidden">
                            <div class="filter-section">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label>Ürün Adı</label>
                                        <input type="text" id="restoranUrunAdi" placeholder="Ürün adı ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Menü Grubu</label>
                                        <select id="restoranMenuGrubu">
                                            <option value="">Tümü</option>
                                            <option value="Ana Yemek">Ana Yemek</option>
                                            <option value="Meze">Meze</option>
                                            <option value="Salata">Salata</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="filter-buttons">
                                    <button class="btn btn-primary btn-small" onclick="searchRestoran()">Ara</button>
                                    <button class="btn btn-secondary btn-small" onclick="clearRestoranFilters()">Temizle</button>
                                </div>
                            </div>

                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ürün Kodu</th>
                                            <th>Ürün Adı</th>
                                            <th>Menü Grubu</th>
                                            <th>Birim</th>
                                            <th>Stok</th>
                                            <th>Etkinlik Miktarı</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="restoranTableBody">
                                        <?php foreach ($restoranUrunleri as $urun): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($urun['kod']) ?></td>
                                            <td><?= htmlspecialchars($urun['ad']) ?></td>
                                            <td><?= htmlspecialchars($urun['menuGrubu']) ?></td>
                                            <td><?= htmlspecialchars($urun['birim']) ?></td>
                                            <td><?= htmlspecialchars($urun['stok']) ?></td>
                                            <td><input type="number" class="quantity-input" id="qty-<?= $urun['kod'] ?>" min="1" value="1"></td>
                                            <td><button class="btn btn-primary btn-small" onclick="addToCart('restoran', '<?= htmlspecialchars($urun['kod'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['ad'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['menuGrubu'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['birim'], ENT_QUOTES) ?>')">Sepete Ekle</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Sağ Panel - Sepet -->
                <div class="right-panel">
                    <div class="card sepet-panel">
                        <h3>Etkinlik Sepeti</h3>
                        <div class="sepet-event-info">
                            <strong><?= htmlspecialchars($etkinlikAdi) ?></strong> – <?= htmlspecialchars($secilenSube) ?>
                        </div>
                        
                        <div id="sepetContent">
                            <div class="sepet-empty">Sepetiniz boş</div>
                        </div>
                        
                        <div id="sepetSummary" class="sepet-summary hidden">
                            <div class="sepet-summary-row">
                                <span>Toplam Satır:</span>
                                <span id="totalRows">0</span>
                            </div>
                            <div class="sepet-summary-row">
                                <span>Tahmini Bütçe:</span>
                                <span id="totalBudget">-</span>
                            </div>
                        </div>
                        
                        <div id="sepetActions" class="sepet-actions hidden">
                            <button class="btn btn-primary" onclick="createEventFromCart()">Sepetten Etkinlik Oluştur</button>
                            <button class="btn btn-secondary" onclick="clearCart()">Sepeti Temizle</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let cart = [];
        let currentCategory = 'kitapevi';

        function switchCategory(category) {
            currentCategory = category;
            
            // Tab butonlarını güncelle
            document.querySelectorAll('.category-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Tab içeriklerini göster/gizle
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            
            const targetTab = document.getElementById('tab-' + category);
            if (targetTab) {
                targetTab.classList.remove('hidden');
            }
        }

        function addToCart(category, kod, ad, detay, birim) {
            const qtyInput = document.getElementById('qty-' + kod);
            const quantity = parseInt(qtyInput.value) || 1;
            
            const cartItem = {
                id: Date.now() + Math.random(), // Unique ID
                category: category,
                kod: kod,
                ad: ad,
                detay: detay,
                birim: birim,
                miktar: quantity,
                not: ''
            };
            
            cart.push(cartItem);
            renderCart();
            
            // Input'u sıfırla
            qtyInput.value = 1;
        }

        function removeFromCart(id) {
            cart = cart.filter(item => item.id !== id);
            renderCart();
        }

        function renderCart() {
            const sepetContent = document.getElementById('sepetContent');
            const sepetSummary = document.getElementById('sepetSummary');
            const sepetActions = document.getElementById('sepetActions');
            
            if (cart.length === 0) {
                sepetContent.innerHTML = '<div class="sepet-empty">Sepetiniz boş</div>';
                sepetSummary.classList.add('hidden');
                sepetActions.classList.add('hidden');
            } else {
                const kaynakClassMap = {
                    'kitapevi': 'sepet-kaynak-kitapevi',
                    'kafe': 'sepet-kaynak-kafe',
                    'restoran': 'sepet-kaynak-restoran'
                };
                
                const kaynakLabelMap = {
                    'kitapevi': 'Kitapevi',
                    'kafe': 'Kafe',
                    'restoran': 'Restoran'
                };
                
                sepetContent.innerHTML = `
                    <div class="sepet-table-container">
                        <table class="sepet-table">
                            <thead>
                                <tr>
                                    <th>Kaynak</th>
                                    <th>Ürün / İçerik Adı</th>
                                    <th>Birim</th>
                                    <th>Miktar</th>
                                    <th>Not</th>
                                    <th>Sil</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${cart.map(item => {
                                    const kaynakClass = kaynakClassMap[item.category] || '';
                                    const kaynakLabel = kaynakLabelMap[item.category] || item.category;
                                    return `
                                        <tr>
                                            <td><span class="sepet-kaynak ${kaynakClass}">${escapeHtml(kaynakLabel)}</span></td>
                                            <td>${escapeHtml(item.ad)}</td>
                                            <td>${escapeHtml(item.birim)}</td>
                                            <td>${item.miktar}</td>
                                            <td>
                                                <input type="text" 
                                                       class="sepet-not-input" 
                                                       placeholder="Not ekle..." 
                                                       value="${escapeHtml(item.not || '')}"
                                                       onchange="updateCartNote(${item.id}, this.value)">
                                            </td>
                                            <td>
                                                <button class="sepet-remove-btn" onclick="removeFromCart(${item.id})" title="Sil">🗑️</button>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
                
                document.getElementById('totalRows').textContent = cart.length;
                sepetSummary.classList.remove('hidden');
                sepetActions.classList.remove('hidden');
            }
        }
        
        function updateCartNote(id, note) {
            const item = cart.find(i => i.id === id);
            if (item) {
                item.not = note;
            }
        }
        
        function clearCart() {
            if (confirm('Sepeti temizlemek istediğinize emin misiniz?')) {
                cart = [];
                renderCart();
            }
        }

        function createEventFromCart() {
            if (cart.length === 0) {
                alert('Sepetiniz boş. Lütfen önce ürün ekleyin.');
                return;
            }
            
            // Konfeti animasyonunu başlat
            startConfetti();
            
            // Başarı mesajını göster
            setTimeout(() => {
                showSuccessMessage();
            }, 500);
            
            // 2 saniye sonra yönlendir
            setTimeout(() => {
                window.location.href = 'Muse.php';
            }, 2500);
        }
        
        function startConfetti() {
            const canvas = document.createElement('canvas');
            canvas.id = 'confetti-canvas';
            canvas.style.position = 'fixed';
            canvas.style.top = '0';
            canvas.style.left = '0';
            canvas.style.width = '100%';
            canvas.style.height = '100%';
            canvas.style.pointerEvents = 'none';
            canvas.style.zIndex = '9999';
            document.body.appendChild(canvas);
            
            const ctx = canvas.getContext('2d');
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
            
            const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
            const confetti = [];
            const confettiCount = 150;
            
            for (let i = 0; i < confettiCount; i++) {
                confetti.push({
                    x: Math.random() * canvas.width,
                    y: -Math.random() * canvas.height,
                    r: Math.random() * 6 + 4,
                    d: Math.random() * confettiCount,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    tilt: Math.floor(Math.random() * 10) - 10,
                    tiltAngleIncrement: Math.random() * 0.07 + 0.05,
                    tiltAngle: 0
                });
            }
            
            let animationId;
            function animate() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                
                confetti.forEach((c, i) => {
                    ctx.beginPath();
                    ctx.lineWidth = c.r / 2;
                    ctx.strokeStyle = c.color;
                    ctx.moveTo(c.x + c.tilt + c.r, c.y);
                    ctx.lineTo(c.x + c.tilt, c.y + c.tilt + c.r);
                    ctx.stroke();
                    
                    c.tiltAngle += c.tiltAngleIncrement;
                    c.y += (Math.cos(c.d) + 3 + c.r / 2) / 2;
                    c.tilt = Math.sin(c.tiltAngle - i / 3) * 15;
                    
                    if (c.y > canvas.height) {
                        confetti[i] = {
                            x: Math.random() * canvas.width,
                            y: -20,
                            r: c.r,
                            d: c.d,
                            color: c.color,
                            tilt: Math.floor(Math.random() * 10) - 10,
                            tiltAngleIncrement: c.tiltAngleIncrement,
                            tiltAngle: c.tiltAngle
                        };
                    }
                });
                
                animationId = requestAnimationFrame(animate);
            }
            
            animate();
            
            // 2 saniye sonra animasyonu durdur ve canvas'ı kaldır
            setTimeout(() => {
                cancelAnimationFrame(animationId);
                canvas.remove();
            }, 2000);
        }
        
        function showSuccessMessage() {
            const message = document.createElement('div');
            message.id = 'success-message';
            message.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: white;
                padding: 40px 60px;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                z-index: 10000;
                text-align: center;
                animation: scaleIn 0.3s ease-out;
            `;
            
            message.innerHTML = `
                <div style="font-size: 48px; margin-bottom: 16px;">🎉</div>
                <h2 style="color: #1e40af; font-size: 24px; margin-bottom: 8px; font-weight: 600;">Etkinlik Oluşturuldu!</h2>
                <p style="color: #6b7280; font-size: 16px;">Etkinlik başarıyla oluşturuldu.</p>
            `;
            
            // CSS animasyonu ekle
            if (!document.getElementById('success-animation-style')) {
                const style = document.createElement('style');
                style.id = 'success-animation-style';
                style.textContent = `
                    @keyframes scaleIn {
                        from {
                            transform: translate(-50%, -50%) scale(0.8);
                            opacity: 0;
                        }
                        to {
                            transform: translate(-50%, -50%) scale(1);
                            opacity: 1;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
            
            document.body.appendChild(message);
            
            // 2 saniye sonra mesajı kaldır
            setTimeout(() => {
                message.style.animation = 'scaleIn 0.3s ease-out reverse';
                setTimeout(() => message.remove(), 300);
            }, 2000);
        }

        function searchKitapevi() {
            const kitapAdi = document.getElementById('kitapAdi').value.toLowerCase();
            const yazar = document.getElementById('yazar').value.toLowerCase();
            const tur = document.getElementById('tur').value.toLowerCase();
            const isbn = document.getElementById('isbn').value.toLowerCase();
            
            const rows = document.querySelectorAll('#kitapeviTableBody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = (!kitapAdi || text.includes(kitapAdi)) &&
                             (!yazar || text.includes(yazar)) &&
                             (!tur || text.includes(tur)) &&
                             (!isbn || text.includes(isbn));
                row.style.display = match ? '' : 'none';
            });
        }

        function clearKitapeviFilters() {
            document.getElementById('kitapAdi').value = '';
            document.getElementById('yazar').value = '';
            document.getElementById('tur').value = '';
            document.getElementById('isbn').value = '';
            searchKitapevi();
        }

        function searchKafe() {
            const urunAdi = document.getElementById('kafeUrunAdi').value.toLowerCase();
            const kategori = document.getElementById('kafeKategori').value.toLowerCase();
            
            const rows = document.querySelectorAll('#kafeTableBody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = (!urunAdi || text.includes(urunAdi)) &&
                             (!kategori || text.includes(kategori));
                row.style.display = match ? '' : 'none';
            });
        }

        function clearKafeFilters() {
            document.getElementById('kafeUrunAdi').value = '';
            document.getElementById('kafeKategori').value = '';
            searchKafe();
        }

        function searchRestoran() {
            const urunAdi = document.getElementById('restoranUrunAdi').value.toLowerCase();
            const menuGrubu = document.getElementById('restoranMenuGrubu').value.toLowerCase();
            
            const rows = document.querySelectorAll('#restoranTableBody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = (!urunAdi || text.includes(urunAdi)) &&
                             (!menuGrubu || text.includes(menuGrubu));
                row.style.display = match ? '' : 'none';
            });
        }

        function clearRestoranFilters() {
            document.getElementById('restoranUrunAdi').value = '';
            document.getElementById('restoranMenuGrubu').value = '';
            searchRestoran();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Enter tuşu ile arama
        ['kitapAdi', 'yazar', 'tur', 'isbn', 'kafeUrunAdi', 'restoranUrunAdi'].forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        if (id.startsWith('kitap') || id === 'yazar' || id === 'tur' || id === 'isbn') {
                            searchKitapevi();
                        } else if (id.startsWith('kafe')) {
                            searchKafe();
                        } else if (id.startsWith('restoran')) {
                            searchRestoran();
                        }
                    }
                });
            }
        });
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

// Adım 1'den gelen veriler (şimdilik statik, sonra session'dan gelecek)
$etkinlikAdi = $_GET['etkinlikAdi'] ?? 'Yılbaşı Kutlaması';
$etkinlikTarihi = $_GET['baslangicTarihi'] ?? '2024-12-31';
$secilenSube = $_GET['sube'] ?? 'Taksim Şube';

// Statik veriler
$kitapeviUrunleri = [
    ['kod' => 'KIT001', 'isbn' => '978-1234567890', 'ad' => 'Sapiens', 'yazar' => 'Yuval Noah Harari', 'tur' => 'Tarih', 'stok' => 25, 'birim' => 'Adet'],
    ['kod' => 'KIT002', 'isbn' => '978-0987654321', 'ad' => 'Homo Deus', 'yazar' => 'Yuval Noah Harari', 'tur' => 'Tarih', 'stok' => 15, 'birim' => 'Adet'],
    ['kod' => 'KIT003', 'isbn' => '978-1122334455', 'ad' => 'Dune', 'yazar' => 'Frank Herbert', 'tur' => 'Bilim Kurgu', 'stok' => 30, 'birim' => 'Adet'],
    ['kod' => 'KIT004', 'isbn' => '978-5566778899', 'ad' => '1984', 'yazar' => 'George Orwell', 'tur' => 'Distopya', 'stok' => 20, 'birim' => 'Adet'],
    ['kod' => 'KIT005', 'isbn' => '978-9988776655', 'ad' => 'Suç ve Ceza', 'yazar' => 'Fyodor Dostoyevski', 'tur' => 'Klasik', 'stok' => 18, 'birim' => 'Adet']
];

$kafeUrunleri = [
    ['kod' => 'KAF001', 'ad' => 'Espresso', 'kategori' => 'Sıcak İçecek', 'stok' => 100, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF002', 'ad' => 'Cappuccino', 'kategori' => 'Sıcak İçecek', 'stok' => 80, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF003', 'ad' => 'Latte', 'kategori' => 'Sıcak İçecek', 'stok' => 75, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF004', 'ad' => 'Soğuk Kahve', 'kategori' => 'Soğuk İçecek', 'stok' => 60, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF005', 'ad' => 'Cheesecake', 'kategori' => 'Tatlı', 'stok' => 40, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF006', 'ad' => 'Brownie', 'kategori' => 'Tatlı', 'stok' => 35, 'birim' => 'Porsiyon']
];

$restoranUrunleri = [
    ['kod' => 'RES001', 'ad' => 'Izgara Tavuk', 'menuGrubu' => 'Ana Yemek', 'stok' => 50, 'birim' => 'Porsiyon'],
    ['kod' => 'RES002', 'ad' => 'Mantı', 'menuGrubu' => 'Ana Yemek', 'stok' => 45, 'birim' => 'Porsiyon'],
    ['kod' => 'RES003', 'ad' => 'Humus', 'menuGrubu' => 'Meze', 'stok' => 30, 'birim' => 'Porsiyon'],
    ['kod' => 'RES004', 'ad' => 'Cacık', 'menuGrubu' => 'Meze', 'stok' => 25, 'birim' => 'Porsiyon'],
    ['kod' => 'RES005', 'ad' => 'Çoban Salata', 'menuGrubu' => 'Salata', 'stok' => 40, 'birim' => 'Porsiyon'],
    ['kod' => 'RES006', 'ad' => 'Mevsim Salata', 'menuGrubu' => 'Salata', 'stok' => 35, 'birim' => 'Porsiyon']
];

$kategoriler = ['Kitapevi', 'Kafe', 'Restoran'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etkinlik İçeriğini Oluştur - MINOA</title>
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

.page-header-info {
    font-size: 13px;
    color: #6b7280;
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.page-header-info span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.page-header-content {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}

.main-layout {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 24px;
}

.left-panel {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.right-panel {
    position: sticky;
    top: 100px;
    height: fit-content;
    max-height: calc(100vh - 120px);
    overflow-y: auto;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible;
}

/* Category Tabs */
.category-tabs {
    display: flex;
    gap: 12px;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
    flex-wrap: wrap;
}

.category-tab {
    padding: 12px 24px;
    border: 2px solid #e5e7eb;
    background: white;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    color: #6b7280;
}

.category-tab:hover {
    border-color: #3b82f6;
    color: #3b82f6;
}

.category-tab.active {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border-color: #3b82f6;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

/* Filter Section */
.filter-section {
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 12px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 500;
    color: #374151;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 13px;
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

/* Table */
.table-container {
    overflow-x: auto;
    max-height: 600px;
    overflow-y: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

thead {
    background: #f8fafc;
    position: sticky;
    top: 0;
    z-index: 10;
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

.quantity-input {
    width: 80px;
    padding: 6px 8px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 13px;
    text-align: center;
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

.btn-small {
    padding: 6px 12px;
    font-size: 12px;
}

.btn-back {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-back:hover {
    background: #f0f9ff;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

/* Sepet Panel */
.sepet-panel {
    padding: 24px;
}

.sepet-panel h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e40af;
    margin-bottom: 12px;
}

.sepet-event-info {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #e5e7eb;
}

.sepet-table-container {
    max-height: 400px;
    overflow-y: auto;
    margin-bottom: 16px;
}

.sepet-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.sepet-table thead {
    background: #f8fafc;
    position: sticky;
    top: 0;
    z-index: 10;
}

.sepet-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    font-size: 12px;
    white-space: nowrap;
}

.sepet-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #e5e7eb;
    color: #4b5563;
    vertical-align: middle;
}

.sepet-table tbody tr:hover {
    background: #f9fafb;
}

.sepet-kaynak {
    font-size: 11px;
    font-weight: 500;
    padding: 4px 8px;
    border-radius: 4px;
    display: inline-block;
}

.sepet-kaynak-kitapevi {
    background: #dbeafe;
    color: #1e40af;
}

.sepet-kaynak-kafe {
    background: #fef3c7;
    color: #92400e;
}

.sepet-kaynak-restoran {
    background: #d1fae5;
    color: #065f46;
}


.sepet-not-input {
    width: 100%;
    padding: 4px 6px;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    font-size: 11px;
    font-family: inherit;
}

.sepet-not-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
}

.sepet-remove-btn {
    background: #fee2e2;
    color: #dc2626;
    border: none;
    border-radius: 4px;
    padding: 6px 10px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
}

.sepet-remove-btn:hover {
    background: #fecaca;
}

.sepet-empty {
    text-align: center;
    padding: 40px 20px;
    color: #9ca3af;
    font-size: 14px;
}

.sepet-summary {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 2px solid #e5e7eb;
}

.sepet-summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 13px;
    color: #6b7280;
}

.sepet-summary-row.total {
    font-size: 15px;
    font-weight: 600;
    color: #1e40af;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #e5e7eb;
}

.sepet-actions {
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.sepet-actions .btn {
    width: 100%;
    justify-content: center;
}


.hidden {
    display: none;
}

/* Responsive */
@media (max-width: 1200px) {
    .main-layout {
        grid-template-columns: 1fr;
    }

    .right-panel {
        position: static;
        max-height: none;
    }
}

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

    .category-tabs {
        flex-direction: column;
    }

    .category-tab {
        width: 100%;
    }

    .filter-row {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h2>Etkinlik İçeriğini Oluştur</h2>
                <div class="page-header-info">
                    <span><strong>Etkinlik:</strong> <?= htmlspecialchars($etkinlikAdi) ?></span>
                    <span><strong>Tarih:</strong> <?= htmlspecialchars($etkinlikTarihi) ?></span>
                    <span><strong>Şube:</strong> <?= htmlspecialchars($secilenSube) ?></span>
                </div>
            </div>
            <a href="MuseSO.php" class="btn btn-secondary">← Geri</a>
        </div>

        <div class="content-wrapper">
            <div class="main-layout">
                <!-- Sol Panel -->
                <div class="left-panel">
                    <!-- Kategori Butonları -->
                    <div class="card">
                        <div class="category-tabs">
                            <button class="category-tab active" onclick="switchCategory('kitapevi')">Kitapevi</button>
                            <button class="category-tab" onclick="switchCategory('kafe')">Kafe</button>
                            <button class="category-tab" onclick="switchCategory('restoran')">Restoran</button>
                        </div>

                        <!-- Kitapevi Tab -->
                        <div id="tab-kitapevi" class="tab-content">
                            <div class="filter-section">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label>Kitap Adı</label>
                                        <input type="text" id="kitapAdi" placeholder="Kitap adı ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Yazar</label>
                                        <input type="text" id="yazar" placeholder="Yazar ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Tür</label>
                                        <input type="text" id="tur" placeholder="Tür ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>ISBN</label>
                                        <input type="text" id="isbn" placeholder="ISBN ara...">
                                    </div>
                                </div>
                                <div class="filter-buttons">
                                    <button class="btn btn-primary btn-small" onclick="searchKitapevi()">Ara</button>
                                    <button class="btn btn-secondary btn-small" onclick="clearKitapeviFilters()">Temizle</button>
                                </div>
                            </div>

                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ürün Kodu / ISBN</th>
                                            <th>Kitap Adı</th>
                                            <th>Yazar</th>
                                            <th>Tür</th>
                                            <th>Stok</th>
                                            <th>Birim</th>
                                            <th>Etkinlik Miktarı</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="kitapeviTableBody">
                                        <?php foreach ($kitapeviUrunleri as $urun): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($urun['kod']) ?><br><small style="color: #9ca3af;"><?= htmlspecialchars($urun['isbn']) ?></small></td>
                                            <td><?= htmlspecialchars($urun['ad']) ?></td>
                                            <td><?= htmlspecialchars($urun['yazar']) ?></td>
                                            <td><?= htmlspecialchars($urun['tur']) ?></td>
                                            <td><?= htmlspecialchars($urun['stok']) ?></td>
                                            <td><?= htmlspecialchars($urun['birim']) ?></td>
                                            <td><input type="number" class="quantity-input" id="qty-<?= $urun['kod'] ?>" min="1" value="1"></td>
                                            <td><button class="btn btn-primary btn-small" onclick="addToCart('kitapevi', '<?= htmlspecialchars($urun['kod'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['ad'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['yazar'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['birim'], ENT_QUOTES) ?>')">Sepete Ekle</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Kafe Tab -->
                        <div id="tab-kafe" class="tab-content hidden">
                            <div class="filter-section">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label>Ürün Adı</label>
                                        <input type="text" id="kafeUrunAdi" placeholder="Ürün adı ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Kategori</label>
                                        <select id="kafeKategori">
                                            <option value="">Tümü</option>
                                            <option value="Sıcak İçecek">Sıcak İçecek</option>
                                            <option value="Soğuk İçecek">Soğuk İçecek</option>
                                            <option value="Tatlı">Tatlı</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="filter-buttons">
                                    <button class="btn btn-primary btn-small" onclick="searchKafe()">Ara</button>
                                    <button class="btn btn-secondary btn-small" onclick="clearKafeFilters()">Temizle</button>
                                </div>
                            </div>

                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ürün Kodu</th>
                                            <th>Ürün Adı</th>
                                            <th>Kategori</th>
                                            <th>Birim</th>
                                            <th>Stok</th>
                                            <th>Etkinlik Miktarı</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="kafeTableBody">
                                        <?php foreach ($kafeUrunleri as $urun): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($urun['kod']) ?></td>
                                            <td><?= htmlspecialchars($urun['ad']) ?></td>
                                            <td><?= htmlspecialchars($urun['kategori']) ?></td>
                                            <td><?= htmlspecialchars($urun['birim']) ?></td>
                                            <td><?= htmlspecialchars($urun['stok']) ?></td>
                                            <td><input type="number" class="quantity-input" id="qty-<?= $urun['kod'] ?>" min="1" value="1"></td>
                                            <td><button class="btn btn-primary btn-small" onclick="addToCart('kafe', '<?= htmlspecialchars($urun['kod'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['ad'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['kategori'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['birim'], ENT_QUOTES) ?>')">Sepete Ekle</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Restoran Tab -->
                        <div id="tab-restoran" class="tab-content hidden">
                            <div class="filter-section">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label>Ürün Adı</label>
                                        <input type="text" id="restoranUrunAdi" placeholder="Ürün adı ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Menü Grubu</label>
                                        <select id="restoranMenuGrubu">
                                            <option value="">Tümü</option>
                                            <option value="Ana Yemek">Ana Yemek</option>
                                            <option value="Meze">Meze</option>
                                            <option value="Salata">Salata</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="filter-buttons">
                                    <button class="btn btn-primary btn-small" onclick="searchRestoran()">Ara</button>
                                    <button class="btn btn-secondary btn-small" onclick="clearRestoranFilters()">Temizle</button>
                                </div>
                            </div>

                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ürün Kodu</th>
                                            <th>Ürün Adı</th>
                                            <th>Menü Grubu</th>
                                            <th>Birim</th>
                                            <th>Stok</th>
                                            <th>Etkinlik Miktarı</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="restoranTableBody">
                                        <?php foreach ($restoranUrunleri as $urun): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($urun['kod']) ?></td>
                                            <td><?= htmlspecialchars($urun['ad']) ?></td>
                                            <td><?= htmlspecialchars($urun['menuGrubu']) ?></td>
                                            <td><?= htmlspecialchars($urun['birim']) ?></td>
                                            <td><?= htmlspecialchars($urun['stok']) ?></td>
                                            <td><input type="number" class="quantity-input" id="qty-<?= $urun['kod'] ?>" min="1" value="1"></td>
                                            <td><button class="btn btn-primary btn-small" onclick="addToCart('restoran', '<?= htmlspecialchars($urun['kod'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['ad'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['menuGrubu'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['birim'], ENT_QUOTES) ?>')">Sepete Ekle</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Sağ Panel - Sepet -->
                <div class="right-panel">
                    <div class="card sepet-panel">
                        <h3>Etkinlik Sepeti</h3>
                        <div class="sepet-event-info">
                            <strong><?= htmlspecialchars($etkinlikAdi) ?></strong> – <?= htmlspecialchars($secilenSube) ?>
                        </div>
                        
                        <div id="sepetContent">
                            <div class="sepet-empty">Sepetiniz boş</div>
                        </div>
                        
                        <div id="sepetSummary" class="sepet-summary hidden">
                            <div class="sepet-summary-row">
                                <span>Toplam Satır:</span>
                                <span id="totalRows">0</span>
                            </div>
                            <div class="sepet-summary-row">
                                <span>Tahmini Bütçe:</span>
                                <span id="totalBudget">-</span>
                            </div>
                        </div>
                        
                        <div id="sepetActions" class="sepet-actions hidden">
                            <button class="btn btn-primary" onclick="createEventFromCart()">Sepetten Etkinlik Oluştur</button>
                            <button class="btn btn-secondary" onclick="clearCart()">Sepeti Temizle</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let cart = [];
        let currentCategory = 'kitapevi';

        function switchCategory(category) {
            currentCategory = category;
            
            // Tab butonlarını güncelle
            document.querySelectorAll('.category-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Tab içeriklerini göster/gizle
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            
            const targetTab = document.getElementById('tab-' + category);
            if (targetTab) {
                targetTab.classList.remove('hidden');
            }
        }

        function addToCart(category, kod, ad, detay, birim) {
            const qtyInput = document.getElementById('qty-' + kod);
            const quantity = parseInt(qtyInput.value) || 1;
            
            const cartItem = {
                id: Date.now() + Math.random(), // Unique ID
                category: category,
                kod: kod,
                ad: ad,
                detay: detay,
                birim: birim,
                miktar: quantity,
                not: ''
            };
            
            cart.push(cartItem);
            renderCart();
            
            // Input'u sıfırla
            qtyInput.value = 1;
        }

        function removeFromCart(id) {
            cart = cart.filter(item => item.id !== id);
            renderCart();
        }

        function renderCart() {
            const sepetContent = document.getElementById('sepetContent');
            const sepetSummary = document.getElementById('sepetSummary');
            const sepetActions = document.getElementById('sepetActions');
            
            if (cart.length === 0) {
                sepetContent.innerHTML = '<div class="sepet-empty">Sepetiniz boş</div>';
                sepetSummary.classList.add('hidden');
                sepetActions.classList.add('hidden');
            } else {
                const kaynakClassMap = {
                    'kitapevi': 'sepet-kaynak-kitapevi',
                    'kafe': 'sepet-kaynak-kafe',
                    'restoran': 'sepet-kaynak-restoran'
                };
                
                const kaynakLabelMap = {
                    'kitapevi': 'Kitapevi',
                    'kafe': 'Kafe',
                    'restoran': 'Restoran'
                };
                
                sepetContent.innerHTML = `
                    <div class="sepet-table-container">
                        <table class="sepet-table">
                            <thead>
                                <tr>
                                    <th>Kaynak</th>
                                    <th>Ürün / İçerik Adı</th>
                                    <th>Birim</th>
                                    <th>Miktar</th>
                                    <th>Not</th>
                                    <th>Sil</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${cart.map(item => {
                                    const kaynakClass = kaynakClassMap[item.category] || '';
                                    const kaynakLabel = kaynakLabelMap[item.category] || item.category;
                                    return `
                                        <tr>
                                            <td><span class="sepet-kaynak ${kaynakClass}">${escapeHtml(kaynakLabel)}</span></td>
                                            <td>${escapeHtml(item.ad)}</td>
                                            <td>${escapeHtml(item.birim)}</td>
                                            <td>${item.miktar}</td>
                                            <td>
                                                <input type="text" 
                                                       class="sepet-not-input" 
                                                       placeholder="Not ekle..." 
                                                       value="${escapeHtml(item.not || '')}"
                                                       onchange="updateCartNote(${item.id}, this.value)">
                                            </td>
                                            <td>
                                                <button class="sepet-remove-btn" onclick="removeFromCart(${item.id})" title="Sil">🗑️</button>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
                
                document.getElementById('totalRows').textContent = cart.length;
                sepetSummary.classList.remove('hidden');
                sepetActions.classList.remove('hidden');
            }
        }
        
        function updateCartNote(id, note) {
            const item = cart.find(i => i.id === id);
            if (item) {
                item.not = note;
            }
        }
        
        function clearCart() {
            if (confirm('Sepeti temizlemek istediğinize emin misiniz?')) {
                cart = [];
                renderCart();
            }
        }

        function createEventFromCart() {
            if (cart.length === 0) {
                alert('Sepetiniz boş. Lütfen önce ürün ekleyin.');
                return;
            }
            
            // Konfeti animasyonunu başlat
            startConfetti();
            
            // Başarı mesajını göster
            setTimeout(() => {
                showSuccessMessage();
            }, 500);
            
            // 2 saniye sonra yönlendir
            setTimeout(() => {
                window.location.href = 'Muse.php';
            }, 2500);
        }
        
        function startConfetti() {
            const canvas = document.createElement('canvas');
            canvas.id = 'confetti-canvas';
            canvas.style.position = 'fixed';
            canvas.style.top = '0';
            canvas.style.left = '0';
            canvas.style.width = '100%';
            canvas.style.height = '100%';
            canvas.style.pointerEvents = 'none';
            canvas.style.zIndex = '9999';
            document.body.appendChild(canvas);
            
            const ctx = canvas.getContext('2d');
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
            
            const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
            const confetti = [];
            const confettiCount = 150;
            
            for (let i = 0; i < confettiCount; i++) {
                confetti.push({
                    x: Math.random() * canvas.width,
                    y: -Math.random() * canvas.height,
                    r: Math.random() * 6 + 4,
                    d: Math.random() * confettiCount,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    tilt: Math.floor(Math.random() * 10) - 10,
                    tiltAngleIncrement: Math.random() * 0.07 + 0.05,
                    tiltAngle: 0
                });
            }
            
            let animationId;
            function animate() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                
                confetti.forEach((c, i) => {
                    ctx.beginPath();
                    ctx.lineWidth = c.r / 2;
                    ctx.strokeStyle = c.color;
                    ctx.moveTo(c.x + c.tilt + c.r, c.y);
                    ctx.lineTo(c.x + c.tilt, c.y + c.tilt + c.r);
                    ctx.stroke();
                    
                    c.tiltAngle += c.tiltAngleIncrement;
                    c.y += (Math.cos(c.d) + 3 + c.r / 2) / 2;
                    c.tilt = Math.sin(c.tiltAngle - i / 3) * 15;
                    
                    if (c.y > canvas.height) {
                        confetti[i] = {
                            x: Math.random() * canvas.width,
                            y: -20,
                            r: c.r,
                            d: c.d,
                            color: c.color,
                            tilt: Math.floor(Math.random() * 10) - 10,
                            tiltAngleIncrement: c.tiltAngleIncrement,
                            tiltAngle: c.tiltAngle
                        };
                    }
                });
                
                animationId = requestAnimationFrame(animate);
            }
            
            animate();
            
            // 2 saniye sonra animasyonu durdur ve canvas'ı kaldır
            setTimeout(() => {
                cancelAnimationFrame(animationId);
                canvas.remove();
            }, 2000);
        }
        
        function showSuccessMessage() {
            const message = document.createElement('div');
            message.id = 'success-message';
            message.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: white;
                padding: 40px 60px;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                z-index: 10000;
                text-align: center;
                animation: scaleIn 0.3s ease-out;
            `;
            
            message.innerHTML = `
                <div style="font-size: 48px; margin-bottom: 16px;">🎉</div>
                <h2 style="color: #1e40af; font-size: 24px; margin-bottom: 8px; font-weight: 600;">Etkinlik Oluşturuldu!</h2>
                <p style="color: #6b7280; font-size: 16px;">Etkinlik başarıyla oluşturuldu.</p>
            `;
            
            // CSS animasyonu ekle
            if (!document.getElementById('success-animation-style')) {
                const style = document.createElement('style');
                style.id = 'success-animation-style';
                style.textContent = `
                    @keyframes scaleIn {
                        from {
                            transform: translate(-50%, -50%) scale(0.8);
                            opacity: 0;
                        }
                        to {
                            transform: translate(-50%, -50%) scale(1);
                            opacity: 1;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
            
            document.body.appendChild(message);
            
            // 2 saniye sonra mesajı kaldır
            setTimeout(() => {
                message.style.animation = 'scaleIn 0.3s ease-out reverse';
                setTimeout(() => message.remove(), 300);
            }, 2000);
        }

        function searchKitapevi() {
            const kitapAdi = document.getElementById('kitapAdi').value.toLowerCase();
            const yazar = document.getElementById('yazar').value.toLowerCase();
            const tur = document.getElementById('tur').value.toLowerCase();
            const isbn = document.getElementById('isbn').value.toLowerCase();
            
            const rows = document.querySelectorAll('#kitapeviTableBody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = (!kitapAdi || text.includes(kitapAdi)) &&
                             (!yazar || text.includes(yazar)) &&
                             (!tur || text.includes(tur)) &&
                             (!isbn || text.includes(isbn));
                row.style.display = match ? '' : 'none';
            });
        }

        function clearKitapeviFilters() {
            document.getElementById('kitapAdi').value = '';
            document.getElementById('yazar').value = '';
            document.getElementById('tur').value = '';
            document.getElementById('isbn').value = '';
            searchKitapevi();
        }

        function searchKafe() {
            const urunAdi = document.getElementById('kafeUrunAdi').value.toLowerCase();
            const kategori = document.getElementById('kafeKategori').value.toLowerCase();
            
            const rows = document.querySelectorAll('#kafeTableBody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = (!urunAdi || text.includes(urunAdi)) &&
                             (!kategori || text.includes(kategori));
                row.style.display = match ? '' : 'none';
            });
        }

        function clearKafeFilters() {
            document.getElementById('kafeUrunAdi').value = '';
            document.getElementById('kafeKategori').value = '';
            searchKafe();
        }

        function searchRestoran() {
            const urunAdi = document.getElementById('restoranUrunAdi').value.toLowerCase();
            const menuGrubu = document.getElementById('restoranMenuGrubu').value.toLowerCase();
            
            const rows = document.querySelectorAll('#restoranTableBody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = (!urunAdi || text.includes(urunAdi)) &&
                             (!menuGrubu || text.includes(menuGrubu));
                row.style.display = match ? '' : 'none';
            });
        }

        function clearRestoranFilters() {
            document.getElementById('restoranUrunAdi').value = '';
            document.getElementById('restoranMenuGrubu').value = '';
            searchRestoran();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Enter tuşu ile arama
        ['kitapAdi', 'yazar', 'tur', 'isbn', 'kafeUrunAdi', 'restoranUrunAdi'].forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        if (id.startsWith('kitap') || id === 'yazar' || id === 'tur' || id === 'isbn') {
                            searchKitapevi();
                        } else if (id.startsWith('kafe')) {
                            searchKafe();
                        } else if (id.startsWith('restoran')) {
                            searchRestoran();
                        }
                    }
                });
            }
        });
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

// Adım 1'den gelen veriler (şimdilik statik, sonra session'dan gelecek)
$etkinlikAdi = $_GET['etkinlikAdi'] ?? 'Yılbaşı Kutlaması';
$etkinlikTarihi = $_GET['baslangicTarihi'] ?? '2024-12-31';
$secilenSube = $_GET['sube'] ?? 'Taksim Şube';

// Statik veriler
$kitapeviUrunleri = [
    ['kod' => 'KIT001', 'isbn' => '978-1234567890', 'ad' => 'Sapiens', 'yazar' => 'Yuval Noah Harari', 'tur' => 'Tarih', 'stok' => 25, 'birim' => 'Adet'],
    ['kod' => 'KIT002', 'isbn' => '978-0987654321', 'ad' => 'Homo Deus', 'yazar' => 'Yuval Noah Harari', 'tur' => 'Tarih', 'stok' => 15, 'birim' => 'Adet'],
    ['kod' => 'KIT003', 'isbn' => '978-1122334455', 'ad' => 'Dune', 'yazar' => 'Frank Herbert', 'tur' => 'Bilim Kurgu', 'stok' => 30, 'birim' => 'Adet'],
    ['kod' => 'KIT004', 'isbn' => '978-5566778899', 'ad' => '1984', 'yazar' => 'George Orwell', 'tur' => 'Distopya', 'stok' => 20, 'birim' => 'Adet'],
    ['kod' => 'KIT005', 'isbn' => '978-9988776655', 'ad' => 'Suç ve Ceza', 'yazar' => 'Fyodor Dostoyevski', 'tur' => 'Klasik', 'stok' => 18, 'birim' => 'Adet']
];

$kafeUrunleri = [
    ['kod' => 'KAF001', 'ad' => 'Espresso', 'kategori' => 'Sıcak İçecek', 'stok' => 100, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF002', 'ad' => 'Cappuccino', 'kategori' => 'Sıcak İçecek', 'stok' => 80, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF003', 'ad' => 'Latte', 'kategori' => 'Sıcak İçecek', 'stok' => 75, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF004', 'ad' => 'Soğuk Kahve', 'kategori' => 'Soğuk İçecek', 'stok' => 60, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF005', 'ad' => 'Cheesecake', 'kategori' => 'Tatlı', 'stok' => 40, 'birim' => 'Porsiyon'],
    ['kod' => 'KAF006', 'ad' => 'Brownie', 'kategori' => 'Tatlı', 'stok' => 35, 'birim' => 'Porsiyon']
];

$restoranUrunleri = [
    ['kod' => 'RES001', 'ad' => 'Izgara Tavuk', 'menuGrubu' => 'Ana Yemek', 'stok' => 50, 'birim' => 'Porsiyon'],
    ['kod' => 'RES002', 'ad' => 'Mantı', 'menuGrubu' => 'Ana Yemek', 'stok' => 45, 'birim' => 'Porsiyon'],
    ['kod' => 'RES003', 'ad' => 'Humus', 'menuGrubu' => 'Meze', 'stok' => 30, 'birim' => 'Porsiyon'],
    ['kod' => 'RES004', 'ad' => 'Cacık', 'menuGrubu' => 'Meze', 'stok' => 25, 'birim' => 'Porsiyon'],
    ['kod' => 'RES005', 'ad' => 'Çoban Salata', 'menuGrubu' => 'Salata', 'stok' => 40, 'birim' => 'Porsiyon'],
    ['kod' => 'RES006', 'ad' => 'Mevsim Salata', 'menuGrubu' => 'Salata', 'stok' => 35, 'birim' => 'Porsiyon']
];

$kategoriler = ['Kitapevi', 'Kafe', 'Restoran'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etkinlik İçeriğini Oluştur - MINOA</title>
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

.page-header-info {
    font-size: 13px;
    color: #6b7280;
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    margin-top: 8px;
}

.page-header-info span {
    display: flex;
    align-items: center;
    gap: 6px;
}

.page-header-content {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}

.main-layout {
    display: grid;
    grid-template-columns: 1fr 400px;
    gap: 24px;
}

.left-panel {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.right-panel {
    position: sticky;
    top: 100px;
    height: fit-content;
    max-height: calc(100vh - 120px);
    overflow-y: auto;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible;
}

/* Category Tabs */
.category-tabs {
    display: flex;
    gap: 12px;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
    flex-wrap: wrap;
}

.category-tab {
    padding: 12px 24px;
    border: 2px solid #e5e7eb;
    background: white;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    color: #6b7280;
}

.category-tab:hover {
    border-color: #3b82f6;
    color: #3b82f6;
}

.category-tab.active {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    border-color: #3b82f6;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

/* Filter Section */
.filter-section {
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 12px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 500;
    color: #374151;
}

.filter-group input,
.filter-group select {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 13px;
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

/* Table */
.table-container {
    overflow-x: auto;
    max-height: 600px;
    overflow-y: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

thead {
    background: #f8fafc;
    position: sticky;
    top: 0;
    z-index: 10;
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

.quantity-input {
    width: 80px;
    padding: 6px 8px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 13px;
    text-align: center;
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

.btn-small {
    padding: 6px 12px;
    font-size: 12px;
}

.btn-back {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-back:hover {
    background: #f0f9ff;
}

.btn-danger {
    background: #ef4444;
    color: white;
}

.btn-danger:hover {
    background: #dc2626;
}

/* Sepet Panel */
.sepet-panel {
    padding: 24px;
}

.sepet-panel h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e40af;
    margin-bottom: 12px;
}

.sepet-event-info {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #e5e7eb;
}

.sepet-table-container {
    max-height: 400px;
    overflow-y: auto;
    margin-bottom: 16px;
}

.sepet-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.sepet-table thead {
    background: #f8fafc;
    position: sticky;
    top: 0;
    z-index: 10;
}

.sepet-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    font-size: 12px;
    white-space: nowrap;
}

.sepet-table td {
    padding: 12px 16px;
    border-bottom: 1px solid #e5e7eb;
    color: #4b5563;
    vertical-align: middle;
}

.sepet-table tbody tr:hover {
    background: #f9fafb;
}

.sepet-kaynak {
    font-size: 11px;
    font-weight: 500;
    padding: 4px 8px;
    border-radius: 4px;
    display: inline-block;
}

.sepet-kaynak-kitapevi {
    background: #dbeafe;
    color: #1e40af;
}

.sepet-kaynak-kafe {
    background: #fef3c7;
    color: #92400e;
}

.sepet-kaynak-restoran {
    background: #d1fae5;
    color: #065f46;
}


.sepet-not-input {
    width: 100%;
    padding: 4px 6px;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    font-size: 11px;
    font-family: inherit;
}

.sepet-not-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
}

.sepet-remove-btn {
    background: #fee2e2;
    color: #dc2626;
    border: none;
    border-radius: 4px;
    padding: 6px 10px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
}

.sepet-remove-btn:hover {
    background: #fecaca;
}

.sepet-empty {
    text-align: center;
    padding: 40px 20px;
    color: #9ca3af;
    font-size: 14px;
}

.sepet-summary {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 2px solid #e5e7eb;
}

.sepet-summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 13px;
    color: #6b7280;
}

.sepet-summary-row.total {
    font-size: 15px;
    font-weight: 600;
    color: #1e40af;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #e5e7eb;
}

.sepet-actions {
    margin-top: 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.sepet-actions .btn {
    width: 100%;
    justify-content: center;
}


.hidden {
    display: none;
}

/* Responsive */
@media (max-width: 1200px) {
    .main-layout {
        grid-template-columns: 1fr;
    }

    .right-panel {
        position: static;
        max-height: none;
    }
}

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

    .category-tabs {
        flex-direction: column;
    }

    .category-tab {
        width: 100%;
    }

    .filter-row {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div class="page-header-content">
                <h2>Etkinlik İçeriğini Oluştur</h2>
                <div class="page-header-info">
                    <span><strong>Etkinlik:</strong> <?= htmlspecialchars($etkinlikAdi) ?></span>
                    <span><strong>Tarih:</strong> <?= htmlspecialchars($etkinlikTarihi) ?></span>
                    <span><strong>Şube:</strong> <?= htmlspecialchars($secilenSube) ?></span>
                </div>
            </div>
            <a href="MuseSO.php" class="btn btn-secondary">← Geri</a>
        </div>

        <div class="content-wrapper">
            <div class="main-layout">
                <!-- Sol Panel -->
                <div class="left-panel">
                    <!-- Kategori Butonları -->
                    <div class="card">
                        <div class="category-tabs">
                            <button class="category-tab active" onclick="switchCategory('kitapevi')">Kitapevi</button>
                            <button class="category-tab" onclick="switchCategory('kafe')">Kafe</button>
                            <button class="category-tab" onclick="switchCategory('restoran')">Restoran</button>
                        </div>

                        <!-- Kitapevi Tab -->
                        <div id="tab-kitapevi" class="tab-content">
                            <div class="filter-section">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label>Kitap Adı</label>
                                        <input type="text" id="kitapAdi" placeholder="Kitap adı ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Yazar</label>
                                        <input type="text" id="yazar" placeholder="Yazar ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Tür</label>
                                        <input type="text" id="tur" placeholder="Tür ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>ISBN</label>
                                        <input type="text" id="isbn" placeholder="ISBN ara...">
                                    </div>
                                </div>
                                <div class="filter-buttons">
                                    <button class="btn btn-primary btn-small" onclick="searchKitapevi()">Ara</button>
                                    <button class="btn btn-secondary btn-small" onclick="clearKitapeviFilters()">Temizle</button>
                                </div>
                            </div>

                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ürün Kodu / ISBN</th>
                                            <th>Kitap Adı</th>
                                            <th>Yazar</th>
                                            <th>Tür</th>
                                            <th>Stok</th>
                                            <th>Birim</th>
                                            <th>Etkinlik Miktarı</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="kitapeviTableBody">
                                        <?php foreach ($kitapeviUrunleri as $urun): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($urun['kod']) ?><br><small style="color: #9ca3af;"><?= htmlspecialchars($urun['isbn']) ?></small></td>
                                            <td><?= htmlspecialchars($urun['ad']) ?></td>
                                            <td><?= htmlspecialchars($urun['yazar']) ?></td>
                                            <td><?= htmlspecialchars($urun['tur']) ?></td>
                                            <td><?= htmlspecialchars($urun['stok']) ?></td>
                                            <td><?= htmlspecialchars($urun['birim']) ?></td>
                                            <td><input type="number" class="quantity-input" id="qty-<?= $urun['kod'] ?>" min="1" value="1"></td>
                                            <td><button class="btn btn-primary btn-small" onclick="addToCart('kitapevi', '<?= htmlspecialchars($urun['kod'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['ad'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['yazar'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['birim'], ENT_QUOTES) ?>')">Sepete Ekle</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Kafe Tab -->
                        <div id="tab-kafe" class="tab-content hidden">
                            <div class="filter-section">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label>Ürün Adı</label>
                                        <input type="text" id="kafeUrunAdi" placeholder="Ürün adı ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Kategori</label>
                                        <select id="kafeKategori">
                                            <option value="">Tümü</option>
                                            <option value="Sıcak İçecek">Sıcak İçecek</option>
                                            <option value="Soğuk İçecek">Soğuk İçecek</option>
                                            <option value="Tatlı">Tatlı</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="filter-buttons">
                                    <button class="btn btn-primary btn-small" onclick="searchKafe()">Ara</button>
                                    <button class="btn btn-secondary btn-small" onclick="clearKafeFilters()">Temizle</button>
                                </div>
                            </div>

                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ürün Kodu</th>
                                            <th>Ürün Adı</th>
                                            <th>Kategori</th>
                                            <th>Birim</th>
                                            <th>Stok</th>
                                            <th>Etkinlik Miktarı</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="kafeTableBody">
                                        <?php foreach ($kafeUrunleri as $urun): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($urun['kod']) ?></td>
                                            <td><?= htmlspecialchars($urun['ad']) ?></td>
                                            <td><?= htmlspecialchars($urun['kategori']) ?></td>
                                            <td><?= htmlspecialchars($urun['birim']) ?></td>
                                            <td><?= htmlspecialchars($urun['stok']) ?></td>
                                            <td><input type="number" class="quantity-input" id="qty-<?= $urun['kod'] ?>" min="1" value="1"></td>
                                            <td><button class="btn btn-primary btn-small" onclick="addToCart('kafe', '<?= htmlspecialchars($urun['kod'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['ad'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['kategori'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['birim'], ENT_QUOTES) ?>')">Sepete Ekle</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Restoran Tab -->
                        <div id="tab-restoran" class="tab-content hidden">
                            <div class="filter-section">
                                <div class="filter-row">
                                    <div class="filter-group">
                                        <label>Ürün Adı</label>
                                        <input type="text" id="restoranUrunAdi" placeholder="Ürün adı ara...">
                                    </div>
                                    <div class="filter-group">
                                        <label>Menü Grubu</label>
                                        <select id="restoranMenuGrubu">
                                            <option value="">Tümü</option>
                                            <option value="Ana Yemek">Ana Yemek</option>
                                            <option value="Meze">Meze</option>
                                            <option value="Salata">Salata</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="filter-buttons">
                                    <button class="btn btn-primary btn-small" onclick="searchRestoran()">Ara</button>
                                    <button class="btn btn-secondary btn-small" onclick="clearRestoranFilters()">Temizle</button>
                                </div>
                            </div>

                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Ürün Kodu</th>
                                            <th>Ürün Adı</th>
                                            <th>Menü Grubu</th>
                                            <th>Birim</th>
                                            <th>Stok</th>
                                            <th>Etkinlik Miktarı</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="restoranTableBody">
                                        <?php foreach ($restoranUrunleri as $urun): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($urun['kod']) ?></td>
                                            <td><?= htmlspecialchars($urun['ad']) ?></td>
                                            <td><?= htmlspecialchars($urun['menuGrubu']) ?></td>
                                            <td><?= htmlspecialchars($urun['birim']) ?></td>
                                            <td><?= htmlspecialchars($urun['stok']) ?></td>
                                            <td><input type="number" class="quantity-input" id="qty-<?= $urun['kod'] ?>" min="1" value="1"></td>
                                            <td><button class="btn btn-primary btn-small" onclick="addToCart('restoran', '<?= htmlspecialchars($urun['kod'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['ad'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['menuGrubu'], ENT_QUOTES) ?>', '<?= htmlspecialchars($urun['birim'], ENT_QUOTES) ?>')">Sepete Ekle</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Sağ Panel - Sepet -->
                <div class="right-panel">
                    <div class="card sepet-panel">
                        <h3>Etkinlik Sepeti</h3>
                        <div class="sepet-event-info">
                            <strong><?= htmlspecialchars($etkinlikAdi) ?></strong> – <?= htmlspecialchars($secilenSube) ?>
                        </div>
                        
                        <div id="sepetContent">
                            <div class="sepet-empty">Sepetiniz boş</div>
                        </div>
                        
                        <div id="sepetSummary" class="sepet-summary hidden">
                            <div class="sepet-summary-row">
                                <span>Toplam Satır:</span>
                                <span id="totalRows">0</span>
                            </div>
                            <div class="sepet-summary-row">
                                <span>Tahmini Bütçe:</span>
                                <span id="totalBudget">-</span>
                            </div>
                        </div>
                        
                        <div id="sepetActions" class="sepet-actions hidden">
                            <button class="btn btn-primary" onclick="createEventFromCart()">Sepetten Etkinlik Oluştur</button>
                            <button class="btn btn-secondary" onclick="clearCart()">Sepeti Temizle</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let cart = [];
        let currentCategory = 'kitapevi';

        function switchCategory(category) {
            currentCategory = category;
            
            // Tab butonlarını güncelle
            document.querySelectorAll('.category-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Tab içeriklerini göster/gizle
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            
            const targetTab = document.getElementById('tab-' + category);
            if (targetTab) {
                targetTab.classList.remove('hidden');
            }
        }

        function addToCart(category, kod, ad, detay, birim) {
            const qtyInput = document.getElementById('qty-' + kod);
            const quantity = parseInt(qtyInput.value) || 1;
            
            const cartItem = {
                id: Date.now() + Math.random(), // Unique ID
                category: category,
                kod: kod,
                ad: ad,
                detay: detay,
                birim: birim,
                miktar: quantity,
                not: ''
            };
            
            cart.push(cartItem);
            renderCart();
            
            // Input'u sıfırla
            qtyInput.value = 1;
        }

        function removeFromCart(id) {
            cart = cart.filter(item => item.id !== id);
            renderCart();
        }

        function renderCart() {
            const sepetContent = document.getElementById('sepetContent');
            const sepetSummary = document.getElementById('sepetSummary');
            const sepetActions = document.getElementById('sepetActions');
            
            if (cart.length === 0) {
                sepetContent.innerHTML = '<div class="sepet-empty">Sepetiniz boş</div>';
                sepetSummary.classList.add('hidden');
                sepetActions.classList.add('hidden');
            } else {
                const kaynakClassMap = {
                    'kitapevi': 'sepet-kaynak-kitapevi',
                    'kafe': 'sepet-kaynak-kafe',
                    'restoran': 'sepet-kaynak-restoran'
                };
                
                const kaynakLabelMap = {
                    'kitapevi': 'Kitapevi',
                    'kafe': 'Kafe',
                    'restoran': 'Restoran'
                };
                
                sepetContent.innerHTML = `
                    <div class="sepet-table-container">
                        <table class="sepet-table">
                            <thead>
                                <tr>
                                    <th>Kaynak</th>
                                    <th>Ürün / İçerik Adı</th>
                                    <th>Birim</th>
                                    <th>Miktar</th>
                                    <th>Not</th>
                                    <th>Sil</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${cart.map(item => {
                                    const kaynakClass = kaynakClassMap[item.category] || '';
                                    const kaynakLabel = kaynakLabelMap[item.category] || item.category;
                                    return `
                                        <tr>
                                            <td><span class="sepet-kaynak ${kaynakClass}">${escapeHtml(kaynakLabel)}</span></td>
                                            <td>${escapeHtml(item.ad)}</td>
                                            <td>${escapeHtml(item.birim)}</td>
                                            <td>${item.miktar}</td>
                                            <td>
                                                <input type="text" 
                                                       class="sepet-not-input" 
                                                       placeholder="Not ekle..." 
                                                       value="${escapeHtml(item.not || '')}"
                                                       onchange="updateCartNote(${item.id}, this.value)">
                                            </td>
                                            <td>
                                                <button class="sepet-remove-btn" onclick="removeFromCart(${item.id})" title="Sil">🗑️</button>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
                
                document.getElementById('totalRows').textContent = cart.length;
                sepetSummary.classList.remove('hidden');
                sepetActions.classList.remove('hidden');
            }
        }
        
        function updateCartNote(id, note) {
            const item = cart.find(i => i.id === id);
            if (item) {
                item.not = note;
            }
        }
        
        function clearCart() {
            if (confirm('Sepeti temizlemek istediğinize emin misiniz?')) {
                cart = [];
                renderCart();
            }
        }

        function createEventFromCart() {
            if (cart.length === 0) {
                alert('Sepetiniz boş. Lütfen önce ürün ekleyin.');
                return;
            }
            
            // Konfeti animasyonunu başlat
            startConfetti();
            
            // Başarı mesajını göster
            setTimeout(() => {
                showSuccessMessage();
            }, 500);
            
            // 2 saniye sonra yönlendir
            setTimeout(() => {
                window.location.href = 'Muse.php';
            }, 2500);
        }
        
        function startConfetti() {
            const canvas = document.createElement('canvas');
            canvas.id = 'confetti-canvas';
            canvas.style.position = 'fixed';
            canvas.style.top = '0';
            canvas.style.left = '0';
            canvas.style.width = '100%';
            canvas.style.height = '100%';
            canvas.style.pointerEvents = 'none';
            canvas.style.zIndex = '9999';
            document.body.appendChild(canvas);
            
            const ctx = canvas.getContext('2d');
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
            
            const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'];
            const confetti = [];
            const confettiCount = 150;
            
            for (let i = 0; i < confettiCount; i++) {
                confetti.push({
                    x: Math.random() * canvas.width,
                    y: -Math.random() * canvas.height,
                    r: Math.random() * 6 + 4,
                    d: Math.random() * confettiCount,
                    color: colors[Math.floor(Math.random() * colors.length)],
                    tilt: Math.floor(Math.random() * 10) - 10,
                    tiltAngleIncrement: Math.random() * 0.07 + 0.05,
                    tiltAngle: 0
                });
            }
            
            let animationId;
            function animate() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                
                confetti.forEach((c, i) => {
                    ctx.beginPath();
                    ctx.lineWidth = c.r / 2;
                    ctx.strokeStyle = c.color;
                    ctx.moveTo(c.x + c.tilt + c.r, c.y);
                    ctx.lineTo(c.x + c.tilt, c.y + c.tilt + c.r);
                    ctx.stroke();
                    
                    c.tiltAngle += c.tiltAngleIncrement;
                    c.y += (Math.cos(c.d) + 3 + c.r / 2) / 2;
                    c.tilt = Math.sin(c.tiltAngle - i / 3) * 15;
                    
                    if (c.y > canvas.height) {
                        confetti[i] = {
                            x: Math.random() * canvas.width,
                            y: -20,
                            r: c.r,
                            d: c.d,
                            color: c.color,
                            tilt: Math.floor(Math.random() * 10) - 10,
                            tiltAngleIncrement: c.tiltAngleIncrement,
                            tiltAngle: c.tiltAngle
                        };
                    }
                });
                
                animationId = requestAnimationFrame(animate);
            }
            
            animate();
            
            // 2 saniye sonra animasyonu durdur ve canvas'ı kaldır
            setTimeout(() => {
                cancelAnimationFrame(animationId);
                canvas.remove();
            }, 2000);
        }
        
        function showSuccessMessage() {
            const message = document.createElement('div');
            message.id = 'success-message';
            message.style.cssText = `
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: white;
                padding: 40px 60px;
                border-radius: 16px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                z-index: 10000;
                text-align: center;
                animation: scaleIn 0.3s ease-out;
            `;
            
            message.innerHTML = `
                <div style="font-size: 48px; margin-bottom: 16px;">🎉</div>
                <h2 style="color: #1e40af; font-size: 24px; margin-bottom: 8px; font-weight: 600;">Etkinlik Oluşturuldu!</h2>
                <p style="color: #6b7280; font-size: 16px;">Etkinlik başarıyla oluşturuldu.</p>
            `;
            
            // CSS animasyonu ekle
            if (!document.getElementById('success-animation-style')) {
                const style = document.createElement('style');
                style.id = 'success-animation-style';
                style.textContent = `
                    @keyframes scaleIn {
                        from {
                            transform: translate(-50%, -50%) scale(0.8);
                            opacity: 0;
                        }
                        to {
                            transform: translate(-50%, -50%) scale(1);
                            opacity: 1;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
            
            document.body.appendChild(message);
            
            // 2 saniye sonra mesajı kaldır
            setTimeout(() => {
                message.style.animation = 'scaleIn 0.3s ease-out reverse';
                setTimeout(() => message.remove(), 300);
            }, 2000);
        }

        function searchKitapevi() {
            const kitapAdi = document.getElementById('kitapAdi').value.toLowerCase();
            const yazar = document.getElementById('yazar').value.toLowerCase();
            const tur = document.getElementById('tur').value.toLowerCase();
            const isbn = document.getElementById('isbn').value.toLowerCase();
            
            const rows = document.querySelectorAll('#kitapeviTableBody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = (!kitapAdi || text.includes(kitapAdi)) &&
                             (!yazar || text.includes(yazar)) &&
                             (!tur || text.includes(tur)) &&
                             (!isbn || text.includes(isbn));
                row.style.display = match ? '' : 'none';
            });
        }

        function clearKitapeviFilters() {
            document.getElementById('kitapAdi').value = '';
            document.getElementById('yazar').value = '';
            document.getElementById('tur').value = '';
            document.getElementById('isbn').value = '';
            searchKitapevi();
        }

        function searchKafe() {
            const urunAdi = document.getElementById('kafeUrunAdi').value.toLowerCase();
            const kategori = document.getElementById('kafeKategori').value.toLowerCase();
            
            const rows = document.querySelectorAll('#kafeTableBody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = (!urunAdi || text.includes(urunAdi)) &&
                             (!kategori || text.includes(kategori));
                row.style.display = match ? '' : 'none';
            });
        }

        function clearKafeFilters() {
            document.getElementById('kafeUrunAdi').value = '';
            document.getElementById('kafeKategori').value = '';
            searchKafe();
        }

        function searchRestoran() {
            const urunAdi = document.getElementById('restoranUrunAdi').value.toLowerCase();
            const menuGrubu = document.getElementById('restoranMenuGrubu').value.toLowerCase();
            
            const rows = document.querySelectorAll('#restoranTableBody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                const match = (!urunAdi || text.includes(urunAdi)) &&
                             (!menuGrubu || text.includes(menuGrubu));
                row.style.display = match ? '' : 'none';
            });
        }

        function clearRestoranFilters() {
            document.getElementById('restoranUrunAdi').value = '';
            document.getElementById('restoranMenuGrubu').value = '';
            searchRestoran();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Enter tuşu ile arama
        ['kitapAdi', 'yazar', 'tur', 'isbn', 'kafeUrunAdi', 'restoranUrunAdi'].forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        if (id.startsWith('kitap') || id === 'yazar' || id === 'tur' || id === 'isbn') {
                            searchKitapevi();
                        } else if (id.startsWith('kafe')) {
                            searchKafe();
                        } else if (id.startsWith('restoran')) {
                            searchRestoran();
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>

