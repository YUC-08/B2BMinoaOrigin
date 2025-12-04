<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fire/Zayi Kaydı Oluştur - MINOA</title>
    <?php include 'navbar.php'; ?>
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

        .btn-save {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
        }

        .btn-save:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
            transform: translateY(-1px);
        }

        .content-wrapper {
            padding: 32px;
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

        /* Fire/Zayi Selection */
        .type-selection {
            padding: 24px;
            border-bottom: 1px solid #e5e7eb;
        }

        .type-buttons {
            display: flex;
            gap: 12px;
        }

        .type-btn {
            flex: 1;
            padding: 12px 24px;
            border: 2px solid;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
        }

        .type-btn.fire {
            border-color: #10b981;
            background: white;
            color: #10b981;
        }

        .type-btn.fire.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-color: transparent;
        }

        .type-btn.zayi {
            border-color: #ef4444;
            background: white;
            color: #ef4444;
        }

        .type-btn.zayi.active {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border-color: transparent;
        }

        /* Filter Section */
        .filter-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            padding: 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            color: #1e3a8a;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .filter-group select {
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            background: white;
        }

        .filter-group select:hover {
            border-color: #3b82f6;
        }

        .filter-group select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* Table Controls */
        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
        }

        .show-entries {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #6b7280;
        }

        .entries-select {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .entries-select:hover {
            border-color: #3b82f6;
        }

        .entries-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-box {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .search-input {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            width: 250px;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8fafc;
        }

        th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: #1e3a8a;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
            white-space: nowrap;
        }

        th.sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
            padding-right: 24px;
        }

        th.sortable:hover {
            background: #f1f5f9;
        }

        th.sortable::after {
            content: '◆';
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 8px;
            color: #9ca3af;
        }

        td {
            padding: 16px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
            color: #374151;
        }

        tbody tr:hover {
            background: #f9fafb;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        /* Quantity Controls */
        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .qty-btn {
            width: 32px;
            height: 32px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .qty-btn:hover {
            background: #f8fafc;
            border-color: #3b82f6;
        }

        .qty-input {
            width: 80px;
            padding: 8px;
            text-align: center;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
        }

        .qty-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* Description Input */
        .desc-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .desc-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        /* File Upload */
        .file-upload-cell {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .file-upload-btn {
            padding: 6px 12px;
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            width: fit-content;
        }

        .file-upload-btn:hover {
            background: #bfdbfe;
        }

        .file-upload-cell input[type="file"] {
            display: none;
        }

        .file-name {
            font-size: 12px;
            color: #6b7280;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state-text {
            font-size: 16px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <main class="main-content">
        <div class="page-header">
            <h2>Fire/Zayi Kaydı Oluştur</h2>
            <button class="btn-save" onclick="saveRecord()">
                <span>✓</span>
                <span>Kaydet</span>
            </button>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <div class="type-selection">
                    <div class="type-buttons">
                        <button type="button" class="type-btn fire active" onclick="selectType('fire')">
                            Fire
                        </button>
                        <button type="button" class="type-btn zayi" onclick="selectType('zayi')">
                            Zayi
                        </button>
                    </div>
                </div>

                <div class="filter-section">
                    <div class="filter-group">
                        <label for="kalem-tanim">Kalem Tanımı</label>
                        <select id="kalem-tanim">
                            <option value="">Kalem Tanımı Seçin</option>
                            <option value="ekmek">EKŞİ MAYALI TAM BUĞDAY EKMEK</option>
                            <option value="un">UN</option>
                            <option value="granola">MOM'S GRANOLA</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="kalem-grup">Kalem Grubu</label>
                        <select id="kalem-grup">
                            <option value="">Kalem Grubu Seçin</option>
                            <option value="kuru-gida">KURU GIDA</option>
                            <option value="taze-gida">TAZE GIDA</option>
                            <option value="icecek">İÇECEK</option>
                        </select>
                    </div>
                </div>

                <div class="table-controls">
                    <div class="show-entries">
                        <span>Sayfada</span>
                        <select class="entries-select" id="entries-per-page">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <span>kayıt göster</span>
                    </div>
                    <div class="search-box">
                        <label for="table-search">Ara:</label>
                        <input type="text" id="table-search" class="search-input" placeholder="Arama yapın...">
                    </div>
                </div>

                <div class="table-container">
                    <table id="items-table">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="kodu">Kodu</th>
                                <th class="sortable" data-sort="tanim">Tanım</th>
                                <th class="sortable" data-sort="grup">Grup</th>
                                <th>Miktar</th>
                                <th class="sortable" data-sort="olcu-birimi">Ölçü Birimi</th>
                                <th>Açıklama</th>
                                <th>Görsel</th>
                            </tr>
                        </thead>
                        <tbody id="table-body">
                            <!-- Veriler JavaScript ile yüklenecek -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        let currentType = 'fire';
        let allItems = [];
        let filteredItems = [];
        let entriesPerPage = 25;
        let currentPage = 1;
        let currentSort = { column: null, direction: 'asc' };
        let formData = {}; // Satır bazında form verileri

        // Type seçimi
        function selectType(type) {
            currentType = type;
            document.querySelectorAll('.type-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelector(`.type-btn.${type}`).classList.add('active');
        }

        // Filtreleme ve arama
        function applyFilters() {
            const kalemTanim = document.getElementById('kalem-tanim').value.toLowerCase();
            const kalemGrup = document.getElementById('kalem-grup').value.toLowerCase();
            const search = document.getElementById('table-search').value.toLowerCase();

            filteredItems = allItems.filter(item => {
                if (kalemTanim && !item.tanim.toLowerCase().includes(kalemTanim)) return false;
                if (kalemGrup && item.grup.toLowerCase() !== kalemGrup) return false;
                if (search) {
                    const searchable = `${item.kodu} ${item.tanim} ${item.grup}`.toLowerCase();
                    if (!searchable.includes(search)) return false;
                }
                return true;
            });

            currentPage = 1;
            renderTable();
        }

        // Tablo render
        function renderTable() {
            const tbody = document.getElementById('table-body');
            const start = (currentPage - 1) * entriesPerPage;
            const end = start + entriesPerPage;
            const pageData = filteredItems.slice(start, end);

            if (pageData.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="empty-state">
                            <div class="empty-state-icon">📋</div>
                            <div class="empty-state-text">Kayıt bulunamadı</div>
                        </td>
                    </tr>
                `;
            } else {
                tbody.innerHTML = pageData.map((item, index) => {
                    const rowIndex = start + index;
                    const itemData = formData[item.kodu] || { miktar: 0, aciklama: '', gorsel: null };
                    
                    return `
                        <tr>
                            <td>${item.kodu}</td>
                            <td>${item.tanim}</td>
                            <td>${item.grup}</td>
                            <td>
                                <div class="quantity-controls">
                                    <button class="qty-btn" onclick="changeQuantity('${item.kodu}', -1)">-</button>
                                    <input type="number" class="qty-input" id="qty_${item.kodu}" 
                                           value="${itemData.miktar}" min="0" 
                                           onchange="updateQuantity('${item.kodu}', this.value)">
                                    <button class="qty-btn" onclick="changeQuantity('${item.kodu}', 1)">+</button>
                                </div>
                            </td>
                            <td>${item.olcuBirimi}</td>
                            <td>
                                <input type="text" class="desc-input" id="desc_${item.kodu}" 
                                       placeholder="Açıklama giriniz" 
                                       value="${itemData.aciklama}"
                                       onchange="updateDescription('${item.kodu}', this.value)">
                            </td>
                            <td>
                                <div class="file-upload-cell">
                                    <label for="file_${item.kodu}" class="file-upload-btn">Dosya Seç</label>
                                    <input type="file" id="file_${item.kodu}" accept="image/*" 
                                           onchange="handleFileSelect('${item.kodu}', this)">
                                    <div class="file-name" id="file_name_${item.kodu}">Dosya seçilmedi</div>
                                </div>
                            </td>
                        </tr>
                    `;
                }).join('');
            }
        }

        // Miktar değiştir
        function changeQuantity(kodu, delta) {
            if (!formData[kodu]) {
                formData[kodu] = { miktar: 0, aciklama: '', gorsel: null };
            }
            let newQty = (formData[kodu].miktar || 0) + delta;
            if (newQty < 0) newQty = 0;
            formData[kodu].miktar = newQty;
            document.getElementById(`qty_${kodu}`).value = newQty;
        }

        // Miktar güncelle
        function updateQuantity(kodu, value) {
            if (!formData[kodu]) {
                formData[kodu] = { miktar: 0, aciklama: '', gorsel: null };
            }
            formData[kodu].miktar = parseFloat(value) || 0;
        }

        // Açıklama güncelle
        function updateDescription(kodu, value) {
            if (!formData[kodu]) {
                formData[kodu] = { miktar: 0, aciklama: '', gorsel: null };
            }
            formData[kodu].aciklama = value;
        }

        // Dosya seç
        function handleFileSelect(kodu, input) {
            if (!formData[kodu]) {
                formData[kodu] = { miktar: 0, aciklama: '', gorsel: null };
            }
            if (input.files && input.files[0]) {
                formData[kodu].gorsel = input.files[0];
                document.getElementById(`file_name_${kodu}`).textContent = input.files[0].name;
            } else {
                formData[kodu].gorsel = null;
                document.getElementById(`file_name_${kodu}`).textContent = 'Dosya seçilmedi';
            }
        }

        // Sıralama
        function sortTable(column) {
            if (currentSort.column === column) {
                currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                currentSort.column = column;
                currentSort.direction = 'asc';
            }

            filteredItems.sort((a, b) => {
                let aVal = a[column];
                let bVal = b[column];

                if (column === 'kodu') {
                    aVal = parseFloat(aVal) || 0;
                    bVal = parseFloat(bVal) || 0;
                } else {
                    aVal = String(aVal || '').toLowerCase();
                    bVal = String(bVal || '').toLowerCase();
                }

                if (aVal < bVal) return currentSort.direction === 'asc' ? -1 : 1;
                if (aVal > bVal) return currentSort.direction === 'asc' ? 1 : -1;
                return 0;
            });

            renderTable();
        }

        // Kaydet
        function saveRecord() {
            // Sadece miktarı > 0 olan kayıtları topla
            const records = [];
            for (const [kodu, data] of Object.entries(formData)) {
                if (data.miktar > 0) {
                    const item = allItems.find(i => i.kodu === kodu);
                    records.push({
                        type: currentType,
                        kodu: kodu,
                        tanim: item?.tanim || '',
                        grup: item?.grup || '',
                        miktar: data.miktar,
                        olcuBirimi: item?.olcuBirimi || '',
                        aciklama: data.aciklama,
                        gorsel: data.gorsel
                    });
                }
            }

            if (records.length === 0) {
                alert('Lütfen en az bir kalem için miktar giriniz!');
                return;
            }

            // Gerçek uygulamada API çağrısı yapılacak
            console.log('Fire/Zayi kaydı gönderiliyor:', records);
            alert(`${records.length} kayıt başarıyla kaydedildi! (Demo)`);
            window.location.href = 'Fire-Zayi.php';
        }

        // Event listeners
        document.getElementById('kalem-tanim').addEventListener('change', applyFilters);
        document.getElementById('kalem-grup').addEventListener('change', applyFilters);
        document.getElementById('table-search').addEventListener('input', applyFilters);
        document.getElementById('entries-per-page').addEventListener('change', function() {
            entriesPerPage = parseInt(this.value);
            currentPage = 1;
            renderTable();
        });

        // Sıralama için click event
        document.querySelectorAll('th.sortable').forEach(th => {
            th.addEventListener('click', () => {
                sortTable(th.dataset.sort);
            });
        });

        // Sayfa yüklendiğinde
        document.addEventListener('DOMContentLoaded', function() {
            // Örnek veri (gerçek uygulamada API'den gelecek)
            allItems = [
                { kodu: '10002', tanim: 'EKŞİ MAYALI TAM BUĞDAY EKMEK', grup: 'KURU GIDA', olcuBirimi: 'GR' },
                { kodu: '10003', tanim: 'UN', grup: 'KURU GIDA', olcuBirimi: 'GR' },
                { kodu: '10010', tanim: 'MOM\'S GRANOLA', grup: 'KURU GIDA', olcuBirimi: 'GR' },
                { kodu: '10015', tanim: 'ZEYTİN', grup: 'TAZE GIDA', olcuBirimi: 'GR' },
                { kodu: '10020', tanim: 'SU', grup: 'İÇECEK', olcuBirimi: 'AD' }
            ];

            filteredItems = [...allItems];
            renderTable();
        });
    </script>
</body>
</html>

