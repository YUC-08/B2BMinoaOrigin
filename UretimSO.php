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

//////////////////////////////////////////////////////// Kalem grupları (ItemGroups) çek
$selectValue = "Number,GroupName";
$orderByValue = "GroupName asc";
$itemGroupsQuery = "ItemGroups?\$select=" . urlencode($selectValue) . "&\$orderby=" . urlencode($orderByValue);
$itemGroupsData = $sap->get($itemGroupsQuery);

// Diziye dönüştür
$itemGroups = [];

// Hem ['response']['value'] hem de direkt ['value'] ihtimalini düşün
if (($itemGroupsData['status'] ?? 0) == 200) {
    if (isset($itemGroupsData['response']['value'])) {
        $itemGroups = $itemGroupsData['response']['value'];
    } elseif (isset($itemGroupsData['value'])) {
        $itemGroups = $itemGroupsData['value'];
    }
}
//////////////////////////////////////////////////////// Kalem grupları (ItemGroups) çek

//////////////////////////////////////////////////////// Ölçü birimleri (UnitOfMeasurements) çek
$uomSelectValue = "AbsEntry,Code,Name";
$uomQuery = "UnitOfMeasurements?\$select=" . urlencode($uomSelectValue);
$uomData = $sap->get($uomQuery);

// Diziye dönüştür
$unitOfMeasurements = [];

if (($uomData['status'] ?? 0) == 200) {
    if (isset($uomData['response']['value'])) {
        $unitOfMeasurements = $uomData['response']['value'];
    } elseif (isset($uomData['value'])) {
        $unitOfMeasurements = $uomData['value'];
    }
}
//////////////////////////////////////////////////////// Ölçü birimleri (UnitOfMeasurements) çek

