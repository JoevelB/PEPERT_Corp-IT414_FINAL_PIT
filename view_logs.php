<?php

$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'pepert_corps_db';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) die("DB Connection failed: " . $conn->connect_error);

// --- HANDLE SCANNING ---
if (isset($_REQUEST['scan'])) {
    $scanRfid = $conn->real_escape_string(trim($_REQUEST['scan']));
    $conn->begin_transaction();

    try {
        $qr = $conn->query("SELECT rfid_status FROM rfid_reg WHERE rfid_data='$scanRfid' LIMIT 1");
        $isRegistered = false;
        $currentStatus = null;

        if ($qr && $qr->num_rows > 0) {
            $isRegistered = true;
            $currentStatus = (int)$qr->fetch_assoc()['rfid_status'];
            $newStatus = ($currentStatus === 1) ? 0 : 1;
        } else {
            // FIX 1: Use string "NULL" so SQL inserts a real null value
            $newStatus = "NULL"; 
        }

        $lastLogQ = $conn->query("SELECT rfid_status FROM rfid_logs WHERE rfid_data='$scanRfid' ORDER BY id DESC LIMIT 10");
        $lastStatus = -999; // Use a fake number that isn't 0 or 1
        if ($lastLogQ && $lastLogQ->num_rows > 0) {
             $row = $lastLogQ->fetch_assoc();
             // Handle if the last log was NULL
             $lastStatus = ($row['rfid_status'] === null) ? "NULL" : (int)$row['rfid_status'];
        }

        // Only insert if status is different
        if ($newStatus !== $lastStatus) {
            if ($isRegistered) {
                $conn->query("UPDATE rfid_reg SET rfid_status=$newStatus WHERE rfid_data='$scanRfid'");
            }
            // Insert Query ($newStatus will be 0, 1, or "NULL")
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

// --- FETCH DATA ---
$rfids = [];
$rfidResult = $conn->query("SELECT rfid_data, rfid_status FROM rfid_reg ORDER BY rfid_data ASC");
if ($rfidResult && $rfidResult->num_rows > 0) {
    while ($row = $rfidResult->fetch_assoc()) $rfids[] = $row;
}

$logs = [];
$logResult = $conn->query("SELECT * FROM rfid_logs ORDER BY id DESC");
if ($logResult && $logResult->num_rows > 0) {
    while ($row = $logResult->fetch_assoc()) $logs[] = $row;
}

// FIX 2: Strict check for NULL in label function
function statusLabel($status) {
    if ($status === null) return "RFID NOT FOUND"; // Must be triple =
    if ($status == 0) return "0";
    if ($status == 1) return "1";
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
.status-denied { color: #ff0702ff; font-weight: bold; } /* This is RED */
@media (max-width: 768px) { .container { flex-direction: column; } .left-panel { width: 100%; } .right-panel { width: 100%; margin-top: 15px; } }
</style>
</head>
<body>

<div class="container">

    <div class="left-panel">
        <h3>RFID</h3>
        <?php foreach ($rfids as $row):
            $rfid = $row['rfid_data'];
            $status = (int)$row['rfid_status'];
            $checked = ($status === 1) ? "checked" : "";
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
                    
                    if ($row['rfid_status'] === null) {
                        $statusClass = 'status-denied'; 
                    } elseif ($row['rfid_status'] == 1) {
                        $statusClass = 'status-login'; 
                    } else {
                        $statusClass = 'status-logout'; 
                    }
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