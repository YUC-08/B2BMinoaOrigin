<?php
session_start();

if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

/* ------------------------
 * Helpers
 * ---------------------- */
function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) return date('d.m.Y', strtotime(substr($date, 0, 10)));
    return date('d.m.Y', strtotime($date));
}

function findWarehouse($sap, string $uAsOwnr, string $branch, string $filterExpr): ?string {
    $filter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and {$filterExpr}";
    $query  = "Warehouses?\$select=WarehouseCode&\$filter=" . rawurlencode($filter);
    $data   = $sap->get($query);
    if (($data['status'] ?? 0) != 200) {
        error_log("[findWarehouse] GET failed: HTTP " . ($data['status'] ?? 'NO STATUS') . " | Query: {$query} | Error: " . json_encode($data['response']['error'] ?? $data['error'] ?? 'Unknown'));
    }
    $rows   = $data['response']['value'] ?? [];
    return !empty($rows) ? ($rows[0]['WarehouseCode'] ?? null) : null;
}

/**
 * View: ASB2B_TransferRequestList_B1SLQuery'den doc'un tüm satırlarını çeker.
 * (Tek kaynak burası)
 */
function getRequestLinesFromView($sap, int $docEntry): array {
    $viewFilter = "DocEntry eq {$docEntry}";
    $viewQuery  = "view.svc/ASB2B_TransferRequestList_B1SLQuery?\$filter=" . rawurlencode($viewFilter);
    $viewData   = $sap->get($viewQuery);
    if (($viewData['status'] ?? 0) != 200) {
        error_log("[getRequestLinesFromView] GET failed: HTTP " . ($viewData['status'] ?? 'NO STATUS') . " | Query: {$viewQuery} | Error: " . json_encode($viewData['response']['error'] ?? $viewData['error'] ?? 'Unknown'));
    }
    return $viewData['response']['value'] ?? [];
}

/**
 * ST liste çek (U_ASB2B_QutMaster üzerinden)
 */
function getStockTransfersByFilter($sap, string $filter): array {
    $q = "StockTransfers?\$select=DocEntry,FromWarehouse,ToWarehouse&\$filter=" . rawurlencode($filter) . "&\$orderby=DocEntry%20asc";
    $d = $sap->get($q);
    if (($d['status'] ?? 0) != 200) {
        error_log("[getStockTransfersByFilter] GET failed: HTTP " . ($d['status'] ?? 'NO STATUS') . " | Query: {$q} | Error: " . json_encode($d['response']['error'] ?? $d['error'] ?? 'Unknown'));
        return [];
    }
    return $d['response']['value'] ?? [];
}

/**
 * ST1 belgelerini bul (birden fazla yöntemle)
 * Not: ToWarehouse filtresi kaldırıldı çünkü header seviyesindeki ToWarehouse ile
 * satır bazlı depo kodları farklı olabilir. Bunun yerine satır bazlı kontrol yapılacak.
 */
