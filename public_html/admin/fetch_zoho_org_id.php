<?php
/**
 * Quick utility to fetch Zoho Books Organization ID
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

// Check authentication
session_start();
if (!isset($_SESSION['staff_id'])) {
    die('Unauthorized');
}

// Get a valid access token
$access_token = zoho_get_valid_token();

if (!$access_token) {
    die('Error: Could not get valid access token. Please re-authorize.');
}

// Fetch organizations
$url = 'https://www.zohoapis.com/books/v3/organizations';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Zoho-oauthtoken ' . $access_token
    ]
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h2>Zoho Books API Response</h2>";
echo "<p><strong>HTTP Code:</strong> $http_code</p>";
echo "<h3>Raw Response:</h3>";
echo "<pre>" . htmlspecialchars($response) . "</pre>";

if ($http_code === 200) {
    $data = json_decode($response, true);
    if (isset($data['organizations']) && count($data['organizations']) > 0) {
        echo "<h3>Organizations Found:</h3>";
        foreach ($data['organizations'] as $org) {
            echo "<div style='background: #f0f9ff; border: 1px solid #0284c7; padding: 1rem; margin: 1rem 0; border-radius: 8px;'>";
            echo "<p><strong>Organization Name:</strong> " . htmlspecialchars($org['name'] ?? 'N/A') . "</p>";
            echo "<p><strong>Organization ID:</strong> <code style='background: #fff; padding: 4px 8px; border: 1px solid #ddd;'>" . htmlspecialchars($org['organization_id'] ?? 'N/A') . "</code></p>";
            echo "<p><strong>Email:</strong> " . htmlspecialchars($org['email'] ?? 'N/A') . "</p>";
            echo "</div>";
        }

        // Auto-update the first org ID
        if (count($data['organizations']) > 0) {
            $org_id = $data['organizations'][0]['organization_id'];

            // Update database
            try {
                $stmt = $pdo->prepare("SELECT additional_config FROM api_keys WHERE service_name = 'zoho_books'");
                $stmt->execute();
                $result = $stmt->fetch();

                $config = json_decode($result['additional_config'], true);
                $config['organization_id'] = $org_id;

                $stmt = $pdo->prepare("UPDATE api_keys SET additional_config = ? WHERE service_name = 'zoho_books'");
                $stmt->execute([json_encode($config)]);

                echo "<div style='background: #ecfdf5; border: 1px solid #10b981; padding: 1rem; margin: 1rem 0; border-radius: 8px; color: #065f46;'>";
                echo "<p><strong>✓ Organization ID automatically saved to database!</strong></p>";
                echo "<p><a href='settings.php?tab=api' style='color: #059669; text-decoration: underline;'>Go back to settings and test the API</a></p>";
                echo "</div>";
            } catch (Exception $e) {
                echo "<div style='background: #fef2f2; border: 1px solid #ef4444; padding: 1rem; margin: 1rem 0; border-radius: 8px; color: #991b1b;'>";
                echo "<p><strong>Error saving to database:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
                echo "</div>";
            }
        }
    } else {
        echo "<p style='color: #dc2626;'>No organizations found in response.</p>";
    }
} else {
    echo "<p style='color: #dc2626;'><strong>Error:</strong> API returned HTTP $http_code</p>";
}
