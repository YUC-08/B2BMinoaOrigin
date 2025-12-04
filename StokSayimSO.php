<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Session'dan bilgileri al (navbar'daki gibi)
$whsCode = $_SESSION['WhsCode'] ?? '';
$userName = $_SESSION['UserName'] ?? '';
$branchDesc = '';
if (isset($_SESSION['Branch2']) && is_array($_SESSION['Branch2'])) {
    $branchDesc = $_SESSION['Branch2']['Description'] ?? $_SESSION['Branch2']['Name'] ?? '';
}
$displayUser = $branchDesc ?: $userName;

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// Varsayılan sayım tarihi bugün
$sayimTarihi = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Sayımı Oluştur - MINOA</title>
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
    color: #111827;
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

.card-header {
    padding: 20px 24px 0 24px;
}

.card-header h3 {
    color: #1e40af;
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 0;
}

.card-body {
    padding: 16px 24px 24px 24px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-size: 13px;
    color: #4b5563;
    margin-bottom: 4px;
    font-weight: 500;
}

.form-group input[type="date"],
.form-group input[type="text"],
.form-group input[type="number"] {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: white;
    width: 100%;
    min-height: 42px;
    box-sizing: border-box;
}

.form-group input[type="date"]:focus,
.form-group input[type="text"]:focus,
.form-group input[type="number"]:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group input[readonly],
.readonly-field {
    background: #f3f4f6;
    cursor: not-allowed;
    color: #374151;
    font-weight: 500;
}

.readonly-field {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    min-height: 42px;
    display: flex;
    align-items: center;
}

.multi-select-container {
    position: relative;
}

.multi-select-input {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    padding: 10px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    min-height: 42px;
    transition: all 0.2s ease;
}

.multi-select-input:hover {
    border-color: #3b82f6;
}

.multi-select-input.active {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.multi-select-input input {
    border: none;
    outline: none;
    flex: 1;
    background: transparent;
    min-width: 120px;
    font-size: 14px;
}

.multi-select-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    max-height: 280px;
    overflow-y: auto;
    z-index: 9999;
    display: none;
    box-shadow: 0 4px 16px rgba(0,0,0,0.15);
}

.multi-select-dropdown.show {
    display: block;
}

.multi-select-option {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.15s;
    font-size: 14px;
}

.multi-select-option:hover {
    background: #f9fafb;
}

.multi-select-option.selected {
    background: #dbeafe;
    color: #1e40af;
    font-weight: 500;
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
    border-radius: 8px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s ease;
    background: white;
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
    border-radius: 8px;
    font-size: 14px;
    min-width: 220px;
    transition: all 0.2s ease;
}

.search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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

