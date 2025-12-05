<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

// DocumentEntry parametresi gerekli
$documentEntry = isset($_GET['DocumentEntry']) ? intval($_GET['DocumentEntry']) : null;

if (empty($documentEntry)) {
    header("Location: Stok.php");
    exit;
}

// InventoryCounting belgesini çek (expand çalışmıyor, header response'undan alıyoruz)
$countingQuery = "InventoryCountings({$documentEntry})";
$countingData = $sap->get($countingQuery);

if (($countingData['status'] ?? 0) != 200) {
    die("Sayım belgesi bulunamadı veya erişilemedi.");
}

$counting = $countingData['response'] ?? $countingData;
$lines = [];

// Header response'undan InventoryCountingLines'ı al
if (isset($counting['InventoryCountingLines']) && is_array($counting['InventoryCountingLines'])) {
    $lines = $counting['InventoryCountingLines'];
}

// Eğer hala boşsa, direkt collection path'i dene
if (empty($lines)) {
    $linesQuery = "InventoryCountings({$documentEntry})/InventoryCountingLines";
    $linesData = $sap->get($linesQuery);
    
    if (($linesData['status'] ?? 0) == 200) {
        $linesResponse = $linesData['response'] ?? $linesData;
        
        // Farklı response yapılarını kontrol et
        if (isset($linesResponse['value']) && is_array($linesResponse['value'])) {
            $lines = $linesResponse['value'];
        } elseif (isset($linesResponse['InventoryCountingLines']) && is_array($linesResponse['InventoryCountingLines'])) {
            $lines = $linesResponse['InventoryCountingLines'];
        } elseif (is_array($linesResponse)) {
            $lines = $linesResponse;
        }
    }
}

$documentStatus = $counting['DocumentStatus'] ?? '';
// Status mapping: cdsOpen, cdsClosed, bost_Open, bost_Close
$isClosed = (stripos($documentStatus, 'close') !== false || $documentStatus === 'bost_Close');

// Status mapping
function getStatusText($status) {
    $statusMap = [
        'bost_Open' => 'Açık',
        'bost_Close' => 'Kapalı',
        'cdsOpen' => 'Açık',
        'cdsClosed' => 'Kapalı',
        'cds_Open' => 'Açık',
        'cds_Closed' => 'Kapalı',
        'Open' => 'Açık',
        'Closed' => 'Kapalı'
    ];
    // Eğer status içinde 'open' veya 'close' geçiyorsa ona göre döndür
    if (stripos($status, 'open') !== false) {
        return 'Açık';
    }
    if (stripos($status, 'close') !== false) {
        return 'Kapalı';
    }
    return $statusMap[$status] ?? ($status ?: 'Bilinmiyor');
}

function getStatusClass($status) {
    $classMap = [
        'bost_Open' => 'status-open',
        'bost_Close' => 'status-closed',
        'cdsOpen' => 'status-open',
        'cdsClosed' => 'status-closed',
        'cds_Open' => 'status-open',
        'cds_Closed' => 'status-closed',
        'Open' => 'status-open',
        'Closed' => 'status-closed'
    ];
    // Eğer status içinde 'open' veya 'close' geçiyorsa ona göre döndür
    if (stripos($status, 'open') !== false) {
        return 'status-open';
    }
    if (stripos($status, 'close') !== false) {
        return 'status-closed';
    }
    return $classMap[$status] ?? 'status-unknown';
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// PATCH: Sayım satırlarını güncelle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    header('Content-Type: application/json');
    
    if ($isClosed) {
        echo json_encode(['success' => false, 'message' => 'Kapalı sayım güncellenemez']);
        exit;
    }
    
    $lines = isset($_POST['lines']) ? json_decode($_POST['lines'], true) : [];
    
    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'En az bir kalem gereklidir']);
        exit;
    }
    
    $payload = [
        'InventoryCountingLines' => []
    ];
    
    foreach ($lines as $line) {
        // PATCH için LineNumber kullan (LineNum geçersiz!)
        $lineNumber = $line['LineNumber'] ?? $line['LineNum'] ?? null;
        if ($lineNumber === null) {
            continue;
        }
        
        $lineData = [
            'LineNumber' => intval($lineNumber), // PATCH için LineNumber kullan (LineNum geçersiz!)
            'CountedQuantity' => floatval($line['CountedQuantity'] ?? 0),
            'Counted' => 'tYES' // SAP'nin satırı sayılmış olarak işaretlemesi için gerekli
        ];
        
        $payload['InventoryCountingLines'][] = $lineData;
    }
    
    $result = $sap->patch("InventoryCountings({$documentEntry})", $payload);
    
    if (($result['status'] ?? 0) == 200 || ($result['status'] ?? 0) == 204) {
        echo json_encode(['success' => true, 'message' => 'Sayım güncellendi']);
    } else {
        $error = $result['response']['error']['message']['value'] ?? $result['response']['error']['message'] ?? 'Bilinmeyen hata';
        echo json_encode(['success' => false, 'message' => 'Sayım güncellenemedi: ' . $error]);
    }
    exit;
}

