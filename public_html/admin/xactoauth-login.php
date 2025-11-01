<?php
/**
 * XactoAuth Login Initiator
 * Makes POST request to XactoAuth initiate endpoint and redirects to auth URL
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

// Start session
session_start();

// Initiate XactoAuth login flow
xactoauth_initiate_login();

// If we reach here, something went wrong
die('Error: Failed to initiate XactoAuth login. Please try again or contact support.');
