<?php
/**
 * Database connection handler
 * Provides a singleton pattern for database connections
 */

require_once __DIR__ . '/config-loader.php';

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ]
            );
        } catch (PDOException $e) {
            // Log error in production, display in development
            if (ini_get('display_errors')) {
                die("Database connection failed: " . $e->getMessage());
            } else {
                error_log("Database connection failed: " . $e->getMessage());
                die("Database connection error. Please try again later.");
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Prevent cloning of the instance
    private function __clone() {}
    
    // Prevent unserializing of the instance
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// Helper function to get database connection
function getDB() {
    return Database::getInstance()->getConnection();
}

// Helper function to safely get user by Discord ID
function getUserByDiscordId($discord_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ?");
    $stmt->execute([$discord_id]);
    return $stmt->fetch();
}

// Helper function to create or update user
function createOrUpdateUser($discord_id, $username, $avatar) {
    $db = getDB();
    
    // Check if user exists
    $user = getUserByDiscordId($discord_id);
    
    if ($user) {
        // Update existing user
        $stmt = $db->prepare("UPDATE users SET username = ?, avatar = ?, updated_at = NOW() WHERE discord_id = ?");
        $stmt->execute([$username, $avatar, $discord_id]);
        return $user['id'];
    } else {
        // Create new user with pending approval status
        $stmt = $db->prepare("INSERT INTO users (discord_id, username, avatar, approval_status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$discord_id, $username, $avatar]);
        $user_id = $db->lastInsertId();
        
        // Create approval request record
        $stmt = $db->prepare("INSERT INTO approval_requests (user_id, discord_username, first_login_ip) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $username, $_SERVER['REMOTE_ADDR'] ?? null]);
        
        // Send webhook notification for new user
        require_once __DIR__ . '/webhooks.php';
        $new_user = [
            'id' => $user_id,
            'username' => $username,
            'discord_id' => $discord_id,
            'created_at' => date('Y-m-d H:i:s')
        ];
        notifyUserAwaitingApproval($new_user);
        
        return $user_id;
    }
}

// Helper function to get all resources
function getAllResources() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM resources ORDER BY category, name");
    return $stmt->fetchAll();
}

// Helper function to get resource totals
function getResourceTotals() {
    $db = getDB();
    $sql = "
        SELECT 
            r.id,
            r.name,
            r.category,
            COALESCE(SUM(c.quantity), 0) as total_contributed,
            COALESCE(SUM(d.quantity), 0) as total_distributed,
            COALESCE(SUM(c.quantity), 0) - COALESCE(SUM(d.quantity), 0) as current_stock
        FROM resources r
        LEFT JOIN contributions c ON r.id = c.resource_id
        LEFT JOIN distributions d ON r.id = d.resource_id
        GROUP BY r.id, r.name, r.category
        ORDER BY r.category, r.name
    ";
    $stmt = $db->query($sql);
    return $stmt->fetchAll();
}

// Helper function to get recent contributions
function getRecentContributions($limit = 10) {
    $db = getDB();
    $sql = "
        SELECT 
            c.*,
            COALESCE(u.in_game_name, u.username) as username,
            u.avatar,
            u.discord_id,
            r.name as resource_name,
            r.category as resource_category
        FROM contributions c
        JOIN users u ON c.user_id = u.id
        JOIN resources r ON c.resource_id = r.id
        ORDER BY c.date_collected DESC
        LIMIT ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// Helper function to add contribution
function addContribution($user_id, $resource_id, $quantity, $notes = null, $apply_tax = true) {
    $db = getDB();
    
    try {
        $db->beginTransaction();
        
        // Get tax settings if applying tax
        $tax_amount = 0;
        $tax_rate = 0;
        
        if ($apply_tax) {
            // Check if tax is enabled
            $stmt = $db->prepare("SELECT setting_value FROM guild_settings WHERE setting_key = 'guild_tax_enabled'");
            $stmt->execute();
            $tax_enabled = $stmt->fetchColumn() === 'true';
            
            if ($tax_enabled) {
                // Get user's tax rate preference
                $stmt = $db->prepare("SELECT personal_tax_rate, tax_opt_in FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user_tax = $stmt->fetch();
                
                if ($user_tax && $user_tax['tax_opt_in']) {
                    // Use user's personal tax rate or default guild rate
                    $tax_rate = $user_tax['personal_tax_rate'];
                    
                    if ($tax_rate <= 0) {
                        // Get default guild tax rate
                        $stmt = $db->prepare("SELECT setting_value FROM guild_settings WHERE setting_key = 'guild_tax_rate'");
                        $stmt->execute();
                        $tax_rate = floatval($stmt->fetchColumn());
                    }
                    
                    $tax_amount = $quantity * $tax_rate;
                }
            }
        }
        
        // Insert the contribution with tax information
        $stmt = $db->prepare("
            INSERT INTO contributions (user_id, resource_id, quantity, tax_amount, tax_rate, notes) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $resource_id, $quantity, $tax_amount, $tax_rate, $notes]);
        $contribution_id = $db->lastInsertId();
        
        // If tax was applied, add it to guild treasury
        if ($tax_amount > 0) {
            $stmt = $db->prepare("
                INSERT INTO guild_treasury (resource_id, quantity, source_type, source_user_id, source_contribution_id, notes)
                VALUES (?, ?, 'tax', ?, ?, ?)
            ");
            $stmt->execute([
                $resource_id, 
                $tax_amount, 
                $user_id, 
                $contribution_id,
                "Guild tax ({$tax_rate}% of " . number_format($quantity, 2) . ")"
            ]);
        }
        
        // Check if this is a large contribution (>1000 units)
        if ($quantity >= 1000) {
            // Get user and resource details for webhook
    $stmt = $db->prepare("SELECT COALESCE(in_game_name, username) as username FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            $stmt = $db->prepare("SELECT name FROM resources WHERE id = ?");
            $stmt->execute([$resource_id]);
            $resource = $stmt->fetch();
            
            if ($user && $resource) {
                require_once __DIR__ . '/webhooks.php';
                notifyLargeContribution($user, $resource, $quantity);
            }
        }
        
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// Helper function to get user contributions
function getUserContributions($user_id) {
    $db = getDB();
    $sql = "
        SELECT 
            c.*,
            r.name as resource_name,
            r.category as resource_category
        FROM contributions c
        JOIN resources r ON c.resource_id = r.id
        WHERE c.user_id = ?
        ORDER BY c.date_collected DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

// Helper function to get user contribution totals
function getUserContributionTotals($user_id) {
    $db = getDB();
    $sql = "
        SELECT 
            r.name,
            r.category,
            SUM(c.quantity) as total_contributed
        FROM contributions c
        JOIN resources r ON c.resource_id = r.id
        WHERE c.user_id = ?
        GROUP BY r.id, r.name, r.category
        ORDER BY r.category, r.name
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

// Farming Run Functions

// Create a new farming run
function createFarmingRun($name, $type, $location, $created_by, $notes = null) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO farming_runs (name, run_type, location, started_at, created_by, notes)
        VALUES (?, ?, ?, NOW(), ?, ?)
    ");
    $stmt->execute([$name, $type, $location, $created_by, $notes]);
    $run_id = $db->lastInsertId();
    
    // Get the created run and creator details for webhook
    $stmt = $db->prepare("
        SELECT fr.*, u.username as creator_name 
        FROM farming_runs fr
        JOIN users u ON fr.created_by = u.id
        WHERE fr.id = ?
    ");
    $stmt->execute([$run_id]);
    $run = $stmt->fetch();
    
    if ($run) {
        // Send webhook notification
        require_once __DIR__ . '/webhooks.php';
        $creator = ['username' => $run['creator_name']];
        notifyFarmingRunStarted($run, $creator);
    }
    
    return $run_id;
}

// Add participant to run
function addRunParticipant($run_id, $user_id, $role = 'member') {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO run_participants (run_id, user_id, role)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE role = VALUES(role)
    ");
    return $stmt->execute([$run_id, $user_id, $role]);
}

// Get active farming runs
function getActiveFarmingRuns() {
    $db = getDB();
    $sql = "
        SELECT 
            fr.*,
            COALESCE(u.in_game_name, u.username) as creator_name,
            u.avatar as creator_avatar,
            u.discord_id as creator_discord_id,
            COUNT(DISTINCT rp.user_id) as participant_count
        FROM farming_runs fr
        JOIN users u ON fr.created_by = u.id
        LEFT JOIN run_participants rp ON fr.id = rp.run_id
        WHERE fr.status = 'active'
        GROUP BY fr.id
        ORDER BY fr.started_at DESC
    ";
    return $db->query($sql)->fetchAll();
}

// Get farming run details
function getFarmingRun($run_id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            fr.*,
            u.username as creator_name,
            u.avatar as creator_avatar
        FROM farming_runs fr
        JOIN users u ON fr.created_by = u.id
        WHERE fr.id = ?
    ");
    $stmt->execute([$run_id]);
    return $stmt->fetch();
}

// Get run participants
function getRunParticipants($run_id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            rp.*,
            u.username,
            u.avatar,
            u.discord_id
        FROM run_participants rp
        JOIN users u ON rp.user_id = u.id
        WHERE rp.run_id = ?
        ORDER BY rp.role DESC, rp.joined_at
    ");
    $stmt->execute([$run_id]);
    return $stmt->fetchAll();
}

// Add collection to run
function addRunCollection($run_id, $resource_id, $quantity, $collected_by = null, $notes = null) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO run_collections (run_id, resource_id, quantity, collected_by, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    return $stmt->execute([$run_id, $resource_id, $quantity, $collected_by, $notes]);
}

// Get run collections
function getRunCollections($run_id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            rc.*,
            r.name as resource_name,
            r.category as resource_category,
            r.can_be_refined,
            u.username as collector_name
        FROM run_collections rc
        JOIN resources r ON rc.resource_id = r.id
        LEFT JOIN users u ON rc.collected_by = u.id
        WHERE rc.run_id = ?
        ORDER BY rc.created_at DESC
    ");
    $stmt->execute([$run_id]);
    return $stmt->fetchAll();
}

// Add refined output
function addRefinedOutput($run_id, $input_resource_id, $output_resource_id, $input_qty, $output_qty, $refined_by = null) {
    $db = getDB();
    $conversion_rate = $input_qty > 0 ? $output_qty / $input_qty : 0;
    $stmt = $db->prepare("
        INSERT INTO run_refined_outputs 
        (run_id, input_resource_id, output_resource_id, input_quantity, output_quantity, conversion_rate, refined_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([$run_id, $input_resource_id, $output_resource_id, $input_qty, $output_qty, $conversion_rate, $refined_by]);
}

// Get resources that can be refined
function getRefinableResources() {
    $db = getDB();
    $sql = "
        SELECT 
            r1.*,
            r2.name as refined_into_name,
            r2.category as refined_into_category
        FROM resources r1
        LEFT JOIN resources r2 ON r1.refined_into_id = r2.id
        WHERE r1.can_be_refined = TRUE
        ORDER BY r1.category, r1.name
    ";
    return $db->query($sql)->fetchAll();
}

// Complete a farming run
function completeFarmingRun($run_id) {
    $db = getDB();
    
    try {
        $db->beginTransaction();
        
        // Update run status
        $stmt = $db->prepare("
            UPDATE farming_runs 
            SET status = 'completed', ended_at = NOW()
            WHERE id = ? AND status = 'active'
        ");
        $stmt->execute([$run_id]);
        
        // Calculate and store metrics
        calculateRunMetrics($run_id);
        
        // Get run details and stats for webhook notification
        $stmt = $db->prepare("SELECT * FROM farming_runs WHERE id = ?");
        $stmt->execute([$run_id]);
        $run = $stmt->fetch();
        
        if ($run) {
            // Get run stats
            $stmt = $db->prepare("
                SELECT 
                    COUNT(DISTINCT rp.user_id) as participant_count,
                    COUNT(DISTINCT rc.id) as collection_count,
                    COALESCE(SUM(rc.quantity), 0) as total_resources,
                    COUNT(DISTINCT rc.resource_id) as unique_resources,
                    TIMESTAMPDIFF(MINUTE, fr.started_at, fr.ended_at) as duration
                FROM farming_runs fr
                LEFT JOIN run_participants rp ON fr.id = rp.run_id
                LEFT JOIN run_collections rc ON fr.id = rc.run_id
                WHERE fr.id = ?
                GROUP BY fr.id
            ");
            $stmt->execute([$run_id]);
            $stats = $stmt->fetch();
            
            if ($stats) {
                $stats['per_participant'] = $stats['participant_count'] > 0 ? 
                    $stats['total_resources'] / $stats['participant_count'] : 0;
                $stats['per_minute'] = $stats['duration'] > 0 ? 
                    $stats['total_resources'] / $stats['duration'] : 0;
                
                // Get top contributors
                $stmt = $db->prepare("
                    SELECT u.username, SUM(rc.quantity) as total
                    FROM run_collections rc
                    JOIN users u ON rc.collected_by = u.id
                    WHERE rc.run_id = ?
                    GROUP BY rc.collected_by
                    ORDER BY total DESC
                    LIMIT 3
                ");
                $stmt->execute([$run_id]);
                $stats['top_contributors'] = $stmt->fetchAll();
                
                // Send webhook notification
                require_once __DIR__ . '/webhooks.php';
                notifyFarmingRunCompleted($run, $stats);
            }
        }
        
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// Get all guild members (users who have logged in or manual users)
function getAllGuildMembers() {
    $db = getDB();
    $sql = "SELECT id, discord_id, username, in_game_name, avatar, is_manual 
            FROM users 
            WHERE merged_into_user_id IS NULL 
            ORDER BY username";
    return $db->query($sql)->fetchAll();
}

// Update a user's in-game name
function updateUserInGameName($user_id, $in_game_name) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET in_game_name = ? WHERE id = ?");
    return $stmt->execute([$in_game_name ?: null, $user_id]);
}

/**
 * Compute Spice → Melange distribution across one or more runs
 * Algorithms:
 *  - equal_per_run: each run's spice total is split equally among its participants
 *  - weighted_across_runs: use per-user collected spice quantities across selected runs
 * Options:
 *  - discounted (bool): if true, 7,500 spice → 200 melange; otherwise 10,000 → 200
 * Returns: [
 *  'total_input' => int,
 *  'total_output' => int,
 *  'per_user' => [ user_id => ['username' => string, 'input' => int, 'share' => float, 'output' => int] ]
 * ]
 */
function computeSpiceToMelangeDistribution(array $run_ids, string $algorithm = 'weighted_across_runs', bool $discounted = false) {
    if (empty($run_ids)) {
        return ['total_input' => 0, 'total_output' => 0, 'per_user' => []];
    }
    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($run_ids), '?'));

    // Identify spice-like resource ids (avoid Spice Coffee)
    $spiceIds = $db->prepare("SELECT id FROM resources WHERE LOWER(name) LIKE '%spice%' AND LOWER(name) NOT LIKE '%coffee%'");
    $spiceIds->execute();
    $spice_ids = array_column($spiceIds->fetchAll(), 'id');
    if (empty($spice_ids)) {
        return ['total_input' => 0, 'total_output' => 0, 'per_user' => []];
    }
    $spice_placeholders = implode(',', array_fill(0, count($spice_ids), '?'));

    // Totals per run
    $stmt = $db->prepare("SELECT run_id, SUM(quantity) as qty FROM run_collections WHERE run_id IN ($placeholders) AND resource_id IN ($spice_placeholders) GROUP BY run_id");
    $stmt->execute(array_merge($run_ids, $spice_ids));
    $run_totals = [];
    foreach ($stmt->fetchAll() as $row) {
        $run_totals[(int)$row['run_id']] = (int)$row['qty'];
    }

    // Participants per run
    $stmt = $db->prepare("SELECT run_id, user_id FROM run_participants WHERE run_id IN ($placeholders)");
    $stmt->execute($run_ids);
    $participants = [];
    foreach ($stmt->fetchAll() as $row) {
        $participants[(int)$row['run_id']][] = (int)$row['user_id'];
    }

    // Initialize per-user input shares
    $per_user_input = [];

    if ($algorithm === 'equal_per_run') {
        foreach ($run_ids as $rid) {
            $total = (int)($run_totals[$rid] ?? 0);
            $users = $participants[$rid] ?? [];
            $count = count($users);
            if ($total <= 0 || $count === 0) continue;
            $share = $total / $count;
            foreach ($users as $uid) {
                if (!isset($per_user_input[$uid])) $per_user_input[$uid] = 0;
                $per_user_input[$uid] += $share;
            }
        }
    } else { // weighted_across_runs
        $stmt = $db->prepare("SELECT collected_by as user_id, SUM(quantity) as qty FROM run_collections WHERE run_id IN ($placeholders) AND resource_id IN ($spice_placeholders) AND collected_by IS NOT NULL GROUP BY collected_by");
        $stmt->execute(array_merge($run_ids, $spice_ids));
        foreach ($stmt->fetchAll() as $row) {
            $per_user_input[(int)$row['user_id']] = (int)$row['qty'];
        }
        // If no per-user data (all NULL collections), fall back to equal_per_run
        if (empty($per_user_input)) {
            foreach ($run_ids as $rid) {
                $total = (int)($run_totals[$rid] ?? 0);
                $users = $participants[$rid] ?? [];
                $count = count($users);
                if ($total <= 0 || $count === 0) continue;
                $share = $total / $count;
                foreach ($users as $uid) {
                    if (!isset($per_user_input[$uid])) $per_user_input[$uid] = 0;
                    $per_user_input[$uid] += $share;
                }
            }
        }
    }

    $total_input = array_sum($per_user_input);
    $input_per_unit = $discounted ? 7500 : 10000;
    $output_per_unit = 200;
    $total_units = $input_per_unit > 0 ? intdiv((int)$total_input, $input_per_unit) : 0;
    $total_output = $total_units * $output_per_unit;

    // Compute weights and outputs
    $per_user = [];
    if ($total_input > 0 && $total_output > 0) {
        $weights = [];
        foreach ($per_user_input as $uid => $inp) {
            $weights[$uid] = $inp / $total_input;
        }
        // Initial floor allocation
        $allocated = 0;
        $fractions = [];
        foreach ($weights as $uid => $w) {
            $out = floor($total_output * $w);
            $per_user[$uid] = ['input' => (int)$per_user_input[$uid], 'share' => $w, 'output' => (int)$out];
            $allocated += $out;
            $fractions[$uid] = ($total_output * $w) - $out;
        }
        // Distribute remainder by largest fractional parts
        $remainder = $total_output - $allocated;
        if ($remainder > 0) {
            arsort($fractions);
            foreach (array_keys($fractions) as $uid) {
                if ($remainder <= 0) break;
                $per_user[$uid]['output'] += 1;
                $remainder--;
            }
        }
    } else {
        foreach ($per_user_input as $uid => $inp) {
            $per_user[$uid] = ['input' => (int)$inp, 'share' => 0.0, 'output' => 0];
        }
    }

    // Attach usernames
    if (!empty($per_user)) {
        $uids = array_keys($per_user);
        $ph = implode(',', array_fill(0, count($uids), '?'));
        $q = $db->prepare("SELECT id, COALESCE(in_game_name, username) as name FROM users WHERE id IN ($ph)");
        $q->execute($uids);
        $map = [];
        foreach ($q->fetchAll() as $row) { $map[(int)$row['id']] = $row['name']; }
        foreach ($per_user as $uid => &$row) { $row['username'] = $map[$uid] ?? ('User #' . $uid); }
    }

    return [
        'total_input' => (int)$total_input,
        'total_output' => (int)$total_output,
        'per_user' => $per_user
    ];
}

// Remove participant from run
function removeRunParticipant($run_id, $user_id) {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM run_participants WHERE run_id = ? AND user_id = ?");
    return $stmt->execute([$run_id, $user_id]);
}

// Get participant contributions for a run
function getParticipantContributions($run_id) {
    $db = getDB();
    $sql = "
        SELECT 
            u.id as user_id,
            u.username,
            u.avatar,
            u.discord_id,
            rp.role,
            COUNT(DISTINCT rc.id) as collection_count,
            COUNT(DISTINCT rro.id) as refinement_count,
            COALESCE(SUM(rc.quantity), 0) as total_collected,
            GROUP_CONCAT(DISTINCT CONCAT(r.name, ':', rc.quantity) SEPARATOR ', ') as collections_detail
        FROM run_participants rp
        JOIN users u ON rp.user_id = u.id
        LEFT JOIN run_collections rc ON rc.run_id = rp.run_id AND rc.collected_by = u.id
        LEFT JOIN run_refined_outputs rro ON rro.run_id = rp.run_id AND rro.refined_by = u.id
        LEFT JOIN resources r ON rc.resource_id = r.id
        WHERE rp.run_id = ?
        GROUP BY u.id, u.username, u.avatar, u.discord_id, rp.role
        ORDER BY rp.role DESC, total_collected DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$run_id]);
    return $stmt->fetchAll();
}

// Get individual participant contribution details
function getParticipantRunDetails($run_id, $user_id) {
    $db = getDB();
    
    // Get collections
    $stmt = $db->prepare("
        SELECT 
            rc.*,
            r.name as resource_name,
            r.category as resource_category
        FROM run_collections rc
        JOIN resources r ON rc.resource_id = r.id
        WHERE rc.run_id = ? AND rc.collected_by = ?
        ORDER BY rc.created_at DESC
    ");
    $stmt->execute([$run_id, $user_id]);
    $collections = $stmt->fetchAll();
    
    // Get refinements
    $stmt = $db->prepare("
        SELECT 
            rro.*,
            r1.name as input_name,
            r2.name as output_name
        FROM run_refined_outputs rro
        JOIN resources r1 ON rro.input_resource_id = r1.id
        JOIN resources r2 ON rro.output_resource_id = r2.id
        WHERE rro.run_id = ? AND rro.refined_by = ?
        ORDER BY rro.refined_at DESC
    ");
    $stmt->execute([$run_id, $user_id]);
    $refinements = $stmt->fetchAll();
    
    return [
        'collections' => $collections,
        'refinements' => $refinements
    ];
}

// Admin Functions

// Check if user is admin
function isUserAdmin($user_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $role = $stmt->fetchColumn();
    return $role === 'admin';
}

// Log activity
function logActivity($user_id, $action, $details = null, $ip_address = null) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO activity_logs (user_id, action, details, ip_address)
        VALUES (?, ?, ?, ?)
    ");
    return $stmt->execute([$user_id, $action, $details, $ip_address]);
}

// Get activity logs
function getActivityLogs($limit = 100, $user_id = null) {
    $db = getDB();
    $sql = "
        SELECT 
            al.*,
            u.username,
            u.avatar,
            u.discord_id
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
    ";
    
    if ($user_id) {
        $sql .= " WHERE al.user_id = ?";
    }
    
    $sql .= " ORDER BY al.created_at DESC LIMIT ?";
    
    $stmt = $db->prepare($sql);
    if ($user_id) {
        $stmt->execute([$user_id, $limit]);
    } else {
        $stmt->execute([$limit]);
    }
    return $stmt->fetchAll();
}

// Update user role
function updateUserRole($user_id, $role) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
    return $stmt->execute([$role, $user_id]);
}

// Create manual user
function createManualUser($username) {
    $db = getDB();
    
    // Check if username already exists
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND merged_into_user_id IS NULL");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        throw new Exception("Username already exists");
    }
    
    $stmt = $db->prepare("INSERT INTO users (username, is_manual, role) VALUES (?, TRUE, 'member')");
    $stmt->execute([$username]);
    return $db->lastInsertId();
}

// Merge manual user into Discord user
function mergeUsers($manual_user_id, $discord_user_id) {
    $db = getDB();
    
    try {
        $db->beginTransaction();
        
        // Update all contributions
        $stmt = $db->prepare("UPDATE contributions SET user_id = ? WHERE user_id = ?");
        $stmt->execute([$discord_user_id, $manual_user_id]);
        
        // Update all farming run participations
        $stmt = $db->prepare("UPDATE run_participants SET user_id = ? WHERE user_id = ?");
        $stmt->execute([$discord_user_id, $manual_user_id]);
        
        // Update all run collections
        $stmt = $db->prepare("UPDATE run_collections SET collected_by = ? WHERE collected_by = ?");
        $stmt->execute([$discord_user_id, $manual_user_id]);
        
        // Update all refined outputs
        $stmt = $db->prepare("UPDATE run_refined_outputs SET refined_by = ? WHERE refined_by = ?");
        $stmt->execute([$discord_user_id, $manual_user_id]);
        
        // Update all activity logs
        $stmt = $db->prepare("UPDATE activity_logs SET user_id = ? WHERE user_id = ?");
        $stmt->execute([$discord_user_id, $manual_user_id]);
        
        // Mark manual user as merged
        $stmt = $db->prepare("UPDATE users SET merged_into_user_id = ? WHERE id = ?");
        $stmt->execute([$discord_user_id, $manual_user_id]);
        
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

// Get manual users
function getManualUsers($include_merged = false) {
    $db = getDB();
    $sql = "SELECT * FROM users WHERE is_manual = TRUE";
    if (!$include_merged) {
        $sql .= " AND merged_into_user_id IS NULL";
    }
    $sql .= " ORDER BY username";
    return $db->query($sql)->fetchAll();
}

// Get all users with roles
function getAllUsersWithRoles() {
    $db = getDB();
    $sql = "
        SELECT 
            u.*,
            COUNT(DISTINCT c.id) as total_contributions,
            COUNT(DISTINCT rp.run_id) as total_runs,
            MAX(c.date_collected) as last_active,
            mu.username as merged_into_username
        FROM users u
        LEFT JOIN contributions c ON u.id = c.user_id
        LEFT JOIN run_participants rp ON u.id = rp.user_id
        LEFT JOIN users mu ON u.merged_into_user_id = mu.id
        WHERE u.merged_into_user_id IS NULL
        GROUP BY u.id
        ORDER BY u.role DESC, u.username
    ";
    return $db->query($sql)->fetchAll();
}

// Dashboard Enhancement Functions

// Get active resource goals
function getActiveResourceGoals() {
    $db = getDB();
    $sql = "
        SELECT 
            rg.*,
            r.name as resource_name,
            r.category as resource_category,
            u.username as created_by_name,
            (
                SELECT COALESCE(SUM(c2.quantity), 0)
                FROM contributions c2
                WHERE c2.resource_id = rg.resource_id
                AND c2.date_collected >= rg.created_at
            ) as current_amount
        FROM resource_goals rg
        JOIN resources r ON rg.resource_id = r.id
        JOIN users u ON rg.created_by = u.id
        WHERE rg.is_active = TRUE
        ORDER BY rg.deadline ASC, rg.created_at DESC
    ";
    return $db->query($sql)->fetchAll();
}

// Create resource goal
function createResourceGoal($resource_id, $target_amount, $deadline, $created_by) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO resource_goals (resource_id, target_amount, deadline, created_by)
        VALUES (?, ?, ?, ?)
    ");
    return $stmt->execute([$resource_id, $target_amount, $deadline, $created_by]);
}

// Get top contributors for a period
function getTopContributors($period = 'all', $limit = 10) {
    $db = getDB();
    $sql = "
        SELECT 
            u.id,
            u.username,
            u.avatar,
            u.discord_id,
            COUNT(DISTINCT c.id) as contribution_count,
            COALESCE(SUM(c.quantity), 0) as total_contributed,
            COUNT(DISTINCT c.resource_id) as resource_variety
        FROM users u
        JOIN contributions c ON u.id = c.user_id
        WHERE u.merged_into_user_id IS NULL
    ";
    
    if ($period === 'week') {
        $sql .= " AND c.date_collected >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $sql .= " AND c.date_collected >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }
    
    $sql .= "
        GROUP BY u.id
        ORDER BY total_contributed DESC
        LIMIT ?
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// Get resource trends
function getResourceTrends($resource_id, $days = 30) {
    $db = getDB();
    $sql = "
        SELECT 
            DATE(date_collected) as date,
            SUM(quantity) as daily_total,
            COUNT(DISTINCT user_id) as contributors
        FROM contributions
        WHERE resource_id = ?
        AND date_collected >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(date_collected)
        ORDER BY date ASC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$resource_id, $days]);
    return $stmt->fetchAll();
}

// Get upcoming farming runs
function getUpcomingFarmingRuns($limit = 5) {
    $db = getDB();
    $sql = "
        SELECT 
            fr.*,
            u.username as creator_name,
            u.avatar as creator_avatar,
            u.discord_id as creator_discord_id,
            COUNT(DISTINCT rp.user_id) as participant_count
        FROM farming_runs fr
        JOIN users u ON fr.created_by = u.id
        LEFT JOIN run_participants rp ON fr.id = rp.run_id
        WHERE fr.status = 'active'
        GROUP BY fr.id
        ORDER BY fr.started_at DESC
        LIMIT ?
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

// Farming Run Enhancement Functions

// Calculate run metrics
function calculateRunMetrics($run_id) {
    $db = getDB();
    
    // Get run details
    $run = $db->prepare("
        SELECT started_at, ended_at 
        FROM farming_runs 
        WHERE id = ?
    ");
    $run->execute([$run_id]);
    $runData = $run->fetch();
    
    if (!$runData || !$runData['ended_at']) {
        return false;
    }
    
    // Calculate duration
    $start = new DateTime($runData['started_at']);
    $end = new DateTime($runData['ended_at']);
    $duration = $start->diff($end)->format('%i') + ($start->diff($end)->format('%h') * 60);
    
    // Get collection stats
    $stats = $db->prepare("
        SELECT 
            COUNT(*) as total_items,
            SUM(quantity) as total_quantity,
            COUNT(DISTINCT resource_id) as unique_resources,
            COUNT(DISTINCT collected_by) as active_participants
        FROM run_collections
        WHERE run_id = ?
    ");
    $stats->execute([$run_id]);
    $statsData = $stats->fetch();
    
    // Get participant count
    $participants = $db->prepare("
        SELECT COUNT(*) FROM run_participants WHERE run_id = ?
    ");
    $participants->execute([$run_id]);
    $participantCount = $participants->fetchColumn();
    
    // Calculate metrics
    $resourcesPerParticipant = $participantCount > 0 ? 
        $statsData['total_quantity'] / $participantCount : 0;
    $resourcesPerMinute = $duration > 0 ? 
        $statsData['total_quantity'] / $duration : 0;
    
    // Insert or update metrics
    $stmt = $db->prepare("
        INSERT INTO run_metrics (
            run_id, duration_minutes, total_resources_collected,
            unique_resources_collected, resources_per_participant,
            resources_per_minute
        ) VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            duration_minutes = VALUES(duration_minutes),
            total_resources_collected = VALUES(total_resources_collected),
            unique_resources_collected = VALUES(unique_resources_collected),
            resources_per_participant = VALUES(resources_per_participant),
            resources_per_minute = VALUES(resources_per_minute)
    ");
    
    return $stmt->execute([
        $run_id, $duration, $statsData['total_quantity'],
        $statsData['unique_resources'], $resourcesPerParticipant,
        $resourcesPerMinute
    ]);
}

// Get run metrics
function getRunMetrics($run_id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM run_metrics WHERE run_id = ?");
    $stmt->execute([$run_id]);
    return $stmt->fetch();
}

// Create run template
function createRunTemplate($name, $description, $type, $location, $notes, $created_by) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO run_templates (name, description, run_type, default_location, default_notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([$name, $description, $type, $location, $notes, $created_by]);
}

// Get run templates
function getRunTemplates($active_only = true) {
    $db = getDB();
    $sql = "
        SELECT rt.*, u.username as creator_name
        FROM run_templates rt
        JOIN users u ON rt.created_by = u.id
    ";
    if ($active_only) {
        $sql .= " WHERE rt.is_active = TRUE";
    }
    $sql .= " ORDER BY rt.name";
    return $db->query($sql)->fetchAll();
}

// Schedule farming run
function scheduleFarmingRun($name, $type, $location, $scheduled_for, $created_by, $notes = null) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO farming_runs (name, run_type, location, scheduled_for, is_scheduled, status, created_by, notes)
        VALUES (?, ?, ?, ?, TRUE, 'scheduled', ?, ?)
    ");
    $stmt->execute([$name, $type, $location, $scheduled_for, $created_by, $notes]);
    return $db->lastInsertId();
}

// Get scheduled runs
function getScheduledRuns() {
    $db = getDB();
    $sql = "
        SELECT 
            fr.*,
            u.username as creator_name,
            u.avatar as creator_avatar,
            u.discord_id as creator_discord_id,
            COUNT(DISTINCT rp.user_id) as participant_count
        FROM farming_runs fr
        JOIN users u ON fr.created_by = u.id
        LEFT JOIN run_participants rp ON fr.id = rp.run_id
        WHERE fr.is_scheduled = TRUE 
        AND fr.status = 'scheduled'
        AND fr.scheduled_for > NOW()
        GROUP BY fr.id
        ORDER BY fr.scheduled_for ASC
    ";
    return $db->query($sql)->fetchAll();
}

// Distribute resources from run
function distributeRunResources($run_id, $resource_id, $recipient_id, $quantity, $distributed_by, $notes = null) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO run_distributions (run_id, resource_id, recipient_id, quantity, distributed_by, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([$run_id, $resource_id, $recipient_id, $quantity, $distributed_by, $notes]);
}

// Get run distributions
function getRunDistributions($run_id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            rd.*,
            r.name as resource_name,
            u1.username as recipient_name,
            u2.username as distributor_name
        FROM run_distributions rd
        JOIN resources r ON rd.resource_id = r.id
        JOIN users u1 ON rd.recipient_id = u1.id
        JOIN users u2 ON rd.distributed_by = u2.id
        WHERE rd.run_id = ?
        ORDER BY rd.distributed_at DESC
    ");
    $stmt->execute([$run_id]);
    return $stmt->fetchAll();
}

// Add run comment
function addRunComment($run_id, $user_id, $comment) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO run_comments (run_id, user_id, comment)
        VALUES (?, ?, ?)
    ");
    return $stmt->execute([$run_id, $user_id, $comment]);
}

// Get run comments
function getRunComments($run_id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            rc.*,
            u.username,
            u.avatar,
            u.discord_id
        FROM run_comments rc
        JOIN users u ON rc.user_id = u.id
        WHERE rc.run_id = ?
        ORDER BY rc.created_at DESC
    ");
    $stmt->execute([$run_id]);
    return $stmt->fetchAll();
}

// Get crafting recipes
function getCraftingRecipes() {
    $db = getDB();
    $sql = "
        SELECT 
            cr.*,
            r.name as output_name,
            r.category as output_category
        FROM crafting_recipes cr
        JOIN resources r ON cr.output_resource_id = r.id
        ORDER BY r.name
    ";
    $recipes = $db->query($sql)->fetchAll();
    
    // Get ingredients for each recipe
    foreach ($recipes as &$recipe) {
        $stmt = $db->prepare("
            SELECT 
                ri.quantity,
                r.name as resource_name,
                r.id as resource_id
            FROM recipe_ingredients ri
            JOIN resources r ON ri.resource_id = r.id
            WHERE ri.recipe_id = ?
            ORDER BY ri.quantity DESC
        ");
        $stmt->execute([$recipe['id']]);
        $recipe['ingredients'] = $stmt->fetchAll();
    }
    
    return $recipes;
}

// Add crafted item to run
function addCraftedItem($run_id, $recipe_id, $quantity, $crafted_by = null) {
    $db = getDB();
    
    // Get recipe details
    $stmt = $db->prepare("SELECT * FROM crafting_recipes WHERE id = ?");
    $stmt->execute([$recipe_id]);
    $recipe = $stmt->fetch();
    
    if (!$recipe) return false;
    
    // Add as a collection (crafted items are added to inventory)
    return addRunCollection($run_id, $recipe['output_resource_id'], 
                          $quantity * $recipe['output_quantity'], 
                          $crafted_by, 'Crafted');
}