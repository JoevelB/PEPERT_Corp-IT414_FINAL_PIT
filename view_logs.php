view_logs.php

<?php

// index.php - RFID Dashboard with safe scan toggle

$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'pepert_corps_db';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) die("DB Connection failed: " . $conn->connect_error);

// --------------------
// SCAN HANDLER
// --------------------
if (isset($_REQUEST['scan'])) {
    $scanRfid = $conn->real_escape_string(trim($_REQUEST['scan']));
    $conn->begin_transaction();

    try {
        // Get current status of registered RFID (if exists)
        $qr = $conn->query("SELECT rfid_status FROM rfid_reg WHERE rfid_data='$scanRfid' LIMIT 1");
        $isRegistered = false;
        $currentStatus = null;

        if ($qr && $qr->num_rows > 0) {
            $isRegistered = true;
            $currentStatus = (int)$qr->fetch_assoc()['rfid_status'];
            $newStatus = ($currentStatus === 1) ? 0 : 1;
        } else {
            $newStatus = -1; // Unregistered
        }

        // Get last log status
        $lastLogQ = $conn->query("SELECT rfid_status FROM rfid_logs WHERE rfid_data='$scanRfid' ORDER BY id DESC LIMIT 10");
        $lastStatus = null;
        if ($lastLogQ && $lastLogQ->num_rows > 0) {
            $lastStatus = (int)$lastLogQ->fetch_assoc()['rfid_status'];
        }

        // Only insert log if new status is different from last log
        if ($newStatus !== $lastStatus) {
            if ($isRegistered) {
                $conn->query("UPDATE rfid_reg SET rfid_status=$newStatus WHERE rfid_data='$scanRfid'");
            }
            $conn->query("INSERT INTO rfid_logs (rfid_data, rfid_status, time_log) VALUES ('$scanRfid', $newStatus, NOW())");
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Scan error: " . $e->getMessage());
    }

    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit();
}


// --------------------
// FETCH DATA FOR UI
// --------------------

// Registered RFIDs
$rfids = [];
$rfidResult = $conn->query("SELECT rfid_data, rfid_status FROM rfid_reg ORDER BY rfid_data ASC");
if ($rfidResult && $rfidResult->num_rows > 0) {
    while ($row = $rfidResult->fetch_assoc()) $rfids[] = $row;
}

// Last 10 logs
$logs = [];
$logResult = $conn->query("SELECT * FROM rfid_logs ORDER BY id DESC");
if ($logResult && $logResult->num_rows > 0) {
    while ($row = $logResult->fetch_assoc()) $logs[] = $row;
}

// Status label helper
function statusLabel($status) {
    if ($status == 1) return "0";
    if ($status == 0) return "1";
    if ($status == -1) return "RFID NOT FOUND";
    return "UNKNOWN";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RFID Logs - pepert_corps</title>
<meta http-equiv="refresh" content="5">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; background: #111; color: #eee; display: flex; flex-direction: column; min-height: 100vh; }
.container { flex: 1; display: flex; padding: 20px; gap: 20px; width: 100%; }
.left-panel, .right-panel { background: #222; padding: 15px; border-radius: 8px; }
.left-panel { width: 250px; text-align: left; }
.left-panel h3 { color: #4CAF50; margin-bottom: 15px; text-align:left; }
.rfid-item { display: flex; justify-content: space-between; align-items: center; margin: 12px 0; font-size: 15px; }
.toggle { position: relative; display: inline-block; width: 45px; height: 22px; }
.toggle input { display: none; }
.slider { position: absolute; cursor: default; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; border-radius: 22px; transition: .3s; }
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 2px; bottom: 2px; background-color: white; border-radius: 50%; transition: .3s; }
input:checked + .slider { background-color: #4CAF50; }
input:checked + .slider:before { transform: translateX(23px); }
.right-panel { flex: 1; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; background: #222; text-align: center; }
th, td { padding: 10px; border: 1px solid #444; }
th { background: #333; }
tr:nth-child(even) { background: #2a2a2a; }
.status-login { color: #4CAF50; font-weight: bold; }
.status-logout { color: #2196F3; font-weight: bold; }
.status-denied { color: #E53935; font-weight: bold; }
@media (max-width: 768px) { .container { flex-direction: column; } .left-panel { width: 100%; } .right-panel { width: 100%; margin-top: 15px; } }
</style>
</head>
<body>

<div class="container">

    <!-- LEFT PANEL: Registered RFID Status -->
    <div class="left-panel">
        <h3>RFID</h3>
        <?php foreach ($rfids as $row):
            $rfid = $row['rfid_data'];
            $status = (int)$row['rfid_status'];
            $checked = ($status === 0) ? "checked" : "";
        ?>
        <div class="rfid-item">
            <span><?= htmlspecialchars($rfid) ?></span>
            <label class="toggle">
                <input type="checkbox" <?= $checked ?> disabled>
                <span class="slider"></span>
            </label>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- RIGHT PANEL: Last 10 Logs -->
    <div class="right-panel">
        <table>
            <thead>
                <tr>
                    <th>RFID</th>
                    <th>Status</th>
                    <th>Date & Time</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($logs)): ?>
                <?php foreach ($logs as $row):
                    $statusText = statusLabel($row['rfid_status']);
                    $statusClass = $row['rfid_status'] == 1 ? 'status-login' :
                                   ($row['rfid_status'] == 0 ? 'status-logout' : 'status-denied');
                ?>
                <tr>
                    <td><?= htmlspecialchars($row['rfid_data']) ?></td>
                    <td class="<?= $statusClass ?>"><?= $statusText ?></td>
                    <td><?= date("F j, Y g:i A", strtotime($row['time_log'])) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3">No logs available</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>