//////////////////////////////////////////////////////// Yeni Reçete Ekleme (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_recipe') {
    header('Content-Type: application/json');
    
    $urunTanimi = trim($_POST['urunTanimi'] ?? '');
    $urunGrubu = trim($_POST['urunGrubu'] ?? ''); // GroupName
    $birim = trim($_POST['birim'] ?? ''); // UoMGroupEntry (şimdilik 1)
    $materials = json_decode($_POST['materials'] ?? '[]', true);
    
    if (empty($urunTanimi)) {
        echo json_encode(['success' => false, 'message' => 'Ürün tanımı zorunludur.']);
        exit;
    }
    
    if (empty($urunGrubu)) {
        echo json_encode(['success' => false, 'message' => 'Ürün grubu seçilmelidir.']);
        exit;
    }
    
    if (empty($materials) || count($materials) === 0) {
        echo json_encode(['success' => false, 'message' => 'En az bir malzeme eklenmelidir.']);
        exit;
    }
    
    // GroupName'den ItemsGroupCode'a çevir
    $itemsGroupCode = null;
    foreach ($itemGroups as $group) {
        if (isset($group['GroupName']) && $group['GroupName'] === $urunGrubu) {
            $itemsGroupCode = $group['Number'] ?? null;
            break;
        }
    }
    
    if ($itemsGroupCode === null) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz ürün grubu.']);
        exit;
    }
    
    // UoMGroupEntry: şimdilik 1 (ADET)
    $uomGroupEntry = 1;
    if (!empty($birim)) {
        // Eğer birim seçildiyse, UnitOfMeasurements'den AbsEntry'yi bul
        foreach ($unitOfMeasurements as $uom) {
            $uomCode = $uom['Code'] ?? $uom['Name'] ?? '';
            if ($uomCode === $birim) {
                // UoMGroupEntry için birim grubu bulunmalı, şimdilik 1 kullanıyoruz
                break;
            }
        }
    }
    
    try {
        // U_AS_OWNR değerini al (kullanıcının sektörü)
        $uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
        
        // U_AS_OWNR mapping: Restaurant (YE) → KT, Cafe (CF) → RT
        $uAsOwnrForItem = '';
        if ($uAsOwnr === 'YE') {
            $uAsOwnrForItem = 'KT'; // Restaurant → KT
        } elseif ($uAsOwnr === 'CF') {
            $uAsOwnrForItem = 'RT'; // Cafe → RT
        } else {
            $uAsOwnrForItem = $uAsOwnr; // Diğer durumlar için aynen kullan
        }
        
        // ItemsGroupCode'a göre Seri (Series) Belirleme
        // SAP'den sorgulanan değerlere göre (2025-12-01):
        // - ItemsGroupCode 100 (Mamül): Series 77 (en yaygın)
        // - ItemsGroupCode 101 (Yarımamül): Series 3
        // - ItemsGroupCode 104 (Hammadde): Series 77
        // NOT: Series zorunludur, SAP otomatik seçmez!
        $series = 77; // Varsayılan (Mamül için en yaygın)
        if ($itemsGroupCode == 100) { 
            // Eğer grup "Mamül" (100) ise
            $series = 77; // En yaygın kullanılan Series (SAP'den doğrulandı)
        } elseif ($itemsGroupCode == 101) { 
            // Eğer grup "Yarımamül" (101) ise
            $series = 3; // Yarımamül serisi (SAP'den doğrulandı: KABAK PÜRE örneği)
        } elseif ($itemsGroupCode == 104) { 
            // Eğer grup "Hammadde" (104) ise
            $series = 77; // Hammadde serisi
        }
        
        // UoMGroupEntry: Şimdilik 1 (ADET) kullanıyoruz
        $finalUoMGroupEntry = $uomGroupEntry; // Varsayılan 1
        
        // 1. Adım: Items POST (Mamül/Yarımamül stok kartı oluştur)
        $itemPayload = [
            'Series' => $series, // ItemsGroupCode'a göre dinamik seri numarası (ZORUNLU!)
            'ItemName' => $urunTanimi,
            'ItemType' => 'itItems',
            'ItemsGroupCode' => intval($itemsGroupCode),
            'UoMGroupEntry' => $finalUoMGroupEntry,
            'U_AS_OWNR' => $uAsOwnrForItem, // Sektör bilgisi ekleniyor 
            'PurchaseItem' => 'tNO',
            'SalesItem' => 'tYES',
            'InventoryItem' => 'tNO',
            
        ];
        
        $itemResult = $sap->post('Items', $itemPayload);
        
        if (($itemResult['status'] ?? 0) != 200 && ($itemResult['status'] ?? 0) != 201) {
            $errorMsg = 'Stok kartı oluşturulamadı.';
            if (isset($itemResult['response']['error']['message']['value'])) {
                $errorMsg = $itemResult['response']['error']['message']['value'];
            } elseif (isset($itemResult['response']['error']['message'])) {
                $errorMsg = is_array($itemResult['response']['error']['message']) 
                    ? ($itemResult['response']['error']['message']['value'] ?? json_encode($itemResult['response']['error']['message']))
                    : $itemResult['response']['error']['message'];
            }
            echo json_encode(['success' => false, 'message' => 'Stok kartı hatası: ' . $errorMsg]);
            exit;
        }
        
        $itemCode = $itemResult['response']['ItemCode'] ?? '';
        
        if (empty($itemCode)) {
            echo json_encode(['success' => false, 'message' => 'ItemCode alınamadı.']);
            exit;
        }
        
        // 2. Adım: ProductTrees POST (Reçete oluştur)
        // Malzeme ItemCode'larını bulmak için $materials array'ini kullan
        $materialCodeMap = [];
        foreach ($materials as $mat) {
            $itemName = $mat['ItemName'] ?? $mat['ItemCode'] ?? '';
            if (!empty($itemName)) {
                $materialCodeMap[$itemName] = $mat['ItemCode'] ?? '';
            }
        }
        
        $productTreeLines = [];
        foreach ($materials as $material) {
            $materialName = $material['malzeme'] ?? '';
            $materialItemCode = $material['itemCode'] ?? ''; // JavaScript'ten gelen itemCode
            $quantity = floatval($material['miktar'] ?? 0);
            
            if (empty($materialItemCode) && !empty($materialName)) {
                // Eğer itemCode yoksa, PHP'deki $materials array'inden bul
                $materialItemCode = $materialCodeMap[$materialName] ?? '';
            }
            
            if (empty($materialItemCode) || $quantity <= 0) {
                continue;
            }
            
            $productTreeLines[] = [
                'ItemCode' => $materialItemCode,
                'Quantity' => $quantity
            ];
        }
        
        if (empty($productTreeLines)) {
            echo json_encode(['success' => false, 'message' => 'Geçerli malzeme bulunamadı.']);
            exit;
        }
        
        $productTreePayload = [
            'TreeCode' => $itemCode,
            'TreeType' => 'iAssemblyTree',
            'ProductTreeLines' => $productTreeLines
        ];
        
        $productTreeResult = $sap->post('ProductTrees', $productTreePayload);
        
        if (($productTreeResult['status'] ?? 0) != 200 && ($productTreeResult['status'] ?? 0) != 201) {
            $errorMsg = 'Reçete oluşturulamadı.';
            if (isset($productTreeResult['response']['error']['message']['value'])) {
                $errorMsg = $productTreeResult['response']['error']['message']['value'];
            } elseif (isset($productTreeResult['response']['error']['message'])) {
                $errorMsg = is_array($productTreeResult['response']['error']['message']) 
                    ? ($productTreeResult['response']['error']['message']['value'] ?? json_encode($productTreeResult['response']['error']['message']))
                    : $productTreeResult['response']['error']['message'];
            }
            echo json_encode(['success' => false, 'message' => 'Reçete hatası: ' . $errorMsg]);
            exit;
        }
        
        // Başarılı oluşturma
        echo json_encode([
            'success' => true,
            'message' => 'Reçete başarıyla oluşturuldu.',
            'itemCode' => $itemCode
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Bir hata oluştu: ' . $e->getMessage()]);
        exit;
    }
}

//////////////////////////////////////////////////////// Yeni Stok Kartı Ekleme (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_stock_item') {
    header('Content-Type: application/json');
    
    $productName = trim($_POST['productName'] ?? '');
    $productUnit = trim($_POST['productUnit'] ?? ''); // Ana birim AbsEntry
    $productIkincilBirim = trim($_POST['productIkincilBirim'] ?? ''); // İkincil birim AbsEntry (opsiyonel)
    $productIkincilBirimMiktar = floatval($_POST['productIkincilBirimMiktar'] ?? 0); // İkincil birim miktarı
    $productUcuncuBirim = trim($_POST['productUcuncuBirim'] ?? ''); // Üçüncül birim AbsEntry (opsiyonel)
    $productUcuncuBirimMiktar = floatval($_POST['productUcuncuBirimMiktar'] ?? 0); // Üçüncül birim miktarı
    $productDescription = trim($_POST['productDescription'] ?? '');
    
    if (empty($productName) || empty($productUnit)) {
        echo json_encode(['success' => false, 'message' => 'Ürün adı ve ana birim zorunludur.']);
        exit;
    }
    
    if (!empty($productIkincilBirim) && $productIkincilBirimMiktar <= 0) {
        echo json_encode(['success' => false, 'message' => 'İkincil birim seçildiğinde miktar 0\'dan büyük olmalıdır.']);
        exit;
    }
    
    if (!empty($productUcuncuBirim) && $productUcuncuBirimMiktar <= 0) {
        echo json_encode(['success' => false, 'message' => 'Üçüncül birim seçildiğinde miktar 0\'dan büyük olmalıdır.']);
        exit;
    }
    
    try {
        // 1. Adım: UnitOfMeasurementGroups oluştur
        // Code ve Name için daha kısa ve geçerli bir değer oluştur
        $uomGroupCode = 'UOM_' . time() . '_' . rand(1000, 9999);
        $uomGroupName = $uomGroupCode;
        
        // UoMGroupDefinitionCollection'ı oluştur
        $uomDefinitions = [];
        
        // İkincil birim varsa ekle
        if (!empty($productIkincilBirim) && $productIkincilBirimMiktar > 0) {
            $uomDefinitions[] = [
                'AlternateQuantity' => 1,
                'AlternateUoM' => intval($productIkincilBirim),
                'BaseQuantity' => floatval($productIkincilBirimMiktar)
            ];
        }
        
        // Üçüncül birim varsa ekle
        if (!empty($productUcuncuBirim) && $productUcuncuBirimMiktar > 0) {
            $uomDefinitions[] = [
                'AlternateQuantity' => 1,
                'AlternateUoM' => intval($productUcuncuBirim),
                'BaseQuantity' => floatval($productUcuncuBirimMiktar)
            ];
        }
        
        $uomGroupPayload = [
            'BaseUoM' => intval($productUnit),
            'Code' => $uomGroupCode,
            'Name' => $uomGroupName
        ];
        
        // Sadece tanımlar varsa collection'ı ekle
        if (!empty($uomDefinitions)) {
            $uomGroupPayload['UoMGroupDefinitionCollection'] = $uomDefinitions;
        }
        
        $uomGroupResult = $sap->post('UnitOfMeasurementGroups', $uomGroupPayload);
        
        if (($uomGroupResult['status'] ?? 0) != 200 && ($uomGroupResult['status'] ?? 0) != 201) {
            // Hata mesajını farklı yerlerden dene
            $errorMsg = 'Ölçü birim grubu oluşturulamadı.';
            if (isset($uomGroupResult['response']['error']['message']['value'])) {
                $errorMsg = $uomGroupResult['response']['error']['message']['value'];
            } elseif (isset($uomGroupResult['response']['error']['message'])) {
                $errorMsg = is_array($uomGroupResult['response']['error']['message']) 
                    ? ($uomGroupResult['response']['error']['message']['value'] ?? json_encode($uomGroupResult['response']['error']['message']))
                    : $uomGroupResult['response']['error']['message'];
            } elseif (isset($uomGroupResult['response']['error']['code'])) {
                $errorMsg = 'Hata Kodu: ' . $uomGroupResult['response']['error']['code'];
            } elseif (isset($uomGroupResult['response']['raw'])) {
                $errorMsg = 'SAP Response: ' . substr($uomGroupResult['response']['raw'], 0, 200);
            }
            
            echo json_encode([
                'success' => false, 
                'message' => 'Ölçü birim grubu hatası: ' . $errorMsg
            ]);
            exit;
        }
        
        $uomGroupEntry = $uomGroupResult['response']['AbsEntry'] ?? null;
        
        if (empty($uomGroupEntry)) {
            echo json_encode(['success' => false, 'message' => 'Ölçü birim grubu AbsEntry değeri alınamadı.']);
            exit;
        }
        
        // 2. Adım: Items oluştur
        // U_AS_OWNR mapping: Restaurant (YE) → KT, Cafe (CF) → RT
        $uAsOwnrForItem = '';
        if ($uAsOwnr === 'YE') {
            $uAsOwnrForItem = 'KT'; // Restaurant → KT
        } elseif ($uAsOwnr === 'CF') {
            $uAsOwnrForItem = 'RT'; // Cafe → RT
        } else {
            $uAsOwnrForItem = $uAsOwnr; // Diğer durumlar için aynen kullan
        }
        
        $itemPayload = [
            'Series' => 77, // Hammadde için sabit 
            'ItemName' => $productName,
            'ItemType' => 'itItems',
            'ItemsGroupCode' => 104, // Hammadde için sabit
            'UoMGroupEntry' => intval($uomGroupEntry),
            'U_AS_OWNR' => $uAsOwnrForItem // U_AS_OWNR ekleniyor

            
            
        ];
        
        $itemResult = $sap->post('Items', $itemPayload);
        
        if (($itemResult['status'] ?? 0) != 200 && ($itemResult['status'] ?? 0) != 201) {
            // Hata mesajını farklı yerlerden dene
            $errorMsg = 'Stok kartı oluşturulamadı.';
            if (isset($itemResult['response']['error']['message']['value'])) {
                $errorMsg = $itemResult['response']['error']['message']['value'];
            } elseif (isset($itemResult['response']['error']['message'])) {
                $errorMsg = is_array($itemResult['response']['error']['message']) 
                    ? ($itemResult['response']['error']['message']['value'] ?? json_encode($itemResult['response']['error']['message']))
                    : $itemResult['response']['error']['message'];
            } elseif (isset($itemResult['response']['error']['code'])) {
                $errorMsg = 'Hata Kodu: ' . $itemResult['response']['error']['code'];
            } elseif (isset($itemResult['response']['raw'])) {
                $errorMsg = 'SAP Response: ' . substr($itemResult['response']['raw'], 0, 200);
            }
            
            echo json_encode([
                'success' => false, 
                'message' => 'Stok kartı hatası: ' . $errorMsg
            ]);
            exit;
        }
        
        $itemCode = $itemResult['response']['ItemCode'] ?? '';
        
        echo json_encode([
            'success' => true,
            'message' => 'Stok kartı başarıyla oluşturuldu.',
            'itemCode' => $itemCode,
            'uomGroupEntry' => $uomGroupEntry
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Bir hata oluştu: ' . $e->getMessage()]);
        exit;
    }
}
//////////////////////////////////////////////////////// Yeni Stok Kartı Ekleme (AJAX)

//////////////////////////////////////////////////////// Reçete alt ürünleri (Items) çek - Malzeme arama için
// U_AS_OWNR mapping: Restaurant (YE) → KT, Cafe (CF) → RT
$uAsOwnrForFilter = '';
if ($uAsOwnr === 'YE') {
    $uAsOwnrForFilter = 'KT'; // Restaurant → KT
} elseif ($uAsOwnr === 'CF') {
    $uAsOwnrForFilter = 'RT'; // Cafe → RT
} else {
    $uAsOwnrForFilter = $uAsOwnr; // Diğer durumlar için aynen kullan
}

$materialsSelectValue = "ItemCode,ItemName,ItemsGroupCode,InventoryUOM,UoMGroupEntry";
$materialsFilterValue = "(U_AS_OWNR eq '{$uAsOwnrForFilter}') and (ItemsGroupCode eq 100 or ItemsGroupCode eq 101 or ItemsGroupCode eq 104)";
// Boşlukları elle %20 ile değiştir
$materialsFilterEncoded = str_replace(' ', '%20', $materialsFilterValue);
$materialsQuery = "Items?\$select=" . urlencode($materialsSelectValue) . "&\$filter=" . $materialsFilterEncoded;
$materialsData = $sap->get($materialsQuery);

// Diziye dönüştür
$materials = [];

if (($materialsData['status'] ?? 0) == 200) {
    if (isset($materialsData['response']['value'])) {
        $materials = $materialsData['response']['value'];
    } elseif (isset($materialsData['value'])) {
        $materials = $materialsData['value'];
    }
    
    // Her item için UnitOfMeasurementGroups bilgisini çek (BaseQuantity için)
    foreach ($materials as &$item) {
        $uomGroupEntry = $item['UoMGroupEntry'] ?? null;
        if ($uomGroupEntry !== null && $uomGroupEntry > 0) {
            // UnitOfMeasurementGroups query'si - direkt collection path kullanarak
            $uomGroupQuery = "UnitOfMeasurementGroups({$uomGroupEntry})/UoMGroupDefinitionCollection";
            $uomGroupData = $sap->get($uomGroupQuery);
            
            if (($uomGroupData['status'] ?? 0) == 200) {
                // Response'dan UoMGroupDefinitionCollection array'ini çıkar
                $collection = [];
                $response = $uomGroupData['response'] ?? $uomGroupData;
                
                // Response içinde UoMGroupDefinitionCollection key'i var mı kontrol et
                if (isset($response['UoMGroupDefinitionCollection']) && is_array($response['UoMGroupDefinitionCollection'])) {
                    $collection = $response['UoMGroupDefinitionCollection'];
                } elseif (isset($response['value']) && is_array($response['value'])) {
                    $collection = $response['value'];
                } elseif (is_array($response)) {
                    // Eğer response direkt array ise
                    $collection = $response;
                }
                
                if (!empty($collection) && is_array($collection)) {
                    $item['_UoMGroupDefinitionCollection'] = $collection;
                }
            }
        }
    }
    unset($item); // Reference'ı temizle
}
//////////////////////////////////////////////////////// Reçete alt ürünleri (Items) çek - Malzeme arama için

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Reçete Oluştur - MINOA</title>
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
    margin-bottom: 0;
}

.card-body {
    padding: 24px;
}

/* Form Styles */
.form-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

/* Single select container'ı form-group içinde düzgün görünsün */
.form-group .single-select-container {
    width: 100%;
}

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
    text-align: left;
}

