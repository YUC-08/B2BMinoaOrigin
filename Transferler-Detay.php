<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// URL'den parametreler
$docEntry = $_GET['docEntry'] ?? '';
$type = $_GET['type'] ?? 'incoming'; // incoming veya outgoing
$filterItemCode = $_GET['itemCode'] ?? ''; // Tek kalem teslim/inceleme için
$filterLineNum = $_GET['lineNum'] ?? '';   // Tek kalem teslim/inceleme için
$filterItemCode = $_GET['itemCode'] ?? ''; // Filtreleme için ItemCode
$filterLineNum = $_GET['lineNum'] ?? ''; // Filtreleme için LineNum

if (empty($docEntry)) {
    header("Location: Transferler.php");
    exit;
}

// InventoryTransferRequests({docEntry}) çağır - Her zaman fresh data çek
$docQuery = "InventoryTransferRequests({$docEntry})";
$docData = $sap->get($docQuery);
$requestData = $docData['response'] ?? null;

if (!$requestData) {
    echo "Belge bulunamadı!";
    exit;
}

// STATUS'u fresh olarak al (cache'lenmiş değer kullanma)
$status = $requestData['U_ASB2B_STATUS'] ?? '0';

// Lines'ı çek - önce StockTransferLines, sonra InventoryTransferRequestLines dene
$allLines = $requestData['StockTransferLines'] ?? [];
if (empty($allLines)) {
    // InventoryTransferRequestLines endpoint'ini dene
    $linesQuery = "InventoryTransferRequests({$docEntry})/InventoryTransferRequestLines";
    $linesData = $sap->get($linesQuery);
    if (($linesData['status'] ?? 0) == 200) {
        $linesResponse = $linesData['response'] ?? null;
        if ($linesResponse) {
            if (isset($linesResponse['value']) && is_array($linesResponse['value'])) {
                $allLines = $linesResponse['value'];
            } elseif (is_array($linesResponse) && !isset($linesResponse['@odata.context'])) {
                $allLines = $linesResponse;
            }
        }
    }
}

$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch = $_SESSION["WhsCode"] ?? $_SESSION["Branch2"]["Name"] ?? '';

// Miktar formatı: 10.00 → 10, 10.5 → 10,5, 10.25 → 10,25
function formatQuantity($qty) {
    $num = floatval($qty);
    if ($num == 0) return '0';
    // Tam sayı ise küsurat gösterme
    if ($num == floor($num)) {
        return (string)intval($num);
    }
    // Küsurat varsa virgül ile göster
    return str_replace('.', ',', rtrim(rtrim(sprintf('%.2f', $num), '0'), ','));
}

// Status mapping
function getStatusText($status) {
    $statusMap = [
        '0' => 'Onay Bekliyor',
        '1' => 'Onay Bekliyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk Edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal Edildi'
    ];
    return $statusMap[$status] ?? 'Bilinmiyor';
}

function getStatusClass($status) {
    $classMap = [
        '0' => 'status-pending',
        '1' => 'status-pending',
        '2' => 'status-processing',
        '3' => 'status-shipped',
        '4' => 'status-completed',
        '5' => 'status-cancelled'
    ];
    return $classMap[$status] ?? 'status-unknown';
}

function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

function isReceivableStatus($status) {
    $s = trim((string)$status);
    // Sadece Hazırlanıyor (2) ve Sevk Edildi (3) durumlarında teslim al butonu görünür
    // Tamamlandı (4) durumunda buton görünmez
    return in_array($s, ['2', '3'], true);
}

function isApprovalStatus($status) {
    $s = trim((string)$status);
    return in_array($s, ['0', '1'], true);
}

$docDate = formatDate($requestData['DocDate'] ?? '');
$dueDate = formatDate($requestData['DueDate'] ?? '');
// STATUS zaten yukarıda fresh olarak alındı
$statusText = getStatusText($status);
$statusClass = getStatusClass($status);
$numAtCard = $requestData['U_ASB2B_NumAtCard'] ?? '-';
$comments = $requestData['Comments'] ?? '-';
$fromWarehouse = $requestData['FromWarehouse'] ?? '';
$toWarehouse = $requestData['ToWarehouse'] ?? '';
$allLines = $requestData['StockTransferLines'] ?? [];

