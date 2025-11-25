<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// Session'dan bilgileri al
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? '';

// Fire & Zayi deposunu bul (U_ASB2B_MAIN='3' veya özel bir UDF ile işaretlenmiş olabilir)
$fireZayiWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '3'";
$fireZayiWarehouseQuery = "Warehouses?\$filter=" . urlencode($fireZayiWarehouseFilter);
$fireZayiWarehouseData = $sap->get($fireZayiWarehouseQuery);
$fireZayiWarehouses = $fireZayiWarehouseData['response']['value'] ?? [];
$fireZayiWarehouse = !empty($fireZayiWarehouses) ? $fireZayiWarehouses[0]['WarehouseCode'] : null;

// Eğer U_ASB2B_MAIN='3' ile bulunamazsa, alternatif olarak U_ASB2B_FIREZAYI='Y' gibi bir UDF ile arayabiliriz
if (empty($fireZayiWarehouse)) {
    // Alternatif: U_ASB2B_FIREZAYI='Y' ile işaretlenmiş depo
    $fireZayiWarehouseFilter2 = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_FIREZAYI eq 'Y'";
    $fireZayiWarehouseQuery2 = "Warehouses?\$filter=" . urlencode($fireZayiWarehouseFilter2);
    $fireZayiWarehouseData2 = $sap->get($fireZayiWarehouseQuery2);
    $fireZayiWarehouses2 = $fireZayiWarehouseData2['response']['value'] ?? [];
    $fireZayiWarehouse = !empty($fireZayiWarehouses2) ? $fireZayiWarehouses2[0]['WarehouseCode'] : null;
}

$requestNo   = $_GET['requestNo'] ?? '';
$orderNo     = $_GET['orderNo'] ?? '';      // Eski parametre (geriye dönük uyumluluk için)
$orderNosParam = $_GET['orderNos'] ?? '';   // Yeni parametre (virgülle ayrılmış)

if (empty($requestNo)) {
    die("Talep numarası eksik.");
}

// Çoklu sipariş desteği: orderNos parametresini parse et
$orderNosArray = [];
if (!empty($orderNosParam)) {
    // Virgülle ayrılmış sipariş numaralarını parse et
    $orderNosArray = array_filter(array_map('trim', explode(',', $orderNosParam)));
} elseif (!empty($orderNo)) {
    // Eski parametre (geriye dönük uyumluluk)
    $orderNosArray = [trim($orderNo)];
}

if (empty($orderNosArray)) {
    die("Sipariş numarası eksik. Teslim almak için sipariş oluşturulmuş olmalıdır.");
}

// Yardımcı fonksiyonlar
function isReceivableStatus($status) {
    $s = trim((string)$status);
    return in_array($s, ['2', '3'], true);
}

function getStatusText($status) {
    $statusMap = [
        '0' => 'Sipariş yok',
        '1' => 'Onay bekleniyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal edildi'
    ];
    return $statusMap[(string)$status] ?? 'Bilinmiyor';
}

function getStatusClass($status) {
    $classMap = [
        '0' => 'status-unknown',
        '1' => 'status-pending',
        '2' => 'status-processing',
        '3' => 'status-shipped',
        '4' => 'status-completed',
        '5' => 'status-cancelled'
    ];
    return $classMap[(string)$status] ?? 'status-unknown';
}

// -----------------------------
// Çoklu sipariş verisini hazırla
// -----------------------------
$allOrders  = [];
$allLines   = [];
$orderInfo  = null;
$orderStatus = null;
$debugInfo  = []; // Kullanılırsa notice yememek için

if (!empty($orderNosArray)) {
    // Çoklu sipariş: Tüm sipariş numaralarını işle
    foreach ($orderNosArray as $orderNoItem) {
        if (empty($orderNoItem)) continue;

        $orderQuery = 'PurchaseOrders(' . intval($orderNoItem) . ')';
        $orderData  = $sap->get($orderQuery);
        $orderInfoTemp = $orderData['response'] ?? [];

        if (empty($orderInfoTemp)) {
            continue; // Sipariş bulunamadı, devam et
        }

        $orderDocEntry = $orderInfoTemp['DocEntry'] ?? intval($orderNoItem);

        // Durum bilgisini çek (view üzerinden)
        if (!empty($uAsOwnr) && !empty($branch)) {
            $orderNoInt  = intval($orderNoItem);
            $viewFilter  = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_ORNO eq {$orderNoInt}";
            $viewQuery   = 'view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=' . urlencode($viewFilter);
            $viewData    = $sap->get($viewQuery);
            $viewRows    = $viewData['response']['value'] ?? [];

            if (!empty($viewRows)) {
                $orderStatusTemp = $viewRows[0]['U_ASB2B_STATUS'] ?? null;
                if (isReceivableStatus($orderStatusTemp)) {
                    $allOrders[] = [
                        'OrderNo' => $orderNoItem,
                        'Status'  => $orderStatusTemp
                    ];
                }
            }
        }

        // Satırları çek
        $linesQuery = "PurchaseOrders({$orderDocEntry})/DocumentLines";
        $linesData  = $sap->get($linesQuery);

        if (($linesData['status'] ?? 0) == 200 && isset($linesData['response'])) {
            $resp  = $linesData['response'];
            $lines = [];

            if (isset($resp['value']) && is_array($resp['value'])) {
                $lines = $resp['value'];
            } elseif (isset($resp['DocumentLines']) && is_array($resp['DocumentLines'])) {
                $lines = $resp['DocumentLines'];
            }

            // Her satıra sipariş bilgisi ekle
            foreach ($lines as $line) {
                $line['_OrderNo']      = $orderNoItem;
                $line['_OrderDocEntry'] = $orderDocEntry;
                $line['_CardCode']     = $orderInfoTemp['CardCode'] ?? '';
                $allLines[] = $line;
            }
        }

        // İlk sipariş bilgisini ana orderInfo olarak kullan
        if ($orderInfo === null) {
            $orderInfo   = $orderInfoTemp;
            $orderStatus = $orderStatusTemp ?? null;
        }
    }
} else {
    // Güvenlik için, normalde buraya düşmemeli
    die("Sipariş bilgisi alınamadı.");
}

