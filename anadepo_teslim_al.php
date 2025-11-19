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
        $deliveredQty = floatval($_POST['delivered_qty'][$index] ?? 0);
        $missingQty = floatval($_POST['missing_qty'][$index] ?? 0);
        $damagedQty = floatval($_POST['damaged_qty'][$index] ?? 0);
        $notes = trim($_POST['notes'][$index] ?? '');
        
        if ($deliveredQty > 0) {
            $itemCode = $line['ItemCode'] ?? '';
            
            
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
                'Quantity' => $deliveredQty,
                'FromWarehouseCode' => $toWarehouse,
                'WarehouseCode' => $targetWarehouse
            ];
            
            if (!empty($notes)) {
                $lineData['U_ASB2B_Comments'] = $notes;
            }
            
            if ($damagedQty > 0) {
                $lineData['U_ASB2B_Damaged'] = 'K';
            } elseif ($missingQty > 0) {
                $lineData['U_ASB2B_Damaged'] = 'E';
            } else {
                $lineData['U_ASB2B_Damaged'] = '-';
            }
            
            $transferLines[] = $lineData;
            
            $itemCode = $line['ItemCode'] ?? '';
            $itemName = $line['ItemDescription'] ?? $itemCode;
            $commentParts = [];
            
            if ($missingQty > 0) {
                $commentParts[] = "Eksik: {$missingQty}";
            }
            if ($damagedQty > 0) {
                $commentParts[] = "Kusurlu: {$damagedQty}";
            }
            if (!empty($notes)) {
                $commentParts[] = "Not: {$notes}";
            }
            
            if (!empty($commentParts)) {
                $headerComments[] = "{$itemCode} ({$itemName}): " . implode(", ", $commentParts);
            }
        }
    }
    
    error_log('[TESLIM AL DEBUG] POST delivered_qty: ' . print_r($_POST['delivered_qty'] ?? [], true));
    error_log('[TESLIM AL DEBUG] TransferLines count: ' . count($transferLines));
    error_log('[TESLIM AL DEBUG] Lines count: ' . count($lines));
    
    if (empty($transferLines)) {
        $errorMsg = "Teslim miktarı girilen kalem bulunamadı! Lütfen en az bir kalem için teslim miktarı girin.";
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
                        <th>Ana Depo Miktarı</th>
                        <th>Talep Miktarı</th>
                        <th>Sevk Miktarı</th>
                        <th>Teslim Miktarı</th>
                        <th>Eksik Miktar</th>
                        <th>Kusurlu Miktar</th>
                        <th>Not</th>
                        <th>Görsel</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lines)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 2rem; color: #9ca3af;">
                                Kalem bulunamadı. Lütfen sayfayı yenileyin veya ana sayfaya dönün.
                            </td>
                        </tr>
                    <?php else: ?>
                    <?php foreach ($lines as $index => $line): 
                        $quantity = $line['Quantity'] ?? 0;
                        $remaining = $line['RemainingOpenQuantity'] ?? 0;
                        $delivered = ($remaining < $quantity) ? ($quantity - $remaining) : $quantity;
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($line['ItemCode'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($line['ItemDescription'] ?? '-') ?></td>
                            <td class="table-cell-center">
                                <input type="number" 
                                       value="<?= htmlspecialchars($quantity) ?>" 
                                       readonly 
                                       step="0.01"
                                       class="qty-input">
                            </td>
                            <td class="table-cell-center">
                                <div class="quantity-controls">
                                    <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'delivered', -1, <?= htmlspecialchars($quantity) ?>)">-</button>
                                    <input type="number" 
                                           name="delivered_qty[<?= $index ?>]" 
                                           id="delivered_qty_<?= $index ?>"
                                           value="<?= htmlspecialchars($delivered) ?>" 
                                           min="0" 
                                           max="<?= htmlspecialchars($quantity) ?>"
                                           step="0.01"
                                           class="qty-input"
                                           required>
                                    <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'delivered', 1, <?= htmlspecialchars($quantity) ?>)">+</button>
                                </div>
                            </td>
                            <td class="table-cell-center">
                                <div class="quantity-controls">
                                    <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'missing', -1, <?= htmlspecialchars($quantity) ?>)">-</button>
                                    <input type="number" 
                                           name="missing_qty[<?= $index ?>]" 
                                           id="missing_qty_<?= $index ?>"
                                           value="0" 
                                           min="0" 
                                           max="<?= htmlspecialchars($quantity) ?>"
                                           step="0.01"
                                           class="qty-input qty-input-small">
                                    <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'missing', 1, <?= htmlspecialchars($quantity) ?>)">+</button>
                                </div>
                            </td>
                            <td class="table-cell-center">
                                <div class="quantity-controls">
                                    <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'damaged', -1, <?= htmlspecialchars($quantity) ?>)">-</button>
                                    <input type="number" 
                                           name="damaged_qty[<?= $index ?>]" 
                                           id="damaged_qty_<?= $index ?>"
                                           value="0" 
                                           min="0" 
                                           max="<?= htmlspecialchars($quantity) ?>"
                                           step="0.01"
                                           class="qty-input qty-input-small">
                                    <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'damaged', 1, <?= htmlspecialchars($quantity) ?>)">+</button>
                                </div>
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
function validateForm() {
    let hasDeliveredQty = false;
    const deliveredInputs = document.querySelectorAll('input[name^="delivered_qty"]');
    
    deliveredInputs.forEach(function(input) {
        const value = parseFloat(input.value) || 0;
        if (value > 0) {
            hasDeliveredQty = true;
        }
    });
    
    if (!hasDeliveredQty) {
        alert('Lütfen en az bir kalem için teslim miktarı girin!');
        return false;
    }
    
    return true;
}

