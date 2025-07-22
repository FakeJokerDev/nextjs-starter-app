<?php
// warehouse.php - Gestione Magazzino

require_once 'config.php';
require_once 'functions.php';

ensureLoggedIn();

// Verifica permessi
if (!checkModulePermission('warehouse')) {
    $_SESSION['error_message'] = 'Non hai i permessi per accedere a questa sezione.';
    header("Location: index.php");
    exit;
}

$pageTitle = 'Gestione Magazzino';
$conn = db_connect();

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Token di sicurezza non valido.';
        header("Location: warehouse.php");
        exit;
    }
    
    switch ($action) {
        case 'add_product':
            $productName = sanitizeInput($_POST['product_name']);
            $productCode = sanitizeInput($_POST['product_code']);
            $description = sanitizeInput($_POST['description']);
            $quantity = (int)$_POST['quantity'];
            $minQuantity = (int)$_POST['min_quantity'];
            $unitPrice = (float)$_POST['unit_price'];
            $category = sanitizeInput($_POST['category']);
            $location = sanitizeInput($_POST['location']);
            $supplier = sanitizeInput($_POST['supplier']);
            
            if (empty($productName) || empty($productCode)) {
                $_SESSION['error_message'] = 'Nome prodotto e codice sono obbligatori.';
                break;
            }
            
            try {
                $stmt = $conn->prepare("
                    INSERT INTO warehouse (product_name, product_code, description, quantity, min_quantity, unit_price, category, location, supplier, created_by) 
                    VALUES (:product_name, :product_code, :description, :quantity, :min_quantity, :unit_price, :category, :location, :supplier, :created_by)
                ");
                $stmt->execute([
                    ':product_name' => $productName,
                    ':product_code' => $productCode,
                    ':description' => $description,
                    ':quantity' => $quantity,
                    ':min_quantity' => $minQuantity,
                    ':unit_price' => $unitPrice,
                    ':category' => $category,
                    ':location' => $location,
                    ':supplier' => $supplier,
                    ':created_by' => $_SESSION['user_id']
                ]);
                
                logActivity($_SESSION['user_id'], 'Prodotto Aggiunto', "Aggiunto prodotto: $productName ($productCode)");
                $_SESSION['success_message'] = 'Prodotto aggiunto con successo.';
                
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $_SESSION['error_message'] = 'Codice prodotto già esistente.';
                } else {
                    $_SESSION['error_message'] = 'Errore durante l\'aggiunta del prodotto.';
                }
            }
            break;
            
        case 'update_quantity':
            $productId = (int)$_POST['product_id'];
            $newQuantity = (int)$_POST['new_quantity'];
            $reason = sanitizeInput($_POST['reason']);
            
            try {
                // Ottieni quantità attuale
                $stmt = $conn->prepare("SELECT product_name, quantity FROM warehouse WHERE id = :id");
                $stmt->execute([':id' => $productId]);
                $product = $stmt->fetch();
                
                if ($product) {
                    // Aggiorna quantità
                    $stmt = $conn->prepare("UPDATE warehouse SET quantity = :quantity WHERE id = :id");
                    $stmt->execute([':quantity' => $newQuantity, ':id' => $productId]);
                    
                    // Registra movimento
                    $movementType = $newQuantity > $product['quantity'] ? 'in' : ($newQuantity < $product['quantity'] ? 'out' : 'adjustment');
                    $stmt = $conn->prepare("
                        INSERT INTO warehouse_movements (product_id, movement_type, quantity, previous_quantity, new_quantity, reason, created_by) 
                        VALUES (:product_id, :movement_type, :quantity, :previous_quantity, :new_quantity, :reason, :created_by)
                    ");
                    $stmt->execute([
                        ':product_id' => $productId,
                        ':movement_type' => $movementType,
                        ':quantity' => abs($newQuantity - $product['quantity']),
                        ':previous_quantity' => $product['quantity'],
                        ':new_quantity' => $newQuantity,
                        ':reason' => $reason,
                        ':created_by' => $_SESSION['user_id']
                    ]);
                    
                    logActivity($_SESSION['user_id'], 'Quantità Aggiornata', "Prodotto: {$product['product_name']}, Da: {$product['quantity']} A: $newQuantity");
                    $_SESSION['success_message'] = 'Quantità aggiornata con successo.';
                }
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Errore durante l\'aggiornamento della quantità.';
            }
            break;
            
        case 'delete_product':
            $productId = (int)$_POST['product_id'];
            
            try {
                $stmt = $conn->prepare("SELECT product_name FROM warehouse WHERE id = :id");
                $stmt->execute([':id' => $productId]);
                $product = $stmt->fetch();
                
                if ($product) {
                    $stmt = $conn->prepare("DELETE FROM warehouse WHERE id = :id");
                    $stmt->execute([':id' => $productId]);
                    
                    logActivity($_SESSION['user_id'], 'Prodotto Eliminato', "Eliminato prodotto: {$product['product_name']}");
                    $_SESSION['success_message'] = 'Prodotto eliminato con successo.';
                }
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Errore durante l\'eliminazione del prodotto.';
            }
            break;
    }
    
    header("Location: warehouse.php");
    exit;
}