// Eğer StockTransferLines boşsa, InventoryTransferRequestLines endpoint'ini dene
if (empty($allLines)) {
    $linesQuery = "InventoryTransferRequests({$docEntry})/InventoryTransferRequestLines";
    $linesData = $sap->get($linesQuery);
    if (($linesData['status'] ?? 0) == 200) {
        $linesResponse = $linesData['response'] ?? null;
        if ($linesResponse) {
            if (isset($linesResponse['value']) && is_array($linesResponse['value'])) {
                $allLines = $linesResponse['value'];
            } elseif (is_array($linesResponse) && !isset($linesResponse['@odata.context'])) {
                $allLines = $linesResponse;
            }
        }
    }
}

// Eğer itemCode veya lineNum parametresi varsa, sadece o ürünü göster
$lines = [];
if (!empty($filterItemCode) || !empty($filterLineNum)) {
    foreach ($allLines as $line) {
        $lineItemCode = $line['ItemCode'] ?? '';
        $lineNum = $line['LineNum'] ?? '';
        
        // ItemCode veya LineNum eşleşiyorsa ekle
        if ((!empty($filterItemCode) && $lineItemCode === $filterItemCode) || 
            (!empty($filterLineNum) && (string)$lineNum === (string)$filterLineNum)) {
            $lines[] = $line;
            break; // Sadece bir tane bulmamız yeterli
        }
    }
} else {
    // Parametre yoksa tüm satırları göster
    $lines = $allLines;
}

// Alıcı şubenin ana deposunu ve sevkiyat deposunu bul (detay sayfasında kullanılacak)
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? '';

$anaDepo = null;
$sevkiyatDepo = null;

if (!empty($uAsOwnr) && !empty($branch)) {
    // Ana depo (U_ASB2B_MAIN='1')
    $anaDepoFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '1'";
    $anaDepoQuery = "Warehouses?\$filter=" . urlencode($anaDepoFilter);
    $anaDepoData = $sap->get($anaDepoQuery);
    $anaDepolar = $anaDepoData['response']['value'] ?? [];
    $anaDepo = !empty($anaDepolar) ? $anaDepolar[0]['WarehouseCode'] : null;
    
    // Sevkiyat depo (U_ASB2B_MAIN='2')
    $sevkiyatDepoFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '2'";
    $sevkiyatDepoQuery = "Warehouses?\$filter=" . urlencode($sevkiyatDepoFilter);
    $sevkiyatDepoData = $sap->get($sevkiyatDepoQuery);
    $sevkiyatDepolar = $sevkiyatDepoData['response']['value'] ?? [];
    $sevkiyatDepo = !empty($sevkiyatDepolar) ? $sevkiyatDepolar[0]['WarehouseCode'] : $toWarehouse;
}

// Warehouse isimlerini çek
$fromWarehouseName = '';
$toWarehouseName = '';
if (!empty($fromWarehouse)) {
    $fromWhsQuery = "Warehouses('{$fromWarehouse}')?\$select=WarehouseCode,WarehouseName";
    $fromWhsData = $sap->get($fromWhsQuery);
    $fromWarehouseName = $fromWhsData['response']['WarehouseName'] ?? '';
}
if (!empty($toWarehouse)) {
    $toWhsQuery = "Warehouses('{$toWarehouse}')?\$select=WarehouseCode,WarehouseName";
    $toWhsData = $sap->get($toWhsQuery);
    $toWarehouseName = $toWhsData['response']['WarehouseName'] ?? '';
}

// Gönderen Şube formatı: 100-KT-0 / Şube Adı
$gonderSubeDisplay = $fromWarehouse;
if (!empty($fromWarehouseName)) {
    $gonderSubeDisplay = $fromWarehouse . ' / ' . $fromWarehouseName;
} elseif (empty($fromWarehouse)) {
    $gonderSubeDisplay = '-';
}

