<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

// DocEntry parametresi gerekli
$docEntry = isset($_GET['DocEntry']) ? intval($_GET['DocEntry']) : null;

if (empty($docEntry)) {
    header("Location: Fire-Zayi.php");
    exit;
}

// StockTransfer belgesini çek
$transferQuery = "StockTransfers({$docEntry})";
$transferData = $sap->get($transferQuery);

if (($transferData['status'] ?? 0) != 200) {
    die("Fire/Zayi belgesi bulunamadı veya erişilemedi.");
}

$transfer = $transferData['response'] ?? $transferData;
$allLines = [];

// Header response'undan StockTransferLines'ı al
if (isset($transfer['StockTransferLines']) && is_array($transfer['StockTransferLines'])) {
    $allLines = $transfer['StockTransferLines'];
}

// Eğer hala boşsa, direkt collection path'i dene
if (empty($allLines)) {
    $linesQuery = "StockTransfers({$docEntry})/StockTransferLines";
    $linesData = $sap->get($linesQuery);
    
    if (($linesData['status'] ?? 0) == 200) {
        $linesResponse = $linesData['response'] ?? $linesData;
        
        // Farklı response yapılarını kontrol et
        if (isset($linesResponse['value']) && is_array($linesResponse['value'])) {
            $allLines = $linesResponse['value'];
        } elseif (isset($linesResponse['StockTransferLines']) && is_array($linesResponse['StockTransferLines'])) {
            $allLines = $linesResponse['StockTransferLines'];
        } elseif (is_array($linesResponse)) {
            $allLines = $linesResponse;
        }
    }
}

// Fire/Zayi satırlarını filtrele: Sadece U_ASB2B_LOST veya U_ASB2B_Damaged dolu olan satırlar
// NOT: Fire/Zayi belgesinde sadece Fire/Zayi satırları görünmeli (kusurlu, eksik, fazla)
$lines = [];
$itemSummary = []; // Item bazında toplam miktarlar (açıklama için)

foreach ($allLines as $line) {
    $uAsb2bLost = trim($line['U_ASB2B_LOST'] ?? '');
    $uAsb2bDamaged = trim($line['U_ASB2B_Damaged'] ?? '');
    
    // Fire & Zayi: U_ASB2B_LOST veya U_ASB2B_Damaged dolu VE '-' değil
    $isFireZayi = (!empty($uAsb2bLost) && $uAsb2bLost !== '-') || (!empty($uAsb2bDamaged) && $uAsb2bDamaged !== '-');
    
    if ($isFireZayi) {
        $lines[] = $line;
        
        // Açıklama için item bazında toplam miktarları hesapla
        $itemCode = $line['ItemCode'] ?? '';
        $itemName = $line['ItemDescription'] ?? $itemCode;
        $quantity = floatval($line['Quantity'] ?? 0);
        
        if (!isset($itemSummary[$itemCode])) {
            $itemSummary[$itemCode] = [
                'name' => $itemName,
                'kusurlu' => 0,
                'eksik' => 0,
                'fazla' => 0,
                'zayi' => 0
            ];
        }
        
        // Tip bilgisine göre miktarı ekle
        if ($uAsb2bDamaged == 'K') {
            $itemSummary[$itemCode]['kusurlu'] += $quantity;
        } elseif ($uAsb2bDamaged == 'E') {
            $itemSummary[$itemCode]['eksik'] += $quantity;
        } elseif ($uAsb2bLost == '1') {
            $itemSummary[$itemCode]['fazla'] += $quantity;
        } elseif ($uAsb2bLost == '2') {
            $itemSummary[$itemCode]['zayi'] += $quantity;
        }
    }
}

