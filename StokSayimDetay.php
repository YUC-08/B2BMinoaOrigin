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

// --- YARDIMCI FONKSİYON: BİRİM ÇARPANINI BUL ---
// Bu fonksiyon, "KT" gibi bir birim kodunun kaç "AD" ettiğini bulur.
function getBaseQty($sap, $itemCode, $uomCode) {
    if (empty($itemCode) || empty($uomCode)) return 1;
    
    // Ürünün grup bilgisini çek
    $itemData = $sap->get("Items('$itemCode')?\$select=UoMGroupEntry,InventoryUOM");
    $uomGroupEntry = $itemData['response']['UoMGroupEntry'] ?? -1;
    
    // Manuel grup ise veya birim stok birimiyle aynıysa çarpan 1'dir
    if ($uomGroupEntry == -1) return 1;
    
    // Grup tanımlarını çek
    $groupData = $sap->get("UoMGroups($uomGroupEntry)?\$select=UoMGroupDefinitionCollection");
    $defs = $groupData['response']['UoMGroupDefinitionCollection'] ?? [];
    
    foreach ($defs as $def) {
        // SAP bazen büyük/küçük harf duyarlı olabilir, strcasecmp ile kontrol
        if (strcasecmp($def['UoMCode'], $uomCode) === 0) {
            return floatval($def['BaseQuantity']); // Örn: 1 KT = 24 AD ise 24 döner
        }
    }
    return 1; // Bulunamazsa 1 kabul et
}

// --- GÜNCELLEME VE ONAYLAMA İŞLEMLERİ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($isClosed) {
        echo json_encode(['success' => false, 'message' => 'Kapalı belge üzerinde işlem yapılamaz.']);
        exit;
    }
    
    $inputLines = isset($_POST['lines']) ? json_decode($_POST['lines'], true) : [];
    $action = $_POST['action']; // 'update' veya 'confirm'
    
    if (empty($inputLines)) {
        echo json_encode(['success' => false, 'message' => 'Veri gelmedi.']);
        exit;
    }
    
    $payloadLines = [];
    
    foreach ($inputLines as $input) {
        $lineNum = $input['LineNumber'] ?? $input['LineNum'] ?? null;
        $itemCode = $input['ItemCode'] ?? '';
        $userQty = floatval($input['CountedQuantity'] ?? 0); // Kullanıcının girdiği (Örn: 1)
        
        // Mevcut satırdaki birim kodunu (UoMCode) bulmamız lazım (Örn: KT)
        // Bunu yapmak için $lines dizisinde arama yapıyoruz
        $currentLine = null;
        foreach ($lines as $l) {
            if (($l['LineNumber'] ?? $l['LineNum'] ?? null) == $lineNum) {
                $currentLine = $l;
                break;
            }
        }
        
        if (!$currentLine) continue;
        
        // --- MATEMATİKSEL DÜZELTME ---
        // Kullanıcının girdiği miktarı (1 Kutu), SAP'nin anladığı dile (10 Adet) çeviriyoruz.
        $targetUoM = $currentLine['UoMCode'] ?? null;
        
        // Eğer birim "Manuel" değilse çarpma işlemi yap
        $multiplier = 1;
        if ($targetUoM && $targetUoM !== 'Manual') {
            $multiplier = getBaseQty($sap, $itemCode, $targetUoM);
        }
        
        // SAP'ye gidecek NET miktar (Adet bazında)
        $finalQty = $userQty * $multiplier;
        
        // Payload Hazırlığı
        $lineData = [
            'LineNumber' => intval($lineNum),
            'CountedQuantity' => $finalQty, // Çevrilmiş miktar (10)
            'Counted' => 'tYES'
        ];
        
        $payloadLines[] = $lineData;
    }
    
    // 1. ADIM: Sayım Belgesini Güncelle (InventoryCounting - PATCH)
    // Bu adım her iki durumda da (Güncelle ve Onayla) yapılır.
    $patchPayload = ['InventoryCountingLines' => $payloadLines];
    $patchRes = $sap->patch("InventoryCountings({$documentEntry})", $patchPayload);
    
    if (($patchRes['status'] ?? 0) != 200 && ($patchRes['status'] ?? 0) != 204) {
        $err = $patchRes['response']['error']['message']['value'] ?? $patchRes['response']['error']['message'] ?? 'Güncelleme hatası';
        echo json_encode(['success' => false, 'message' => "Hata: $err"]);
        exit;
    }
    
    // 2. ADIM: Eğer işlem 'confirm' ise Stok Kaydı Oluştur (InventoryPostings - POST)
    if ($action === 'confirm') {
        // Sayım farkı olan satırları bulmak için belgeyi TEKRAR çekiyoruz (Güncel haliyle)
        $updatedCounting = $sap->get("InventoryCountings({$documentEntry})");
        $updatedLines = $updatedCounting['response']['InventoryCountingLines'] ?? [];
        
        // Eğer hala boşsa, direkt collection path'i dene
        if (empty($updatedLines)) {
            $linesData = $sap->get("InventoryCountings({$documentEntry})/InventoryCountingLines");
            $linesResponse = $linesData['response'] ?? $linesData;
            if (isset($linesResponse['value']) && is_array($linesResponse['value'])) {
                $updatedLines = $linesResponse['value'];
            } elseif (is_array($linesResponse)) {
                $updatedLines = $linesResponse;
            }
        }
        
        $postingLines = [];
        
        foreach ($updatedLines as $uLine) {
            // Sistemdeki miktar (InWarehouseQuantity) ile Sayılan (CountedQuantity) farkı var mı?
            $sysQty = floatval($uLine['InWarehouseQuantity'] ?? $uLine['SystemQuantity'] ?? 0);
            $countQty = floatval($uLine['CountedQuantity'] ?? 0);
            
            // Fark 0 ise stok kaydına ekleme
            if (abs($sysQty - $countQty) < 0.001) continue;
            
            // Fiyatı al (Maliyet)
            $itemCode = $uLine['ItemCode'] ?? '';
            if (empty($itemCode)) continue;
            
            $itmPriceData = $sap->get("Items('$itemCode')?\$select=ItemCost,AvgPrice");
            $price = $itmPriceData['response']['ItemCost'] ?? $itmPriceData['response']['AvgPrice'] ?? 1; // Maliyet yoksa 1 yaz
            if($price <= 0) $price = 1;
            
            // Stok Kaydı Satırı
            // DİKKAT: Stok kaydı (Inventory Posting) her zaman ANA BİRİM (Adet) üzerinden çalışır.
            // UoMCode veya UoMEntry göndermiyoruz, çünkü zaten yukarıda miktarı Adet'e çevirdik.
            $postingLines[] = [
                'BaseType' => 1470000065, // InventoryCounting Type ID
                'BaseEntry' => intval($documentEntry),
                'BaseLine' => intval($uLine['LineNumber'] ?? $uLine['LineNum'] ?? 0),
                'ItemCode' => $itemCode,
                'WarehouseCode' => $uLine['WarehouseCode'] ?? '',
                'CountedQuantity' => $countQty, // Zaten adet cinsinden
                'Price' => $price
            ];
            
            // Depo kilidini kaldır (Locked hatası için önlem)
            try { 
                $sap->patch("Items('$itemCode')", [
                    'ItemWarehouseInfoCollection' => [[ 'WarehouseCode' => $uLine['WarehouseCode'] ?? '', 'Locked' => 'tNO' ]]
                ]); 
            } catch (Exception $e) {}
        }
        
        if (empty($postingLines)) {
            // Fark yoksa sadece belgeyi kapat
            $sap->patch("InventoryCountings({$documentEntry})", ['DocumentStatus' => 'bost_Close']);
            echo json_encode(['success' => true, 'message' => 'Fark bulunamadı, belge kapatıldı.']);
            exit;
        }
        
        // Stok Kaydını (Inventory Posting) Oluştur
        $postPayload = [
            'Remarks' => "Sayım Belgesi #$documentEntry Referanslı Kayıt",
            'InventoryPostingLines' => $postingLines
        ];
        
        $postRes = $sap->post('InventoryPostings', $postPayload);
        
        if (($postRes['status'] ?? 0) == 200 || ($postRes['status'] ?? 0) == 201) {
            // Sayımı kapat
            $sap->patch("InventoryCountings({$documentEntry})", ['DocumentStatus' => 'bost_Close']);
            echo json_encode(['success' => true, 'message' => 'Stok kaydı başarıyla oluşturuldu ve sayım kapatıldı.']);
        } else {
            $err = $postRes['response']['error']['message']['value'] ?? $postRes['response']['error']['message'] ?? 'Stok kaydı oluşturulamadı';
            echo json_encode(['success' => false, 'message' => "Hata: $err", 'debug' => $postRes]);
        }
        exit;
    }
    
    // Sadece güncelleme ise
    echo json_encode(['success' => true, 'message' => 'Miktarlar güncellendi.']);
    exit;
}