// Alıcı Şube formatı: 100-KT-1 / Şube Adı
// STATUS = 4 (Tamamlandı) ise: ana depo (üst) ↓ sevkiyat depo (alt) şeklinde göster
$aliciSubeDisplay = $toWarehouse;
if ($status == '4' && $anaDepo) {
    // Tamamlandı durumunda: ana depo (üst) ↓ sevkiyat depo (alt)
    $anaDepoName = '';
    if (!empty($anaDepo)) {
        $anaDepoQuery = "Warehouses('{$anaDepo}')?\$select=WarehouseCode,WarehouseName";
        $anaDepoData = $sap->get($anaDepoQuery);
        $anaDepoName = $anaDepoData['response']['WarehouseName'] ?? '';
    }
    
    $sevkiyatDepoDisplay = $toWarehouse;
    if (!empty($toWarehouseName)) {
        $sevkiyatDepoDisplay = $toWarehouse . ' / ' . $toWarehouseName;
    }
    
    $anaDepoDisplay = $anaDepo;
    if (!empty($anaDepoName)) {
        $anaDepoDisplay = $anaDepo . ' / ' . $anaDepoName;
    }
    
    // Sevkiyat depo üstte, ok ortada, ana depo altta
    // Transfer akışı: sevkiyat depo (100-KT-1) → ana depo (100-KT-0)
    // Diğer detail-item'lar gibi sol hizalı olacak, ok iki yazının ortasına denk gelecek
    $aliciSubeDisplay = '<div style="display: flex; flex-direction: column; align-items: flex-start;">' .
                        '<div style="margin-bottom: 4px;">' . htmlspecialchars($sevkiyatDepoDisplay) . '</div>' .
                        '<div style="font-size: 1.2rem; margin: 5px 0; padding-left: 7.5rem;">↓</div>' .
                        '<div style="margin-top: 4px;">' . htmlspecialchars($anaDepoDisplay) . '</div>' .
                        '</div>';
} elseif (!empty($toWarehouseName)) {
    $aliciSubeDisplay = $toWarehouse . ' / ' . $toWarehouseName;
} elseif (empty($toWarehouse)) {
    $aliciSubeDisplay = '-';
}

// Sevk / Teslim miktarları için haritalama: ItemCode => Toplam Miktar (StockTransfers'tan gelen)
$sevkMiktarMap = []; // Gönderen şubenin sevk ettiği miktar (outgoing şube onayladığında)
$teslimatMiktarMap = []; // Alıcı şubenin teslim aldığı miktar (fiziksel - kusurlu)
$outgoingStockTransferInfo = null;
$incomingStockTransferInfo = null;

// 9280 numaralı StockTransfer'i direkt sorgula (debug için ve sevk miktarı için)
// NOT: U_ASB2B_QutMaster filtresi çalışmıyor gibi görünüyor, bu yüzden direkt DocEntry ile de deneyeceğiz
$st9280Data = null;
$st9280Lines = [];

// Sevk miktarı: Hazırlanıyor (2), Sevk Edildi (3) ve Tamamlandı (4) durumlarında göster
// İlk StockTransfer: gönderici depo -> sevkiyat depo (U_ASB2B_QutMaster = docEntry)
// Sevk miktarı = İlk StockTransfer belgesindeki StockTransferLines'daki Quantity değerleri
if ($status == '2' || $status == '3' || $status == '4') {
    $docEntryInt = (int)$docEntry;
    
    // 1) TÜM sevk StockTransfer'lerini bul (U_ASB2B_QutMaster = docEntry)
    // Boşlukları elle %20 veriyoruz, urlencode KULLANMIYORUZ
    $sevkQuery = "StockTransfers?\$filter=U_ASB2B_QutMaster%20eq%20{$docEntryInt}"
               . "&\$orderby=DocEntry%20asc"; // $top=1 kaldırıldı - tüm belgeleri oku
    $sevkData = $sap->get($sevkQuery);
    $sevkList = $sevkData['response']['value'] ?? [];
    
    // 2) Sevk miktar map'i: ItemCode => Quantity (TÜM StockTransferLines.Quantity toplamı)
    $sevkMiktarMap = [];
    
    // Tüm StockTransfer belgelerini dolaş
    foreach ($sevkList as $outgoingStockTransferInfo) {
        $stDocEntry = $outgoingStockTransferInfo['DocEntry'] ?? null;
        if (!$stDocEntry) {
            continue;
        }
        
        // Her stok nakil belgesinin satırlarını çek
        $stLinesQuery = "StockTransfers({$stDocEntry})/StockTransferLines";
        $stLinesData = $sap->get($stLinesQuery);
        
        if (($stLinesData['status'] ?? 0) != 200) {
            continue;
        }
        
        $stLinesResponse = $stLinesData['response'] ?? null;
        $stLines = [];
        
        if (isset($stLinesResponse['value']) && is_array($stLinesResponse['value'])) {
            $stLines = $stLinesResponse['value'];
        } elseif (is_array($stLinesResponse) && !isset($stLinesResponse['value'])) {
            $stLines = $stLinesResponse;
        }
        
        foreach ($stLines as $stLine) {
            $itemCode = $stLine['ItemCode'] ?? '';
            $qty = (float)($stLine['Quantity'] ?? 0);
            
            if ($itemCode === '' || $qty <= 0) {
                continue;
            }
            
            // Fire/Zayi'yi filtrele
            $lost = trim($stLine['U_ASB2B_LOST'] ?? '');
            $damaged = trim($stLine['U_ASB2B_Damaged'] ?? '');
            if (($lost !== '' && $lost !== '-') || ($damaged !== '' && $damaged !== '-')) {
                continue;
            }
            
            if (!isset($sevkMiktarMap[$itemCode])) {
                $sevkMiktarMap[$itemCode] = 0;
            }
            $sevkMiktarMap[$itemCode] += $qty;
        }
    }
}