// Modül adını belirle (U_ASB2B_TYPE'a göre)
$moduleName = '';
$uAsb2bType = trim($transfer['U_ASB2B_TYPE'] ?? '');
if ($uAsb2bType == 'MAIN') {
    $moduleName = 'ANA DEPO';
} elseif ($uAsb2bType == 'TRANSFER') {
    // Dış Tedarik mi Transferler mi kontrol et (DocumentReferences'a bak)
    $documentRefs = $transfer['DocumentReferences'] ?? [];
    $isDisTedarik = false;
    foreach ($documentRefs as $ref) {
        if (($ref['RefObjType'] ?? '') == 'rot_PurchaseRequest') {
            $isDisTedarik = true;
            break;
        }
    }
    $moduleName = $isDisTedarik ? 'DIŞ TEDARİK' : 'TRANSFER';
} else {
    $moduleName = strtoupper($uAsb2bType ?: 'BİLİNMEYEN');
}

// Açıklama metnini oluştur (modül adı + eksik/fazla bilgileri)
$commentsParts = [];
foreach ($itemSummary as $itemCode => $summary) {
    $itemParts = [];
    if ($summary['kusurlu'] > 0) {
        $itemParts[] = "Kusurlu: " . formatNumber($summary['kusurlu']);
    }
    if ($summary['eksik'] > 0) {
        $itemParts[] = "Eksik: " . formatNumber($summary['eksik']);
    }
    if ($summary['fazla'] > 0) {
        $itemParts[] = "Fazla: " . formatNumber($summary['fazla']);
    }
    if ($summary['zayi'] > 0) {
        $itemParts[] = "Zayi: " . formatNumber($summary['zayi']);
    }
    
    if (!empty($itemParts)) {
        $commentsParts[] = "{$itemCode} ({$summary['name']}): " . implode(", ", $itemParts);
    }
}
$itemComments = !empty($commentsParts) ? implode(" | ", $commentsParts) : '';
$enhancedComments = !empty($moduleName) ? "[{$moduleName}] " . $itemComments : $itemComments;
if (empty($enhancedComments)) {
    $enhancedComments = $transfer['Comments'] ?? '';
}

// Tür mapping
function getTypeText($lost) {
    if ($lost == '1') return 'Fire';
    if ($lost == '2') return 'Zayi';
    return '-';
}

