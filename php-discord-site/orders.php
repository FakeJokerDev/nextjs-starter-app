<?php
// orders.php - Gestione Ordini

require_once 'config.php';
require_once 'functions.php';

ensureLoggedIn();

// Verifica permessi
if (!checkModulePermission('orders')) {
    $_SESSION['error_message'] = 'Non hai i permessi per accedere a questa sezione.';
    header("Location: index.php");
    exit;
}

$pageTitle = 'Gestione Ordini';
$conn = db_connect();

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Token di sicurezza non valido.';
        header("Location: orders.php");
        exit;
    }
    
    switch ($action) {
        case 'add_order':
            $orderNumber = sanitizeInput($_POST['order_number']);
            $customerName = sanitizeInput($_POST['customer_name']);
            $customerEmail = sanitizeInput($_POST['customer_email']);
            $customerPhone = sanitizeInput($_POST['customer_phone']);
            $orderDate = $_POST['order_date'];
            $deliveryDate = $_POST['delivery_date'] ?: null;
            $notes = sanitizeInput($_POST['notes']);
            $totalAmount = (float)$_POST['total_amount'];
            
            if (empty($orderNumber) || empty($customerName) || empty($orderDate)) {
                $_SESSION['error_message'] = 'Numero ordine, cliente e data sono obbligatori.';
                break;
            }
            
            try {
                $stmt = $conn->prepare("
                    INSERT INTO orders (order_number, customer_name, customer_email, customer_phone, order_date, delivery_date, total_amount, notes, created_by) 
                    VALUES (:order_number, :customer_name, :customer_email, :customer_phone, :order_date, :delivery_date, :total_amount, :notes, :created_by)
                ");
                $stmt->execute([
                    ':order_number' => $orderNumber,
                    ':customer_name' => $customerName,
                    ':customer_email' => $customerEmail,
                    ':customer_phone' => $customerPhone,
                    ':order_date' => $orderDate,
                    ':delivery_date' => $deliveryDate,
                    ':total_amount' => $totalAmount,
                    ':notes' => $notes,
                    ':created_by' => $_SESSION['user_id']
                ]);
                
                logActivity($_SESSION['user_id'], 'Ordine Creato', "Creato ordine: $orderNumber per $customerName");
                $_SESSION['success_message'] = 'Ordine creato con successo.';
                
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $_SESSION['error_message'] = 'Numero ordine già esistente.';
                } else {
                    $_SESSION['error_message'] = 'Errore durante la creazione dell\'ordine.';
                }
            }
            break;
            
        case 'update_status':
            $orderId = (int)$_POST['order_id'];
            $newStatus = $_POST['new_status'];
            $assignedTo = $_POST['assigned_to'] ?: null;
            
            $validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
            if (!in_array($newStatus, $validStatuses)) {
                $_SESSION['error_message'] = 'Stato non valido.';
                break;
            }
            
            try {
                // Ottieni informazioni ordine
                $stmt = $conn->prepare("SELECT order_number, customer_name, status FROM orders WHERE id = :id");
                $stmt->execute([':id' => $orderId]);
                $order = $stmt->fetch();
                
                if ($order) {
                    // Aggiorna stato
                    $stmt = $conn->prepare("UPDATE orders SET status = :status, assigned_to = :assigned_to WHERE id = :id");
                    $stmt->execute([
                        ':status' => $newStatus,
                        ':assigned_to' => $assignedTo,
                        ':id' => $orderId
                    ]);
                    
                    logActivity($_SESSION['user_id'], 'Stato Ordine Aggiornato', 
                        "Ordine {$order['order_number']}: {$order['status']} → $newStatus");
                    $_SESSION['success_message'] = 'Stato ordine aggiornato con successo.';
                }
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Errore durante l\'aggiornamento dello stato.';
            }
            break;
            
        case 'delete_order':
            $orderId = (int)$_POST['order_id'];
            
            try {
                $stmt = $conn->prepare("SELECT order_number, customer_name FROM orders WHERE id = :id");
                $stmt->execute([':id' => $orderId]);
                $order = $stmt->fetch();
                
                if ($order) {
                    $stmt = $conn->prepare("DELETE FROM orders WHERE id = :id");
                    $stmt->execute([':id' => $orderId]);
                    
                    logActivity($_SESSION['user_id'], 'Ordine Eliminato', 
                        "Eliminato ordine: {$order['order_number']} di {$order['customer_name']}");
                    $_SESSION['success_message'] = 'Ordine eliminato con successo.';
                }
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Errore durante l\'eliminazione dell\'ordine.';
            }
            break;
    }
    
    header("Location: orders.php");
    exit;
}

