<?php
class SAPConnect {
    private $baseUrl = "https://192.168.54.185:50000/b1s/v2/";
    private $sessionId;

    public function __construct() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['sapSession'])) {
        $this->sessionId = $_SESSION['sapSession'];
    }
}

    public function login($username, $password, $companyDB) {
        $url = $this->baseUrl . "Login";
        $data = [
            "CompanyDB" => $companyDB,
            "UserName" => $username,
            "Password" => $password 
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if (!empty($curlError)) {
            error_log("[SAP LOGIN] ERR-001: cURL hatası - " . $curlError);
            return ['success' => false, 'error_code' => 'ERR-001', 'error' => 'Bağlantı hatası: ' . $curlError];
        }

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result["SessionId"])) {
                $this->sessionId = $result["SessionId"];
                $_SESSION["sapSession"] = $this->sessionId;
                return true;
            } else {
                error_log("[SAP LOGIN] ERR-002: SessionId bulunamadı - " . substr($response, 0, 200));
                return ['success' => false, 'error_code' => 'ERR-002', 'error' => 'SessionId alınamadı', 'response' => $response];
            }
        } else {
            error_log("[SAP LOGIN] ERR-003: HTTP " . $httpCode . " - " . substr($response, 0, 200));
            return ['success' => false, 'error_code' => 'ERR-003', 'error' => 'HTTP ' . $httpCode, 'response' => $response];
        }
    } 


    private function sendRequest($method, $endpoint, $payload = null) { //GET, POST, PATCH gibi HTTP metodu
        // Endpoint'i baseUrl'e ekle
        $url = $this->baseUrl . $endpoint;
        
        // Debug: URL'i logla
        error_log("[SAP REQUEST] Full URL: " . substr($url, 0, 200));
        
        $ch = curl_init($url);

        $headers = ["Content-Type: application/json"];
        if ($this->sessionId) {
            $headers[] = "Cookie: B1SESSION=" . $this->sessionId; //session token (SessionId) 
                                                                  //Bu cookie olmadan SAP "Invalid session" hatası döner.
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers, 
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        if ($payload) {  //gönderilecek veriyi JSON'a çevirip isteğin gövdesine koyar.
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);  //SAP'ye isteği yollar, cevabı JSON olarak alır.
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); //SAP'nin HTTP yanıt kodunu alır. //(örnek: 200 = başarılı, 400 = hatalı istek, 301 = session timeout)
        $curlError = curl_error($ch);
        curl_close($ch); 

        // cURL hatası varsa
        if (!empty($curlError)) {
            error_log("[SAP REQUEST] cURL Error: " . $curlError . " | URL: " . $url);
            return ["status" => 0, "response" => ["raw" => $curlError, "error" => $curlError]];
        }

        // HTTP Code 0 ise (bağlantı hatası)
        if ($httpCode == 0) {
            error_log("[SAP REQUEST] HTTP Code 0 - Connection failed | URL: " . $url);
            return ["status" => 0, "response" => ["raw" => $response ?? '', "error" => "Connection failed"]];
        }

        $json = json_decode($response, true); //SAP'den gelen cevap JSON'sa → array'e çevir.
        return is_array($json)
    ? ["status" => $httpCode, "response" => $json]
    : ["status" => $httpCode, "response" => ["raw" => $response]];  //Eğer değilse (örneğin HTML hata mesajı döndüyse) → ham cevabı "raw" olarak sakla.

    } 


     /**
     * Minoa kullanıcı login metodu
     * Önce SAP Service Layer login yaparak SessionId alır,
     * sonra EmployeesInfo ile kullanıcı bilgilerini doğrular
     * 
     * @param string $username SAP B1 default kullanıcısı (örn: manager)
     * @param string $password SAP B1 şifresi
     * @param string $companyDB Şirket veritabanı
     * @param string $userB2B B2B kullanıcı adı (U_ASB2B_USER)
     * @param string $passB2B B2B şifresi (U_ASB2B_PASS)
     * @return array|false Başarılı ise kullanıcı bilgileri, değilse false
     */
    public function minoaUserLogin($username, $password, $companyDB, $userB2B, $passB2B) {
        // 1. Önce SAP Service Layer login yaparak SessionId al
        $loginResult = $this->login($username, $password, $companyDB);
        
        if ($loginResult !== true) {
            return [
                'success' => false,
                'error_code' => $loginResult['error_code'] ?? 'ERR-LOGIN-UNKNOWN',
                'error' => $loginResult['error'] ?? 'Service Layer login başarısız',
                'stage' => 'service_layer_login',
                'details' => $loginResult
            ];
        }

        // 2. EmployeesInfo API ile kullanıcı bilgilerini çek
        // Kullanıcı girdilerindeki özel karakterleri escape et
        $userB2BEscaped = str_replace("'", "''", $userB2B);
        $passB2BEscaped = str_replace("'", "''", $passB2B);
        
        // OData filtre sözdizimi
        $selectValue = "EmployeeID,FirstName,LastName,U_AS_OWNR";
        $filterValue = "U_ASB2B_USER eq '{$userB2BEscaped}' and U_ASB2B_PASS eq '{$passB2BEscaped}'"; 
        $expandValue = "Branch(\$select=Name,Description)";
        
        $endpoint = "EmployeesInfo?\$select=" . urlencode($selectValue) . "&\$filter=" . urlencode($filterValue) . "&\$expand=" . $expandValue;
        
        $result = $this->get($endpoint);
        $httpStatus = $result['status'] ?? 'NO STATUS';
        
        // Response kontrolü
        if (!isset($result['status'])) {
            error_log("[MINOA LOGIN] ERR-101: Response geçersiz");
            return [
                'success' => false,
                'error_code' => 'ERR-101',
                'error' => 'API response geçersiz',
                'stage' => 'api_call',
                'response' => $result
            ];
        }
        
        if ($httpStatus !== 200) {
            error_log("[MINOA LOGIN] ERR-102: HTTP " . $httpStatus);
            return [
                'success' => false,
                'error_code' => 'ERR-102',
                'error' => 'HTTP ' . $httpStatus,
                'stage' => 'api_call',
                'http_status' => $httpStatus,
                'response' => $result['response'] ?? null
            ];
        }
        
        if (!isset($result['response']['value'])) {
            error_log("[MINOA LOGIN] ERR-103: Value array bulunamadı");
            return [
                'success' => false,
                'error_code' => 'ERR-103',
                'error' => 'Response\'da value array bulunamadı',
                'stage' => 'data_processing',
                'response' => $result['response'] ?? null
            ];
        }
        
        if (empty($result['response']['value'])) {
            return [
                'success' => false,
                'error_code' => 'ERR-104',
                'error' => 'Kullanıcı bulunamadı (kullanıcı adı/şifre hatalı olabilir)',
                'stage' => 'user_not_found',
                'filter_used' => $filterValue
            ];
        }
        
        
        $users = $result['response']['value'];
        if (is_array($users) && count($users) > 1) {
            error_log("[MINOA LOGIN] ERR-105: Birden fazla kullanıcı bulundu - Aynı kullanıcı adı/şifre kombinasyonu mevcut!");
            
            error_log("[MINOA LOGIN] Bulunan kullanıcı sayısı: " . count($users));
            foreach ($users as $idx => $usr) {
                if (is_array($usr)) {
                    $branchName = isset($usr['Branch2']['Name']) ? $usr['Branch2']['Name'] : (isset($usr['Branch']['Name']) ? $usr['Branch']['Name'] : 'N/A');
                    $employeeId = isset($usr['EmployeeID']) ? $usr['EmployeeID'] : 'N/A';
                    $uAsOwnr = isset($usr['U_AS_OWNR']) ? $usr['U_AS_OWNR'] : 'N/A';
                    error_log("[MINOA LOGIN] Kullanıcı #{$idx}: EmployeeID={$employeeId}, U_AS_OWNR={$uAsOwnr}, Branch={$branchName}");
                }
            }
        }
        
        // 3. Başarılı - Kullanıcı bilgilerini işle (ilk kaydı al)
        $userInfo = $result['response']['value'][0];
        
        
        $branchData = $userInfo['Branch2'] ?? $userInfo['Branch'] ?? null;
        
        // BranchCode: Önce Code varsa onu kullan, yoksa Name'i kullan
        $branchCode = $branchData['Code'] ?? ($branchData['Name'] ?? null);
        
        return [
            'success' => true,
            'userInfo' => $userInfo,
            'U_AS_OWNR' => $userInfo['U_AS_OWNR'] ?? null,
            'BranchCode' => $branchCode,
            'Branch2' => $branchData
        ];
    }




    public function get($endpoint) { return $this->sendRequest("GET", $endpoint); }
    public function post($endpoint, $payload) { return $this->sendRequest("POST", $endpoint, $payload); }
    public function patch($endpoint, $payload) { return $this->sendRequest("PATCH", $endpoint, $payload); }

   
}
?>