function findST1Documents($sap, int $docEntry, string $sevkiyatDepo): array {
    $allST1 = [];
    $found = [];
    
    // Yöntem 1: U_ASB2B_QutMaster ile (ToWarehouse filtresi kaldırıldı)
    $st1a = getStockTransfersByFilter(
        $sap,
        "U_ASB2B_QutMaster eq {$docEntry}"
    );
    foreach ($st1a as $st) {
        $de = (int)($st['DocEntry'] ?? 0);
        if ($de > 0 && !isset($found[$de])) {
            // Satır bazlı kontrol: En az bir satırın ToWarehouse'u sevkiyatDepo ile eşleşiyor mu?
            $lines = getStockTransferLines($sap, $de);
            $hasMatchingLine = false;
            foreach ($lines as $line) {
                $lineToWhs = (string)($line['WarehouseCode'] ?? '');
                if ($lineToWhs === $sevkiyatDepo) {
                    $hasMatchingLine = true;
                    break;
                }
            }
            // Eğer satır bazlı eşleşme yoksa, header seviyesindeki ToWarehouse'u kontrol et
            if (!$hasMatchingLine) {
                $headerToWhs = (string)($st['ToWarehouse'] ?? '');
                if ($headerToWhs === $sevkiyatDepo) {
                    $hasMatchingLine = true;
                }
            }
            if ($hasMatchingLine) {
                $allST1[] = $st;
                $found[$de] = true;
            }
        }
    }
    
    // Yöntem 2: BaseEntry + BaseType ile (ToWarehouse filtresi kaldırıldı)
    $st1b = getStockTransfersByFilter(
        $sap,
        "BaseEntry eq {$docEntry} and BaseType eq 1250000001"
    );
    foreach ($st1b as $st) {
        $de = (int)($st['DocEntry'] ?? 0);
        if ($de > 0 && !isset($found[$de])) {
            // Satır bazlı kontrol
            $lines = getStockTransferLines($sap, $de);
            $hasMatchingLine = false;
            foreach ($lines as $line) {
                $lineToWhs = (string)($line['WarehouseCode'] ?? '');
                if ($lineToWhs === $sevkiyatDepo) {
                    $hasMatchingLine = true;
                    break;
                }
            }
            if (!$hasMatchingLine) {
                $headerToWhs = (string)($st['ToWarehouse'] ?? '');
                if ($headerToWhs === $sevkiyatDepo) {
                    $hasMatchingLine = true;
                }
            }
            if ($hasMatchingLine) {
                $allST1[] = $st;
                $found[$de] = true;
            }
        }
    }
    
    // Yöntem 3: Comments ile (eski kayıtlar için, ToWarehouse filtresi kaldırıldı)
    $st1c = getStockTransfersByFilter(
        $sap,
        "Comments eq '{$docEntry}'"
    );
    foreach ($st1c as $st) {
        $de = (int)($st['DocEntry'] ?? 0);
        if ($de > 0 && !isset($found[$de])) {
            // Satır bazlı kontrol
            $lines = getStockTransferLines($sap, $de);
            $hasMatchingLine = false;
            foreach ($lines as $line) {
                $lineToWhs = (string)($line['WarehouseCode'] ?? '');
                if ($lineToWhs === $sevkiyatDepo) {
                    $hasMatchingLine = true;
                    break;
                }
            }
            if (!$hasMatchingLine) {
                $headerToWhs = (string)($st['ToWarehouse'] ?? '');
                if ($headerToWhs === $sevkiyatDepo) {
                    $hasMatchingLine = true;
                }
            }
            if ($hasMatchingLine) {
                $allST1[] = $st;
                $found[$de] = true;
            }
        }
    }
    
    return $allST1;
}

/**
 * ST lines çek
 */
function getStockTransferLines($sap, int $stDocEntry): array {
    // $select kullanma; bazı ortamlarda navigation + select saçmalıyor
    $q = "StockTransfers({$stDocEntry})/StockTransferLines";
    $d = $sap->get($q);

    if (($d['status'] ?? 0) != 200) {
        // istersen buraya error_log(json_encode($d)) koy
        return [];
    }

    $r = $d['response'] ?? [];

    // 1) Klasik OData collection: {"value":[...]}
    if (isset($r['value']) && is_array($r['value'])) return $r['value'];

    // 2) Sende gelen format: {"StockTransferLines":[...]}
    if (isset($r['StockTransferLines']) && is_array($r['StockTransferLines'])) return $r['StockTransferLines'];

    return [];
}

/**
 * ST'lerden (ST1/ST2) satır bazlı qty hesaplar.
 * Eşleme önceliği: BaseLine == lineNum, yoksa sadece ItemCode (son çare).
 */
function sumQtyForLineFromStockTransfers($sap, array $stHeaders, string $itemCode, int $lineNum): float {
    $sum = 0.0;
    foreach ($stHeaders as $st) {
        $stDoc = (int)($st['DocEntry'] ?? 0);
        if ($stDoc <= 0) continue;

        $lines = getStockTransferLines($sap, $stDoc);
        foreach ($lines as $ln) {
            $it = (string)($ln['ItemCode'] ?? '');
            if ($it !== $itemCode) continue;

            // BaseLine varsa lineNum ile eşleştir.
            if (isset($ln['BaseLine']) && $ln['BaseLine'] !== null && $ln['BaseLine'] !== '') {
                if ((int)$ln['BaseLine'] !== $lineNum) continue;
            }
            $qty = (float)($ln['Quantity'] ?? 0);
            if ($qty > 0) $sum += $qty;
        }
    }
    return $sum;
}

/* ------------------------
 * 0) Session & Params
 * ---------------------- */
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch  = $_SESSION["Branch2"]["Name"] ?? ($_SESSION["WhsCode"] ?? ($_SESSION["Branch"] ?? ''));

