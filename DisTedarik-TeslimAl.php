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

$requestNo = $_GET['requestNo'] ?? '';
$orderNo = $_GET['orderNo'] ?? null;

if (empty($requestNo)) {
    die("Talep No bulunamadı!");
}

if (empty($orderNo)) {
    die("Sipariş No bulunamadı! Teslim almak için sipariş oluşturulmuş olmalıdır.");
}

$errorMsg = '';
$warningMsg = '';
$cardCode = '';
$cardName = '';
$orderDocEntry = null;
$orderDocNum = null;
$orderDocDate = '';
$orderDocDueDate = '';
$defaultIrsaliyeNo = '';
$lines = [];
$isClosed = false;
$canReceive = true; // Varsayılan olarak teslim alınabilir
$docStatus = null;

// Spec'e göre: GET /b1s/v2/PurchaseOrders(7673)
// Fallback: GET /b1s/v2/PurchaseOrders?$filter=DocNum eq '7673'
$orderQuery = 'PurchaseOrders(' . intval($orderNo) . ')';
$orderData = $sap->get($orderQuery);

$debugInfo = [];
$debugInfo['query'] = $orderQuery;
$debugInfo['http_status'] = $orderData['status'] ?? 'NO STATUS';
$debugInfo['has_response'] = isset($orderData['response']);
$debugInfo['error'] = $orderData['error'] ?? null;
$debugInfo['response_error'] = $orderData['response']['error'] ?? null;