// -----------------------------
// Genel değişkenler
// -----------------------------
$errorMsg   = '';
$warningMsg = '';

$cardCode        = $orderInfo['CardCode'] ?? '';
$cardName        = $orderInfo['CardName'] ?? '';
$orderDocEntry   = $orderInfo['DocEntry'] ?? null;
$orderDocNum     = $orderInfo['DocNum'] ?? null;
$orderDocDate    = $orderInfo['DocDate'] ?? '';
$orderDocDueDate = $orderInfo['DocDueDate'] ?? '';
$defaultIrsaliyeNo = $orderInfo['U_ASB2B_NumAtCard'] ?? '';

$lines     = $allLines;
$isClosed  = false;
$canReceive = true;
$docStatus = null;

// -----------------------------
// POST işlemi: PurchaseDeliveryNotes
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'teslim_al') {
    header('Content-Type: application/json');

    $teslimatNo     = trim($_POST['teslimat_no'] ?? '');
    $teslimatTarihi = $_POST['teslimat_tarihi'] ?? date('Y-m-d');

    if (empty($teslimatNo)) {
        echo json_encode(['success' => false, 'message' => 'Teslimat belge numarası zorunludur!']);
        exit;
    }

    if (empty($lines)) {
        echo json_encode(['success' => false, 'message' => 'Sipariş satırları bulunamadı!']);
        exit;
    }

    // Her sipariş için ayrı teslimat oluştur
    $ordersData = [];

    foreach ($lines as $index => $lineData) {
        $orderNoKey       = $lineData['_OrderNo'] ?? '';
        $orderDocEntryKey = $lineData['_OrderDocEntry'] ?? '';
        $cardCodeLine     = $lineData['_CardCode'] ?? '';
        $itemCode         = $lineData['ItemCode'] ?? '';

        if (empty($orderNoKey) || empty($orderDocEntryKey) || empty($cardCodeLine)) {
            continue;
        }

        if (!isset($ordersData[$orderNoKey])) {
            $ordersData[$orderNoKey] = [
                'CardCode'      => $cardCodeLine,
                'OrderDocEntry' => $orderDocEntryKey,
                'DocumentLines' => []
            ];
        }

        // Formdan gelen bilgiler - $index ile eşleştir
        $deliveryQty = floatval($_POST['irsaliye_qty'][$index] ?? 0);
        $eksikFazlaQty = floatval($_POST['eksik_fazla'][$index] ?? 0);
        $kusurluQty = floatval($_POST['kusurlu'][$index] ?? 0);
        if ($kusurluQty < 0) $kusurluQty = 0;
        $not = trim($_POST['not'][$index] ?? '');
        
        // Fiziksel miktar = İrsaliye + Eksik/Fazla
        $fizikselMiktar = $deliveryQty + $eksikFazlaQty;
        if ($fizikselMiktar < 0) $fizikselMiktar = 0;
        
        // Kusurlu miktar fiziksel miktarı aşamaz
        if ($kusurluQty > $fizikselMiktar) {
            $kusurluQty = $fizikselMiktar;
        }
        
        // Normal transfer miktarı = Fiziksel - Kusurlu
        $normalTransferMiktar = $fizikselMiktar - $kusurluQty;
        if ($normalTransferMiktar < 0) $normalTransferMiktar = 0;

        // Normal line (Fiziksel - Kusurlu miktar)
        if ($normalTransferMiktar > 0) {
            $lineNum = $lineData['LineNum'] ?? 0;
            $linePayload = [
                'BaseType' => 22, // Purchase Order
                'BaseEntry' => intval($orderDocEntryKey),
                'BaseLine'  => intval($lineNum),
                'Quantity'  => $normalTransferMiktar
            ];
            
            // Normal line'da eksik/fazla ve kusurlu miktarlar için ayrı line'lar oluşturulacak
            // Bu yüzden normal line'da U_ASB2B_Damaged '-' olarak kalır
            $linePayload['U_ASB2B_Damaged'] = '-';
            
            // Normal line'da eksik/fazla ve kusurlu miktarlar için ayrı line'lar oluşturulacak
            // Normal line'ın Comments'ine eksik/fazla bilgisini de ekliyoruz
            $commentsParts = [];
            if (!empty($not)) {
                $commentsParts[] = $not;
            }
            
            // Eksik/Fazla miktar bilgisini Comments'e ekle
            if ($eksikFazlaQty < 0) {
                $zayiMiktar = abs($eksikFazlaQty); // Mutlak değer
                $commentsParts[] = "EEksik: {$zayiMiktar} adet";
            } elseif ($eksikFazlaQty > 0) {
                $commentsParts[] = "Fazla: {$eksikFazlaQty} adet";
            }
            
            // Comments'i birleştir
            if (!empty($commentsParts)) {
                $linePayload['U_ASB2B_Comments'] = implode(' | ', $commentsParts);
            }
            
            $ordersData[$orderNoKey]['DocumentLines'][] = $linePayload;
        }
        
        // Fire & Zayi deposu bulunamazsa geçici olarak 200-KT-2 kullan
        $targetFireZayiWarehouse = $fireZayiWarehouse;
        if (empty($targetFireZayiWarehouse)) {
            $targetFireZayiWarehouse = '200-KT-2'; // Geçici olarak sabit değer
        }
        
        // Eksik miktar varsa → Ayrı line oluştur (Fire & Zayi deposuna - Zayi)
        if ($eksikFazlaQty < 0) {
            $zayiMiktar = abs($eksikFazlaQty); // Mutlak değer
            
            // Fire & Zayi deposuna transfer satırı (Zayi)
            // Bu line Purchase Order'dan bağımsız olduğu için BaseLine, BaseType, BaseEntry kullanmıyoruz
            $zayiLinePayload = [
                'ItemCode' => $itemCode, // Kalem kodu
                'Quantity'  => $zayiMiktar, // Zayi miktar
                'WarehouseCode' => $targetFireZayiWarehouse // Fire & Zayi deposu (200-KT-2)
            ];
            
            // Zayi satırı için UDF'ler
            $zayiLinePayload['U_ASB2B_LOST'] = '2'; // Zayi
            $zayiLinePayload['U_ASB2B_Damaged'] = 'E'; // Eksik
            
            // Zayi satırı Comments'i
            $zayiCommentsParts = [];
            if (!empty($not)) {
                $zayiCommentsParts[] = $not;
            }
            $zayiCommentsParts[] = "Eksik: {$zayiMiktar} adet";
            $zayiCommentsParts[] = 'Fire & Zayi';
            
            $zayiLinePayload['U_ASB2B_Comments'] = implode(' | ', $zayiCommentsParts);
            
            $ordersData[$orderNoKey]['DocumentLines'][] = $zayiLinePayload;
        }
        
        // Fazla miktar varsa → Ayrı line oluştur (Fire & Zayi deposuna - Fire)
        if ($eksikFazlaQty > 0) {
            // Fire & Zayi deposuna transfer satırı (Fire)
            // Bu line Purchase Order'dan bağımsız olduğu için BaseLine, BaseType, BaseEntry kullanmıyoruz
            $fireLinePayload = [
                'ItemCode' => $itemCode, // Kalem kodu
                'Quantity'  => $eksikFazlaQty, // Fazla miktar
                'WarehouseCode' => $targetFireZayiWarehouse // Fire & Zayi deposu (200-KT-2)
            ];
            
            // Fire satırı için UDF'ler
            $fireLinePayload['U_ASB2B_LOST'] = '1'; // Fire
            
            // Fire satırı Comments'i
            $fireCommentsParts = [];
            if (!empty($not)) {
                $fireCommentsParts[] = $not;
            }
            $fireCommentsParts[] = "Fazla: {$eksikFazlaQty} adet";
            $fireCommentsParts[] = 'Fire & Zayi';
            
            $fireLinePayload['U_ASB2B_Comments'] = implode(' | ', $fireCommentsParts);
            
            $ordersData[$orderNoKey]['DocumentLines'][] = $fireLinePayload;
        }
        
        // Kusurlu miktar varsa → Ayrı line oluştur (Fire & Zayi deposuna)
        if ($kusurluQty > 0) {
            // Fire & Zayi deposuna transfer satırı
            // Bu line Purchase Order'dan bağımsız olduğu için BaseLine, BaseType, BaseEntry kullanmıyoruz
            $fireZayiLinePayload = [
                'ItemCode' => $itemCode, // Kalem kodu
                'Quantity'  => $kusurluQty, // Kusurlu miktar
                'WarehouseCode' => $targetFireZayiWarehouse // Fire & Zayi deposu (200-KT-2)
            ];
            
            // Fire & Zayi satırı için UDF'ler
            $fireZayiLinePayload['U_ASB2B_Damaged'] = 'K'; // Kusurlu
            
            // Fire & Zayi satırı Comments'i
            $fireZayiCommentsParts = [];
            if (!empty($not)) {
                $fireZayiCommentsParts[] = $not;
            }
            $fireZayiCommentsParts[] = "Kusurlu: {$kusurluQty} adet";
            $fireZayiCommentsParts[] = 'Fire & Zayi';
            
            $fireZayiLinePayload['U_ASB2B_Comments'] = implode(' | ', $fireZayiCommentsParts);
            
            $ordersData[$orderNoKey]['DocumentLines'][] = $fireZayiLinePayload;
        }
    }

    // Her sipariş için ayrı POST yap
    $successCount  = 0;
    $errorMessages = [];

    foreach ($ordersData as $orderNoKey => $orderData) {
        if (empty($orderData['DocumentLines'])) {
            continue;
        }

        $payload = [
            'CardCode'        => $orderData['CardCode'],
            'U_ASB2B_NumAtCard' => $teslimatNo,
            'DocumentLines'   => $orderData['DocumentLines']
        ];

        $result = $sap->post('PurchaseDeliveryNotes', $payload);

        if (($result['status'] ?? 0) == 200 || ($result['status'] ?? 0) == 201) {
            $successCount++;
        } else {
            $errorMessages[] = "Sipariş {$orderNoKey}: " . json_encode($result['response'] ?? 'Bilinmeyen hata');
        }
    }

    if ($successCount > 0 && empty($errorMessages)) {
        // Başarılı: DisTedarik sayfasına yönlendir
        $_SESSION['success_message'] = "{$successCount} sipariş başarıyla teslim alındı!";
        header('Location: DisTedarik.php');
        exit;
    } elseif ($successCount > 0) {
        // Kısmen başarılı: Uyarı mesajı ile yönlendir
        $_SESSION['warning_message'] = "{$successCount} sipariş başarıyla teslim alındı, ancak bazı hatalar var.";
        if (!empty($errorMessages)) {
            $_SESSION['error_details'] = $errorMessages;
        }
        header('Location: DisTedarik.php');
        exit;
    } else {
        // Başarısız: Hata mesajı ile yönlendir
        $_SESSION['error_message'] = 'Teslim alma işlemi başarısız!';
        if (!empty($errorMessages)) {
            $_SESSION['error_details'] = $errorMessages;
        }
        header('Location: DisTedarik.php');
        exit;
    }
}

