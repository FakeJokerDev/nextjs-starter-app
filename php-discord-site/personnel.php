<?php
// personnel.php - Gestione Personale

require_once 'config.php';
require_once 'functions.php';

ensureLoggedIn();

// Verifica permessi
if (!checkModulePermission('personnel')) {
    $_SESSION['error_message'] = 'Non hai i permessi per accedere a questa sezione.';
    header("Location: index.php");
    exit;
}

$pageTitle = 'Gestione Personale';
$conn = db_connect();

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Token di sicurezza non valido.';
        header("Location: personnel.php");
        exit;
    }
    
    switch ($action) {
        case 'add_employee':
            $employeeCode = sanitizeInput($_POST['employee_code']);
            $firstName = sanitizeInput($_POST['first_name']);
            $lastName = sanitizeInput($_POST['last_name']);
            $email = sanitizeInput($_POST['email']);
            $phone = sanitizeInput($_POST['phone']);
            $position = sanitizeInput($_POST['position']);
            $department = sanitizeInput($_POST['department']);
            $hireDate = $_POST['hire_date'];
            $salary = (float)$_POST['salary'];
            $notes = sanitizeInput($_POST['notes']);
            $userId = $_POST['user_id'] ?: null;
            
            if (empty($employeeCode) || empty($firstName) || empty($lastName) || empty($email)) {
                $_SESSION['error_message'] = 'Codice dipendente, nome, cognome e email sono obbligatori.';
                break;
            }
            
            try {
                $stmt = $conn->prepare("
                    INSERT INTO personnel (user_id, employee_code, first_name, last_name, email, phone, position, department, hire_date, salary, notes, created_by) 
                    VALUES (:user_id, :employee_code, :first_name, :last_name, :email, :phone, :position, :department, :hire_date, :salary, :notes, :created_by)
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':employee_code' => $employeeCode,
                    ':first_name' => $firstName,
                    ':last_name' => $lastName,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':position' => $position,
                    ':department' => $department,
                    ':hire_date' => $hireDate,
                    ':salary' => $salary,
                    ':notes' => $notes,
                    ':created_by' => $_SESSION['user_id']
                ]);
                
                logActivity($_SESSION['user_id'], 'Dipendente Aggiunto', "Aggiunto dipendente: $firstName $lastName ($employeeCode)");
                $_SESSION['success_message'] = 'Dipendente aggiunto con successo.';
                
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $_SESSION['error_message'] = 'Codice dipendente o email già esistenti.';
                } else {
                    $_SESSION['error_message'] = 'Errore durante l\'aggiunta del dipendente.';
                }
            }
            break;
            
        case 'update_employee':
            $employeeId = (int)$_POST['employee_id'];
            $firstName = sanitizeInput($_POST['first_name']);
            $lastName = sanitizeInput($_POST['last_name']);
            $email = sanitizeInput($_POST['email']);
            $phone = sanitizeInput($_POST['phone']);
            $position = sanitizeInput($_POST['position']);
            $department = sanitizeInput($_POST['department']);
            $salary = (float)$_POST['salary'];
            $notes = sanitizeInput($_POST['notes']);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            try {
                $stmt = $conn->prepare("
                    UPDATE personnel 
                    SET first_name = :first_name, last_name = :last_name, email = :email, phone = :phone, 
                        position = :position, department = :department, salary = :salary, notes = :notes, is_active = :is_active
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':first_name' => $firstName,
                    ':last_name' => $lastName,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':position' => $position,
                    ':department' => $department,
                    ':salary' => $salary,
                    ':notes' => $notes,
                    ':is_active' => $isActive,
                    ':id' => $employeeId
                ]);
                
                logActivity($_SESSION['user_id'], 'Dipendente Aggiornato', "Aggiornato dipendente: $firstName $lastName");
                $_SESSION['success_message'] = 'Dipendente aggiornato con successo.';
                
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Errore durante l\'aggiornamento del dipendente.';
            }
            break;
            
        case 'delete_employee':
            $employeeId = (int)$_POST['employee_id'];
            
            try {
                $stmt = $conn->prepare("SELECT first_name, last_name, employee_code FROM personnel WHERE id = :id");
                $stmt->execute([':id' => $employeeId]);
                $employee = $stmt->fetch();
                
                if ($employee) {
                    $stmt = $conn->prepare("DELETE FROM personnel WHERE id = :id");
                    $stmt->execute([':id' => $employeeId]);
                    
                    logActivity($_SESSION['user_id'], 'Dipendente Eliminato', 
                        "Eliminato dipendente: {$employee['first_name']} {$employee['last_name']} ({$employee['employee_code']})");
                    $_SESSION['success_message'] = 'Dipendente eliminato con successo.';
                }
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Errore durante l\'eliminazione del dipendente.';
            }
            break;
    }
    
    header("Location: personnel.php");
    exit;
}