.form-group input[type="text"]::placeholder,
.form-group input[type="number"]::placeholder {
    text-align: left;
}

.form-group input[type="text"]:hover,
.form-group input[type="number"]:hover {
    border-color: #3b82f6;
}

.form-group input[type="text"]:focus,
.form-group input[type="number"]:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group input[type="text"][readonly] {
    background: #f3f4f6;
    cursor: not-allowed;
}

.form-group select {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: white;
    cursor: pointer;
    min-height: 42px;
}

.form-group select:hover {
    border-color: #3b82f6;
}

.form-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-select {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: white;
    cursor: pointer;
    min-height: 42px;
}

.form-select:hover {
    border-color: #3b82f6;
}

.form-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-select:disabled {
    background-color: #f3f4f6;
    color: #6b7280;
    cursor: not-allowed;
    opacity: 0.7;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-image: none;
}

/* Single Select Dropdown (Arama yapılabilir tek seçimli) - AnaDepo ile aynı */
.single-select-container {
    position: relative;
    width: 100%;
}

.single-select-input {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    min-height: 42px;
    transition: all 0.2s ease;
    width: 100%;
    box-sizing: border-box;
}

.single-select-input:hover {
    border-color: #3b82f6;
}

.single-select-input.active {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.single-select-input input[type="text"] {
    border: none !important;
    outline: none;
    flex: 1;
    background: transparent !important;
    cursor: pointer;
    font-size: 14px;
    color: #2c3e50;
    padding: 0 !important;
    margin: 0;
    width: 100%;
    min-height: auto;
    box-sizing: border-box;
    height: auto;
    text-align: left;
    align-self: stretch;
}

.single-select-input input[type="text"]::placeholder {
    text-align: left;
}

.single-select-input input[type="hidden"] {
    display: none;
}

.dropdown-arrow {
    transition: transform 0.2s;
    color: #6b7280;
    font-size: 12px;
    margin-left: 8px;
    flex-shrink: 0;
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

.form-group textarea {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: white;
    font-family: inherit;
    resize: vertical;
    width: 100%;
}

.form-group textarea:hover {
    border-color: #3b82f6;
}

.form-group textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Malzeme Ekleme Alanı */
.material-add-section {
    padding: 20px;
    background: #f8fafc;
    border-radius: 8px;
    margin-bottom: 24px;
}

.material-add-form {
    display: flex;
    gap: 12px;
    align-items: center;
}

.search-box {
    display: flex;
    gap: 8px;
    align-items: center;
    flex: 1;
    position: relative;
}

.search-input {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    min-width: 220px;
    flex: 1;
    transition: border-color 0.2s;
}

.search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Autocomplete Dropdown */
.material-autocomplete {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #3b82f6;
    border-top: none;
    border-radius: 0 0 8px 8px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    margin-top: -2px;
}

.material-autocomplete.show {
    display: block;
}

.material-autocomplete-item {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    transition: background 0.15s ease;
}

.material-autocomplete-item:hover,
.material-autocomplete-item.selected {
    background: #f0f9ff;
    color: #1e40af;
}

.material-autocomplete-item:last-child {
    border-bottom: none;
}

.material-autocomplete-empty {
    padding: 10px 14px;
    color: #6b7280;
    font-style: italic;
    text-align: center;
}

/* Buton Styles */
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
    white-space: nowrap;
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

.btn-danger {
    background: #ef4444;
    color: white;
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 500;
}

.btn-danger:hover {
    background: #dc2626;
}

/* Quantity Controls */
.quantity-controls {
    display: flex;
    gap: 6px;
    align-items: center;
    justify-content: center;
    width: 100%;
}

.qty-btn {
    width: 32px;
    height: 32px;
    border: 2px solid #e5e7eb;
    background: white;
    border-radius: 6px;
    cursor: pointer;
    font-size: 1.1rem;
    font-weight: 600;
    color: #374151;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.qty-btn:hover {
    border-color: #3b82f6;
    color: #3b82f6;
}

.qty-input {
    width: 100px;
    padding: 6px 10px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    text-align: center;
    font-size: 0.9rem;
    transition: border-color 0.2s;
}

.qty-input:focus {
    outline: none;
    border-color: #3b82f6;
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

tbody td input[type="number"],
tbody td select {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    width: 100%;
    max-width: 150px;
    transition: all 0.2s ease;
}

tbody td input[type="number"]:focus,
tbody td select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.empty-state {
    text-align: center;
    padding: 40px;
    color: #6b7280;
    font-style: italic;
}

/* Modal Styles */
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 10000;
    align-items: center;
    justify-content: center;
}

.modal-overlay.show {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
    width: 90%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    padding: 24px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
}

.modal-header h3 {
    color: #1e40af;
    font-size: 1.5rem;
    font-weight: 600;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    color: #6b7280;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
    transition: all 0.2s ease;
}

.modal-close:hover {
    background: #e5e7eb;
    color: #1f2937;
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    padding: 20px 24px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    background: #f8fafc;
}

/* Responsive */
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

    .material-add-form {
        flex-direction: column;
        align-items: stretch;
    }

}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2>Yeni Reçete Oluştur</h2>
            <div style="display: flex; gap: 12px; align-items: center;">
                <button class="btn btn-secondary" onclick="window.location.href='Uretim.php'">← Geri Dön</button>
                <button type="button" class="btn btn-primary" onclick="handleSave()">Kaydet</button>
            </div>
        </div>

        <div class="content-wrapper">
            <form id="recipeForm" onsubmit="handleSubmit(event)">
                <!-- Ürün / Reçete Bilgileri -->
                <div class="card">
                    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; gap: 12px;">
                        <h3>Ürün / Reçete Bilgileri</h3>
                        <button type="button" class="btn btn-primary" onclick="openAddProductModal()" style="font-size: 0.80rem; padding: 6px 14px; white-space: nowrap;">
                            + Yeni kalem (hammadde) ekle
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <input type="text" id="urunNo" name="urunNo" placeholder="Ürün Numarası" readonly style="background: #f3f4f6; cursor: not-allowed;">
                            </div>
                            <div class="form-group">
                                <input type="text" id="urunTanimi" name="urunTanimi" placeholder="Ürün Tanımı (Örn: Çorba)">
                            </div>
                            <div class="form-group">
                                <div class="single-select-container">
                                    <div class="single-select-input" onclick="toggleSingleSelect('urunGrubu')">
                                        <input type="text" id="urunGrubuInput" value="" placeholder="Ürün Grubu" readonly oninput="filterSingleSelect('urunGrubu', this.value)" onclick="event.stopPropagation(); toggleSingleSelect('urunGrubu');">
                                        <input type="hidden" id="urunGrubu" name="urunGrubu" value="">
                                        <span class="dropdown-arrow">▼</span>
                                    </div>
                                    <div class="single-select-dropdown" id="urunGrubuDropdown">
                                        <?php if (!empty($itemGroups)): ?>
                                            <?php foreach ($itemGroups as $group): ?>
                                                <?php if (isset($group['GroupName'])): ?>
                                                <div class="single-select-option" data-value="<?= htmlspecialchars($group['GroupName']) ?>" onclick="selectSingleOption('urunGrubu', '<?= htmlspecialchars($group['GroupName']) ?>', '<?= htmlspecialchars($group['GroupName']) ?>')">
                                                    <?= htmlspecialchars($group['GroupName']) ?>
                                                </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="single-select-option">Veri yüklenemedi</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="single-select-container">
                                    <div class="single-select-input" onclick="toggleSingleSelect('birim')">
                                        <input type="text" id="birimInput" value="" placeholder="Ürün Birimi (Satış/Stok Birimi)" readonly oninput="filterSingleSelect('birim', this.value)" onclick="event.stopPropagation(); toggleSingleSelect('birim');">
                                        <input type="hidden" id="birim" name="birim" value="">
                                        <span class="dropdown-arrow">▼</span>
                                    </div>
                                    <div class="single-select-dropdown" id="birimDropdown">
                                        <?php if (!empty($unitOfMeasurements)): ?>
                                            <?php foreach ($unitOfMeasurements as $uom): ?>
                                                <?php if (isset($uom['Code']) || isset($uom['Name'])): ?>
                                                <?php 
                                                    $uomCode = $uom['Code'] ?? $uom['Name'] ?? '';
                                                    $uomName = $uom['Name'] ?? $uom['Code'] ?? '';
                                                ?>
                                                <div class="single-select-option" data-value="<?= htmlspecialchars($uomCode) ?>" onclick="selectSingleOption('birim', '<?= htmlspecialchars($uomCode) ?>', '<?= htmlspecialchars($uomName) ?>')">
                                                    <?= htmlspecialchars($uomName) ?>
                                                </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="single-select-option">Veri yüklenemedi</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Malzeme Ekleme Alanı -->
                <div class="card">
                    <div class="card-body">
                        <div class="material-add-section">
                            <div class="material-add-form">
                                <div class="search-box">
                                    <input type="text" id="materialSearch" class="search-input" placeholder="Malzeme kodu / adı yazın..." autocomplete="off" oninput="filterMaterials(this.value)" onkeydown="handleMaterialKeydown(event)" onfocus="showMaterialSuggestions()">
                                    <div id="materialAutocomplete" class="material-autocomplete"></div>
                                </div>
                                <select id="materialUnit" class="form-select" style="min-width: 150px;" disabled>
                                    <option value="">Birim Seçiniz</option>
                                    <?php if (!empty($unitOfMeasurements)): ?>
                                        <?php foreach ($unitOfMeasurements as $uom): ?>
                                            <?php if (isset($uom['Code']) || isset($uom['Name'])): ?>
                                            <option value="<?= htmlspecialchars($uom['Code'] ?? $uom['Name']) ?>"><?= htmlspecialchars($uom['Name'] ?? $uom['Code']) ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="">Veri yüklenemedi</option>
                                    <?php endif; ?>
                                </select>
                                <input type="hidden" id="materialUnitHidden" name="materialUnitHidden" value="">
                                <button type="button" class="btn btn-primary" onclick="addMaterial()">Ekle</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Malzemeler Tablosu (Sepet) -->
                <div class="card">
                    <div class="card-body">
                        <div style="margin-bottom: 8px;">
                            <h3 style="color: #1e40af; font-size: 1.25rem; font-weight: 600; margin: 0;">Malzemeler (Reçete İçeriği)</h3>
                        </div>
                        <p style="color: #6b7280; font-size: 13px; margin-bottom: 20px;">Reçetede kullanılacak malzemeleri ekleyin. Her malzeme için miktar ve birim belirtin.</p>
                        <div class="table-container">
                            <table id="materialsTable">
                                <thead>
                                    <tr>
                                        <th>Sıra</th>
                                        <th>Malzeme</th>
                                        <th>Miktar</th>
                                        <th>Birim</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody id="materialsTableBody">
                                    <tr class="empty-state-row">
                                        <td colspan="5" class="empty-state">Lütfen veri ekleyiniz</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                    <button class="btn btn-secondary" onclick="window.location.href='Uretim.php'">← Geri Dön</button>
                    <button type="submit" class="btn btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Product Modal -->
    <div id="addProductModal" class="modal-overlay" onclick="closeModalOnOverlay(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3>Yeni Ürün/Malzeme Ekle</h3>
                <button type="button" class="modal-close" onclick="closeAddProductModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addProductForm" onsubmit="handleAddProduct(event)">
                    <div class="form-group">
                        <label for="newProductName">Ürün/Malzeme Adı *</label>
                        <input type="text" id="newProductName" name="newProductName" placeholder="Örn: Domates" required>
                    </div>
                    <div class="form-group">
                        <label for="newProductUnit">Ana Birim *</label>
                        <select id="newProductUnit" name="newProductUnit" required>
                            <option value="">Seçiniz</option>
                            <?php if (!empty($unitOfMeasurements)): ?>
                                <?php foreach ($unitOfMeasurements as $uom): ?>
                                    <?php if (isset($uom['AbsEntry']) && isset($uom['Code'])): ?>
                                    <option value="<?= htmlspecialchars($uom['AbsEntry']) ?>" data-code="<?= htmlspecialchars($uom['Code']) ?>" data-name="<?= htmlspecialchars($uom['Name'] ?? $uom['Code']) ?>"><?= htmlspecialchars($uom['Name'] ?? $uom['Code']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="">Veri yüklenemedi</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="newProductIkincilBirim">İkincil Birim (Opsiyonel)</label>
                        <select id="newProductIkincilBirim" name="newProductIkincilBirim">
                            <option value="">Seçiniz</option>
                            <?php if (!empty($unitOfMeasurements)): ?>
                                <?php foreach ($unitOfMeasurements as $uom): ?>
                                    <?php if (isset($uom['AbsEntry']) && isset($uom['Code'])): ?>
                                    <option value="<?= htmlspecialchars($uom['AbsEntry']) ?>" data-code="<?= htmlspecialchars($uom['Code']) ?>" data-name="<?= htmlspecialchars($uom['Name'] ?? $uom['Code']) ?>"><?= htmlspecialchars($uom['Name'] ?? $uom['Code']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="">Veri yüklenemedi</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group" id="ikincilBirimMiktarGroup" style="display: none;">
                        <label id="ikincilBirimMiktarLabel" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span>1</span>
                            <span id="ikincilBirimAdi" style="font-weight: 600;"></span>
                            <span>=</span>
                            <input type="number" id="newProductIkincilBirimMiktar" name="newProductIkincilBirimMiktar" placeholder="0" min="0.01" step="0.01" style="width: 100px; padding: 8px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                            <span id="anaBirimAdi" style="font-weight: 600;"></span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="newProductUcuncuBirim">Üçüncül Birim (Opsiyonel)</label>
                        <select id="newProductUcuncuBirim" name="newProductUcuncuBirim">
                            <option value="">Seçiniz</option>
                            <?php if (!empty($unitOfMeasurements)): ?>
                                <?php foreach ($unitOfMeasurements as $uom): ?>
                                    <?php if (isset($uom['AbsEntry']) && isset($uom['Code'])): ?>
                                    <option value="<?= htmlspecialchars($uom['AbsEntry']) ?>" data-code="<?= htmlspecialchars($uom['Code']) ?>" data-name="<?= htmlspecialchars($uom['Name'] ?? $uom['Code']) ?>"><?= htmlspecialchars($uom['Name'] ?? $uom['Code']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="">Veri yüklenemedi</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group" id="ucuncuBirimMiktarGroup" style="display: none;">
                        <label id="ucuncuBirimMiktarLabel" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <span>1</span>
                            <span id="ucuncuBirimAdi" style="font-weight: 600;"></span>
                            <span>=</span>
                            <input type="number" id="newProductUcuncuBirimMiktar" name="newProductUcuncuBirimMiktar" placeholder="0" min="0.01" step="0.01" style="width: 100px; padding: 8px; border: 2px solid #e5e7eb; border-radius: 6px; font-size: 14px;">
                            <span id="anaBirimAdi2" style="font-weight: 600;"></span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="newProductDescription">Açıklama (Opsiyonel)</label>
                        <textarea id="newProductDescription" name="newProductDescription" rows="3" placeholder="Ürün hakkında notlar..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddProductModal()">İptal</button>
                <button type="button" class="btn btn-primary" onclick="handleAddProduct()">Ekle ve Sepete Ekle</button>
            </div>
        </div>
    </div>

    <script>
        let materialCounter = 0;
        
        // SAP'den gelen malzeme listesi (Reçete alt urunleri)
        const materialList = <?= json_encode(array_filter(array_map(function($item) {
            return $item['ItemName'] ?? $item['ItemCode'] ?? '';
        }, $materials))) ?>;
        
        // ItemCode ve ItemName mapping (malzeme seçildiğinde ItemCode'u da almak için)
        const materialCodeMap = {};
        <?php 
        foreach ($materials as $item) {
            $itemName = $item['ItemName'] ?? $item['ItemCode'] ?? '';
            if (!empty($itemName)) {
                // Birim dönüşüm bilgisini al
                $uomInfo = $item['InventoryUOM'] ?? '';
                
                // UnitOfMeasurementGroups bilgisini kontrol et
                if (isset($item['_UoMGroupDefinitionCollection']) && !empty($item['_UoMGroupDefinitionCollection'])) {
                    $definitions = $item['_UoMGroupDefinitionCollection'];
                    
                    // Eğer definitions içinde UoMGroupDefinitionCollection key'i varsa (nested yapı)
                    if (isset($definitions['UoMGroupDefinitionCollection']) && is_array($definitions['UoMGroupDefinitionCollection'])) {
                        $definitions = $definitions['UoMGroupDefinitionCollection'];
                    }
                    
                    // İlk tanımı al (genellikle ikincil birim dönüşümü)
                    if (is_array($definitions) && !empty($definitions) && isset($definitions[0])) {
                        $def = $definitions[0];
                        $baseQty = $def['BaseQuantity'] ?? 1;
                        
                        // Eğer BaseQuantity > 1 ise, dönüşüm bilgisini göster
                        // Örnek: 1 KOLİ = 10 LİTRE ise, "10 Litre" göster
                        if ($baseQty > 1) {
                            $inventoryUOM = $item['InventoryUOM'] ?? '';
                            $uomInfo = $baseQty . ' ' . $inventoryUOM;
                        }
                    }
                }
                
                $itemNameEscaped = addslashes($itemName);
                $itemData = [
                    'code' => $item['ItemCode'] ?? '',
                    'name' => $item['ItemName'] ?? '',
                    'groupCode' => $item['ItemsGroupCode'] ?? '',
                    'uom' => $item['InventoryUOM'] ?? '',
                    'uomInfo' => $uomInfo
                ];
                $itemDataJson = json_encode($itemData, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
                echo "materialCodeMap['" . $itemNameEscaped . "'] = " . $itemDataJson . ";\n";
            }
        }
        ?>
        
        // Malzeme birim bilgileri (autocomplete'te göstermek için)
        const materialUomMap = {};
        <?php 
        foreach ($materials as $item) {
            $itemName = $item['ItemName'] ?? $item['ItemCode'] ?? '';
            $uom = $item['InventoryUOM'] ?? '';
            if (!empty($itemName) && !empty($uom)) {
                $itemNameEscaped = addslashes($itemName);
                $uomEscaped = addslashes($uom);
                echo "materialUomMap['" . $itemNameEscaped . "'] = '" . $uomEscaped . "';\n";
            }
        }
        ?>
        
        let filteredMaterials = [];
        let selectedAutocompleteIndex = -1;

        // UnitOfMeasurements listesi
        const unitOfMeasurements = <?= json_encode(array_map(function($uom) {
            return [
                'code' => $uom['Code'] ?? $uom['Name'] ?? '',
                'name' => $uom['Name'] ?? $uom['Code'] ?? ''
            ];
        }, array_filter($unitOfMeasurements, function($uom) {
            return isset($uom['Code']) || isset($uom['Name']);
        })), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function addMaterial() {
            const searchInput = document.getElementById('materialSearch');
            const materialUnitSelect = document.getElementById('materialUnit');
            const materialUnitHidden = document.getElementById('materialUnitHidden');
            const materialName = searchInput.value.trim();
            // Disabled select'ten değil, hidden input'tan değeri al
            const selectedUnit = materialUnitHidden ? materialUnitHidden.value : materialUnitSelect.value;

            if (!materialName) {
                alert('Lütfen malzeme adı giriniz.');
                return;
            }

            if (!selectedUnit) {
                alert('Lütfen birim seçiniz.');
                return;
            }

            materialCounter++;
            const tbody = document.getElementById('materialsTableBody');
            const emptyRow = tbody.querySelector('.empty-state-row');
            
            if (emptyRow) {
                emptyRow.style.display = 'none';
            }

            // Birim dropdown'ı için HTML oluştur
            const unitOptions = unitOfMeasurements.map(uom => {
                const code = uom.code;
                const name = uom.name;
                const selected = code === selectedUnit ? 'selected' : '';
                return `<option value="${code}" ${selected}>${name}</option>`;
            }).join('');

            const newRow = document.createElement('tr');
            const rowId = 'material_' + materialCounter;
            newRow.innerHTML = `
                <td>${materialCounter}</td>
                <td>${materialName}</td>
                <td>
                    <div class="quantity-controls">
                        <button type="button" class="qty-btn" onclick="changeMaterialQuantity('${rowId}', -1)">−</button>
                        <input type="text" 
                               id="${rowId}" 
                               name="miktar[]" 
                               class="qty-input"
                               placeholder="0" 
                               value="0"
                               onchange="updateMaterialQuantity('${rowId}', this.value)"
                               oninput="validateMaterialInput(this)"
                               pattern="[0-9]+([,][0-9]{1,2})?"
                               inputmode="decimal">
                        <button type="button" class="qty-btn" onclick="changeMaterialQuantity('${rowId}', 1)">+</button>
                    </div>
                </td>
                <td>
                    <select name="birim_display[]" class="form-select" disabled style="appearance: none; -webkit-appearance: none; -moz-appearance: none; background-image: none;">
                        ${unitOptions}
                    </select>
                    <input type="hidden" name="birim[]" value="${selectedUnit}">
                </td>
                <td>
                    <button type="button" class="btn btn-danger" onclick="removeMaterial(this)">🗑️ Sil</button>
                </td>
            `;

            tbody.appendChild(newRow);
            searchInput.value = '';
            materialUnitSelect.value = '';
            if (materialUnitHidden) {
                materialUnitHidden.value = '';
            }
            searchInput.focus();
        }

        function removeMaterial(button) {
            const row = button.closest('tr');
            row.remove();
            
            // Sıra numaralarını yeniden düzenle
            const tbody = document.getElementById('materialsTableBody');
            const rows = tbody.querySelectorAll('tr:not(.empty-state-row)');
            
            if (rows.length === 0) {
                const emptyRow = tbody.querySelector('.empty-state-row');
                if (emptyRow) {
                    emptyRow.style.display = '';
                }
                materialCounter = 0;
            } else {
                rows.forEach((row, index) => {
                    row.querySelector('td:first-child').textContent = index + 1;
                });
                materialCounter = rows.length;
            }
        }

        function changeMaterialQuantity(rowId, delta) {
            const input = document.getElementById(rowId);
            if (!input) return;
            
            let value = parseFloat(input.value.replace(',', '.')) || 0;
            value += delta;
            if (value < 0) value = 0;
            // Tam sayı olarak göster (1, 2, 3...)
            let formattedValue = value % 1 === 0 ? value.toString() : value.toFixed(2);
            // Noktayı virgüle çevir
            input.value = formattedValue.replace('.', ',');
        }

        function validateMaterialInput(input) {
            // Sadece sayı, virgül ve nokta karakterlerine izin ver
            let value = input.value;
            let cleanedValue = value.replace(/[^0-9,.]/g, '');
            
            // Nokta girilmişse virgüle çevir
            cleanedValue = cleanedValue.replace('.', ',');
            
            // Birden fazla virgül varsa sadece ilkini tut
            const commaIndex = cleanedValue.indexOf(',');
            if (commaIndex !== -1) {
                cleanedValue = cleanedValue.substring(0, commaIndex + 1) + cleanedValue.substring(commaIndex + 1).replace(/,/g, '');
            }
            
            // Virgülden sonra maksimum 2 basamak
            if (commaIndex !== -1) {
                const parts = cleanedValue.split(',');
                if (parts[1] && parts[1].length > 2) {
                    cleanedValue = parts[0] + ',' + parts[1].substring(0, 2);
                }
            }
            
            // Temizlenmiş değeri input'a yaz
            input.value = cleanedValue;
        }

        function updateMaterialQuantity(rowId, value) {
            const input = document.getElementById(rowId);
            if (!input) return;
            
            let cleanedValue = value.toString().replace(/[^0-9,.]/g, '');
            
            // Nokta girilmişse virgüle çevir
            cleanedValue = cleanedValue.replace('.', ',');
            
            // Birden fazla virgül varsa sadece ilkini tut
            const commaIndex = cleanedValue.indexOf(',');
            if (commaIndex !== -1) {
                cleanedValue = cleanedValue.substring(0, commaIndex + 1) + cleanedValue.substring(commaIndex + 1).replace(/,/g, '');
            }
            
            // Virgülden sonra maksimum 2 basamak
            if (commaIndex !== -1) {
                const parts = cleanedValue.split(',');
                if (parts[1] && parts[1].length > 2) {
                    cleanedValue = parts[0] + ',' + parts[1].substring(0, 2);
                }
            }
            
            // Parse et ve negatif kontrolü
            let qty = parseFloat(cleanedValue.replace(',', '.')) || 0;
            if (qty < 0) qty = 0;
            
            // Değeri virgül ile göster
            let formattedValue = qty % 1 === 0 ? qty.toString() : qty.toFixed(2).replace('.', ',');
            input.value = formattedValue;
        }

        function handleSubmit(event) {
            event.preventDefault();
            
            // Form validasyonu
            const urunTanimi = document.getElementById('urunTanimi').value.trim();
            const urunGrubu = document.getElementById('urunGrubu').value.trim();
            
            if (!urunTanimi) {
                alert('Ürün tanımı zorunludur.');
                return;
            }
            
            if (!urunGrubu) {
                alert('Ürün grubu seçilmelidir.');
                return;
            }
            
            // Malzemeleri düzenle
            const materials = [];
            const rows = document.querySelectorAll('#materialsTableBody tr:not(.empty-state-row)');
            
            rows.forEach((row, index) => {
                const materialName = row.querySelector('td:nth-child(2)').textContent.trim();
                const materialInfo = materialCodeMap[materialName] || {};
                const materialCode = materialInfo.code || '';
                // Virgülü noktaya çevir ve parse et
                const miktarValue = row.querySelector('input[name="miktar[]"]').value.replace(',', '.');
                const miktar = parseFloat(miktarValue) || 0;
                
                if (materialName && materialCode) {
                    if (miktar <= 0) {
                        alert(`"${materialName}" için miktar 0'dan büyük olmalıdır.`);
                        return;
                    }
                    
                    materials.push({
                        malzeme: materialName,
                        itemCode: materialCode,
                        miktar: miktar
                    });
                }
            });
            
            if (materials.length === 0) {
                alert('En az bir malzeme eklenmelidir ve miktarları 0\'dan büyük olmalıdır.');
                return;
            }
            
            // Form verilerini AJAX ile gönder
            const formDataToSend = new FormData();
            formDataToSend.append('action', 'create_recipe');
            formDataToSend.append('urunTanimi', urunTanimi);
            formDataToSend.append('urunGrubu', urunGrubu);
            formDataToSend.append('birim', document.getElementById('birim').value.trim() || '');
            formDataToSend.append('materials', JSON.stringify(materials));
            
            // Loading göster
            const saveButton = document.querySelector('button[onclick="handleSave()"]');
            const originalText = saveButton.textContent;
            saveButton.disabled = true;
            saveButton.textContent = 'Kaydediliyor...';
            
            fetch('UretimSO.php', {
                method: 'POST',
                body: formDataToSend
            })
            .then(response => {
                return response.text().then(text => {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        return { success: false, message: 'Yanıt parse edilemedi: ' + text.substring(0, 200) };
                    }
                });
            })
            .then(result => {
                if (result.success) {
                    alert('Reçete başarıyla oluşturuldu! ItemCode: ' + (result.itemCode || 'N/A'));
                    window.location.href = 'Uretim.php?t=' + Date.now();
                } else {
                    alert('Hata: ' + (result.message || 'Bilinmeyen bir hata oluştu.'));
                    saveButton.disabled = false;
                    saveButton.textContent = originalText;
                }
            })
            .catch(error => {
                alert('Bir hata oluştu: ' + error.message);
                saveButton.disabled = false;
                saveButton.textContent = originalText;
            });
        }

        function handleSave() {
            document.getElementById('recipeForm').dispatchEvent(new Event('submit'));
        }


        // Malzeme filtreleme
        function filterMaterials(searchText) {
            const searchLower = searchText.toLowerCase().trim();
            const autocomplete = document.getElementById('materialAutocomplete');
            
            if (!autocomplete) {
                return;
            }
            
            if (!materialList || materialList.length === 0) {
                return;
            }
            
            if (searchLower.length === 0) {
                autocomplete.classList.remove('show');
                filteredMaterials = [];
                selectedAutocompleteIndex = -1;
                return;
            }
            
            // Başlangıçta eşleşenleri bul
            filteredMaterials = materialList.filter(material => 
                material.toLowerCase().startsWith(searchLower)
            );
            
            if (filteredMaterials.length > 0) {
                showMaterialSuggestions();
            } else {
                autocomplete.classList.remove('show');
            }
        }
        
        // Autocomplete önerilerini göster
        function showMaterialSuggestions() {
            const autocomplete = document.getElementById('materialAutocomplete');
            const searchInput = document.getElementById('materialSearch');
            const searchText = searchInput.value.toLowerCase().trim();
            
            if (searchText.length === 0) {
                autocomplete.classList.remove('show');
                return;
            }
            
            if (filteredMaterials.length === 0) {
                autocomplete.innerHTML = '<div class="material-autocomplete-empty">Malzeme bulunamadı</div>';
            } else {
                autocomplete.innerHTML = filteredMaterials.map((material, index) => {
                    // Birim bilgisini al
                    const materialInfo = materialCodeMap[material] || {};
                    const uomInfo = materialInfo.uomInfo || materialInfo.uom || '';
                    
                    return `<div class="material-autocomplete-item ${index === selectedAutocompleteIndex ? 'selected' : ''}" 
                          onclick="selectMaterial('${material.replace(/'/g, "\\'")}')" 
                          onmouseenter="selectedAutocompleteIndex = ${index}; updateAutocompleteSelection()"
                          style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 500;">${material}</span>
                        ${uomInfo ? `<span style="color: #6b7280; margin-left: auto; font-size: 13px;">${uomInfo}</span>` : ''}
                    </div>`;
                }).join('');
            }
            
            autocomplete.classList.add('show');
        }
        
        // Malzeme seç
        function selectMaterial(material) {
            document.getElementById('materialSearch').value = material;
            document.getElementById('materialAutocomplete').classList.remove('show');
            filteredMaterials = [];
            selectedAutocompleteIndex = -1;
            
            // Malzeme seçildiğinde birim bilgisini otomatik doldur
            if (materialCodeMap[material] && materialCodeMap[material].uom) {
                const materialUnitSelect = document.getElementById('materialUnit');
                const materialUnitHidden = document.getElementById('materialUnitHidden');
                const uomCode = materialCodeMap[material].uom;
                // UnitOfMeasurements listesinde eşleşen birimi bul
                const matchingUnit = unitOfMeasurements.find(uom => 
                    uom.code === uomCode || uom.name === uomCode
                );
                if (matchingUnit) {
                    materialUnitSelect.value = matchingUnit.code;
                    if (materialUnitHidden) {
                        materialUnitHidden.value = matchingUnit.code;
                    }
                }
            }
        }
        
        // Autocomplete seçimini güncelle
        function updateAutocompleteSelection() {
            const items = document.querySelectorAll('.material-autocomplete-item');
            items.forEach((item, index) => {
                if (index === selectedAutocompleteIndex) {
                    item.style.background = '#f0f9ff';
                } else {
                    item.style.background = '';
                }
            });
        }
        
        // Klavye ile autocomplete kontrolü
        function handleMaterialKeydown(event) {
            const autocomplete = document.getElementById('materialAutocomplete');
            
            if (!autocomplete.classList.contains('show') || filteredMaterials.length === 0) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    addMaterial();
                }
                return;
            }
            
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                selectedAutocompleteIndex = Math.min(selectedAutocompleteIndex + 1, filteredMaterials.length - 1);
                updateAutocompleteSelection();
                scrollToSelectedItem();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                selectedAutocompleteIndex = Math.max(selectedAutocompleteIndex - 1, -1);
                updateAutocompleteSelection();
                scrollToSelectedItem();
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (selectedAutocompleteIndex >= 0 && selectedAutocompleteIndex < filteredMaterials.length) {
                    selectMaterial(filteredMaterials[selectedAutocompleteIndex]);
                    addMaterial();
                } else {
                    addMaterial();
                }
            } else if (event.key === 'Escape') {
                autocomplete.classList.remove('show');
                selectedAutocompleteIndex = -1;
            }
        }
        
        // Seçili öğeye scroll
        function scrollToSelectedItem() {
            const items = document.querySelectorAll('.material-autocomplete-item');
            if (items[selectedAutocompleteIndex]) {
                items[selectedAutocompleteIndex].scrollIntoView({ block: 'nearest' });
            }
        }
        
        // Dışarı tıklanınca autocomplete'i kapat
        document.addEventListener('click', function(event) {
            const searchBox = document.querySelector('.search-box');
            if (searchBox && !searchBox.contains(event.target)) {
                document.getElementById('materialAutocomplete').classList.remove('show');
            }
        });

        // Modal Functions
        function openAddProductModal() {
            document.getElementById('addProductModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeAddProductModal() {
            document.getElementById('addProductModal').classList.remove('show');
            document.body.style.overflow = '';
            // Formu temizle
            document.getElementById('addProductForm').reset();
            // Birim miktar gruplarını gizle
            document.getElementById('ikincilBirimMiktarGroup').style.display = 'none';
            document.getElementById('ucuncuBirimMiktarGroup').style.display = 'none';
            document.getElementById('newProductIkincilBirimMiktar').required = false;
            document.getElementById('newProductUcuncuBirimMiktar').required = false;
        }

        function closeModalOnOverlay(event) {
            if (event.target.classList.contains('modal-overlay')) {
                closeAddProductModal();
            }
        }

        // Ana birim değiştiğinde ikincil ve üçüncül birim miktar etiketlerini güncelle
        document.addEventListener('DOMContentLoaded', function() {
            const anaBirimSelect = document.getElementById('newProductUnit');
            if (anaBirimSelect) {
                anaBirimSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const anaBirimAdi = selectedOption ? selectedOption.textContent : '';
                    const anaBirimAdiSpan = document.getElementById('anaBirimAdi');
                    const anaBirimAdi2Span = document.getElementById('anaBirimAdi2');
                    if (anaBirimAdiSpan) anaBirimAdiSpan.textContent = anaBirimAdi;
                    if (anaBirimAdi2Span) anaBirimAdi2Span.textContent = anaBirimAdi;
                });
            }

            // İkincil birim seçildiğinde miktar alanını göster
            const ikincilBirimSelect = document.getElementById('newProductIkincilBirim');
            if (ikincilBirimSelect) {
                ikincilBirimSelect.addEventListener('change', function() {
                    const ikincilBirimMiktarGroup = document.getElementById('ikincilBirimMiktarGroup');
                    if (this.value && this.value.trim() !== '') {
                        const selectedOption = this.options[this.selectedIndex];
                        const ikincilBirimAdi = selectedOption ? selectedOption.textContent : '';
                        const ikincilBirimAdiSpan = document.getElementById('ikincilBirimAdi');
                        if (ikincilBirimAdiSpan) ikincilBirimAdiSpan.textContent = ikincilBirimAdi;
                        
                        const anaBirimSelect = document.getElementById('newProductUnit');
                        const anaBirimOption = anaBirimSelect ? anaBirimSelect.options[anaBirimSelect.selectedIndex] : null;
                        const anaBirimAdi = anaBirimOption ? anaBirimOption.textContent : '';
                        const anaBirimAdiSpan = document.getElementById('anaBirimAdi');
                        if (anaBirimAdiSpan) anaBirimAdiSpan.textContent = anaBirimAdi;
                        
                        if (ikincilBirimMiktarGroup) {
                            ikincilBirimMiktarGroup.style.display = 'block';
                            const miktarInput = document.getElementById('newProductIkincilBirimMiktar');
                            if (miktarInput) {
                                miktarInput.required = true;
                                miktarInput.focus();
                            }
                        }
                    } else {
                        if (ikincilBirimMiktarGroup) {
                            ikincilBirimMiktarGroup.style.display = 'none';
                            const miktarInput = document.getElementById('newProductIkincilBirimMiktar');
                            if (miktarInput) {
                                miktarInput.required = false;
                                miktarInput.value = '';
                            }
                        }
                    }
                });
            }

            // Üçüncül birim seçildiğinde miktar alanını göster
            const ucuncuBirimSelect = document.getElementById('newProductUcuncuBirim');
            if (ucuncuBirimSelect) {
                ucuncuBirimSelect.addEventListener('change', function() {
                    const ucuncuBirimMiktarGroup = document.getElementById('ucuncuBirimMiktarGroup');
                    if (this.value && this.value.trim() !== '') {
                        const selectedOption = this.options[this.selectedIndex];
                        const ucuncuBirimAdi = selectedOption ? selectedOption.textContent : '';
                        const ucuncuBirimAdiSpan = document.getElementById('ucuncuBirimAdi');
                        if (ucuncuBirimAdiSpan) ucuncuBirimAdiSpan.textContent = ucuncuBirimAdi;
                        
                        const anaBirimSelect = document.getElementById('newProductUnit');
                        const anaBirimOption = anaBirimSelect ? anaBirimSelect.options[anaBirimSelect.selectedIndex] : null;
                        const anaBirimAdi = anaBirimOption ? anaBirimOption.textContent : '';
                        const anaBirimAdi2Span = document.getElementById('anaBirimAdi2');
                        if (anaBirimAdi2Span) anaBirimAdi2Span.textContent = anaBirimAdi;
                        
                        if (ucuncuBirimMiktarGroup) {
                            ucuncuBirimMiktarGroup.style.display = 'block';
                            const miktarInput = document.getElementById('newProductUcuncuBirimMiktar');
                            if (miktarInput) {
                                miktarInput.required = true;
                                miktarInput.focus();
                            }
                        }
                    } else {
                        if (ucuncuBirimMiktarGroup) {
                            ucuncuBirimMiktarGroup.style.display = 'none';
                            const miktarInput = document.getElementById('newProductUcuncuBirimMiktar');
                            if (miktarInput) {
                                miktarInput.required = false;
                                miktarInput.value = '';
                            }
                        }
                    }
                });
            }
        });

        function handleAddProduct(event) {
            if (event) {
                event.preventDefault();
            }

            const productName = document.getElementById('newProductName').value.trim();
            const productUnit = document.getElementById('newProductUnit').value;
            const productIkincilBirim = document.getElementById('newProductIkincilBirim').value.trim();
            const productIkincilBirimMiktarInput = document.getElementById('newProductIkincilBirimMiktar');
            const productIkincilBirimMiktar = productIkincilBirimMiktarInput ? productIkincilBirimMiktarInput.value.trim() : '';
            const productUcuncuBirim = document.getElementById('newProductUcuncuBirim').value.trim();
            const productUcuncuBirimMiktarInput = document.getElementById('newProductUcuncuBirimMiktar');
            const productUcuncuBirimMiktar = productUcuncuBirimMiktarInput ? productUcuncuBirimMiktarInput.value.trim() : '';
            const productDescription = document.getElementById('newProductDescription').value.trim();

            if (!productName || !productUnit) {
                alert('Lütfen ürün adı ve ana birim alanlarını doldurun.');
                return;
            }

            // İkincil birim seçilmişse miktar zorunlu (sadece seçilmişse kontrol et)
            if (productIkincilBirim && productIkincilBirim.length > 0) {
                const ikincilMiktar = parseFloat(productIkincilBirimMiktar);
                if (!productIkincilBirimMiktar || isNaN(ikincilMiktar) || ikincilMiktar <= 0) {
                    alert('İkincil birim seçildiğinde miktar zorunludur ve 0\'dan büyük olmalıdır.');
                    if (productIkincilBirimMiktarInput) {
                        productIkincilBirimMiktarInput.focus();
                    }
                    return;
                }
            }

            // Üçüncül birim seçilmişse miktar zorunlu (sadece seçilmişse kontrol et)
            if (productUcuncuBirim && productUcuncuBirim.length > 0) {
                const ucuncuMiktar = parseFloat(productUcuncuBirimMiktar);
                if (!productUcuncuBirimMiktar || isNaN(ucuncuMiktar) || ucuncuMiktar <= 0) {
                    alert('Üçüncül birim seçildiğinde miktar zorunludur ve 0\'dan büyük olmalıdır.');
                    if (productUcuncuBirimMiktarInput) {
                        productUcuncuBirimMiktarInput.focus();
                    }
                    return;
                }
            }

            // Loading göster
            const submitButton = event && event.target ? event.target.querySelector('button[type="button"]') : document.querySelector('#addProductModal .btn-primary');
            const originalText = submitButton ? submitButton.textContent : 'Ekle ve Sepete Ekle';
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Ekleniyor...';
            }

            // AJAX ile backend'e gönder
            const formData = new FormData();
            formData.append('action', 'create_stock_item');
            formData.append('productName', productName);
            formData.append('productUnit', productUnit);
            formData.append('productIkincilBirim', productIkincilBirim);
            formData.append('productIkincilBirimMiktar', productIkincilBirimMiktar);
            formData.append('productUcuncuBirim', productUcuncuBirim);
            formData.append('productUcuncuBirimMiktar', productUcuncuBirimMiktar);
            formData.append('productDescription', productDescription);

            fetch('UretimSO.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                }

                if (data.success) {
                    // Başarılı - Ürün kodu döndü
                    const itemCode = data.itemCode || productName;
                    
                    // Ürün adını malzeme arama alanına yaz ve ekle
                    const materialSearch = document.getElementById('materialSearch');
                    const materialUnitSelect = document.getElementById('materialUnit');
                    const materialUnitHidden = document.getElementById('materialUnitHidden');
                    materialSearch.value = productName;
                    
                    // Birimi seç (ana birim)
                    const selectedUnitOption = document.querySelector(`#newProductUnit option[value="${productUnit}"]`);
                    if (selectedUnitOption) {
                        const unitCode = selectedUnitOption.getAttribute('data-code');
                        if (unitCode) {
                            const matchingUnit = Array.from(document.querySelectorAll('#materialUnit option')).find(opt => opt.value === unitCode);
                            if (matchingUnit) {
                                materialUnitSelect.value = unitCode;
                                if (materialUnitHidden) {
                                    materialUnitHidden.value = unitCode;
                                }
                            }
                        }
                    }
                    
                    // Malzemeyi sepete ekle
                    addMaterial();

                    // Modal'ı kapat ve formu temizle
                    closeAddProductModal();

                    alert('Yeni ürün başarıyla eklendi! Ürün Kodu: ' + itemCode);
                } else {
                    alert('Hata: ' + (data.message || 'Ürün eklenirken bir hata oluştu.'));
                }
            })
            .catch(error => {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = originalText;
                }
                alert('Bir hata oluştu. Lütfen tekrar deneyin.');
            });
        }

        // ESC tuşu ile modal kapatma
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddProductModal();
            }
        });

        // Single Select Dropdown Fonksiyonları (Ürün Grubu ve Birim için)
        function toggleSingleSelect(type) {
            const dropdown = document.getElementById(type + 'Dropdown');
            const input = document.getElementById(type + 'Input');
            
            if (!dropdown || !input) {
                return;
            }
            
            const container = input.closest('.single-select-container');
            if (!container) {
                return;
            }
            
            const inputElement = container.querySelector('.single-select-input');
            
            // Diğer dropdown'ları kapat
            document.querySelectorAll('.single-select-dropdown').forEach(d => {
                if (d.id !== type + 'Dropdown') {
                    d.classList.remove('show');
                }
            });
            document.querySelectorAll('.single-select-input').forEach(i => {
                if (i !== inputElement) {
                    i.classList.remove('active');
                }
            });
            
            // Bu dropdown'ı aç/kapat
            dropdown.classList.toggle('show');
            if (inputElement) {
                inputElement.classList.toggle('active');
            }
            
            // Dropdown açıldığında input'u readonly'den çıkar (arama için)
            if (dropdown.classList.contains('show')) {
                input.removeAttribute('readonly');
                input.focus();
            } else {
                input.setAttribute('readonly', 'readonly');
            }
        }

        function selectSingleOption(type, value, displayText) {
            const input = document.getElementById(type + 'Input');
            const hiddenInput = document.getElementById(type);
            const dropdown = document.getElementById(type + 'Dropdown');
            const container = input.closest('.single-select-container');
            const inputElement = container.querySelector('.single-select-input');
            
            // Değerleri güncelle
            input.value = displayText;
            if (hiddenInput) {
                hiddenInput.value = value;
            }
            
            // Seçili option'ı işaretle
            dropdown.querySelectorAll('.single-select-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            const selectedOption = dropdown.querySelector(`.single-select-option[data-value="${value.replace(/"/g, '&quot;')}"]`);
            if (selectedOption) {
                selectedOption.classList.add('selected');
            }
            
            // Dropdown'ı kapat
            dropdown.classList.remove('show');
            inputElement.classList.remove('active');
            input.setAttribute('readonly', 'readonly');
        }

        function filterSingleSelect(type, searchText) {
            const dropdown = document.getElementById(type + 'Dropdown');
            if (!dropdown) return;
            
            const options = dropdown.querySelectorAll('.single-select-option');
            const searchLower = searchText.toLowerCase().trim();
            
            options.forEach(option => {
                const text = option.textContent.toLowerCase();
                if (searchLower === '' || text.includes(searchLower)) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
        }

        // Dropdown dışına tıklandığında kapat
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.single-select-container')) {
                document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
                document.querySelectorAll('.single-select-input').forEach(i => {
                    i.classList.remove('active');
                    const input = i.querySelector('input[type="text"]');
                    if (input) {
                        input.setAttribute('readonly', 'readonly');
                    }
                });
            }
        });
        
    </script>
</body>
</html>