if ($uAsOwnr === '' || $branch === '') {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

$docEntry  = (int)($_GET['docEntry'] ?? 0);
$itemCode  = trim((string)($_GET['itemCode'] ?? ''));
$lineNum   = ($_GET['lineNum'] ?? '');
$lineNum   = ($lineNum === '' ? null : (int)$lineNum);

if ($docEntry <= 0 || $itemCode === '' || $lineNum === null) {
    // Bu sayfa satır bazlı çalışır.
    header("Location: Transferler.php?view=incoming");
    exit;
}

/* ------------------------
 * 1) View'den Header + Satırlar
 * ---------------------- */
$viewRows = getRequestLinesFromView($sap, $docEntry);
if (empty($viewRows)) {
    $_SESSION['error_message'] = "Transfer talebi bulunamadı (View boş)!";
    header("Location: Transferler.php?view=incoming");
    exit;
}

$headerRow = $viewRows[0];

// Header alanlar
$docDate = formatDate($headerRow['DocDate'] ?? null);
$dueDate = formatDate($headerRow['DocDueDate'] ?? null);

$fromWarehouse = (string)($headerRow['FromWhsCode'] ?? '');
$toWarehouse   = (string)($headerRow['WhsCode'] ?? '');
$headerStatus  = (string)($headerRow['U_ASB2B_STATUS'] ?? '0');

// Seçilen satırı bul
$selected = null;
foreach ($viewRows as $r) {
    $rItem = (string)($r['ItemCode'] ?? '');
    $rLine = (int)($r['LineNum'] ?? -1);
    if ($rItem === $itemCode && $rLine === $lineNum) {
        $selected = $r;
        break;
    }
}

if (!$selected) {
    $_SESSION['error_message'] = "Satır bulunamadı! (DocEntry={$docEntry}, ItemCode={$itemCode}, LineNum={$lineNum})";
    header("Location: Transferler.php?view=incoming");
    exit;
}

// Satır alanları
$itemName     = (string)($selected['Dscription'] ?? $itemCode);
$requestedQty = (float)($selected['Quantity'] ?? 0);
$currentLineStatus = (string)($selected['U_ASB2B_STATUS'] ?? '0');

// Zaten tamamlandıysa sayfadan çık
if ($currentLineStatus === '4') {
    $_SESSION['error_message'] = "Bu satır zaten tamamlanmış!";
    header("Location: Transferler.php?view=incoming");
    exit;
}

/* ------------------------
 * 2) Depo Mantığı (Mevcut yaklaşımı koru)
 * ---------------------- */
$sevkiyatDepo    = $toWarehouse;
$targetWarehouse = str_replace('-1', '-0', $sevkiyatDepo);

// -1 -> -0 dönüşmüyorsa (beklenmeyen format), sistemdeki MAIN flag'den bul
if ($targetWarehouse === $sevkiyatDepo || $targetWarehouse === '') {
    $targetWarehouse = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_MAIN eq '1'");
    if (!$targetWarehouse) {
        $_SESSION['error_message'] = "Hedef depo (Ana Depo) bulunamadı!";
        header("Location: Transferler.php?view=incoming");
        exit;
    }
    $sevkiyatDepoCheck = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_MAIN eq '2'");
    if ($sevkiyatDepoCheck) $sevkiyatDepo = $sevkiyatDepoCheck;
}

$fireZayiWarehouse = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_MAIN eq '3'");
if (!$fireZayiWarehouse) {
    $fireZayiWarehouse = findWarehouse($sap, $uAsOwnr, $branch, "U_ASB2B_FIREZAYI eq 'Y'");
}

/* ------------------------
 * 3) Sevk / Önceki Teslim hesapları
 * ---------------------- */
// ST1: Birden fazla yöntemle ara
$st1Headers = findST1Documents($sap, $docEntry, $sevkiyatDepo);

// ST2: U_ASB2B_QutMaster = docEntry AND FromWarehouse = sevkiyatDepo AND ToWarehouse = targetWarehouse
$st2Headers = getStockTransfersByFilter(
    $sap,
    "U_ASB2B_QutMaster eq {$docEntry} and FromWarehouse eq '{$sevkiyatDepo}' and ToWarehouse eq '{$targetWarehouse}'"
);

$shippedQtyRaw   = sumQtyForLineFromStockTransfers($sap, $st1Headers, $itemCode, $lineNum);
$deliveredQtyRaw = sumQtyForLineFromStockTransfers($sap, $st2Headers, $itemCode, $lineNum);

$remainingToDeliver = max(0.0, $shippedQtyRaw - $deliveredQtyRaw);

/* ------------------------
 * 4) POST: Teslim Al (SADECE bu satır)
 * ---------------------- */
$alertError = null;
$alertSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'teslim_al') {

    // Aynı anda biri basarsa diye taze hesap
    // ST1: Birden fazla yöntemle ara
    $st1Headers = findST1Documents($sap, $docEntry, $sevkiyatDepo);
    
    // ST2: U_ASB2B_QutMaster ile
    $st2Headers = getStockTransfersByFilter(
        $sap,
        "U_ASB2B_QutMaster eq {$docEntry} and FromWarehouse eq '{$sevkiyatDepo}' and ToWarehouse eq '{$targetWarehouse}'"
    );

    $shippedQtyRaw   = sumQtyForLineFromStockTransfers($sap, $st1Headers, $itemCode, $lineNum);
    $deliveredQtyRaw = sumQtyForLineFromStockTransfers($sap, $st2Headers, $itemCode, $lineNum);
    $remainingToDeliver = max(0.0, $shippedQtyRaw - $deliveredQtyRaw);

    if ($shippedQtyRaw <= 0) {
        // Debug: Tüm ST1'leri kontrol et
        $debugInfo = [];
        $debugInfo[] = "Aranan Sevkiyat Depo: {$sevkiyatDepo}";
        $debugInfo[] = "Bulunan ST1 belge sayısı: " . count($st1Headers);
        
        // Bulunan ST1'lerin detayları
        foreach ($st1Headers as $st1) {
            $st1Doc = (int)($st1['DocEntry'] ?? 0);
            if ($st1Doc > 0) {
                $st1HeaderToWhs = (string)($st1['ToWarehouse'] ?? '');
                $st1Lines = getStockTransferLines($sap, $st1Doc);
                $debugInfo[] = "ST1 DocEntry: {$st1Doc}, Header ToWarehouse: {$st1HeaderToWhs}, Satır sayısı: " . count($st1Lines);
                foreach ($st1Lines as $ln) {
                    $it = (string)($ln['ItemCode'] ?? '');
                    $bl = (int)($ln['BaseLine'] ?? -1);
                    $qty = (float)($ln['Quantity'] ?? 0);
                    $lineToWhs = (string)($ln['WarehouseCode'] ?? '');
                    if ($it === $itemCode) {
                        $debugInfo[] = "  - ItemCode: {$it}, BaseLine: {$bl}, Quantity: {$qty}, Line ToWarehouse: {$lineToWhs}";
                    }
                }
            }
        }
        
        // Eğer hiç ST1 bulunamadıysa, U_ASB2B_QutMaster ile tüm ST'leri kontrol et (ToWarehouse filtresi olmadan)
        if (empty($st1Headers)) {
            $allSTs = getStockTransfersByFilter($sap, "U_ASB2B_QutMaster eq {$docEntry}");
            $debugInfo[] = "\nU_ASB2B_QutMaster ile bulunan tüm ST belgeleri (ToWarehouse filtresi olmadan): " . count($allSTs);
            foreach ($allSTs as $st) {
                $stDoc = (int)($st['DocEntry'] ?? 0);
                $stHeaderToWhs = (string)($st['ToWarehouse'] ?? '');
                $stHeaderFromWhs = (string)($st['FromWarehouse'] ?? '');
                $debugInfo[] = "  - ST DocEntry: {$stDoc}, FromWarehouse: {$stHeaderFromWhs}, ToWarehouse: {$stHeaderToWhs}";
                if ($stDoc > 0) {
                    $stLines = getStockTransferLines($sap, $stDoc);
                    foreach ($stLines as $ln) {
                        $it = (string)($ln['ItemCode'] ?? '');
                        $lineToWhs = (string)($ln['WarehouseCode'] ?? '');
                        if ($it === $itemCode) {
                            $debugInfo[] = "    * ItemCode: {$it}, Line ToWarehouse: {$lineToWhs}";
                        }
                    }
                }
            }
        }
        
        $alertError = "❌ Bu satır için sevk (ST1) bulunamadı. Teslim alma yapılamaz.\n\nDetay:\n- DocEntry: {$docEntry}\n- ItemCode: {$itemCode}\n- LineNum: {$lineNum}\n- Sevkiyat Depo: {$sevkiyatDepo}\n\nDebug Bilgisi:\n" . implode("\n", $debugInfo) . "\n\nNot: ST1 belgesi henüz oluşturulmamış olabilir. Lütfen önce 'Onayla' işlemini yapın.";
    } elseif ($remainingToDeliver <= 0) {
        $alertError = "❌ Bu satır için teslim edilecek kalan miktar yok.\n\nDetay:\n- Sevk Miktarı: {$shippedQtyRaw}\n- Teslim Edilen: {$deliveredQtyRaw}\n- Kalan: {$remainingToDeliver}";
    } else {
        $eksikFazla = (float)($_POST['eksik_fazla'] ?? 0);
        $kusurlu    = max(0.0, (float)($_POST['kusurlu'] ?? 0));
        $not        = trim((string)($_POST['not'] ?? ''));

        $physicalQty = max(0.0, $requestedQty + $eksikFazla);

        // Server-side güvenlik: kalan sevki aşma
        $eps = 0.00001;
        if ($physicalQty - $remainingToDeliver > $eps) {
            $alertError = "❌ Fiziksel miktar, kalan sevk miktarını aşamaz!\n\nDetay:\n- Fiziksel Miktar: {$physicalQty}\n- Kalan Sevk: {$remainingToDeliver}\n- Fark: " . ($physicalQty - $remainingToDeliver);
        } elseif ($physicalQty <= 0) {
            $alertError = "❌ Fiziksel miktar 0 olamaz.\n\nDetay:\n- Talep: {$requestedQty}\n- Eksik/Fazla: {$eksikFazla}\n- Fiziksel: {$physicalQty}";
        } elseif ($kusurlu - $physicalQty > $eps) {
            $alertError = "❌ Kusurlu miktar fiziksel miktarı aşamaz.\n\nDetay:\n- Fiziksel: {$physicalQty}\n- Kusurlu: {$kusurlu}";
        } elseif ($kusurlu > 0 && !$fireZayiWarehouse) {
            $alertError = "❌ Fire & Zayi deposu bulunamadı!\n\nDetay:\n- U_AS_OWNR: {$uAsOwnr}\n- Branch: {$branch}";
        }

        if (!$alertError) {
            // Normal teslim (target'a girecek net)
            $netToTarget = max(0.0, $physicalQty - $kusurlu);

            $transferLines = [];

            if ($netToTarget > 0) {
                $transferLines[] = [
                    'ItemCode'          => $itemCode,
                    'Quantity'          => $netToTarget,
                    'FromWarehouseCode' => $sevkiyatDepo,
                    'WarehouseCode'     => $targetWarehouse
                ];
            }

            // Kusurlu varsa fire/zayi'ye çıkar
            if ($kusurlu > 0) {
                $transferLines[] = [
                    'ItemCode'          => $itemCode,
                    'Quantity'          => $kusurlu,
                    'FromWarehouseCode' => $targetWarehouse,
                    'WarehouseCode'     => $fireZayiWarehouse,
                    'U_ASB2B_Damaged'   => 'K',
                    'U_ASB2B_Comments'  => "Kusurlu: {$kusurlu}" . ($not !== '' ? " | {$not}" : '')
                ];
            }

            if (empty($transferLines)) {
                $alertError = "❌ İşlenecek miktar yok.\n\nDetay:\n- Net To Target: {$netToTarget}\n- Kusurlu: {$kusurlu}";
            } else {
                // ÖNCE StockTransfer oluştur (belge açıkken)
                // 1) ST2 oluştur
                $stockTransferPayload = [
                    'FromWarehouse'      => $sevkiyatDepo,
                    'ToWarehouse'        => $targetWarehouse,
                    'DocDate'            => date('Y-m-d'),
                    'Comments'           => "Teslim Alma - ITR: {$docEntry} | {$itemCode} | Line: {$lineNum}" . ($not !== '' ? " | {$not}" : ''),
                    'U_ASB2B_BRAN'       => $branch,
                    'U_AS_OWNR'          => $uAsOwnr,
                    'U_ASB2B_STATUS'     => '4',
                    'U_ASB2B_TYPE'       => 'TRANSFER',
                    'U_ASB2B_User'       => $_SESSION["UserName"] ?? '',
                    'U_ASB2B_QutMaster'  => (int)$docEntry,
                    'StockTransferLines' => $transferLines,
                    // 🔥 EKLENDİ: DocumentReferences (Danışman tavsiyesi)
                    'DocumentReferences' => [
                        [
                            'RefDocEntr' => (int)$docEntry,
                            // Belge tipi (Obje Tipi): 1250000001 
                            'RefObjType' => 1250000001 
                        ]
                    ]
                ];

                $postRes = $sap->post('StockTransfers', $stockTransferPayload);
                $postStatus = (int)($postRes['status'] ?? 0);

                if ($postStatus == 200 || $postStatus == 201) {
                    
                    // 2) StockTransfer başarıyla oluşturuldu, şimdi status güncelle
                    // 🔥 ÖNEMLİ: Yeni oluşturulan ST2'yi de dahil etmek için ST2'leri yeniden çek
                    $st2HeadersFresh = getStockTransfersByFilter(
                        $sap,
                        "U_ASB2B_QutMaster eq {$docEntry} and FromWarehouse eq '{$sevkiyatDepo}' and ToWarehouse eq '{$targetWarehouse}'"
                    );
                    $deliveredQtyRawFresh = sumQtyForLineFromStockTransfers($sap, $st2HeadersFresh, $itemCode, $lineNum);
                    
                    // Bu satırın yeni status'u: bu işlemden sonra toplam teslim >= sevk ise 4, değilse 3
                    $newLineStatus = ($deliveredQtyRawFresh + $eps >= $shippedQtyRaw) ? '4' : '3';

                    // 🔥 DEBUG: Hesaplama bilgileri
                    $debugStatusInfo = [
                        "Sevk Miktarı (ST1): {$shippedQtyRaw}",
                        "Önceki Teslim (ST2 - eski): {$deliveredQtyRaw}",
                        "Yeni Teslim (ST2 - fresh): {$deliveredQtyRawFresh}",
                        "Bu İşlem Miktarı: {$physicalQty}",
                        "Hesaplanan Satır Status: {$newLineStatus}"
                    ];

                    /**
                     * KRİTİK: Diğer satırlara dokunma.
                     * Header status'u ise, view'den mevcut satır status'lerini alıp sadece bu satırı güncelleyerek hesapla.
                     * NOT: View'i yeniden çekiyoruz çünkü önceki işlemlerden sonra güncel olmayabilir.
                     */
                    $freshViewRows = getRequestLinesFromView($sap, $docEntry);
                    $allStatuses = [];
                    foreach ($freshViewRows as $r) {
                        $ln = (int)($r['LineNum'] ?? -1);
                        if ($ln < 0) continue;
                        $allStatuses[$ln] = (string)($r['U_ASB2B_STATUS'] ?? '0');
                    }
                    $allStatuses[$lineNum] = $newLineStatus;

                    $computedHeaderStatus = '1';
                    $has3or4 = false;
                    $all4 = true;
                    foreach ($allStatuses as $st) {
                        if ($st === '3' || $st === '4') $has3or4 = true;
                        if ($st !== '4') $all4 = false;
                    }
                    if ($all4) $computedHeaderStatus = '4';
                    else if ($has3or4) $computedHeaderStatus = '3';

                    // 🔥 DEBUG: Tüm satır status'leri
                    $debugStatusInfo[] = "Tüm Satır Status'leri: " . json_encode($allStatuses, JSON_UNESCAPED_UNICODE);
                    $debugStatusInfo[] = "Hesaplanan Header Status: {$computedHeaderStatus}";

                    // ITR güncelle: SADECE LineNum + Header status
                    $updatePayload = [
                        'U_ASB2B_STATUS' => $computedHeaderStatus,
                        'StockTransferLines' => [
                            [
                                'LineNum'        => $lineNum,
                                'U_ASB2B_STATUS' => $newLineStatus
                            ]
                        ]
                    ];
                    
                    // 🔥 DEBUG: PATCH payload
                    error_log("[TRANSFER-TESLIMAL] PATCH Payload: " . json_encode($updatePayload, JSON_UNESCAPED_UNICODE));
                    
                    $patchRes = $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);
                    $patchStatus = (int)($patchRes['status'] ?? 0);

                    // 🔥 DEBUG: PATCH sonucu
                    error_log("[TRANSFER-TESLIMAL] PATCH Response: HTTP {$patchStatus} | " . json_encode($patchRes['response'] ?? [], JSON_UNESCAPED_UNICODE));

                    // PATCH sonucu kontrol
                    if ($patchStatus >= 400) {
                        $alertError = "❌ Status güncellenemedi!\n\nDetay:\n- HTTP Status: {$patchStatus}\n- Response: " . json_encode($patchRes['response'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    } elseif (isset($patchRes['response']['error'])) {
                        $alertError = "❌ Status güncelleme hatası!\n\nDetay:\n- Error: " . json_encode($patchRes['response']['error'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    } else {
                        // Hepsi tamamlandıysa SAP tarafında Close
                        if ($computedHeaderStatus === '4') {
                            $closeRes = $sap->post("InventoryTransferRequests({$docEntry})/Close", []);
                            $closeStatus = (int)($closeRes['status'] ?? 0);
                            if ($closeStatus >= 400) {
                                error_log("[TRANSFER-TESLIMAL] Close işlemi başarısız: HTTP {$closeStatus}");
                            }
                        }

                        $alertSuccess = "✅ Satır başarıyla teslim alındı!\n\nDetay:\n- ItemCode: {$itemCode}\n- LineNum: {$lineNum}\n- Satır Status: {$newLineStatus}\n- Header Status: {$computedHeaderStatus}\n- StockTransfer oluşturuldu (HTTP {$postStatus})\n\nDebug:\n" . implode("\n", $debugStatusInfo);
                    }
                } else {
                    // StockTransfer oluşturulamadı
                    $errMsg = "❌ StockTransfer (ST2) oluşturulamadı!\n\nDetay:\n- HTTP Status: {$postStatus}";
                    if (isset($postRes['response']['error'])) {
                        $errMsg .= "\n- Error: " . json_encode($postRes['response']['error'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    }
                    if (isset($postRes['response'])) {
                        $errMsg .= "\n- Full Response: " . json_encode($postRes['response'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    }
                    $errMsg .= "\n\nPayload:\n" . json_encode($stockTransferPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    $alertError = $errMsg;
                }
            }
        }
    }
}

/* ------------------------
 * 5) Ekran değerleri
 * ---------------------- */
$sevkQty = $remainingToDeliver; // Bu sayfa "bu satır için kalan sevk" mantığıyla çalışır
$eksikFazlaDefault = 0;

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Teslim Al - MINOA</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #f5f7fa; color: #2c3e50; }
        .main-content { width: 100%; min-height: 100vh; background: whitesmoke; }
        .page-header { background: white; padding: 20px 2rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:10; }
        .page-header h2 { color:#1e40af; font-size:1.4rem; font-weight:700; }
        .card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); padding: 1.5rem; margin: 24px 32px; }
        .btn { padding: 0.75rem 1.25rem; border: none; border-radius: 8px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
        .btn-primary { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; }
        .btn-secondary { background: white; color: #3b82f6; border: 2px solid #3b82f6; }
        table { width:100%; border-collapse: collapse; margin-top: 12px; }
        thead { background: #2563eb; color: white; }
        th, td { padding: 12px; border-bottom: 1px solid #e5e7eb; }
        th:nth-child(n+3), td:nth-child(n+3) { text-align:center; }
        .qty-input { width: 110px; text-align: center; padding: 0.5rem; border: 2px solid #e5e7eb; border-radius: 6px; }
        .qty-input[readonly] { background:#f3f4f6; }
        .controls { display:flex; gap:8px; justify-content:center; align-items:center; }
        .qty-btn { width: 42px; height: 38px; border: 2px solid #3b82f6; background: white; color:#3b82f6; border-radius: 6px; font-weight:900; cursor:pointer; }
        .form-actions { display:flex; gap:12px; justify-content:flex-end; margin-top: 16px; }
        .eksik-neg { color:#dc2626 !important; }
        .eksik-pos { color:#16a34a !important; }
        .eksik-zero{ color:#6b7280 !important; }
    </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<main class="main-content">
    <header class="page-header">
        <h2>Teslim Al - Transfer No: <?= htmlspecialchars((string)$docEntry) ?> | <?= htmlspecialchars($itemCode) ?> (Line <?= (int)$lineNum ?>)</h2>
        <button class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=incoming'">← Geri Dön</button>
    </header>

    <div class="card">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
            <div><div style="font-size:12px;color:#6b7280;font-weight:700;">Gönderen Depo</div><div style="font-weight:700;"><?= htmlspecialchars($fromWarehouse) ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;font-weight:700;">Hedef (ITR)</div><div style="font-weight:700;"><?= htmlspecialchars($toWarehouse) ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;font-weight:700;">Tarih / Vade</div><div style="font-weight:700;"><?= $docDate ?> / <?= $dueDate ?></div></div>
            <div><div style="font-size:12px;color:#6b7280;font-weight:700;">Depo Akışı</div><div style="font-weight:700;"><?= htmlspecialchars($sevkiyatDepo) ?> → <?= htmlspecialchars($targetWarehouse) ?></div></div>
        </div>
    </div>

    <form method="POST" action="" onsubmit="return validateForm();">
        <input type="hidden" name="action" value="teslim_al">

        <div class="card">
            <h3 style="color:#1e40af;margin-bottom:10px;"><?= htmlspecialchars($itemName) ?></h3>

            <table>
                <thead>
                    <tr>
                        <th>Kalem Kodu</th>
                        <th>Kalem Tanımı</th>
                        <th>Talep</th>
                        <th>Kalan Sevk</th>
                        <th>Eksik/Fazla</th>
                        <th>Kusurlu</th>
                        <th>Fiziksel</th>
                        <th>Not</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= htmlspecialchars($itemCode) ?></td>
                        <td><?= htmlspecialchars($itemName) ?></td>
                        <td>
                            <div class="controls">
                                <input type="number" id="talep" value="<?= htmlspecialchars((string)$requestedQty) ?>" readonly step="0.01" class="qty-input">
                                <span>AD</span>
                            </div>
                        </td>
                        <td>
                            <div class="controls">
                                <input type="number" id="sevk" value="<?= htmlspecialchars((string)$sevkQty) ?>" readonly step="0.01" class="qty-input">
                                <span>AD</span>
                            </div>
                        </td>
                        <td>
                            <div class="controls">
                                <button type="button" class="qty-btn" onclick="changeEksikFazla(-1)">-</button>
                                <input type="number" name="eksik_fazla" id="eksik" value="<?= htmlspecialchars((string)$eksikFazlaDefault) ?>" step="0.01" class="qty-input" oninput="updateEksikColor(); calculatePhysical();">
                                <button type="button" class="qty-btn" onclick="changeEksikFazla(1)">+</button>
                            </div>
                        </td>
                        <td>
                            <div class="controls">
                                <button type="button" class="qty-btn" onclick="changeKusurlu(-1)">-</button>
                                <input type="number" name="kusurlu" id="kusurlu" value="0" min="0" step="0.01" class="qty-input" oninput="calculatePhysical();">
                                <button type="button" class="qty-btn" onclick="changeKusurlu(1)">+</button>
                            </div>
                        </td>
                        <td>
                            <div class="controls">
                                <input type="text" id="fiziksel" value="0" readonly class="qty-input">
                                <span>AD</span>
                            </div>
                        </td>
                        <td>
                            <input type="text" name="not" placeholder="Not" style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;">
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=incoming'">İptal</button>
                <button type="submit" class="btn btn-primary">✓ Teslim Al / Onayla</button>
            </div>
        </div>
    </form>
</main>

<script>
function changeEksikFazla(delta) {
    const i = document.getElementById('eksik');
    i.value = (parseFloat(i.value) || 0) + delta;
    updateEksikColor();
    calculatePhysical();
}

function changeKusurlu(delta) {
    const k = document.getElementById('kusurlu');
    let v = (parseFloat(k.value) || 0) + delta;
    if (v < 0) v = 0;
    k.value = v;
    calculatePhysical();
}

function updateEksikColor() {
    const i = document.getElementById('eksik');
    const v = parseFloat(i.value) || 0;
    i.classList.remove('eksik-neg','eksik-pos','eksik-zero');
    if (v < 0) i.classList.add('eksik-neg');
    else if (v > 0) i.classList.add('eksik-pos');
    else i.classList.add('eksik-zero');
}

function calculatePhysical() {
    const talep = parseFloat(document.getElementById('talep').value) || 0;
    const eksik = parseFloat(document.getElementById('eksik').value) || 0;
    const kusurlu = parseFloat(document.getElementById('kusurlu').value) || 0;

    let fiziksel = talep + eksik;
    if (fiziksel < 0) fiziksel = 0;

    if (kusurlu > fiziksel) {
        document.getElementById('kusurlu').value = fiziksel;
    }

    document.getElementById('fiziksel').value = (Math.floor(fiziksel) === fiziksel) ? String(Math.floor(fiziksel)) : fiziksel.toFixed(2);
}

function validateForm() {
    const talep = parseFloat(document.getElementById('talep').value) || 0;
    const eksik = parseFloat(document.getElementById('eksik').value) || 0;
    const fiziksel = talep + eksik;
    if (fiziksel <= 0) {
        alert('Fiziksel miktar 0 olamaz!');
        return false;
    }
    return true;
}

document.addEventListener('DOMContentLoaded', () => {
    updateEksikColor();
    calculatePhysical();
    
    <?php if ($alertError !== null): ?>
    alert(<?= json_encode($alertError, JSON_UNESCAPED_UNICODE) ?>);
    <?php endif; ?>
    
    <?php if ($alertSuccess !== null): ?>
    if (confirm(<?= json_encode($alertSuccess, JSON_UNESCAPED_UNICODE) ?> + "\n\nListe sayfasına dönmek ister misiniz?")) {
        window.location.href = 'Transferler.php?view=incoming';
    }
    <?php endif; ?>
});
</script>
</body>
</html>
