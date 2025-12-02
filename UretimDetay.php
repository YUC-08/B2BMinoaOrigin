<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece YE ve CF kullanıcıları giriş yapabilir
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'YE' && $uAsOwnr !== 'CF') {
    header("Location: index.php");
    exit;
}

$itemCode = $_GET['id'] ?? '';

if (empty($itemCode)) {
    header("Location: Uretim.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

// Ürün bilgilerini çek (Items endpoint'inden)
$itemSelectValue = "ItemCode,ItemName,ItemsGroupCode,InventoryUOM";
$itemQuery = "Items('{$itemCode}')?\$select=" . urlencode($itemSelectValue);
$itemData = $sap->get($itemQuery);

$recipeData = [
    'urunNo' => $itemCode,
    'urunTanimi' => '',
    'urunGrubu' => '',
    'birim' => ''
];

// ItemGroups verilerini çek (ItemsGroupCode'u GroupName'e çevirmek için)
$itemGroupsSelectValue = "Number,GroupName";
$itemGroupsQuery = "ItemGroups?\$select=" . urlencode($itemGroupsSelectValue);
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

// Ürün bilgilerini doldur
if (($itemData['status'] ?? 0) == 200) {
    $itemInfo = $itemData['response'] ?? $itemData;
    $recipeData['urunNo'] = $itemInfo['ItemCode'] ?? $itemCode;
    $recipeData['urunTanimi'] = $itemInfo['ItemName'] ?? '';
    $itemsGroupCode = $itemInfo['ItemsGroupCode'] ?? '';
    $recipeData['urunGrubu'] = $itemGroupsMap[$itemsGroupCode] ?? $itemsGroupCode;
    $recipeData['birim'] = $itemInfo['InventoryUOM'] ?? '';
}

//////////////////////////////////////////////////////// Malzeme Listesi (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'materials') {
    header('Content-Type: application/json');
    
    // U_AS_OWNR mapping: Restaurant (YE) → KT, Cafe (CF) → RT
    $uAsOwnrForFilter = '';
    if ($uAsOwnr === 'YE') {
        $uAsOwnrForFilter = 'KT';
    } elseif ($uAsOwnr === 'CF') {
        $uAsOwnrForFilter = 'RT';
    } else {
        $uAsOwnrForFilter = $uAsOwnr;
    }
    
    $materialsSelectValue = "ItemCode,ItemName,ItemsGroupCode,InventoryUOM,UoMGroupEntry";
    $materialsFilterValue = "(U_AS_OWNR eq '{$uAsOwnrForFilter}') and (ItemsGroupCode eq 100 or ItemsGroupCode eq 101 or ItemsGroupCode eq 104)";
    $materialsFilterEncoded = str_replace(' ', '%20', $materialsFilterValue);
    $materialsQuery = "Items?\$select=" . urlencode($materialsSelectValue) . "&\$filter=" . $materialsFilterEncoded;
    $materialsData = $sap->get($materialsQuery);
    
    $materialsList = [];
    if (($materialsData['status'] ?? 0) == 200) {
        $materialsArray = $materialsData['response']['value'] ?? $materialsData['value'] ?? [];
        foreach ($materialsArray as $item) {
            $uomGroupEntry = $item['UoMGroupEntry'] ?? null;
            $baseQuantity = null;
            
            // BaseQuantity bilgisini al
            if ($uomGroupEntry) {
                $uomGroupQuery = "UnitOfMeasurementGroups({$uomGroupEntry})/UoMGroupDefinitionCollection";
                $uomGroupData = $sap->get($uomGroupQuery);
                
                if (($uomGroupData['status'] ?? 0) == 200) {
                    $response = $uomGroupData['response'] ?? $uomGroupData;
                    $definitions = [];
                    
                    // Farklı response yapılarını kontrol et
                    if (isset($response['value']) && is_array($response['value'])) {
                        $definitions = $response['value'];
                    } elseif (isset($response['UoMGroupDefinitionCollection']) && is_array($response['UoMGroupDefinitionCollection'])) {
                        $definitions = $response['UoMGroupDefinitionCollection'];
                    } elseif (is_array($response)) {
                        $definitions = $response;
                    }
                    
                    if (!empty($definitions) && is_array($definitions)) {
                        // İlk tanımı al (genellikle ikincil birim için)
                        $firstDef = $definitions[0] ?? null;
                        if ($firstDef && isset($firstDef['BaseQuantity'])) {
                            $baseQuantity = floatval($firstDef['BaseQuantity']);
                        }
                    }
                }
            }
            
            $materialsList[] = [
                'code' => $item['ItemCode'] ?? '',
                'name' => $item['ItemName'] ?? '',
                'uom' => $item['InventoryUOM'] ?? '',
                'baseQuantity' => $baseQuantity
            ];
        }
    }
    
    echo json_encode(['success' => true, 'materials' => $materialsList]);
    exit;
}
//////////////////////////////////////////////////////// Malzeme Listesi (AJAX)

//////////////////////////////////////////////////////// ProductTrees Güncelleme (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_recipe') {
    header('Content-Type: application/json');
    
    $treeCode = trim($_POST['treeCode'] ?? '');
    $materials = json_decode($_POST['materials'] ?? '[]', true);
    
    if (empty($treeCode)) {
        echo json_encode(['success' => false, 'message' => 'TreeCode bulunamadı.']);
        exit;
    }
    
    if (!is_array($materials) || empty($materials)) {
        echo json_encode(['success' => false, 'message' => 'Malzeme listesi boş olamaz.']);
        exit;
    }
    
    try {
        // ProductTreeLines'ı oluştur
        $productTreeLines = [];
        foreach ($materials as $material) {
            $itemCode = trim($material['itemCode'] ?? '');
            $quantity = floatval($material['miktar'] ?? 0);
            
            if (empty($itemCode) || $quantity <= 0) {
                continue;
            }
            
            $productTreeLines[] = [
                'ItemCode' => $itemCode,
                'Quantity' => $quantity
            ];
        }
        
        if (empty($productTreeLines)) {
            echo json_encode(['success' => false, 'message' => 'Geçerli malzeme bulunamadı.']);
            exit;
        }
        
        // 1. Adım: Mevcut ProductTrees'i sil
        $deleteResult = $sap->delete("ProductTrees('{$treeCode}')");
        
        // DELETE başarılı olmasa bile devam et (zaten yoksa 404 dönebilir)
        // 404 hatası normal, çünkü zaten silinmiş olabilir
        
        // 2. Adım: Yeni ProductTrees'i oluştur
        $productTreePayload = [
            'TreeCode' => $treeCode,
            'TreeType' => 'iProductionTree',
            'ProductTreeLines' => $productTreeLines
        ];
        
        $result = $sap->post('ProductTrees', $productTreePayload);
        
        if (($result['status'] ?? 0) == 200 || ($result['status'] ?? 0) == 201) {
            echo json_encode(['success' => true, 'message' => 'Reçete başarıyla güncellendi.']);
        } else {
            $errorMsg = 'Reçete güncellenemedi.';
            if (isset($result['response']['error']['message']['value'])) {
                $errorMsg = $result['response']['error']['message']['value'];
            } elseif (isset($result['response']['error']['message'])) {
                $errorMsg = is_array($result['response']['error']['message']) 
                    ? ($result['response']['error']['message']['value'] ?? json_encode($result['response']['error']['message']))
                    : $result['response']['error']['message'];
            }
            echo json_encode(['success' => false, 'message' => $errorMsg]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Bir hata oluştu: ' . $e->getMessage()]);
    }
    exit;
}
//////////////////////////////////////////////////////// ProductTrees Güncelleme (AJAX)

// Reçete alt ürünlerini çek (view.svc/ASB2B_ProducTreeDetail_B1SLQuery)
$fatherCode = str_replace("'", "''", $itemCode); // SQL injection koruması
$recipeFilterValue = "Father eq '{$fatherCode}'";
// Boşlukları elle %20 ile değiştir
$recipeFilterEncoded = str_replace(' ', '%20', $recipeFilterValue);
$recipeQuery = "view.svc/ASB2B_ProducTreeDetail_B1SLQuery?\$filter=" . $recipeFilterEncoded;
$recipeDataResponse = $sap->get($recipeQuery);

$malzemeler = [];

if (($recipeDataResponse['status'] ?? 0) == 200) {
    $recipeLines = $recipeDataResponse['response']['value'] ?? $recipeDataResponse['value'] ?? [];
    
    foreach ($recipeLines as $index => $line) {
        $malzemeler[] = [
            'sira' => $index + 1, // Sıra numarası (1, 2, 3...)
            'itemCode' => $line['Code'] ?? $line['ItemCode'] ?? '', // Malzeme kodu
            'malzeme' => $line['ItemName'] ?? $line['Code'] ?? '',
            'miktar' => number_format($line['Quantity'] ?? 0, 2, '.', ''),
            'birim' => $line['InvntryUom'] ?? ''
        ];
    }
} else {
    // Hata durumunda log
    error_log("[URETIMDETAY] Recipe Query Status: " . ($recipeDataResponse['status'] ?? 'NO STATUS'));
    error_log("[URETIMDETAY] Recipe Query: " . $recipeQuery);
    if (isset($recipeDataResponse['response']['error'])) {
        error_log("[URETIMDETAY] Recipe Error: " . json_encode($recipeDataResponse['response']['error']));
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reçete Detayı - MINOA</title>
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

.card-header {
    padding: 24px;
    border-bottom: 1px solid #e5e7eb;
}

.card-header h3 {
    color: #1e40af;
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 20px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.info-label {
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    font-size: 16px;
    color: #1f2937;
    font-weight: 500;
}

.card-body {
    padding: 24px;
}

.card-body h4 {
    color: #1e40af;
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 20px;
}

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

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #f0f9ff;
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

    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2>Reçete Detayı</h2>
            <a href="Uretim.php" class="btn btn-secondary">← Geri</a>
        </div>

        <div class="content-wrapper">
            <!-- Ürün Bilgileri -->
            <div class="card">
                <div class="card-header">
                    <h3>Ürün Bilgileri</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Ürün Numarası</span>
                            <span class="info-value"><?= htmlspecialchars($recipeData['urunNo']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Ürün Tanımı</span>
                            <span class="info-value"><?= htmlspecialchars($recipeData['urunTanimi']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Ürün Grubu</span>
                            <span class="info-value"><?= htmlspecialchars($recipeData['urunGrubu']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Birim</span>
                            <span class="info-value"><?= htmlspecialchars($recipeData['birim']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Malzemeler -->
            <div class="card">
                <div class="card-body">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h4 style="margin: 0;">Malzemeler</h4>
                        <div style="display: flex; gap: 8px;">
                            <button type="button" id="deleteSelectedBtn" onclick="deleteSelectedMaterials()" style="display: none; background: #ef4444; color: white; border: none; padding: 0.5rem 1rem; border-radius: 8px; cursor: pointer; font-weight: 500;">🗑️ Seçilenleri Sil</button>
                            <button type="button" id="addMaterialBtn" class="btn btn-primary" style="background: #3b82f6; color: white; border: none; padding: 0.5rem 1rem; border-radius: 8px; cursor: pointer; font-weight: 500;">+ Malzeme Ekle</button>
                        </div>
                    </div>
                    <div class="table-container">
                        <table id="materialsTable">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)" style="cursor: pointer;">
                                    </th>
                                    <th>Sıra</th>
                                    <th>Malzeme</th>
                                    <th>Miktar</th>
                                    <th>Birim</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="materialsTableBody">
                                <?php foreach ($malzemeler as $index => $malzeme): ?>
                                <tr data-item-code="<?= htmlspecialchars($malzeme['itemCode']) ?>" data-index="<?= $index ?>">
                                    <td style="text-align: center;">
                                        <input type="checkbox" class="material-checkbox" onchange="updateDeleteButton()" style="cursor: pointer;">
                                    </td>
                                    <td class="sira-cell"><?= htmlspecialchars($malzeme['sira']) ?></td>
                                    <td class="malzeme-cell"><?= htmlspecialchars($malzeme['malzeme']) ?></td>
                                    <td class="miktar-cell">
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <button type="button" class="btn-minus" onclick="decreaseQuantity(this)" style="background: #ef4444; color: white; border: none; width: 28px; height: 28px; border-radius: 4px; cursor: pointer; font-size: 16px; display: none;">-</button>
                                            <span class="miktar-display"><?= htmlspecialchars($malzeme['miktar']) ?></span>
                                            <input type="text" class="miktar-input" value="<?= htmlspecialchars($malzeme['miktar']) ?>" onfocus="saveCursorPosition(this)" onkeyup="saveCursorPosition(this)" onclick="saveCursorPosition(this)" oninput="validateNumberInput(this)" style="display: none; width: 100px; padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                                            <button type="button" class="btn-plus" onclick="increaseQuantity(this)" style="background: #10b981; color: white; border: none; width: 28px; height: 28px; border-radius: 4px; cursor: pointer; font-size: 16px; display: none;">+</button>
                                        </div>
                                    </td>
                                    <td class="birim-cell"><?= htmlspecialchars($malzeme['birim']) ?></td>
                                    <td>
                                        <button type="button" class="btn-edit" onclick="editMaterial(this)" style="background: #10b981; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; margin-right: 4px; font-size: 13px;">✏️ Düzenle</button>
                                        <button type="button" class="btn-save" onclick="saveMaterial(this)" style="display: none; background: #3b82f6; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; margin-right: 4px; font-size: 13px;">💾 Kaydet</button>
                                        <button type="button" class="btn-cancel" onclick="cancelEdit(this)" style="display: none; background: #6b7280; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; margin-right: 4px; font-size: 13px;">❌ İptal</button>
                                        <button type="button" class="btn-delete" onclick="deleteMaterial(this)" style="background: #ef4444; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 13px;">🗑️ Sil</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const itemCode = '<?= htmlspecialchars($itemCode, ENT_QUOTES) ?>';
        let materials = <?= json_encode($malzemeler, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        let materialCounter = materials.length;
        let editingRow = null;
        let originalMiktar = null;
        let savedCursorPositions = {}; // Input'lar için cursor pozisyonlarını sakla

        // Malzeme düzenle
        function editMaterial(button) {
            const row = button.closest('tr');
            if (editingRow && editingRow !== row) {
                cancelEdit(editingRow.querySelector('.btn-cancel'));
            }
            
            editingRow = row;
            const miktarCell = row.querySelector('.miktar-cell');
            const miktarDisplay = miktarCell.querySelector('.miktar-display');
            const miktarInput = miktarCell.querySelector('.miktar-input');
            
            originalMiktar = miktarDisplay.textContent.trim();
            miktarDisplay.style.display = 'none';
            miktarInput.style.display = 'inline-block';
            row.querySelector('.btn-minus').style.display = 'inline-block';
            row.querySelector('.btn-plus').style.display = 'inline-block';
            miktarInput.value = originalMiktar;
            miktarInput.focus();
            
            row.querySelector('.btn-edit').style.display = 'none';
            row.querySelector('.btn-save').style.display = 'inline-block';
            row.querySelector('.btn-cancel').style.display = 'inline-block';
            row.querySelector('.btn-delete').style.display = 'none';
        }

        // Cursor pozisyonunu sakla
        function saveCursorPosition(input) {
            setTimeout(() => {
                const pos = input.selectionStart;
                if (pos !== null && pos !== undefined) {
                    savedCursorPositions[input] = pos;
                }
            }, 0);
        }

        // Number input validasyonu
        function validateNumberInput(input) {
            let value = input.value;
            // Sadece sayı, nokta ve virgül kabul et
            value = value.replace(/[^0-9.,]/g, '');
            // Virgülü noktaya çevir
            value = value.replace(',', '.');
            // Birden fazla nokta varsa sadece ilkini tut
            const parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }
            input.value = value;
            saveCursorPosition(input);
        }

        // Miktar artır
        function increaseQuantity(button) {
            const row = button.closest('tr');
            const miktarInput = row.querySelector('.miktar-input');
            
            // Input'a focus yap
            miktarInput.focus();
            
            // Kaydedilmiş cursor pozisyonunu al veya mevcut pozisyonu al
            let cursorPos = savedCursorPositions[miktarInput];
            if (cursorPos === undefined || cursorPos === null) {
                cursorPos = miktarInput.selectionStart;
                if (cursorPos === null || cursorPos === undefined) {
                    cursorPos = miktarInput.value.length;
                }
            }
            
            const currentValue = parseFloat(miktarInput.value) || 0;
            const valueStr = miktarInput.value.toString();
            const dotIndex = valueStr.indexOf('.');
            
            let increment = 1; // Varsayılan: 1
            if (dotIndex !== -1) {
                // Nokta varsa, cursor pozisyonuna göre belirle
                if (cursorPos <= dotIndex) {
                    // Noktanın solunda veya noktanın üzerinde: 1'er artır
                    increment = 1;
                } else {
                    // Noktanın sağında: 0.5 artır
                    increment = 0.5;
                }
            }
            
            miktarInput.value = (currentValue + increment).toFixed(2);
            
            // Cursor pozisyonunu koru ve güncelle
            setTimeout(() => {
                const newCursorPos = Math.min(cursorPos, miktarInput.value.length);
                miktarInput.setSelectionRange(newCursorPos, newCursorPos);
                savedCursorPositions[miktarInput] = newCursorPos;
            }, 0);
        }

        // Miktar azalt
        function decreaseQuantity(button) {
            const row = button.closest('tr');
            const miktarInput = row.querySelector('.miktar-input');
            
            // Input'a focus yap
            miktarInput.focus();
            
            // Kaydedilmiş cursor pozisyonunu al veya mevcut pozisyonu al
            let cursorPos = savedCursorPositions[miktarInput];
            if (cursorPos === undefined || cursorPos === null) {
                cursorPos = miktarInput.selectionStart;
                if (cursorPos === null || cursorPos === undefined) {
                    cursorPos = miktarInput.value.length;
                }
            }
            
            const currentValue = parseFloat(miktarInput.value) || 0;
            const valueStr = miktarInput.value.toString();
            const dotIndex = valueStr.indexOf('.');
            
            let decrement = 1; // Varsayılan: 1
            if (dotIndex !== -1) {
                // Nokta varsa, cursor pozisyonuna göre belirle
                if (cursorPos <= dotIndex) {
                    // Noktanın solunda veya noktanın üzerinde: 1'er azalt
                    decrement = 1;
                } else {
                    // Noktanın sağında: 0.5 azalt
                    decrement = 0.5;
                }
            }
            
            const newValue = currentValue - decrement;
            if (newValue >= 0) {
                miktarInput.value = newValue.toFixed(2);
            } else {
                miktarInput.value = '0.00';
            }
            
            // Cursor pozisyonunu koru ve güncelle
            setTimeout(() => {
                const newCursorPos = Math.min(cursorPos, miktarInput.value.length);
                miktarInput.setSelectionRange(newCursorPos, newCursorPos);
                savedCursorPositions[miktarInput] = newCursorPos;
            }, 0);
        }

        // Malzeme kaydet
        function saveMaterial(button) {
            const row = button.closest('tr');
            const miktarCell = row.querySelector('.miktar-cell');
            const miktarDisplay = miktarCell.querySelector('.miktar-display');
            const miktarInput = miktarCell.querySelector('.miktar-input');
            const newMiktar = parseFloat(miktarInput.value);
            
            if (isNaN(newMiktar) || newMiktar <= 0) {
                alert('Miktar 0\'dan büyük olmalıdır.');
                miktarInput.focus();
                return;
            }
            
            // Display'i güncelle
            miktarDisplay.textContent = newMiktar.toFixed(2);
            miktarDisplay.style.display = 'inline';
            miktarInput.style.display = 'none';
            row.querySelector('.btn-minus').style.display = 'none';
            row.querySelector('.btn-plus').style.display = 'none';
            
            row.querySelector('.btn-edit').style.display = 'inline-block';
            row.querySelector('.btn-save').style.display = 'none';
            row.querySelector('.btn-cancel').style.display = 'none';
            row.querySelector('.btn-delete').style.display = 'inline-block';
            
            editingRow = null;
            originalMiktar = null;
            
            // Backend'e gönder
            updateRecipe();
        }

        // Düzenlemeyi iptal et
        function cancelEdit(button) {
            const row = button.closest('tr');
            const miktarCell = row.querySelector('.miktar-cell');
            const miktarDisplay = miktarCell.querySelector('.miktar-display');
            const miktarInput = miktarCell.querySelector('.miktar-input');
            
            miktarDisplay.style.display = 'inline';
            miktarInput.style.display = 'none';
            row.querySelector('.btn-minus').style.display = 'none';
            row.querySelector('.btn-plus').style.display = 'none';
            miktarInput.value = originalMiktar;
            
            row.querySelector('.btn-edit').style.display = 'inline-block';
            row.querySelector('.btn-save').style.display = 'none';
            row.querySelector('.btn-cancel').style.display = 'none';
            row.querySelector('.btn-delete').style.display = 'inline-block';
            
            editingRow = null;
            originalMiktar = null;
        }

        // Tümünü seç/seçimi kaldır
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.material-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateDeleteButton();
        }

        // Seçilenleri sil butonunu güncelle
        function updateDeleteButton() {
            const checkboxes = document.querySelectorAll('.material-checkbox:checked');
            const deleteBtn = document.getElementById('deleteSelectedBtn');
            if (checkboxes.length > 0) {
                deleteBtn.style.display = 'inline-block';
            } else {
                deleteBtn.style.display = 'none';
            }
        }

        // Seçilen malzemeleri sil
        function deleteSelectedMaterials() {
            const checkboxes = document.querySelectorAll('.material-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Lütfen silmek istediğiniz malzemeleri seçin.');
                return;
            }
            
            if (!confirm(`${checkboxes.length} malzeme silinecek. Emin misiniz?`)) {
                return;
            }
            
            // Seçilen satırları sil
            checkboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                if (editingRow === row) {
                    editingRow = null;
                    originalMiktar = null;
                }
                row.remove();
            });
            
            // Tümünü seç checkbox'ını sıfırla
            document.getElementById('selectAllCheckbox').checked = false;
            updateDeleteButton();
            updateSiraNumbers();
            
            // Backend'e gönder
            updateRecipe();
        }

        // Malzeme sil
        function deleteMaterial(button) {
            if (!confirm('Bu malzemeyi silmek istediğinize emin misiniz?')) {
                return;
            }
            
            const row = button.closest('tr');
            const itemCodeToDelete = row.getAttribute('data-item-code');
            
            // Eğer düzenleme modundaysa iptal et
            if (editingRow === row) {
                editingRow = null;
                originalMiktar = null;
            }
            
            // Satırı DOM'dan kaldır
            row.remove();
            updateSiraNumbers();
            
            // Backend'e gönder (kalan tüm satırları)
            updateRecipe();
        }

        // Sıra numaralarını güncelle
        function updateSiraNumbers() {
            const rows = document.querySelectorAll('#materialsTableBody tr');
            rows.forEach((row, index) => {
                row.querySelector('.sira-cell').textContent = index + 1;
            });
        }

        // Reçeteyi güncelle
        function updateRecipe() {
            const rows = document.querySelectorAll('#materialsTableBody tr');
            const materialsToSend = [];
            
            rows.forEach((row) => {
                const itemCode = row.getAttribute('data-item-code');
                if (!itemCode) return; // data-item-code yoksa atla
                
                const miktarDisplay = row.querySelector('.miktar-display');
                const miktarInput = row.querySelector('.miktar-input');
                const miktar = miktarInput && miktarInput.style.display !== 'none' 
                    ? parseFloat(miktarInput.value) 
                    : parseFloat(miktarDisplay ? miktarDisplay.textContent.trim() : 0);
                
                if (itemCode && miktar > 0) {
                    materialsToSend.push({
                        itemCode: itemCode,
                        miktar: miktar
                    });
                }
            });
            
            if (materialsToSend.length === 0) {
                alert('En az bir malzeme olmalıdır. Lütfen en az bir malzeme ekleyin.');
                location.reload();
                return;
            }
            
            // Loading göster
            const saveButtons = document.querySelectorAll('.btn-save');
            saveButtons.forEach(btn => {
                if (btn.style.display !== 'none') {
                    btn.disabled = true;
                    btn.textContent = 'Kaydediliyor...';
                }
            });
            
            const formData = new FormData();
            formData.append('action', 'update_recipe');
            formData.append('treeCode', itemCode);
            formData.append('materials', JSON.stringify(materialsToSend));
            
            fetch('UretimDetay.php?id=' + itemCode, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    // Başarılı - sayfayı yenile (alert gösterme, direkt reload)
                    location.reload();
                } else {
                    alert('Hata: ' + (result.message || 'Bilinmeyen bir hata oluştu.'));
                    // Hata durumunda butonları tekrar aktif et
                    saveButtons.forEach(btn => {
                        btn.disabled = false;
                        btn.textContent = '💾 Kaydet';
                    });
                }
            })
            .catch(error => {
                alert('Bir hata oluştu: ' + error.message);
                // Hata durumunda butonları tekrar aktif et
                saveButtons.forEach(btn => {
                    btn.disabled = false;
                    btn.textContent = '💾 Kaydet';
                });
            });
        }

        // Malzeme listesi
        let materialsList = [];
        let materialCodeMap = {};
        
        // Malzeme listesini yükle
        fetch('UretimDetay.php?id=' + itemCode + '&ajax=materials')
            .then(response => response.json())
            .then(result => {
                if (result.success && result.materials) {
                    materialsList = result.materials.map(m => m.name);
                    result.materials.forEach(m => {
                        materialCodeMap[m.name] = {
                            code: m.code,
                            name: m.name,
                            uom: m.uom,
                            baseQuantity: m.baseQuantity
                        };
                    });
                }
            });

        // Malzeme ekle modal
        let addMaterialModal = null;
        
        document.getElementById('addMaterialBtn').addEventListener('click', function() {
            if (!addMaterialModal) {
                createAddMaterialModal();
            }
            addMaterialModal.style.display = 'flex';
        });

        function createAddMaterialModal() {
            addMaterialModal = document.createElement('div');
            addMaterialModal.style.cssText = 'display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;';
            
            const modalContent = document.createElement('div');
            modalContent.style.cssText = 'background: white; padding: 24px; border-radius: 12px; width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto;';
            
            modalContent.innerHTML = `
                <h3 style="margin: 0 0 20px 0; color: #1e40af;">Yeni Malzeme Ekle</h3>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Malzeme</label>
                    <div style="position: relative;">
                        <input type="text" id="newMaterialSearch" placeholder="Malzeme ara..." style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px;">
                        <div id="newMaterialAutocomplete" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #d1d5db; border-radius: 8px; max-height: 200px; overflow-y: auto; z-index: 1001; margin-top: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);"></div>
                    </div>
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 500;">Miktar</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" onclick="decreaseNewMaterialQty()" style="background: #ef4444; color: white; border: none; width: 36px; height: 36px; border-radius: 8px; cursor: pointer; font-size: 18px; font-weight: bold;">-</button>
                        <input type="number" id="newMaterialQty" placeholder="0" step="0.01" min="0" style="flex: 1; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; text-align: center;">
                        <button type="button" onclick="increaseNewMaterialQty()" style="background: #10b981; color: white; border: none; width: 36px; height: 36px; border-radius: 8px; cursor: pointer; font-size: 18px; font-weight: bold;">+</button>
                    </div>
                </div>
                <div style="display: flex; gap: 12px; justify-content: flex-end;">
                    <button type="button" onclick="closeAddMaterialModal()" style="padding: 8px 16px; background: #6b7280; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">İptal</button>
                    <button type="button" onclick="addNewMaterial()" style="padding: 8px 16px; background: #3b82f6; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">Ekle</button>
                </div>
            `;
            
            addMaterialModal.appendChild(modalContent);
            document.body.appendChild(addMaterialModal);
            
            // Autocomplete
            const searchInput = document.getElementById('newMaterialSearch');
            const autocomplete = document.getElementById('newMaterialAutocomplete');
            let filteredMaterials = [];
            
            searchInput.addEventListener('input', function() {
                const searchText = this.value.toLowerCase().trim();
                if (searchText.length === 0) {
                    autocomplete.style.display = 'none';
                    return;
                }
                
                filteredMaterials = materialsList.filter(m => 
                    m.toLowerCase().startsWith(searchText)
                );
                
                if (filteredMaterials.length > 0) {
                    autocomplete.innerHTML = filteredMaterials.map(m => {
                        const materialInfo = materialCodeMap[m] || {};
                        const uom = materialInfo.uom || '';
                        const baseQty = materialInfo.baseQuantity;
                        let uomInfo = uom;
                        // BaseQuantity varsa ve 1'den büyükse göster
                        if (baseQty !== null && baseQty !== undefined && baseQty > 0) {
                            if (baseQty > 1) {
                                uomInfo = `${baseQty} ${uom}`;
                            } else {
                                uomInfo = uom;
                            }
                        }
                        return `<div onclick="selectNewMaterial('${m.replace(/'/g, "\\'")}')" style="padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f3f4f6; display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 500;">${m}</span>
                            ${uomInfo ? `<span style="color: #6b7280; font-size: 12px; margin-left: auto;">${uomInfo}</span>` : ''}
                        </div>`;
                    }).join('');
                    autocomplete.style.display = 'block';
                } else {
                    autocomplete.style.display = 'none';
                }
            });
            
            // Modal dışına tıklanınca kapat
            addMaterialModal.addEventListener('click', function(e) {
                if (e.target === addMaterialModal) {
                    closeAddMaterialModal();
                }
            });
        }

        function selectNewMaterial(materialName) {
            document.getElementById('newMaterialSearch').value = materialName;
            document.getElementById('newMaterialAutocomplete').style.display = 'none';
        }

        function increaseNewMaterialQty() {
            const qtyInput = document.getElementById('newMaterialQty');
            qtyInput.focus();
            
            let cursorPos = savedCursorPositions[qtyInput];
            if (cursorPos === undefined || cursorPos === null) {
                cursorPos = qtyInput.selectionStart;
                if (cursorPos === null || cursorPos === undefined) {
                    cursorPos = qtyInput.value.length;
                }
            }
            
            const currentValue = parseFloat(qtyInput.value) || 0;
            const valueStr = qtyInput.value.toString();
            const dotIndex = valueStr.indexOf('.');
            
            let increment = 1;
            if (dotIndex !== -1) {
                if (cursorPos <= dotIndex) {
                    increment = 1;
                } else {
                    increment = 0.5;
                }
            }
            
            qtyInput.value = (currentValue + increment).toFixed(2);
            
            setTimeout(() => {
                const newCursorPos = Math.min(cursorPos, qtyInput.value.length);
                qtyInput.setSelectionRange(newCursorPos, newCursorPos);
                savedCursorPositions[qtyInput] = newCursorPos;
            }, 0);
        }

        function decreaseNewMaterialQty() {
            const qtyInput = document.getElementById('newMaterialQty');
            qtyInput.focus();
            
            let cursorPos = savedCursorPositions[qtyInput];
            if (cursorPos === undefined || cursorPos === null) {
                cursorPos = qtyInput.selectionStart;
                if (cursorPos === null || cursorPos === undefined) {
                    cursorPos = qtyInput.value.length;
                }
            }
            
            const currentValue = parseFloat(qtyInput.value) || 0;
            const valueStr = qtyInput.value.toString();
            const dotIndex = valueStr.indexOf('.');
            
            let decrement = 1;
            if (dotIndex !== -1) {
                if (cursorPos <= dotIndex) {
                    decrement = 1;
                } else {
                    decrement = 0.5;
                }
            }
            
            const newValue = currentValue - decrement;
            if (newValue >= 0) {
                qtyInput.value = newValue.toFixed(2);
            } else {
                qtyInput.value = '0.00';
            }
            
            setTimeout(() => {
                const newCursorPos = Math.min(cursorPos, qtyInput.value.length);
                qtyInput.setSelectionRange(newCursorPos, newCursorPos);
                savedCursorPositions[qtyInput] = newCursorPos;
            }, 0);
        }

        function closeAddMaterialModal() {
            if (addMaterialModal) {
                addMaterialModal.style.display = 'none';
                document.getElementById('newMaterialSearch').value = '';
                document.getElementById('newMaterialQty').value = '';
            }
        }

        function addNewMaterial() {
            const materialName = document.getElementById('newMaterialSearch').value.trim();
            const qty = parseFloat(document.getElementById('newMaterialQty').value);
            
            if (!materialName || !materialCodeMap[materialName]) {
                alert('Lütfen geçerli bir malzeme seçin.');
                return;
            }
            
            if (isNaN(qty) || qty <= 0) {
                alert('Miktar 0\'dan büyük olmalıdır.');
                return;
            }
            
            const materialInfo = materialCodeMap[materialName];
            
            // Duplicate kontrolü - aynı ItemCode zaten var mı?
            const existingRows = document.querySelectorAll('#materialsTableBody tr');
            for (let row of existingRows) {
                const existingItemCode = row.getAttribute('data-item-code');
                if (existingItemCode === materialInfo.code) {
                    alert('Bu malzeme zaten listede mevcut!');
                    return;
                }
            }
            
            const tbody = document.getElementById('materialsTableBody');
            const newRow = document.createElement('tr');
            newRow.setAttribute('data-item-code', materialInfo.code);
            
            materialCounter++;
            newRow.innerHTML = `
                <td style="text-align: center;">
                    <input type="checkbox" class="material-checkbox" onchange="updateDeleteButton()" style="cursor: pointer;">
                </td>
                <td class="sira-cell">${materialCounter}</td>
                <td class="malzeme-cell">${materialName}</td>
                <td class="miktar-cell">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <button type="button" class="btn-minus" onclick="decreaseQuantity(this)" style="background: #ef4444; color: white; border: none; width: 28px; height: 28px; border-radius: 4px; cursor: pointer; font-size: 16px; display: none;">-</button>
                        <span class="miktar-display">${qty.toFixed(2)}</span>
                        <input type="text" class="miktar-input" value="${qty.toFixed(2)}" onfocus="saveCursorPosition(this)" onkeyup="saveCursorPosition(this)" onclick="saveCursorPosition(this)" oninput="validateNumberInput(this)" style="display: none; width: 100px; padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px;">
                        <button type="button" class="btn-plus" onclick="increaseQuantity(this)" style="background: #10b981; color: white; border: none; width: 28px; height: 28px; border-radius: 4px; cursor: pointer; font-size: 16px; display: none;">+</button>
                    </div>
                </td>
                <td class="birim-cell">${materialInfo.uom || ''}</td>
                <td>
                    <button type="button" class="btn-edit" onclick="editMaterial(this)" style="background: #10b981; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; margin-right: 4px; font-size: 13px;">✏️ Düzenle</button>
                    <button type="button" class="btn-save" onclick="saveMaterial(this)" style="display: none; background: #3b82f6; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; margin-right: 4px; font-size: 13px;">💾 Kaydet</button>
                    <button type="button" class="btn-cancel" onclick="cancelEdit(this)" style="display: none; background: #6b7280; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; margin-right: 4px; font-size: 13px;">❌ İptal</button>
                    <button type="button" class="btn-delete" onclick="deleteMaterial(this)" style="background: #ef4444; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 13px;">🗑️ Sil</button>
                </td>
            `;
            
            tbody.appendChild(newRow);
            closeAddMaterialModal();
            updateSiraNumbers();
            updateRecipe();
        }
    </script>
</body>
</html>