function getTypeClass($lost) {
    if ($lost == '1') return 'status-fire';
    if ($lost == '2') return 'status-zayi';
    return '';
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// Sayı formatlama (ondalık kısım yok)
function formatNumber($num) {
    if (empty($num) && $num !== 0) return '-';
    $num = (float)$num;
    // Eğer tam sayı ise ondalık kısım gösterme
    if ($num == floor($num)) {
        return number_format($num, 0, ',', '.');
    }
    return number_format($num, 2, ',', '.');
}

// Satır tipi metni
function getLineTypeText($lost, $damaged) {
    if ($lost == '1') return 'Fazla';
    if ($lost == '2') return 'Zayi';
    if ($damaged == 'K') return 'Kusurlu';
    if ($damaged == 'E') return 'Eksik';
    return '';
}

// POST işlemi: Tür değiştirme
$errorMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_type') {
    $newType = $_POST['type'] ?? '';
    if (in_array($newType, ['1', '2'])) {
        $updatePayload = ['U_ASB2B_LOST' => $newType];
        $updateResult = $sap->patch("StockTransfers({$docEntry})", $updatePayload);
        
        if (($updateResult['status'] ?? 0) == 200 || ($updateResult['status'] ?? 0) == 204) {
            // Başarılı, sayfayı yenile
            header("Location: Fire-ZayiDetay.php?DocEntry={$docEntry}");
            exit;
        } else {
            $errorMsg = "Tür güncellenemedi! " . json_encode($updateResult['response'] ?? []);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fire/Zayi Detay - MINOA</title>
    <?php include 'navbar.php'; ?>
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

        .status-fire {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-zayi {
            background: #fef3c7;
            color: #92400e;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            table-layout: fixed;
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

        .data-table th.text-right {
            text-align: right;
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
            text-align: left;
        }

        .data-table td.text-right {
            text-align: right;
        }

        .text-right {
            text-align: right;
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

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
        }

        .empty-message {
            text-align: center;
            padding: 3rem;
            color: #9ca3af;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <main class="main-content">
        <header class="page-header">
            <h2>Fire/Zayi Detay</h2>
            <button class="btn btn-secondary" onclick="window.location.href='Fire-Zayi.php'">← Geri Dön</button>
        </header>

        <div class="content-wrapper">
            <?php if (!empty($errorMsg)): ?>
            <div class="card" style="background: #fee2e2; border: 2px solid #dc2626; margin-bottom: 1.5rem;">
                <div class="card-body">
                    <p style="color: #991b1b; font-weight: 600; margin: 0;"><?= htmlspecialchars($errorMsg) ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Üst Bilgiler -->
            <section class="card">
                <div class="card-header">
                    <h3>Üst Bilgiler</h3>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Belge No</span>
                            <span class="info-value"><?= htmlspecialchars($transfer['DocEntry'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Tarih</span>
                            <span class="info-value"><?= formatDate($transfer['DocDate'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Tür</span>
                            <span class="info-value">
                                <form method="POST" action="" style="display: inline-block;" onsubmit="return confirm('Türü değiştirmek istediğinize emin misiniz?');">
                                    <input type="hidden" name="action" value="update_type">
                                    <select name="type" onchange="this.form.submit()" style="padding: 6px 12px; border-radius: 20px; border: none; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; cursor: pointer; <?= ($transfer['U_ASB2B_LOST'] ?? '') == '1' ? 'background: #fee2e2; color: #991b1b;' : 'background: #fef3c7; color: #92400e;' ?>">
                                        <option value="1" <?= ($transfer['U_ASB2B_LOST'] ?? '') == '1' ? 'selected' : '' ?>>Fire</option>
                                        <option value="2" <?= ($transfer['U_ASB2B_LOST'] ?? '') == '2' ? 'selected' : '' ?>>Zayi</option>
                                    </select>
                                </form>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Sahip</span>
                            <span class="info-value"><?= htmlspecialchars($transfer['U_AS_OWNR'] ?? '-') ?></span>
                        </div>
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <span class="info-label">Açıklama</span>
                            <span class="info-value"><?= htmlspecialchars($enhancedComments) ?></span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Satır Listesi -->
            <section class="card">
                <div class="card-header">
                    <h3>Satır Listesi</h3>
                </div>
                <div class="card-body">
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Satır No</th>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th class="text-right">Miktar</th>
                                </tr>
                            </thead>
                            <tbody id="linesTableBody">
                                <?php if (empty($lines)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 40px; color: #6b7280;">
                                        Satır bulunmamaktadır.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php 
                                foreach ($lines as $line): 
                                    $lineNum = $line['LineNum'] ?? $line['LineNumber'] ?? '';
                                    $itemCode = $line['ItemCode'] ?? '';
                                    $itemName = $line['ItemDescription'] ?? '';
                                    $uomCode = $line['UoMCode'] ?? '';
                                    $quantity = floatval($line['Quantity'] ?? 0);
                                    $uAsb2bLost = trim($line['U_ASB2B_LOST'] ?? '');
                                    $uAsb2bDamaged = trim($line['U_ASB2B_Damaged'] ?? '');
                                    $lineTypeText = getLineTypeText($uAsb2bLost, $uAsb2bDamaged);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($lineNum) ?></td>
                                    <td><strong><?= htmlspecialchars($itemCode) ?></strong></td>
                                    <td><?= htmlspecialchars($itemName) ?></td>
                                    <td class="text-right">
                                        <?= formatNumber($quantity) ?> <?= htmlspecialchars($uomCode) ?>
                                        <?php if (!empty($lineTypeText)): ?>
                                            <span style="color: #6b7280; font-size: 12px; margin-left: 8px;">(<?= htmlspecialchars($lineTypeText) ?>)</span>
                                        <?php endif; ?>
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
            <div class="action-buttons">
                <button class="btn btn-secondary" onclick="window.location.href='Fire-Zayi.php'">Geri Dön</button>
                <button class="btn btn-primary" onclick="window.print()">Yazdır</button>
            </div>
        </div>
    </main>
</body>
</html>


