<?php
// logs.php - Log di tutte le attività

require_once 'config.php';
require_once 'functions.php';

ensureLoggedIn();

// Verifica permessi
if (!checkModulePermission('logs')) {
    $_SESSION['error_message'] = 'Non hai i permessi per accedere a questa sezione.';
    header("Location: index.php");
    exit;
}

$pageTitle = 'Log Attività';
$conn = db_connect();

// Gestione azioni POST (per pulizia log)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Token di sicurezza non valido.';
        header("Location: logs.php");
        exit;
    }
    
    switch ($action) {
        case 'clear_old_logs':
            $daysToKeep = (int)$_POST['days_to_keep'];
            if ($daysToKeep < 1) $daysToKeep = 30;
            
            try {
                $stmt = $conn->prepare("DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)");
                $stmt->execute([':days' => $daysToKeep]);
                $deletedRows = $stmt->rowCount();
                
                logActivity($_SESSION['user_id'], 'Pulizia Log', "Eliminati $deletedRows log più vecchi di $daysToKeep giorni");
                $_SESSION['success_message'] = "Eliminati $deletedRows log più vecchi di $daysToKeep giorni.";
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Errore durante la pulizia dei log.';
            }
            break;
            
        case 'export_logs':
            $dateFrom = $_POST['export_date_from'];
            $dateTo = $_POST['export_date_to'];
            
            try {
                $stmt = $conn->prepare("
                    SELECT l.*, u.username 
                    FROM logs l 
                    LEFT JOIN users u ON l.user_id = u.id 
                    WHERE l.created_at BETWEEN :date_from AND :date_to 
                    ORDER BY l.created_at DESC
                ");
                $stmt->execute([
                    ':date_from' => $dateFrom . ' 00:00:00',
                    ':date_to' => $dateTo . ' 23:59:59'
                ]);
                $exportLogs = $stmt->fetchAll();
                
                // Genera CSV
                $filename = 'logs_' . $dateFrom . '_to_' . $dateTo . '.csv';
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Data/Ora', 'Utente', 'Azione', 'Dettagli', 'IP']);
                
                foreach ($exportLogs as $log) {
                    fputcsv($output, [
                        $log['created_at'],
                        $log['username'] ?? 'Sistema',
                        $log['action'],
                        $log['details'],
                        $log['ip_address']
                    ]);
                }
                
                fclose($output);
                exit;
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Errore durante l\'esportazione dei log.';
            }
            break;
    }
    
    header("Location: logs.php");
    exit;
}

