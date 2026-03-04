<?php
/**
 * Database Configuration
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'khat_khatibangla');
define('DB_USER', 'khat_khatiadmin');
define('DB_PASS', 'Gfp2a%G2Gt2Jfi#D');
define('DB_CHARSET', 'utf8mb4');

// Site URL Configuration
define('SITE_URL', 'https://khatibangla.com');
define('ADMIN_URL', SITE_URL . '/admin');
define('UPLOAD_URL', SITE_URL . '/uploads');
define('ASSETS_URL', SITE_URL . '/assets');

// File Paths
define('ROOT_PATH', dirname(__DIR__) . '/');
define('UPLOAD_PATH', ROOT_PATH . 'uploads/');
define('INCLUDES_PATH', ROOT_PATH . 'includes/');

// Session
define('SESSION_LIFETIME', 86400);
define('ADMIN_SESSION_LIFETIME', 28800);

// Security
define('ENCRYPTION_KEY', 'your-secret-key-change-this-in-production');
define('CSRF_TOKEN_NAME', '_csrf_token');

// Pagination
define('ITEMS_PER_PAGE', 20);
define('ADMIN_ITEMS_PER_PAGE', 25);

// PDO Connection
class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function insert($table, $data) {
        $columns = implode(',', array_keys($data));
        $placeholders = implode(',', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = []) {
        $set = implode(',', array_map(fn($k) => "{$k} = ?", array_keys($data)));
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        $this->query($sql, array_merge(array_values($data), $whereParams));
    }

    public function delete($table, $where, $params = []) {
        $this->query("DELETE FROM {$table} WHERE {$where}", $params);
    }

    public function count($table, $where = '1=1', $params = []) {
        return $this->fetch("SELECT COUNT(*) as cnt FROM {$table} WHERE {$where}", $params)['cnt'];
    }
}