// POST: Sayımı onayla (InventoryPostings oluştur)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm') {
    header('Content-Type: application/json');
    
    if ($isClosed) {
        echo json_encode(['success' => false, 'message' => 'Sayım zaten kapalı']);
        exit;
    }
    
    $lines = isset($_POST['lines']) ? json_decode($_POST['lines'], true) : [];
    
    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'En az bir kalem gereklidir']);
        exit;
    }
    
    // InventoryCounting'deki gerçek satırları çek
    // UoMEntry bilgisini alabilmek için satır detaylarını garanti altına alıyoruz
    $countingQuery = "InventoryCountings({$documentEntry})";
    $countingData = $sap->get($countingQuery);
    $countingLines = [];
    
    if (($countingData['status'] ?? 0) == 200) {
        $counting = $countingData['response'] ?? $countingData;
        if (isset($counting['InventoryCountingLines']) && is_array($counting['InventoryCountingLines'])) {
            $countingLines = $counting['InventoryCountingLines'];
        }
    }
    
    // Eğer Header'da satır yoksa, ayrıca satır endpoint'ine git
    if (empty($countingLines)) {
        $linesData = $sap->get("InventoryCountings({$documentEntry})/InventoryCountingLines");
        $countingLines = $linesData['value'] ?? $linesData['response']['value'] ?? [];
    }

    if (empty($countingLines)) {
        echo json_encode(['success' => false, 'message' => 'Sayım belgesinde satır bulunamadı']);
        exit;
    }
    
    // Frontend verilerini map'le
    $userInputMap = [];
    foreach ($lines as $line) {
        $itemCode = $line['ItemCode'] ?? '';
        $countedQty = floatval($line['CountedQuantity'] ?? 0);
        if ($itemCode) {
            $userInputMap[$itemCode] = $countedQty;
        }
    }
    
    // ADIM 1: Sayım Satırlarını Güncelle (Counted = tYES)
    $updatePayload = [
        'InventoryCountingLines' => []
    ];
    
    foreach ($countingLines as $countingLine) {
        $itemCode = $countingLine['ItemCode'] ?? '';
        $lineNumber = $countingLine['LineNumber'] ?? null;
        
        if (empty($itemCode) || $lineNumber === null) continue;
        
        $countedQuantity = isset($userInputMap[$itemCode]) ? $userInputMap[$itemCode] : floatval($countingLine['CountedQuantity'] ?? 0);
        
        $updatePayload['InventoryCountingLines'][] = [
            'LineNumber' => intval($lineNumber),
            'CountedQuantity' => $countedQuantity,
            'Counted' => 'tYES'
        ];
    }
    
    $sap->patch("InventoryCountings({$documentEntry})", $updatePayload);
    
    // --- HAZIRLIK: Fiyatları Hazırla ---
    
    // 1. Tarihi Header'dan al
    $headerCountDate = $counting['CountDate'] ?? date('Y-m-d');
    if (strpos($headerCountDate, 'T') !== false) {
        $headerCountDate = substr($headerCountDate, 0, 10);
    }

    // 2. Ürün maliyetlerini çek (Fiyat Hatası Çözümü)
    $itemInfoMap = [];
    foreach ($countingLines as $cl) {
        $icode = $cl['ItemCode'] ?? '';
        if ($icode && !isset($itemInfoMap[$icode])) {
            $itmData = $sap->get("Items('$icode')?\$select=ItemCost,AvgPrice");
            $val = $itmData['response'] ?? $itmData;
            
            $cost = 0;
            if (isset($val['ItemCost'])) $cost = $val['ItemCost'];
            elseif (isset($val['AvgPrice'])) $cost = $val['AvgPrice'];
            
            $itemInfoMap[$icode] = ($cost > 0) ? $cost : 1; // Maliyet yoksa 1
        }
    }

    // ADIM 2: InventoryPostingLines Oluştur
    $postingLines = [];
    
    foreach ($countingLines as $countingLine) {
        $itemCode = $countingLine['ItemCode'] ?? '';
        $warehouseCode = $countingLine['WarehouseCode'] ?? '';
        $lineNumber = $countingLine['LineNumber'] ?? null;
        
        if (empty($itemCode) || $lineNumber === null) continue;
        
        // Sistem miktarı (sayım tarihindeki depodaki miktar)
        $systemQty = floatval(
            $countingLine['InWarehouseQuantity'] ??
            $countingLine['SystemQuantity'] ??
            0
        );
        
        // Kullanıcının girdiği sayım miktarı
        $countedQuantity = isset($userInputMap[$itemCode])
            ? $userInputMap[$itemCode]
            : floatval($countingLine['CountedQuantity'] ?? 0);
        
        // 🔴 SAP B1 davranışını taklit et:
        // Sapma 0 ise bu satır için stok kaydı oluşturma
        if (abs($countedQuantity - $systemQty) < 0.000001) {
            continue;
        }
        
        // Item'ın güncel UoM bilgisini çek
        $isManualUoM = false; // Manuel mi kontrolü için flag
        $uomEntry = null;
        $uomCode = null;
        
        if (!empty($itemCode)) {
            $itemUoMResp = $sap->get("Items('{$itemCode}')?\$select=InventoryUOM,UoMGroupEntry,SalesUnit,PurchasingUnit");
            $itemUoMData = $itemUoMResp['response'] ?? $itemUoMResp;
            
            $uomCode = $itemUoMData['InventoryUOM'] ?? null;
            $uomGroupEntry = $itemUoMData['UoMGroupEntry'] ?? -1;
            
            // SAP'de -1 genelde "Manuel" gruptur.
            if ($uomGroupEntry == -1) {
                $isManualUoM = true;
            } else {
                // Eğer Manuel değilse, Gruptan doğru Entry'i bulmaya çalış
                $uomGroupResp = $sap->get("UoMGroups({$uomGroupEntry})?\$select=UoMGroupDefinitionCollection");
                $uomGroupData = $uomGroupResp['response'] ?? $uomGroupResp;
                
                if (isset($uomGroupData['UoMGroupDefinitionCollection']) && is_array($uomGroupData['UoMGroupDefinitionCollection'])) {
                    // 1. ÖNCELİK: Sayım satırındaki birim kodu (Kt, Cf vb.) ile eşleşen var mı?
                    // Kullanıcı arayüzde 'Koli' seçtiyse veya SAP'den 'Koli' geldiyse onu bulmaya çalış.
                    $targetUoMCode = $countingLine['UoMCode'] ?? $uomCode; 
                    foreach ($uomGroupData['UoMGroupDefinitionCollection'] as $uomDef) {
                        if (($uomDef['UoMCode'] ?? '') === ($targetUoMCode ?? '')) {
                            $uomEntry = $uomDef['UoMEntry'] ?? null;
                            break;
                        }
                    }
                    
                    // 2. ÖNCELİK: Bulamadıysa Stok Birimi (InventoryUOM) ile eşleşeni al
                    if (empty($uomEntry)) {
                        foreach ($uomGroupData['UoMGroupDefinitionCollection'] as $uomDef) {
                            if (($uomDef['UoMCode'] ?? '') === ($uomCode ?? '')) {
                                $uomEntry = $uomDef['UoMEntry'] ?? null;
                                break;
                            }
                        }
                    }
                }
            }
        }
        
        // --- A. KİLİT KALDIRMA (Locked Hatası Çözümü) ---
        $unlockPayload = [
            'ItemWarehouseInfoCollection' => [
                [ 'WarehouseCode' => $warehouseCode, 'Locked' => 'tNO' ]
            ]
        ];
        try { $sap->patch("Items('$itemCode')", $unlockPayload); } catch (Exception $e) {}
        
        
        // --- B. SATIR VERİLERİNİ HAZIRLA ---
        // $countedQuantity ve $systemQty yukarıda hesaplandı, tekrar hesaplamaya gerek yok
        $baseLine = intval($lineNumber);
        $price = $itemInfoMap[$itemCode] ?? 1;
        
        $postingLine = [
            'ItemCode' => $itemCode,
            'WarehouseCode' => $warehouseCode,
            'CountedQuantity' => $countedQuantity,
            'BaseEntry' => $documentEntry,
            'BaseLine' => $baseLine,
            'BaseType' => 1470000065,
            'Price' => $price,
            'CountDate' => $headerCountDate
        ];
        
        // --- C. BİRİM (UoM) KONTROLÜ (DÜZELTİLMİŞ HALİ) ---
        
        // EĞER ÜRÜN MANUEL GRUPTAYSA: Kesinlikle UoMCode veya UoMEntry GÖNDERME!
        if ($isManualUoM) {
            // Manuel gruplar için SAP sadece miktar bekler, birim kodu istemez.
            // Bu blok boş kalacak, postingLine'a UoM eklemeyeceğiz.
        } 
        // EĞER GRUP ÜRÜNÜYSE VE UoMEntry BULUNDUYSA: Mutlaka UoMEntry GÖNDER.
        elseif (!empty($uomEntry)) {
            $postingLine['UoMEntry'] = intval($uomEntry);
        }
        // Entry bulunamadı ve manuel de değilse: HİÇBİR UoM BİLGİSİ GÖNDERME
        // Çünkü yanlış UoMCode göndermek "UoM group has been changed" hatasına neden olur
        // else {
        //     // UoMEntry bulunamadı, hiçbir şey gönderme
        // }
        
        // DEBUG: UoM bilgisini logla
        error_log("=== UoM DEBUG (ItemCode: {$itemCode}) ===");
        error_log("isManualUoM: " . ($isManualUoM ? 'YES' : 'NO') . ", uomGroupEntry: " . ($uomGroupEntry ?? 'NULL'));
        error_log("Resolved UoM: uomEntry=" . ($uomEntry ?? 'NULL') . ", uomCode=" . ($uomCode ?? 'NULL'));
        error_log("PostingLine UoM: " . json_encode(['UoMEntry' => $postingLine['UoMEntry'] ?? null, 'UoMCode' => $postingLine['UoMCode'] ?? null]));
        
        $postingLines[] = $postingLine;
    }
    
    if (empty($postingLines)) {
        echo json_encode(['success' => false, 'message' => 'Fark bulunamadı.']);
        exit;
    }
    
    // InventoryPostings oluştur
    $postingPayload = [
        'Remarks' => 'Sayım farkı bağlı belge',
        'InventoryPostingLines' => $postingLines
    ];
    
    $postingResult = $sap->post('InventoryPostings', $postingPayload);
    
    if (($postingResult['status'] ?? 0) == 200 || ($postingResult['status'] ?? 0) == 201) {
        // Sayımı kapat
        $sap->patch("InventoryCountings({$documentEntry})", ['DocumentStatus' => 'bost_Close']);
        
        echo json_encode(['success' => true, 'message' => 'Sayım onaylandı ve fark belgesi oluşturuldu']);
    } else {
        $error = $postingResult['response']['error']['message']['value'] ?? 'Bilinmeyen hata';
        echo json_encode([
            'success' => false, 
            'message' => 'Sayım onaylanamadı: ' . $error,
            'debug' => ['payload' => $postingPayload, 'error' => $postingResult['response']['error'] ?? null]
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sayım Detay - MINOA</title>
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

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.info-label {
    font-size: 12px;
    color: #6b7280;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    font-size: 15px;
    color: #111827;
    font-weight: 600;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-open {
    background: #d1fae5;
    color: #065f46;
}

.status-closed {
    background: #f3f4f6;
    color: #6b7280;
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

.input-small {
    padding: 6px 10px;
    border: 2px solid #e5e7eb;
    border-radius: 4px;
    font-size: 13px;
    width: 100px;
    transition: all 0.2s;
}

.input-small:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.input-small:read-only {
    background: #f3f4f6;
    cursor: not-allowed;
    color: #374151;
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

.btn-success {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.btn-success:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    transform: translateY(-1px);
}

.btn-success:disabled {
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

.action-buttons {
    display: flex;
    gap: 12px;
    margin-top: 24px;
    justify-content: flex-end;
}

.alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
    display: none;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #10b981;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #ef4444;
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
    
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .action-buttons {
        flex-direction: column;
    }
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Sayım Detay</h2>
            <div style="display: flex; gap: 12px;">
                <button class="btn btn-secondary" onclick="window.location.href='Stok.php'">← Geri Dön</button>
                <?php if (!$isClosed): ?>
                <button class="btn btn-secondary" onclick="window.location.href='StokSayimSO.php?DocumentEntry=<?= $documentEntry ?>&continue=1'">Güncelle (Devam Et)</button>
                <?php endif; ?>
            </div>
        </header>

        <div class="content-wrapper">
            <div id="alertMessage" class="alert"></div>

            <!-- Üst Bilgi Kartı -->
            <section class="card">
                <div class="card-header">
                    <h3>Üst Bilgiler</h3>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Doküman No</span>
                            <span class="info-value"><?= htmlspecialchars($counting['DocumentEntry'] ?? '') ?></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Depo</span>
                            <span class="info-value"><?= htmlspecialchars($counting['WarehouseCode'] ?? '') ?></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Sayım Tarihi</span>
                            <span class="info-value"><?= formatDate($counting['CountDate'] ?? '') ?></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Açıklama</span>
                            <span class="info-value"><?= htmlspecialchars($counting['Remarks'] ?? '') ?></span>
                        </div>
                        
                        <div class="info-item">
                            <span class="info-label">Durum</span>
                            <span class="info-value">
                                <span class="status-badge <?= getStatusClass($documentStatus) ?>">
                                    <?= getStatusText($documentStatus) ?>
                                </span>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Satır Tablosu -->
            <section class="card">
                <div class="card-header">
                    <h3>Sayım Satırları</h3>
                </div>
                <div class="card-body">
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>LineNum</th>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>Depo</th>
                                    <th>Birim</th>
                                    <th>Sayılan Miktar</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody id="linesTableBody">
                                <?php if (empty($lines)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #6b7280;">
                                        Sayım satırı bulunmamaktadır.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($lines as $line): 
                                    $lineNum = $line['LineNum'] ?? $line['LineNumber'] ?? '';
                                ?>
                                <tr data-line-num="<?= $lineNum ?>">
                                    <td><?= htmlspecialchars($lineNum) ?></td>
                                    <td><?= htmlspecialchars($line['ItemCode'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['ItemDescription'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['WarehouseCode'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($line['UoMCode'] ?? '') ?></td>
                                    <td>
                                        <input type="number" 
                                               class="input-small" 
                                               value="<?= htmlspecialchars($line['CountedQuantity'] ?? 0) ?>" 
                                               step="0.01" 
                                               min="0" 
                                               data-line-num="<?= $lineNum ?>"
                                               data-item-code="<?= htmlspecialchars($line['ItemCode'] ?? '') ?>"
                                               data-warehouse-code="<?= htmlspecialchars($line['WarehouseCode'] ?? '') ?>"
                                               data-system-quantity="<?= htmlspecialchars($line['InWarehouseQuantity'] ?? $line['SystemQuantity'] ?? 0) ?>"
                                               <?= $isClosed ? 'readonly' : '' ?>>
                                    </td>
                                    <td>
                                        <span style="color: #9ca3af; font-size: 12px;">Değişiklik</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Butonlar -->
            <?php if (!$isClosed): ?>
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="updateCounting()">Güncelle</button>
                <button class="btn btn-success" onclick="confirmCounting()">Sayımı Onayla</button>
            </div>
            <?php endif; ?>
            
            <!-- Debug Panel -->
            <section class="card" id="debugPanel" style="display: none; margin-top: 24px;">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h3>🔍 Debug Bilgileri</h3>
                    <button class="btn btn-secondary" onclick="document.getElementById('debugPanel').style.display = 'none'">Kapat</button>
                </div>
                <div class="card-body">
                    <pre id="debugContent" style="background: #f8fafc; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 12px; max-height: 500px; overflow-y: auto; white-space: pre-wrap; word-wrap: break-word; margin: 0; border: 1px solid #e5e7eb;">Debug bilgileri burada görünecek...</pre>
                </div>
            </section>
        </div>
    </main>

    <script>
const documentEntry = <?= $documentEntry ?>;
const isClosed = <?= $isClosed ? 'true' : 'false' ?>;

function showAlert(message, type) {
    const alert = document.getElementById('alertMessage');
    alert.textContent = message;
    alert.className = 'alert alert-' + type;
    alert.style.display = 'block';
    
    setTimeout(() => {
        alert.style.display = 'none';
    }, 5000);
    
    // Scroll to top
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function updateCounting() {
    const lines = [];
    const inputs = document.querySelectorAll('#linesTableBody input[type="number"]');
    
    inputs.forEach(input => {
        const lineNum = input.getAttribute('data-line-num');
        const countedQty = parseFloat(input.value) || 0;
        
        if (lineNum) {
            lines.push({
                LineNum: parseInt(lineNum),
                CountedQuantity: countedQty
            });
        }
    });
    
    if (lines.length === 0) {
        alert('Güncellenecek satır bulunamadı');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update');
    formData.append('lines', JSON.stringify(lines));
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert('Sayım güncellendi', 'success');
        } else {
            showAlert('Hata: ' + data.message, 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showAlert('Bir hata oluştu', 'error');
    });
}

function confirmCounting() {
    if (!confirm('Sayımı onaylamak istediğinizden emin misiniz? Fark belgesi oluşturulacak ve sayım kapatılacak.')) {
        return;
    }
    
    const lines = [];
    const inputs = document.querySelectorAll('#linesTableBody input[type="number"]');
    
    inputs.forEach(input => {
        const lineNum = input.getAttribute('data-line-num');
        const itemCode = input.getAttribute('data-item-code');
        const warehouseCode = input.getAttribute('data-warehouse-code');
        const systemQty = parseFloat(input.getAttribute('data-system-quantity')) || 0;
        const countedQty = parseFloat(input.value) || 0;
        
        if (lineNum && itemCode && warehouseCode) {
            lines.push({
                LineNum: parseInt(lineNum),
                ItemCode: itemCode,
                WarehouseCode: warehouseCode,
                CountedQuantity: countedQty,
                SystemQuantity: systemQty
            });
        }
    });
    
    if (lines.length === 0) {
        alert('Onaylanacak satır bulunamadı');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'confirm');
    formData.append('lines', JSON.stringify(lines));
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        // Debug panelini göster
        const debugPanel = document.getElementById('debugPanel');
        const debugContent = document.getElementById('debugContent');
        if (debugPanel && debugContent) {
            debugPanel.style.display = 'block';
            debugContent.textContent = JSON.stringify(data, null, 2);
            debugPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        if (data.success) {
            // Fiyatı olmayan itemler varsa özel mesaj göster
            if (data.itemsWithoutPrice && data.itemsWithoutPrice.length > 0) {
                let priceWarning = '⚠️ Fiyatı bulunamayan ürünler için 1 TL gönderildi:\n\n';
                data.itemsWithoutPrice.forEach(item => {
                    priceWarning += '• ' + item.ItemName + ' (' + item.ItemCode + ')\n';
                });
                alert(priceWarning);
            }
            
            showAlert(data.message, 'success');
            setTimeout(() => {
                window.location.href = 'Stok.php';
            }, 2000);
        } else {
            showAlert('Hata: ' + data.message, 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        showAlert('Bir hata oluştu', 'error');
    });
}
    </script>
</body>
</html>