function changeQuantity(index, type, delta, maxValue = null) {
    const inputId = type === 'delivered' ? 'delivered_qty_' + index : type + '_qty_' + index;
    const input = document.getElementById(inputId);
    if (!input) return;
    
    let value = parseFloat(input.value) || 0;
    value += delta;
    if (value < 0) value = 0;
    if (maxValue !== null && value > maxValue) {
        value = maxValue;
        alert('Maksimum değer: ' + maxValue);
    }
    
    input.value = value.toFixed(2);
    
    if (type !== 'delivered') {
        updateRelatedFields(index);
    }
}

function updateRelatedFields(index) {
    const row = document.getElementById('missing_qty_' + index).closest('tr');
    const orderQtyInput = row.querySelector('input[readonly]');
    const orderQty = parseFloat(orderQtyInput.value) || 0;
    
    const deliveredInput = document.getElementById('delivered_qty_' + index);
    const missingInput = document.getElementById('missing_qty_' + index);
    const missingQty = parseFloat(missingInput.value) || 0;
    const damagedInput = document.getElementById('damaged_qty_' + index);
    const damagedQty = parseFloat(damagedInput.value) || 0;
    
    const totalDeficit = missingQty + damagedQty;
    if (totalDeficit > orderQty) {
        const excess = totalDeficit - orderQty;
        if (missingQty > 0) {
            const newMissing = Math.max(0, missingQty - excess);
            missingInput.value = newMissing.toFixed(2);
            updateRelatedFields(index);
            return;
        }
        if (damagedQty > 0) {
            const newDamaged = Math.max(0, damagedQty - excess);
            damagedInput.value = newDamaged.toFixed(2);
            updateRelatedFields(index);
            return;
        }
    }
    
    const calculatedDelivered = orderQty - missingQty - damagedQty;
    const newDelivered = calculatedDelivered >= 0 ? calculatedDelivered : 0;
    
    if (deliveredInput) {
        deliveredInput.value = newDelivered.toFixed(2);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach((row, index) => {
        const missingInput = document.getElementById('missing_qty_' + index);
        const damagedInput = document.getElementById('damaged_qty_' + index);
        const deliveredInput = document.getElementById('delivered_qty_' + index);
        
        if (missingInput) {
            missingInput.addEventListener('input', () => updateRelatedFields(index));
            missingInput.addEventListener('change', () => updateRelatedFields(index));
        }
        if (damagedInput) {
            damagedInput.addEventListener('input', () => updateRelatedFields(index));
            damagedInput.addEventListener('change', () => updateRelatedFields(index));
        }
        if (deliveredInput) {
            deliveredInput.addEventListener('input', function() {
                const orderQtyInput = row.querySelector('input[readonly]');
                const orderQty = parseFloat(orderQtyInput.value) || 0;
                const deliveredQty = parseFloat(this.value) || 0;
                
                if (deliveredQty > orderQty) {
                    this.value = orderQty.toFixed(2);
                    alert('Teslimat miktarı sipariş miktarını aşamaz!');
                }
            });
        }
    });
});
    </script>
</body>
</html>
