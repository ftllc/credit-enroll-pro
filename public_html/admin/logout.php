<?php
/**
 * Admin Logout
 */

session_start();
session_destroy();
header('Location: login.php');
exit;