// Parametri di ricerca e filtri
$search = $_GET['search'] ?? '';
$user = $_GET['user'] ?? '';
$action = $_GET['action'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

// Costruisci query
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(l.action LIKE :search OR l.details LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($user)) {
    $whereConditions[] = "l.user_id = :user_id";
    $params[':user_id'] = $user;
}

if (!empty($action)) {
    $whereConditions[] = "l.action LIKE :action";
    $params[':action'] = "%$action%";
}

if (!empty($dateFrom)) {
    $whereConditions[] = "l.created_at >= :date_from";
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}

if (!empty($dateTo)) {
    $whereConditions[] = "l.created_at <= :date_to";
    $params[':date_to'] = $dateTo . ' 23:59:59';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Conta totale record
$stmt = $conn->prepare("SELECT COUNT(*) FROM logs l $whereClause");
$stmt->execute($params);
$totalRecords = $stmt->fetchColumn();
$totalPages = ceil($totalRecords / $perPage);

// Ottieni log con paginazione
$offset = ($page - 1) * $perPage;
$params[':limit'] = $perPage;
$params[':offset'] = $offset;

$stmt = $conn->prepare("
    SELECT l.*, u.username 
    FROM logs l 
    LEFT JOIN users u ON l.user_id = u.id 
    $whereClause 
    ORDER BY l.created_at DESC 
    LIMIT :limit OFFSET :offset
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Ottieni utenti per filtro
$stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.username 
    FROM users u 
    INNER JOIN logs l ON u.id = l.user_id 
    ORDER BY u.username
");
$stmt->execute();
$users = $stmt->fetchAll();

// Ottieni azioni per filtro
$stmt = $conn->prepare("SELECT DISTINCT action FROM logs ORDER BY action");
$stmt->execute();
$actions = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Statistiche
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_logs,
        COUNT(DISTINCT user_id) as unique_users,
        COUNT(DISTINCT DATE(created_at)) as active_days,
        MAX(created_at) as last_activity
    FROM logs
");
$stmt->execute();
$stats = $stmt->fetch();

// Attività per giorno (ultimi 7 giorni)
$stmt = $conn->prepare("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as count
    FROM logs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date DESC
");
$stmt->execute();
$dailyActivity = $stmt->fetchAll();
?>

<?php include 'header.php'; ?>

<div class="mb-6">
    <div class="flex justify-between items-center mb-4">
        <div>
            <h1 class="text-xl font-bold mb-2">Log Attività</h1>
            <p class="text-secondary">Monitora tutte le attività e operazioni del sistema</p>
        </div>
        <div class="flex gap-2">
            <button onclick="openModal('exportModal')" class="btn btn-secondary">
                Esporta Log
            </button>
            <?php if (hasRole('admin')): ?>
            <button onclick="openModal('cleanupModal')" class="btn btn-warning">
                Pulizia Log
            </button>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Statistiche -->
    <div class="stats-grid mb-6">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['total_logs'] ?? 0) ?></div>
            <div class="stat-label">Log Totali</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['unique_users'] ?? 0 ?></div>
            <div class="stat-label">Utenti Attivi</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['active_days'] ?? 0 ?></div>
            <div class="stat-label">Giorni di Attività</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">
                <?= $stats['last_activity'] ? formatDate($stats['last_activity']) : 'N/A' ?>
            </div>
            <div class="stat-label">Ultima Attività</div>
        </div>
    </div>
</div>

<!-- Attività Giornaliera -->
<?php if (!empty($dailyActivity)): ?>
<div class="card mb-6">
    <div class="card-header">
        <h2 class="card-title">Attività Ultimi 7 Giorni</h2>
    </div>
    <div class="card-content">
        <div class="grid grid-cols-7 gap-2">
            <?php foreach ($dailyActivity as $day): ?>
                <div class="text-center p-3 bg-gray-50 rounded">
                    <div class="text-sm text-secondary"><?= date('d/m', strtotime($day['date'])) ?></div>
                    <div class="font-bold text-primary-color"><?= $day['count'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filtri e Ricerca -->
<div class="card mb-6">
    <div class="card-content">
        <form method="GET" class="grid grid-cols-1 grid-cols-5 gap-4 items-end">
            <div class="form-group mb-0">
                <label class="form-label">Ricerca</label>
                <input type="text" name="search" class="form-input" 
                       placeholder="Azione o dettagli..." 
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            
            <div class="form-group mb-0">
                <label class="form-label">Utente</label>
                <select name="user" class="form-select">
                    <option value="">Tutti gli utenti</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $user == $u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group mb-0">
                <label class="form-label">Azione</label>
                <select name="action" class="form-select">
                    <option value="">Tutte le azioni</option>
                    <?php foreach ($actions as $act): ?>
                        <option value="<?= htmlspecialchars($act) ?>" <?= $action === $act ? 'selected' : '' ?>>
                            <?= htmlspecialchars($act) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group mb-0">
                <label class="form-label">Da</label>
                <input type="date" name="date_from" class="form-input" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            
            <div class="form-group mb-0">
                <label class="form-label">A</label>
                <input type="date" name="date_to" class="form-input" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary">Filtra</button>
                <a href="logs.php" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabella Log -->
<div class="card">
    <div class="card-header">
        <div class="flex justify-between items-center">
            <h2 class="card-title">Log Attività (<?= number_format($totalRecords) ?> totali)</h2>
            <div class="text-sm text-secondary">
                Pagina <?= $page ?> di <?= $totalPages ?>
            </div>
        </div>
    </div>
    <div class="card-content">
        <?php if (empty($logs)): ?>
            <p class="text-center text-secondary py-8">Nessun log trovato</p>
        <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Data/Ora</th>
                            <th>Utente</th>
                            <th>Azione</th>
                            <th>Dettagli</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="text-sm">
                                    <?= formatDate($log['created_at']) ?>
                                </td>
                                <td>
                                    <span class="font-semibold">
                                        <?= htmlspecialchars($log['username'] ?? 'Sistema') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php
                                        $actionLower = strtolower($log['action']);
                                        if (strpos($actionLower, 'login') !== false) echo 'success';
                                        elseif (strpos($actionLower, 'logout') !== false) echo 'info';
                                        elseif (strpos($actionLower, 'eliminat') !== false || strpos($actionLower, 'delete') !== false) echo 'error';
                                        elseif (strpos($actionLower, 'aggiunt') !== false || strpos($actionLower, 'creat') !== false) echo 'success';
                                        elseif (strpos($actionLower, 'aggiorn') !== false || strpos($actionLower, 'update') !== false) echo 'warning';
                                        else echo 'info';
                                    ?>">
                                        <?= htmlspecialchars($log['action']) ?>
                                    </span>
                                </td>
                                <td class="text-sm">
                                    <?php if ($log['details']): ?>
                                        <div class="max-w-xs truncate" title="<?= htmlspecialchars($log['details']) ?>">
                                            <?= htmlspecialchars($log['details']) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-secondary">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-sm text-secondary">
                                    <?= htmlspecialchars($log['ip_address'] ?? '-') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginazione -->
            <?php if ($totalPages > 1): ?>
                <div class="flex justify-center items-center gap-2 mt-6">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
                           class="btn btn-secondary btn-sm">Precedente</a>
                    <?php endif; ?>
                    
                    <span class="text-sm text-secondary">
                        Pagina <?= $page ?> di <?= $totalPages ?>
                    </span>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
                           class="btn btn-secondary btn-sm">Successiva</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Esporta Log -->
<div id="exportModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Esporta Log</h3>
        </div>
        <form method="POST">
            <div class="modal-content">
                <input type="hidden" name="action" value="export_logs">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div class="form-group">
                    <label class="form-label">Data Inizio *</label>
                    <input type="date" name="export_date_from" class="form-input" required 
                           value="<?= date('Y-m-d', strtotime('-30 days')) ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Data Fine *</label>
                    <input type="date" name="export_date_to" class="form-input" required 
                           value="<?= date('Y-m-d') ?>">
                </div>
                
                <div class="alert alert-info">
                    I log verranno esportati in formato CSV con le colonne: Data/Ora, Utente, Azione, Dettagli, IP.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('exportModal')" class="btn btn-secondary">Annulla</button>
                <button type="submit" class="btn btn-primary">Esporta CSV</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Pulizia Log -->
<?php if (hasRole('admin')): ?>
<div id="cleanupModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Pulizia Log</h3>
        </div>
        <form method="POST" onsubmit="return confirm('Sei sicuro di voler eliminare i log più vecchi? Questa operazione non può essere annullata.')">
            <div class="modal-content">
                <input type="hidden" name="action" value="clear_old_logs">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div class="form-group">
                    <label class="form-label">Mantieni log degli ultimi (giorni) *</label>
                    <input type="number" name="days_to_keep" class="form-input" required 
                           value="90" min="1" max="365">
                </div>
                
                <div class="alert alert-warning">
                    <strong>Attenzione:</strong> Questa operazione eliminerà definitivamente tutti i log più vecchi del periodo specificato. L'operazione non può essere annullata.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('cleanupModal')" class="btn btn-secondary">Annulla</button>
                <button type="submit" class="btn btn-warning">Elimina Log Vecchi</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>
