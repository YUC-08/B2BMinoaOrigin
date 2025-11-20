<?php
session_start();
if (!isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();


$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch = $_SESSION["WhsCode"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}


$doc = $_GET['doc'] ?? '';

if (empty($doc)) {
    header("Location: AnaDepo.php");
    exit;
}


$docQuery = "InventoryTransferRequests({$doc})";
$docData = $sap->get($docQuery);
$requestData = $docData['response'] ?? null;

if (!$requestData) {
    echo "Belge bulunamadı!";
    exit;
}


error_log("[TESLIM AL] DocEntry: {$doc}");
error_log("[TESLIM AL] RequestData keys: " . implode(', ', array_keys($requestData ?? [])));
error_log("[TESLIM AL] Has StockTransferLines key: " . (isset($requestData['StockTransferLines']) ? 'YES' : 'NO'));

$lines = $requestData['StockTransferLines'] ?? [];


error_log("[TESLIM AL] Lines count: " . count($lines));
if (empty($lines)) {
    error_log("[TESLIM AL] Lines is empty! Full requestData structure: " . print_r($requestData, true));
    $linesQuery = "InventoryTransferRequests({$doc})/StockTransferLines";
    $linesData = $sap->get($linesQuery);
    $linesResponse = $linesData['response'] ?? null;
    if ($linesResponse && isset($linesResponse['value'])) {
        $lines = $linesResponse['value'];
        error_log("[TESLIM AL] Lines found via direct query, count: " . count($lines));
    } elseif ($linesResponse && is_array($linesResponse)) {
        $lines = $linesResponse;
        error_log("[TESLIM AL] Lines found via direct query (array), count: " . count($lines));
    }
}
$fromWarehouse = $requestData['FromWarehouse'] ?? '';
$toWarehouse = $requestData['ToWarehouse'] ?? '';


$targetWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '1'";
$targetWarehouseQuery = "Warehouses?\$filter=" . urlencode($targetWarehouseFilter);
$targetWarehouseData = $sap->get($targetWarehouseQuery);
$targetWarehouses = $targetWarehouseData['response']['value'] ?? [];
$targetWarehouse = !empty($targetWarehouses) ? $targetWarehouses[0]['WarehouseCode'] : null;

if (empty($targetWarehouse)) {
    die("Hedef depo (U_ASB2B_MAIN=1) bulunamadı!");
}

// Sevk ve Teslimat miktarları için haritalama
$stockTransferLinesMap = []; // Ana deponun sevk ettiği miktar
$deliveryTransferLinesMap = []; // Kullanıcının teslim aldığı miktar (varsa)

// Sevk miktarını bul (StockTransfer'den)
$stockTransferFilter = "BaseType eq 1250000001 and BaseEntry eq {$doc}";
$stockTransferQuery = "StockTransfers?\$filter=" . urlencode($stockTransferFilter) . "&\$expand=StockTransferLines&\$orderby=DocEntry desc&\$top=1";
$stockTransferData = $sap->get($stockTransferQuery);
$stockTransfers = $stockTransferData['response']['value'] ?? [];

if (!empty($stockTransfers)) {
    $stockTransferInfo = $stockTransfers[0];
    $stLines = $stockTransferInfo['StockTransferLines'] ?? [];
    foreach ($stLines as $stLine) {
        $itemCode = $stLine['ItemCode'] ?? '';
        $qty = (float)($stLine['Quantity'] ?? 0);
        $stockTransferLinesMap[$itemCode] = $qty;
    }
}

// Teslim miktarını bul (daha önce teslim alınmışsa)
$requestComments = $requestData['Comments'] ?? '';
$deliveryDocEntry = null;

if (preg_match('/DELIVERY_DocEntry:(\d+)/', $requestComments, $matches)) {
    $deliveryDocEntry = intval($matches[1]);
    
    if ($deliveryDocEntry) {
        $deliveryTransferQuery = "StockTransfers({$deliveryDocEntry})?\$expand=StockTransferLines";
        $deliveryTransferData = $sap->get($deliveryTransferQuery);
        $deliveryTransferInfo = $deliveryTransferData['response'] ?? null;
        
        if ($deliveryTransferInfo) {
            $dtLines = $deliveryTransferInfo['StockTransferLines'] ?? [];
            foreach ($dtLines as $dtLine) {
                $itemCode = $dtLine['ItemCode'] ?? '';
                $qty = (float)($dtLine['Quantity'] ?? 0);
                if (isset($deliveryTransferLinesMap[$itemCode])) {
                    $deliveryTransferLinesMap[$itemCode] += $qty;
                } else {
                    $deliveryTransferLinesMap[$itemCode] = $qty;
                }
            }
        }
    }
}

// Her kalem için ana depo stok miktarını çek
$warehouseStockMap = [];
foreach ($lines as $line) {
    $itemCode = $line['ItemCode'] ?? '';
    if (!empty($itemCode) && !isset($warehouseStockMap[$itemCode])) {
        try {
            $itemWhQuery = "Items('{$itemCode}')/ItemWarehouseInfoCollection?\$filter=WarehouseCode eq '{$targetWarehouse}'";
            $itemWhResult = $sap->get($itemWhQuery);
            $itemWhData = $itemWhResult['response'] ?? null;
            
            $stockQty = 0;
            if ($itemWhData && isset($itemWhData['value']) && !empty($itemWhData['value'])) {
                $whInfo = $itemWhData['value'][0];
                $stockQty = floatval($whInfo['InStock'] ?? $whInfo['Available'] ?? 0);
            }
            $warehouseStockMap[$itemCode] = $stockQty;
        } catch (Exception $e) {
            $warehouseStockMap[$itemCode] = 0;
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transferLines = [];
    $headerComments = [];
    
    foreach ($lines as $index => $line) {
        // Teslimat miktarı (read-only, talep miktarından geliyor)
        $teslimatMiktari = floatval($line['Quantity'] ?? 0);
        
        // Eksik/Fazla miktar (cebirsel - negatif/pozitif olabilir)
        $eksikFazlaQty = floatval($_POST['eksik_fazla_qty'][$index] ?? 0);
        
        // Kusurlu miktar (min 0)
        $damagedQty = floatval($_POST['damaged_qty'][$index] ?? 0);
        if ($damagedQty < 0) $damagedQty = 0;
        
        // Fiziksel miktar = Teslimat + EksikFazla - Kusurlu
        $fizikselMiktar = $teslimatMiktari + $eksikFazlaQty - $damagedQty;
        
        // Fiziksel miktar negatif olamaz, 0 olabilir
        if ($fizikselMiktar < 0) {
            $fizikselMiktar = 0;
        }
        
        $notes = trim($_POST['notes'][$index] ?? '');
        
        $itemCode = $line['ItemCode'] ?? '';
        $itemName = $line['ItemDescription'] ?? $itemCode;
        $commentParts = [];
        
        // Eksik/Fazla miktar (cebirsel gösterim)
        if ($eksikFazlaQty != 0) {
            $eksikFazlaStr = $eksikFazlaQty > 0 ? "+{$eksikFazlaQty}" : (string)$eksikFazlaQty;
            $commentParts[] = "Eksik/Fazla: {$eksikFazlaStr}";
        }
        
        // Kusurlu miktar
        if ($damagedQty > 0) {
            $commentParts[] = "Kusurlu: {$damagedQty}";
        }
        
        // Fiziksel miktar
        $commentParts[] = "Fiziksel: {$fizikselMiktar}";
        
        if (!empty($notes)) {
            $commentParts[] = "Not: {$notes}";
        }
        
        if (!empty($commentParts)) {
            $headerComments[] = "{$itemCode} ({$itemName}): " . implode(", ", $commentParts);
        }
        
        // Fiziksel miktar > 0 ise StockTransfer için hazırla (0 ise sadece Comments'e eklendi, StockTransfer oluşturulmayacak)
        if ($fizikselMiktar > 0) {
            $itemCost = 0;
            try {
                $itemWhQuery = "Items('{$itemCode}')/ItemWarehouseInfoCollection?\$filter=WarehouseCode eq '{$toWarehouse}'";
                $itemWhResult = $sap->get($itemWhQuery);
                $itemWhData = $itemWhResult['response'] ?? null;
                
                if ($itemWhData && isset($itemWhData['value']) && !empty($itemWhData['value'])) {
                    $whInfo = $itemWhData['value'][0];
                    $itemCost = floatval($whInfo['AveragePrice'] ?? $whInfo['LastPrice'] ?? 0);
                }
                
                if ($itemCost == 0) {
                    $itemQuery = "Items('{$itemCode}')?\$select=ItemCode,StandardPrice,LastPurchasePrice,AvgPrice";
                    $itemResult = $sap->get($itemQuery);
                    $itemData = $itemResult['response'] ?? null;
                    
                    if ($itemData) {
                        $itemCost = floatval($itemData['AvgPrice'] ?? $itemData['StandardPrice'] ?? $itemData['LastPurchasePrice'] ?? 0);
                    }
                }
                
                if ($itemCost == 0) {
                    $itemCost = floatval($line['Price'] ?? $line['UnitPrice'] ?? 0);
                }
            } catch (Exception $e) {
                $itemCost = floatval($line['Price'] ?? $line['UnitPrice'] ?? 0);
            }
            
            $lineData = [
                'ItemCode' => $itemCode,
                'Quantity' => $fizikselMiktar, // Fiziksel miktar = Teslimat + EksikFazla - Kusurlu
                'FromWarehouseCode' => $toWarehouse,
                'WarehouseCode' => $targetWarehouse
            ];
            
            if (!empty($notes)) {
                $lineData['U_ASB2B_Comments'] = $notes;
            }
            
            // Kusurlu miktar > 0 ise 'K', eksik/fazla negatif ise 'E', yoksa '-'
            if ($damagedQty > 0) {
                $lineData['U_ASB2B_Damaged'] = 'K';
            } elseif ($eksikFazlaQty < 0) {
                $lineData['U_ASB2B_Damaged'] = 'E';
            } else {
                $lineData['U_ASB2B_Damaged'] = '-';
            }
            
            // U_ASB2B_LOST enum değerleri: '0' (veya '-'), '1' (Fire), '2' (Zayi)
            // Eksik/Fazla miktar negatif ise (eksik varsa) → '2' (Zayi)
            // Eksik/Fazla miktar pozitif ise (fazla varsa) → '1' (Fire)
            // Eksik/Fazla miktar 0 ise → kaydetme
            if ($eksikFazlaQty < 0) {
                // Eksik varsa → Zayi
                $lineData['U_ASB2B_LOST'] = '2';
            } elseif ($eksikFazlaQty > 0) {
                // Fazla varsa → Fire
                $lineData['U_ASB2B_LOST'] = '1';
            }
            // 0 ise kaydetme
            
            $transferLines[] = $lineData;
        }
    }
    
    
    // Eğer transferLines boşsa ama headerComments varsa, sadece Comments güncelle (fiziksel miktar 0 olan kalemler için)
    if (empty($transferLines) && !empty($headerComments)) {
        // Sadece Comments güncelle, StockTransfer oluşturma
        $headerCommentsText = implode(" | ", $headerComments);
        $currentComments = $requestData['Comments'] ?? '';
        $newComments = !empty($currentComments) ? $headerCommentsText . ' | ' . $currentComments : $headerCommentsText;
        
        $updatePayload = [
            'U_ASB2B_STATUS' => '4',
            'Comments' => $newComments
        ];
        
        $updateResult = $sap->patch("InventoryTransferRequests({$doc})", $updatePayload);
        
        if ($updateResult['status'] == 200 || $updateResult['status'] == 204) {
            header("Location: AnaDepo.php?msg=ok");
            exit;
        } else {
            $errorMsg = "Durum güncellenemedi! HTTP " . ($updateResult['status'] ?? 'NO STATUS');
            if (isset($updateResult['response']['error'])) {
                $errorMsg .= " - " . json_encode($updateResult['response']['error']);
            }
        }
    } elseif (empty($transferLines)) {
        $errorMsg = "İşlenecek kalem bulunamadı! Lütfen en az bir kalem için teslim alın.";
    } else {
        $docDate = $requestData['DocDate'] ?? date('Y-m-d');
        
        $headerCommentsText = !empty($headerComments) ? implode(" | ", $headerComments) : '';
        
        $stockTransferPayload = [
            'FromWarehouse' => $toWarehouse,
            'ToWarehouse' => $targetWarehouse,
            'DocDate' => $docDate,
            'Comments' => $headerCommentsText,
            'U_ASB2B_BRAN' => $branch,
            'U_AS_OWNR' => $uAsOwnr,
            'U_ASB2B_STATUS' => '4',
            'U_ASB2B_TYPE' => 'MAIN',
            'U_ASB2B_User' => $_SESSION["UserName"] ?? '',
            'StockTransferLines' => $transferLines
        ];

        
        $result = $sap->post('StockTransfers', $stockTransferPayload);
        
        if ($result['status'] == 200 || $result['status'] == 201) {
            // StockTransfer oluşturulduktan sonra DocEntry'yi al
            $stockTransferDocEntry = $result['response']['DocEntry'] ?? null;
            
            // InventoryTransferRequest'i güncelle: Status ve Teslimat DocEntry'si
            $updatePayload = [
                'U_ASB2B_STATUS' => '4'
            ];
            
            // Eğer StockTransfer DocEntry varsa, Comments'e ekle (belgeyi bulmak için)
            if ($stockTransferDocEntry) {
                $currentComments = $requestData['Comments'] ?? '';
                $deliveryDocEntryComment = "DELIVERY_DocEntry:{$stockTransferDocEntry}";
                
                // Eğer Comments'te zaten DELIVERY_DocEntry varsa, güncelle; yoksa ekle
                if (preg_match('/DELIVERY_DocEntry:\d+/', $currentComments)) {
                    $currentComments = preg_replace('/DELIVERY_DocEntry:\d+/', $deliveryDocEntryComment, $currentComments);
                } else {
                    $currentComments = !empty($currentComments) ? $deliveryDocEntryComment . ' | ' . $currentComments : $deliveryDocEntryComment;
                }
                
                $updatePayload['Comments'] = $currentComments;
            }
            
            $updateResult = $sap->patch("InventoryTransferRequests({$doc})", $updatePayload);
            
            if ($updateResult['status'] == 200 || $updateResult['status'] == 204) {
                header("Location: AnaDepo.php?msg=ok");
            } else {
                error_log("[TESLIM AL] StockTransfer başarılı ama status güncellenemedi: " . ($updateResult['status'] ?? 'NO STATUS'));
                if (isset($updateResult['response']['error'])) {
                    error_log("[TESLIM AL] Update Error: " . json_encode($updateResult['response']['error']));
                }
                header("Location: AnaDepo.php?msg=ok");
            }
            exit;
        } else {
            $errorMsg = "StockTransfer oluşturulamadı! HTTP " . ($result['status'] ?? 'NO STATUS');
            if (isset($result['response']['error'])) {
                $errorMsg .= " - " . json_encode($result['response']['error']);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teslim Al - MINOA</title>
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
        input[name^="eksik_fazla_qty"] {
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
            <h2>Teslim Al – Talep No: <?= htmlspecialchars($doc) ?></h2>
            <button class="btn btn-secondary" onclick="window.location.href='AnaDepo.php'">← Geri Dön</button>
        </header>

        <?php if (isset($errorMsg)): ?>
            <div class="alert alert-danger">
                <strong>Hata:</strong> <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <div class="info-box">
            <strong>Bilgi:</strong> FromWarehouse: <?= htmlspecialchars($toWarehouse) ?> → ToWarehouse: <?= htmlspecialchars($targetWarehouse) ?>
        </div>

        <form method="POST" class="card">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Kalem Kodu</th>
                        <th>Kalem Tanımı</th>
                        <th>Talep Miktarı</th>
                        <th>Teslimat Miktarı</th>
                        <th>Eksik/Fazla Miktar</th>
                        <th>Kusurlu Miktar</th>
                        <th>Fiziksel</th>
                        <th>Not</th>
                        <th>Görsel</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lines)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 2rem; color: #9ca3af;">
                                Kalem bulunamadı. Lütfen sayfayı yenileyin veya ana sayfaya dönün.
                            </td>
                        </tr>
                    <?php else: ?>
                    <?php foreach ($lines as $index => $line): 
                        $itemCode = $line['ItemCode'] ?? '';
                        $quantity = $line['Quantity'] ?? 0; // Talep Miktarı
                        $remaining = $line['RemainingOpenQuantity'] ?? 0;
                        
                        // Teslim Miktarı (daha önce teslim alınmışsa)
                        $teslimMiktari = $deliveryTransferLinesMap[$itemCode] ?? 0;
                        
                        // Teslimat Miktarı (varsayılan: talep miktarı, daha önce teslim alınmışsa onu göster)
                        $teslimatMiktari = $quantity; // Varsayılan olarak talep miktarı
                        if ($teslimMiktari > 0) {
                            $teslimatMiktari = $teslimMiktari; // Daha önce teslim alınmışsa onu göster
                        }
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($itemCode) ?></td>
                            <td><?= htmlspecialchars($line['ItemDescription'] ?? '-') ?></td>
                            <td class="table-cell-center">
                                <input type="text" 
                                       value="<?= htmlspecialchars($quantity) ?>" 
                                       readonly 
                                       class="qty-input">
                            </td>
                            <td class="table-cell-center">
                                <input type="text" 
                                       id="teslimat_miktari_<?= $index ?>"
                                       value="<?= htmlspecialchars($teslimatMiktari) ?>" 
                                       readonly 
                                       class="qty-input">
                            </td>
                            <td class="table-cell-center">
                                <div class="quantity-controls">
                                    <button type="button" class="qty-btn" onclick="changeEksikFazla(<?= $index ?>, -1)">-</button>
                                    <input type="number" 
                                           name="eksik_fazla_qty[<?= $index ?>]" 
                                           id="eksik_fazla_qty_<?= $index ?>"
                                           value="0" 
                                           step="0.01"
                                           class="qty-input qty-input-small"
                                           onchange="calculatePhysical(<?= $index ?>)">
                                    <button type="button" class="qty-btn" onclick="changeEksikFazla(<?= $index ?>, 1)">+</button>
                                </div>
                            </td>
                            <td class="table-cell-center">
                                <div class="quantity-controls">
                                    <button type="button" class="qty-btn" onclick="changeDamaged(<?= $index ?>, -1)">-</button>
                                    <input type="number" 
                                           name="damaged_qty[<?= $index ?>]" 
                                           id="damaged_qty_<?= $index ?>"
                                           value="0" 
                                           min="0"
                                           step="0.01"
                                           class="qty-input qty-input-small"
                                           onchange="calculatePhysical(<?= $index ?>)">
                                    <button type="button" class="qty-btn" onclick="changeDamaged(<?= $index ?>, 1)">+</button>
                                </div>
                            </td>
                            <td class="table-cell-center">
                                <input type="text" 
                                       id="fiziksel_<?= $index ?>"
                                       value="0" 
                                       readonly 
                                       class="qty-input">
                            </td>
                            <td>
                                <textarea name="notes[<?= $index ?>]" rows="2" class="notes-textarea" placeholder="Not..."></textarea>
                            </td>
                            <td>
                                <input type="file" name="image[<?= $index ?>]" accept="image/*" class="file-input">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="window.location.href='AnaDepo.php'">İptal</button>
                <button type="submit" class="btn btn-primary" onclick="return validateForm()">Teslim Al / Onayla</button>
            </div>
        </form>
    </main>

    <script>
// Sayfa yüklendiğinde fiziksel miktarları hesapla ve renkleri güncelle
document.addEventListener('DOMContentLoaded', function() {
    const eksikFazlaInputs = document.querySelectorAll('input[name^="eksik_fazla_qty"]');
    eksikFazlaInputs.forEach(function(input) {
        const index = input.id.replace('eksik_fazla_qty_', '');
        updateEksikFazlaColor(input);
        calculatePhysical(parseInt(index));
    });
});

function validateForm() {
    // Form doğrulama - fiziksel miktar >= 0 olmalı (negatif olamaz)
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
    
    return true;
}

// Eksik/Fazla miktar değiştirme (cebirsel - negatif/pozitif olabilir)
function changeEksikFazla(index, delta) {
    const input = document.getElementById('eksik_fazla_qty_' + index);
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

// Kusurlu miktar değiştirme (min 0)
function changeDamaged(index, delta) {
    const input = document.getElementById('damaged_qty_' + index);
    if (!input) return;
    
    let value = parseFloat(input.value) || 0;
    value += delta;
    if (value < 0) value = 0;
    input.value = value;
    calculatePhysical(index);
}

// Fiziksel miktar hesaplama: Teslimat + EksikFazla - Kusurlu
function calculatePhysical(index) {
    const teslimatInput = document.getElementById('teslimat_miktari_' + index);
    const eksikFazlaInput = document.getElementById('eksik_fazla_qty_' + index);
    const damagedInput = document.getElementById('damaged_qty_' + index);
    const fizikselInput = document.getElementById('fiziksel_' + index);
    
    if (!teslimatInput || !eksikFazlaInput || !damagedInput || !fizikselInput) return;
    
    const teslimat = parseFloat(teslimatInput.value) || 0;
    const eksikFazla = parseFloat(eksikFazlaInput.value) || 0;
    const kusurlu = parseFloat(damagedInput.value) || 0;
    
    // Fiziksel = Teslimat + EksikFazla - Kusurlu
    let fiziksel = teslimat + eksikFazla - kusurlu;
    
    // Fiziksel miktar negatif olamaz, 0 olabilir
    if (fiziksel < 0) {
        fiziksel = 0;
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
    const eksikFazlaInputs = document.querySelectorAll('input[name^="eksik_fazla_qty"]');
    const damagedInputs = document.querySelectorAll('input[name^="damaged_qty"]');
    
    eksikFazlaInputs.forEach(function(input) {
        const index = input.id.replace('eksik_fazla_qty_', '');
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
    
    damagedInputs.forEach(function(input) {
        const index = input.id.replace('damaged_qty_', '');
        input.addEventListener('input', () => calculatePhysical(parseInt(index)));
        input.addEventListener('change', () => calculatePhysical(parseInt(index)));
    });
});
    </script>
</body>
</html>
