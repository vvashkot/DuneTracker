<?php
/**
 * Discord Webhook Integration
 */

require_once __DIR__ . '/db.php';

// Event types that can trigger webhooks
const WEBHOOK_EVENTS = [
    'user_approval_pending' => 'New User Awaiting Approval',
    'user_approved' => 'User Approved',
    'user_rejected' => 'User Rejected',
    'farming_run_started' => 'Farming Run Started',
    'farming_run_completed' => 'Farming Run Completed',
    'resource_milestone' => 'Resource Milestone Reached',
    'large_contribution' => 'Large Contribution Made',
    'low_resource_alert' => 'Low Resource Alert',
    'admin_adjustment' => 'Admin Resource Adjustment',
];

/**
 * Send a Discord webhook notification
 */
function sendDiscordWebhook($event_type, $embed_data, $content = null) {
    $db = getDB();
    
    // Get active webhooks that subscribe to this event type
    $stmt = $db->prepare("
        SELECT * FROM webhook_configs 
        WHERE is_active = TRUE 
        AND (event_types IS NULL OR JSON_CONTAINS(event_types, ?))
    ");
    $stmt->execute([json_encode($event_type)]);
    $webhooks = $stmt->fetchAll();
    
    $success_count = 0;
    
    foreach ($webhooks as $webhook) {
        // Build the Discord message
        $data = [
            'username' => APP_NAME . ' Bot',
            'avatar_url' => 'https://via.placeholder.com/150', // You can set a custom avatar URL
        ];
        
        if ($content) {
            $data['content'] = $content;
        }
        
        if ($embed_data) {
            // Add default embed properties
            if (!isset($embed_data['color'])) {
                $embed_data['color'] = getEventColor($event_type);
            }
            if (!isset($embed_data['timestamp'])) {
                $embed_data['timestamp'] = date('c');
            }
            if (!isset($embed_data['footer'])) {
                $embed_data['footer'] = [
                    'text' => APP_NAME . ' • ' . GUILD_NAME
                ];
            }
            
            $data['embeds'] = [$embed_data];
        }
        
        // Send the webhook
        $ch = curl_init($webhook['webhook_url']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Log the webhook attempt
        $status = ($http_code >= 200 && $http_code < 300) ? 'success' : 'failed';
        $error_message = $status === 'failed' ? ($error ?: "HTTP $http_code: $response") : null;
        
        $stmt = $db->prepare("
            INSERT INTO webhook_logs (webhook_config_id, event_type, event_data, status, error_message)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $webhook['id'],
            $event_type,
            json_encode($embed_data),
            $status,
            $error_message
        ]);
        
        if ($status === 'success') {
            $success_count++;
        }
    }
    
    return $success_count;
}

/**
 * Get color for event type
 */
function getEventColor($event_type) {
    $colors = [
        'user_approval_pending' => 16776960, // Yellow
        'user_approved' => 5025616, // Green
        'user_rejected' => 15158332, // Red
        'farming_run_started' => 3447003, // Blue
        'farming_run_completed' => 5025616, // Green
        'resource_milestone' => 10181046, // Purple
        'large_contribution' => 5025616, // Green
        'low_resource_alert' => 15158332, // Red
        'admin_adjustment' => 16776960, // Yellow
    ];
    
    return $colors[$event_type] ?? 7506394; // Default gray
}

/**
 * Notification helper functions for specific events
 */

function notifyUserAwaitingApproval($user) {
    $embed = [
        'title' => '👤 New User Awaiting Approval',
        'description' => "A new user has registered and is waiting for approval.",
        'fields' => [
            [
                'name' => 'Username',
                'value' => $user['username'],
                'inline' => true
            ],
            [
                'name' => 'Discord ID',
                'value' => $user['discord_id'],
                'inline' => true
            ],
            [
                'name' => 'Registered',
                'value' => date('M d, Y H:i', strtotime($user['created_at'])),
                'inline' => true
            ]
        ],
        'url' => BASE_URL . '/admin/approvals.php'
    ];
    
    return sendDiscordWebhook('user_approval_pending', $embed, "<@&ADMIN_ROLE_ID> New user needs approval!");
}

function notifyUserApproved($user, $approver) {
    $embed = [
        'title' => '✅ User Approved',
        'description' => "{$user['username']} has been approved for access.",
        'fields' => [
            [
                'name' => 'Approved By',
                'value' => $approver['username'],
                'inline' => true
            ],
            [
                'name' => 'Approved At',
                'value' => date('M d, Y H:i'),
                'inline' => true
            ]
        ]
    ];
    
    return sendDiscordWebhook('user_approved', $embed);
}

function notifyFarmingRunStarted($run, $creator) {
    $embed = [
        'title' => '🚜 Farming Run Started',
        'description' => "A new farming run has begun! Join now to participate.",
        'fields' => [
            [
                'name' => 'Run Name',
                'value' => $run['name'],
                'inline' => true
            ],
            [
                'name' => 'Type',
                'value' => ucfirst($run['run_type']),
                'inline' => true
            ],
            [
                'name' => 'Leader',
                'value' => $creator['username'],
                'inline' => true
            ]
        ],
        'url' => BASE_URL . '/farming-run.php?id=' . $run['id']
    ];
    
    $content = "@everyone New farming run started!";
    if ($run['notes']) {
        $embed['fields'][] = [
            'name' => 'Notes',
            'value' => $run['notes'],
            'inline' => false
        ];
    }
    
    return sendDiscordWebhook('farming_run_started', $embed, $content);
}

function notifyFarmingRunCompleted($run, $stats) {
    $embed = [
        'title' => '✅ Farming Run Completed',
        'description' => "Run #{$run['run_number']} has been completed!",
        'fields' => [
            [
                'name' => 'Duration',
                'value' => $stats['duration'] . ' minutes',
                'inline' => true
            ],
            [
                'name' => 'Participants',
                'value' => $stats['participant_count'],
                'inline' => true
            ],
            [
                'name' => 'Total Collected',
                'value' => number_format($stats['total_resources']),
                'inline' => true
            ],
            [
                'name' => 'Unique Resources',
                'value' => $stats['unique_resources'],
                'inline' => true
            ],
            [
                'name' => 'Per Participant',
                'value' => number_format($stats['per_participant'], 1),
                'inline' => true
            ],
            [
                'name' => 'Efficiency',
                'value' => number_format($stats['per_minute'], 1) . '/min',
                'inline' => true
            ]
        ],
        'url' => BASE_URL . '/farming-run.php?id=' . $run['id']
    ];
    
    // Add top contributors
    if (!empty($stats['top_contributors'])) {
        $contributors_text = "";
        foreach ($stats['top_contributors'] as $i => $contributor) {
            $medal = ['🥇', '🥈', '🥉'][$i] ?? '🏅';
            $contributors_text .= "$medal {$contributor['username']}: " . number_format($contributor['total']) . "\n";
        }
        
        $embed['fields'][] = [
            'name' => 'Top Contributors',
            'value' => $contributors_text,
            'inline' => false
        ];
    }
    
    return sendDiscordWebhook('farming_run_completed', $embed);
}

function notifyLargeContribution($user, $resource, $quantity) {
    $embed = [
        'title' => '💎 Large Contribution!',
        'description' => "{$user['username']} made a significant contribution!",
        'fields' => [
            [
                'name' => 'Resource',
                'value' => $resource['name'],
                'inline' => true
            ],
            [
                'name' => 'Quantity',
                'value' => number_format($quantity),
                'inline' => true
            ]
        ]
    ];
    
    return sendDiscordWebhook('large_contribution', $embed);
}

function notifyResourceMilestone($resource, $total, $milestone) {
    $embed = [
        'title' => '🎯 Resource Milestone Reached!',
        'description' => "The guild has reached a milestone for {$resource['name']}!",
        'fields' => [
            [
                'name' => 'Current Total',
                'value' => number_format($total),
                'inline' => true
            ],
            [
                'name' => 'Milestone',
                'value' => number_format($milestone),
                'inline' => true
            ]
        ]
    ];
    
    return sendDiscordWebhook('resource_milestone', $embed);
}

function notifyAdminAdjustment($admin, $resource, $adjustment, $reason) {
    $embed = [
        'title' => '⚙️ Resource Adjustment',
        'description' => "An admin has adjusted resource totals.",
        'fields' => [
            [
                'name' => 'Admin',
                'value' => $admin['username'],
                'inline' => true
            ],
            [
                'name' => 'Resource',
                'value' => $resource['name'],
                'inline' => true
            ],
            [
                'name' => 'Adjustment',
                'value' => ($adjustment > 0 ? '+' : '') . number_format($adjustment),
                'inline' => true
            ],
            [
                'name' => 'Reason',
                'value' => $reason,
                'inline' => false
            ]
        ]
    ];
    
    return sendDiscordWebhook('admin_adjustment', $embed);
}