// Header’da göstermek için sipariş text’i
$orderNoHeaderText = !empty($orderNosArray) ? implode(', ', $orderNosArray) : $orderNo;
?> 

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dış Tedarik Teslim Al - MINOA</title>
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

/* Main content - adjusted for sidebar */
.main-content {
    width: 100%;
    background: whitesmoke;
    padding: 0;
    min-height: 100vh;
}

        /* Modern page header matching AnaDepoSO style */
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

        /* Modern button styles */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #3b82f6;
            border: 2px solid #3b82f6;
        }

        .btn-secondary:hover {
            background: #eff6ff;
            transform: translateY(-2px);
        }

        /* Modern card styling */
.content-wrapper {
    padding: 24px 32px;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 2rem;
            margin: 24px 32px 2rem 32px;
        }

        /* Modern alert styling */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin: 24px 32px 1.5rem 32px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-danger {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
        }

        /* Modern info box */
        .info-box {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border: 1px solid #3b82f6;
            border-radius: 8px;
            padding: 1rem 1.5rem;
            margin: 0 32px 1.5rem 32px;
            color: #1e40af;
        }

        .info-box strong {
            font-weight: 600;
        }

        /* Modern table styling */
.data-table {
    width: 100%;
    border-collapse: collapse;
            margin-top: 1rem;
        }

        .data-table thead {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
}

