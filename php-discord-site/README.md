# Sistema di Gestione Aziendale con Discord OAuth

Un sistema completo di gestione aziendale che utilizza Discord OAuth per l'autenticazione e la gestione dei ruoli basata sui ruoli del server Discord.

## Funzionalità Principali

- **Autenticazione Discord OAuth**: Login sicuro tramite Discord
- **Gestione Ruoli**: Mappatura automatica dei ruoli Discord ai ruoli del sistema
- **Gestione Magazzino**: Inventario, scorte, movimenti prodotti
- **Gestione Ordini**: Tracciamento ordini clienti e stati
- **Gestione Personale**: Amministrazione dipendenti e informazioni
- **Log Attività**: Monitoraggio completo di tutte le operazioni
- **Dashboard**: Panoramica generale con statistiche e comunicazioni

## Requisiti di Sistema

- PHP 7.4 o superiore
- MySQL 5.7 o superiore
- Estensioni PHP: PDO, cURL, JSON
- Server web (Apache/Nginx)

## Installazione

### 1. Configurazione Database

1. Importa il file `database.sql` nel tuo database MySQL:
```sql
mysql -u username -p database_name < database.sql
```

2. Aggiorna le credenziali del database in `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'discord_management_db');
```

### 2. Configurazione Discord

1. Vai su [Discord Developer Portal](https://discord.com/developers/applications)
2. Crea una nuova applicazione
3. Nella sezione OAuth2, aggiungi il redirect URI:
   ```
   http://yourdomain.com/callback.php
   ```
4. Copia Client ID e Client Secret in `config.php`:
```php
define('DISCORD_CLIENT_ID', 'your_discord_client_id');
define('DISCORD_CLIENT_SECRET', 'your_discord_client_secret');
define('DISCORD_REDIRECT_URI', 'http://yourdomain.com/callback.php');
```

### 3. Configurazione Bot Discord

1. Nella stessa applicazione Discord, vai alla sezione "Bot"
2. Crea un bot e copia il token in `config.php`:
```php
define('DISCORD_BOT_TOKEN', 'your_discord_bot_token');
```
3. Invita il bot nel tuo server con i permessi di lettura membri

### 4. Configurazione Ruoli

1. Ottieni gli ID dei ruoli dal tuo server Discord
2. Aggiorna la mappatura in `config.php`:
```php
$ROLE_MAPPING = [
    'discord_role_id_admin'        => 'admin',
    'discord_role_id_manager'      => 'manager',
    'discord_role_id_warehouse'    => 'warehouse_manager',
    'discord_role_id_order'        => 'order_manager',
    'discord_role_id_personnel'    => 'personnel_manager',
];
```

### 5. Configurazione Server Web

Assicurati che il server web punti alla directory del progetto e che i file PHP siano eseguibili.

## Struttura dei Ruoli

- **Admin**: Accesso completo a tutte le funzionalità
- **Manager**: Accesso a tutte le sezioni operative
- **Warehouse Manager**: Gestione magazzino e inventario
- **Order Manager**: Gestione ordini e clienti
- **Personnel Manager**: Gestione personale e dipendenti
- **User**: Accesso base alla dashboard

## Sicurezza

- Tutti i form utilizzano token CSRF per prevenire attacchi
- Input sanitizzati per prevenire XSS
- Query preparate per prevenire SQL injection
- Controllo permessi su ogni pagina
- Log completo di tutte le attività

## Utilizzo

1. Accedi tramite Discord OAuth
2. Il sistema verificherà automaticamente i tuoi ruoli nel server Discord
3. Sarai reindirizzato alla dashboard con accesso alle sezioni appropriate
4. Tutte le azioni vengono registrate nei log di sistema

## Personalizzazione

### Aggiungere Nuovi Ruoli
1. Aggiungi il ruolo in `$ROLE_MAPPING` in `config.php`
2. Aggiorna la funzione `checkModulePermission()` in `functions.php`
3. Modifica i controlli di accesso nelle pagine necessarie

### Modificare Stili
Tutti gli stili sono centralizzati in `public/css/style.css` utilizzando CSS moderno con variabili personalizzate.

### Aggiungere Nuove Funzionalità
1. Crea le tabelle necessarie nel database
2. Aggiungi le nuove pagine PHP
3. Aggiorna il menu di navigazione in `header.php`
4. Implementa i controlli di accesso appropriati

## Troubleshooting

### Errori Comuni

1. **Errore di connessione database**: Verifica credenziali in `config.php`
2. **OAuth non funziona**: Controlla Client ID, Secret e Redirect URI
3. **Bot non legge ruoli**: Verifica che il bot sia nel server con permessi adeguati
4. **Errori di permessi**: Controlla la mappatura ruoli e gli ID Discord

### Log degli Errori
Gli errori vengono registrati nei log di PHP. Controlla:
- Log del server web
- Log di PHP
- Tabella `logs` nel database per errori applicativi

## Manutenzione

### Backup
- Esegui backup regolari del database
- Mantieni copie di sicurezza dei file di configurazione

### Pulizia Log
Utilizza la funzione di pulizia log nella sezione amministrativa per mantenere le prestazioni del sistema.

### Aggiornamenti
- Testa sempre gli aggiornamenti in ambiente di sviluppo
- Mantieni aggiornate le dipendenze PHP e del database

## Supporto

Per problemi o domande:
1. Controlla i log di sistema
2. Verifica la configurazione Discord
3. Consulta la documentazione delle API Discord

## Licenza

Questo progetto è rilasciato sotto licenza MIT. Vedi il file LICENSE per i dettagli.