// Parametri di ricerca e filtri
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$lowStock = isset($_GET['low_stock']);

// Costruisci query
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(product_name LIKE :search OR product_code LIKE :search OR description LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($category)) {
    $whereConditions[] = "category = :category";
    $params[':category'] = $category;
}

if ($lowStock) {
    $whereConditions[] = "quantity <= min_quantity AND min_quantity > 0";
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Ottieni prodotti
$stmt = $conn->prepare("
    SELECT w.*, u.username as created_by_name 
    FROM warehouse w 
    LEFT JOIN users u ON w.created_by = u.id 
    $whereClause 
    ORDER BY w.product_name ASC
");
$stmt->execute($params);
$products = $stmt->fetchAll();

// Ottieni categorie per il filtro
$stmt = $conn->prepare("SELECT DISTINCT category FROM warehouse WHERE category IS NOT NULL AND category != '' ORDER BY category");
$stmt->execute();
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Statistiche
$stmt = $conn->prepare("SELECT COUNT(*) as total, SUM(quantity) as total_quantity, SUM(quantity * unit_price) as total_value FROM warehouse");
$stmt->execute();
$stats = $stmt->fetch();
?>

<?php include 'header.php'; ?>

<div class="mb-6">
    <div class="flex justify-between items-center mb-4">
        <div>
            <h1 class="text-xl font-bold mb-2">Gestione Magazzino</h1>
            <p class="text-secondary">Gestisci inventario, scorte e movimenti prodotti</p>
        </div>
        <button onclick="openModal('addProductModal')" class="btn btn-primary">
            Aggiungi Prodotto
        </button>
    </div>
    
    <!-- Statistiche -->
    <div class="stats-grid mb-6">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['total'] ?? 0 ?></div>
            <div class="stat-label">Prodotti Totali</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['total_quantity'] ?? 0) ?></div>
            <div class="stat-label">Quantità Totale</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">€<?= number_format($stats['total_value'] ?? 0, 2) ?></div>
            <div class="stat-label">Valore Inventario</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">
                <?php
                $lowStockCount = 0;
                foreach ($products as $product) {
                    if ($product['quantity'] <= $product['min_quantity'] && $product['min_quantity'] > 0) {
                        $lowStockCount++;
                    }
                }
                echo $lowStockCount;
                ?>
            </div>
            <div class="stat-label">Scorte Basse</div>
        </div>
    </div>
</div>

<!-- Filtri e Ricerca -->
<div class="card mb-6">
    <div class="card-content">
        <form method="GET" class="grid grid-cols-1 grid-cols-4 gap-4 items-end">
            <div class="form-group mb-0">
                <label class="form-label">Ricerca</label>
                <input type="text" name="search" class="form-input" 
                       placeholder="Nome, codice o descrizione..." 
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            
            <div class="form-group mb-0">
                <label class="form-label">Categoria</label>
                <select name="category" class="form-select">
                    <option value="">Tutte le categorie</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group mb-0">
                <label class="form-label">Filtri</label>
                <div class="flex items-center gap-2">
                    <input type="checkbox" name="low_stock" id="low_stock" <?= $lowStock ? 'checked' : '' ?>>
                    <label for="low_stock" class="text-sm">Solo scorte basse</label>
                </div>
            </div>
            
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">Filtra</button>
                <a href="warehouse.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabella Prodotti -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Prodotti (<?= count($products) ?>)</h2>
    </div>
    <div class="card-content">
        <?php if (empty($products)): ?>
            <p class="text-center text-secondary py-8">Nessun prodotto trovato</p>
        <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Codice</th>
                            <th>Nome Prodotto</th>
                            <th>Categoria</th>
                            <th>Quantità</th>
                            <th>Min. Scorta</th>
                            <th>Prezzo Unit.</th>
                            <th>Ubicazione</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr class="<?= ($product['quantity'] <= $product['min_quantity'] && $product['min_quantity'] > 0) ? 'bg-red-50' : '' ?>">
                                <td class="font-semibold"><?= htmlspecialchars($product['product_code']) ?></td>
                                <td>
                                    <div class="font-semibold"><?= htmlspecialchars($product['product_name']) ?></div>
                                    <?php if ($product['description']): ?>
                                        <div class="text-sm text-secondary"><?= htmlspecialchars($product['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($product['category'] ?? '-') ?></td>
                                <td>
                                    <span class="font-bold <?= ($product['quantity'] <= $product['min_quantity'] && $product['min_quantity'] > 0) ? 'text-red-600' : '' ?>">
                                        <?= $product['quantity'] ?>
                                    </span>
                                </td>
                                <td><?= $product['min_quantity'] ?></td>
                                <td>€<?= number_format($product['unit_price'], 2) ?></td>
                                <td><?= htmlspecialchars($product['location'] ?? '-') ?></td>
                                <td>
                                    <div class="flex gap-2">
                                        <button onclick="openUpdateQuantityModal(<?= $product['id'] ?>, '<?= htmlspecialchars($product['product_name']) ?>', <?= $product['quantity'] ?>)" 
                                                class="btn btn-secondary btn-sm">
                                            Aggiorna
                                        </button>
                                        <button onclick="deleteProduct(<?= $product['id'] ?>, '<?= htmlspecialchars($product['product_name']) ?>')" 
                                                class="btn btn-error btn-sm">
                                            Elimina
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Aggiungi Prodotto -->
<div id="addProductModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Aggiungi Nuovo Prodotto</h3>
        </div>
        <form method="POST">
            <div class="modal-content">
                <input type="hidden" name="action" value="add_product">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div class="grid grid-cols-1 grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label">Nome Prodotto *</label>
                        <input type="text" name="product_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Codice Prodotto *</label>
                        <input type="text" name="product_code" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Categoria</label>
                        <input type="text" name="category" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Ubicazione</label>
                        <input type="text" name="location" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Quantità</label>
                        <input type="number" name="quantity" class="form-input" value="0" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Scorta Minima</label>
                        <input type="number" name="min_quantity" class="form-input" value="0" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Prezzo Unitario (€)</label>
                        <input type="number" name="unit_price" class="form-input" step="0.01" min="0" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Fornitore</label>
                        <input type="text" name="supplier" class="form-input">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Descrizione</label>
                    <textarea name="description" class="form-textarea" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('addProductModal')" class="btn btn-secondary">Annulla</button>
                <button type="submit" class="btn btn-primary">Aggiungi Prodotto</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Aggiorna Quantità -->
<div id="updateQuantityModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Aggiorna Quantità</h3>
        </div>
        <form method="POST">
            <div class="modal-content">
                <input type="hidden" name="action" value="update_quantity">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="product_id" id="update_product_id">
                
                <div class="form-group">
                    <label class="form-label">Prodotto</label>
                    <input type="text" id="update_product_name" class="form-input" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Quantità Attuale</label>
                    <input type="number" id="current_quantity" class="form-input" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nuova Quantità</label>
                    <input type="number" name="new_quantity" id="new_quantity" class="form-input" min="0" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Motivo</label>
                    <input type="text" name="reason" class="form-input" placeholder="Motivo della modifica...">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('updateQuantityModal')" class="btn btn-secondary">Annulla</button>
                <button type="submit" class="btn btn-primary">Aggiorna</button>
            </div>
        </form>
    </div>
</div>

<script>
function openUpdateQuantityModal(productId, productName, currentQuantity) {
    document.getElementById('update_product_id').value = productId;
    document.getElementById('update_product_name').value = productName;
    document.getElementById('current_quantity').value = currentQuantity;
    document.getElementById('new_quantity').value = currentQuantity;
    openModal('updateQuantityModal');
}

function deleteProduct(productId, productName) {
    if (confirmDelete(`Sei sicuro di voler eliminare il prodotto "${productName}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_product">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="product_id" value="${productId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include 'footer.php'; ?>