// Teslimat miktarı: Sadece Sevk Edildi (3) ve Tamamlandı (4) durumlarında göster
// Incoming şube teslim aldığında (fiziksel - kusurlu miktar)
if ($status == '3' || $status == '4') {
    // Teslimat miktarını U_ASB2B_QutMaster ile hesapla
    $docEntryInt = (int)$docEntry;
    
    // U_ASB2B_QutMaster ile filtrele (expand kullanmadan, satırları ayrı çekeceğiz)
    // Boşlukları elle %20 veriyoruz, urlencode KULLANMIYORUZ
    $deliveryQuery = "StockTransfers?\$filter=U_ASB2B_QutMaster%20eq%20{$docEntryInt}";
    $deliveryData = $sap->get($deliveryQuery);
    $deliveryList = $deliveryData['response']['value'] ?? [];

    // İkinci StockTransfer'i bul (sevkiyat depo -> ana depo)
    $secondStockTransfer = null;
    $secondStockTransferLines = [];
    
    foreach ($deliveryList as $st) {
        $isFirstTransfer = ($st['ToWarehouse'] ?? '') === $sevkiyatDepo;
        $isSecondTransfer = !$isFirstTransfer && ($st['FromWarehouse'] ?? '') === $sevkiyatDepo && ($st['ToWarehouse'] ?? '') === $anaDepo;
        
        if ($isSecondTransfer) {
            $secondStockTransfer = $st;
            $stDocEntry = $st['DocEntry'] ?? null;
            
            if ($stDocEntry) {
                $stLinesQuery = "StockTransfers({$stDocEntry})/StockTransferLines";
                $stLinesData = $sap->get($stLinesQuery);
                
                $response = $stLinesData['response'] ?? [];
                if (isset($response['value']) && is_array($response['value'])) {
                    $secondStockTransferLines = $response['value'];
                } elseif (isset($response['StockTransferLines']) && is_array($response['StockTransferLines'])) {
                    $secondStockTransferLines = $response['StockTransferLines'];
                } elseif (is_array($response) && !isset($response['@odata.context'])) {
                    $secondStockTransferLines = $response;
                }
            }
            
            $incomingStockTransferInfo = $secondStockTransfer;
            break;
        }
    }
    
    // İkinci StockTransfer'den teslimat miktarını hesapla
    // Transferler-TeslimAl.php'de ikinci StockTransfer:
    // - Normal transfer satırı: WarehouseCode = ana depo, Quantity = Sevk miktarı (kusurlu hariç)
    // - Kusurlu satırı: WarehouseCode = Fire & Zayi deposu, U_ASB2B_Damaged='K', Quantity = Kusurlu miktarı
    // - Eksik satırı: WarehouseCode = Fire & Zayi deposu, U_ASB2B_LOST='2', U_ASB2B_Damaged='E', Quantity = Eksik miktarı
    // - Fazla satırı: WarehouseCode = Fire & Zayi deposu, U_ASB2B_LOST='1', Quantity = Fazla miktarı
    //
    // ÖNEMLİ: Teslimat Miktarı = Fiziksel = Sevk (Eksik/Fazla eklenmez, kusurlu dahil değil)
    // Sevk zaten fiziksel miktarı temsil ediyor, Eksik/Fazla sadece Sevk ile Talep arasındaki farkı gösterir
    // Yani: Teslimat Miktarı = Normal Transfer (Sevk) = Fiziksel
    // Kusurlu miktar teslimat miktarına eklenmez!
    
    // Önce her itemCode için BaseQty'yi bul
    $itemBaseQtyMap = [];
    foreach ($lines as $reqLine) {
        $itemCode = $reqLine['ItemCode'] ?? '';
        if ($itemCode !== '') {
            $itemBaseQtyMap[$itemCode] = (float)($reqLine['BaseQty'] ?? 1.0);
            if ($itemBaseQtyMap[$itemCode] == 0) {
                $itemBaseQtyMap[$itemCode] = 1.0;
            }
        }
    }
    
    // Her itemCode için normal transfer (Sevk) miktarını topla
    // Teslimat Miktarı = Fiziksel = Sevk (Eksik/Fazla eklenmez)
    $itemNormalTransferMap = []; // Normal transfer = Sevk miktarı = Fiziksel
    
    $targetWhs = $anaDepo ? $anaDepo : $toWarehouse;
    
    foreach ($secondStockTransferLines as $dtLine) {
        $itemCode = $dtLine['ItemCode'] ?? '';
        if ($itemCode === '') continue;
        
        $qty = (float)($dtLine['Quantity'] ?? 0);
        $lineBaseQty = $itemBaseQtyMap[$itemCode] ?? 1.0;
        $normalizedQty = $lineBaseQty > 0 ? ($qty / $lineBaseQty) : $qty;
        
        $lost = trim($dtLine['U_ASB2B_LOST'] ?? '');
        $damaged = trim($dtLine['U_ASB2B_Damaged'] ?? '');
        $isKusurlu = ($damaged === 'K');
        $isEksik = ($lost === '2' && $damaged === 'E');
        $isFazla = ($lost === '1');
        
        $lineToWhs = $dtLine['WarehouseCode'] ?? '';
        
        // Normal transfer satırı (WarehouseCode = ana depo, Fire & Zayi değil, Kusurlu değil)
        // Bu satır Sevk miktarını temsil eder = Fiziksel = Teslimat Miktarı
        if ($lineToWhs === $targetWhs && !$isKusurlu && !$isEksik && !$isFazla) {
            if (!isset($itemNormalTransferMap[$itemCode])) {
                $itemNormalTransferMap[$itemCode] = 0;
            }
            $itemNormalTransferMap[$itemCode] += $normalizedQty;
        }
        
        // Eksik/Fazla ve Kusurlu satırları teslimat miktarına dahil edilmez!
        // Çünkü Sevk zaten fiziksel miktarı temsil ediyor
    }
    
    // Teslimat Miktarı = Normal Transfer (Sevk) = Fiziksel
    // Eksik/Fazla ve Kusurlu miktar eklenmez!
    foreach ($itemNormalTransferMap as $itemCode => $normalTransferQty) {
        // Teslimat Miktarı = Sevk = Fiziksel
        $teslimatMiktarMap[$itemCode] = $normalTransferQty;
    }
}