// Parametri di ricerca e filtri
$search = $_GET['search'] ?? '';
$department = $_GET['department'] ?? '';
$position = $_GET['position'] ?? '';
$activeOnly = isset($_GET['active_only']);

// Costruisci query
$whereConditions = [];
$params = [];

if (!empty($search)) {
    $whereConditions[] = "(p.first_name LIKE :search OR p.last_name LIKE :search OR p.email LIKE :search OR p.employee_code LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($department)) {
    $whereConditions[] = "p.department = :department";
    $params[':department'] = $department;
}

if (!empty($position)) {
    $whereConditions[] = "p.position = :position";
    $params[':position'] = $position;
}

if ($activeOnly) {
    $whereConditions[] = "p.is_active = 1";
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Ottieni personale
$stmt = $conn->prepare("
    SELECT p.*, 
           u1.username as created_by_name,
           u2.username as user_account
    FROM personnel p 
    LEFT JOIN users u1 ON p.created_by = u1.id 
    LEFT JOIN users u2 ON p.user_id = u2.id 
    $whereClause 
    ORDER BY p.last_name ASC, p.first_name ASC
");
$stmt->execute($params);
$employees = $stmt->fetchAll();

// Ottieni dipartimenti e posizioni per i filtri
$stmt = $conn->prepare("SELECT DISTINCT department FROM personnel WHERE department IS NOT NULL AND department != '' ORDER BY department");
$stmt->execute();
$departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $conn->prepare("SELECT DISTINCT position FROM personnel WHERE position IS NOT NULL AND position != '' ORDER BY position");
$stmt->execute();
$positions = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Ottieni utenti per collegamento account
$stmt = $conn->prepare("
    SELECT u.id, u.username 
    FROM users u 
    LEFT JOIN personnel p ON u.id = p.user_id 
    WHERE p.user_id IS NULL 
    ORDER BY u.username
");
$stmt->execute();
$availableUsers = $stmt->fetchAll();

// Statistiche
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
        AVG(salary) as avg_salary
    FROM personnel
");
$stmt->execute();
$stats = $stmt->fetch();
?>

<?php include 'header.php'; ?>

<div class="mb-6">
    <div class="flex justify-between items-center mb-4">
        <div>
            <h1 class="text-xl font-bold mb-2">Gestione Personale</h1>
            <p class="text-secondary">Gestisci dipendenti, ruoli e informazioni personali</p>
        </div>
        <button onclick="openModal('addEmployeeModal')" class="btn btn-primary">
            Aggiungi Dipendente
        </button>
    </div>
    
    <!-- Statistiche -->
    <div class="stats-grid mb-6">
        <div class="stat-card">
            <div class="stat-value"><?= $stats['total'] ?? 0 ?></div>
            <div class="stat-label">Dipendenti Totali</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['active'] ?? 0 ?></div>
            <div class="stat-label">Attivi</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $stats['inactive'] ?? 0 ?></div>
            <div class="stat-label">Inattivi</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">€<?= number_format($stats['avg_salary'] ?? 0, 0) ?></div>
            <div class="stat-label">Stipendio Medio</div>
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
                       placeholder="Nome, cognome, email, codice..." 
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            
            <div class="form-group mb-0">
                <label class="form-label">Dipartimento</label>
                <select name="department" class="form-select">
                    <option value="">Tutti i dipartimenti</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>" <?= $department === $dept ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group mb-0">
                <label class="form-label">Posizione</label>
                <select name="position" class="form-select">
                    <option value="">Tutte le posizioni</option>
                    <?php foreach ($positions as $pos): ?>
                        <option value="<?= htmlspecialchars($pos) ?>" <?= $position === $pos ? 'selected' : '' ?>>
                            <?= htmlspecialchars($pos) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex gap-2 items-end">
                <div class="flex items-center gap-2 mb-3">
                    <input type="checkbox" name="active_only" id="active_only" <?= $activeOnly ? 'checked' : '' ?>>
                    <label for="active_only" class="text-sm">Solo attivi</label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary">Filtra</button>
                    <a href="personnel.php" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Tabella Personale -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">Dipendenti (<?= count($employees) ?>)</h2>
    </div>
    <div class="card-content">
        <?php if (empty($employees)): ?>
            <p class="text-center text-secondary py-8">Nessun dipendente trovato</p>
        <?php else: ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Codice</th>
                            <th>Nome Completo</th>
                            <th>Email</th>
                            <th>Posizione</th>
                            <th>Dipartimento</th>
                            <th>Stato</th>
                            <th>Account</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $employee): ?>
                            <tr class="<?= !$employee['is_active'] ? 'opacity-60' : '' ?>">
                                <td class="font-semibold"><?= htmlspecialchars($employee['employee_code']) ?></td>
                                <td>
                                    <div class="font-semibold">
                                        <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>
                                    </div>
                                    <?php if ($employee['phone']): ?>
                                        <div class="text-sm text-secondary"><?= htmlspecialchars($employee['phone']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($employee['email']) ?></td>
                                <td><?= htmlspecialchars($employee['position'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($employee['department'] ?? '-') ?></td>
                                <td>
                                    <span class="badge badge-<?= $employee['is_active'] ? 'success' : 'error' ?>">
                                        <?= $employee['is_active'] ? 'Attivo' : 'Inattivo' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($employee['user_account']): ?>
                                        <span class="badge badge-info"><?= htmlspecialchars($employee['user_account']) ?></span>
                                    <?php else: ?>
                                        <span class="text-secondary">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="flex gap-2">
                                        <button onclick="openEditEmployeeModal(<?= htmlspecialchars(json_encode($employee)) ?>)" 
                                                class="btn btn-secondary btn-sm">
                                            Modifica
                                        </button>
                                        <button onclick="viewEmployeeDetails(<?= $employee['id'] ?>)" 
                                                class="btn btn-info btn-sm">
                                            Dettagli
                                        </button>
                                        <button onclick="deleteEmployee(<?= $employee['id'] ?>, '<?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>')" 
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

<!-- Modal Aggiungi Dipendente -->
<div id="addEmployeeModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Aggiungi Nuovo Dipendente</h3>
        </div>
        <form method="POST">
            <div class="modal-content">
                <input type="hidden" name="action" value="add_employee">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div class="grid grid-cols-1 grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label">Codice Dipendente *</label>
                        <input type="text" name="employee_code" class="form-input" required 
                               value="EMP-<?= date('Y') ?>-<?= str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Account Utente</label>
                        <select name="user_id" class="form-select">
                            <option value="">Nessun account collegato</option>
                            <?php foreach ($availableUsers as $user): ?>
                                <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Nome *</label>
                        <input type="text" name="first_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Cognome *</label>
                        <input type="text" name="last_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Telefono</label>
                        <input type="tel" name="phone" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Posizione</label>
                        <input type="text" name="position" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Dipartimento</label>
                        <input type="text" name="department" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Data Assunzione</label>
                        <input type="date" name="hire_date" class="form-input" value="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Stipendio (€)</label>
                        <input type="number" name="salary" class="form-input" step="0.01" min="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Note</label>
                    <textarea name="notes" class="form-textarea" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('addEmployeeModal')" class="btn btn-secondary">Annulla</button>
                <button type="submit" class="btn btn-primary">Aggiungi Dipendente</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Modifica Dipendente -->
<div id="editEmployeeModal" class="modal-overlay" style="display: none;">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">Modifica Dipendente</h3>
        </div>
        <form method="POST">
            <div class="modal-content">
                <input type="hidden" name="action" value="update_employee">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="employee_id" id="edit_employee_id">
                
                <div class="grid grid-cols-1 grid-cols-2 gap-4">
                    <div class="form-group">
                        <label class="form-label">Nome *</label>
                        <input type="text" name="first_name" id="edit_first_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Cognome *</label>
                        <input type="text" name="last_name" id="edit_last_name" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input type="email" name="email" id="edit_email" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Telefono</label>
                        <input type="tel" name="phone" id="edit_phone" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Posizione</label>
                        <input type="text" name="position" id="edit_position" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Dipartimento</label>
                        <input type="text" name="department" id="edit_department" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Stipendio (€)</label>
                        <input type="number" name="salary" id="edit_salary" class="form-input" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <div class="flex items-center gap-2">
                            <input type="checkbox" name="is_active" id="edit_is_active">
                            <label for="edit_is_active" class="form-label mb-0">Dipendente Attivo</label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Note</label>
                    <textarea name="notes" id="edit_notes" class="form-textarea" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('editEmployeeModal')" class="btn btn-secondary">Annulla</button>
                <button type="submit" class="btn btn-primary">Salva Modifiche</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditEmployeeModal(employee) {
    document.getElementById('edit_employee_id').value = employee.id;
    document.getElementById('edit_first_name').value = employee.first_name;
    document.getElementById('edit_last_name').value = employee.last_name;
    document.getElementById('edit_email').value = employee.email;
    document.getElementById('edit_phone').value = employee.phone || '';
    document.getElementById('edit_position').value = employee.position || '';
    document.getElementById('edit_department').value = employee.department || '';
    document.getElementById('edit_salary').value = employee.salary || '';
    document.getElementById('edit_notes').value = employee.notes || '';
    document.getElementById('edit_is_active').checked = employee.is_active == 1;
    openModal('editEmployeeModal');
}

function viewEmployeeDetails(employeeId) {
    // Implementa visualizzazione dettagli dipendente
    alert('Funzionalità dettagli dipendente da implementare');
}

function deleteEmployee(employeeId, employeeName) {
    if (confirmDelete(`Sei sicuro di voler eliminare il dipendente "${employeeName}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_employee">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="employee_id" value="${employeeId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include 'footer.php'; ?>
