<?php
// index.php - Dashboard principale con ordini e comunicazioni

require_once 'config.php';
require_once 'functions.php';

ensureLoggedIn();

$pageTitle = 'Dashboard';
$conn = db_connect();

// Ottieni statistiche generali
$stats = [];

// Conteggio ordini per stato
$stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM orders GROUP BY status");
$stmt->execute();
$orderStats = $stmt->fetchAll();

// Conteggio prodotti in magazzino
$stmt = $conn->prepare("SELECT COUNT(*) as total_products, SUM(quantity) as total_quantity FROM warehouse");
$stmt->execute();
$warehouseStats = $stmt->fetch();

// Conteggio personale attivo
$stmt = $conn->prepare("SELECT COUNT(*) as active_personnel FROM personnel WHERE is_active = 1");
$stmt->execute();
$personnelStats = $stmt->fetch();

// Ordini recenti (ultimi 10)
$stmt = $conn->prepare("
    SELECT o.*, u.username as created_by_name 
    FROM orders o 
    LEFT JOIN users u ON o.created_by = u.id 
    ORDER BY o.created_at DESC 
    LIMIT 10
");
$stmt->execute();
$recentOrders = $stmt->fetchAll();

// Comunicazioni attive
$stmt = $conn->prepare("
    SELECT c.*, u.username as created_by_name 
    FROM communications c 
    LEFT JOIN users u ON c.created_by = u.id 
    WHERE c.is_active = 1 
    AND (c.expires_at IS NULL OR c.expires_at > NOW())
    ORDER BY c.created_at DESC
");
$stmt->execute();
$communications = $stmt->fetchAll();

// Prodotti con scorte basse
$stmt = $conn->prepare("
    SELECT * FROM warehouse 
    WHERE quantity <= min_quantity 
    AND min_quantity > 0 
    ORDER BY (quantity - min_quantity) ASC 
    LIMIT 5
");
$stmt->execute();
$lowStockProducts = $stmt->fetchAll();

// Attività recenti (ultimi 10 log)
$stmt = $conn->prepare("
    SELECT l.*, u.username 
    FROM logs l 
    LEFT JOIN users u ON l.user_id = u.id 
    ORDER BY l.created_at DESC 
    LIMIT 10
");
$stmt->execute();
$recentActivities = $stmt->fetchAll();

// Mostra messaggio di benvenuto se è il primo accesso
$showWelcome = isset($_GET['welcome']) && $_GET['welcome'] == '1';
?>

<?php include 'header.php'; ?>

<?php if ($showWelcome): ?>
<div class="alert alert-success">
    <strong>Benvenuto!</strong> Accesso effettuato con successo. Benvenuto nel sistema di gestione aziendale.
</div>
<?php endif; ?>

<div class="mb-6">
    <h1 class="text-xl font-bold mb-2">Dashboard</h1>
    <p class="text-secondary">Panoramica generale del sistema e attività recenti</p>
</div>

<!-- Statistiche principali -->
<div class="stats-grid mb-6">
    <div class="stat-card">
        <div class="stat-value"><?= $warehouseStats['total_products'] ?? 0 ?></div>
        <div class="stat-label">Prodotti in Magazzino</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?= $warehouseStats['total_quantity'] ?? 0 ?></div>
        <div class="stat-label">Quantità Totale</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value">
            <?php
            $pendingOrders = 0;
            foreach ($orderStats as $stat) {
                if ($stat['status'] === 'pending') {
                    $pendingOrders = $stat['count'];
                    break;
                }
            }
            echo $pendingOrders;
            ?>
        </div>
        <div class="stat-label">Ordini in Attesa</div>
    </div>
    
    <div class="stat-card">
        <div class="stat-value"><?= $personnelStats['active_personnel'] ?? 0 ?></div>
        <div class="stat-label">Personale Attivo</div>
    </div>
</div>

<div class="grid grid-cols-1 grid-cols-2 gap-6">
    <!-- Comunicazioni -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Comunicazioni</h2>
            <p class="card-description">Annunci e comunicazioni importanti</p>
        </div>
        <div class="card-content">
            <?php if (empty($communications)): ?>
                <p class="text-secondary">Nessuna comunicazione attiva</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($communications as $comm): ?>
                        <div class="border-l-4 pl-4 <?php
                            switch($comm['type']) {
                                case 'urgent': echo 'border-red-500'; break;
                                case 'warning': echo 'border-yellow-500'; break;
                                case 'info': echo 'border-blue-500'; break;
                                default: echo 'border-gray-300';
                            }
                        ?>">
                            <div class="flex items-center gap-2 mb-1">
                                <h3 class="font-semibold"><?= htmlspecialchars($comm['title']) ?></h3>
                                <span class="badge badge-<?php
                                    switch($comm['type']) {
                                        case 'urgent': echo 'error'; break;
                                        case 'warning': echo 'warning'; break;
                                        case 'info': echo 'info'; break;
                                        default: echo 'info';
                                    }
                                ?>"><?= ucfirst($comm['type']) ?></span>
                            </div>
                            <p class="text-sm text-secondary mb-2"><?= nl2br(htmlspecialchars($comm['message'])) ?></p>
                            <div class="text-xs text-secondary">
                                Da: <?= htmlspecialchars($comm['created_by_name'] ?? 'Sistema') ?> • 
                                <?= formatDate($comm['created_at']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ordini Recenti -->
    <div class="card">
        <div class="card-header">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="card-title">Ordini Recenti</h2>
                    <p class="card-description">Ultimi ordini inseriti nel sistema</p>
                </div>
                <?php if (checkModulePermission('orders')): ?>
                    <a href="orders.php" class="btn btn-primary btn-sm">Vedi Tutti</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-content">
            <?php if (empty($recentOrders)): ?>
                <p class="text-secondary">Nessun ordine presente</p>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Numero</th>
                                <th>Cliente</th>
                                <th>Stato</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $order): ?>
                                <tr>
                                    <td class="font-semibold"><?= htmlspecialchars($order['order_number']) ?></td>
                                    <td><?= htmlspecialchars($order['customer_name']) ?></td>
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
                                            <?= ucfirst($order['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-sm"><?= formatDate($order['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sezione inferiore -->
<div class="grid grid-cols-1 grid-cols-2 gap-6 mt-6">
    <!-- Scorte Basse -->
    <?php if (checkModulePermission('warehouse')): ?>
    <div class="card">
        <div class="card-header">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="card-title">Scorte Basse</h2>
                    <p class="card-description">Prodotti che necessitano rifornimento</p>
                </div>
                <a href="warehouse.php" class="btn btn-warning btn-sm">Gestisci</a>
            </div>
        </div>
        <div class="card-content">
            <?php if (empty($lowStockProducts)): ?>
                <p class="text-secondary">Tutte le scorte sono sufficienti</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($lowStockProducts as $product): ?>
                        <div class="flex justify-between items-center p-3 bg-yellow-50 rounded border-l-4 border-yellow-400">
                            <div>
                                <div class="font-semibold"><?= htmlspecialchars($product['product_name']) ?></div>
                                <div class="text-sm text-secondary">Codice: <?= htmlspecialchars($product['product_code']) ?></div>
                            </div>
                            <div class="text-right">
                                <div class="font-bold text-warning-color"><?= $product['quantity'] ?></div>
                                <div class="text-xs text-secondary">Min: <?= $product['min_quantity'] ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Attività Recenti -->
    <?php if (checkModulePermission('logs')): ?>
    <div class="card">
        <div class="card-header">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="card-title">Attività Recenti</h2>
                    <p class="card-description">Ultime azioni effettuate nel sistema</p>
                </div>
                <a href="logs.php" class="btn btn-secondary btn-sm">Vedi Tutti</a>
            </div>
        </div>
        <div class="card-content">
            <?php if (empty($recentActivities)): ?>
                <p class="text-secondary">Nessuna attività registrata</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($recentActivities as $activity): ?>
                        <div class="flex items-start gap-3 p-2 hover:bg-gray-50 rounded">
                            <div class="w-2 h-2 bg-primary-color rounded-full mt-2 flex-shrink-0"></div>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm">
                                    <span class="font-semibold"><?= htmlspecialchars($activity['username'] ?? 'Sistema') ?></span>
                                    <?= htmlspecialchars($activity['action']) ?>
                                </div>
                                <div class="text-xs text-secondary"><?= formatDate($activity['created_at']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