// Eski confirm kodu kaldırıldı - yukarıdaki birleşik işlem kullanılıyor
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
    sendData('update');
}

function confirmCounting() {
    if (confirm('Sayımı onaylayıp stok kaydı oluşturmak istiyor musunuz? Bu işlem geri alınamaz.')) {
        sendData('confirm');
    }
}

function sendData(actionType) {
    const lines = [];
    // Sadece input olan satırları tara
    const inputs = document.querySelectorAll('#linesTableBody input[type="number"]');
    
    inputs.forEach(input => {
        const lineNum = input.getAttribute('data-line-num');
        const itemCode = input.getAttribute('data-item-code');
        const val = input.value;
        // Boş değilse listeye ekle
        if (lineNum && val !== "") {
            lines.push({
                LineNumber: parseInt(lineNum),
                ItemCode: itemCode,
                CountedQuantity: parseFloat(val)
            });
        }
    });
    
    if (lines.length === 0) {
        alert('Lütfen en az bir miktar giriniz.');
        return;
    }
    
    // Loading göster
    const btn = actionType === 'confirm' ? document.querySelector('.btn-success') : document.querySelector('.btn-primary');
    const originalText = btn ? btn.innerText : '';
    if (btn) {
        btn.innerText = 'İşleniyor...';
        btn.disabled = true;
    }
    
    const formData = new FormData();
    formData.append('action', actionType);
    formData.append('lines', JSON.stringify(lines));
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            if (actionType === 'confirm') {
                window.location.href = 'Stok.php'; // Onaylandıysa listeye dön
            } else {
                location.reload(); // Güncellendiyse sayfayı yenile
            }
        } else {
            alert(data.message);
            if (btn) {
                btn.innerText = originalText;
                btn.disabled = false;
            }
        }
    })
    .catch(err => {
        console.error(err);
        alert('Bir bağlantı hatası oluştu.');
        if (btn) {
            btn.innerText = originalText;
            btn.disabled = false;
        }
    });
}
    </script>
</body>
</html>


