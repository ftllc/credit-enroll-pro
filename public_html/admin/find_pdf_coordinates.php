<?php
/**
 * PDF Coordinate Finder Tool
 * Helps find exact coordinates for signature placement
 */

define('SNS_ENROLLMENT', true);
require_once __DIR__ . '/../src/config.php';

session_start();

// Check if logged in - redirect to login if not
if (!isset($_SESSION['staff_id']) || !isset($_SESSION['staff_logged_in'])) {
    // Store the current URL to redirect back after login
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit;
}

// Get staff info
$stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ?");
$stmt->execute([$_SESSION['staff_id']]);
$staff = $stmt->fetch();

// Check if staff exists, is active, and is admin
if (!$staff) {
    session_destroy();
    header('Location: login.php?error=invalid_session');
    exit;
}

if (!$staff['is_active']) {
    session_destroy();
    header('Location: login.php?error=account_inactive');
    exit;
}

if ($staff['role'] !== 'admin') {
    header('Location: panel.php?error=access_denied');
    exit;
}

// Fetch company name from settings
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$stmt->execute();
$company_name = $stmt->fetchColumn() ?: 'Your Company Name';

// Get document ID
$doc_id = intval($_GET['doc_id'] ?? 0);

if ($doc_id > 0) {
    // Fetch document
    $stmt = $pdo->prepare("SELECT * FROM state_contract_documents WHERE id = ?");
    $stmt->execute([$doc_id]);
    $doc = $stmt->fetch();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Coordinate Finder - <?php echo htmlspecialchars($company_name); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 1.5rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 24px; }
        .header-info { display: flex; gap: 1rem; align-items: center; }
        .btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
        }
        .btn:hover { background: rgba(255,255,255,0.3); }
        .content { padding: 2rem; }
        .info-box {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }
        .info-blue { background: #e0f2fe; border-color: #0284c7; color: #075985; }
        .info-yellow { background: #fef3c7; border-color: #f59e0b; color: #78350f; }
        .info-cyan { background: #f0f9ff; border-color: #06b6d4; color: #164e63; }
        .coord-display {
            background: #f0f9ff;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            min-height: 100px;
        }
        .pdf-container {
            border: 2px solid #3b82f6;
            border-radius: 8px;
            overflow: hidden;
            position: relative;
            margin-bottom: 1rem;
        }
        #pdfCanvas { display: block; cursor: crosshair; max-width: 100%; }
        #overlayCanvas { position: absolute; top: 0; left: 0; pointer-events: none; }
        .nav-info { text-align: center; color: #666; font-size: 14px; margin-top: 1rem; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>📐 PDF Coordinate Finder</h1>
        <div class="header-info">
            <span style="opacity:0.9;">Admin: <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?></span>
            <a href="settings.php?tab=contracts" class="btn">← Back to Settings</a>
        </div>
    </div>

    <div class="content">
        <?php if (!empty($doc)): ?>
            <div class="info-box info-blue">
                <strong>Document:</strong> <?php echo htmlspecialchars($doc['file_name']); ?><br>
                <strong>Type:</strong> <?php echo htmlspecialchars($doc['contract_type']); ?>
            </div>

            <div class="info-box info-yellow">
                <strong>How to use:</strong><br>
                <ol style="margin:0.5rem 0 0 1.5rem;">
                    <li>Click and drag on the PDF below to select a signature area</li>
                    <li>PDF origin is <strong>bottom-left</strong> (Y increases upward)</li>
                    <li>Coordinates appear below in the correct format</li>
                    <li>Copy the coordinates and paste into settings</li>
                </ol>
            </div>

            <div class="coord-display" id="coordinateDisplay">
                <strong>Click on PDF to get coordinates...</strong><br>
                <div id="coordText"></div>
            </div>

            <div class="pdf-container">
                <canvas id="pdfCanvas"></canvas>
                <canvas id="overlayCanvas"></canvas>
            </div>

            <div class="nav-info">
                <strong>Navigation:</strong> Use ← → arrow keys to change pages | <span id="pageInfo"></span>
            </div>

                <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
                <script>
                    const docId = <?php echo $doc_id; ?>;
                    let pdfDoc = null;
                    let pageNum = 1;
                    let pageRendering = false;
                    let scale = 1.5;
                    let startX = null, startY = null;
                    let isDragging = false;

                    // PDF.js worker
                    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

                    const canvas = document.getElementById('pdfCanvas');
                    const overlayCanvas = document.getElementById('overlayCanvas');
                    const ctx = canvas.getContext('2d');
                    const overlayCtx = overlayCanvas.getContext('2d');

                    // Load PDF
                    pdfjsLib.getDocument('download_contract.php?doc_id=' + docId).promise.then(function(pdf) {
                        pdfDoc = pdf;
                        document.getElementById('coordText').innerHTML = 'PDF loaded! Ready to select coordinates.';
                        document.getElementById('pageInfo').innerHTML = 'Page ' + pageNum + ' of ' + pdf.numPages;
                        renderPage(pageNum);
                    });

                    function renderPage(num) {
                        pageRendering = true;
                        pdfDoc.getPage(num).then(function(page) {
                            const viewport = page.getViewport({scale: scale});
                            canvas.height = viewport.height;
                            canvas.width = viewport.width;
                            overlayCanvas.height = viewport.height;
                            overlayCanvas.width = viewport.width;

                            const renderContext = {
                                canvasContext: ctx,
                                viewport: viewport
                            };
                            page.render(renderContext).promise.then(function() {
                                pageRendering = false;
                            });
                        });
                    }

                    // Mouse events for coordinate finding
                    canvas.addEventListener('mousedown', function(e) {
                        const rect = canvas.getBoundingClientRect();
                        const x = e.clientX - rect.left;
                        const y = e.clientY - rect.top;

                        startX = x;
                        startY = y;
                        isDragging = true;

                        // Clear overlay
                        overlayCtx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);
                    });

                    canvas.addEventListener('mousemove', function(e) {
                        if (!isDragging) return;

                        const rect = canvas.getBoundingClientRect();
                        const x = e.clientX - rect.left;
                        const y = e.clientY - rect.top;

                        // Clear and redraw selection box
                        overlayCtx.clearRect(0, 0, overlayCanvas.width, overlayCanvas.height);
                        overlayCtx.strokeStyle = '#3b82f6';
                        overlayCtx.lineWidth = 2;
                        overlayCtx.strokeRect(startX, startY, x - startX, y - startY);
                        overlayCtx.fillStyle = 'rgba(59, 130, 246, 0.1)';
                        overlayCtx.fillRect(startX, startY, x - startX, y - startY);
                    });

                    canvas.addEventListener('mouseup', function(e) {
                        if (!isDragging) return;
                        isDragging = false;

                        const rect = canvas.getBoundingClientRect();
                        const x = e.clientX - rect.left;
                        const y = e.clientY - rect.top;

                        // Convert canvas coordinates to PDF points
                        const pdfX1 = (Math.min(startX, x) / scale).toFixed(1);
                        const pdfY1 = ((canvas.height - Math.max(startY, y)) / scale).toFixed(1);
                        const pdfX2 = (Math.max(startX, x) / scale).toFixed(1);
                        const pdfY2 = ((canvas.height - Math.min(startY, y)) / scale).toFixed(1);

                        const coordDisplay = `
                            <strong style="color:#059669;">✓ Selection Made!</strong><br><br>
                            <strong>PDF Coordinates (Bottom-Left Origin):</strong><br>
                            • Top-Left X1: <span style="color:#dc2626;font-weight:bold;">${pdfX1}</span><br>
                            • Top-Left Y1: <span style="color:#dc2626;font-weight:bold;">${pdfY1}</span><br>
                            • Bottom-Right X2: <span style="color:#dc2626;font-weight:bold;">${pdfX2}</span><br>
                            • Bottom-Right Y2: <span style="color:#dc2626;font-weight:bold;">${pdfY2}</span><br>
                            • Page: <span style="color:#dc2626;font-weight:bold;">${pageNum}</span><br><br>
                            <strong>Box Size:</strong> ${(pdfX2 - pdfX1).toFixed(1)} × ${(pdfY2 - pdfY1).toFixed(1)} points<br>
                            <strong>Size in inches:</strong> ${((pdfX2 - pdfX1) / 72).toFixed(2)}" × ${((pdfY2 - pdfY1) / 72).toFixed(2)}"
                        `;

                        document.getElementById('coordText').innerHTML = coordDisplay;
                    });

                    // Page navigation
                    document.addEventListener('keydown', function(e) {
                        if (!pdfDoc) return;
                        if (e.key === 'ArrowLeft' && pageNum > 1) {
                            pageNum--;
                            renderPage(pageNum);
                            document.getElementById('pageInfo').innerHTML = 'Page ' + pageNum + ' of ' + pdfDoc.numPages;
                        } else if (e.key === 'ArrowRight' && pageNum < pdfDoc.numPages) {
                            pageNum++;
                            renderPage(pageNum);
                            document.getElementById('pageInfo').innerHTML = 'Page ' + pageNum + ' of ' + pdfDoc.numPages;
                        }
                    });
                </script>

        <?php else: ?>
            <div style="text-align:center;padding:3rem;color:#666;">
                <p style="font-size:18px;margin-bottom:1rem;">No document selected</p>
                <a href="settings.php?tab=contracts" class="btn">Go to Contract Settings</a>
            </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