$canReceive = isReceivableStatus($status);
$canApprove = isApprovalStatus($status);
$showNewRequestButton = ($type === 'incoming'); // Sadece gelen transferler için
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Detay - MINOA</title>
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
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 24px;
}

.detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #e5e7eb;
}

.detail-title h3 {
    font-size: 1.5rem;
    color: #2c3e50;
    font-weight: 400;
}

.detail-title h3 strong {
    font-weight: 600;
    color: #3b82f6;
}

.detail-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    padding: 24px;
    margin-bottom: 24px;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 2rem;
}

.detail-column {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.detail-item label {
    font-size: 13px;
    color: #1e3a8a;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.detail-value {
    font-size: 15px;
    color: #2c3e50;
    font-weight: 500;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    color: #1e3a8a;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e5e7eb;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
    table-layout: fixed;
}

.data-table thead {
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
}

.data-table th {
    padding: 16px 20px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    color: #1e3a8a;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
    width: 25%;
}

.data-table th:nth-child(3),
.data-table th:nth-child(4),
.data-table th:nth-child(5) {
    text-align: center;
}

.data-table td {
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    color: #374151;
    width: 20%;
}

.data-table td:nth-child(3),
.data-table td:nth-child(4),
.data-table td:nth-child(5) {
    text-align: center;
}

.data-table tbody tr {
    transition: background 0.15s ease;
}

.data-table tbody tr:hover {
    background: #f8fafc;
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

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-processing {
    background: #dbeafe;
    color: #1e40af;
}

.status-shipped {
    background: #e0e7ff;
    color: #4338ca;
}

.status-completed {
    background: #d1fae5;
    color: #065f46;
}

.status-cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.status-unknown {
    background: #f3f4f6;
    color: #6b7280;
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

.btn-receive {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.btn-receive:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    transform: translateY(-1px);
}

.btn-approve {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

.btn-approve:hover {
    background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
    transform: translateY(-1px);
}

.header-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Transfer Detay</h2>
            <div class="header-actions">
                <?php if ($showNewRequestButton): ?>
                    <a href="TransferlerSO.php">
                        <button class="btn btn-primary">+ Yeni Talep Oluştur</button>
                    </a>
                <?php endif; ?>
                <?php if ($canReceive && $type === 'incoming'): ?>
                    <a href="Transferler-TeslimAl.php?docEntry=<?= urlencode($docEntry) ?><?= !empty($filterItemCode) ? '&itemCode=' . urlencode($filterItemCode) : '' ?><?= ($filterLineNum !== '') ? '&lineNum=' . urlencode($filterLineNum) : '' ?>">
                        <button class="btn btn-primary">✓ Teslim Al</button>
                    </a>
                <?php endif; ?>
                <?php if ($canApprove && $type === 'outgoing'): ?>
                    <a href="Transferler-Onayla.php?docEntry=<?= urlencode($docEntry) ?>&action=approve">
                        <button class="btn btn-approve">✓ Onayla</button>
                    </a>
                    <a href="Transferler-Onayla.php?docEntry=<?= urlencode($docEntry) ?>&action=reject">
                        <button class="btn" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">✗ İptal</button>
                    </a>
                <?php endif; ?>
                <button class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=<?= $type ?>'">← Geri Dön</button>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="detail-header">
                <div class="detail-title">
                    <h3>Transfer Talebi: <strong><?= htmlspecialchars($docEntry) ?></strong></h3>
                </div>
            </div>

            <div class="detail-card">
                <div class="detail-grid">
                    <!-- Sol Sütun -->
                    <div class="detail-column">
                        <div class="detail-item">
                            <label>Transfer No:</label>
                            <div class="detail-value"><?= htmlspecialchars($docEntry) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Talep Tarihi:</label>
                            <div class="detail-value"><?= htmlspecialchars($docDate) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Vade Tarihi:</label>
                            <div class="detail-value"><?= htmlspecialchars($dueDate) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Teslimat Belge No:</label>
                            <div class="detail-value">
                                <?php if ($outgoingStockTransferInfo): ?>
                                    <?= htmlspecialchars($outgoingStockTransferInfo['DocNum'] ?? $outgoingStockTransferInfo['DocEntry'] ?? '-') ?>
                                <?php elseif ($incomingStockTransferInfo): ?>
                                    <?= htmlspecialchars($incomingStockTransferInfo['DocNum'] ?? $incomingStockTransferInfo['DocEntry'] ?? '-') ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($numAtCard) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <label>Not:</label>
                            <div class="detail-value"><?= !empty($comments) && $comments !== '-' ? htmlspecialchars($comments) : 'Transfer nakil talebi' ?></div>
                        </div>
                    </div>
                    
                    <!-- Sağ Sütun -->
                    <div class="detail-column">
                        <div class="detail-item">
                            <label>Kaynak Depo:</label>
                            <div class="detail-value">
                                <?php if ($outgoingStockTransferInfo): ?>
                                    <?php
                                    $fromWhs = $outgoingStockTransferInfo['FromWarehouse'] ?? '';
                                    $fromWhsName = '';
                                    if (!empty($fromWhs)) {
                                        $fromWhsQuery = "Warehouses('{$fromWhs}')?\$select=WarehouseCode,WarehouseName";
                                        $fromWhsData = $sap->get($fromWhsQuery);
                                        $fromWhsName = $fromWhsData['response']['WarehouseName'] ?? '';
                                    }
                                    $fromWhsDisplay = $fromWhs;
                                    if (!empty($fromWhsName)) {
                                        $fromWhsDisplay = $fromWhs . ' / ' . $fromWhsName;
                                    }
                                    echo htmlspecialchars($fromWhsDisplay);
                                    ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($gonderSubeDisplay) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <label>Hedef Depo:</label>
                            <div class="detail-value">
                                <?php if ($outgoingStockTransferInfo): ?>
                                    <?php
                                    // İlk StockTransfer'in ToWarehouse'u (sevkiyat depo)
                                    $firstToWhs = $outgoingStockTransferInfo['ToWarehouse'] ?? $sevkiyatDepo;
                                    $firstToWhsName = '';
                                    if (!empty($firstToWhs)) {
                                        $firstToWhsQuery = "Warehouses('{$firstToWhs}')?\$select=WarehouseCode,WarehouseName";
                                        $firstToWhsData = $sap->get($firstToWhsQuery);
                                        $firstToWhsName = $firstToWhsData['response']['WarehouseName'] ?? '';
                                    }
                                    $firstToWhsDisplay = $firstToWhs;
                                    if (!empty($firstToWhsName)) {
                                        $firstToWhsDisplay = $firstToWhs . ' / ' . $firstToWhsName;
                                    }
                                    echo htmlspecialchars($firstToWhsDisplay);
                                    ?>
                                <?php elseif ($status == '4' && $anaDepo): ?>
                                    <?= $aliciSubeDisplay ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($aliciSubeDisplay) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <label>Durum:</label>
                            <div class="detail-value">
                                <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                            </div>
                        </div>
                        <?php if ($outgoingStockTransferInfo): ?>
                            <div class="detail-item">
                                <label>Sevk Tarihi:</label>
                                <div class="detail-value"><?= formatDate($outgoingStockTransferInfo['DocDate'] ?? '') ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($incomingStockTransferInfo): ?>
                            <div class="detail-item">
                                <label>Teslimat Tarihi:</label>
                                <div class="detail-value"><?= formatDate($incomingStockTransferInfo['DocDate'] ?? '') ?></div>
                            </div>
                            <?php 
                            // İlk StockTransfer bilgisini depo yönüne göre bul
                            // İlişki: Aynı U_ASB2B_QutMaster, ToWarehouse = sevkiyatDepo
                            if ($sevkiyatDepo && $incomingStockTransferInfo): 
                                $qutMaster = (int)($incomingStockTransferInfo['U_ASB2B_QutMaster'] ?? 0);
                                if ($qutMaster > 0) {
                                    $firstSTFilter = "U_ASB2B_QutMaster eq {$qutMaster} and ToWarehouse eq '{$sevkiyatDepo}'";
                                    $firstSTQuery = "StockTransfers?\$filter=" . urlencode($firstSTFilter) . "&\$orderby=DocEntry asc&\$top=1&\$select=DocEntry,DocNum,DocDate";
                                    $firstSTData = $sap->get($firstSTQuery);
                                    $firstSTList = $firstSTData['response']['value'] ?? [];
                                    if (!empty($firstSTList)):
                                        $firstSTInfo = $firstSTList[0];
                            ?>
                            <div class="detail-item">
                                <label>İlk StockTransfer (Sevkiyat):</label>
                                <div class="detail-value">
                                    DocEntry: <?= htmlspecialchars($firstSTInfo['DocEntry'] ?? '-') ?>, 
                                    DocNum: <?= htmlspecialchars($firstSTInfo['DocNum'] ?? '-') ?>, 
                                    Tarih: <?= formatDate($firstSTInfo['DocDate'] ?? '') ?>
                                </div>
                            </div>
                            <?php 
                                    endif;
                                }
                            endif; 
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($incomingStockTransferInfo): ?>
                <div class="section-title">Teslimat Bilgileri (İkinci Stok Nakil)</div>
                <div class="detail-card">
                    <div class="detail-grid">
                        <div class="detail-item">
                            <label>Teslimat Belge No:</label>
                            <div class="detail-value"><?= htmlspecialchars($incomingStockTransferInfo['DocNum'] ?? $incomingStockTransferInfo['DocEntry'] ?? '-') ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Gönderen Depo:</label>
                            <div class="detail-value">
                                <?php
                                $fromWhs = $incomingStockTransferInfo['FromWarehouse'] ?? '';
                                $fromWhsName = '';
                                if (!empty($fromWhs)) {
                                    $fromWhsQuery = "Warehouses('{$fromWhs}')?\$select=WarehouseCode,WarehouseName";
                                    $fromWhsData = $sap->get($fromWhsQuery);
                                    $fromWhsName = $fromWhsData['response']['WarehouseName'] ?? '';
                                }
                                $fromWhsDisplay = $fromWhs;
                                if (!empty($fromWhsName)) {
                                    $fromWhsDisplay = $fromWhs . ' / ' . $fromWhsName;
                                }
                                echo htmlspecialchars($fromWhsDisplay);
                                ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <label>Teslimat Tarihi:</label>
                            <div class="detail-value"><?= formatDate($incomingStockTransferInfo['DocDate'] ?? '') ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Gittiği Depo:</label>
                            <div class="detail-value">
                                <?php
                                $toWhs = $incomingStockTransferInfo['ToWarehouse'] ?? '';
                                $toWhsName = '';
                                if (!empty($toWhs)) {
                                    $toWhsQuery = "Warehouses('{$toWhs}')?\$select=WarehouseCode,WarehouseName";
                                    $toWhsData = $sap->get($toWhsQuery);
                                    $toWhsName = $toWhsData['response']['WarehouseName'] ?? '';
                                }
                                $toWhsDisplay = $toWhs;
                                if (!empty($toWhsName)) {
                                    $toWhsDisplay = $toWhs . ' / ' . $toWhsName;
                                }
                                echo htmlspecialchars($toWhsDisplay);
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <section class="card">
                <div class="section-title">Transfer Detayı</div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kalem Numarası</th>
                            <th>Kalem Tanımı</th>
                            <th>Talep Miktarı</th>
                            <th>Sevk Miktarı</th>
                            <th>Teslimat Miktarı</th>
                            <th>Satır Durumu</th>
                            <?php if ($type === 'incoming' && $canReceive): ?>
                                <th style="text-align:center;">İşlem</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lines)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 2rem; color: #9ca3af;">Satır bulunamadı.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($lines as $line): 
                                $itemCode = $line['ItemCode'] ?? '';
                                $itemName = $line['ItemDescription'] ?? '';
                                $quantity = (float)($line['Quantity'] ?? 0);
                                $remaining = (float)($line['RemainingOpenQuantity'] ?? 0);
                                $baseQty = (float)($line['BaseQty'] ?? 1.0);
                                $uomCode = $line['UoMCode'] ?? 'AD';
                                
                                // Talep miktarı (InventoryTransferRequest'ten)
                                $talepMiktar = $quantity;
                                
                                // Sevk / Teslimat başlangıç
                                $sevkMiktar = 0;
                                $teslimatMiktar = 0;
                                
                                // Sevk miktarı: Hazırlanıyor (2), Sevk Edildi (3) ve Tamamlandı (4) durumlarında göster
                                if ($status == '2' || $status == '3' || $status == '4') {
                                    $sevkMiktar = $sevkMiktarMap[$itemCode] ?? 0;
                                }
                                
                                // Teslimat miktarı: Sadece Sevk Edildi (3) ve Tamamlandı (4) durumlarında göster
                                if ($status == '3' || $status == '4') {
                                    $teslimatMiktar = $teslimatMiktarMap[$itemCode] ?? 0;
                                }
                                
                                // Formatlama
                                $talepFormatted = formatQuantity($talepMiktar);
                                if ($talepMiktar > 0) {
                                    $talepDisplay = $talepFormatted . ' ' . htmlspecialchars($uomCode);
                                } else {
                                    $talepDisplay = '0';
                                }
                                
                                $sevkFormatted = formatQuantity($sevkMiktar);
                                if ($sevkMiktar > 0) {
                                    $sevkDisplay = $sevkFormatted . ' ' . htmlspecialchars($uomCode);
                                } else {
                                    $sevkDisplay = '0';
                                }
                                
                                $teslimatFormatted = formatQuantity($teslimatMiktar);
                                if ($teslimatMiktar > 0) {
                                    $teslimatDisplay = $teslimatFormatted . ' ' . htmlspecialchars($uomCode);
                                } else {
                                    $teslimatDisplay = '0';
                                }

                                // Satır durumu: teslimat >= talep ise Tamamlandı, değilse Sevk Edildi
                                $lineStatus = ($teslimatMiktar >= $talepMiktar && $talepMiktar > 0) ? 'Tamamlandı' : 'Sevk Edildi';
                                $lineStatusClass = $lineStatus === 'Tamamlandı' ? 'status-completed' : 'status-shipped';
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($itemCode) ?></td>
                                    <td><?= htmlspecialchars($itemName) ?></td>
                                    <td style="text-align: center;">
                                        <?= $talepDisplay ?>
                                        <div style="font-size: 0.7rem; color: #dc2626; margin-top: 2px;">
                                            DEBUG: <?= $talepMiktar ?> (quantity: <?= $quantity ?>)
                                        </div>
                                    </td>
                                    <td style="text-align: center;">
                                        <?= $sevkDisplay ?>
                                        <div style="font-size: 0.7rem; color: #dc2626; margin-top: 2px;">
                                            DEBUG: <?= $sevkMiktar ?> (map: <?= $sevkMiktarMap[$itemCode] ?? 'YOK' ?>, remaining: <?= $remaining ?>, status: <?= $status ?>)
                                        </div>
                                    </td>
                                    <td style="text-align: center;">
                                        <?= $teslimatDisplay ?>
                                        <div style="font-size: 0.7rem; color: #dc2626; margin-top: 2px;">
                                            DEBUG: <?= $teslimatMiktar ?> (map: <?= $teslimatMiktarMap[$itemCode] ?? 'YOK' ?>)
                                        </div>
                                    </td>
                                    <td style="text-align:center;">
                                        <span class="status-badge <?= $lineStatusClass ?>"><?= htmlspecialchars($lineStatus) ?></span>
                                    </td>
                                    <?php if ($type === 'incoming' && $canReceive): ?>
                                        <td style="text-align:center;">
                                            <a href="Transferler-TeslimAl.php?docEntry=<?= urlencode($docEntry) ?>&itemCode=<?= urlencode($itemCode) ?>&lineNum=<?= urlencode($line['LineNum'] ?? 0) ?>" class="btn btn-primary" style="padding: 6px 10px; font-size: 12px;">✓ Teslim Al</a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>
</body>
</html>