<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece MS (Muse) kullanıcıları giriş yapabilir
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'MS') {
    header("Location: index.php");
    exit;
}

$subeler = ['Taksim Şube', 'Kadıköy Şube', 'Beşiktaş Şube', 'Ümraniye Şube'];
$sorumlular = ['Ahmet Yılmaz', 'Ayşe Demir', 'Mehmet Kaya', 'Zeynep Özkan'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Etkinlik Oluştur - MINOA</title>
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
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1000px;
    margin: 0 auto;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible;
}

.form-section {
    padding: 24px;
}

.section-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e40af;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e5e7eb;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    margin-bottom: 8px;
}

.form-group label .required {
    color: #ef4444;
    margin-left: 4px;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    transition: border-color 0.2s;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.datetime-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
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

.btn-back {
    background: #6b7280;
    color: white;
}

.btn-back:hover {
    background: #4b5563;
}

.form-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px;
    border-top: 1px solid #e5e7eb;
    margin-top: 24px;
}

.hidden {
    display: none;
}

/* Responsive */
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

    .form-row,
    .datetime-row {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
        gap: 12px;
    }

    .form-actions button {
        width: 100%;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 16px;">
                <button class="btn btn-back" onclick="window.location.href='Muse.php'">← Geri</button>
                <h2>Yeni Etkinlik Oluştur</h2>
            </div>
            <button class="btn btn-primary" onclick="continueToStep2()">Devam</button>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <form id="etkinlikForm">
                    <!-- 1. Etkinlik Bilgileri -->
                    <div class="form-section">
                        <h3 class="section-title">1. Etkinlik Bilgileri</h3>
                        
                        <div class="form-group">
                            <label>Etkinlik Adı <span class="required">*</span></label>
                            <input type="text" id="etkinlikAdi" name="etkinlikAdi" required>
                        </div>

                        <div class="form-group">
                            <label>Etkinlik Açıklaması</label>
                            <textarea id="etkinlikAciklama" name="etkinlikAciklama" placeholder="Etkinlik hakkında detaylı bilgi..."></textarea>
                        </div>

                        <div class="form-group">
                            <label>Başlangıç Tarihi / Saati <span class="required">*</span></label>
                            <div class="datetime-row">
                                <input type="date" id="baslangicTarihi" name="baslangicTarihi" required>
                                <input type="time" id="baslangicSaati" name="baslangicSaati" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Bitiş Tarihi / Saati <span class="required">*</span></label>
                            <div class="datetime-row">
                                <input type="date" id="bitisTarihi" name="bitisTarihi" required>
                                <input type="time" id="bitisSaati" name="bitisSaati" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Kapasite <span class="required">*</span></label>
                            <input type="number" id="kapasite" name="kapasite" min="1" required>
                        </div>
                    </div>

                    <!-- 2. Lokasyon / Şube Bilgileri -->
                    <div class="form-section">
                        <h3 class="section-title">2. Lokasyon / Şube Bilgileri</h3>
                        
                        <div class="form-group">
                            <label>Etkinlik Yeri Tipi <span class="required">*</span></label>
                            <select id="yerTipi" name="yerTipi" required onchange="toggleHariciLokasyon()">
                                <option value="">Seçiniz...</option>
                                <option value="sube">Şube İçinde</option>
                                <option value="harici">Harici Lokasyon</option>
                            </select>
                        </div>

                        <div class="form-group" id="subeGroup">
                            <label>Şube Seçimi <span class="required">*</span></label>
                            <select id="sube" name="sube">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($subeler as $sube): ?>
                                <option value="<?= htmlspecialchars($sube) ?>"><?= htmlspecialchars($sube) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="hariciLokasyonGroup" class="hidden">
                            <div class="form-group">
                                <label>Lokasyon Adı <span class="required">*</span></label>
                                <input type="text" id="lokasyonAdi" name="lokasyonAdi">
                            </div>

                            <div class="form-group">
                                <label>Adres <span class="required">*</span></label>
                                <textarea id="adres" name="adres" placeholder="Detaylı adres bilgisi..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Sorumlu Kişi -->
                    <div class="form-section">
                        <h3 class="section-title">3. Sorumlu Kişi</h3>
                        
                        <div class="form-group">
                            <label>Etkinlik Sorumlusu</label>
                            <select id="sorumlu" name="sorumlu">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($sorumlular as $sorumlu): ?>
                                <option value="<?= htmlspecialchars($sorumlu) ?>"><?= htmlspecialchars($sorumlu) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>İletişim Notu</label>
                            <textarea id="iletisimNotu" name="iletisimNotu" placeholder="Sorumlu kişi ile ilgili notlar..."></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='Muse.php'">İptal</button>
                        <button type="button" class="btn btn-primary" onclick="continueToStep2()">Devam → Etkinlik İçeriğini Oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleHariciLokasyon() {
            const yerTipi = document.getElementById('yerTipi').value;
            const subeGroup = document.getElementById('subeGroup');
            const hariciLokasyonGroup = document.getElementById('hariciLokasyonGroup');
            const subeSelect = document.getElementById('sube');
            const lokasyonAdiInput = document.getElementById('lokasyonAdi');
            const adresTextarea = document.getElementById('adres');

            if (yerTipi === 'harici') {
                subeGroup.classList.add('hidden');
                hariciLokasyonGroup.classList.remove('hidden');
                subeSelect.removeAttribute('required');
                lokasyonAdiInput.setAttribute('required', 'required');
                adresTextarea.setAttribute('required', 'required');
            } else {
                subeGroup.classList.remove('hidden');
                hariciLokasyonGroup.classList.add('hidden');
                subeSelect.setAttribute('required', 'required');
                lokasyonAdiInput.removeAttribute('required');
                adresTextarea.removeAttribute('required');
            }
        }

        function continueToStep2() {
            const form = document.getElementById('etkinlikForm');
            
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            // Form verilerini topla
            const formData = {
                etkinlikAdi: document.getElementById('etkinlikAdi').value,
                etkinlikAciklama: document.getElementById('etkinlikAciklama').value,
                baslangicTarihi: document.getElementById('baslangicTarihi').value,
                baslangicSaati: document.getElementById('baslangicSaati').value,
                bitisTarihi: document.getElementById('bitisTarihi').value,
                bitisSaati: document.getElementById('bitisSaati').value,
                kapasite: document.getElementById('kapasite').value,
                yerTipi: document.getElementById('yerTipi').value,
                sube: document.getElementById('sube').value || document.getElementById('lokasyonAdi').value,
                lokasyonAdi: document.getElementById('lokasyonAdi').value,
                adres: document.getElementById('adres').value,
                sorumlu: document.getElementById('sorumlu').value,
                iletisimNotu: document.getElementById('iletisimNotu').value
            };

            // Adım 2 sayfasına yönlendir
            window.location.href = 'MuseSO2.php?' + new URLSearchParams(formData).toString();
        }

        // Tarih validasyonu: Bitiş tarihi başlangıç tarihinden önce olamaz
        document.getElementById('baslangicTarihi').addEventListener('change', function() {
            const bitisTarihi = document.getElementById('bitisTarihi');
            if (this.value && bitisTarihi.value && this.value > bitisTarihi.value) {
                bitisTarihi.setCustomValidity('Bitiş tarihi başlangıç tarihinden önce olamaz.');
            } else {
                bitisTarihi.setCustomValidity('');
            }
        });

        document.getElementById('bitisTarihi').addEventListener('change', function() {
            const baslangicTarihi = document.getElementById('baslangicTarihi');
            if (this.value && baslangicTarihi.value && this.value < baslangicTarihi.value) {
                this.setCustomValidity('Bitiş tarihi başlangıç tarihinden önce olamaz.');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>


if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece MS (Muse) kullanıcıları giriş yapabilir
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'MS') {
    header("Location: index.php");
    exit;
}

$subeler = ['Taksim Şube', 'Kadıköy Şube', 'Beşiktaş Şube', 'Ümraniye Şube'];
$sorumlular = ['Ahmet Yılmaz', 'Ayşe Demir', 'Mehmet Kaya', 'Zeynep Özkan'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Etkinlik Oluştur - MINOA</title>
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
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1000px;
    margin: 0 auto;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible;
}

.form-section {
    padding: 24px;
}

.section-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e40af;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e5e7eb;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    margin-bottom: 8px;
}

.form-group label .required {
    color: #ef4444;
    margin-left: 4px;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    transition: border-color 0.2s;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.datetime-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
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

.btn-back {
    background: #6b7280;
    color: white;
}

.btn-back:hover {
    background: #4b5563;
}

.form-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px;
    border-top: 1px solid #e5e7eb;
    margin-top: 24px;
}

.hidden {
    display: none;
}

/* Responsive */
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

    .form-row,
    .datetime-row {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
        gap: 12px;
    }

    .form-actions button {
        width: 100%;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 16px;">
                <button class="btn btn-back" onclick="window.location.href='Muse.php'">← Geri</button>
                <h2>Yeni Etkinlik Oluştur</h2>
            </div>
            <button class="btn btn-primary" onclick="continueToStep2()">Devam</button>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <form id="etkinlikForm">
                    <!-- 1. Etkinlik Bilgileri -->
                    <div class="form-section">
                        <h3 class="section-title">1. Etkinlik Bilgileri</h3>
                        
                        <div class="form-group">
                            <label>Etkinlik Adı <span class="required">*</span></label>
                            <input type="text" id="etkinlikAdi" name="etkinlikAdi" required>
                        </div>

                        <div class="form-group">
                            <label>Etkinlik Açıklaması</label>
                            <textarea id="etkinlikAciklama" name="etkinlikAciklama" placeholder="Etkinlik hakkında detaylı bilgi..."></textarea>
                        </div>

                        <div class="form-group">
                            <label>Başlangıç Tarihi / Saati <span class="required">*</span></label>
                            <div class="datetime-row">
                                <input type="date" id="baslangicTarihi" name="baslangicTarihi" required>
                                <input type="time" id="baslangicSaati" name="baslangicSaati" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Bitiş Tarihi / Saati <span class="required">*</span></label>
                            <div class="datetime-row">
                                <input type="date" id="bitisTarihi" name="bitisTarihi" required>
                                <input type="time" id="bitisSaati" name="bitisSaati" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Kapasite <span class="required">*</span></label>
                            <input type="number" id="kapasite" name="kapasite" min="1" required>
                        </div>
                    </div>

                    <!-- 2. Lokasyon / Şube Bilgileri -->
                    <div class="form-section">
                        <h3 class="section-title">2. Lokasyon / Şube Bilgileri</h3>
                        
                        <div class="form-group">
                            <label>Etkinlik Yeri Tipi <span class="required">*</span></label>
                            <select id="yerTipi" name="yerTipi" required onchange="toggleHariciLokasyon()">
                                <option value="">Seçiniz...</option>
                                <option value="sube">Şube İçinde</option>
                                <option value="harici">Harici Lokasyon</option>
                            </select>
                        </div>

                        <div class="form-group" id="subeGroup">
                            <label>Şube Seçimi <span class="required">*</span></label>
                            <select id="sube" name="sube">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($subeler as $sube): ?>
                                <option value="<?= htmlspecialchars($sube) ?>"><?= htmlspecialchars($sube) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="hariciLokasyonGroup" class="hidden">
                            <div class="form-group">
                                <label>Lokasyon Adı <span class="required">*</span></label>
                                <input type="text" id="lokasyonAdi" name="lokasyonAdi">
                            </div>

                            <div class="form-group">
                                <label>Adres <span class="required">*</span></label>
                                <textarea id="adres" name="adres" placeholder="Detaylı adres bilgisi..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Sorumlu Kişi -->
                    <div class="form-section">
                        <h3 class="section-title">3. Sorumlu Kişi</h3>
                        
                        <div class="form-group">
                            <label>Etkinlik Sorumlusu</label>
                            <select id="sorumlu" name="sorumlu">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($sorumlular as $sorumlu): ?>
                                <option value="<?= htmlspecialchars($sorumlu) ?>"><?= htmlspecialchars($sorumlu) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>İletişim Notu</label>
                            <textarea id="iletisimNotu" name="iletisimNotu" placeholder="Sorumlu kişi ile ilgili notlar..."></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='Muse.php'">İptal</button>
                        <button type="button" class="btn btn-primary" onclick="continueToStep2()">Devam → Etkinlik İçeriğini Oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleHariciLokasyon() {
            const yerTipi = document.getElementById('yerTipi').value;
            const subeGroup = document.getElementById('subeGroup');
            const hariciLokasyonGroup = document.getElementById('hariciLokasyonGroup');
            const subeSelect = document.getElementById('sube');
            const lokasyonAdiInput = document.getElementById('lokasyonAdi');
            const adresTextarea = document.getElementById('adres');

            if (yerTipi === 'harici') {
                subeGroup.classList.add('hidden');
                hariciLokasyonGroup.classList.remove('hidden');
                subeSelect.removeAttribute('required');
                lokasyonAdiInput.setAttribute('required', 'required');
                adresTextarea.setAttribute('required', 'required');
            } else {
                subeGroup.classList.remove('hidden');
                hariciLokasyonGroup.classList.add('hidden');
                subeSelect.setAttribute('required', 'required');
                lokasyonAdiInput.removeAttribute('required');
                adresTextarea.removeAttribute('required');
            }
        }

        function continueToStep2() {
            const form = document.getElementById('etkinlikForm');
            
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            // Form verilerini topla
            const formData = {
                etkinlikAdi: document.getElementById('etkinlikAdi').value,
                etkinlikAciklama: document.getElementById('etkinlikAciklama').value,
                baslangicTarihi: document.getElementById('baslangicTarihi').value,
                baslangicSaati: document.getElementById('baslangicSaati').value,
                bitisTarihi: document.getElementById('bitisTarihi').value,
                bitisSaati: document.getElementById('bitisSaati').value,
                kapasite: document.getElementById('kapasite').value,
                yerTipi: document.getElementById('yerTipi').value,
                sube: document.getElementById('sube').value || document.getElementById('lokasyonAdi').value,
                lokasyonAdi: document.getElementById('lokasyonAdi').value,
                adres: document.getElementById('adres').value,
                sorumlu: document.getElementById('sorumlu').value,
                iletisimNotu: document.getElementById('iletisimNotu').value
            };

            // Adım 2 sayfasına yönlendir
            window.location.href = 'MuseSO2.php?' + new URLSearchParams(formData).toString();
        }

        // Tarih validasyonu: Bitiş tarihi başlangıç tarihinden önce olamaz
        document.getElementById('baslangicTarihi').addEventListener('change', function() {
            const bitisTarihi = document.getElementById('bitisTarihi');
            if (this.value && bitisTarihi.value && this.value > bitisTarihi.value) {
                bitisTarihi.setCustomValidity('Bitiş tarihi başlangıç tarihinden önce olamaz.');
            } else {
                bitisTarihi.setCustomValidity('');
            }
        });

        document.getElementById('bitisTarihi').addEventListener('change', function() {
            const baslangicTarihi = document.getElementById('baslangicTarihi');
            if (this.value && baslangicTarihi.value && this.value < baslangicTarihi.value) {
                this.setCustomValidity('Bitiş tarihi başlangıç tarihinden önce olamaz.');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>


if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece MS (Muse) kullanıcıları giriş yapabilir
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'MS') {
    header("Location: index.php");
    exit;
}

$subeler = ['Taksim Şube', 'Kadıköy Şube', 'Beşiktaş Şube', 'Ümraniye Şube'];
$sorumlular = ['Ahmet Yılmaz', 'Ayşe Demir', 'Mehmet Kaya', 'Zeynep Özkan'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Etkinlik Oluştur - MINOA</title>
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
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1000px;
    margin: 0 auto;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible;
}

.form-section {
    padding: 24px;
}

.section-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e40af;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e5e7eb;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: #374151;
    margin-bottom: 8px;
}

.form-group label .required {
    color: #ef4444;
    margin-left: 4px;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    transition: border-color 0.2s;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.datetime-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
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

.btn-back {
    background: #6b7280;
    color: white;
}

.btn-back:hover {
    background: #4b5563;
}

.form-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px;
    border-top: 1px solid #e5e7eb;
    margin-top: 24px;
}

.hidden {
    display: none;
}

/* Responsive */
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

    .form-row,
    .datetime-row {
        grid-template-columns: 1fr;
    }

    .form-actions {
        flex-direction: column;
        gap: 12px;
    }

    .form-actions button {
        width: 100%;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 16px;">
                <button class="btn btn-back" onclick="window.location.href='Muse.php'">← Geri</button>
                <h2>Yeni Etkinlik Oluştur</h2>
            </div>
            <button class="btn btn-primary" onclick="continueToStep2()">Devam</button>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <form id="etkinlikForm">
                    <!-- 1. Etkinlik Bilgileri -->
                    <div class="form-section">
                        <h3 class="section-title">1. Etkinlik Bilgileri</h3>
                        
                        <div class="form-group">
                            <label>Etkinlik Adı <span class="required">*</span></label>
                            <input type="text" id="etkinlikAdi" name="etkinlikAdi" required>
                        </div>

                        <div class="form-group">
                            <label>Etkinlik Açıklaması</label>
                            <textarea id="etkinlikAciklama" name="etkinlikAciklama" placeholder="Etkinlik hakkında detaylı bilgi..."></textarea>
                        </div>

                        <div class="form-group">
                            <label>Başlangıç Tarihi / Saati <span class="required">*</span></label>
                            <div class="datetime-row">
                                <input type="date" id="baslangicTarihi" name="baslangicTarihi" required>
                                <input type="time" id="baslangicSaati" name="baslangicSaati" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Bitiş Tarihi / Saati <span class="required">*</span></label>
                            <div class="datetime-row">
                                <input type="date" id="bitisTarihi" name="bitisTarihi" required>
                                <input type="time" id="bitisSaati" name="bitisSaati" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Kapasite <span class="required">*</span></label>
                            <input type="number" id="kapasite" name="kapasite" min="1" required>
                        </div>
                    </div>

                    <!-- 2. Lokasyon / Şube Bilgileri -->
                    <div class="form-section">
                        <h3 class="section-title">2. Lokasyon / Şube Bilgileri</h3>
                        
                        <div class="form-group">
                            <label>Etkinlik Yeri Tipi <span class="required">*</span></label>
                            <select id="yerTipi" name="yerTipi" required onchange="toggleHariciLokasyon()">
                                <option value="">Seçiniz...</option>
                                <option value="sube">Şube İçinde</option>
                                <option value="harici">Harici Lokasyon</option>
                            </select>
                        </div>

                        <div class="form-group" id="subeGroup">
                            <label>Şube Seçimi <span class="required">*</span></label>
                            <select id="sube" name="sube">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($subeler as $sube): ?>
                                <option value="<?= htmlspecialchars($sube) ?>"><?= htmlspecialchars($sube) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="hariciLokasyonGroup" class="hidden">
                            <div class="form-group">
                                <label>Lokasyon Adı <span class="required">*</span></label>
                                <input type="text" id="lokasyonAdi" name="lokasyonAdi">
                            </div>

                            <div class="form-group">
                                <label>Adres <span class="required">*</span></label>
                                <textarea id="adres" name="adres" placeholder="Detaylı adres bilgisi..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- 3. Sorumlu Kişi -->
                    <div class="form-section">
                        <h3 class="section-title">3. Sorumlu Kişi</h3>
                        
                        <div class="form-group">
                            <label>Etkinlik Sorumlusu</label>
                            <select id="sorumlu" name="sorumlu">
                                <option value="">Seçiniz...</option>
                                <?php foreach ($sorumlular as $sorumlu): ?>
                                <option value="<?= htmlspecialchars($sorumlu) ?>"><?= htmlspecialchars($sorumlu) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>İletişim Notu</label>
                            <textarea id="iletisimNotu" name="iletisimNotu" placeholder="Sorumlu kişi ile ilgili notlar..."></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='Muse.php'">İptal</button>
                        <button type="button" class="btn btn-primary" onclick="continueToStep2()">Devam → Etkinlik İçeriğini Oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleHariciLokasyon() {
            const yerTipi = document.getElementById('yerTipi').value;
            const subeGroup = document.getElementById('subeGroup');
            const hariciLokasyonGroup = document.getElementById('hariciLokasyonGroup');
            const subeSelect = document.getElementById('sube');
            const lokasyonAdiInput = document.getElementById('lokasyonAdi');
            const adresTextarea = document.getElementById('adres');

            if (yerTipi === 'harici') {
                subeGroup.classList.add('hidden');
                hariciLokasyonGroup.classList.remove('hidden');
                subeSelect.removeAttribute('required');
                lokasyonAdiInput.setAttribute('required', 'required');
                adresTextarea.setAttribute('required', 'required');
            } else {
                subeGroup.classList.remove('hidden');
                hariciLokasyonGroup.classList.add('hidden');
                subeSelect.setAttribute('required', 'required');
                lokasyonAdiInput.removeAttribute('required');
                adresTextarea.removeAttribute('required');
            }
        }

        function continueToStep2() {
            const form = document.getElementById('etkinlikForm');
            
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            // Form verilerini topla
            const formData = {
                etkinlikAdi: document.getElementById('etkinlikAdi').value,
                etkinlikAciklama: document.getElementById('etkinlikAciklama').value,
                baslangicTarihi: document.getElementById('baslangicTarihi').value,
                baslangicSaati: document.getElementById('baslangicSaati').value,
                bitisTarihi: document.getElementById('bitisTarihi').value,
                bitisSaati: document.getElementById('bitisSaati').value,
                kapasite: document.getElementById('kapasite').value,
                yerTipi: document.getElementById('yerTipi').value,
                sube: document.getElementById('sube').value || document.getElementById('lokasyonAdi').value,
                lokasyonAdi: document.getElementById('lokasyonAdi').value,
                adres: document.getElementById('adres').value,
                sorumlu: document.getElementById('sorumlu').value,
                iletisimNotu: document.getElementById('iletisimNotu').value
            };

            // Adım 2 sayfasına yönlendir
            window.location.href = 'MuseSO2.php?' + new URLSearchParams(formData).toString();
        }

        // Tarih validasyonu: Bitiş tarihi başlangıç tarihinden önce olamaz
        document.getElementById('baslangicTarihi').addEventListener('change', function() {
            const bitisTarihi = document.getElementById('bitisTarihi');
            if (this.value && bitisTarihi.value && this.value > bitisTarihi.value) {
                bitisTarihi.setCustomValidity('Bitiş tarihi başlangıç tarihinden önce olamaz.');
            } else {
                bitisTarihi.setCustomValidity('');
            }
        });

        document.getElementById('bitisTarihi').addEventListener('change', function() {
            const baslangicTarihi = document.getElementById('baslangicTarihi');
            if (this.value && baslangicTarihi.value && this.value < baslangicTarihi.value) {
                this.setCustomValidity('Bitiş tarihi başlangıç tarihinden önce olamaz.');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>