.data-table tbody tr.empty-row td {
    text-align: center;
    padding: 2rem;
    color: #9ca3af;
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

.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
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

.btn-save-large {
    width: 100%;
    padding: 1rem 2rem;
    font-size: 1.1rem;
    font-weight: 600;
    margin-top: 1.5rem;
}

.empty-message {
    text-align: center;
    padding: 3rem;
    color: #9ca3af;
    font-size: 14px;
}

@media (max-width: 1200px) {
    .form-grid {
        grid-template-columns: repeat(2, 1fr);
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
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .table-controls {
        flex-direction: column;
        align-items: stretch;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Stok Sayımı Oluştur</h2>
            <button class="btn btn-primary" onclick="saveCount()">✓ Kaydet</button>
        </header>

        <div class="content-wrapper">
            <!-- Üst Kart: Giriş Alanları -->
            <section class="card">
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Şube Kodu:</label>
                            <div class="readonly-field"><?= htmlspecialchars($whsCode ?: '-') ?></div>
                        </div>
                        
                        <div class="form-group">
                            <label>Kullanıcı:</label>
                            <div class="readonly-field"><?= htmlspecialchars($displayUser ?: '-') ?></div>
                        </div>
                        
                        <div class="form-group">
                            <label>Sayım Tarihi:</label>
                            <input type="date" id="sayimTarihi" value="<?= htmlspecialchars($sayimTarihi) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Kalem Tanımı:</label>
                            <div class="multi-select-container">
                                <div class="multi-select-input" onclick="toggleDropdown('itemName')">
                                    <div id="itemNameTags"></div>
                                    <input type="text" id="filterItemName" placeholder="Kalem Tanımı seçiniz" readonly>
                                </div>
                                <div class="multi-select-dropdown" id="itemNameDropdown">
                                    <div class="multi-select-option" data-value="" onclick="selectOption('itemName', '', 'Tümü')">Tümü</div>
                                    <div id="itemNameOptions"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Kalem Grubu:</label>
                            <div class="multi-select-container">
                                <div class="multi-select-input" onclick="toggleDropdown('itemGroup')">
                                    <div id="itemGroupTags"></div>
                                    <input type="text" id="filterItemGroup" placeholder="Kalem Grubu seçiniz" readonly>
                                </div>
                                <div class="multi-select-dropdown" id="itemGroupDropdown">
                                    <div class="multi-select-option" data-value="" onclick="selectOption('itemGroup', '', 'Tümü')">Tümü</div>
                                    <div id="itemGroupOptions"></div>
                                </div>
                            </div>
                        </div>
                    </div>
            </section>

            <!-- Alt Kart: Tablo -->
            <section class="card">
                <div class="table-controls">
                    <div class="show-entries">
                        Sayfada 
                        <select class="entries-select" id="entriesPerPage">
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="75">75</option>
                        </select>
                        kayıt göster
                    </div>
                    <div class="search-box">
                        <input type="text" class="search-input" id="tableSearch" placeholder="Ara..." onkeyup="if(event.key==='Enter') performSearch()">
                        <button class="btn btn-secondary" onclick="performSearch()">🔍</button>
                    </div>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kalem Kodu</th>
                            <th>Kalem Tanımı</th>
                            <th>Kalem Grubu</th>
                            <th>Sayım Miktarı</th>
                            <th>Ölçü Birimi</th>
                            <th>Birim Çevirimi</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <tr class="empty-row">
                            <td colspan="6">Tabloda veri bulunmuyor</td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="margin-top: 1rem; display: flex; justify-content: space-between; align-items: center;">
                    <div style="color: #6b7280; font-size: 0.9rem;">Kayıt yok</div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button class="btn btn-secondary" disabled>Önceki</button>
                        <button class="btn btn-secondary" disabled>Sonraki</button>
                    </div>
                </div>
            </section>

            <!-- Alt Kısım: Kaydet Butonu -->
            <button class="btn btn-primary btn-save-large" onclick="saveCount()">
                ✓ Sayımı Kaydet
            </button>
        </div>
    </main>

    <script>
let selectedItemNames = [];
let selectedItemGroups = [];

function toggleDropdown(type) {
    const dropdown = document.getElementById(type + 'Dropdown');
    const input = dropdown.parentElement.querySelector('.multi-select-input');
    const isOpen = dropdown.classList.contains('show');
    
    document.querySelectorAll('.multi-select-dropdown').forEach(d => d.classList.remove('show'));
    document.querySelectorAll('.multi-select-input').forEach(d => d.classList.remove('active'));
    
    if (!isOpen) {
        dropdown.classList.add('show');
        input.classList.add('active');
    }
}

function selectOption(type, value, text) {
    let selectedArray;
    
    if (type === 'itemName') {
        selectedArray = selectedItemNames;
    } else if (type === 'itemGroup') {
        selectedArray = selectedItemGroups;
    } else {
        return;
    }
    
    if (value === '') {
        selectedArray.length = 0;
    } else {
        const index = selectedArray.indexOf(value);
        if (index > -1) {
            selectedArray.splice(index, 1);
        } else {
            selectedArray.push(value);
        }
    }
    
    updateFilterDisplay(type);
}

function updateFilterDisplay(type) {
    let tagsContainer;
    let input;
    let selected;
    
    if (type === 'itemName') {
        tagsContainer = document.getElementById('itemNameTags');
        input = document.getElementById('filterItemName');
        selected = selectedItemNames;
    } else if (type === 'itemGroup') {
        tagsContainer = document.getElementById('itemGroupTags');
        input = document.getElementById('filterItemGroup');
        selected = selectedItemGroups;
    } else {
        return;
    }
    
    if (!tagsContainer || !input) return;
    
    tagsContainer.innerHTML = '';
    
    if (selected.length === 0) {
        input.placeholder = type === 'itemName' ? 'Kalem Tanımı seçiniz' : 'Kalem Grubu seçiniz';
    } else {
        input.placeholder = '';
        selected.forEach(value => {
            const tag = document.createElement('span');
            tag.className = 'multi-select-tag';
            tag.style.cssText = 'display: inline-flex; align-items: center; gap: 6px; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 4px 10px; border-radius: 16px; font-size: 0.85rem; font-weight: 500;';
            tag.innerHTML = `${value} <span class="remove" onclick="removeFilter('${type}', '${value}')" style="cursor: pointer; font-weight: bold; font-size: 16px; margin-left: 4px;">×</span>`;
            tagsContainer.appendChild(tag);
        });
    }
    
    // Dropdown'daki seçili durumları güncelle
    const dropdown = document.getElementById(type + 'Dropdown');
    if (dropdown) {
        dropdown.querySelectorAll('.multi-select-option').forEach(opt => {
            const value = opt.getAttribute('data-value');
            if (selected.includes(value)) {
                opt.classList.add('selected');
            } else {
                opt.classList.remove('selected');
            }
        });
    }
}

function removeFilter(type, value) {
    if (type === 'itemName') {
        selectedItemNames = selectedItemNames.filter(v => v !== value);
    } else if (type === 'itemGroup') {
        selectedItemGroups = selectedItemGroups.filter(v => v !== value);
    }
    updateFilterDisplay(type);
}

function performSearch() {
    // Arama işlemi buraya eklenecek
    console.log('Arama yapılıyor...');
}

function saveCount() {
    // Kaydetme işlemi buraya eklenecek
    alert('Sayım kaydedilecek (işlevsellik eklenecek)');
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.multi-select-container')) {
        document.querySelectorAll('.multi-select-dropdown').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.multi-select-input').forEach(d => d.classList.remove('active'));
    }
});
    </script>
</body>
</html>