// Parametri di ricerca e filtri
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Costruisci query
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(o.order_number LIKE :search OR o.customer_name LIKE :search OR o.customer_email LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($status)) {
    $whereConditions[] = "o.status = :status";
    $params[':status'] = $status;
}

if (!empty($dateFrom)) {
    $whereConditions[] = "o.order_date >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if (!empty($dateTo)) {
    $whereConditions[] = "o.order_date <= :date_to";
    $params[':date_to'] = $dateTo;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Ottieni ordini
$stmt = $conn->prepare("
    SELECT o.*, 
           u1.username as created_by_name,
           u2.username as assigned_to_name
    FROM orders o 
    LEFT JOIN users u1 ON o.created_by = u1.id 
    LEFT JOIN users u2 ON o.assigned_to = u2.id 
    $whereClause 
    ORDER BY o.created_at DESC
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Ottieni utenti per assegnazione
$stmt = $conn->prepare("SELECT id, username FROM users WHERE is_active = 1 ORDER BY username");
$stmt->execute();
$users = $stmt->fetchAll();

// Statistiche
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
        SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(total_amount) as total_value
    FROM orders
");
$stmt->execute();
$stats = $stmt->fetch();
?>

<?php include 'header.php'; ?>

<div class="mb-6">
    <div class="flex justify-between items-center mb-4">
        <div>
            <h1 class="text-xl font-bold mb-2">Gestione Ordini</h1>
            <p class="text-secondary">Gestisci ordini clienti, stati e consegne</p>
        </div>
        <button onclick="openModal('addOrderModal')" class="btn btn-primary">
            Nuovo Ordine
        </button>
    </div>
    
    <!-- Statistiche -->
    <div class="stats-grid mb-6">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['total'] ?? 0 ?></div>
            <div class="stat-label">Ordini Totali</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['pending'] ?? 0 ?></div>
            <div class="stat-label">In Attesa</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['processing'] ?? 0 ?></div>
            <div class="stat-label">In Lavorazione</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">€<?= number_format($stats['total_value'] ?? 0, 2) ?></div>
            <div class="stat-label">Valore Totale</div>
        </div>
    </div>
</div>

<!-- Filtri e Ricerca -->
<div class="card mb-6">
    <div class="card-content">
        <form method="GET" class="grid grid-cols-1 grid-cols-5 gap-4 items-end">
            <div class="form-group mb-0">
                <label class="form-label">Ricerca</label>
                <input type="text" name="search" class="form-input" 
                       placeholder="Numero ordine, cliente..." 
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            
            <div class="form-group mb-0">
                <label class="form-label">Stato</label>
                <select name="status" class="form-select">
                    <option value="">Tutti gli stati</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>In Attesa</option>
                    <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>In Lavorazione</option>
                    <option value="shipped" <?= $status === 'shipped' ? 'selected' : '' ?>>Spedito</option>
                    <option value="delivered" <?= $status === 'delivered' ? 'selected' : '' ?>>Consegnato</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Annullato</option>
                </select>
            </div>
            
            <div class="form-group mb-0">
                <label class="form-label">Data Da</label>
                <input type="date" name="date_from" class="form-input" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            
            <div class="form-group mb-0">
                <label class="form-label">Data A</label>
                <input type="date" name="date_to" class="form-input" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">Filtra</button>
                <a href="orders.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabella Ordini -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Ordini (<?= count($orders) ?>)</h2>
    </div>
    <div class="card-content">
        <?php if (empty($orders)): ?>
            <p class="text-center text-secondary py-8">Nessun ordine trovato</p>
        <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Numero</th>
                            <th>Cliente</th>
                            <th>Data Ordine</th>
                            <th>Stato</th>
                            <th>Importo</th>
                            <th>Assegnato a</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td class="font-semibold"><?= htmlspecialchars($order['order_number']) ?></td>
                                <td>
                                    <div class="font-semibold"><?= htmlspecialchars($order['customer_name']) ?></div>
                                    <?php if ($order['customer_email']): ?>
                                        <div class="text-sm text-secondary"><?= htmlspecialchars($order['customer_email']) ?></div>
                                    <?php endif; ?>
                                    <?php if ($order['customer_phone']): ?>
                                        <div class="text-sm text-secondary"><?= htmlspecialchars($order['customer_phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?= formatDate($order['order_date']) ?></div>
                                    <?php if ($order['delivery_date']): ?>
                                        <div class="text-sm text-secondary">Consegna: <?= formatDate($order['delivery_date']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php
                                        switch($order['status']) {
                                            case 'delivered': echo 'success'; break;
                                            case 'shipped': echo 'info'; break;
                                            case 'processing': echo 'warning'; break;
                                            case 'cancelled': echo 'error'; break;
                                            default: echo 'warning';
                                        }
                                    ?>">
                                        <?php
                                        switch($order['status']) {
                                            case 'pending': echo 'In Attesa'; break;
                                            case 'processing': echo 'In Lavorazione'; break;
                                            case 'shipped': echo 'Spedito'; break;
                                            case 'delivered': echo 'Consegnato'; break;
                                            case 'cancelled': echo 'Annullato'; break;
                                            default: echo ucfirst($order['status']);
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td class="font-semibold">€<?= number_format($order['total_amount'], 2) ?></td>
                                <td><?= htmlspecialchars($order['assigned_to_name'] ?? '-') ?></td>
                                <td>
                                    <div class="flex gap-2">
                                        <button onclick="openUpdateStatusModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_number']) ?>', '<?= $order['status'] ?>', <?= $order['assigned_to'] ?? 'null' ?>)" 
                                                class="btn btn-secondary btn-sm">
                                            Aggiorna
                                        </button>
                                        <button onclick="viewOrderDetails(<?= $order['id'] ?>)" 
                                                class="btn btn-info btn-sm">
                                            Dettagli
                                        </button>
                                        <button onclick="deleteOrder(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_number']) ?>')" 
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

<!-- Modal Nuovo Ordine -->
<div id="addOrderModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Nuovo Ordine</h3>
        </div>
        <form method="POST">
            <div class="modal-content">
                <input type="hidden" name="action" value="add_order">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div class="grid grid-cols-1 grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label">Numero Ordine *</label>
                        <input type="text" name="order_number" class="form-input" required 
                               value="ORD-<?= date('Ymd') ?>-<?= str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Data Ordine *</label>
                        <input type="date" name="order_date" class="form-input" required value="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Nome Cliente *</label>
                        <input type="text" name="customer_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email Cliente</label>
                        <input type="email" name="customer_email" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Telefono Cliente</label>
                        <input type="tel" name="customer_phone" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Data Consegna</label>
                        <input type="date" name="delivery_date" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Importo Totale (€)</label>
                        <input type="number" name="total_amount" class="form-input" step="0.01" min="0" value="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Note</label>
                    <textarea name="notes" class="form-textarea" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('addOrderModal')" class="btn btn-secondary">Annulla</button>
                <button type="submit" class="btn btn-primary">Crea Ordine</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Aggiorna Stato -->
<div id="updateStatusModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Aggiorna Stato Ordine</h3>
        </div>
        <form method="POST">
            <div class="modal-content">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="order_id" id="update_order_id">
                
                <div class="form-group">
                    <label class="form-label">Ordine</label>
                    <input type="text" id="update_order_number" class="form-input" readonly>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Nuovo Stato</label>
                    <select name="new_status" id="new_status" class="form-select" required>
                        <option value="pending">In Attesa</option>
                        <option value="processing">In Lavorazione</option>
                        <option value="shipped">Spedito</option>
                        <option value="delivered">Consegnato</option>
                        <option value="cancelled">Annullato</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Assegna a</label>
                    <select name="assigned_to" id="assigned_to" class="form-select">
                        <option value="">Nessuno</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('updateStatusModal')" class="btn btn-secondary">Annulla</button>
                <button type="submit" class="btn btn-primary">Aggiorna</button>
            </div>
        </form>
    </div>
</div>

<script>
function openUpdateStatusModal(orderId, orderNumber, currentStatus, assignedTo) {
    document.getElementById('update_order_id').value = orderId;
    document.getElementById('update_order_number').value = orderNumber;
    document.getElementById('new_status').value = currentStatus;
    document.getElementById('assigned_to').value = assignedTo || '';
    openModal('updateStatusModal');
}

function viewOrderDetails(orderId) {
    // Implementa visualizzazione dettagli ordine
    alert('Funzionalità dettagli ordine da implementare');
}

function deleteOrder(orderId, orderNumber) {
    if (confirmDelete(`Sei sicuro di voler eliminare l'ordine "${orderNumber}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_order">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="order_id" value="${orderId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include 'footer.php'; ?>