if (($orderData['status'] ?? 0) == 200 && isset($orderData['response'])) {
    $purchaseOrderData = $orderData['response'];
    $cardCode = $purchaseOrderData['CardCode'] ?? '';
    $cardName = $purchaseOrderData['CardName'] ?? '';
    $orderDocEntry = $purchaseOrderData['DocEntry'] ?? null;
    $orderDocNum = $purchaseOrderData['DocNum'] ?? null;
    $orderDocDate = $purchaseOrderData['DocDate'] ?? '';
    $orderDocDueDate = $purchaseOrderData['DocDueDate'] ?? '';
    $defaultIrsaliyeNo = $purchaseOrderData['U_ASB2B_NumAtCard'] ?? '';
    
    // Purchase Order durumunu kontrol et ve debug bilgilerini logla
    $docStatus = $purchaseOrderData['DocumentStatus'] ?? $purchaseOrderData['DocStatus'] ?? null;
    $isClosed = ($docStatus === 'C' || $docStatus === 'Closed' || $docStatus === 'c');
    
    // Debug: Purchase Order durumunu logla
    error_log("[DIS_TEDARIK_TESLIM] PurchaseOrder Status Check: DocStatus=" . ($docStatus ?? 'NULL') . ", IsClosed=" . ($isClosed ? 'YES' : 'NO'));
    error_log("[DIS_TEDARIK_TESLIM] PurchaseOrder Keys: " . implode(', ', array_keys($purchaseOrderData)));
    
    // ✅ Kapalı sipariş kontrolü - Teslim alınamaz
    if ($isClosed) {
        $canReceive = false;
        $warningMsg = "⚠️ Bu sipariş KAPALI durumda! Teslim Al yapılamaz. Sipariş No: " . htmlspecialchars($orderNo) . " - Durum: " . ($docStatus ?? 'Kapalı');
        error_log("[DIS_TEDARIK_TESLIM] PurchaseOrder is CLOSED - Receiving is NOT allowed");
    }
    
    // ✅ ÖNEMLİ: Satır sorgusunda DocEntry kullan (DocNum değil!)
    // orderDocEntry artık her zaman doğru (yukarıda resolve edildi)
    $baseEntry = $orderDocEntry;
    
    if (!$baseEntry) {
        $errorMsg = "Sipariş DocEntry bulunamadı! Sipariş No: " . htmlspecialchars($orderNo);
    }
    
    // $lines değişkenini başlat
    $lines = [];
    
    // ✅ ÖNEMLİ: PurchaseRequest'ten talep miktarlarını çek (ItemCode bazında eşleştirme için)
    $requestQuantities = []; // ItemCode => Quantity mapping
    if (!empty($requestNo)) {
        $requestQuery = 'PurchaseRequests(' . intval($requestNo) . ')';
        $requestData = $sap->get($requestQuery);
        
        if (($requestData['status'] ?? 0) == 200 && isset($requestData['response'])) {
            // PurchaseRequest satırlarını çek
            $requestLinesQuery = "PurchaseRequests(" . intval($requestNo) . ")/DocumentLines";
            $requestLinesData = $sap->get($requestLinesQuery);
            
            if (($requestLinesData['status'] ?? 0) == 200 && isset($requestLinesData['response'])) {
                $requestLines = [];
                $resp = $requestLinesData['response'];
                
                // ✅ Robust parsing: Farklı response formatlarını destekle
                if (isset($resp['value']) && is_array($resp['value'])) {
                    $requestLines = $resp['value'];
                    error_log("[DIS_TEDARIK_TESLIM] PurchaseRequest lines found in 'value' key, count: " . count($requestLines));
                } elseif (isset($resp['DocumentLines']) && is_array($resp['DocumentLines'])) {
                    $requestLines = $resp['DocumentLines'];
                    error_log("[DIS_TEDARIK_TESLIM] PurchaseRequest lines found in 'DocumentLines' key, count: " . count($requestLines));
                } elseif (is_array($resp) && !isset($resp['@odata.context'])) {
                    // Direct array
                    $requestLines = $resp;
                    error_log("[DIS_TEDARIK_TESLIM] PurchaseRequest lines found as direct array, count: " . count($requestLines));
                } else {
                    error_log("[DIS_TEDARIK_TESLIM] ⚠️ PurchaseRequest lines response format not recognized. Keys: " . implode(', ', array_keys($resp)));
                    // Debug: Full response'u logla
                    $logDir = __DIR__ . '/logs';
                    if (!is_dir($logDir)) {
                        @mkdir($logDir, 0755, true);
                    }
                    file_put_contents(
                        $logDir . '/pr_lines_response_' . date('Ymd_His') . '_' . $requestNo . '.json',
                        json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    );
                }
                
                // ItemCode bazında Quantity mapping oluştur
                foreach ($requestLines as $reqLine) {
                    if (is_array($reqLine) && isset($reqLine['ItemCode'])) {
                        $itemCode = $reqLine['ItemCode'];
                        $reqQuantity = floatval($reqLine['Quantity'] ?? 0);
                        // Eğer aynı ItemCode birden fazla satırda varsa, topla
                        if (isset($requestQuantities[$itemCode])) {
                            $requestQuantities[$itemCode] += $reqQuantity;
                        } else {
                            $requestQuantities[$itemCode] = $reqQuantity;
                        }
                        error_log("[DIS_TEDARIK_TESLIM] PurchaseRequest line: ItemCode={$itemCode}, Quantity={$reqQuantity}");
                    }
                }
                
                error_log("[DIS_TEDARIK_TESLIM] PurchaseRequest quantities loaded: " . json_encode($requestQuantities));
            } else {
                error_log("[DIS_TEDARIK_TESLIM] ⚠️ PurchaseRequest lines query failed! HTTP Status: " . ($requestLinesData['status'] ?? 'NO STATUS'));
            }
        }
    }
    
    // ✅ $expand ile tek çağrıda hem header hem satırları al (daha güvenli)
    $expandQuery = "PurchaseOrders({$baseEntry})"
        . "?\$select=DocEntry,DocNum,DocDate,DocDueDate,CardCode,CardName,U_ASB2B_NumAtCard,DocumentStatus,DocStatus"
        . "&\$expand=DocumentLines(\$select=LineNum,ItemCode,ItemDescription,Quantity,RemainingOpenQuantity,OpenQuantity,UoMCode)";
    
    $expandData = $sap->get($expandQuery);
    
    $debugInfo['lines_query'] = $expandQuery;
    $debugInfo['lines_http_status'] = $expandData['status'] ?? 'NO STATUS';
    
    // Eğer expand başarılıysa, satırları direkt al
    if (($expandData['status'] ?? 0) == 200 && isset($expandData['response'])) {
        $poData = $expandData['response'];
        
        // Header bilgilerini güncelle (eğer eksikse)
        if (empty($cardCode) && isset($poData['CardCode'])) {
            $cardCode = $poData['CardCode'];
            $cardName = $poData['CardName'] ?? '';
            $orderDocEntry = $poData['DocEntry'] ?? $orderDocEntry;
            $orderDocNum = $poData['DocNum'] ?? $orderDocNum;
            $orderDocDate = $poData['DocDate'] ?? $orderDocDate;
            $orderDocDueDate = $poData['DocDueDate'] ?? $orderDocDueDate;
            $defaultIrsaliyeNo = $poData['U_ASB2B_NumAtCard'] ?? $defaultIrsaliyeNo;
        }
        
        // Satırları al - $expand response'unda da farklı formatlar olabilir
        if (isset($poData['DocumentLines'])) {
            if (is_array($poData['DocumentLines']) && !empty($poData['DocumentLines'])) {
                $lines = $poData['DocumentLines'];
                error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines via \$expand (DocumentLines array), count: " . count($lines));
            } elseif (is_string($poData['DocumentLines']) && strpos($poData['DocumentLines'], 'PurchaseOrders') !== false) {
                // Navigation link - takip et
                $navLink = $poData['DocumentLines'];
                error_log("[DIS_TEDARIK_TESLIM] \$expand returned navigation link: " . $navLink);
                $navRes = $sap->get($navLink);
                if (($navRes['status'] ?? 0) == 200 && isset($navRes['response'])) {
                    if (isset($navRes['response']['value']) && is_array($navRes['response']['value'])) {
                        $lines = $navRes['response']['value'];
                        error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines via \$expand navigation link (value), count: " . (is_array($lines) ? count($lines) : 0));
                    } elseif (isset($navRes['response']['DocumentLines']) && is_array($navRes['response']['DocumentLines'])) {
                        $lines = $navRes['response']['DocumentLines'];
                        error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines via \$expand navigation link (DocumentLines), count: " . (is_array($lines) ? count($lines) : 0));
                    }
                }
            } else {
                error_log("[DIS_TEDARIK_TESLIM] ⚠️ WARNING: \$expand DocumentLines is neither array nor navigation link. Type: " . gettype($poData['DocumentLines']));
            }
        } else {
            error_log("[DIS_TEDARIK_TESLIM] ⚠️ WARNING: \$expand response has no DocumentLines key");
            // $expand başarısız, fallback'e düş
        }
    }
    
    // Eğer $expand başarısız olduysa veya satırlar bulunamadıysa, fallback kullan
    if (empty($lines)) {
        // Fallback: Eski yöntem (ayrı çağrı)
        $linesQuery = "PurchaseOrders({$baseEntry})/DocumentLines";
        $linesData = $sap->get($linesQuery);
        
        $debugInfo['lines_query'] = $linesQuery;
        $debugInfo['lines_http_status'] = $linesData['status'] ?? 'NO STATUS';
        
        if (($linesData['status'] ?? 0) == 200 && isset($linesData['response'])) {
            $resp = $linesData['response'] ?? [];
            
            // Debug: Response yapısını logla
            $responseKeys = array_keys($resp);
            error_log("[DIS_TEDARIK_TESLIM] Lines Response Keys: " . implode(', ', $responseKeys));
            
            // ✅ ROBUST PARSER: SAP SL'in farklı response formatlarını destekle
            // Format 1: { "value": [...] } - Klasik OData formatı
            if (isset($resp['value']) && is_array($resp['value']) && !empty($resp['value'])) {
                $lines = $resp['value'];
                error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines in 'value' key, count: " . count($lines));
            }
            // Format 2: { "DocumentLines": [...] } - Yeni build formatı
            elseif (isset($resp['DocumentLines'])) {
                if (is_array($resp['DocumentLines']) && !empty($resp['DocumentLines'])) {
                    $lines = $resp['DocumentLines'];
                    error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines in 'DocumentLines' key (array), count: " . count($lines));
                } 
                // Format 3: DocumentLines bir string (navigation link) mi?
                elseif (is_string($resp['DocumentLines']) && strpos($resp['DocumentLines'], 'PurchaseOrders') !== false) {
                    $navLink = $resp['DocumentLines'];
                    error_log("[DIS_TEDARIK_TESLIM] Found DocumentLines as navigation link: " . $navLink);
                    
                    // Navigation link'i takip et
                    $navRes = $sap->get($navLink);
                    if (($navRes['status'] ?? 0) == 200 && isset($navRes['response'])) {
                        // Navigation link response'unda value veya DocumentLines olabilir
                        if (isset($navRes['response']['value']) && is_array($navRes['response']['value'])) {
                            $lines = $navRes['response']['value'];
                            error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines via navigation link (value), count: " . (is_array($lines) ? count($lines) : 0));
                        } elseif (isset($navRes['response']['DocumentLines']) && is_array($navRes['response']['DocumentLines'])) {
                            $lines = $navRes['response']['DocumentLines'];
                            error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines via navigation link (DocumentLines), count: " . (is_array($lines) ? count($lines) : 0));
                        }
                    }
                } else {
                    // DocumentLines var ama ne array ne string - debug için logla
                    error_log("[DIS_TEDARIK_TESLIM] ⚠️ WARNING: DocumentLines is neither array nor navigation link. Type: " . gettype($resp['DocumentLines']));
                    error_log("[DIS_TEDARIK_TESLIM] DocumentLines value (first 200 chars): " . substr(json_encode($resp['DocumentLines']), 0, 200));
                }
            }
            // Format 4: Navigation link var mı? (DocumentLines@odata.navigationLink)
            elseif (isset($resp['DocumentLines@odata.navigationLink'])) {
                $navLink = $resp['DocumentLines@odata.navigationLink'];
                error_log("[DIS_TEDARIK_TESLIM] Found navigation link: " . $navLink);
                
                // Navigation link'i takip et
                $navRes = $sap->get($navLink);
                if (($navRes['status'] ?? 0) == 200 && isset($navRes['response'])) {
                    if (isset($navRes['response']['value']) && is_array($navRes['response']['value'])) {
                        $lines = $navRes['response']['value'];
                        error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines via navigation link (value), count: " . (is_array($lines) ? count($lines) : 0));
                    } elseif (isset($navRes['response']['DocumentLines']) && is_array($navRes['response']['DocumentLines'])) {
                        $lines = $navRes['response']['DocumentLines'];
                        error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines via navigation link (DocumentLines), count: " . (is_array($lines) ? count($lines) : 0));
                    }
                }
            }
            // Format 5: Son çare - Response içinde @ ile başlamayan ve array olan herhangi bir key
            else {
                foreach ($resp as $key => $value) {
                    // @ ile başlayan metadata key'lerini atla
                    if (substr($key, 0, 1) === '@') {
                        continue;
                    }
                    
                    // Eğer value bir array ise ve içinde LineNum veya ItemCode gibi satır özellikleri varsa
                    if (is_array($value) && !empty($value)) {
                        // İlk elemanı kontrol et - satır gibi görünüyor mu?
                        $firstItem = $value[0] ?? null;
                        if (is_array($firstItem) && (isset($firstItem['LineNum']) || isset($firstItem['ItemCode']))) {
                            $lines = $value;
                            error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines in key '{$key}' (fallback), count: " . count($lines));
                            break;
                        }
                    }
                }
            }
            
            // Eğer hala satır bulunamadıysa, full response'u logla ve kapalı siparişler için alternatif dene
            if (empty($lines)) {
                $logDir = __DIR__ . '/logs';
                if (!is_dir($logDir)) {
                    @mkdir($logDir, 0755, true);
                }
                
                $logFile = $logDir . '/lines_response_' . date('Ymd_His') . '_' . $orderNo . '.json';
                file_put_contents(
                    $logFile,
                    json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                );
                
                error_log("[DIS_TEDARIK_TESLIM] ⚠️ WARNING: No valid lines found! Full response saved to: {$logFile}");
                error_log("[DIS_TEDARIK_TESLIM] Response keys: " . implode(', ', array_keys($resp)));
                error_log("[DIS_TEDARIK_TESLIM] DocStatus: " . ($docStatus ?? 'NULL') . " | IsClosed: " . ($isClosed ? 'YES' : 'NO'));
                
                // Kapalı siparişlerde alternatif query dene (bazen $expand çalışmıyor)
                if ($isClosed) {
                    error_log("[DIS_TEDARIK_TESLIM] Trying alternative query for CLOSED order (without \$expand)...");
                    
                    // Direkt DocumentLines endpoint'ini dene (kapalı siparişlerde bazen bu çalışır)
                    $altQuery = "PurchaseOrders({$baseEntry})/DocumentLines?\$select=LineNum,ItemCode,ItemDescription,Quantity,RemainingOpenQuantity,OpenQuantity,UoMCode";
                    $altData = $sap->get($altQuery);
                    
                    if (($altData['status'] ?? 0) == 200 && isset($altData['response'])) {
                        $altResp = $altData['response'] ?? [];
                        error_log("[DIS_TEDARIK_TESLIM] Alternative query response keys: " . implode(', ', array_keys($altResp)));
                        
                        // Aynı robust parse mantığını uygula
                        if (isset($altResp['value']) && is_array($altResp['value']) && !empty($altResp['value'])) {
                            $lines = $altResp['value'];
                            error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines via alternative query (value), count: " . count($lines));
                        } elseif (isset($altResp['DocumentLines']) && is_array($altResp['DocumentLines']) && !empty($altResp['DocumentLines'])) {
                            $lines = $altResp['DocumentLines'];
                            error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines via alternative query (DocumentLines), count: " . count($lines));
                        } elseif (isset($altResp['DocumentLines@odata.navigationLink'])) {
                            $navLink = $altResp['DocumentLines@odata.navigationLink'];
                            $navRes = $sap->get($navLink);
                            if (($navRes['status'] ?? 0) == 200 && isset($navRes['response'])) {
                                if (isset($navRes['response']['value']) && is_array($navRes['response']['value'])) {
                                    $lines = $navRes['response']['value'];
                                    error_log("[DIS_TEDARIK_TESLIM] ✅ Found lines via alternative navigation link, count: " . (is_array($lines) ? count($lines) : 0));
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Debug: LineNum değerlerini ve RemainingOpenQuantity'yi logla
    if (!empty($lines)) {
        foreach ($lines as $idx => $line) {
            if (is_array($line) && isset($line['LineNum'])) {
                $lineNum = $line['LineNum'] ?? 'NULL';
                $itemCode = $line['ItemCode'] ?? 'N/A';
                $quantity = $line['Quantity'] ?? 0;
                $remainingQty = $line['RemainingOpenQuantity'] ?? $line['OpenQuantity'] ?? null;
                error_log("[DIS_TEDARIK_TESLIM] PurchaseOrder Line[$idx]: LineNum=$lineNum, ItemCode=$itemCode, Quantity=$quantity, RemainingOpenQuantity=" . ($remainingQty ?? 'NULL'));
                
                // Eğer RemainingOpenQuantity = 0 ise, bu satır için teslim alma yapılamaz
                if ($remainingQty !== null && floatval($remainingQty) <= 0) {
                    error_log("[DIS_TEDARIK_TESLIM] WARNING: Line[$idx] has RemainingOpenQuantity=0, cannot deliver!");
                }
            } else {
                error_log("[DIS_TEDARIK_TESLIM] WARNING: Line[$idx] is not a valid array or missing LineNum: " . json_encode($line));
            }
        }
    }
} else {
    // Fallback: DocNum ile ara
    $fallbackQuery = "PurchaseOrders?\$filter=DocNum eq '{$orderNo}'";
    $fallbackData = $sap->get($fallbackQuery);
    
    if (($fallbackData['status'] ?? 0) == 200 && isset($fallbackData['response']['value']) && !empty($fallbackData['response']['value'])) {
        $purchaseOrderData = $fallbackData['response']['value'][0];
        $cardCode = $purchaseOrderData['CardCode'] ?? '';
        $cardName = $purchaseOrderData['CardName'] ?? '';
        $orderDocEntry = $purchaseOrderData['DocEntry'] ?? null;
        $orderDocNum = $purchaseOrderData['DocNum'] ?? null;
        $orderDocDate = $purchaseOrderData['DocDate'] ?? '';
        $orderDocDueDate = $purchaseOrderData['DocDueDate'] ?? '';
        $defaultIrsaliyeNo = $purchaseOrderData['U_ASB2B_NumAtCard'] ?? '';
        
        // ✅ Satırları çek (DocEntry ile, $expand kullan)
        if ($orderDocEntry) {
            $expandQuery = "PurchaseOrders({$orderDocEntry})"
                . "?\$expand=DocumentLines(\$select=LineNum,ItemCode,ItemDescription,Quantity,RemainingOpenQuantity,OpenQuantity,UoMCode)";
            
            $expandData = $sap->get($expandQuery);
            
            if (($expandData['status'] ?? 0) == 200 && isset($expandData['response']['DocumentLines'])) {
                $lines = $expandData['response']['DocumentLines'];
                error_log("[DIS_TEDARIK_TESLIM] Fallback: Found lines via \$expand, count: " . (is_array($lines) ? count($lines) : 0));
            } else {
                // Fallback: Eski yöntem
                $linesQuery = "PurchaseOrders({$orderDocEntry})/DocumentLines";
                $linesData = $sap->get($linesQuery);
                
                if (($linesData['status'] ?? 0) == 200 && isset($linesData['response'])) {
                    $resp = $linesData['response'] ?? [];
                    
                    if (isset($resp['value']) && is_array($resp['value'])) {
                        $lines = $resp['value'];
                    } elseif (isset($resp['DocumentLines']) && is_array($resp['DocumentLines'])) {
                        $lines = $resp['DocumentLines'];
                    } elseif (isset($resp['DocumentLines@odata.navigationLink'])) {
                        $navLink = $resp['DocumentLines@odata.navigationLink'];
                        $navRes = $sap->get($navLink);
                        if (($navRes['status'] ?? 0) == 200 && isset($navRes['response']['value'])) {
                            $lines = $navRes['response']['value'];
                        }
                    }
                    
                    error_log("[DIS_TEDARIK_TESLIM] Fallback: Lines found: " . count($lines ?? []));
                } else {
                    error_log("[DIS_TEDARIK_TESLIM] Fallback: Lines query failed! HTTP Status: " . ($linesData['status'] ?? 'NO STATUS'));
                }
            }
        }
    } else {
        $errorMsg = "Sipariş detayları alınamadı! HTTP " . ($orderData['status'] ?? 'NO STATUS');
        if (isset($orderData['response']['error'])) {
            $errorMsg .= " - " . json_encode($orderData['response']['error']);
        }
    }
}

// ✅ Spec'e göre: RemainingOpenQuantity <= 0 olan satırlar GİZLENMEMELİ, sadece DISABLE olmalı
// Kapalı siparişlerde tüm satırlar görünsün ama disable olsun
// ✅ Ayrıca PurchaseRequest'ten talep miktarını ekle
$processedLines = [];
foreach ($lines as $line) {
    // Satır geçerli mi kontrol et
    if (!is_array($line) || empty($line['ItemCode'])) {
        continue;
    }
    
    $remainingQty = floatval($line['RemainingOpenQuantity'] ?? $line['OpenQuantity'] ?? 0);
    $line['RemainingOpenQuantity'] = $remainingQty; // Normalize et
    $line['IsDisabled'] = ($remainingQty <= 0 || $isClosed); // RemainingQty = 0 veya sipariş kapalı ise disable
    
    // ✅ PurchaseRequest'ten talep miktarını ekle
    $itemCode = $line['ItemCode'] ?? '';
    if (isset($requestQuantities[$itemCode])) {
        $line['RequestedQuantity'] = $requestQuantities[$itemCode];
        error_log("[DIS_TEDARIK_TESLIM] ✅ ItemCode {$itemCode}: RequestedQty={$requestQuantities[$itemCode]}, OrderQty=" . ($line['Quantity'] ?? 0));
    } else {
        // Eğer PurchaseRequest'te bu ItemCode yoksa, PurchaseOrder Quantity'yi kullan
        $line['RequestedQuantity'] = floatval($line['Quantity'] ?? 0);
        error_log("[DIS_TEDARIK_TESLIM] ⚠️ ItemCode {$itemCode}: RequestedQty not found in PR (available keys: " . implode(', ', array_keys($requestQuantities)) . "), using OrderQty=" . ($line['Quantity'] ?? 0));
    }
    
    $processedLines[] = $line;
    
    if ($line['IsDisabled']) {
        error_log("[DIS_TEDARIK_TESLIM] Line will be DISABLED: ItemCode=" . ($line['ItemCode'] ?? 'N/A') . ", RemainingOpenQuantity=" . $remainingQty . ", IsClosed=" . ($isClosed ? 'YES' : 'NO'));
    }
}
$lines = $processedLines;

// Debug: İşlenmiş satır sayısını logla
error_log("[DIS_TEDARIK_TESLIM] Processed lines count: " . count($lines) . " (all lines shown, disabled if RemainingQty <= 0 or order is closed)");

// Kapalı siparişlerde teslim alma yapılamaz
if ($isClosed) {
    $canReceive = false;
}

// POST işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'teslim_al') 
    // ✅ Kapalı sipariş kontrolü - POST işleminde de kontrol et
    if ($isClosed) {
        $errorMsg = "Bu sipariş KAPALI durumda! Teslim Al yapılamaz. Sipariş No: " . htmlspecialchars($orderNo);
        error_log("[DIS_TEDARIK_TESLIM] POST REJECTED: PurchaseOrder is CLOSED, cannot create GRPO");
    } else {
        $deliveryLines = [];
        $teslimatNo = trim($_POST['teslimat_no'] ?? '');
        
        // ✅ ÖNEMLİ: Önce girilen miktarları kontrol et - RemainingOpenQuantity'yi aşan var mı?
        $warnings = [];
        $willCloseLines = [];
        $willCloseOrder = true; // Varsayılan: Tüm satırlar kapanacaksa sipariş de kapanır
        
        foreach ($lines as $index => $line) {
            if (!is_array($line) || !isset($line['ItemCode'])) {
                continue;
            }
            
            $remainingQty = floatval($line['RemainingOpenQuantity'] ?? $line['OpenQuantity'] ?? 0);
            $irsaliyeQty = floatval($_POST['irsaliye_qty'][$index] ?? 0);
            
            // Eğer bu satır için girilen miktar kalan miktarı tam karşılıyorsa veya aşıyorsa
            if ($irsaliyeQty > 0 && $remainingQty > 0 && $irsaliyeQty >= $remainingQty) {
                $willCloseLines[] = [
                    'index' => $index,
                    'itemCode' => $line['ItemCode'] ?? 'N/A',
                    'itemName' => $line['ItemDescription'] ?? 'N/A',
                    'remainingQty' => $remainingQty,
                    'irsaliyeQty' => $irsaliyeQty
                ];
                $warnings[] = "Kalem {$line['ItemCode']} ({$line['ItemDescription']}): Girilen miktar ({$irsaliyeQty}) kalan miktarı ({$remainingQty}) karşılıyor veya aşıyor. Bu satır kapanacak.";
            } elseif ($irsaliyeQty > 0 && $remainingQty > 0) {
                // Kısmi teslim - bu satır açık kalacak, sipariş de açık kalabilir
                $willCloseOrder = false;
            }
        }
        
        // Eğer uyarılar varsa ve kullanıcı onaylamadıysa, formu göster
        if (!empty($warnings) && !isset($_POST['confirm_close'])) {
            $errorMsg = "<strong>⚠️ Uyarı: Girilen irsaliye miktarları bazı satırların kalan miktarlarını tam karşılıyor!</strong><br><br>";
            $errorMsg .= "<ul style='margin-left: 20px;'>";
            foreach ($warnings as $warning) {
                $errorMsg .= "<li>" . htmlspecialchars($warning) . "</li>";
            }
            $errorMsg .= "</ul><br>";
            if ($willCloseOrder) {
                $errorMsg .= "<strong style='color: #dc2626;'>Bu işlem sonrasında sipariş KAPANACAKTIR!</strong><br><br>";
            } else {
                $errorMsg .= "Bu işlem sonrasında bazı satırlar kapanacak, ancak sipariş açık kalacaktır.<br><br>";
            }
            $errorMsg .= "Devam etmek istiyor musunuz?<br><br>";
            $errorMsg .= "<form method='POST' style='display: inline;'>";
            foreach ($_POST as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $errorMsg .= "<input type='hidden' name='" . htmlspecialchars($key) . "[" . htmlspecialchars($k) . "]' value='" . htmlspecialchars($v) . "'>";
                    }
                } else {
                    $errorMsg .= "<input type='hidden' name='" . htmlspecialchars($key) . "' value='" . htmlspecialchars($value) . "'>";
                }
            }
            $errorMsg .= "<input type='hidden' name='confirm_close' value='1'>";
            $errorMsg .= "<button type='submit' class='btn btn-primary' style='background: #dc2626; margin-right: 10px;'>Evet, Devam Et</button>";
            $errorMsg .= "<a href='DisTedarik-TeslimAl.php?requestNo=" . urlencode($requestNo) . "&orderNo=" . urlencode($orderNo) . "' class='btn btn-secondary'>İptal</a>";
            $errorMsg .= "</form>"; 
        } else {
            // Onaylandı veya uyarı yok, normal işleme devam et
            
            // ✅ İrsaliye numarası zorunlu kontrolü (server-side)
            $teslimatNo = trim($_POST['teslimat_no'] ?? '');
            if (empty($teslimatNo)) {
                $errorMsg = "⚠️ İrsaliye/Teslimat numarası zorunludur! Lütfen irsaliye numarası girin.";
                error_log("[DIS_TEDARIK_TESLIM] POST REJECTED: Teslimat numarası boş");
            } else {
                foreach ($lines as $index => $line) {
                    // Geçerli bir satır değilse atla (metadata key'leri gibi)
                    if (!is_array($line) || !isset($line['ItemCode'])) {
                        continue;
                    }
                    
                    $irsaliyeQty = floatval($_POST['irsaliye_qty'][$index] ?? 0);
                    if ($irsaliyeQty > 0) {
                // BaseLine: Purchase Order'dan gelen LineNum değerini kullan
                // SAP'de LineNum genellikle 0-indexed gelir (0, 1, 2...)
                // Eğer LineNum yoksa veya 0 ise, array index'ini kullan
                $lineNum = isset($line['LineNum']) ? intval($line['LineNum']) : null;
                
                if ($lineNum !== null) {
                    // LineNum varsa direkt kullan (zaten 0-indexed olmalı)
                    $baseLine = $lineNum;
                } else {
                    // LineNum yoksa array index'ini kullan
                    $baseLine = $index;
                }
                
                // Spec'e göre: Eksik/Fazla, Kusurlu ve Not alanlarını al
                $eksikFazlaQty = floatval($_POST['eksik_fazla'][$index] ?? 0);
                $kusurluQty = floatval($_POST['kusurlu'][$index] ?? 0);
                $not = trim($_POST['not'][$index] ?? '');
                
                // Debug: LineNum değerlerini logla
                error_log("[DIS_TEDARIK_TESLIM] Line[$index]: LineNum=" . ($lineNum ?? 'NULL') . ", BaseLine=$baseLine, ItemCode=" . ($line['ItemCode'] ?? 'N/A') . ", Quantity=$irsaliyeQty, EksikFazla=$eksikFazlaQty, Kusurlu=$kusurluQty");
                
                // UserFields oluştur (sadece dolu olanları ekle)
                $userFields = [];
                if ($eksikFazlaQty != 0) {
                    $userFields['U_ASB2B_SHORTAGE'] = $eksikFazlaQty; // Eksik/Fazla miktar (pozitif/negatif olabilir)
                }
                if ($kusurluQty > 0) {
                    $userFields['U_ASB2B_DEFECTQTY'] = $kusurluQty; // Kusurlu miktar
                }
                if (!empty($not)) {
                    $userFields['U_ASB2B_NOTES'] = $not; // Not
                }
                
                // BaseEntry için doğru DocEntry kullan (fallback senaryosunda $orderDocEntry kullanılmalı)
                $actualBaseEntry = $orderDocEntry ?? intval($orderNo);
                
                $deliveryLine = [
                    'BaseType' => 22, // Purchase Order (statik değer, her zaman 22)
                    'BaseEntry' => intval($actualBaseEntry), // PurchaseOrder.DocEntry
                    'BaseLine' => $baseLine, // PurchaseOrderLine.LineNum
                    'Quantity' => $irsaliyeQty // İrsaliye miktarı (kullanıcının girdiği)
                ];
                
                // UserFields varsa ekle
                if (!empty($userFields)) {
                    $deliveryLine['UserFields'] = $userFields;
                }
                
                // ✅ RemainingOpenQuantity = 0 olan satırları atla (POST'ta)
                $remainingQty = floatval($line['RemainingOpenQuantity'] ?? 0);
                if ($remainingQty > 0) {
                    $deliveryLines[] = $deliveryLine;
                } else {
                    error_log("[DIS_TEDARIK_TESLIM] POST: Skipping line with RemainingOpenQuantity=0: ItemCode=" . ($line['ItemCode'] ?? 'N/A'));
                }
                }
            }
        }
        
        if (empty($deliveryLines)) {
            $errorMsg = "Lütfen en az bir kalem için irsaliye miktarı girin! (RemainingOpenQuantity > 0 olan satırlar için)";
        } else {
            // Spec'e göre: POST /b1s/v2/PurchaseDeliveryNotes
            $payload = [
            'CardCode' => $cardCode, // PurchaseOrder.CardCode
            'U_ASB2B_NumAtCard' => $teslimatNo, // Teslimat / irsaliye belge no (ekrandaki alan)
            'Comments' => 'Dış Tedarik Teslim Alma İşlemi',
            'U_AS_OWNR' => $uAsOwnr, // Session: kitabevi
            'U_ASB2B_BRAN' => $branch, // Session: şube
                'DocumentLines' => $deliveryLines
            ];
            
            // Debug: Payload'ı logla
            $logDir = __DIR__ . '/logs';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
            file_put_contents(
                $logDir . '/pdn_payload_' . date('Ymd_His') . '.json',
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            
            $result = $sap->post("PurchaseDeliveryNotes", $payload);
            
            // Debug: Response'u logla
            file_put_contents(
                $logDir . '/pdn_response_' . date('Ymd_His') . '.json',
                json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
            
            if (($result['status'] ?? 0) == 201 || ($result['status'] ?? 0) == 200) {
                // PurchaseDeliveryNotes başarıyla oluşturuldu
                $pdnDocEntry = $result['response']['DocEntry'] ?? null;
                $pdnDocNum = $result['response']['DocNum'] ?? null;
                
                error_log("[DIS_TEDARIK_TESLIM] PurchaseDeliveryNotes oluşturuldu: DocEntry={$pdnDocEntry}, DocNum={$pdnDocNum}");
                
                // Spec'e göre: PurchaseDeliveryNotes başarıyla oluşturulduktan sonra
                // PurchaseRequest'in U_ASB2B_STATUS'unu 4 (Tamamlandı) yap
                $requestDocEntry = intval($requestNo);
                $statusUpdateSuccess = false;
                $statusUpdateError = '';
                
                // Önce requestNo'yu DocEntry olarak deneyelim
                $requestUpdatePayload = [
                    'U_ASB2B_STATUS' => '4'
                ];
                
                // DocEntry olarak PATCH dene
                $requestUpdateResult = $sap->patch("PurchaseRequests({$requestDocEntry})", $requestUpdatePayload);
                
                if (($requestUpdateResult['status'] ?? 0) == 200 || ($requestUpdateResult['status'] ?? 0) == 204) {
                    $statusUpdateSuccess = true;
                    error_log("[DIS_TEDARIK_TESLIM] PurchaseRequest status güncellendi: RequestNo={$requestNo}, Status=4");
                } else {
                    // Eğer DocEntry olarak çalışmadıysa, DocNum olarak ara
                    $prQuery = "PurchaseRequests?\$filter=DocNum eq '{$requestNo}'&\$select=DocEntry";
                    $prSearchData = $sap->get($prQuery);
                    
                    if (($prSearchData['status'] ?? 0) == 200 && isset($prSearchData['response']['value']) && !empty($prSearchData['response']['value'])) {
                        $actualDocEntry = $prSearchData['response']['value'][0]['DocEntry'] ?? null;
                        if ($actualDocEntry) {
                            $requestUpdateResult = $sap->patch("PurchaseRequests({$actualDocEntry})", $requestUpdatePayload);
                            if (($requestUpdateResult['status'] ?? 0) == 200 || ($requestUpdateResult['status'] ?? 0) == 204) {
                                $statusUpdateSuccess = true;
                                error_log("[DIS_TEDARIK_TESLIM] PurchaseRequest status güncellendi (DocNum ile bulundu): RequestNo={$requestNo}, DocEntry={$actualDocEntry}, Status=4");
                            } else {
                                $statusUpdateError = 'PATCH başarısız: HTTP ' . ($requestUpdateResult['status'] ?? 'NO STATUS');
                                if (isset($requestUpdateResult['response']['error'])) {
                                    $statusUpdateError .= ' - ' . json_encode($requestUpdateResult['response']['error']);
                                }
                                error_log("[DIS_TEDARIK_TESLIM] PurchaseRequest status güncellenemedi: " . $statusUpdateError);
                            }
                        } else {
                            $statusUpdateError = 'PurchaseRequest bulunamadı (DocNum: ' . $requestNo . ')';
                            error_log("[DIS_TEDARIK_TESLIM] PurchaseRequest bulunamadı: RequestNo={$requestNo}");
                        }
                    } else {
                        $statusUpdateError = 'PurchaseRequest bulunamadı (RequestNo: ' . $requestNo . ')';
                        error_log("[DIS_TEDARIK_TESLIM] PurchaseRequest bulunamadı: RequestNo={$requestNo}, HTTP=" . ($prSearchData['status'] ?? 'NO STATUS'));
                    }
                }
                
                // Status güncellemesi başarılı olsa da olmasa da, teslim alma işlemi başarılı olduğu için yönlendir
                // PDN bilgilerini URL parametresi olarak ekle
                $redirectParams = "msg=teslim_alindi&pdn_docentry=" . ($pdnDocEntry ?? '') . "&pdn_docnum=" . ($pdnDocNum ?? '');
                if ($statusUpdateSuccess) {
                    header("Location: DisTedarik.php?{$redirectParams}");
                } else {
                    // Status güncellenemedi ama teslim alma başarılı
                    header("Location: DisTedarik.php?{$redirectParams}&status_warning=1&error=" . urlencode($statusUpdateError));
                }
                exit;
            } else {
                $errorMsg = "Teslim alma işlemi başarısız! HTTP " . ($result['status'] ?? 'NO STATUS');
                
                // SAP'den gelen hata mesajını detaylı göster
                if (isset($result['response']['error'])) {
                    $sapError = $result['response']['error'];
                    $errorDetails = [];
                    
                    if (isset($sapError['code'])) {
                        $errorDetails[] = "Kod: " . $sapError['code'];
                    }
                    if (isset($sapError['message'])) {
                        $message = is_array($sapError['message']) ? ($sapError['message']['value'] ?? '') : $sapError['message'];
                        if (!empty($message)) {
                            $errorDetails[] = "Mesaj: " . $message;
                            
                            // Eğer "already been closed" hatası varsa, daha anlaşılır mesaj göster
                            if (stripos($message, 'already been closed') !== false || stripos($message, 'zaten kapatılmış') !== false) {
                                $errorMsg = "Bu sipariş zaten kapatılmış! Sipariş No: " . htmlspecialchars($orderNo) . "<br><br>";
                                $errorMsg .= "<strong>Hata Detayı:</strong><br>";
                                if (isset($sapError['code'])) {
                                    $errorMsg .= "Kod: " . $sapError['code'] . "<br>";
                                }
                                $errorMsg .= "Mesaj: " . $message;
                            }
                        }
                    }
                    
                    // Eğer özel mesaj oluşturulmadıysa, standart hata mesajını göster
                    if (empty($errorDetails) || stripos($errorMsg, 'zaten kapatılmış') === false) {
                        if (!empty($errorDetails)) {
                            $errorMsg .= "<br><br><strong>SAP Hata Detayı:</strong><br>" . implode("<br>", $errorDetails);
                        } else {
                            $errorMsg .= "<br><br><strong>SAP Hata Detayı:</strong><br><pre>" . htmlspecialchars(json_encode($sapError, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
                        }
                    }
                    
                    error_log("[DIS_TEDARIK_TESLIM] Error: " . json_encode($result['response']['error'], JSON_UNESCAPED_UNICODE));
                } elseif (isset($result['response']['raw'])) {
                    $errorMsg .= "<br><br><strong>Sunucu Yanıtı:</strong><br><pre>" . htmlspecialchars(substr($result['response']['raw'], 0, 500)) . "</pre>";
                }
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
    <title>Dis Tedarik Teslim Al - MINOA</title>
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
    margin: 0 32px 24px 32px;
}

.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin: 0 32px 1.5rem 32px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.alert-danger {
    background: #fee2e2;
    border: 1px solid #ef4444;
    color: #991b1b;
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
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.875rem;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.data-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.qty-input {
    width: 100px;
    padding: 0.5rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    text-align: center;
}

.qty-btn {
    padding: 0.5rem 0.75rem;
    border: 1px solid #d1d5db;
    background: white;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}

.quantity-controls {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

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
            <h2>Teslim Al - Talep No: <?= htmlspecialchars($requestNo) ?> | Sipariş No: <?= htmlspecialchars($orderNo) ?></h2>
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
        
        <?php if (!empty($debugInfo) && ($errorMsg || empty($lines))): ?>
            <div class="card" style="background: #fef3c7; border: 2px solid #f59e0b; margin-bottom: 1.5rem;">
                <h3 style="color: #92400e; margin-bottom: 1rem;">🔍 Debug Bilgileri</h3>
                <div style="font-family: monospace; font-size: 0.85rem; color: #78350f;">
                    <p><strong>Request No:</strong> <?= htmlspecialchars($requestNo) ?></p>
                    <p><strong>Order No:</strong> <?= htmlspecialchars($orderNo) ?></p>
                    <p><strong>Query:</strong> <?= htmlspecialchars($debugInfo['query'] ?? 'N/A') ?></p>
                    <p><strong>HTTP Status:</strong> <?= htmlspecialchars($debugInfo['http_status'] ?? 'N/A') ?></p>
                    <p><strong>Has Response:</strong> <?= $debugInfo['has_response'] ? 'Evet' : 'Hayır' ?></p>
                    <?php if (isset($debugInfo['lines_query'])): ?>
                        <p><strong>Lines Query:</strong> <?= htmlspecialchars($debugInfo['lines_query']) ?></p>
                        <p><strong>Lines HTTP Status:</strong> <?= htmlspecialchars($debugInfo['lines_http_status'] ?? 'N/A') ?></p>
                    <?php endif; ?>
                    <?php if ($debugInfo['error']): ?>
                        <p style="color: #dc2626;"><strong>Error:</strong> <?= htmlspecialchars(json_encode($debugInfo['error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></p>
                    <?php endif; ?>
                    <?php if ($debugInfo['response_error']): ?>
                        <p style="color: #dc2626;"><strong>Response Error:</strong> <?= htmlspecialchars(json_encode($debugInfo['response_error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></p>
                    <?php endif; ?>
                    </div>
                    </div>
        <?php endif; ?>

        <?php if (empty($lines)): ?>
            <div class="card">
                <p style="color: #ef4444; font-weight: 600; margin-bottom: 1rem;">⚠️ Satır bulunamadı veya sipariş oluşturulmamış!</p>
                <?php if (isset($debugInfo['lines_http_status']) && $debugInfo['lines_http_status'] == 200): ?>
                    <div style="background: #fef3c7; padding: 1rem; border-radius: 6px; margin-top: 1rem;">
                        <p style="color: #92400e; font-weight: 600; margin-bottom: 0.5rem;">🔍 Debug Bilgileri:</p>
                        <p style="color: #78350f; font-size: 0.875rem; margin: 0.25rem 0;">
                            <strong>Lines Query:</strong> <?= htmlspecialchars($debugInfo['lines_query'] ?? 'N/A') ?>
                        </p>
                        <p style="color: #78350f; font-size: 0.875rem; margin: 0.25rem 0;">
                            <strong>HTTP Status:</strong> <?= htmlspecialchars($debugInfo['lines_http_status'] ?? 'N/A') ?>
                        </p>
                        <p style="color: #78350f; font-size: 0.875rem; margin: 0.25rem 0;">
                            <strong>Response Keys:</strong> <?= isset($linesData['response']) ? htmlspecialchars(implode(', ', array_keys($linesData['response']))) : 'N/A' ?>
                        </p>
                        <p style="color: #78350f; font-size: 0.875rem; margin: 0.25rem 0;">
                            <strong>Has 'value' key:</strong> <?= isset($linesData['response']['value']) ? 'Evet' : 'Hayır' ?>
                        </p>
                        <p style="color: #78350f; font-size: 0.875rem; margin-top: 0.5rem;">
                            <strong>Not:</strong> Response log dosyasına kaydedildi: <code>logs/lines_response_*.json</code>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Spec'e göre: Üstte sipariş bilgisi -->
            <div class="card">
                <h3 style="margin-bottom: 1rem; color: #1e40af;">Sipariş Bilgileri</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Talep No</div>
                        <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($requestNo) ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Sipariş No</div>
                        <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($orderDocEntry ?? $orderDocNum ?? $orderNo) ?></div>
                    </div>
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; margin-bottom: 0.25rem;">Tedarikçi</div>
                        <div style="font-size: 1rem; color: #1f2937; font-weight: 500;"><?= htmlspecialchars($cardName ?: '-') ?></div>
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
                                <th>Kalem Kodu</th>
                                <th>Kalem Tanımı</th>
                                <th>Sipariş Miktarı</th>
                                <th>İrsaliye Miktarı</th>
                                <th>Eksik/Fazla Miktar</th>
                                <th>Kusurlu Miktar</th>
                                <th>Not</th>
                                <th>Görsel</th>
                        </tr>
                    </thead>
                    <tbody>
                            <?php foreach ($lines as $index => $line): 
                                $orderQuantity = floatval($line['Quantity'] ?? 0); // PurchaseOrder'dan gelen sipariş miktarı
                                $requestedQuantity = floatval($line['RequestedQuantity'] ?? $orderQuantity); // PurchaseRequest'ten gelen talep miktarı
                                $remainingQty = floatval($line['RemainingOpenQuantity'] ?? $line['OpenQuantity'] ?? 0);
                                $isLineDisabled = $line['IsDisabled'] ?? ($remainingQty <= 0 || $isClosed);
                                $disabledAttr = $isLineDisabled ? 'disabled' : '';
                                $disabledStyle = $isLineDisabled ? 'background: #f3f4f6; color: #9ca3af; cursor: not-allowed;' : '';
                                $rowStyle = $isLineDisabled ? 'background: #f9fafb; opacity: 0.7;' : '';
                                
                                // ✅ Uyarı: Eğer girilen miktar kalan miktarı karşılıyorsa veya aşıyorsa uyarı göster
                                $irsaliyeQtyInputAttr = '';
                                if (!$isLineDisabled && $remainingQty > 0) {
                                    // JavaScript ile gerçek zamanlı kontrol için data attribute ve oninput ekle
                                    $irsaliyeQtyInputAttr = "data-remaining-qty='{$remainingQty}' oninput='checkRemainingQty({$index}, {$remainingQty})'";
                                }
                                
                                // ✅ Talep miktarı ile sipariş miktarı farklıysa göster
                                $quantityDisplay = $requestedQuantity; // Varsayılan: Talep miktarını göster
                                $quantityTooltip = '';
                                if (abs($requestedQuantity - $orderQuantity) > 0.01) {
                                    $quantityTooltip = "Talep: {$requestedQuantity} | Sipariş: {$orderQuantity}";
                                }
                            ?>
                                <tr style="<?= $rowStyle ?>">
                                    <td><?= htmlspecialchars($line['ItemCode'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($line['ItemDescription'] ?? '-') ?></td>
                                    <td>
                                        <input type="number" 
                                               value="<?= htmlspecialchars($quantityDisplay) ?>" 
                                               readonly 
                                               step="0.01"
                                               class="qty-input"
                                               style="<?= $disabledStyle ?>"
                                               title="<?= htmlspecialchars($quantityTooltip) ?>">
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
                                                <?= $isClosed ? 'Sipariş kapalı' : 'RemainingQty: ' . $remainingQty ?>
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
                                            <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'eksik', -1)" <?= $disabledAttr ?> style="<?= $disabledStyle ?>">-</button>
                                            <input type="number" 
                                                   name="eksik_fazla[<?= $index ?>]"
                                                   id="eksik_<?= $index ?>"
                                                   value="0" 
                                                   step="0.01"
                                                   class="qty-input"
                                                   <?= $disabledAttr ?>
                                                   style="<?= $disabledStyle ?>">
                                            <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'eksik', 1)" <?= $disabledAttr ?> style="<?= $disabledStyle ?>">+</button>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="quantity-controls">
                                            <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'kusurlu', -1)" <?= $disabledAttr ?> style="<?= $disabledStyle ?>">-</button>
                                            <input type="number" 
                                                   name="kusurlu[<?= $index ?>]"
                                                   id="kusurlu_<?= $index ?>"
                                                   value="0" 
                                                   min="0"
                                                   step="0.01"
                                                   class="qty-input"
                                                   <?= $disabledAttr ?>
                                                   style="<?= $disabledStyle ?>">
                                            <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'kusurlu', 1)" <?= $disabledAttr ?> style="<?= $disabledStyle ?>">+</button>
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
function changeQuantity(index, type, delta) {
    const input = document.getElementById(type + '_' + index);
    if (!input) return;
    
    let value = parseFloat(input.value) || 0;
    value += delta;
    if (value < 0) value = 0;
    input.value = value;
}

// ✅ Gerçek zamanlı kontrol: Girilen miktar kalan miktarı karşılıyor mu?
function checkRemainingQty(index, remainingQty) {
    const input = document.getElementById('irsaliye_' + index);
    const warning = document.getElementById('warning_' + index);
    const info = document.getElementById('info_' + index);
    
    if (!input || !warning || !info) return;
    
    const enteredQty = parseFloat(input.value) || 0;
    
    if (enteredQty > 0 && enteredQty >= remainingQty) {
        // Uyarı göster
        warning.style.display = 'block';
        info.style.display = 'none';
        input.style.borderColor = '#dc2626';
        input.style.borderWidth = '2px';
    } else {
        // Uyarıyı gizle
        warning.style.display = 'none';
        info.style.display = 'block';
        input.style.borderColor = '';
        input.style.borderWidth = '';
    }
}

function validateForm() {
    // ✅ İrsaliye numarası zorunlu kontrolü
    const teslimatNoInput = document.querySelector('input[name="teslimat_no"]');
    const teslimatNo = teslimatNoInput ? teslimatNoInput.value.trim() : '';
    
    if (!teslimatNo || teslimatNo === '') {
        alert('⚠️ Lütfen İrsaliye/Teslimat numarası girin!');
        teslimatNoInput?.focus();
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
            
            // RemainingQty kontrolü
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
    
    // Eğer bir satır kapanacaksa onay iste
    if (willCloseAnyLine) {
        const message = '⚠️ UYARI:\n\n' + warnings.join('\n') + '\n\nBu işlem sonrasında bazı satırlar kapanacak. Devam etmek istiyor musunuz?';
        if (!confirm(message)) {
            return false;
        }
    }
    
    return true;
}
    </script>
</body>
</html>