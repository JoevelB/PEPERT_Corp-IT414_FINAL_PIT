<?php


// Send plain UTF-8 response (for ESP32)
header('Content-Type: text/plain; charset=utf-8');

// Database configuration
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'pepert_corps_db';

// 1. Connect to database
// ------------------------------------------------------
$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    echo "ERROR|DBCON";
    exit;
}

// ------------------------------------------------------
// 2. Get UID from GET parameter
// ------------------------------------------------------
$uid = isset($_GET['uid']) ? strtoupper(trim($_GET['uid'])) : '';
if ($uid === '') {
    echo "ERROR|NOUID";
    $conn->close();
    exit;
}

// ------------------------------------------------------
// 3. Check if RFID is registered
// ------------------------------------------------------
$stmt = $conn->prepare("SELECT rfid_status FROM rfid_reg WHERE rfid_data = ? LIMIT 1");
if (!$stmt) {
    echo "ERROR|PREPARE_SELECT";
    $conn->close();
    exit;
}

$stmt->bind_param("s", $uid);
$stmt->execute();
$res = $stmt->get_result();

// ------------------------------------------------------
// 4. If RFID is registered → toggle + log
// ------------------------------------------------------
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $status = intval($row['rfid_status']);
    $newStatus = ($status === 0) ? 1 : 0;

    $stmt->close();

    // --- Update status in rfid_reg ---
    $update = $conn->prepare("UPDATE rfid_reg SET rfid_status = ? WHERE rfid_data = ?");
    if (!$update) {
        echo "ERROR|PREPARE_UPDATE";
        $conn->close();
        exit;
    }
    $update->bind_param("is", $newStatus, $uid);
    $update->execute();
    $update->close();

    // --- Insert new log into rfid_logs ---
    $log = $conn->prepare("INSERT INTO rfid_logs (time_log, rfid_data, rfid_status) VALUES (NOW(), ?, ?)");
    if (!$log) {
        echo "ERROR|PREPARE_LOG";
        $conn->close();
        exit;
    }
    $log->bind_param("si", $uid, $newStatus);
    $log->execute();
    $log->close();

    // --- Respond to ESP32 ---
    echo "FOUND|$newStatus";

} else {
    // --------------------------------------------------
    // 5. RFID not found in rfid_reg (not registered)
    // --------------------------------------------------
    if ($stmt) $stmt->close();
    echo "NOTFOUND|$uid";
}

// ------------------------------------------------------
// 6. Close connection
// ------------------------------------------------------
$conn->close();
?>