.data-table th {
            padding: 1rem;
    text-align: left;
    font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table th:nth-child(4),
        .data-table th:nth-child(5),
        .data-table th:nth-child(6),
        .data-table th:nth-child(7),
        .data-table th:nth-child(8) {
            text-align: center;
        }

        .data-table tbody tr {
    border-bottom: 1px solid #e5e7eb;
            transition: background-color 0.2s;
        }

        .data-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .data-table td {
            padding: 1rem;
            font-size: 0.95rem;
        }

        .data-table td:nth-child(4),
        .data-table td:nth-child(5),
        .data-table td:nth-child(6),
        .data-table td:nth-child(7),
        .data-table td:nth-child(8) {
            text-align: center;
        }

        .table-cell-center {
            text-align: center;
        }

        /* Modern quantity controls */
        .quantity-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            justify-content: center;
        }

        .qty-btn {
    padding: 0.5rem 1rem;
            border: 2px solid #3b82f6;
            background: white;
            color: #3b82f6;
    border-radius: 6px;
    cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            min-width: 40px;
            transition: all 0.2s;
        }

        .qty-btn:hover {
    background: #3b82f6;
    color: white;
            transform: scale(1.05);
        }

        .qty-btn:active {
            transform: scale(0.95);
        }

        .copy-arrow-btn {
            min-width: 35px;
            padding: 0.5rem;
            font-size: 1.2rem;
            font-weight: bold;
            cursor: pointer;
        }

        .copy-arrow-btn:hover:not(:disabled) {
            background: #3b82f6;
    color: white;
            transform: scale(1.1);
        }

        .copy-arrow-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .qty-input {
            width: 100px;
            text-align: center;
            padding: 0.5rem;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }

        .qty-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .qty-input[readonly] {
            background-color: #f3f4f6;
            color: #6b7280;
        }
        
        /* Eksik/Fazla miktar alanı için cebirsel gösterim */
        input[name^="eksik_fazla"] {
            font-weight: 500;
        }
        
        .eksik-fazla-negatif {
            color: #dc2626 !important; /* Negatif değerler için kırmızı */
        }
        
        .eksik-fazla-pozitif {
            color: #16a34a !important; /* Pozitif değerler için yeşil */
        }
        
        .eksik-fazla-sifir {
            color: #6b7280 !important; /* Sıfır için gri */
        }

        .qty-input-small {
            width: 80px;
        }

        .notes-textarea {
            width: 100%;
            min-width: 150px;
            padding: 0.5rem;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.875rem;
            resize: vertical;
            font-family: inherit;
            transition: border-color 0.2s;
        }

        .notes-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .file-input {
            font-size: 0.875rem;
            padding: 0.25rem;
        }

        .form-group {
    margin-bottom: 1.5rem;
}

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }

        .form-group input {
            width: 100%;
            padding: 0.5rem;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* Form actions styling */
        .form-actions {
            margin-top: 2rem;
            text-align: right;
    display: flex;
    gap: 1rem;
            justify-content: flex-end;
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
        <h2>Teslim Al - Talep No: <?= htmlspecialchars($requestNo) ?> | Sipariş No: <?= htmlspecialchars($orderNoHeaderText) ?></h2>
        <button class="btn btn-secondary" onclick="window.location.href='DisTedarik.php'">← Geri Dön</button>
        </header>

    <?php if ($warningMsg): ?>
        <div class="card" style="background: #fef3c7; border: 2px solid #f59e0b; margin-bottom: 1.5rem;">
            <p style="color: #92400e; font-weight: 600; margin: 0;"><?= htmlspecialchars($warningMsg) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($errorMsg): ?>
        <div class="card" style="background: #fee2e2; border: 2px solid #dc2626; margin-bottom: 1.5rem;">
            <p style="color: #991b1b; font-weight: 600; margin: 0;"><?= htmlspecialchars($errorMsg) ?></p>
                    </div>
    <?php endif; ?>

    <?php if (empty($lines)): ?>
        <div class="card">
            <p style="color: #ef4444; font-weight: 600; margin-bottom: 1rem;">⚠️ Satır bulunamadı veya sipariş oluşturulmamış!</p>
                    </div>
    <?php else: ?>

        <!-- Sipariş bilgi kartı -->
        <div class="card">
            <h3 style="margin-bottom: 1rem; color: #1e40af;">Sipariş Bilgileri</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Talep No</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($requestNo) ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Sipariş No</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($orderDocEntry ?? $orderDocNum ?? $orderNoHeaderText) ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Tedarikçi</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($orderInfo['CardName'] ?? '-') ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Sipariş Tarihi</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= !empty($orderDocDate) ? date('d.m.Y', strtotime(substr($orderDocDate, 0, 10))) : '-' ?></div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Tahmini Teslimat</div>
                    <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= !empty($orderDocDueDate) ? date('d.m.Y', strtotime(substr($orderDocDueDate, 0, 10))) : '-' ?></div>
                </div>
                    </div>
                </div>

        <form method="POST" action="" onsubmit="return validateForm()">
            <input type="hidden" name="action" value="teslim_al">

            <div class="card">
                <div class="form-group">
                    <label>Teslimat Numarası (İrsaliye No) <span style="color: #dc2626;">*</span></label>
                    <input type="text"
                           name="teslimat_no"
                           id="teslimat_no"
                           value="<?= htmlspecialchars($defaultIrsaliyeNo) ?>"
                           placeholder="İrsaliye/Teslimat numarası"
                           required>
                    <?php if (!empty($defaultIrsaliyeNo)): ?>
                        <small style="color: #6b7280; display: block; margin-top: 0.25rem;">Varsayılan: <?= htmlspecialchars($defaultIrsaliyeNo) ?></small>
                    <?php else: ?>
                        <small style="color: #dc2626; display: block; margin-top: 0.25rem;">⚠️ Bu alan zorunludur!</small>
                    <?php endif; ?>
                    </div>
                </div>

            <div class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Sipariş No</th>
                            <th>Kalem Kodu</th>
                            <th>Kalem Tanımı</th>
                            <th>Sipariş Miktarı</th>
                            <th>İrsaliye Miktarı</th>
                            <th>Eksik/Fazla Miktar</th>
                            <th>Kusurlu Miktar</th>
                            <th>Fiziksel</th>
                            <th>Not</th>
                            <th>Görsel</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lines as $index => $line):
                        $lineOrderNo = $line['_OrderNo'] ?? '-';
                        $orderQuantity    = floatval($line['Quantity'] ?? 0); // PurchaseOrder'dan gelen sipariş miktarı
                        $requestedQuantity = floatval($line['RequestedQuantity'] ?? $orderQuantity);
                        $remainingQty     = floatval($line['RemainingOpenQuantity'] ?? $line['OpenQuantity'] ?? 0);
                        $isLineDisabled   = $line['IsDisabled'] ?? ($remainingQty <= 0 || $isClosed);
                        $disabledAttr     = $isLineDisabled ? 'disabled' : '';
                        $disabledStyle    = $isLineDisabled ? 'background: #f3f4f6; color: #9ca3af; cursor: not-allowed;' : '';
                        $rowStyle         = $isLineDisabled ? 'background: #f9fafb; opacity: 0.7;' : '';

                        $irsaliyeQtyInputAttr = '';
                        if (!$isLineDisabled && $remainingQty > 0) {
                            $irsaliyeQtyInputAttr = "data-remaining-qty='{$remainingQty}' oninput='checkRemainingQty({$index}, {$remainingQty})'";
                        }

                        $quantityDisplay = $requestedQuantity;
                        $quantityTooltip = '';
                        if (abs($requestedQuantity - $orderQuantity) > 0.01) {
                            $quantityTooltip = "Talep: {$requestedQuantity} | Sipariş: {$orderQuantity}";
                        }
                    ?>
                        <tr style="<?= $rowStyle ?>">
                            <td style="font-weight: 600; color: #1e40af;"><?= htmlspecialchars($lineOrderNo) ?></td>
                            <td><?= htmlspecialchars($line['ItemCode'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($line['ItemDescription'] ?? '-') ?></td>
                            <td>
                                <div style="display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                                    <input type="number"
                                           id="siparis_<?= $index ?>"
                                           value="<?= htmlspecialchars($quantityDisplay) ?>"
                                           readonly
                                           step="0.01"
                                           class="qty-input"
                                           style="<?= $disabledStyle ?>"
                                           title="<?= htmlspecialchars($quantityTooltip) ?>">
                                    <button type="button" 
                                            class="qty-btn copy-arrow-btn" 
                                            onclick="copySiparisToIrsaliye(<?= $index ?>, <?= $quantityDisplay ?>, <?= $remainingQty ?>);" 
                                            <?= $disabledAttr ?> 
                                            title="Sipariş miktarını irsaliye miktarına kopyala">
                                        →
                                    </button>
                                </div>
                                <?php if (!empty($quantityTooltip)): ?>
                                    <small style="display: block; color: #6b7280; font-size: 0.75rem; margin-top: 0.25rem;">
                                        Sipariş: <?= $orderQuantity ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="quantity-controls">
                                    <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'irsaliye', -1); checkRemainingQty(<?= $index ?>, <?= $remainingQty ?>);" <?= $disabledAttr ?> style="<?= $disabledStyle ?>">-</button>
                                    <input type="number"
                                           name="irsaliye_qty[<?= $index ?>]"
                                           id="irsaliye_<?= $index ?>"
                                           value=""
                                           min="0"
                                           step="0.01"
                                           class="qty-input"
                                           placeholder="0"
                                           <?= $disabledAttr ?>
                                           <?= $irsaliyeQtyInputAttr ?>
                                           style="<?= $disabledStyle ?>">
                                    <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'irsaliye', 1); checkRemainingQty(<?= $index ?>, <?= $remainingQty ?>);" <?= $disabledAttr ?> style="<?= $disabledStyle ?>">+</button>
                                </div>
                                <?php if ($isLineDisabled): ?>
                                    <small style="color: #dc2626; display: block; margin-top: 0.25rem;">
                                        <?= $isClosed ? 'Sipariş kapalı' : '' ?>
                                    </small>
                                <?php else: ?>
                                    <small id="warning_<?= $index ?>" style="color: #dc2626; display: none; margin-top: 0.25rem; font-weight: 600;">
                                        ⚠️ Bu miktar satırı kapatacak! (Kalan: <?= $remainingQty ?>)
                                    </small>
                                    <small id="info_<?= $index ?>" style="color: #059669; display: block; margin-top: 0.25rem;">
                                        Kalan: <?= $remainingQty ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="quantity-controls">
                                    <button type="button" class="qty-btn" onclick="changeEksikFazla(<?= $index ?>, -1)" <?= $disabledAttr ?> style="<?= $disabledStyle ?>">-</button>
                                    <input type="number"
                                           name="eksik_fazla[<?= $index ?>]"
                                           id="eksik_<?= $index ?>"
                                           value="0"
                                           step="0.01"
                                           class="qty-input"
                                           onchange="calculatePhysical(<?= $index ?>)"
                                           <?= $disabledAttr ?>
                                           style="<?= $disabledStyle ?>">
                                    <button type="button" class="qty-btn" onclick="changeEksikFazla(<?= $index ?>, 1)" <?= $disabledAttr ?> style="<?= $disabledStyle ?>">+</button>
                                </div>
                            </td>
                            <td>
                                <div class="quantity-controls">
                                    <button type="button" class="qty-btn" onclick="changeKusurlu(<?= $index ?>, -1)" <?= $disabledAttr ?> style="<?= $disabledStyle ?>">-</button>
                                    <input type="number"
                                           name="kusurlu[<?= $index ?>]"
                                           id="kusurlu_<?= $index ?>"
                                           value="0"
                                           min="0"
                                           step="0.01"
                                           class="qty-input"
                                           onchange="calculatePhysical(<?= $index ?>)"
                                           <?= $disabledAttr ?>
                                           style="<?= $disabledStyle ?>">
                                    <button type="button" class="qty-btn" onclick="changeKusurlu(<?= $index ?>, 1)" <?= $disabledAttr ?> style="<?= $disabledStyle ?>">+</button>
                                </div>
                            </td>
                            <td class="table-cell-center">
                                <div style="display: flex; align-items: center; justify-content: center; gap: 4px;">
                                    <input type="text"
                                           id="fiziksel_<?= $index ?>"
                                           value="0"
                                           readonly
                                           class="qty-input"
                                           style="<?= $disabledStyle ?>">
                                </div>
                            </td>
                            <td>
                                <input type="text"
                                       name="not[<?= $index ?>]"
                                       placeholder="Not"
                                       style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; <?= $disabledStyle ?>"
                                       <?= $disabledAttr ?>>
                            </td>
                            <td>
                                <input type="file"
                                       name="gorsel[<?= $index ?>]"
                                       accept="image/*"
                                       style="font-size: 0.75rem;"
                                       <?= $disabledAttr ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='DisTedarik.php'">İptal</button>
                    <button type="submit" class="btn btn-primary" <?= !$canReceive ? 'disabled' : '' ?> style="<?= !$canReceive ? 'background: #9ca3af; cursor: not-allowed;' : '' ?>">
                        ✓ Teslim Al / Onayla
                    </button>
                    <?php if (!$canReceive): ?>
                        <small style="display: block; color: #dc2626; margin-top: 0.5rem;">
                            <?= $isClosed ? 'Sipariş kapalı olduğu için teslim alma yapılamaz.' : 'Teslim alma yapılamaz.' ?>
                        </small>
                    <?php endif; ?>
                </div>
                </div>
        </form>
                <?php endif; ?>
    </main>

    <script>
// Sayfa yüklendiğinde fiziksel miktarları hesapla
document.addEventListener('DOMContentLoaded', function() {
    const eksikFazlaInputs = document.querySelectorAll('input[name^="eksik_fazla"]');
    eksikFazlaInputs.forEach(function(input) {
        const index = input.id.replace('eksik_', '');
        updateEksikFazlaColor(input);
        calculatePhysical(parseInt(index));
    });
});

function changeQuantity(index, type, delta) {
    const input = document.getElementById(type + '_' + index);
    if (!input) return;

    let value = parseFloat(input.value) || 0;
    value += delta;
    if (value < 0) value = 0;
    input.value = value;
    
    if (type === 'irsaliye') {
        calculatePhysical(index);
        checkRemainingQty(index, parseFloat(input.getAttribute('data-remaining-qty')) || 0);
    }
}

// Sipariş miktarını irsaliye miktarına kopyala
function copySiparisToIrsaliye(index, siparisMiktari, remainingQty) {
    const irsaliyeInput = document.getElementById('irsaliye_' + index);
    if (!irsaliyeInput || irsaliyeInput.disabled) return;
    
    irsaliyeInput.value = siparisMiktari;
    calculatePhysical(index);
    checkRemainingQty(index, remainingQty);
}

// Eksik/Fazla miktar değiştirme (cebirsel - negatif/pozitif olabilir)
function changeEksikFazla(index, delta) {
    const input = document.getElementById('eksik_' + index);
    if (!input) return;
    
    let value = parseFloat(input.value) || 0;
    value += delta;
    input.value = value;
    updateEksikFazlaColor(input);
    calculatePhysical(index);
}

// Eksik/Fazla miktar alanının rengini güncelle
function updateEksikFazlaColor(input) {
    if (!input) return;
    const value = parseFloat(input.value) || 0;
    input.classList.remove('eksik-fazla-negatif', 'eksik-fazla-pozitif', 'eksik-fazla-sifir');
    
    if (value < 0) {
        input.classList.add('eksik-fazla-negatif');
    } else if (value > 0) {
        input.classList.add('eksik-fazla-pozitif');
    } else {
        input.classList.add('eksik-fazla-sifir');
    }
}

// Kusurlu miktar değiştirme (min 0, max fiziksel miktar)
function changeKusurlu(index, delta) {
    const input = document.getElementById('kusurlu_' + index);
    if (!input) return;
    
    // Önce fiziksel miktarı hesapla
    const irsaliyeInput = document.getElementById('irsaliye_' + index);
    const eksikFazlaInput = document.getElementById('eksik_' + index);
    if (!irsaliyeInput || !eksikFazlaInput) return;
    
    const irsaliye = parseFloat(irsaliyeInput.value) || 0;
    const eksikFazla = parseFloat(eksikFazlaInput.value) || 0;
    const fizikselMiktar = Math.max(0, irsaliye + eksikFazla);
    
    let value = parseFloat(input.value) || 0;
    value += delta;
    if (value < 0) value = 0;
    // Kusurlu miktar fiziksel miktarı aşamaz
    if (value > fizikselMiktar) value = fizikselMiktar;
    input.value = value;
    calculatePhysical(index);
}

// Fiziksel miktar hesaplama: İrsaliye + EksikFazla
function calculatePhysical(index) {
    const irsaliyeInput = document.getElementById('irsaliye_' + index);
    const eksikFazlaInput = document.getElementById('eksik_' + index);
    const kusurluInput = document.getElementById('kusurlu_' + index);
    const fizikselInput = document.getElementById('fiziksel_' + index);
    
    if (!irsaliyeInput || !eksikFazlaInput || !kusurluInput || !fizikselInput) return;
    
    const irsaliye = parseFloat(irsaliyeInput.value) || 0;
    const eksikFazla = parseFloat(eksikFazlaInput.value) || 0;
    let kusurlu = parseFloat(kusurluInput.value) || 0;
    
    // Fiziksel = İrsaliye + EksikFazla
    let fiziksel = irsaliye + eksikFazla;
    
    // Fiziksel miktar negatif olamaz, 0 olabilir
    if (fiziksel < 0) {
        fiziksel = 0;
    }
    
    // Kusurlu miktar fiziksel miktarı aşamaz
    if (kusurlu > fiziksel) {
        kusurlu = fiziksel;
        kusurluInput.value = kusurlu;
    }
    
    // Format: Tam sayı ise küsurat gösterme, değilse virgül ile göster
    let formattedValue;
    if (fiziksel == Math.floor(fiziksel)) {
        formattedValue = Math.floor(fiziksel).toString();
    } else {
        formattedValue = fiziksel.toFixed(2).replace('.', ',').replace(/0+$/, '').replace(/,$/, '');
    }
    
    fizikselInput.value = formattedValue;
}

// Eksik/Fazla ve Kusurlu miktar değişikliklerinde fiziksel miktarı güncelle
document.addEventListener('DOMContentLoaded', function() {
    const eksikFazlaInputs = document.querySelectorAll('input[name^="eksik_fazla"]');
    const kusurluInputs = document.querySelectorAll('input[name^="kusurlu"]');
    const irsaliyeInputs = document.querySelectorAll('input[name^="irsaliye_qty"]');
    
    eksikFazlaInputs.forEach(function(input) {
        const index = input.id.replace('eksik_', '');
        updateEksikFazlaColor(input);
        input.addEventListener('input', () => {
            updateEksikFazlaColor(input);
            calculatePhysical(parseInt(index));
        });
        input.addEventListener('change', () => {
            updateEksikFazlaColor(input);
            calculatePhysical(parseInt(index));
        });
    });
    
    kusurluInputs.forEach(function(input) {
        const index = input.id.replace('kusurlu_', '');
        input.addEventListener('input', () => calculatePhysical(parseInt(index)));
        input.addEventListener('change', () => calculatePhysical(parseInt(index)));
    });
    
    irsaliyeInputs.forEach(function(input) {
        const index = input.id.replace('irsaliye_', '');
        input.addEventListener('input', () => calculatePhysical(parseInt(index)));
        input.addEventListener('change', () => calculatePhysical(parseInt(index)));
    });
});

// Girilen miktar kalan miktarı karşılıyor mu?
function checkRemainingQty(index, remainingQty) {
    const input   = document.getElementById('irsaliye_' + index);
    const warning = document.getElementById('warning_' + index);
    const info    = document.getElementById('info_' + index);

    if (!input || !warning || !info) return;

    const enteredQty = parseFloat(input.value) || 0;

    if (enteredQty > 0 && enteredQty >= remainingQty) {
        warning.style.display = 'block';
        info.style.display    = 'none';
        input.style.borderColor = '#dc2626';
        input.style.borderWidth = '2px';
    } else {
        warning.style.display = 'none';
        info.style.display    = 'block';
        input.style.borderColor = '';
        input.style.borderWidth = '';
    }
}

function validateForm() {
    const teslimatNoInput = document.querySelector('input[name="teslimat_no"]');
    const teslimatNo = teslimatNoInput ? teslimatNoInput.value.trim() : '';

    if (!teslimatNo) {
        alert('⚠️ Lütfen İrsaliye/Teslimat numarası girin!');
        if (teslimatNoInput) teslimatNoInput.focus();
        return false;
    }

    // Fiziksel miktar kontrolü - negatif olamaz
    let hasNegativeQty = false;
    const fizikselInputs = document.querySelectorAll('input[id^="fiziksel_"]');
    
    fizikselInputs.forEach(function(input) {
        const value = parseFloat(input.value) || 0;
        if (value < 0) {
            hasNegativeQty = true;
        }
    });
    
    if (hasNegativeQty) {
        alert('Fiziksel miktar negatif olamaz! Lütfen eksik/fazla ve kusurlu miktarları kontrol edin.');
        return false;
    }

    // Kusurlu miktar fiziksel miktarı geçemez kontrolü
    let hasInvalidKusurlu = false;
    const kusurluInputs = document.querySelectorAll('input[name^="kusurlu"]');
    
    kusurluInputs.forEach(function(input) {
        const index = input.id.replace('kusurlu_', '');
        const kusurlu = parseFloat(input.value) || 0;
        const fizikselInput = document.getElementById('fiziksel_' + index);
        
        if (fizikselInput) {
            const fiziksel = parseFloat(fizikselInput.value) || 0;
            if (kusurlu > fiziksel) {
                hasInvalidKusurlu = true;
            }
        }
    });
    
    if (hasInvalidKusurlu) {
        alert('Kusurlu miktar fiziksel miktarı geçemez! Lütfen kusurlu miktarları kontrol edin.');
        return false;
    }

    const irsaliyeInputs = document.querySelectorAll('input[name^="irsaliye_qty"]');
    let hasQuantity = false;
    let willCloseAnyLine = false;
    let warnings = [];

    irsaliyeInputs.forEach(input => {
        const qty = parseFloat(input.value) || 0;
        if (qty > 0) {
            hasQuantity = true;

            const remainingQty = parseFloat(input.getAttribute('data-remaining-qty')) || 0;
            if (remainingQty > 0 && qty >= remainingQty) {
                willCloseAnyLine = true;
                const itemCode = input.closest('tr').querySelector('td:first-child').textContent.trim();
                warnings.push(`Kalem ${itemCode}: Girilen miktar (${qty}) kalan miktarı (${remainingQty}) karşılıyor. Bu satır kapanacak.`);
            }
        }
    });

    if (!hasQuantity) {
        alert('Lütfen en az bir kalem için irsaliye miktarı girin!');
        return false;
    }

    if (willCloseAnyLine) {
        const message = '⚠️ UYARI:\n\n' + warnings.join('\n') +
            '\n\nBu işlem sonrasında bazı satırlar kapanacak. Devam etmek istiyor musunuz?';
        if (!confirm(message)) {
            return false;
        }
    }

    return true;
}
    </script>
</body>
</html>
