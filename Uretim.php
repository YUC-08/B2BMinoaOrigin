<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece RT ve CF kullanıcıları giriş yapabilir (YE göremez)
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'RT' && $uAsOwnr !== 'CF') {
    header("Location: index.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

// ItemGroups verilerini çek (ItemsGroupCode'u GroupName'e çevirmek için)
$itemGroupsSelectValue = "Number,GroupName";
$itemGroupsOrderByValue = "GroupName asc";
$itemGroupsQuery = "ItemGroups?\$select=" . urlencode($itemGroupsSelectValue) . "&\$orderby=" . urlencode($itemGroupsOrderByValue);
$itemGroupsData = $sap->get($itemGroupsQuery);

$itemGroupsMap = [];
if (($itemGroupsData['status'] ?? 0) == 200) {
    $itemGroupsList = $itemGroupsData['response']['value'] ?? $itemGroupsData['value'] ?? [];
    foreach ($itemGroupsList as $group) {
        if (isset($group['Number']) && isset($group['GroupName'])) {
            $itemGroupsMap[$group['Number']] = $group['GroupName'];
        }
    }
}

// Items verilerini çek (Ürün listesi için)
// U_AS_OWNR mapping: Restaurant (YE) → KT, Cafe (CF) → RT
$uAsOwnrForFilter = '';    
if ($uAsOwnr === 'YE') {
    $uAsOwnrForFilter = 'KT'; // Restaurant → KT
} elseif ($uAsOwnr === 'CF') {
    $uAsOwnrForFilter = 'RT'; // Cafe → RT
} else {
    $uAsOwnrForFilter = $uAsOwnr; // Diğer durumlar için aynen kullan
}

$itemsSelectValue = "ItemCode,ItemName,ItemsGroupCode,InventoryUOM,U_AS_OWNR";
$itemsFilterValue = "(U_AS_OWNR eq '{$uAsOwnrForFilter}') and (ItemsGroupCode eq 100 or ItemsGroupCode eq 101)";
// Boşlukları elle %20 ile değiştir (urlencode + yerine %20 kullanıyor, SAP %20 istiyor)
$itemsFilterEncoded = str_replace(' ', '%20', $itemsFilterValue);
$itemsQuery = "Items?\$select=" . urlencode($itemsSelectValue) . "&\$filter=" . $itemsFilterEncoded;

$itemsData = $sap->get($itemsQuery);

$items = [];
if (($itemsData['status'] ?? 0) == 200) {
    if (isset($itemsData['response']['value'])) {
        $items = $itemsData['response']['value'];
    } elseif (isset($itemsData['value'])) {
        $items = $itemsData['value'];
    }
}

// Items verilerini recipes formatına dönüştür
$recipes = [];
foreach ($items as $index => $item) {
    $itemsGroupCode = $item['ItemsGroupCode'] ?? '';
    $groupName = $itemGroupsMap[$itemsGroupCode] ?? $itemsGroupCode;
    
    $recipes[] = [
        'id' => $item['ItemCode'] ?? ($index + 1),
        'urunNo' => $item['ItemCode'] ?? '',
        'urunTanimi' => $item['ItemName'] ?? '',
        'urunGrubu' => $groupName,
        'birim' => $item['InventoryUOM'] ?? ''
    ];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ürün Reçeteleri - MINOA</title>
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

.btn-secondary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
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
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2>Ürün Reçeteleri</h2>
            <button class="btn btn-primary" onclick="window.location.href='UretimSO.php'">+ Yeni Reçete Ekle</button>
        </div>

        <div class="content-wrapper">
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
                                <th>Ürün Numarası</th>
                                <th>Ürün Tanımı</th>
                                <th>Ürün Grubu</th>
                                <th>Birim</th>
                                <th>İşlemler</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <?php foreach ($recipes as $recipe): ?>
                            <tr>
                                <td><?= htmlspecialchars($recipe['urunNo']) ?></td>
                                <td><?= htmlspecialchars($recipe['urunTanimi']) ?></td>
                                <td><?= htmlspecialchars($recipe['urunGrubu']) ?></td>
                                <td><?= htmlspecialchars($recipe['birim']) ?></td>
                                <td>
                                    <a href="UretimDetay.php?id=<?= $recipe['id'] ?>" class="btn-view">Detay</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Search functionality
        function performSearch() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const rows = document.querySelectorAll('#tableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        }

        document.getElementById('searchInput').addEventListener('input', function(e) {
            performSearch();
        });

        // Entries per page
        document.getElementById('entriesPerPage').addEventListener('change', function(e) {
            // Pagination implementasyonu için placeholder
        });
    </script>
</body>
</html>
